<?php
// modules/admin/academic/courses/template_manager.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

$admin_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get course ID from URL
if (!isset($_GET['course_id']) || !is_numeric($_GET['course_id'])) {
    header('Location: index.php');
    exit();
}

$course_id = (int)$_GET['course_id'];

// Get course details
$sql = "SELECT c.*, p.name as program_name, p.program_code 
        FROM courses c 
        JOIN programs p ON c.program_id = p.id 
        WHERE c.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$course = $result->fetch_assoc();
$stmt->close();

// Handle template creation
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_template') {
        $week_number = (int)$_POST['week_number'];
        $content_type = $_POST['content_type'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);

        // Initialize content data array
        $content_data = [
            'admin_id' => $admin_id,
            'week_number' => $week_number,
            'description' => $description
        ];

        $file_references = [];
        $upload_error = false;

        // Handle file uploads based on content type
        if ($content_type === 'material' && isset($_FILES['material_file']) && $_FILES['material_file']['error'] === 0) {
            $upload_result = handleTemplateFileUpload($_FILES['material_file'], $course_id, $content_type);
            if ($upload_result['success']) {
                $file_references[] = $upload_result['file_data'];
                $content_data['file_url'] = $upload_result['file_data']['path'];
                $content_data['file_type'] = $upload_result['file_data']['type'];
                $content_data['file_size'] = $upload_result['file_data']['size'];
                $content_data['original_filename'] = $upload_result['file_data']['original_name'];
            } else {
                $upload_error = $upload_result['error'];
            }
        } elseif ($content_type === 'assignment') {
            $content_data['total_points'] = (float)$_POST['total_points'];
            $content_data['submission_type'] = $_POST['submission_type'];
            $content_data['due_days'] = (int)$_POST['due_days']; // Days after publish
            $content_data['max_files'] = (int)$_POST['max_files'];
            $content_data['allowed_extensions'] = $_POST['allowed_extensions'];
            $content_data['instructions'] = trim($_POST['instructions'] ?? '');

            // Handle assignment attachment if any
            if (isset($_FILES['assignment_attachment']) && $_FILES['assignment_attachment']['error'] === 0) {
                $upload_result = handleTemplateFileUpload($_FILES['assignment_attachment'], $course_id, 'assignment_attachment');
                if ($upload_result['success']) {
                    $file_references[] = $upload_result['file_data'];
                    $content_data['has_attachment'] = true;
                    $content_data['attachment_path'] = $upload_result['file_data']['path'];
                    $content_data['original_filename'] = $upload_result['file_data']['original_name'];
                }
            }
        } elseif ($content_type === 'quiz') {
            $content_data['total_points'] = (float)$_POST['total_points'];
            $content_data['time_limit'] = (int)$_POST['time_limit'];
            $content_data['attempts_allowed'] = (int)$_POST['attempts_allowed'];
            $content_data['available_days'] = (int)$_POST['available_days']; // Days available after publish
            $content_data['shuffle_questions'] = isset($_POST['shuffle_questions']) ? 1 : 0;
            $content_data['shuffle_options'] = isset($_POST['shuffle_options']) ? 1 : 0;
            $content_data['show_correct_answers'] = isset($_POST['show_correct_answers']) ? 1 : 0;
            $content_data['instructions'] = trim($_POST['instructions'] ?? '');
        }

        if (!$upload_error) {
            // Save template to database
            $sql = "INSERT INTO course_content_templates 
                    (course_id, admin_id, week_number, content_type, title, content_data, file_references) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $insert_stmt = $conn->prepare($sql);
            $content_data_json = json_encode($content_data);
            $file_refs_json = json_encode($file_references);

            $insert_stmt->bind_param("iiissss", $course_id, $admin_id, $week_number, $content_type, $title, $content_data_json, $file_refs_json);

            if ($insert_stmt->execute()) {
                $message = "Template created successfully!";
                $message_type = "success";

                // Log activity
                logActivity('template_created', "Created {$content_type} template: {$title}", 'course_content_templates', $insert_stmt->insert_id);
            } else {
                $message = "Failed to create template: " . $conn->error;
                $message_type = "error";
            }
            $insert_stmt->close();
        } else {
            $message = $upload_error;
            $message_type = "error";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_template') {
        $template_id = (int)$_POST['template_id'];

        // Get file references to delete actual files
        $sql = "SELECT file_references FROM course_content_templates WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $file_refs = json_decode($row['file_references'], true);
            if (!empty($file_refs)) {
                foreach ($file_refs as $file) {
                    $file_path = __DIR__ . '/../../../../uploads/templates/' . $file['path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
        }
        $stmt->close();

        // Delete template
        $sql = "DELETE FROM course_content_templates WHERE id = ?";
        $delete_stmt = $conn->prepare($sql);
        $delete_stmt->bind_param("i", $template_id);

        if ($delete_stmt->execute()) {
            $message = "Template deleted successfully!";
            $message_type = "success";
            logActivity('template_deleted', "Deleted template ID: {$template_id}", 'course_content_templates', $template_id);
        } else {
            $message = "Failed to delete template: " . $conn->error;
            $message_type = "error";
        }
        $delete_stmt->close();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'duplicate_template') {
        $template_id = (int)$_POST['template_id'];
        $target_week = (int)$_POST['target_week'];

        // Get original template
        $sql = "SELECT * FROM course_content_templates WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Duplicate template with new week number
            $insert_sql = "INSERT INTO course_content_templates 
                          (course_id, admin_id, week_number, content_type, title, content_data, file_references) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";

            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iiissss",
                $row['course_id'],
                $row['admin_id'],
                $target_week,
                $row['content_type'],
                $row['title'] . ' (Copy)',
                $row['content_data'],
                $row['file_references']
            );

            if ($insert_stmt->execute()) {
                $message = "Template duplicated successfully!";
                $message_type = "success";
                logActivity('template_duplicated', "Duplicated template ID: {$template_id} to week {$target_week}", 'course_content_templates', $insert_stmt->insert_id);
            } else {
                $message = "Failed to duplicate template: " . $conn->error;
                $message_type = "error";
            }
            $insert_stmt->close();
        }
        $stmt->close();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
        $template_id = (int)$_POST['template_id'];
        $is_active = (int)$_POST['is_active'];

        $sql = "UPDATE course_content_templates SET is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $is_active, $template_id);

        if ($stmt->execute()) {
            $message = "Template " . ($is_active ? 'activated' : 'deactivated') . " successfully!";
            $message_type = "success";
        }
        $stmt->close();
    }
}

