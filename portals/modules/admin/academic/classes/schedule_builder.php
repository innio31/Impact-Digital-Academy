<?php
// modules/instructor/classes/schedule_builder.php

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

// Get admin-created templates for this course
$templates_sql = "SELECT * FROM course_content_templates 
                  WHERE course_id = ? AND is_active = 1
                  ORDER BY week_number, content_type, created_at";
$stmt = $conn->prepare($templates_sql);
$stmt->bind_param("i", $class['course_id']);
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
$schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number,
                         cct.content_data
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
    $row['content_data'] = json_decode($row['content_data'], true);
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
            // Clear existing schedules for this class if overwrite is checked
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
            $schedules_sql = "SELECT ccs.*, cct.title, cct.content_type, cct.week_number,
                                     cct.content_data
                              FROM class_content_schedules ccs
                              JOIN course_content_templates cct ON ccs.template_id = cct.id
                              WHERE ccs.class_id = ?
                              ORDER BY ccs.scheduled_publish_date";
            $stmt2 = $conn->prepare($schedules_sql);
            $stmt2->bind_param("i", $class_id);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $existing_schedules = [];
            while ($row2 = $result2->fetch_assoc()) {
                $row2['content_data'] = json_decode($row2['content_data'], true);
                $existing_schedules[$row2['template_id']] = $row2;
            }
            $stmt2->close();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error saving schedule: " . $e->getMessage();
            $message_type = "error";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'remove_schedule') {
        $schedule_id = (int)$_POST['schedule_id'];

        $delete_sql = "DELETE FROM class_content_schedules WHERE id = ? AND class_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
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
            $stmt2->bind_param("i", $class_id);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $existing_schedules = [];
            while ($row2 = $result2->fetch_assoc()) {
                $row2['content_data'] = json_decode($row2['content_data'], true);
                $existing_schedules[$row2['template_id']] = $row2;
            }
            $stmt2->close();
        } else {
            $message = "Failed to remove schedule.";
            $message_type = "error";
        }
        $delete_stmt->close();
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
    <title>Schedule Builder - <?php echo htmlspecialchars($class['batch_code']); ?></title>
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
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <i class="fas fa-chevron-right"></i>
            <span>Schedule Builder</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Content Schedule Builder</h1>
            <p><?php echo htmlspecialchars($class['batch_code']); ?> - <?php echo htmlspecialchars($class['name']); ?></p>

            <div class="class-info">
                <div>
                    <strong><?php echo htmlspecialchars($class['course_title']); ?></strong>
                    <span style="color: var(--gray); margin-left: 0.5rem;">(<?php echo htmlspecialchars($class['course_code']); ?>)</span>
                </div>
                <div class="date-range">
                    <i class="fas fa-calendar"></i>
                    <?php echo $start_date->format('M d, Y'); ?> - <?php echo $end_date->format('M d, Y'); ?>
                    (<?php echo $total_weeks; ?> weeks)
                </div>
                <div class="nav-links">
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

        <!-- Stats -->
        <div class="stats-grid">
            <?php
            $total_scheduled = count($existing_schedules);
            $total_templates = count($templates);
            $scheduled_by_type = [
                'material' => 0,
                'assignment' => 0,
                'quiz' => 0
            ];
            foreach ($existing_schedules as $schedule) {
                if (isset($scheduled_by_type[$schedule['content_type']])) {
                    $scheduled_by_type[$schedule['content_type']]++;
                }
            }
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_scheduled; ?></div>
                <div class="stat-label">Scheduled Items</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_templates; ?></div>
                <div class="stat-label">Available Templates</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $scheduled_by_type['material']; ?></div>
                <div class="stat-label">Materials</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $scheduled_by_type['assignment']; ?></div>
                <div class="stat-label">Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $scheduled_by_type['quiz']; ?></div>
                <div class="stat-label">Quizzes</div>
            </div>
        </div>

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
            <div class="legend-item">
                <i class="fas fa-grip-vertical" style="color: var(--gray);"></i>
                <span>Drag to schedule</span>
            </div>
        </div>

        <!-- Week Navigation -->
        <div class="week-nav" id="weekNav">
            <?php for ($week = 1; $week <= $total_weeks; $week++): ?>
                <?php
                $has_scheduled = false;
                foreach ($existing_schedules as $schedule) {
                    $schedule_week = $schedule['week_number'];
                    if ($schedule_week == $week) {
                        $has_scheduled = true;
                        break;
                    }
                }
                ?>
                <button type="button"
                    class="week-btn <?php echo $week === 1 ? 'active' : ''; ?> <?php echo $has_scheduled ? 'has-scheduled' : ''; ?>"
                    data-week="<?php echo $week; ?>">
                    Week <?php echo $week; ?>
                    <div class="small"><?php echo $week_dates[$week]['start']->format('M d'); ?> - <?php echo $week_dates[$week]['end']->format('M d'); ?></div>
                </button>
            <?php endfor; ?>
        </div>

        <!-- Main Grid -->
        <form method="POST" id="scheduleForm">
            <input type="hidden" name="action" value="save_schedule">

            <div class="main-grid">
                <!-- Templates Panel (Left) -->
                <div class="templates-panel">
                    <h2><i class="fas fa-layer-group"></i> Available Templates</h2>

                    <?php if (empty($templates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>No templates available for this course.</p>
                            <p style="font-size: 0.8rem; margin-top: 0.5rem;">Templates are created by administrators.</p>
                        </div>
                    <?php else: ?>
                        <div class="templates-list" id="templateList">
                            <?php foreach ($templates as $template):
                                $is_scheduled = isset($existing_schedules[$template['id']]);
                                $week = $template['week_number'];
                                $content_data = $template['content_data'];
                            ?>
                                <div class="template-card <?php echo $is_scheduled ? 'scheduled' : ''; ?>"
                                    draggable="true"
                                    data-id="<?php echo $template['id']; ?>"
                                    data-type="<?php echo $template['content_type']; ?>"
                                    data-title="<?php echo htmlspecialchars($template['title']); ?>"
                                    data-week="<?php echo $week; ?>"
                                    data-description="<?php echo htmlspecialchars($content_data['description'] ?? ''); ?>"
                                    onclick="toggleTemplatePreview(<?php echo $template['id']; ?>)"
                                    id="template-<?php echo $template['id']; ?>">

                                    <span class="template-type type-<?php echo $template['content_type']; ?>">
                                        <?php echo ucfirst($template['content_type']); ?>
                                    </span>

                                    <div class="template-title">
                                        <?php echo htmlspecialchars($template['title']); ?>
                                    </div>

                                    <div class="template-week">
                                        <i class="fas fa-calendar-week"></i> Week <?php echo $week; ?>
                                        <?php if ($is_scheduled): ?>
                                            <span style="color: var(--success); margin-left: 0.5rem;">
                                                <i class="fas fa-check-circle"></i> Scheduled
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="template-preview" id="preview-<?php echo $template['id']; ?>">
                                        <?php if ($template['content_type'] === 'material'): ?>
                                            <p><i class="fas fa-file"></i> <?php echo htmlspecialchars($content_data['original_filename'] ?? 'No file'); ?></p>
                                        <?php elseif ($template['content_type'] === 'assignment'): ?>
                                            <p><i class="fas fa-star"></i> Points: <?php echo $content_data['total_points']; ?></p>
                                            <p><i class="fas fa-clock"></i> Due: <?php echo $content_data['due_days']; ?> days after publish</p>
                                        <?php elseif ($template['content_type'] === 'quiz'): ?>
                                            <p><i class="fas fa-star"></i> Points: <?php echo $content_data['total_points']; ?></p>
                                            <p><i class="fas fa-hourglass"></i> Time: <?php echo $content_data['time_limit']; ?> min</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 1rem; font-size: 0.8rem; color: var(--gray);">
                            <i class="fas fa-info-circle"></i> Drag templates to the calendar to schedule them
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Calendar Panel (Right) -->
                <div class="calendar-panel">
                    <?php for ($week = 1; $week <= $total_weeks; $week++): ?>
                        <div class="week-display <?php echo $week === 1 ? 'active' : ''; ?>" id="week-<?php echo $week; ?>">
                            <div class="week-header">
                                <div>
                                    <span class="week-title">Week <?php echo $week; ?></span>
                                    <span class="week-dates">
                                        <?php echo $week_dates[$week]['start']->format('M d, Y'); ?> -
                                        <?php echo $week_dates[$week]['end']->format('M d, Y'); ?>
                                    </span>
                                </div>
                                <div class="quick-actions">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="scheduleAllWeek(<?php echo $week; ?>)">
                                        <i class="fas fa-calendar-plus"></i> Schedule All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="clearWeek(<?php echo $week; ?>)">
                                        <i class="fas fa-eraser"></i> Clear Week
                                    </button>
                                </div>
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
                                    $day_short = substr($day_name, 0, 3);
                                    $is_today = $current_day == $today;
                                ?>
                                    <div class="day-card <?php echo $is_today ? 'today' : ''; ?>"
                                        data-date="<?php echo $date_str; ?>"
                                        data-week="<?php echo $week; ?>"
                                        ondrop="drop(event)"
                                        ondragover="dragOver(event)"
                                        ondragleave="dragLeave(event)">

                                        <div class="day-header">
                                            <span class="day-name"><?php echo $day_short; ?></span>
                                            <span class="day-date"><?php echo $current_day->format('M d'); ?></span>
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
                                                        data-template-id="<?php echo $template_id; ?>"
                                                        onclick="editSchedule(<?php echo $schedule['id']; ?>)">
                                                        <div class="item-title"><?php echo htmlspecialchars($schedule['title']); ?></div>
                                                        <div class="item-time">
                                                            <i class="far fa-clock"></i>
                                                            <?php echo $schedule_date->format('g:i A'); ?>
                                                        </div>
                                                        <button type="button" class="item-remove"
                                                            onclick="event.stopPropagation(); removeSchedule(<?php echo $schedule['id']; ?>, '<?php echo htmlspecialchars($schedule['title']); ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </div>

                                        <!-- Hidden form fields will be added dynamically via JavaScript -->
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
                        <label class="form-check">
                            <input type="checkbox" name="overwrite" value="1"> Overwrite existing schedules
                        </label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Schedule
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Remove Schedule Modal -->
    <div class="modal" id="removeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-trash" style="color: var(--danger);"></i> Remove Scheduled Item</h3>
                <button class="modal-close" onclick="closeModal('removeModal')">&times;</button>
            </div>
            <p>Are you sure you want to remove "<strong id="removeTitle"></strong>" from the schedule?</p>
            <form method="POST" id="removeForm">
                <input type="hidden" name="action" value="remove_schedule">
                <input type="hidden" name="schedule_id" id="removeScheduleId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('removeModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner" id="loadingSpinner">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Saving schedule...</p>
    </div>

    <script>
        // Drag and drop functionality
        const templates = document.querySelectorAll('.template-card');
        let draggedTemplate = null;

        templates.forEach(template => {
            template.addEventListener('dragstart', function(e) {
                draggedTemplate = this;
                this.classList.add('dragging');
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    id: this.dataset.id,
                    type: this.dataset.type,
                    title: this.dataset.title,
                    week: parseInt(this.dataset.week),
                    description: this.dataset.description
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
            const targetWeek = parseInt(dayCard.dataset.week);

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

            // Show time selection modal
            showTimeSelectionModal(data, date);
        }

        // Time selection modal
        function showTimeSelectionModal(template, date) {
            const modal = document.createElement('div');
            modal.className = 'modal show';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="far fa-clock"></i> Select Publish Time</h3>
                        <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
                    </div>
                    <p>Scheduling: <strong>${template.title}</strong></p>
                    <p>Date: ${new Date(date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
                    
                    <div class="form-group" style="margin-top: 1.5rem;">
                        <label for="publishTime">Publish Time</label>
                        <select id="publishTime" class="form-control">
                            <?php echo getTimeOptions(); ?>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="confirmSchedule(this, ${template.id}, '${date}')">Schedule</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Confirm schedule
        function confirmSchedule(btn, templateId, date) {
            const modal = btn.closest('.modal');
            const time = document.getElementById('publishTime').value;

            // Get template data
            const template = document.querySelector(`#template-${templateId}`);
            const type = template.dataset.type;
            const title = template.dataset.title;

            // Create scheduled item display
            const dayElement = document.querySelector(`#day-${date}`);
            const itemDiv = document.createElement('div');
            itemDiv.className = `scheduled-item ${type}`;
            itemDiv.setAttribute('data-template-id', templateId);
            itemDiv.innerHTML = `
                <div class="item-title">${title}</div>
                <div class="item-time">
                    <i class="far fa-clock"></i> 
                    ${new Date('2000-01-01 ' + time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                </div>
                <button type="button" class="item-remove" onclick="event.stopPropagation(); removeUnscheduled(this, ${templateId}, '${title}')">
                    <i class="fas fa-times"></i>
                </button>
            `;
            dayElement.appendChild(itemDiv);

            // Add form fields
            addScheduleField(date, templateId, time);

            // Mark template as scheduled
            template.classList.add('scheduled');
            const weekSpan = template.querySelector('.template-week');
            if (!weekSpan.innerHTML.includes('Scheduled')) {
                weekSpan.innerHTML += ' <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Scheduled</span>';
            }

            // Remove modal
            modal.remove();
        }

        function addScheduleField(date, templateId, time) {
            const form = document.getElementById('scheduleForm');

            // Check if fields already exist
            const existingEnabled = document.querySelector(`input[name="schedules[${templateId}][enabled]"]`);
            if (existingEnabled) {
                existingEnabled.value = '1';
                document.querySelector(`input[name="schedules[${templateId}][publish_date]"]`).value = date;
                document.querySelector(`input[name="schedules[${templateId}][publish_time]"]`).value = time + ':00';
                return;
            }

            // Create container div for better organization
            const container = document.createElement('div');
            container.style.display = 'none';

            // Create hidden fields
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
            timeInput.value = time + ':00';

            container.appendChild(enabledInput);
            container.appendChild(dateInput);
            container.appendChild(timeInput);
            form.appendChild(container);
        }

        function removeUnscheduled(button, templateId, title) {
            if (confirm(`Remove "${title}" from schedule?`)) {
                const itemDiv = button.closest('.scheduled-item');
                const date = itemDiv.closest('.day-card').dataset.date;

                // Remove from display
                itemDiv.remove();

                // Remove or disable hidden fields
                const enabledInput = document.querySelector(`input[name="schedules[${templateId}][enabled]"]`);
                if (enabledInput) {
                    enabledInput.value = '0';
                }

                // Remove scheduled indicator from template
                const template = document.getElementById(`template-${templateId}`);
                template.classList.remove('scheduled');
                const weekSpan = template.querySelector('.template-week');
                weekSpan.innerHTML = weekSpan.innerHTML.replace(/ <span[^>]*>.*<\/span>/, '');
            }
        }

        // Remove existing schedule
        function removeSchedule(scheduleId, title) {
            document.getElementById('removeScheduleId').value = scheduleId;
            document.getElementById('removeTitle').textContent = title;
            document.getElementById('removeModal').classList.add('show');
        }

        // Toggle template preview
        function toggleTemplatePreview(id) {
            const template = document.getElementById(`template-${id}`);
            template.classList.toggle('expanded');
        }

        // Edit schedule
        function editSchedule(scheduleId) {
            // Implement edit functionality if needed
            console.log('Edit schedule:', scheduleId);
        }

        // Week navigation
        const weekBtns = document.querySelectorAll('.week-btn');
        const weekDisplays = document.querySelectorAll('.week-display');

        weekBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const week = this.dataset.week;

                weekBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                weekDisplays.forEach(d => d.classList.remove('active'));
                document.getElementById(`week-${week}`).classList.add('active');
            });
        });

        // Schedule all templates for a week
        function scheduleAllWeek(week) {
            const templates = document.querySelectorAll(`.template-card[data-week="${week}"]:not(.scheduled)`);
            if (templates.length === 0) {
                alert('All templates for this week are already scheduled.');
                return;
            }

            if (!confirm(`Schedule all ${templates.length} templates for this week? They will be scheduled on their recommended days.`)) {
                return;
            }

            // Get week dates
            const weekDisplay = document.getElementById(`week-${week}`);
            const dayCards = weekDisplay.querySelectorAll('.day-card');

            templates.forEach((template, index) => {
                // Distribute templates across days
                const dayIndex = index % dayCards.length;
                const dayCard = dayCards[dayIndex];
                const date = dayCard.dataset.date;

                const templateData = {
                    id: template.dataset.id,
                    type: template.dataset.type,
                    title: template.dataset.title,
                    week: parseInt(template.dataset.week)
                };

                // Schedule with default time (8 AM)
                confirmSchedule({
                        closest: () => ({
                            remove: () => {}
                        })
                    },
                    templateData.id,
                    date
                );
            });
        }

        // Clear all schedules for a week
        function clearWeek(week) {
            if (!confirm('Are you sure you want to remove all scheduled items for this week?')) {
                return;
            }

            const weekDisplay = document.getElementById(`week-${week}`);
            const scheduledItems = weekDisplay.querySelectorAll('.scheduled-item');

            scheduledItems.forEach(item => {
                const templateId = item.dataset.templateId;
                if (templateId) {
                    // Remove hidden fields
                    const enabledInput = document.querySelector(`input[name="schedules[${templateId}][enabled]"]`);
                    if (enabledInput) {
                        enabledInput.value = '0';
                    }

                    // Remove scheduled indicator from template
                    const template = document.getElementById(`template-${templateId}`);
                    if (template) {
                        template.classList.remove('scheduled');
                        const weekSpan = template.querySelector('.template-week');
                        weekSpan.innerHTML = weekSpan.innerHTML.replace(/ <span[^>]*>.*<\/span>/, '');
                    }
                }
            });

            // Remove from display
            scheduledItems.forEach(item => item.remove());
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });

        // Escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });

        // Form submission with loading spinner
        document.getElementById('scheduleForm').addEventListener('submit', function() {
            document.getElementById('loadingSpinner').style.display = 'block';
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set active week based on current date
            const today = new Date();
            const classStart = new Date('<?php echo $class['start_date']; ?>');
            const daysDiff = Math.floor((today - classStart) / (1000 * 60 * 60 * 24));
            const currentWeek = Math.max(1, Math.min(<?php echo $total_weeks; ?>, Math.floor(daysDiff / 7) + 1));

            if (currentWeek >= 1 && currentWeek <= <?php echo $total_weeks; ?>) {
                document.querySelector(`.week-btn[data-week="${currentWeek}"]`).click();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('scheduleForm').submit();
            }
        });

        // Auto-save functionality (optional)
        let autoSaveTimer;
        document.getElementById('scheduleForm').addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                // Could implement auto-save via AJAX
                console.log('Auto-save triggered');
            }, 5000);
        });
    </script>
</body>

</html>