<?php
$page_title = 'Manage Topics';
require_once 'auth_check.php';
require_once '../api/config.php';

// Handle form submissions
$message = '';
$message_type = '';

// Get subjects for dropdown
$stmt = $pdo->query("SELECT id, subject_name, subject_code FROM master_subjects WHERE is_active = 1 ORDER BY subject_name");
$subjects = $stmt->fetchAll();

// Get filter parameters
$filter_subject = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$filter_difficulty = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';

// Add new topic
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $topic_name = trim($_POST['topic_name']);
    $subject_id = intval($_POST['subject_id']);
    $description = trim($_POST['description']);
    $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
    $class_range = trim($_POST['class_range']);

    if (!empty($topic_name) && $subject_id > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO master_topics (topic_name, subject_id, description, difficulty_level, class_range) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$topic_name, $subject_id, $description, $difficulty_level, $class_range]);
            $message = "Topic added successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error adding topic: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "Topic name and subject are required!";
        $message_type = "error";
    }
}

// Edit topic
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = intval($_POST['id']);
    $topic_name = trim($_POST['topic_name']);
    $subject_id = intval($_POST['subject_id']);
    $description = trim($_POST['description']);
    $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
    $class_range = trim($_POST['class_range']);

    if ($id > 0 && !empty($topic_name) && $subject_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE master_topics SET topic_name = ?, subject_id = ?, description = ?, difficulty_level = ?, class_range = ? WHERE id = ?");
            $stmt->execute([$topic_name, $subject_id, $description, $difficulty_level, $class_range, $id]);
            $message = "Topic updated successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating topic: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Delete topic
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Check if topic has questions
    $check = $pdo->prepare("SELECT COUNT(*) FROM central_objective_questions WHERE topic_id = ?");
    $check->execute([$id]);
    $obj_count = $check->fetchColumn();

    $check = $pdo->prepare("SELECT COUNT(*) FROM central_theory_questions WHERE topic_id = ?");
    $check->execute([$id]);
    $theory_count = $check->fetchColumn();

    if ($obj_count > 0 || $theory_count > 0) {
        $message = "Cannot delete topic with existing questions. Total questions: " . ($obj_count + $theory_count);
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM master_topics WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Topic deleted successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error deleting topic: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get topic for editing
$edit_topic = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM master_topics WHERE id = ?");
    $stmt->execute([$id]);
    $edit_topic = $stmt->fetch();
}

// Build topics query with filters
$sql = "SELECT t.*, s.subject_name, s.subject_code,
        (SELECT COUNT(*) FROM central_objective_questions WHERE topic_id = t.id) as obj_count,
        (SELECT COUNT(*) FROM central_theory_questions WHERE topic_id = t.id) as theory_count
        FROM master_topics t
        JOIN master_subjects s ON t.subject_id = s.id";
$params = [];

$where = [];
if ($filter_subject > 0) {
    $where[] = "t.subject_id = ?";
    $params[] = $filter_subject;
}
if (!empty($filter_difficulty)) {
    $where[] = "t.difficulty_level = ?";
    $params[] = $filter_difficulty;
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY s.subject_name, t.topic_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$topics = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Central CBT - <?php echo $page_title; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="../../portals/public/images/favicon.ico">
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
            --info-color: #3498db;
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
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-title i {
            color: var(--primary-color);
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

        /* Filter Bar */
        .filter-bar {
            background: var(--light-bg);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.85rem;
            color: var(--secondary-color);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        /* Difficulty badges */
        .difficulty-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .difficulty-easy {
            background: #d4edda;
            color: #155724;
        }

        .difficulty-medium {
            background: #fff3cd;
            color: #856404;
        }

        .difficulty-hard {
            background: #f8d7da;
            color: #721c24;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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

        .subject-info {
            display: flex;
            flex-direction: column;
        }

        .subject-name {
            font-weight: 500;
        }

        .subject-code {
            font-size: 0.75rem;
            color: var(--secondary-color);
        }

        .question-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #f3e5f5;
            color: #7b1fa2;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
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
            width: 32px;
            height: 32px;
            border-radius: 4px;
        }

        .action-btn.edit {
            color: var(--primary-color);
            background: #e3f2fd;
        }

        .action-btn.edit:hover {
            background: var(--primary-color);
            color: white;
        }

        .action-btn.delete {
            color: var(--danger-color);
            background: #ffebee;
        }

        .action-btn.delete:hover {
            background: var(--danger-color);
            color: white;
        }

        .action-btn.questions {
            color: var(--success-color);
            background: #e8f5e9;
        }

        .action-btn.questions:hover {
            background: var(--success-color);
            color: white;
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

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
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
                    <a href="subjects.php">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="topics.php" class="active">
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
                    <i class="fas fa-tags"></i> <?php echo $page_title; ?>
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
                    $total_topics = count($topics);

                    // Count by difficulty
                    $easy = count(array_filter($topics, function ($t) {
                        return $t['difficulty_level'] === 'easy';
                    }));
                    $medium = count(array_filter($topics, function ($t) {
                        return $t['difficulty_level'] === 'medium';
                    }));
                    $hard = count(array_filter($topics, function ($t) {
                        return $t['difficulty_level'] === 'hard';
                    }));

                    // Total questions across all topics
                    $total_questions = array_sum(array_column($topics, 'obj_count')) + array_sum(array_column($topics, 'theory_count'));
                    ?>

                    <div class="stat-card">
                        <i class="fas fa-tags"></i>
                        <div>
                            <div class="stat-value"><?php echo $total_topics; ?></div>
                            <div class="stat-label">Total Topics</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                        <div>
                            <div class="stat-value"><?php echo $easy; ?></div>
                            <div class="stat-label">Easy</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-adjust" style="color: var(--warning-color);"></i>
                        <div>
                            <div class="stat-value"><?php echo $medium; ?></div>
                            <div class="stat-label">Medium</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i>
                        <div>
                            <div class="stat-value"><?php echo $hard; ?></div>
                            <div class="stat-label">Hard</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-filter"></i> Filter Topics
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="filter-bar">
                            <div class="filter-group">
                                <label><i class="fas fa-book"></i> Subject</label>
                                <select name="subject_id">
                                    <option value="0">All Subjects</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo $subject['subject_code']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-chart-line"></i> Difficulty</label>
                                <select name="difficulty">
                                    <option value="">All Difficulties</option>
                                    <option value="easy" <?php echo $filter_difficulty === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo $filter_difficulty === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo $filter_difficulty === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="topics.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Add/Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-<?php echo $edit_topic ? 'edit' : 'plus-circle'; ?>"></i>
                            <?php echo $edit_topic ? 'Edit Topic' : 'Add New Topic'; ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="form-container">
                            <form method="POST" action="" onsubmit="return validateForm()">
                                <input type="hidden" name="action" value="<?php echo $edit_topic ? 'edit' : 'add'; ?>">
                                <?php if ($edit_topic): ?>
                                    <input type="hidden" name="id" value="<?php echo $edit_topic['id']; ?>">
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="topic_name">
                                        <i class="fas fa-heading"></i> Topic Name *
                                    </label>
                                    <input type="text"
                                        id="topic_name"
                                        name="topic_name"
                                        value="<?php echo $edit_topic ? htmlspecialchars($edit_topic['topic_name']) : ''; ?>"
                                        required
                                        placeholder="e.g., Algebra, Grammar, Photosynthesis"
                                        maxlength="255">
                                </div>

                                <div class="form-group">
                                    <label for="subject_id">
                                        <i class="fas fa-book"></i> Subject *
                                    </label>
                                    <select id="subject_id" name="subject_id" required>
                                        <option value="">-- Select Subject --</option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>"
                                                <?php echo ($edit_topic && $edit_topic['subject_id'] == $subject['id']) ? 'selected' : ''; ?>
                                                <?php echo (!$edit_topic && $filter_subject == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo $subject['subject_code']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="difficulty_level">
                                            <i class="fas fa-chart-line"></i> Difficulty Level
                                        </label>
                                        <select id="difficulty_level" name="difficulty_level">
                                            <option value="easy" <?php echo ($edit_topic && $edit_topic['difficulty_level'] === 'easy') ? 'selected' : ''; ?>>Easy</option>
                                            <option value="medium" <?php echo ($edit_topic && $edit_topic['difficulty_level'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                            <option value="hard" <?php echo ($edit_topic && $edit_topic['difficulty_level'] === 'hard') ? 'selected' : ''; ?>>Hard</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="class_range">
                                            <i class="fas fa-graduation-cap"></i> Class Range
                                        </label>
                                        <input type="text"
                                            id="class_range"
                                            name="class_range"
                                            value="<?php echo $edit_topic ? htmlspecialchars($edit_topic['class_range']) : ''; ?>"
                                            placeholder="e.g., JSS1-SSS3">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="description">
                                        <i class="fas fa-align-left"></i> Description
                                    </label>
                                    <textarea id="description"
                                        name="description"
                                        rows="3"
                                        placeholder="Brief description of the topic"><?php echo $edit_topic ? htmlspecialchars($edit_topic['description']) : ''; ?></textarea>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <?php echo $edit_topic ? 'Update Topic' : 'Add Topic'; ?>
                                    </button>

                                    <?php if ($edit_topic): ?>
                                        <a href="topics.php<?php echo $filter_subject ? '?subject_id=' . $filter_subject : ''; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Topics List -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-list"></i> All Topics
                            <?php if ($filter_subject > 0):
                                $subject = array_filter($subjects, function ($s) use ($filter_subject) {
                                    return $s['id'] == $filter_subject;
                                });
                                $subject = reset($subject);
                                if ($subject): ?>
                                    <small style="font-size: 0.9rem; color: var(--secondary-color); margin-left: 10px;">
                                        (<?php echo htmlspecialchars($subject['subject_name']); ?>)
                                    </small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </h2>
                        <span class="badge"><?php echo count($topics); ?> topics</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($topics)): ?>
                            <div style="text-align: center; padding: 40px; color: var(--secondary-color);">
                                <i class="fas fa-tags" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                <p>No topics found. <?php echo $filter_subject ? 'Try a different filter or ' : ''; ?>add your first topic above.</p>
                                <?php if ($filter_subject > 0): ?>
                                    <a href="topics.php" class="btn btn-secondary btn-sm" style="margin-top: 15px;">
                                        <i class="fas fa-redo"></i> Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Topic Name</th>
                                            <th>Subject</th>
                                            <th>Difficulty</th>
                                            <th>Class Range</th>
                                            <th>Questions</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topics as $topic):
                                            $total_q = $topic['obj_count'] + $topic['theory_count'];
                                        ?>
                                            <tr>
                                                <td>#<?php echo $topic['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong>
                                                    <?php if ($topic['description']): ?>
                                                        <br><small style="color: #666;"><?php echo substr(htmlspecialchars($topic['description']), 0, 60); ?><?php echo strlen($topic['description']) > 60 ? '...' : ''; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="subject-info">
                                                        <span class="subject-name"><?php echo htmlspecialchars($topic['subject_name']); ?></span>
                                                        <span class="subject-code"><?php echo $topic['subject_code']; ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="difficulty-badge difficulty-<?php echo $topic['difficulty_level']; ?>">
                                                        <i class="fas fa-<?php
                                                                            echo $topic['difficulty_level'] === 'easy' ? 'smile' : ($topic['difficulty_level'] === 'medium' ? 'meh' : 'frown');
                                                                            ?>"></i>
                                                        <?php echo ucfirst($topic['difficulty_level']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($topic['class_range']): ?>
                                                        <code><?php echo htmlspecialchars($topic['class_range']); ?></code>
                                                    <?php else: ?>
                                                        <span style="color: #999;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="question-count">
                                                        <i class="fas fa-question-circle"></i>
                                                        <?php echo $total_q; ?>
                                                        <small style="font-size: 0.7rem; margin-left: 4px;">
                                                            (<?php echo $topic['obj_count']; ?> obj | <?php echo $topic['theory_count']; ?> theory)
                                                        </small>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M d, Y', strtotime($topic['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?php echo $topic['id']; ?><?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?>"
                                                            class="action-btn edit"
                                                            title="Edit Topic">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="manage_questions.php?topic_id=<?php echo $topic['id']; ?>"
                                                            class="action-btn questions"
                                                            title="View Questions">
                                                            <i class="fas fa-question-circle"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $topic['id']; ?><?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?>"
                                                            class="action-btn delete"
                                                            title="Delete Topic"
                                                            onclick="return confirmDelete('<?php echo htmlspecialchars($topic['topic_name']); ?>', <?php echo $total_q; ?>)">
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
            const name = document.getElementById('topic_name');
            const subject = document.getElementById('subject_id');
            let isValid = true;

            // Reset errors
            name.classList.remove('error');
            subject.classList.remove('error');

            // Validate name
            if (name.value.trim().length < 2) {
                showError(name, 'Topic name must be at least 2 characters');
                isValid = false;
            }

            // Validate subject
            if (subject.value === '') {
                showError(subject, 'Please select a subject');
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
        function confirmDelete(topicName, questionCount) {
            if (questionCount > 0) {
                alert(`Cannot delete "${topicName}" because it has ${questionCount} questions attached.`);
                return false;
            }
            return confirm(`Are you sure you want to delete "${topicName}"?`);
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

        // Auto-submit filters when selection changes (optional)
        // Uncomment if you want auto-filter on select change
        /*
        document.querySelectorAll('.filter-group select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });
        */
    </script>
</body>

</html>