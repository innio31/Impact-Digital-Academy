<?php
// modules/instructor/classes/schedule_builder.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions - FIXED PATHS
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor (NOT admin)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    // If user is admin, don't redirect to admin dashboard, just show error
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        die("Access Denied: This page is for instructors only. Please use the admin template manager instead.");
    }
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    $_SESSION['error'] = "Invalid class ID.";
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Verify instructor has access to this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.id as course_id,
               p.name as program_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id
        WHERE cb.id = ? AND cb.instructor_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    $_SESSION['error'] = "You don't have access to this class.";
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Check if course_content_templates table exists
$table_check = $conn->query("SHOW TABLES LIKE 'course_content_templates'");
if ($table_check->num_rows === 0) {
    die("The template system hasn't been set up yet. Please contact the administrator to create templates first.");
}

// Get admin-created templates for this course (only active ones)
$templates_sql = "SELECT * FROM course_content_templates 
                  WHERE course_id = ? AND is_active = 1
                  ORDER BY week_number, content_type, created_at";

$stmt = $conn->prepare($templates_sql);
if (!$stmt) {
    die("Error preparing templates query: " . $conn->error);
}

$stmt->bind_param("i", $class['course_id']);
$stmt->execute();
$result = $stmt->get_result();
$templates = [];
while ($row = $result->fetch_assoc()) {
    // Safely decode JSON
    $row['content_data'] = json_decode($row['content_data'], true) ?: [];
    $row['file_references'] = json_decode($row['file_references'], true) ?: [];
    $templates[] = $row;
}
$stmt->close();

// Organize templates by week
$templates_by_week = [];
foreach ($templates as $template) {
    $week = $template['week_number'];
    if (!isset($templates_by_week[$week])) {
        $templates_by_week[$week] = [];
    }
    $templates_by_week[$week][] = $template;
}

// Check if class_content_schedules table exists
$schedule_table_check = $conn->query("SHOW TABLES LIKE 'class_content_schedules'");
$schedules_table_exists = ($schedule_table_check && $schedule_table_check->num_rows > 0);

$existing_schedules = [];
if ($schedules_table_exists) {
    // Get existing schedules for this class
    $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number,
                             cct.content_data
                      FROM class_content_schedules ccs
                      JOIN course_content_templates cct ON ccs.template_id = cct.id
                      WHERE ccs.class_id = ?
                      ORDER BY ccs.scheduled_publish_date";

    $stmt = $conn->prepare($schedules_sql);
    if ($stmt) {
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['content_data'] = json_decode($row['content_data'], true) ?: [];
            $existing_schedules[$row['template_id']] = $row;
        }
        $stmt->close();
    }
}

// Calculate class weeks
$start_date = new DateTime($class['start_date']);
$end_date = new DateTime($class['end_date']);
$class_duration = $start_date->diff($end_date)->days;
$total_weeks = max(1, ceil($class_duration / 7));