// Get all templates for this course
$templates_sql = "SELECT * FROM course_content_templates 
                  WHERE course_id = ? 
                  ORDER BY week_number, content_type, created_at";
$stmt = $conn->prepare($templates_sql);
$stmt->bind_param("i", $course_id);
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

// Helper function for file uploads
function handleTemplateFileUpload($file, $course_id, $type)
{
    $upload_dir = __DIR__ . '/../../../../uploads/templates/' . $course_id . '/';

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
    $target_path = $upload_dir . $unique_name;

    $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'zip', 'txt'];

    if (in_array($file_ext, $allowed_types)) {
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            return [
                'success' => true,
                'file_data' => [
                    'path' => 'uploads/templates/' . $course_id . '/' . $unique_name,
                    'original_name' => $file['name'],
                    'type' => $file_ext,
                    'size' => $file['size']
                ]
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to upload file'];
        }
    } else {
        return ['success' => false, 'error' => 'File type not allowed: ' . $file_ext];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Content Templates - <?php echo htmlspecialchars($course['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
            --warning: #f59e0b;
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
            padding: 2rem;
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

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
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

        .context-switch {
            margin-top: 1rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.95rem;
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

        .btn-warning {
            background: var(--warning);
            color: white;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        /* Week Tabs */
        .week-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .week-tab {
            padding: 1rem 2rem;
            background: white;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
            min-width: 100px;
            text-align: center;
        }

        .week-tab:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .week-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .week-tab .content-count {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
            font-size: 0.875rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 450px;
            gap: 2rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Week Content */
        .week-content {
            display: none;
        }

        .week-content.active {
            display: block;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 1rem;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }

        .content-card.inactive {
            opacity: 0.6;
        }

        .content-card.material {
            border-left-color: var(--info);
        }

        .content-card.assignment {
            border-left-color: var(--warning);
        }

        .content-card.quiz {
            border-left-color: var(--success);
        }

        .content-header {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            cursor: pointer;
        }

        .content-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-material {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .badge-assignment {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-quiz {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .content-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-top: 0.5rem;
        }

        .content-preview {
            display: none;
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
            background: var(--light);
        }

        .content-card.expanded .content-preview {
            display: block;
        }

        .content-actions {
            display: flex;
            gap: 0.5rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Form Panel */
        .form-panel {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 1rem;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }

        .form-panel h2 {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .file-upload {
            border: 2px dashed var(--light-gray);
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: var(--light);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .file-upload input {
            display: none;
        }

        .file-info {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: var(--light);
            border-radius: 4px;
            font-size: 0.85rem;
            border-left: 3px solid var(--primary);
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 3rem;
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
            max-width: 500px;
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
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/">Courses</a>
            <i class="fas fa-chevron-right"></i>
            <span>Template Manager</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-layer-group"></i> Content Template Manager</h1>
            <p><?php echo htmlspecialchars($course['program_name']); ?> - <?php echo htmlspecialchars($course['title']); ?> (<?php echo htmlspecialchars($course['course_code']); ?>)</p>
            <div class="context-switch">
                <a href="view.php?id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-eye"></i> View Course
                </a>
                <a href="edit.php?id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-edit"></i> Edit Course
                </a>
                <a href="../../academic/classes/schedule_builder.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-calendar-alt"></i> Go to Schedule Builder
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Week Tabs -->
        <div class="week-tabs" id="weekTabs">
            <?php for ($week = 1; $week <= 12; $week++): ?>
                <?php $count = isset($templates_by_week[$week]) ? count($templates_by_week[$week]) : 0; ?>
                <div class="week-tab <?php echo $week === 1 ? 'active' : ''; ?>" data-week="<?php echo $week; ?>">
                    Week <?php echo $week; ?>
                    <?php if ($count > 0): ?>
                        <span class="content-count"><?php echo $count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Week Content -->
            <div class="left-column">
                <?php for ($week = 1; $week <= 12; $week++): ?>
                    <div class="week-content <?php echo $week === 1 ? 'active' : ''; ?>" id="week-<?php echo $week; ?>">
                        <h2 style="margin-bottom: 1.5rem;">Week <?php echo $week; ?> Content Templates</h2>

                        <?php if (isset($templates_by_week[$week])): ?>
                            <?php foreach ($templates_by_week[$week] as $template): ?>
                                <div class="content-card <?php echo $template['content_type']; ?> <?php echo !$template['is_active'] ? 'inactive' : ''; ?>" id="template-<?php echo $template['id']; ?>">
                                    <div class="content-header" onclick="toggleTemplate(<?php echo $template['id']; ?>)">
                                        <div>
                                            <span class="content-type-badge badge-<?php echo $template['content_type']; ?>">
                                                <?php echo ucfirst($template['content_type']); ?>
                                            </span>
                                            <?php if (!$template['is_active']): ?>
                                                <span class="status-badge status-inactive">Inactive</span>
                                            <?php endif; ?>
                                            <div class="content-title">
                                                <?php echo htmlspecialchars($template['title']); ?>
                                            </div>
                                        </div>
                                        <div class="content-actions" onclick="event.stopPropagation()">
                                            <button class="btn btn-sm btn-secondary" onclick="editTemplate(<?php echo $template['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-<?php echo $template['is_active'] ? 'warning' : 'success'; ?>"
                                                onclick="toggleActive(<?php echo $template['id']; ?>, <?php echo $template['is_active'] ? 0 : 1; ?>, '<?php echo htmlspecialchars($template['title']); ?>')">
                                                <i class="fas fa-<?php echo $template['is_active'] ? 'pause' : 'play'; ?>"></i>
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="duplicateTemplate(<?php echo $template['id']; ?>, <?php echo $week; ?>)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['title']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="content-preview">
                                        <p><strong>Description:</strong> <?php echo htmlspecialchars($template['content_data']['description'] ?? ''); ?></p>

                                        <?php if ($template['content_type'] === 'material'): ?>
                                            <p><strong>File:</strong> <?php echo htmlspecialchars($template['content_data']['original_filename'] ?? 'No file'); ?></p>
                                            <p><small>File type: <?php echo strtoupper($template['content_data']['file_type'] ?? 'unknown'); ?></small></p>

                                        <?php elseif ($template['content_type'] === 'assignment'): ?>
                                            <p><strong>Points:</strong> <?php echo $template['content_data']['total_points']; ?></p>
                                            <p><strong>Submission Type:</strong> <?php echo ucfirst($template['content_data']['submission_type']); ?></p>
                                            <p><strong>Due Days:</strong> <?php echo $template['content_data']['due_days']; ?> days after publish</p>
                                            <?php if (!empty($template['content_data']['instructions'])): ?>
                                                <p><strong>Instructions:</strong> <?php echo htmlspecialchars(substr($template['content_data']['instructions'], 0, 100)); ?>...</p>
                                            <?php endif; ?>

                                        <?php elseif ($template['content_type'] === 'quiz'): ?>
                                            <p><strong>Points:</strong> <?php echo $template['content_data']['total_points']; ?></p>
                                            <p><strong>Time Limit:</strong> <?php echo $template['content_data']['time_limit']; ?> minutes</p>
                                            <p><strong>Attempts Allowed:</strong> <?php echo $template['content_data']['attempts_allowed']; ?></p>
                                            <p><strong>Available Days:</strong> <?php echo $template['content_data']['available_days']; ?> days</p>
                                        <?php endif; ?>

                                        <p><small>Created: <?php echo date('M d, Y', strtotime($template['created_at'])); ?></small></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p>No templates for this week yet.</p>
                                <p>Use the form on the right to create your first template.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <!-- Right Column - Template Creation Form -->
            <div class="right-column">
                <div class="form-panel">
                    <h2><i class="fas fa-plus-circle"></i> Create New Template</h2>

                    <form method="POST" enctype="multipart/form-data" id="templateForm">
                        <input type="hidden" name="action" value="create_template">

                        <div class="form-group">
                            <label for="week_number" class="required">Week Number</label>
                            <select id="week_number" name="week_number" class="form-control" required>
                                <?php for ($w = 1; $w <= 12; $w++): ?>
                                    <option value="<?php echo $w; ?>">Week <?php echo $w; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="content_type" class="required">Content Type</label>
                            <select id="content_type" name="content_type" class="form-control" required onchange="toggleContentFields()">
                                <option value="material">📄 Material</option>
                                <option value="assignment">📝 Assignment</option>
                                <option value="quiz">❓ Quiz</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="title" class="required">Title</label>
                            <input type="text" id="title" name="title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                        </div>

                        <!-- Common fields for assignment and quiz -->
                        <div id="common_fields" style="display: none;">
                            <div class="form-group">
                                <label for="instructions">Instructions</label>
                                <textarea id="instructions" name="instructions" class="form-control" rows="3"></textarea>
                            </div>
                        </div>

                        <!-- Material Fields -->
                        <div id="material_fields" class="content-fields">
                            <div class="form-group">
                                <label>Material File</label>
                                <div class="file-upload" onclick="document.getElementById('material_file').click()">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click to upload or drag and drop</p>
                                    <p class="form-help">PDF, Word, PowerPoint, Images, Video (Max: 100MB)</p>
                                    <input type="file" id="material_file" name="material_file"
                                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.mp4,.zip,.txt">
                                </div>
                                <div id="material_file_name" class="file-info" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Assignment Fields -->
                        <div id="assignment_fields" class="content-fields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="total_points">Total Points</label>
                                    <input type="number" id="total_points" name="total_points" class="form-control" value="100" step="0.5">
                                </div>
                                <div class="form-group">
                                    <label for="due_days">Due After (Days)</label>
                                    <input type="number" id="due_days" name="due_days" class="form-control" value="7" min="1">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="submission_type">Submission Type</label>
                                <select id="submission_type" name="submission_type" class="form-control">
                                    <option value="file">File Upload</option>
                                    <option value="text">Text Submission</option>
                                    <option value="both">Both File and Text</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="max_files">Max Files</label>
                                    <input type="number" id="max_files" name="max_files" class="form-control" value="1" min="1" max="10">
                                </div>
                                <div class="form-group">
                                    <label for="allowed_extensions">Allowed Extensions</label>
                                    <input type="text" id="allowed_extensions" name="allowed_extensions" class="form-control"
                                        value="pdf,doc,docx,jpg,jpeg,png">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Assignment Attachment (Optional)</label>
                                <div class="file-upload" onclick="document.getElementById('assignment_attachment').click()">
                                    <i class="fas fa-paperclip"></i> Attach file
                                    <input type="file" id="assignment_attachment" name="assignment_attachment"
                                        accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.zip">
                                </div>
                            </div>
                        </div>

                        <!-- Quiz Fields -->
                        <div id="quiz_fields" class="content-fields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="quiz_points">Total Points</label>
                                    <input type="number" id="quiz_points" name="total_points" class="form-control" value="100" step="0.5">
                                </div>
                                <div class="form-group">
                                    <label for="time_limit">Time Limit (minutes)</label>
                                    <input type="number" id="time_limit" name="time_limit" class="form-control" value="30" min="0">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="attempts_allowed">Attempts Allowed</label>
                                    <input type="number" id="attempts_allowed" name="attempts_allowed" class="form-control" value="1" min="1">
                                </div>
                                <div class="form-group">
                                    <label for="available_days">Available Days</label>
                                    <input type="number" id="available_days" name="available_days" class="form-control" value="7" min="1">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Quiz Settings</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                    <label class="form-check">
                                        <input type="checkbox" name="shuffle_questions" value="1"> Shuffle Questions
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="shuffle_options" value="1"> Shuffle Options
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="show_correct_answers" value="1" checked> Show Correct Answers
                                    </label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-save"></i> Create Template
                        </button>
                    </form>

                    <!-- Quick Stats -->
                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--light-gray);">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Quick Stats</h3>
                        <?php
                        $total_templates = count($templates);
                        $active_templates = count(array_filter($templates, function ($t) {
                            return $t['is_active'];
                        }));
                        ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div style="text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--primary);">
                                    <?php echo $total_templates; ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Total Templates</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--success);">
                                    <?php echo $active_templates; ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Active</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Delete Template</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <p>Are you sure you want to delete "<strong id="deleteTitle"></strong>"?</p>
            <p style="color: var(--gray); font-size: 0.9rem; margin-top: 0.5rem;">This action cannot be undone. Any classes using this template will lose scheduled content.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="template_id" id="deleteTemplateId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Template</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toggle Active Modal -->
    <div class="modal" id="toggleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Toggle Template Status</h3>
                <button class="modal-close" onclick="closeModal('toggleModal')">&times;</button>
            </div>
            <p id="toggleMessage"></p>
            <form method="POST" id="toggleForm">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="template_id" id="toggleTemplateId">
                <input type="hidden" name="is_active" id="toggleIsActive">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('toggleModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="toggleConfirmBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Duplicate Modal -->
    <div class="modal" id="duplicateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-copy"></i> Duplicate Template</h3>
                <button class="modal-close" onclick="closeModal('duplicateModal')">&times;</button>
            </div>
            <form method="POST" id="duplicateForm">
                <input type="hidden" name="action" value="duplicate_template">
                <input type="hidden" name="template_id" id="duplicateTemplateId">

                <div class="form-group">
                    <label for="target_week">Target Week</label>
                    <select id="target_week" name="target_week" class="form-control">
                        <?php for ($w = 1; $w <= 12; $w++): ?>
                            <option value="<?php echo $w; ?>">Week <?php echo $w; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('duplicateModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Duplicate</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Week tab switching
        const weekTabs = document.querySelectorAll('.week-tab');
        const weekContents = document.querySelectorAll('.week-content');

        weekTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const week = this.dataset.week;

                weekTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                weekContents.forEach(c => c.classList.remove('active'));
                document.getElementById(`week-${week}`).classList.add('active');

                // Update form week selection
                document.getElementById('week_number').value = week;
            });
        });

        // Toggle template expansion
        function toggleTemplate(id) {
            const card = document.getElementById(`template-${id}`);
            card.classList.toggle('expanded');
        }

        // Toggle content fields based on selected type
        function toggleContentFields() {
            const type = document.getElementById('content_type').value;
            const materialFields = document.getElementById('material_fields');
            const assignmentFields = document.getElementById('assignment_fields');
            const quizFields = document.getElementById('quiz_fields');
            const commonFields = document.getElementById('common_fields');

            materialFields.style.display = 'none';
            assignmentFields.style.display = 'none';
            quizFields.style.display = 'none';
            commonFields.style.display = 'none';

            if (type === 'material') {
                materialFields.style.display = 'block';
            } else if (type === 'assignment') {
                assignmentFields.style.display = 'block';
                commonFields.style.display = 'block';
            } else if (type === 'quiz') {
                quizFields.style.display = 'block';
                commonFields.style.display = 'block';
            }
        }

        // File upload preview
        document.getElementById('material_file')?.addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : '';
            const fileInfo = document.getElementById('material_file_name');

            if (fileName) {
                fileInfo.textContent = `Selected: ${fileName}`;
                fileInfo.style.display = 'block';
            } else {
                fileInfo.style.display = 'none';
            }
        });

        document.getElementById('assignment_attachment')?.addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : '';
            const parent = this.closest('.file-upload');
            const existingInfo = parent.nextElementSibling;

            if (fileName) {
                if (existingInfo && existingInfo.classList.contains('file-info')) {
                    existingInfo.textContent = `Selected: ${fileName}`;
                } else {
                    const info = document.createElement('div');
                    info.className = 'file-info';
                    info.textContent = `Selected: ${fileName}`;
                    parent.parentNode.insertBefore(info, parent.nextSibling);
                }
            }
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function deleteTemplate(id, title) {
            document.getElementById('deleteTemplateId').value = id;
            document.getElementById('deleteTitle').textContent = title;
            openModal('deleteModal');
        }

        function toggleActive(id, newState, title) {
            document.getElementById('toggleTemplateId').value = id;
            document.getElementById('toggleIsActive').value = newState;
            const message = `Are you sure you want to ${newState ? 'activate' : 'deactivate'} "${title}"?`;
            document.getElementById('toggleMessage').textContent = message;
            document.getElementById('toggleConfirmBtn').className = newState ? 'btn btn-success' : 'btn btn-warning';
            openModal('toggleModal');
        }

        function duplicateTemplate(id, currentWeek) {
            document.getElementById('duplicateTemplateId').value = id;
            document.getElementById('target_week').value = currentWeek;
            openModal('duplicateModal');
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // Escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });

        // Initialize form with current week
        document.addEventListener('DOMContentLoaded', function() {
            toggleContentFields();

            const activeTab = document.querySelector('.week-tab.active');
            if (activeTab) {
                document.getElementById('week_number').value = activeTab.dataset.week;
            }
        });

        // Drag and drop support
        const dropZones = document.querySelectorAll('.file-upload');

        dropZones.forEach(zone => {
            zone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--primary)';
                this.style.backgroundColor = 'rgba(37, 99, 235, 0.05)';
            });

            zone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--light-gray)';
                this.style.backgroundColor = 'white';
            });

            zone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--light-gray)';
                this.style.backgroundColor = 'white';

                const fileInput = this.querySelector('input[type="file"]');
                if (fileInput && e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    const event = new Event('change', {
                        bubbles: true
                    });
                    fileInput.dispatchEvent(event);
                }
            });
        });

        // Form validation
        document.getElementById('templateForm').addEventListener('submit', function(e) {
            const type = document.getElementById('content_type').value;

            if (type === 'material') {
                const file = document.getElementById('material_file').files[0];
                if (!file) {
                    e.preventDefault();
                    alert('Please select a file for the material.');
                    return false;
                }
            }
        });
    </script>
</body>

</html>