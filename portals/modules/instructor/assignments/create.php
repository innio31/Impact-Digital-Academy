<?php
// modules/instructor/assignments/create.php

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

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];
$errors = [];
$success = false;

// Get instructor's classes
$sql_classes = "SELECT cb.id, cb.batch_code, cb.name, c.title as course_title, c.course_code
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id 
                WHERE cb.instructor_id = ? AND cb.status IN ('ongoing', 'scheduled')
                ORDER BY cb.start_date";
$stmt = $conn->prepare($sql_classes);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $instructions = isset($_POST['instructions']) ? trim($_POST['instructions']) : '';
    $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : '';
    $total_points = isset($_POST['total_points']) ? floatval($_POST['total_points']) : 100.00;
    $submission_type = isset($_POST['submission_type']) ? $_POST['submission_type'] : 'file';
    $max_files = isset($_POST['max_files']) ? intval($_POST['max_files']) : 1;
    $allowed_extensions = isset($_POST['allowed_extensions']) ? trim($_POST['allowed_extensions']) : '';
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    // Validation
    if (empty($class_id)) {
        $errors[] = "Please select a class.";
    }

    if (empty($title)) {
        $errors[] = "Assignment title is required.";
    } elseif (strlen($title) > 200) {
        $errors[] = "Title must be less than 200 characters.";
    }

    if (empty($due_date)) {
        $errors[] = "Due date is required.";
    } elseif (strtotime($due_date) < time()) {
        $errors[] = "Due date must be in the future.";
    }

    if ($total_points <= 0 || $total_points > 1000) {
        $errors[] = "Total points must be between 1 and 1000.";
    }

    if ($max_files < 1 || $max_files > 10) {
        $errors[] = "Maximum files must be between 1 and 10.";
    }

    // Check if instructor teaches this class
    $check_sql = "SELECT id FROM class_batches WHERE id = ? AND instructor_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $class_id, $instructor_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows === 0) {
        $errors[] = "You are not assigned to teach this class.";
    }
    $check_stmt->close();

    // If no errors, insert assignment
    if (empty($errors)) {
        $sql = "INSERT INTO assignments (class_id, instructor_id, title, description, instructions, 
                due_date, total_points, submission_type, max_files, allowed_extensions, is_published, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iissssdsiisi",
            $class_id,
            $instructor_id,
            $title,
            $description,
            $instructions,
            $due_date,
            $total_points,
            $submission_type,
            $max_files,
            $allowed_extensions,
            $is_published
        );

        if ($stmt->execute()) {
            $assignment_id = $stmt->insert_id;
            $success = true;

            // Log activity
            logActivity('assignment_created', "Created assignment: {$title}", $assignment_id);

            // Redirect to view page or show success
            if ($is_published) {
                // Send notifications to students if published
                sendAssignmentNotification($assignment_id, $conn);
            }
        } else {
            $errors[] = "Failed to create assignment. Please try again.";
        }
        $stmt->close();
    }
}