// Generate week dates
$week_dates = [];
for ($week = 1; $week <= $total_weeks; $week++) {
    $week_start = clone $start_date;
    $week_start->modify('+' . ($week - 1) . ' weeks');
    $week_end = clone $week_start;
    $week_end->modify('+6 days');

    // Ensure we don't go past end date
    if ($week_end > $end_date) {
        $week_end = clone $end_date;
    }

    $week_dates[$week] = [
        'start' => $week_start,
        'end' => $week_end
    ];
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$schedules_table_exists) {
        $message = "The scheduling system hasn't been set up yet. Please contact the administrator.";
        $message_type = "error";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
        $schedules = $_POST['schedules'] ?? [];
        $success_count = 0;
        $error_count = 0;

        // Begin transaction
        $conn->begin_transaction();

        try {
            // Clear existing schedules for this class if overwrite is checked
            if (isset($_POST['overwrite']) && $_POST['overwrite'] === '1') {
                $clear_sql = "DELETE FROM class_content_schedules WHERE class_id = ?";
                $clear_stmt = $conn->prepare($clear_sql);
                if ($clear_stmt) {
                    $clear_stmt->bind_param("i", $class_id);
                    $clear_stmt->execute();
                    $clear_stmt->close();
                }
            }

            foreach ($schedules as $template_id => $schedule_data) {
                if (empty($schedule_data['enabled']) || $schedule_data['enabled'] !== '1') {
                    continue;
                }

                $publish_date = $schedule_data['publish_date'] ?? '';
                $publish_time = $schedule_data['publish_time'] ?? '08:00:00';

                if (empty($publish_date)) {
                    continue;
                }

                $publish_datetime = $publish_date . ' ' . $publish_time;

                // Check if schedule already exists
                $check_sql = "SELECT id FROM class_content_schedules 
                             WHERE class_id = ? AND template_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if ($check_stmt) {
                    $check_stmt->bind_param("ii", $class_id, $template_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($check_result->num_rows > 0) {
                        // Update existing
                        $update_sql = "UPDATE class_content_schedules 
                                      SET scheduled_publish_date = ?, status = 'scheduled', updated_at = NOW()
                                      WHERE class_id = ? AND template_id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        if ($update_stmt) {
                            $update_stmt->bind_param("sii", $publish_datetime, $class_id, $template_id);
                            if ($update_stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                            $update_stmt->close();
                        }
                    } else {
                        // Insert new
                        $insert_sql = "INSERT INTO class_content_schedules 
                                      (class_id, template_id, scheduled_publish_date, status) 
                                      VALUES (?, ?, ?, 'scheduled')";
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("iis", $class_id, $template_id, $publish_datetime);
                            if ($insert_stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                            $insert_stmt->close();
                        }
                    }
                    $check_stmt->close();
                }
            }

            $conn->commit();

            $message = "Schedule saved successfully! {$success_count} items scheduled.";
            $message_type = "success";

            // Log activity
            logActivity('schedule_saved', "Saved content schedule for class ID: {$class_id}", 'class_content_schedules', $class_id);

            // Refresh existing schedules
            $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number,
                                     cct.content_data
                              FROM class_content_schedules ccs
                              JOIN course_content_templates cct ON ccs.template_id = cct.id
                              WHERE ccs.class_id = ?
                              ORDER BY ccs.scheduled_publish_date";
            $stmt2 = $conn->prepare($schedules_sql);
            if ($stmt2) {
                $stmt2->bind_param("i", $class_id);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                $existing_schedules = [];
                while ($row2 = $result2->fetch_assoc()) {
                    $row2['content_data'] = json_decode($row2['content_data'], true) ?: [];
                    $existing_schedules[$row2['template_id']] = $row2;
                }
                $stmt2->close();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error saving schedule: " . $e->getMessage();
            $message_type = "error";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'remove_schedule') {
        $schedule_id = (int)$_POST['schedule_id'];

        $delete_sql = "DELETE FROM class_content_schedules WHERE id = ? AND class_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        if ($delete_stmt) {
            $delete_stmt->bind_param("ii", $schedule_id, $class_id);

            if ($delete_stmt->execute()) {
                $message = "Schedule removed successfully.";
                $message_type = "success";

                // Refresh existing schedules
                $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number,
                                         cct.content_data
                                  FROM class_content_schedules ccs
                                  JOIN course_content_templates cct ON ccs.template_id = cct.id
                                  WHERE ccs.class_id = ?
                                  ORDER BY ccs.scheduled_publish_date";
                $stmt2 = $conn->prepare($schedules_sql);
                if ($stmt2) {
                    $stmt2->bind_param("i", $class_id);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $existing_schedules = [];
                    while ($row2 = $result2->fetch_assoc()) {
                        $row2['content_data'] = json_decode($row2['content_data'], true) ?: [];
                        $existing_schedules[$row2['template_id']] = $row2;
                    }
                    $stmt2->close();
                }
            } else {
                $message = "Failed to remove schedule.";
                $message_type = "error";
            }
            $delete_stmt->close();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Builder - <?php echo htmlspecialchars($class['batch_code'] ?? ''); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--primary);
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .class-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .date-range {
            background: var(--light);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .message-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message-info {
            background: #dbeafe;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 12px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Simple template list for now */
        .template-list {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .template-item {
            padding: 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            margin-bottom: 1rem;
            background: var(--light);
        }

        .template-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .type-material {
            background: rgba(14, 165, 233, 0.1);
            color: var(--info);
        }

        .type-assignment {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .type-quiz {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="index.php">My Classes</a>
            <i class="fas fa-chevron-right"></i>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code'] ?? ''); ?>
            </a>
            <i class="fas fa-chevron-right"></i>
            <span>Schedule Builder</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Content Schedule Builder</h1>
            <p><?php echo htmlspecialchars($class['batch_code'] ?? ''); ?> - <?php echo htmlspecialchars($class['name'] ?? ''); ?></p>

            <div class="class-info">
                <div>
                    <strong><?php echo htmlspecialchars($class['course_title'] ?? ''); ?></strong>
                    <span style="color: var(--gray); margin-left: 0.5rem;">(<?php echo htmlspecialchars($class['course_code'] ?? ''); ?>)</span>
                </div>
                <div class="date-range">
                    <i class="fas fa-calendar"></i>
                    <?php echo $start_date->format('M d, Y'); ?> - <?php echo $end_date->format('M d, Y'); ?>
                    (<?php echo $total_weeks; ?> weeks)
                </div>
                <div>
                    <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back to Class
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Templates Section -->
        <div class="template-list">
            <h2 style="margin-bottom: 1.5rem;">Available Templates (<?php echo count($templates); ?>)</h2>

            <?php if (empty($templates)): ?>
                <div class="empty-state">
                    <i class="fas fa-layer-group"></i>
                    <h3>No Templates Available</h3>
                    <p>There are no templates created for this course yet.</p>
                    <p style="font-size: 0.9rem; margin-top: 1rem;">Templates must be created by an administrator first.</p>
                </div>
            <?php else: ?>
                <?php foreach ($templates as $template): ?>
                    <div class="template-item">
                        <span class="template-type type-<?php echo $template['content_type']; ?>">
                            <?php echo ucfirst($template['content_type']); ?>
                        </span>
                        <h3 style="margin: 0.5rem 0;"><?php echo htmlspecialchars($template['title']); ?></h3>
                        <p style="color: var(--gray); font-size: 0.9rem;">Week <?php echo $template['week_number']; ?></p>
                        <?php if (isset($existing_schedules[$template['id']])): ?>
                            <p style="color: var(--success); margin-top: 0.5rem;">
                                <i class="fas fa-check-circle"></i> Scheduled for
                                <?php echo date('M d, Y g:i A', strtotime($existing_schedules[$template['id']]['scheduled_publish_date'])); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top: 2rem; text-align: center;">
                    <p style="color: var(--gray); margin-bottom: 1rem;">The full drag-and-drop interface is under construction. Please check back later.</p>
                    <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn btn-primary">Return to Class Home</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>