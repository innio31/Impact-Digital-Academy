<?php
$page_title = 'Manage Questions';
require_once 'auth_check.php';
require_once '../api/config.php';

// Handle form submissions
$message = '';
$message_type = '';

// Get subjects and topics for dropdowns
$stmt = $pdo->query("SELECT id, subject_name, subject_code FROM master_subjects WHERE is_active = 1 ORDER BY subject_name");
$subjects = $stmt->fetchAll();

// Get filter parameters
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_subject = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$filter_topic = isset($_GET['topic_id']) ? intval($_GET['topic_id']) : 0;
$filter_difficulty = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get topics for selected subject
$topics = [];
if ($filter_subject > 0) {
    $stmt = $pdo->prepare("SELECT id, topic_name FROM master_topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$filter_subject]);
    $topics = $stmt->fetchAll();
}

// Handle question approval/rejection
if (isset($_GET['approve'])) {
    $id = intval($_GET['approve']);
    $type = $_GET['type'] ?? 'objective';
    $table = $type === 'theory' ? 'central_theory_questions' : 'central_objective_questions';

    try {
        $stmt = $pdo->prepare("UPDATE $table SET is_approved = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Question approved successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error approving question: " . $e->getMessage();
        $message_type = "error";
    }
}

if (isset($_GET['reject'])) {
    $id = intval($_GET['reject']);
    $type = $_GET['type'] ?? 'objective';
    $table = $type === 'theory' ? 'central_theory_questions' : 'central_objective_questions';

    try {
        $stmt = $pdo->prepare("UPDATE $table SET is_approved = 0 WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Question rejected successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error rejecting question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle question deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $type = $_GET['type'] ?? 'objective';
    $table = $type === 'theory' ? 'central_theory_questions' : 'central_objective_questions';

    try {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Question deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error deleting question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Build queries for objective questions
$obj_sql = "SELECT 
            q.*, 
            s.subject_name, 
            s.subject_code,
            t.topic_name,
            (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'objective' AND question_id = q.id) as download_count
            FROM central_objective_questions q
            LEFT JOIN master_subjects s ON q.subject_id = s.id
            LEFT JOIN master_topics t ON q.topic_id = t.id
            WHERE 1=1";
$obj_params = [];

if ($filter_subject > 0) {
    $obj_sql .= " AND q.subject_id = ?";
    $obj_params[] = $filter_subject;
}
if ($filter_topic > 0) {
    $obj_sql .= " AND q.topic_id = ?";
    $obj_params[] = $filter_topic;
}
if (!empty($filter_difficulty)) {
    $obj_sql .= " AND q.difficulty_level = ?";
    $obj_params[] = $filter_difficulty;
}
if ($filter_status === 'approved') {
    $obj_sql .= " AND q.is_approved = 1";
} elseif ($filter_status === 'pending') {
    $obj_sql .= " AND q.is_approved = 0";
}
if (!empty($search)) {
    $obj_sql .= " AND (q.question_text LIKE ? OR q.explanation LIKE ?)";
    $search_term = "%$search%";
    $obj_params[] = $search_term;
    $obj_params[] = $search_term;
}

$obj_sql .= " ORDER BY q.created_at DESC";

// Add debug info (remove in production)
// echo "<!-- Objective SQL: $obj_sql -->";
// echo "<!-- Params: " . print_r($obj_params, true) . " -->";

$stmt = $pdo->prepare($obj_sql);
$stmt->execute($obj_params);
$objective_questions = $stmt->fetchAll();

// Build queries for theory questions
$theory_sql = "SELECT 
              q.*, 
              s.subject_name, 
              s.subject_code,
              t.topic_name,
              (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'theory' AND question_id = q.id) as download_count
              FROM central_theory_questions q
              LEFT JOIN master_subjects s ON q.subject_id = s.id
              LEFT JOIN master_topics t ON q.topic_id = t.id
              WHERE 1=1";
$theory_params = [];

if ($filter_subject > 0) {
    $theory_sql .= " AND q.subject_id = ?";
    $theory_params[] = $filter_subject;
}
if ($filter_topic > 0) {
    $theory_sql .= " AND q.topic_id = ?";
    $theory_params[] = $filter_topic;
}
if (!empty($filter_difficulty)) {
    $theory_sql .= " AND q.difficulty_level = ?";
    $theory_params[] = $filter_difficulty;
}
if ($filter_status === 'approved') {
    $theory_sql .= " AND q.is_approved = 1";
} elseif ($filter_status === 'pending') {
    $theory_sql .= " AND q.is_approved = 0";
}
if (!empty($search)) {
    $theory_sql .= " AND (q.question_text LIKE ? OR q.model_answer LIKE ?)";
    $search_term = "%$search%";
    $theory_params[] = $search_term;
    $theory_params[] = $search_term;
}

$theory_sql .= " ORDER BY q.created_at DESC";

// Add debug info (remove in production)
// echo "<!-- Theory SQL: $theory_sql -->";
// echo "<!-- Params: " . print_r($theory_params, true) . " -->";

$stmt = $pdo->prepare($theory_sql);
$stmt->execute($theory_params);
$theory_questions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Central CBT - <?php echo $page_title; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Quill Editor for rich text -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
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

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
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
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
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
            flex-wrap: wrap;
        }

        /* Search bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-bar input {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 1rem;
        }

        .search-bar button {
            padding: 12px 24px;
        }

        /* Type Tabs */
        .type-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .type-tab {
            padding: 10px 20px;
            background: #fff;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: var(--dark-text);
            font-weight: 500;
            transition: all 0.3s;
        }

        .type-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .type-tab i {
            margin-right: 8px;
        }

        /* Question Cards */
        .questions-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .question-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s;
        }

        .question-card:hover {
            box-shadow: var(--shadow);
            border-color: var(--primary-color);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .question-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .question-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-subject {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-topic {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-difficulty-easy {
            background: #d4edda;
            color: #155724;
        }

        .badge-difficulty-medium {
            background: #fff3cd;
            color: #856404;
        }

        .badge-difficulty-hard {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status-approved {
            background: #d4edda;
            color: #155724;
        }

        .badge-status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .question-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .question-text {
            font-size: 1.1rem;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin: 15px 0;
            padding: 15px;
            background: var(--light-bg);
            border-radius: 6px;
        }

        .option-item {
            padding: 10px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid var(--border-color);
        }

        .option-item.correct {
            background: #d4edda;
            border-color: var(--success-color);
            font-weight: 500;
        }

        .option-label {
            font-weight: 600;
            margin-right: 8px;
            color: var(--primary-color);
        }

        .answer-section {
            margin-top: 15px;
            padding: 15px;
            background: #e8f4fd;
            border-radius: 6px;
            border-left: 4px solid var(--info-color);
        }

        .answer-label {
            font-weight: 600;
            color: var(--info-color);
            margin-right: 10px;
        }

        .question-footer {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--secondary-color);
        }

        .footer-stats {
            display: flex;
            gap: 15px;
        }

        .footer-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
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
            z-index: 2000;
            overflow-y: auto;
        }

        .modal-content {
            background: #fff;
            margin: 50px auto;
            max-width: 800px;
            width: 90%;
            border-radius: 10px;
            box-shadow: var(--shadow);
            animation: slideDown 0.3s ease;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary-color);
        }

        .modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Rich text editor */
        .ql-editor {
            min-height: 150px;
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

            .filter-bar {
                flex-direction: column;
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

            .question-header {
                flex-direction: column;
            }

            .question-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .options-grid {
                grid-template-columns: 1fr;
            }

            .footer-stats {
                flex-wrap: wrap;
            }

            .type-tabs {
                flex-direction: column;
            }

            .type-tab {
                text-align: center;
            }

            .search-bar {
                flex-direction: column;
            }

            .search-bar button {
                width: 100%;
            }

            .modal-content {
                margin: 20px auto;
                width: 95%;
            }
        }

        @media (max-width: 480px) {
            .user-info span {
                display: none;
            }
        }

        /* Loading state styles */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* Better modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            overflow-y: auto;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: #fff;
            margin: 30px auto;
            max-width: 900px;
            width: 95%;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: slideDown 0.3s ease;
        }

        /* Badge styles for modal */
        .badge-difficulty-easy {
            background: #d4edda;
            color: #155724;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .badge-difficulty-medium {
            background: #fff3cd;
            color: #856404;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .badge-difficulty-hard {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .badge-status-approved {
            background: #d4edda;
            color: #155724;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .badge-status-pending {
            background: #fff3cd;
            color: #856404;
            padding: 2px 8px;
            border-radius: 4px;
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
                    <a href="topics.php">
                        <i class="fas fa-tags"></i> Topics
                    </a>
                </li>
                <li>
                    <a href="manage_questions.php" class="active">
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
                    <i class="fas fa-question-circle"></i> <?php echo $page_title; ?>
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

                <!-- Quick Stats -->
                <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <div class="stat-card" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: var(--shadow);">
                        <i class="fas fa-list" style="color: var(--primary-color); font-size: 1.5rem;"></i>
                        <div class="stat-value"><?php echo count($objective_questions); ?></div>
                        <div class="stat-label">Objective Questions</div>
                    </div>
                    <div class="stat-card" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: var(--shadow);">
                        <i class="fas fa-pencil-alt" style="color: var(--success-color); font-size: 1.5rem;"></i>
                        <div class="stat-value"><?php echo count($theory_questions); ?></div>
                        <div class="stat-label">Theory Questions</div>
                    </div>
                    <div class="stat-card" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: var(--shadow);">
                        <i class="fas fa-check-circle" style="color: var(--success-color); font-size: 1.5rem;"></i>
                        <div class="stat-value">
                            <?php
                            $approved = count(array_filter($objective_questions, function ($q) {
                                return $q['is_approved'];
                            })) +
                                count(array_filter($theory_questions, function ($q) {
                                    return $q['is_approved'];
                                }));
                            echo $approved;
                            ?>
                        </div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: var(--shadow);">
                        <i class="fas fa-hourglass-half" style="color: var(--warning-color); font-size: 1.5rem;"></i>
                        <div class="stat-value">
                            <?php
                            $pending = count(array_filter($objective_questions, function ($q) {
                                return !$q['is_approved'];
                            })) +
                                count(array_filter($theory_questions, function ($q) {
                                    return !$q['is_approved'];
                                }));
                            echo $pending;
                            ?>
                        </div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-search"></i> Search Questions</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="search-bar">
                            <input type="text"
                                name="search"
                                placeholder="Search by question text or explanation..."
                                value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="type" value="<?php echo $filter_type; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo $filter_subject; ?>">
                            <input type="hidden" name="topic_id" value="<?php echo $filter_topic; ?>">
                            <input type="hidden" name="difficulty" value="<?php echo $filter_difficulty; ?>">
                            <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <?php if (!empty($search)): ?>
                                <a href="manage_questions.php?type=<?php echo $filter_type; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-filter"></i> Filter Questions</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="filter-bar">
                            <div class="filter-group">
                                <label><i class="fas fa-book"></i> Subject</label>
                                <select name="subject_id" id="filter_subject">
                                    <option value="0">All Subjects</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-tags"></i> Topic</label>
                                <select name="topic_id" id="filter_topic">
                                    <option value="0">All Topics</option>
                                    <?php foreach ($topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>"
                                            <?php echo $filter_topic == $topic['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
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

                            <div class="filter-group">
                                <label><i class="fas fa-check-circle"></i> Status</label>
                                <select name="status">
                                    <option value="">All</option>
                                    <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>

                            <input type="hidden" name="type" value="<?php echo $filter_type; ?>">

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="manage_questions.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Question Type Tabs -->
                <div class="type-tabs">
                    <a href="?type=all<?php
                                        echo $filter_subject ? '&subject_id=' . $filter_subject : '';
                                        echo $filter_topic ? '&topic_id=' . $filter_topic : '';
                                        echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : '';
                                        echo $filter_status ? '&status=' . $filter_status : '';
                                        echo $search ? '&search=' . urlencode($search) : '';
                                        ?>" class="type-tab <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> All Questions
                    </a>
                    <a href="?type=objective<?php
                                            echo $filter_subject ? '&subject_id=' . $filter_subject : '';
                                            echo $filter_topic ? '&topic_id=' . $filter_topic : '';
                                            echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : '';
                                            echo $filter_status ? '&status=' . $filter_status : '';
                                            echo $search ? '&search=' . urlencode($search) : '';
                                            ?>" class="type-tab <?php echo $filter_type === 'objective' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> Objective
                    </a>
                    <a href="?type=theory<?php
                                            echo $filter_subject ? '&subject_id=' . $filter_subject : '';
                                            echo $filter_topic ? '&topic_id=' . $filter_topic : '';
                                            echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : '';
                                            echo $filter_status ? '&status=' . $filter_status : '';
                                            echo $search ? '&search=' . urlencode($search) : '';
                                            ?>" class="type-tab <?php echo $filter_type === 'theory' ? 'active' : ''; ?>">
                        <i class="fas fa-pencil-alt"></i> Theory
                    </a>
                </div>

                <!-- Questions Display -->
                <div class="questions-container">
                    <?php if ($filter_type === 'all' || $filter_type === 'objective'): ?>
                        <!-- Objective Questions Section -->
                        <?php if ($filter_type === 'all'): ?>
                            <h3 style="margin: 20px 0 10px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-list" style="color: var(--primary-color);"></i>
                                Objective Questions (<?php echo count($objective_questions); ?>)
                            </h3>
                        <?php endif; ?>

                        <?php if (empty($objective_questions)): ?>
                            <?php if ($filter_type === 'objective'): ?>
                                <div style="text-align: center; padding: 40px; color: var(--secondary-color);">
                                    <i class="fas fa-list" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                    <p>No objective questions found matching your criteria.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($objective_questions as $question): ?>
                                <div class="question-card" id="obj_<?php echo $question['id']; ?>">
                                    <div class="question-header">
                                        <div class="question-meta">
                                            <span class="question-badge badge-subject">
                                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($question['subject_name'] ?? 'No Subject'); ?>
                                            </span>
                                            <span class="question-badge badge-topic">
                                                <i class="fas fa-tags"></i> <?php echo htmlspecialchars($question['topic_name'] ?? 'No Topic'); ?>
                                            </span>
                                            <span class="question-badge badge-difficulty-<?php echo $question['difficulty_level']; ?>">
                                                <i class="fas fa-<?php
                                                                    echo $question['difficulty_level'] === 'easy' ? 'smile' : ($question['difficulty_level'] === 'medium' ? 'meh' : 'frown');
                                                                    ?>"></i>
                                                <?php echo ucfirst($question['difficulty_level']); ?>
                                            </span>
                                            <span class="question-badge <?php echo $question['is_approved'] ? 'badge-status-approved' : 'badge-status-pending'; ?>">
                                                <i class="fas fa-<?php echo $question['is_approved'] ? 'check-circle' : 'hourglass-half'; ?>"></i>
                                                <?php echo $question['is_approved'] ? 'Approved' : 'Pending'; ?>
                                            </span>
                                        </div>

                                        <div class="question-actions">
                                            <button class="btn btn-sm btn-info" onclick="viewQuestion('objective', <?php echo $question['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if (!$question['is_approved']): ?>
                                                <a href="?approve=<?php echo $question['id']; ?>&type=objective<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Approve this question?')">
                                                    <i class="fas fa-check"></i> Approve
                                                </a>
                                            <?php else: ?>
                                                <a href="?reject=<?php echo $question['id']; ?>&type=objective<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                    class="btn btn-sm btn-warning"
                                                    onclick="return confirm('Reject this question?')">
                                                    <i class="fas fa-times"></i> Reject
                                                </a>
                                            <?php endif; ?>
                                            <a href="?delete=<?php echo $question['id']; ?>&type=objective<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Are you sure you want to delete this question? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </div>

                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars(substr($question['question_text'], 0, 200))); ?>
                                        <?php if (strlen($question['question_text']) > 200): ?>...<?php endif; ?>
                                    </div>

                                    <div class="options-grid">
                                        <div class="option-item <?php echo $question['correct_answer'] === 'A' ? 'correct' : ''; ?>">
                                            <span class="option-label">A:</span> <?php echo htmlspecialchars(substr($question['option_a'], 0, 50)); ?><?php echo strlen($question['option_a']) > 50 ? '...' : ''; ?>
                                        </div>
                                        <div class="option-item <?php echo $question['correct_answer'] === 'B' ? 'correct' : ''; ?>">
                                            <span class="option-label">B:</span> <?php echo htmlspecialchars(substr($question['option_b'], 0, 50)); ?><?php echo strlen($question['option_b']) > 50 ? '...' : ''; ?>
                                        </div>
                                        <div class="option-item <?php echo $question['correct_answer'] === 'C' ? 'correct' : ''; ?>">
                                            <span class="option-label">C:</span> <?php echo htmlspecialchars(substr($question['option_c'], 0, 50)); ?><?php echo strlen($question['option_c']) > 50 ? '...' : ''; ?>
                                        </div>
                                        <div class="option-item <?php echo $question['correct_answer'] === 'D' ? 'correct' : ''; ?>">
                                            <span class="option-label">D:</span> <?php echo htmlspecialchars(substr($question['option_d'], 0, 50)); ?><?php echo strlen($question['option_d']) > 50 ? '...' : ''; ?>
                                        </div>
                                    </div>

                                    <div class="question-footer">
                                        <div class="footer-stats">
                                            <span><i class="fas fa-download"></i> <?php echo $question['download_count']; ?> downloads</span>
                                            <span><i class="fas fa-star"></i> Marks: <?php echo $question['marks']; ?></span>
                                            <?php if ($question['class_level']): ?>
                                                <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($question['class_level']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($question['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($filter_type === 'all' || $filter_type === 'theory'): ?>
                        <!-- Theory Questions Section -->
                        <?php if ($filter_type === 'all' && !empty($theory_questions)): ?>
                            <h3 style="margin: 30px 0 10px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-pencil-alt" style="color: var(--success-color);"></i>
                                Theory Questions (<?php echo count($theory_questions); ?>)
                            </h3>
                        <?php endif; ?>

                        <?php if (empty($theory_questions)): ?>
                            <?php if ($filter_type === 'theory'): ?>
                                <div style="text-align: center; padding: 40px; color: var(--secondary-color);">
                                    <i class="fas fa-pencil-alt" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                    <p>No theory questions found matching your criteria.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($theory_questions as $question): ?>
                                <div class="question-card" id="theory_<?php echo $question['id']; ?>">
                                    <div class="question-header">
                                        <div class="question-meta">
                                            <span class="question-badge badge-subject">
                                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($question['subject_name'] ?? 'No Subject'); ?>
                                            </span>
                                            <span class="question-badge badge-topic">
                                                <i class="fas fa-tags"></i> <?php echo htmlspecialchars($question['topic_name'] ?? 'No Topic'); ?>
                                            </span>
                                            <span class="question-badge badge-difficulty-<?php echo $question['difficulty_level']; ?>">
                                                <i class="fas fa-<?php
                                                                    echo $question['difficulty_level'] === 'easy' ? 'smile' : ($question['difficulty_level'] === 'medium' ? 'meh' : 'frown');
                                                                    ?>"></i>
                                                <?php echo ucfirst($question['difficulty_level']); ?>
                                            </span>
                                            <span class="question-badge <?php echo $question['is_approved'] ? 'badge-status-approved' : 'badge-status-pending'; ?>">
                                                <i class="fas fa-<?php echo $question['is_approved'] ? 'check-circle' : 'hourglass-half'; ?>"></i>
                                                <?php echo $question['is_approved'] ? 'Approved' : 'Pending'; ?>
                                            </span>
                                        </div>

                                        <div class="question-actions">
                                            <button class="btn btn-sm btn-info" onclick="viewQuestion('theory', <?php echo $question['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if (!$question['is_approved']): ?>
                                                <a href="?approve=<?php echo $question['id']; ?>&type=theory<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Approve this question?')">
                                                    <i class="fas fa-check"></i> Approve
                                                </a>
                                            <?php else: ?>
                                                <a href="?reject=<?php echo $question['id']; ?>&type=theory<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                    class="btn btn-sm btn-warning"
                                                    onclick="return confirm('Reject this question?')">
                                                    <i class="fas fa-times"></i> Reject
                                                </a>
                                            <?php endif; ?>
                                            <a href="?delete=<?php echo $question['id']; ?>&type=theory<?php echo $filter_subject ? '&subject_id=' . $filter_subject : ''; ?><?php echo $filter_topic ? '&topic_id=' . $filter_topic : ''; ?><?php echo $filter_difficulty ? '&difficulty=' . $filter_difficulty : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Are you sure you want to delete this question? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </div>

                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars(substr($question['question_text'] ?? '', 0, 300))); ?>
                                        <?php if (strlen($question['question_text'] ?? '') > 300): ?>...<?php endif; ?>
                                    </div>

                                    <?php if ($question['question_file']): ?>
                                        <div style="margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                            <i class="fas fa-file"></i>
                                            <a href="../<?php echo htmlspecialchars($question['question_file']); ?>" target="_blank">
                                                View Attachment
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <div class="answer-section">
                                        <span class="answer-label"><i class="fas fa-check-circle"></i> Model Answer:</span>
                                        <?php echo nl2br(htmlspecialchars(substr($question['model_answer'] ?? '', 0, 200))); ?>
                                        <?php if (strlen($question['model_answer'] ?? '') > 200): ?>...<?php endif; ?>
                                    </div>

                                    <div class="question-footer">
                                        <div class="footer-stats">
                                            <span><i class="fas fa-download"></i> <?php echo $question['download_count']; ?> downloads</span>
                                            <span><i class="fas fa-star"></i> Marks: <?php echo $question['marks']; ?></span>
                                            <?php if ($question['class_level']): ?>
                                                <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($question['class_level']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($question['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View Question Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Question Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        // Toggle sidebar on mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        if (menuToggle) {
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('active');
            });
        }

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
                    if (alertMessage.parentNode) {
                        alertMessage.remove();
                    }
                }, 500);
            }, 5000);
        }

        // Dynamic topic loading based on subject selection
        document.addEventListener('DOMContentLoaded', function() {
            const subjectSelect = document.getElementById('filter_subject');
            const topicSelect = document.getElementById('filter_topic');

            if (!subjectSelect || !topicSelect) {
                console.error('Filter elements not found');
                return;
            }

            // Function to load topics
            function loadTopics(subjectId) {
                // Show loading state
                topicSelect.innerHTML = '<option value="0">Loading...</option>';
                topicSelect.disabled = true;

                if (subjectId && subjectId !== '0') {
                    console.log('Loading topics for subject:', subjectId);

                    // Use absolute path or correct relative path
                    fetch('../api/get_topics.php?subject_id=' + subjectId, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => {
                            console.log('Response status:', response.status);
                            if (!response.ok) {
                                return response.text().then(text => {
                                    throw new Error('HTTP error ' + response.status + ': ' + text);
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('Topics data received:', data);

                            // Clear loading state
                            topicSelect.innerHTML = '';
                            topicSelect.disabled = false;

                            // Add default option
                            const defaultOption = document.createElement('option');
                            defaultOption.value = '0';
                            defaultOption.textContent = 'All Topics';
                            topicSelect.appendChild(defaultOption);

                            // Check if we have topics
                            if (data.topics && data.topics.length > 0) {
                                data.topics.forEach(topic => {
                                    const option = document.createElement('option');
                                    option.value = topic.id;
                                    option.textContent = topic.topic_name;

                                    // Preserve selected topic if it matches
                                    <?php if ($filter_topic > 0): ?>
                                        if (topic.id == <?php echo $filter_topic; ?>) {
                                            option.selected = true;
                                        }
                                    <?php endif; ?>

                                    topicSelect.appendChild(option);
                                });
                                console.log('Added', data.topics.length, 'topics');
                            } else {
                                console.log('No topics found for this subject');
                                const noTopicsOption = document.createElement('option');
                                noTopicsOption.value = '0';
                                noTopicsOption.textContent = 'No topics available';
                                noTopicsOption.disabled = true;
                                topicSelect.appendChild(noTopicsOption);
                            }
                        })
                        .catch(error => {
                            console.error('Error loading topics:', error);
                            topicSelect.innerHTML = '<option value="0">Error loading topics</option>';
                            topicSelect.disabled = false;

                            // Show error message to user (optional)
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'alert alert-error';
                            errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to load topics. Please refresh the page.';
                            errorDiv.style.marginTop = '10px';
                            topicSelect.parentNode.appendChild(errorDiv);

                            // Remove error after 3 seconds
                            setTimeout(() => {
                                if (errorDiv.parentNode) {
                                    errorDiv.remove();
                                }
                            }, 3000);
                        });
                } else {
                    // No subject selected, show default
                    topicSelect.innerHTML = '<option value="0">All Topics</option>';
                    topicSelect.disabled = false;
                }
            }

            // Add change event listener
            subjectSelect.addEventListener('change', function() {
                loadTopics(this.value);
            });

            // Load topics on page load if a subject is selected
            if (subjectSelect.value && subjectSelect.value !== '0') {
                loadTopics(subjectSelect.value);
            }
        });

        // View question details
        function viewQuestion(type, id) {
            const modal = document.getElementById('viewModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.getElementById('modalTitle');

            if (!modal || !modalBody || !modalTitle) {
                console.error('Modal elements not found');
                return;
            }

            modalTitle.textContent = type === 'objective' ? 'Objective Question Details' : 'Theory Question Details';
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 3rem; color: var(--primary-color);"></i><br><br>Loading question...</div>';

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling

            // Fetch question details via AJAX
            fetch('../api/get_question_details.php?type=' + type + '&id=' + id)
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            throw new Error('HTTP error ' + response.status);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        modalBody.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
                    } else {
                        let html = '';

                        if (type === 'objective') {
                            html = `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 10px;">Question:</h4>
                            <div style="background: var(--light-bg); padding: 15px; border-radius: 6px; border-left: 4px solid var(--primary-color);">
                                ${data.question_text || 'No question text'}
                            </div>
                        </div>
                        
                        <h4 style="margin-bottom: 10px;">Options:</h4>
                        <div style="display: grid; gap: 10px; margin-bottom: 20px;">
                            <div class="option-item ${data.correct_answer === 'A' ? 'correct' : ''}" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; ${data.correct_answer === 'A' ? 'background: #d4edda; border-color: #28a745;' : ''}">
                                <span class="option-label" style="font-weight: 600; color: var(--primary-color);">A:</span> ${data.option_a || ''}
                            </div>
                            <div class="option-item ${data.correct_answer === 'B' ? 'correct' : ''}" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; ${data.correct_answer === 'B' ? 'background: #d4edda; border-color: #28a745;' : ''}">
                                <span class="option-label" style="font-weight: 600; color: var(--primary-color);">B:</span> ${data.option_b || ''}
                            </div>
                            <div class="option-item ${data.correct_answer === 'C' ? 'correct' : ''}" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; ${data.correct_answer === 'C' ? 'background: #d4edda; border-color: #28a745;' : ''}">
                                <span class="option-label" style="font-weight: 600; color: var(--primary-color);">C:</span> ${data.option_c || ''}
                            </div>
                            <div class="option-item ${data.correct_answer === 'D' ? 'correct' : ''}" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; ${data.correct_answer === 'D' ? 'background: #d4edda; border-color: #28a745;' : ''}">
                                <span class="option-label" style="font-weight: 600; color: var(--primary-color);">D:</span> ${data.option_d || ''}
                            </div>
                        </div>
                        
                        <div class="answer-section" style="background: #e8f4fd; padding: 15px; border-radius: 6px; border-left: 4px solid var(--info-color);">
                            <strong><i class="fas fa-check-circle" style="color: var(--info-color);"></i> Correct Answer:</strong> ${data.correct_answer || ''}
                        </div>
                    `;
                        } else {
                            html = `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 10px;">Question:</h4>
                            <div style="background: var(--light-bg); padding: 15px; border-radius: 6px; border-left: 4px solid var(--success-color);">
                                ${data.question_text || 'No question text provided'}
                            </div>
                        </div>
                        
                        ${data.question_file ? `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 10px;">Attachment:</h4>
                            <a href="../${data.question_file}" target="_blank" class="btn btn-sm btn-info" style="display: inline-flex; align-items: center; gap: 5px;">
                                <i class="fas fa-file"></i> View Attachment
                            </a>
                        </div>
                        ` : ''}
                        
                        <div class="answer-section" style="background: #fff3cd; padding: 15px; border-radius: 6px; border-left: 4px solid var(--warning-color);">
                            <h4 style="margin-bottom: 10px;"><i class="fas fa-star"></i> Model Answer:</h4>
                            <div style="margin-top: 10px;">
                                ${data.model_answer || 'No model answer provided'}
                            </div>
                        </div>
                    `;
                        }

                        html += `
                    <hr style="margin: 20px 0; border-color: var(--border-color);">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div><strong>Subject:</strong> ${data.subject_name || 'N/A'}</div>
                        <div><strong>Topic:</strong> ${data.topic_name || 'N/A'}</div>
                        <div><strong>Difficulty:</strong> <span class="badge-difficulty-${data.difficulty_level || 'medium'}">${data.difficulty_level || 'N/A'}</span></div>
                        <div><strong>Marks:</strong> ${data.marks || 'N/A'}</div>
                        <div><strong>Class:</strong> ${data.class_level || 'Not specified'}</div>
                        <div><strong>Status:</strong> <span class="badge-status-${data.is_approved ? 'approved' : 'pending'}">${data.is_approved ? 'Approved' : 'Pending'}</span></div>
                        <div><strong>Downloads:</strong> <i class="fas fa-download"></i> ${data.download_count || 0}</div>
                        <div><strong>Created:</strong> ${new Date(data.created_at).toLocaleString()}</div>
                    </div>
                    ${data.explanation ? `
                    <div style="margin-top: 20px;">
                        <strong>Explanation:</strong>
                        <div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 5px;">
                            ${data.explanation}
                        </div>
                    </div>
                    ` : ''}
                `;

                        modalBody.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error loading question:', error);
                    modalBody.innerHTML = `<div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                Error loading question: ${error.message}
            </div>`;
                });
        }

        // Close modal
        function closeModal() {
            const modal = document.getElementById('viewModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = ''; // Restore scrolling
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Keyboard shortcut - ESC to close modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Debug: Log when page loads
        console.log('Manage questions page loaded');
    </script>
</body>

</html>