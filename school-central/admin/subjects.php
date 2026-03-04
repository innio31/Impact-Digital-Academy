<?php
$page_title = 'Manage Subjects';
require_once 'auth_check.php';
require_once '../api/config.php';

// Handle form submissions
$message = '';
$message_type = '';

// Add new subject
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $subject_name = trim($_POST['subject_name']);
    $subject_code = trim($_POST['subject_code']);
    $description = trim($_POST['description']);

    if (!empty($subject_name) && !empty($subject_code)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO master_subjects (subject_name, subject_code, description) VALUES (?, ?, ?)");
            $stmt->execute([$subject_name, strtoupper($subject_code), $description]);
            $message = "Subject added successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $message = "Subject code already exists!";
            } else {
                $message = "Error adding subject: " . $e->getMessage();
            }
            $message_type = "error";
        }
    } else {
        $message = "Subject name and code are required!";
        $message_type = "error";
    }
}

// Edit subject
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = intval($_POST['id']);
    $subject_name = trim($_POST['subject_name']);
    $subject_code = trim($_POST['subject_code']);
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id > 0 && !empty($subject_name) && !empty($subject_code)) {
        try {
            $stmt = $pdo->prepare("UPDATE master_subjects SET subject_name = ?, subject_code = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$subject_name, strtoupper($subject_code), $description, $is_active, $id]);
            $message = "Subject updated successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating subject: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Delete subject
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Check if subject has topics or questions
    $check = $pdo->prepare("SELECT COUNT(*) FROM master_topics WHERE subject_id = ?");
    $check->execute([$id]);
    $topic_count = $check->fetchColumn();

    $check = $pdo->prepare("SELECT COUNT(*) FROM central_objective_questions WHERE subject_id = ?");
    $check->execute([$id]);
    $question_count = $check->fetchColumn();

    if ($topic_count > 0 || $question_count > 0) {
        $message = "Cannot delete subject with existing topics or questions. Deactivate it instead.";
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM master_subjects WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Subject deleted successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error deleting subject: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get all subjects
$stmt = $pdo->query("SELECT * FROM master_subjects ORDER BY subject_name");
$subjects = $stmt->fetchAll();

// Get subject for editing
$edit_subject = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM master_subjects WHERE id = ?");
    $stmt->execute([$id]);
    $edit_subject = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Central CBT - <?php echo $page_title; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #4361ee;
            --primary-dark: #3a56d4;
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --border-color: #dee2e6;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: var(--dark-text);
            line-height: 1.6;
        }

        /* Layout */
        .wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 20px 0;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 10px;
        }

        .nav-links li a i {
            width: 20px;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding-left: 25px;
        }

        .nav-links li.logout {
            margin-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 10px;
        }

        .user-info {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            min-width: 0;
        }

        .top-bar {
            background: #fff;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--secondary-color);
            display: none;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .date {
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        .content-wrapper {
            padding: 20px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.5;
        }

        .close-alert:hover {
            opacity: 1;
        }

        /* Cards */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            background: #fff;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h2 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2 i {
            color: var(--primary-color);
        }

        .card-body {
            padding: 20px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 500;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Forms */
        .form-container {
            max-width: 600px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group label i {
            color: var(--primary-color);
            margin-right: 5px;
            width: 16px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-group input.error {
            border-color: var(--danger-color);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th {
            text-align: left;
            padding: 12px;
            background: var(--light-bg);
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark-text);
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        tr:hover {
            background: var(--light-bg);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn.edit {
            color: var(--primary-color);
        }

        .action-btn.edit:hover {
            color: var(--primary-dark);
            transform: scale(1.1);
        }

        .action-btn.delete {
            color: var(--danger-color);
        }

        .action-btn.delete:hover {
            color: #c0392b;
            transform: scale(1.1);
        }

        .action-btn.view {
            color: var(--success-color);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .stat-card i {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 8px;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark-text);
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            color: var(--secondary-color);
        }

        /* Loading Spinner */
        .loading-spinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            z-index: 2000;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .top-bar {
                padding: 12px 15px;
            }

            .content-wrapper {
                padding: 15px;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .date {
                display: none;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .card-header .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .action-buttons {
                justify-content: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                display: flex;
                align-items: center;
                text-align: left;
                gap: 15px;
            }

            .stat-card i {
                margin-bottom: 0;
            }

            .user-info span {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Central CBT</h3>
                <p>Admin Panel</p>
            </div>

            <ul class="nav-links">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="subjects.php" class="active">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="topics.php">
                        <i class="fas fa-tags"></i> Topics
                    </a>
                </li>
                <li>
                    <a href="manage_questions.php">
                        <i class="fas fa-question-circle"></i> Questions
                    </a>
                </li>
                <li>
                    <a href="upload.php">
                        <i class="fas fa-upload"></i> Bulk Upload
                    </a>
                </li>
                <li>
                    <a href="manage_schools.php">
                        <i class="fas fa-school"></i> Schools
                    </a>
                </li>
                <li>
                    <a href="view_stats.php">
                        <i class="fas fa-chart-bar"></i> Statistics
                    </a>
                </li>
                <li class="logout">
                    <a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>

            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <i class="fas fa-book"></i> <?php echo $page_title; ?>
                </div>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                </div>
            </header>

            <div class="content-wrapper">
                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button class="close-alert" onclick="this.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <?php
                    $total = count($subjects);
                    $active = count(array_filter($subjects, function ($s) {
                        return $s['is_active'];
                    }));
                    $inactive = $total - $active;

                    // Get question counts per subject
                    $stmt = $pdo->query("
                        SELECT subject_id, COUNT(*) as count 
                        FROM central_objective_questions 
                        GROUP BY subject_id
                    ");
                    $question_counts = [];
                    while ($row = $stmt->fetch()) {
                        $question_counts[$row['subject_id']] = $row['count'];
                    }
                    $total_questions = array_sum($question_counts);
                    ?>

                    <div class="stat-card">
                        <i class="fas fa-book"></i>
                        <div>
                            <div class="stat-value"><?php echo $total; ?></div>
                            <div class="stat-label">Total Subjects</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                        <div>
                            <div class="stat-value"><?php echo $active; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-times-circle" style="color: var(--danger-color);"></i>
                        <div>
                            <div class="stat-value"><?php echo $inactive; ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-question-circle"></i>
                        <div>
                            <div class="stat-value"><?php echo $total_questions; ?></div>
                            <div class="stat-label">Questions</div>
                        </div>
                    </div>
                </div>

                <!-- Add/Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-<?php echo $edit_subject ? 'edit' : 'plus-circle'; ?>"></i>
                            <?php echo $edit_subject ? 'Edit Subject' : 'Add New Subject'; ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="form-container">
                            <form method="POST" action="" onsubmit="return validateForm()">
                                <input type="hidden" name="action" value="<?php echo $edit_subject ? 'edit' : 'add'; ?>">
                                <?php if ($edit_subject): ?>
                                    <input type="hidden" name="id" value="<?php echo $edit_subject['id']; ?>">
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="subject_name">
                                        <i class="fas fa-heading"></i> Subject Name *
                                    </label>
                                    <input type="text"
                                        id="subject_name"
                                        name="subject_name"
                                        value="<?php echo $edit_subject ? htmlspecialchars($edit_subject['subject_name']) : ''; ?>"
                                        required
                                        placeholder="e.g., Mathematics"
                                        maxlength="100">
                                </div>

                                <div class="form-group">
                                    <label for="subject_code">
                                        <i class="fas fa-code"></i> Subject Code *
                                    </label>
                                    <input type="text"
                                        id="subject_code"
                                        name="subject_code"
                                        value="<?php echo $edit_subject ? htmlspecialchars($edit_subject['subject_code']) : ''; ?>"
                                        required
                                        placeholder="e.g., MATH"
                                        maxlength="20"
                                        style="text-transform:uppercase">
                                </div>

                                <div class="form-group">
                                    <label for="description">
                                        <i class="fas fa-align-left"></i> Description
                                    </label>
                                    <textarea id="description"
                                        name="description"
                                        rows="3"
                                        placeholder="Brief description of the subject"><?php echo $edit_subject ? htmlspecialchars($edit_subject['description']) : ''; ?></textarea>
                                </div>

                                <?php if ($edit_subject): ?>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox"
                                                id="is_active"
                                                name="is_active"
                                                <?php echo $edit_subject['is_active'] ? 'checked' : ''; ?>>
                                            <label for="is_active">Active (visible to schools)</label>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <?php echo $edit_subject ? 'Update Subject' : 'Add Subject'; ?>
                                    </button>

                                    <?php if ($edit_subject): ?>
                                        <a href="subjects.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Subjects List -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-list"></i> All Subjects
                        </h2>
                        <span class="badge"><?php echo count($subjects); ?> total</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($subjects)): ?>
                            <div style="text-align: center; padding: 40px; color: var(--secondary-color);">
                                <i class="fas fa-book-open" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                <p>No subjects added yet. Add your first subject above.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Subject Name</th>
                                            <th>Code</th>
                                            <th>Topics</th>
                                            <th>Questions</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjects as $subject):
                                            // Get topic count
                                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM master_topics WHERE subject_id = ?");
                                            $stmt->execute([$subject['id']]);
                                            $topic_count = $stmt->fetchColumn();

                                            // Get question count
                                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM central_objective_questions WHERE subject_id = ?");
                                            $stmt->execute([$subject['id']]);
                                            $obj_count = $stmt->fetchColumn();

                                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM central_theory_questions WHERE subject_id = ?");
                                            $stmt->execute([$subject['id']]);
                                            $theory_count = $stmt->fetchColumn();
                                            $total_questions = $obj_count + $theory_count;
                                        ?>
                                            <tr>
                                                <td>#<?php echo $subject['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                                    <?php if ($subject['description']): ?>
                                                        <br><small style="color: #666;"><?php echo substr(htmlspecialchars($subject['description']), 0, 50); ?><?php echo strlen($subject['description']) > 50 ? '...' : ''; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($subject['subject_code']); ?></code></td>
                                                <td>
                                                    <span class="status-badge" style="background: #e3f2fd; color: #1976d2;">
                                                        <i class="fas fa-tags"></i> <?php echo $topic_count; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge" style="background: #f3e5f5; color: #7b1fa2;">
                                                        <i class="fas fa-question-circle"></i> <?php echo $total_questions; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($subject['is_active']): ?>
                                                        <span class="status-badge status-active">
                                                            <i class="fas fa-check-circle"></i> Active
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-inactive">
                                                            <i class="fas fa-times-circle"></i> Inactive
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M d, Y', strtotime($subject['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?php echo $subject['id']; ?>" class="action-btn edit" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="topics.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn view" title="View Topics">
                                                            <i class="fas fa-tags"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $subject['id']; ?>"
                                                            class="action-btn delete"
                                                            title="Delete"
                                                            onclick="return confirmDelete('<?php echo htmlspecialchars($subject['subject_name']); ?>', <?php echo $topic_count; ?>, <?php echo $total_questions; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle sidebar on mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-hide alert after 5 seconds
        const alertMessage = document.getElementById('alertMessage');
        if (alertMessage) {
            setTimeout(function() {
                alertMessage.style.transition = 'opacity 0.5s';
                alertMessage.style.opacity = '0';
                setTimeout(function() {
                    alertMessage.remove();
                }, 500);
            }, 5000);
        }

        // Form validation
        function validateForm() {
            const name = document.getElementById('subject_name');
            const code = document.getElementById('subject_code');
            let isValid = true;

            // Reset errors
            name.classList.remove('error');
            code.classList.remove('error');

            // Validate name
            if (name.value.trim().length < 2) {
                showError(name, 'Subject name must be at least 2 characters');
                isValid = false;
            }

            // Validate code
            if (code.value.trim().length < 2) {
                showError(code, 'Subject code must be at least 2 characters');
                isValid = false;
            }

            if (!isValid) {
                return false;
            }

            // Show loading
            showLoading();
            return true;
        }

        function showError(element, message) {
            element.classList.add('error');

            // Create or update error message
            let error = element.parentElement.querySelector('.error-message');
            if (!error) {
                error = document.createElement('small');
                error.className = 'error-message';
                error.style.color = 'var(--danger-color)';
                error.style.marginTop = '5px';
                error.style.display = 'block';
                element.parentElement.appendChild(error);
            }
            error.textContent = message;

            // Remove error after 3 seconds
            setTimeout(function() {
                element.classList.remove('error');
                if (error) {
                    error.remove();
                }
            }, 3000);
        }

        // Confirm delete
        function confirmDelete(subjectName, topicCount, questionCount) {
            if (topicCount > 0 || questionCount > 0) {
                alert(`Cannot delete "${subjectName}" because it has:\n- ${topicCount} topics\n- ${questionCount} questions\n\nDeactivate it instead.`);
                return false;
            }
            return confirm(`Are you sure you want to delete "${subjectName}"?`);
        }

        // Show loading spinner
        function showLoading() {
            const spinner = document.createElement('div');
            spinner.className = 'loading-spinner';
            spinner.id = 'loadingSpinner';
            spinner.innerHTML = '<div class="spinner"></div>';
            document.body.appendChild(spinner);
        }

        // Hide loading spinner
        window.onload = function() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) {
                spinner.remove();
            }
        };

        // Auto-uppercase subject code
        document.getElementById('subject_code')?.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });

        // Handle responsive table scroll
        const tables = document.querySelectorAll('.table-responsive');
        tables.forEach(table => {
            table.addEventListener('touchstart', function(e) {
                this.style.overflowX = 'auto';
            });
        });
    </script>
</body>

</html>