// Close database connection
$conn->close();

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assignment - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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
            min-height: 100vh;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e40af;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .header-user {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .user-name {
            font-weight: 600;
        }

        .user-role {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            margin-left: 2rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.2rem;
            color: white;
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        /* Breadcrumb */
        .breadcrumb {
            background: var(--light);
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .breadcrumb-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .breadcrumb-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .breadcrumb-link {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb-separator {
            color: var(--gray);
        }

        .breadcrumb-current {
            color: var(--dark);
            font-weight: 500;
        }

        /* Main Container */
        .create-container {
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
            min-height: calc(100vh - 120px);
        }

        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }

        .page-title h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 28px;
        }

        .page-title p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #27ae60;
            color: white;
        }

        .btn-primary:hover {
            background: #219653;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #34495e;
            border: 1px solid #bdc3c7;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        .btn-back {
            background: #95a5a6;
            color: white;
        }

        .btn-back:hover {
            background: #7f8c8d;
        }

        .form-container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eee;
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #3498db;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-label.required:after {
            content: ' *';
            color: #e74c3c;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }

        .form-text i {
            margin-right: 5px;
        }

        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .radio-option input[type="radio"] {
            margin: 0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            margin: 0;
        }

        .error-message {
            color: #e74c3c;
            font-size: 13px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert i {
            font-size: 18px;
        }

        .preview-section {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin-top: 30px;
            border: 1px dashed #dee2e6;
        }

        .preview-title {
            font-size: 16px;
            color: #495057;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-content {
            background: white;
            padding: 20px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }

        .date-time-input {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 576px) {
            .date-time-input {
                flex-direction: column;
            }

            .header-container {
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links {
                margin-left: 0;
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        .extensions-hint {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }

        .extension-tag {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .extension-tag:hover {
            background: #bbdefb;
        }

        .file-preview {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }

        .file-preview img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 4px;
        }

        /* Footer-less design - content takes full remaining height */
        .create-container {
            min-height: calc(100vh - 180px);
        }

        .form-container {
            margin-bottom: 2rem;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-container">
            <div class="logo-section">
                <div class="logo">IDA</div>
                <div class="logo-text">Impact Digital Academy</div>
                <div class="nav-links">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-link">
                        <i class="fas fa-chalkboard"></i> Classes
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="nav-link">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                </div>
            </div>

            <div class="header-user">
                <div class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-count">3</span>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($instructor_name); ?></div>
                        <div class="user-role">Instructor</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <div class="breadcrumb-container">
            <div class="breadcrumb-links">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="breadcrumb-link">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <span class="breadcrumb-separator">/</span>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="breadcrumb-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">
                    <i class="fas fa-plus-circle"></i> Create Assignment
                </span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="create-container">
        <div class="page-header">
            <div class="page-title">
                <h1>Create New Assignment</h1>
                <p>Design and publish assignments for your students</p>
            </div>

            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Assignments
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Assignment created successfully!
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment_id; ?>"
                    style="color: #155724; text-decoration: underline; margin-left: 10px;">
                    View Assignment
                </a>
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="form-container" id="assignmentForm">
            <!-- Basic Information -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-info-circle"></i> Basic Information
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="class_id" class="form-label required">Select Class</label>
                        <select name="class_id" id="class_id" class="form-control" required>
                            <option value="">-- Choose a Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Select the class for this assignment
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="title" class="form-label required">Assignment Title</label>
                        <input type="text" name="title" id="title" class="form-control"
                            value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                            required maxlength="200" placeholder="Enter assignment title">
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Maximum 200 characters
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control"
                        rows="3" placeholder="Brief description of the assignment"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Provide a clear description of what students need to do
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="instructions" class="form-label">Detailed Instructions</label>
                    <textarea name="instructions" id="instructions" class="form-control"
                        rows="6" placeholder="Step-by-step instructions for students"><?php echo isset($_POST['instructions']) ? htmlspecialchars($_POST['instructions']) : ''; ?></textarea>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Include grading criteria, formatting requirements, etc.
                    </div>
                </div>
            </div>

            <!-- Submission Details -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-upload"></i> Submission Details
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="due_date" class="form-label required">Due Date & Time</label>
                        <input type="datetime-local" name="due_date" id="due_date" class="form-control"
                            value="<?php echo isset($_POST['due_date']) ? htmlspecialchars($_POST['due_date']) : ''; ?>"
                            required>
                        <div class="form-text">
                            <i class="fas fa-clock"></i> Students can submit until this time
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="total_points" class="form-label required">Total Points</label>
                        <input type="number" name="total_points" id="total_points" class="form-control"
                            value="<?php echo isset($_POST['total_points']) ? htmlspecialchars($_POST['total_points']) : '100.00'; ?>"
                            step="0.01" min="1" max="1000" required>
                        <div class="form-text">
                            <i class="fas fa-star"></i> Maximum score for this assignment (1-1000)
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="submission_type" class="form-label required">Submission Type</label>
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="submission_type" id="type_file" value="file"
                                    <?php echo (!isset($_POST['submission_type']) || $_POST['submission_type'] == 'file') ? 'checked' : ''; ?>>
                                <label for="type_file">File Upload</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="submission_type" id="type_text" value="text"
                                    <?php echo (isset($_POST['submission_type']) && $_POST['submission_type'] == 'text') ? 'checked' : ''; ?>>
                                <label for="type_text">Text Entry</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="submission_type" id="type_both" value="both"
                                    <?php echo (isset($_POST['submission_type']) && $_POST['submission_type'] == 'both') ? 'checked' : ''; ?>>
                                <label for="type_both">Both</label>
                            </div>
                        </div>
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Choose how students will submit their work
                        </div>
                    </div>

                    <div class="form-group" id="max_files_group">
                        <label for="max_files" class="form-label">Maximum Files</label>
                        <input type="number" name="max_files" id="max_files" class="form-control"
                            value="<?php echo isset($_POST['max_files']) ? htmlspecialchars($_POST['max_files']) : '1'; ?>"
                            min="1" max="10">
                        <div class="form-text">
                            <i class="fas fa-file"></i> Maximum number of files a student can upload
                        </div>
                    </div>

                    <div class="form-group full-width" id="extensions_group">
                        <label for="allowed_extensions" class="form-label">Allowed File Types</label>
                        <input type="text" name="allowed_extensions" id="allowed_extensions" class="form-control"
                            value="<?php echo isset($_POST['allowed_extensions']) ? htmlspecialchars($_POST['allowed_extensions']) : 'pdf,doc,docx,txt,jpg,jpeg,png'; ?>"
                            placeholder="pdf,doc,docx,txt,jpg,jpeg,png">
                        <div class="form-text">
                            <i class="fas fa-file-alt"></i> Comma-separated list of allowed file extensions
                        </div>
                        <div class="extensions-hint">
                            <span class="extension-tag" onclick="addExtension('pdf')">PDF</span>
                            <span class="extension-tag" onclick="addExtension('doc')">DOC</span>
                            <span class="extension-tag" onclick="addExtension('docx')">DOCX</span>
                            <span class="extension-tag" onclick="addExtension('txt')">TXT</span>
                            <span class="extension-tag" onclick="addExtension('jpg')">JPG</span>
                            <span class="extension-tag" onclick="addExtension('png')">PNG</span>
                            <span class="extension-tag" onclick="addExtension('zip')">ZIP</span>
                            <span class="extension-tag" onclick="addExtension('ppt')">PPT</span>
                            <span class="extension-tag" onclick="addExtension('xls')">XLS</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Publishing Options -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-paper-plane"></i> Publishing Options
                </h2>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_published" id="is_published" value="1"
                            <?php echo (!isset($_POST['is_published']) || $_POST['is_published'] == 1) ? 'checked' : ''; ?>>
                        <label for="is_published" style="font-weight: 600; color: #2c3e50;">
                            Publish assignment immediately
                        </label>
                    </div>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> If checked, assignment will be visible to students immediately.
                        If unchecked, it will be saved as a draft.
                    </div>
                </div>

                <div class="preview-section">
                    <div class="preview-title">
                        <i class="fas fa-eye"></i> Preview
                    </div>
                    <div class="preview-content">
                        <div id="titlePreview" style="font-weight: bold; font-size: 18px; margin-bottom: 10px;">
                            <?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '[Assignment Title]'; ?>
                        </div>
                        <div id="dueDatePreview" style="color: #666; margin-bottom: 15px;">
                            <i class="far fa-calendar-alt"></i> Due:
                            <span id="dueDateText">
                                <?php echo isset($_POST['due_date']) ? date('F j, Y g:i A', strtotime($_POST['due_date'])) : '[Due Date]'; ?>
                            </span>
                        </div>
                        <div id="pointsPreview" style="color: #666; margin-bottom: 15px;">
                            <i class="fas fa-star"></i> Points:
                            <span id="pointsText">
                                <?php echo isset($_POST['total_points']) ? htmlspecialchars($_POST['total_points']) : '100'; ?>
                            </span>
                        </div>
                        <div id="descriptionPreview" style="color: #555; line-height: 1.6;">
                            <?php echo isset($_POST['description']) && !empty($_POST['description']) ?
                                htmlspecialchars($_POST['description']) : '[Assignment description will appear here]'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="form-section">
                <div style="display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="submit" name="save_draft" value="1" class="btn btn-secondary">
                        <i class="fas fa-save"></i> Save as Draft
                    </button>
                    <button type="submit" name="publish" value="1" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Create & Publish Assignment
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script>
        // Initialize datetime picker
        flatpickr("#due_date", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            minDate: "today",
            time_24hr: true
        });

        // Toggle file-related fields based on submission type
        function toggleFileFields() {
            const submissionType = document.querySelector('input[name="submission_type"]:checked').value;
            const maxFilesGroup = document.getElementById('max_files_group');
            const extensionsGroup = document.getElementById('extensions_group');

            if (submissionType === 'text') {
                maxFilesGroup.style.display = 'none';
                extensionsGroup.style.display = 'none';
            } else {
                maxFilesGroup.style.display = 'block';
                extensionsGroup.style.display = 'block';
            }
        }

        // Add extension to allowed extensions field
        function addExtension(ext) {
            const field = document.getElementById('allowed_extensions');
            const currentValue = field.value;
            const extensions = currentValue.split(',').map(e => e.trim()).filter(e => e);

            if (!extensions.includes(ext)) {
                if (currentValue && !currentValue.endsWith(',')) {
                    field.value += ', ';
                }
                field.value += ext;
            }
        }

        // Live preview updates
        document.getElementById('title').addEventListener('input', function() {
            document.getElementById('titlePreview').textContent = this.value || '[Assignment Title]';
        });

        document.getElementById('due_date').addEventListener('change', function() {
            if (this.value) {
                const date = new Date(this.value);
                const formatted = date.toLocaleDateString('en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
                document.getElementById('dueDateText').textContent = formatted;
            }
        });

        document.getElementById('total_points').addEventListener('input', function() {
            document.getElementById('pointsText').textContent = this.value || '100';
        });

        document.getElementById('description').addEventListener('input', function() {
            document.getElementById('descriptionPreview').textContent =
                this.value || '[Assignment description will appear here]';
        });

        // Toggle publish button text
        document.getElementById('is_published').addEventListener('change', function() {
            const publishBtn = document.querySelector('button[name="publish"]');
            if (this.checked) {
                publishBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Create & Publish Assignment';
            } else {
                publishBtn.innerHTML = '<i class="fas fa-save"></i> Save as Draft';
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFileFields();
            document.querySelectorAll('input[name="submission_type"]').forEach(radio => {
                radio.addEventListener('change', toggleFileFields);
            });

            // Form validation
            document.getElementById('assignmentForm').addEventListener('submit', function(e) {
                const title = document.getElementById('title').value.trim();
                const dueDate = document.getElementById('due_date').value;
                const totalPoints = document.getElementById('total_points').value;

                if (!title) {
                    e.preventDefault();
                    alert('Please enter an assignment title.');
                    document.getElementById('title').focus();
                    return false;
                }

                if (!dueDate) {
                    e.preventDefault();
                    alert('Please select a due date.');
                    document.getElementById('due_date').focus();
                    return false;
                }

                if (parseFloat(totalPoints) <= 0 || parseFloat(totalPoints) > 1000) {
                    e.preventDefault();
                    alert('Total points must be between 1 and 1000.');
                    document.getElementById('total_points').focus();
                    return false;
                }
            });

            // Notification bell click
            document.querySelector('.notification-bell').addEventListener('click', function() {
                alert('Notifications feature coming soon!');
            });
        });

        // Auto-save draft functionality
        let autoSaveTimer;
        const form = document.getElementById('assignmentForm');

        function autoSaveDraft() {
            const formData = new FormData(form);
            formData.append('auto_save', '1');

            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/autosave.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Draft auto-saved at:', new Date().toLocaleTimeString());
                    }
                })
                .catch(error => console.error('Auto-save error:', error));
        }

        // Auto-save every 30 seconds if there are changes
        let lastFormData = new FormData(form);

        setInterval(() => {
            const currentFormData = new FormData(form);
            let hasChanges = false;

            // Compare form data (simplified check)
            for (let [key, value] of currentFormData.entries()) {
                if (key !== 'auto_save') {
                    hasChanges = true;
                    break;
                }
            }

            if (hasChanges) {
                autoSaveDraft();
                lastFormData = currentFormData;
            }
        }, 30000);
    </script>
</body>

</html>