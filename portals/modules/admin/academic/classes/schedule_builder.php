<?php
// modules/instructor/classes/schedule_builder.php

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.id as course_id,
               p.name as program_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id
        WHERE cb.id = ? AND cb.instructor_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Check if course_content_templates table exists
$table_check = $conn->query("SHOW TABLES LIKE 'course_content_templates'");
if ($table_check->num_rows === 0) {
    die("Error: course_content_templates table does not exist. Please run the SQL setup first.");
}

// Get admin-created templates for this course
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
    // Safely decode JSON with error checking
    $row['content_data'] = json_decode($row['content_data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $row['content_data'] = [];
    }

    $row['file_references'] = json_decode($row['file_references'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $row['file_references'] = [];
    }

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
$schedules_table_exists = ($schedule_table_check->num_rows > 0);

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
            $row['content_data'] = json_decode($row['content_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $row['content_data'] = [];
            }
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
        $message = "Error: class_content_schedules table does not exist. Please run the SQL setup first.";
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
                    $row2['content_data'] = json_decode($row2['content_data'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $row2['content_data'] = [];
                    }
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
                        $row2['content_data'] = json_decode($row2['content_data'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $row2['content_data'] = [];
                        }
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

// Helper function to get time options
function getTimeOptions($selected = '08:00')
{
    $options = [];
    for ($hour = 0; $hour < 24; $hour++) {
        for ($minute = 0; $minute < 60; $minute += 30) {
            $time = sprintf("%02d:%02d", $hour, $minute);
            $display = date('g:i A', strtotime($time));
            $selected_attr = ($time === $selected) ? 'selected' : '';
            $options[] = "<option value=\"{$time}\" {$selected_attr}>{$display}</option>";
        }
    }
    return implode('', $options);
}
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

        /* Breadcrumb */
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

        .nav-links {
            display: flex;
            gap: 0.5rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        /* Week Navigation */
        .week-nav {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .week-btn {
            padding: 1rem 1.5rem;
            background: white;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            white-space: nowrap;
            transition: all 0.3s ease;
            min-width: 120px;
            text-align: center;
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

        .week-btn.has-scheduled {
            position: relative;
        }

        .week-btn.has-scheduled::after {
            content: '';
            position: absolute;
            top: -4px;
            right: -4px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border-radius: 50%;
            border: 2px solid white;
        }

        .week-btn .small {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        /* Main Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .main-grid {
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

        .templates-panel h2 i {
            color: var(--primary);
        }

        .templates-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .template-card {
            background: var(--light);
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: grab;
            transition: all 0.3s ease;
        }

        .template-card:hover {
            border-color: var(--primary);
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .template-card.dragging {
            opacity: 0.5;
            cursor: grabbing;
            transform: scale(0.95);
        }

        .template-card.scheduled {
            border-left: 4px solid var(--success);
            background: #f0fdf4;
        }

        .template-type {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
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
            font-size: 0.95rem;
        }

        .template-week {
            font-size: 0.75rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .template-preview {
            display: none;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: white;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .template-card.expanded .template-preview {
            display: block;
        }

        /* Calendar Panel */
        .calendar-panel {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .week-display {
            display: none;
        }

        .week-display.active {
            display: block;
        }

        .week-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .week-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .week-dates {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .quick-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Day Grid */
        .day-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .day-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }

        .day-card {
            background: var(--light);
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            padding: 1rem;
            min-height: 180px;
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
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .day-name {
            font-size: 0.85rem;
        }

        .day-date {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .scheduled-items {
            min-height: 100px;
        }

        .scheduled-item {
            background: white;
            border-left: 3px solid;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: relative;
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

        .scheduled-item .item-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
            padding-right: 1.5rem;
        }

        .scheduled-item .item-time {
            font-size: 0.65rem;
            color: var(--gray);
        }

        .scheduled-item .item-remove {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .scheduled-item:hover .item-remove {
            opacity: 1;
        }

        /* Legend */
        .legend {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            padding: 1rem;
            background: var(--light);
            border-radius: 8px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
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

        /* Form */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-danger {
            background: white;
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* Message */
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
            max-width: 400px;
            width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 2000;
            text-align: center;
        }

        .spinner i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Debug Info - Remove in production -->
        <div class="debug-info">
            <strong>Debug Information:</strong>
            <pre>
Class ID: <?php echo $class_id; ?>
Course ID: <?php echo $class['course_id'] ?? 'Not found'; ?>
Templates Found: <?php echo count($templates); ?>
Schedules Found: <?php echo count($existing_schedules); ?>
Total Weeks: <?php echo $total_weeks; ?>
            </pre>
        </div>

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

            <div style="margin-top: 1rem;">
                <strong><?php echo htmlspecialchars($class['course_title'] ?? ''); ?></strong>
                <span style="color: var(--gray); margin-left: 0.5rem;">(<?php echo htmlspecialchars($class['course_code'] ?? ''); ?>)</span>
                <span style="margin-left: 2rem;">
                    <i class="fas fa-calendar"></i>
                    <?php echo $start_date->format('M d, Y'); ?> - <?php echo $end_date->format('M d, Y'); ?>
                    (<?php echo $total_weeks; ?> weeks)
                </span>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Simple placeholders until CSS is fixed -->
        <div style="background: white; padding: 2rem; border-radius: 12px; text-align: center;">
            <i class="fas fa-tools" style="font-size: 3rem; color: var(--primary); margin-bottom: 1rem;"></i>
            <h2>Schedule Builder Loading...</h2>
            <p>Found <?php echo count($templates); ?> templates and <?php echo count($existing_schedules); ?> existing schedules.</p>

            <?php if (empty($templates)): ?>
                <div style="margin-top: 2rem; padding: 2rem; background: var(--light); border-radius: 8px;">
                    <p>No templates available for this course. Templates must be created by an administrator first.</p>
                    <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn" style="display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: var(--primary); color: white; text-decoration: none; border-radius: 8px;">Back to Class</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>