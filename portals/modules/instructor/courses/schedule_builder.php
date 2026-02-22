<?php
// modules/instructor/courses/schedule_builder.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
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
    header('Location: ../classes/index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.id as course_id
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: ../classes/index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Get templates for this course
$templates_sql = "SELECT * FROM course_content_templates 
                  WHERE course_id = ? AND instructor_id = ? AND is_active = 1
                  ORDER BY week_number, content_type";
$stmt = $conn->prepare($templates_sql);
$stmt->bind_param("ii", $class['course_id'], $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$templates = [];
while ($row = $result->fetch_assoc()) {
    $row['content_data'] = json_decode($row['content_data'], true);
    $row['file_references'] = json_decode($row['file_references'], true);
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

// Get existing schedules for this class
$schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number
                  FROM class_content_schedules ccs
                  JOIN course_content_templates cct ON ccs.template_id = cct.id
                  WHERE ccs.class_id = ?
                  ORDER BY ccs.scheduled_publish_date";
$stmt = $conn->prepare($schedules_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$existing_schedules = [];
while ($row = $result->fetch_assoc()) {
    $existing_schedules[$row['template_id']] = $row;
}
$stmt->close();

// Calculate class weeks
$start_date = new DateTime($class['start_date']);
$end_date = new DateTime($class['end_date']);
$class_duration = $start_date->diff($end_date)->days;
$total_weeks = ceil($class_duration / 7);

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
    if (isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
        $schedules = $_POST['schedules'] ?? [];
        $success_count = 0;
        $error_count = 0;

        // Begin transaction
        $conn->begin_transaction();

        try {
            // Clear existing schedules for this class (optional - or we can update)
            if (isset($_POST['overwrite']) && $_POST['overwrite'] === '1') {
                $clear_sql = "DELETE FROM class_content_schedules WHERE class_id = ?";
                $clear_stmt = $conn->prepare($clear_sql);
                $clear_stmt->bind_param("i", $class_id);
                $clear_stmt->execute();
                $clear_stmt->close();
            }

            foreach ($schedules as $template_id => $schedule_data) {
                if (empty($schedule_data['enabled'])) {
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
                $check_stmt->bind_param("ii", $class_id, $template_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    // Update existing
                    $update_sql = "UPDATE class_content_schedules 
                                  SET scheduled_publish_date = ?, status = 'scheduled', updated_at = NOW()
                                  WHERE class_id = ? AND template_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("sii", $publish_datetime, $class_id, $template_id);

                    if ($update_stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                    $update_stmt->close();
                } else {
                    // Insert new
                    $insert_sql = "INSERT INTO class_content_schedules 
                                  (class_id, template_id, scheduled_publish_date, status) 
                                  VALUES (?, ?, ?, 'scheduled')";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iis", $class_id, $template_id, $publish_datetime);

                    if ($insert_stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            }

            $conn->commit();

            $message = "Schedule saved successfully! {$success_count} items scheduled.";
            $message_type = "success";

            // Log activity
            logActivity('schedule_saved', "Saved content schedule for class ID: {$class_id}", 'class_content_schedules', $class_id);

            // Refresh existing schedules
            $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number
                              FROM class_content_schedules ccs
                              JOIN course_content_templates cct ON ccs.template_id = cct.id
                              WHERE ccs.class_id = ?
                              ORDER BY ccs.scheduled_publish_date";
            $stmt = $conn->prepare($schedules_sql);
            $stmt->bind_param("i", $class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing_schedules = [];
            while ($row = $result->fetch_assoc()) {
                $existing_schedules[$row['template_id']] = $row;
            }
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error saving schedule: " . $e->getMessage();
            $message_type = "error";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'publish_now') {
        $schedule_id = (int)$_POST['schedule_id'];

        // This will trigger immediate publishing (we'll handle this in the cron job)
        // For now, we'll just update the status
        $update_sql = "UPDATE class_content_schedules 
                      SET status = 'scheduled', scheduled_publish_date = NOW() 
                      WHERE id = ? AND class_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ii", $schedule_id, $class_id);

        if ($stmt->execute()) {
            $message = "Content queued for immediate publishing.";
            $message_type = "success";
        } else {
            $message = "Failed to queue content for publishing.";
            $message_type = "error";
        }
        $stmt->close();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'remove_schedule') {
        $schedule_id = (int)$_POST['schedule_id'];

        $delete_sql = "DELETE FROM class_content_schedules WHERE id = ? AND class_id = ?";
        $stmt = $conn->prepare($delete_sql);
        $stmt->bind_param("ii", $schedule_id, $class_id);

        if ($stmt->execute()) {
            $message = "Schedule removed successfully.";
            $message_type = "success";

            // Refresh existing schedules
            $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number
                              FROM class_content_schedules ccs
                              JOIN course_content_templates cct ON ccs.template_id = cct.id
                              WHERE ccs.class_id = ?
                              ORDER BY ccs.scheduled_publish_date";
            $stmt2 = $conn->prepare($schedules_sql);
            $stmt2->bind_param("i", $class_id);
            $stmt2->execute();
            $result = $stmt2->get_result();
            $existing_schedules = [];
            while ($row = $result->fetch_assoc()) {
                $existing_schedules[$row['template_id']] = $row;
            }
            $stmt2->close();
        } else {
            $message = "Failed to remove schedule.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

$conn->close();

// Helper function to get day options
function getDayOptions($week_start, $week_end, $selected = '')
{
    $options = [];
    $current = clone $week_start;

    while ($current <= $week_end) {
        $date_str = $current->format('Y-m-d');
        $day_name = $current->format('l');
        $selected_attr = ($date_str === $selected) ? 'selected' : '';

        $options[] = "<option value=\"{$date_str}\" {$selected_attr}>{$day_name}, {$current->format('M d, Y')}</option>";

        $current->modify('+1 day');
    }

    return implode('', $options);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Builder - <?php echo htmlspecialchars($class['batch_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        /* Header */
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
            border-top: 2px solid #f1f5f9;
        }

        .date-range {
            background: #f8fafc;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        /* Timeline */
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .timeline-nav {
            display: flex;
            gap: 0.5rem;
        }

        .week-selector {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .week-btn {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .week-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .week-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .week-btn.has-content {
            position: relative;
        }

        .week-btn.has-content::after {
            content: '';
            position: absolute;
            top: -4px;
            right: -4px;
            width: 10px;
            height: 10px;
            background: var(--success);
            border-radius: 50%;
            border: 2px solid white;
        }

        /* Schedule Grid */
        .schedule-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .schedule-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Templates Panel */
        .templates-panel {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            height: fit-content;
            position: sticky;
            top: 1rem;
        }

        .templates-panel h2 {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .template-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .template-item {
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 1rem;
            cursor: move;
            transition: all 0.3s ease;
        }

        .template-item:hover {
            border-color: var(--primary);
            transform: translateX(4px);
            background: #f8fafc;
        }

        .template-item.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .template-item.scheduled {
            border-left: 4px solid var(--success);
            background: #f0fdf4;
        }

        .template-type {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
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

        .template-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .template-week {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Calendar Panel */
        .calendar-panel {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .week-display {
            margin-bottom: 2rem;
        }

        .week-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .week-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .week-dates {
            color: var(--gray);
        }

        .day-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .day-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            min-height: 150px;
            transition: all 0.3s ease;
        }

        .day-card.today {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .day-card.drag-over {
            border-color: var(--success);
            background: #f0fdf4;
            transform: scale(1.02);
        }

        .day-header {
            font-weight: 600;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .day-date {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .scheduled-item {
            background: white;
            border-left: 3px solid;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .scheduled-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
        }

        .scheduled-item.material {
            border-left-color: var(--info);
        }

        .scheduled-item.assignment {
            border-left-color: var(--warning);
        }

        .scheduled-item.quiz {
            border-left-color: var(--success);
        }

        .scheduled-time {
            font-size: 0.7rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .item-actions {
            display: none;
            margin-top: 0.5rem;
            gap: 0.25rem;
        }

        .scheduled-item:hover .item-actions {
            display: flex;
        }

        /* Form */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
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
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .btn-danger {
            background: white;
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* Messages */
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Legend */
        .legend {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #f1f5f9;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-color.material {
            background: var(--info);
        }

        .legend-color.assignment {
            background: var(--warning);
        }

        .legend-color.quiz {
            background: var(--success);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Schedule Builder</h1>
            <p><?php echo htmlspecialchars($class['batch_code']); ?> - <?php echo htmlspecialchars($class['name']); ?></p>

            <div class="class-info">
                <div>
                    <strong>Course:</strong> <?php echo htmlspecialchars($class['course_title']); ?>
                </div>
                <div class="date-range">
                    <i class="fas fa-calendar"></i>
                    <?php echo $start_date->format('M d, Y'); ?> - <?php echo $end_date->format('M d, Y'); ?>
                    (<?php echo $total_weeks; ?> weeks)
                </div>
                <div>
                    <a href="template_manager.php?course_id=<?php echo $class['course_id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-layer-group"></i> Manage Templates
                    </a>
                    <a href="../classes/class_home.php?id=<?php echo $class_id; ?>" class="btn btn-secondary btn-sm">
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

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color material"></div>
                <span>Material</span>
            </div>
            <div class="legend-item">
                <div class="legend-color assignment"></div>
                <span>Assignment</span>
            </div>
            <div class="legend-item">
                <div class="legend-color quiz"></div>
                <span>Quiz</span>
            </div>
        </div>

        <!-- Main Schedule Grid -->
        <div class="schedule-grid">
            <!-- Templates Panel (Left) -->
            <div class="templates-panel">
                <h2><i class="fas fa-layer-group"></i> Available Templates</h2>

                <?php if (empty($templates)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No templates found. Create templates first in the Template Manager.</p>
                        <a href="template_manager.php?course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus-circle"></i> Create Templates
                        </a>
                    </div>
                <?php else: ?>
                    <div class="template-list" id="templateList">
                        <?php foreach ($templates as $template):
                            $is_scheduled = isset($existing_schedules[$template['id']]);
                        ?>
                            <div class="template-item <?php echo $is_scheduled ? 'scheduled' : ''; ?>"
                                draggable="true"
                                data-id="<?php echo $template['id']; ?>"
                                data-type="<?php echo $template['content_type']; ?>"
                                data-title="<?php echo htmlspecialchars($template['title']); ?>"
                                data-week="<?php echo $template['week_number']; ?>"
                                id="template-<?php echo $template['id']; ?>">

                                <span class="template-type type-<?php echo $template['content_type']; ?>">
                                    <?php echo ucfirst($template['content_type']); ?>
                                </span>

                                <div class="template-title">
                                    <?php echo htmlspecialchars($template['title']); ?>
                                </div>

                                <div class="template-week">
                                    Week <?php echo $template['week_number']; ?>
                                    <?php if ($is_scheduled): ?>
                                        <span style="color: var(--success); margin-left: 0.5rem;">
                                            <i class="fas fa-check-circle"></i> Scheduled
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Calendar Panel (Right) -->
            <div class="calendar-panel">
                <form method="POST" id="scheduleForm">
                    <input type="hidden" name="action" value="save_schedule">

                    <!-- Week Navigation -->
                    <div class="week-selector" id="weekSelector">
                        <?php for ($week = 1; $week <= $total_weeks; $week++): ?>
                            <?php
                            $has_content = false;
                            foreach ($templates_by_week[$week] ?? [] as $t) {
                                if (!isset($existing_schedules[$t['id']])) {
                                    $has_content = true;
                                    break;
                                }
                            }
                            ?>
                            <button type="button"
                                class="week-btn <?php echo $week === 1 ? 'active' : ''; ?> <?php echo $has_content ? 'has-content' : ''; ?>"
                                data-week="<?php echo $week; ?>">
                                Week <?php echo $week; ?>
                                <br>
                                <small><?php echo $week_dates[$week]['start']->format('M d'); ?> - <?php echo $week_dates[$week]['end']->format('M d'); ?></small>
                            </button>
                        <?php endfor; ?>
                    </div>

                    <!-- Week Display -->
                    <?php for ($week = 1; $week <= $total_weeks; $week++): ?>
                        <div class="week-display" id="week-<?php echo $week; ?>" style="<?php echo $week === 1 ? '' : 'display: none;'; ?>">
                            <div class="week-header">
                                <div>
                                    <span class="week-title">Week <?php echo $week; ?></span>
                                    <span class="week-dates">
                                        <?php echo $week_dates[$week]['start']->format('M d, Y'); ?> -
                                        <?php echo $week_dates[$week]['end']->format('M d, Y'); ?>
                                    </span>
                                </div>
                                <button type="button" class="btn btn-sm btn-success" onclick="selectAllWeek(<?php echo $week; ?>)">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                            </div>

                            <!-- Day Grid -->
                            <div class="day-grid">
                                <?php
                                $current_day = clone $week_dates[$week]['start'];
                                $today = new DateTime();
                                $today->setTime(0, 0, 0);

                                while ($current_day <= $week_dates[$week]['end']):
                                    $date_str = $current_day->format('Y-m-d');
                                    $day_name = $current_day->format('l');
                                    $is_today = $current_day == $today;
                                ?>
                                    <div class="day-card <?php echo $is_today ? 'today' : ''; ?>"
                                        data-date="<?php echo $date_str; ?>"
                                        data-week="<?php echo $week; ?>"
                                        ondrop="drop(event)"
                                        ondragover="dragOver(event)"
                                        ondragleave="dragLeave(event)">

                                        <div class="day-header">
                                            <?php echo substr($day_name, 0, 3); ?>
                                            <div class="day-date"><?php echo $current_day->format('M d'); ?></div>
                                        </div>

                                        <div class="scheduled-items" id="day-<?php echo $date_str; ?>">
                                            <?php
                                            // Show existing schedules for this day
                                            foreach ($existing_schedules as $template_id => $schedule):
                                                $schedule_date = new DateTime($schedule['scheduled_publish_date']);
                                                if ($schedule_date->format('Y-m-d') === $date_str):
                                            ?>
                                                    <div class="scheduled-item <?php echo $schedule['content_type']; ?>"
                                                        data-schedule-id="<?php echo $schedule['id']; ?>"
                                                        data-template-id="<?php echo $template_id; ?>">
                                                        <div><strong><?php echo htmlspecialchars($schedule['title']); ?></strong></div>
                                                        <div class="scheduled-time">
                                                            <i class="far fa-clock"></i>
                                                            <?php echo $schedule_date->format('g:i A'); ?>
                                                        </div>
                                                        <div class="item-actions">
                                                            <button type="button" class="btn btn-sm btn-danger"
                                                                onclick="removeSchedule(<?php echo $schedule['id']; ?>, '<?php echo htmlspecialchars($schedule['title']); ?>')">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </div>

                                        <!-- Hidden form fields for scheduling -->
                                        <input type="hidden" name="schedules[<?php echo $template['id'] ?? 0; ?>][enabled]"
                                            value="0" id="enabled-<?php echo $date_str; ?>">
                                        <input type="hidden" name="schedules[<?php echo $template['id'] ?? 0; ?>][publish_date]"
                                            value="" id="date-<?php echo $date_str; ?>">
                                        <input type="hidden" name="schedules[<?php echo $template['id'] ?? 0; ?>][publish_time]"
                                            value="08:00:00" id="time-<?php echo $date_str; ?>">
                                    </div>
                                <?php
                                    $current_day->modify('+1 day');
                                endwhile;
                                ?>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <!-- Schedule Options -->
                    <div style="margin-top: 2rem; display: flex; gap: 1rem; align-items: center; justify-content: flex-end;">
                        <label>
                            <input type="checkbox" name="overwrite" value="1"> Overwrite existing schedules
                        </label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove Schedule Modal -->
    <div class="modal" id="removeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Remove Scheduled Item</h3>
                <button class="modal-close" onclick="closeRemoveModal()">&times;</button>
            </div>
            <p>Are you sure you want to remove "<span id="removeTitle"></span>" from the schedule?</p>
            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="remove_schedule">
                <input type="hidden" name="schedule_id" id="removeScheduleId">
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeRemoveModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Week navigation
        const weekBtns = document.querySelectorAll('.week-btn');
        const weekDisplays = document.querySelectorAll('.week-display');

        weekBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const week = this.dataset.week;

                weekBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                weekDisplays.forEach(d => d.style.display = 'none');
                document.getElementById(`week-${week}`).style.display = 'block';
            });
        });

        // Drag and drop functionality
        const templates = document.querySelectorAll('.template-item');
        let draggedTemplate = null;

        templates.forEach(template => {
            template.addEventListener('dragstart', function(e) {
                draggedTemplate = this;
                this.classList.add('dragging');
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    id: this.dataset.id,
                    type: this.dataset.type,
                    title: this.dataset.title,
                    week: this.dataset.week
                }));
            });

            template.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                draggedTemplate = null;
            });
        });

        function dragOver(e) {
            e.preventDefault();
            e.currentTarget.classList.add('drag-over');
        }

        function dragLeave(e) {
            e.preventDefault();
            e.currentTarget.classList.remove('drag-over');
        }

        function drop(e) {
            e.preventDefault();
            const dayCard = e.currentTarget;
            dayCard.classList.remove('drag-over');

            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const date = dayCard.dataset.date;
            const targetWeek = dayCard.dataset.week;

            // Check if template week matches target week
            if (data.week !== targetWeek) {
                alert(`This template is designed for Week ${data.week}. It should be scheduled in Week ${data.week} to maintain proper sequence.`);
                return;
            }

            // Check if already scheduled
            const existing = document.querySelector(`#day-${date} .scheduled-item[data-template-id="${data.id}"]`);
            if (existing) {
                alert('This item is already scheduled for this day.');
                return;
            }

            // Create scheduled item display
            const scheduledItems = document.getElementById(`day-${date}`);
            const itemDiv = document.createElement('div');
            itemDiv.className = `scheduled-item ${data.type}`;
            itemDiv.setAttribute('data-template-id', data.id);
            itemDiv.innerHTML = `
                <div><strong>${data.title}</strong></div>
                <div class="scheduled-time">
                    <i class="far fa-clock"></i> 
                    <input type="time" class="time-input" value="08:00" style="width: 80px; font-size: 0.7rem; border: 1px solid #e2e8f0; border-radius: 4px;" onchange="updateTime('${date}', ${data.id}, this.value)">
                </div>
                <div class="item-actions">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeUnscheduled(this, ${data.id}, '${data.title}')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            scheduledItems.appendChild(itemDiv);

            // Update hidden form fields
            const enabledInput = document.getElementById(`enabled-${date}`);
            const dateInput = document.getElementById(`date-${date}`);
            const timeInput = document.getElementById(`time-${date}`);

            // Since we need to support multiple templates per day, we need dynamic fields
            // This is a simplified version - in production, you'd want dynamic field generation
            addScheduleField(date, data.id, '08:00:00');

            // Mark template as scheduled
            const templateEl = document.getElementById(`template-${data.id}`);
            templateEl.classList.add('scheduled');

            // Add a small indicator to template
            const weekSpan = templateEl.querySelector('.template-week');
            weekSpan.innerHTML += ' <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Scheduled</span>';
        }

        function addScheduleField(date, templateId, time) {
            const form = document.getElementById('scheduleForm');

            // Remove existing fields for this template/date combination if any
            const existingEnabled = document.querySelector(`input[name="schedules[${templateId}][enabled]"]`);
            if (existingEnabled) {
                existingEnabled.value = '1';
                document.querySelector(`input[name="schedules[${templateId}][publish_date]"]`).value = date;
                document.querySelector(`input[name="schedules[${templateId}][publish_time]"]`).value = time;
                return;
            }

            // Create new fields
            const enabledInput = document.createElement('input');
            enabledInput.type = 'hidden';
            enabledInput.name = `schedules[${templateId}][enabled]`;
            enabledInput.value = '1';

            const dateInput = document.createElement('input');
            dateInput.type = 'hidden';
            dateInput.name = `schedules[${templateId}][publish_date]`;
            dateInput.value = date;

            const timeInput = document.createElement('input');
            timeInput.type = 'hidden';
            timeInput.name = `schedules[${templateId}][publish_time]`;
            timeInput.value = time;

            form.appendChild(enabledInput);
            form.appendChild(dateInput);
            form.appendChild(timeInput);
        }

        function updateTime(date, templateId, time) {
            // Update hidden field
            const timeInput = document.querySelector(`input[name="schedules[${templateId}][publish_time]"]`);
            if (timeInput) {
                timeInput.value = time + ':00';
            }
        }

        function removeUnscheduled(button, templateId, title) {
            if (confirm(`Remove "${title}" from schedule?`)) {
                const itemDiv = button.closest('.scheduled-item');
                const date = itemDiv.closest('.day-card').dataset.date;

                // Remove from display
                itemDiv.remove();

                // Update hidden field
                const enabledInput = document.querySelector(`input[name="schedules[${templateId}][enabled]"]`);
                if (enabledInput) {
                    enabledInput.value = '0';
                }

                // Remove scheduled indicator from template
                const templateEl = document.getElementById(`template-${templateId}`);
                templateEl.classList.remove('scheduled');
                const weekSpan = templateEl.querySelector('.template-week');
                weekSpan.innerHTML = weekSpan.innerHTML.replace(/ <span[^>]*>.*<\/span>/, '');
            }
        }

        function removeSchedule(scheduleId, title) {
            document.getElementById('removeScheduleId').value = scheduleId;
            document.getElementById('removeTitle').textContent = title;
            document.getElementById('removeModal').classList.add('show');
        }

        function closeRemoveModal() {
            document.getElementById('removeModal').classList.remove('show');
        }

        function selectAllWeek(week) {
            const weekDisplay = document.getElementById(`week-${week}`);
            const days = weekDisplay.querySelectorAll('.day-card');

            // This is a simplified version - you'd want to select templates based on their week
            alert('Select All functionality will schedule all templates for this week on their recommended days.');
        }

        // Auto-save functionality
        let autoSaveTimer;
        document.getElementById('scheduleForm').addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                // You could implement auto-save via AJAX here
                console.log('Auto-save triggered');
            }, 3000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('scheduleForm').submit();
            }

            // Esc to close modal
            if (e.key === 'Escape') {
                closeRemoveModal();
            }
        });

        // Initialize time pickers
        flatpickr("input[type=time]", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i",
            time_24hr: true
        });
    </script>
</body>

</html>