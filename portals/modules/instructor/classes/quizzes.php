<?php
// modules/instructor/classes/quizzes.php

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

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, 
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
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

// Handle quiz creation
$create_success = false;
$create_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_quiz') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $quiz_type = $_POST['quiz_type'] ?? 'graded';
    $total_points = floatval($_POST['total_points'] ?? 100);
    $time_limit = intval($_POST['time_limit'] ?? 0);
    $attempts_allowed = intval($_POST['attempts_allowed'] ?? 1);
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_options = isset($_POST['shuffle_options']) ? 1 : 0;
    $show_correct_answers = isset($_POST['show_correct_answers']) ? 1 : 0;
    $show_points = isset($_POST['show_points']) ? 1 : 0;
    $available_from = $_POST['available_from'] ?? null;
    $available_to = $_POST['available_to'] ?? null;
    $due_date = $_POST['due_date'] ?? null;
    $auto_submit = isset($_POST['auto_submit']) ? 1 : 0;

    // Validate input
    if (empty($title)) {
        $create_error = "Quiz title is required";
    } else {
        // Insert quiz into database
        $sql = "INSERT INTO quizzes (class_id, instructor_id, title, description, instructions, 
                quiz_type, total_points, time_limit, attempts_allowed, shuffle_questions, 
                shuffle_options, show_correct_answers, show_points, available_from, 
                available_to, due_date, auto_submit, is_published) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iisssssdiiiisssss",
            $class_id,
            $instructor_id,
            $title,
            $description,
            $instructions,
            $quiz_type,
            $total_points,
            $time_limit,
            $attempts_allowed,
            $shuffle_questions,
            $shuffle_options,
            $show_correct_answers,
            $show_points,
            $available_from,
            $available_to,
            $due_date,
            $auto_submit
        );

        if ($stmt->execute()) {
            $quiz_id = $stmt->insert_id;
            $create_success = true;

            // Log activity
            logActivity('quiz_created', "Created quiz: $title", 'quizzes', $quiz_id);

            // Redirect to quiz builder
            header("Location: quiz_builder.php?class_id=$class_id&quiz_id=$quiz_id");
            exit();
        } else {
            $create_error = "Failed to create quiz: " . $conn->error;
        }
        $stmt->close();
    }
}

// Handle quiz deletion
if (isset($_GET['delete_quiz']) && is_numeric($_GET['delete_quiz'])) {
    $quiz_id = (int)$_GET['delete_quiz'];

    // Verify instructor owns this quiz
    $sql = "SELECT id FROM quizzes WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $quiz_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Delete quiz (cascade will delete questions, options, attempts)
        $delete_sql = "DELETE FROM quizzes WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $quiz_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // Log activity
        logActivity('quiz_deleted', "Deleted quiz ID: $quiz_id", 'quizzes', $quiz_id);

        header("Location: quizzes.php?class_id=$class_id&deleted=1");
        exit();
    }
    $stmt->close();
}

// Handle publish/unpublish
if (isset($_GET['toggle_publish']) && is_numeric($_GET['toggle_publish'])) {
    $quiz_id = (int)$_GET['toggle_publish'];

    // Get current status
    $sql = "SELECT is_published FROM quizzes WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $quiz_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $quiz = $result->fetch_assoc();
        $new_status = $quiz['is_published'] ? 0 : 1;

        $update_sql = "UPDATE quizzes SET is_published = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $new_status, $quiz_id);
        $update_stmt->execute();
        $update_stmt->close();

        $action = $new_status ? 'published' : 'unpublished';
        logActivity('quiz_' . $action, "{$action} quiz ID: $quiz_id", 'quizzes', $quiz_id);

        header("Location: quizzes.php?class_id=$class_id&$action=1");
        exit();
    }
    $stmt->close();
}

// Get quiz statistics
$stats_sql = "SELECT 
                COUNT(*) as total_quizzes,
                SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN available_to > NOW() THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN due_date < NOW() THEN 1 ELSE 0 END) as completed
              FROM quizzes 
              WHERE class_id = ? AND instructor_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get attempts statistics
$attempts_stats_sql = "SELECT 
                          q.id,
                          q.title,
                          COUNT(DISTINCT a.id) as total_attempts,
                          COUNT(DISTINCT a.student_id) as students_attempted,
                          AVG(a.percentage) as average_score
                       FROM quizzes q 
                       LEFT JOIN quiz_attempts a ON q.id = a.quiz_id AND a.status = 'graded'
                       WHERE q.class_id = ? AND q.instructor_id = ?
                       GROUP BY q.id
                       ORDER BY q.created_at DESC";
$stmt = $conn->prepare($attempts_stats_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$attempts_stats_result = $stmt->get_result();
$attempts_stats = [];
while ($row = $attempts_stats_result->fetch_assoc()) {
    $attempts_stats[$row['id']] = $row;
}

// Get quizzes for this class
$sql = "SELECT q.*, 
               COUNT(DISTINCT a.id) as attempt_count,
               COUNT(DISTINCT a.student_id) as student_count,
               AVG(a.percentage) as average_score
        FROM quizzes q 
        LEFT JOIN quiz_attempts a ON q.id = a.quiz_id AND a.status = 'graded'
        WHERE q.class_id = ? AND q.instructor_id = ?
        GROUP BY q.id
        ORDER BY q.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$quizzes = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quizzes - <?php echo htmlspecialchars($class['batch_code']); ?> - Impact Digital Academy</title>
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
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
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

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #e5e7eb;
            color: #374151;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Stats Grid */
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
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.published {
            border-top-color: var(--success);
        }

        .stat-card.active {
            border-top-color: var(--warning);
        }

        .stat-card.completed {
            border-top-color: var(--info);
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
            letter-spacing: 0.5px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px 8px 0 0;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Main Content */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Quiz Cards */
        .quizzes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .quiz-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .quiz-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .quiz-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            position: relative;
        }

        .quiz-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .quiz-due {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quiz-due.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .status-badge-small {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-draft {
            background: #e5e7eb;
            color: #374151;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-upcoming {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #e0f2fe;
            color: #0369a1;
        }

        .quiz-body {
            padding: 1.5rem;
        }

        .quiz-description {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .quiz-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .quiz-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attempt-stats {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .attempt-stats .count {
            font-weight: 600;
            color: var(--dark);
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            color: var(--gray);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
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

        .btn-success:hover {
            background: #0d9c6e;
            transform: translateY(-2px);
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
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray);
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f1f5f9;
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--danger);
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

        .form-help {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-check input {
            width: auto;
        }

        .form-check label {
            margin-bottom: 0;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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

        .message i {
            font-size: 1.25rem;
        }

        /* Filters */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
        }

        .clear-filters {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .clear-filters:hover {
            text-decoration: underline;
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .search-box button {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
            text-align: center;
            cursor: pointer;
        }

        .action-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            background: #f8fafc;
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        .action-label {
            font-size: 0.875rem;
            font-weight: 600;
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
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Quizzes</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Quizzes</h1>
                    <p><?php echo htmlspecialchars($class['name']); ?></p>
                </div>
                <span class="status-badge status-<?php echo $class['status']; ?>">
                    <?php echo ucfirst($class['status']); ?>
                </span>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($create_success): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Quiz created successfully!</strong>
                    <p style="margin-top: 0.25rem;">You can now add questions to your quiz.</p>
                </div>
            </div>
        <?php elseif ($create_error): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Creation failed!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($create_error); ?></p>
                </div>
            </div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Quiz deleted successfully!</strong>
                </div>
            </div>
        <?php elseif (isset($_GET['published'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Quiz published successfully!</strong>
                </div>
            </div>
        <?php elseif (isset($_GET['unpublished'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Quiz unpublished successfully!</strong>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total_quizzes']; ?></div>
                <div class="stat-label">Total Quizzes</div>
            </div>
            <div class="stat-card published">
                <div class="stat-value"><?php echo $stats['published']; ?></div>
                <div class="stat-label">Published</div>
            </div>
            <div class="stat-card active">
                <div class="stat-value"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-value"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="tab active">
                <i class="fas fa-list"></i> All Quizzes
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=published" class="tab">
                <i class="fas fa-eye"></i> Published
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=draft" class="tab">
                <i class="fas fa-edit"></i> Drafts
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=active" class="tab">
                <i class="fas fa-clock"></i> Active
            </a>
            <button class="tab" onclick="openCreateModal()" style="margin-left: auto;">
                <i class="fas fa-plus-circle"></i> New Quiz
            </button>
        </div>

        <div class="content-grid">
            <!-- Left Column - Quizzes -->
            <div class="left-column">
                <?php if (empty($quizzes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-question-circle"></i>
                        <h3>No Quizzes Found</h3>
                        <p>You haven't created any quizzes yet. Click "New Quiz" to create your first quiz.</p>
                    </div>
                <?php else: ?>
                    <div class="quizzes-grid">
                        <?php foreach ($quizzes as $quiz):
                            $status = 'draft';
                            $now = time();
                            $available_to = strtotime($quiz['available_to'] ?? '');
                            $due_date = strtotime($quiz['due_date'] ?? '');

                            if ($quiz['is_published']) {
                                if ($available_to && $now > $available_to) {
                                    $status = 'completed';
                                } elseif ($due_date && $now > $due_date) {
                                    $status = 'completed';
                                } elseif ($available_to && $now <= $available_to) {
                                    $status = 'active';
                                } else {
                                    $status = 'upcoming';
                                }
                            }
                        ?>
                            <div class="quiz-card">
                                <div class="quiz-header">
                                    <div class="quiz-title">
                                        <span><?php echo htmlspecialchars($quiz['title']); ?></span>
                                        <span class="status-badge-small status-<?php echo $status; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </div>
                                    <?php if ($quiz['due_date']): ?>
                                        <div class="quiz-due <?php echo strtotime($quiz['due_date']) < time() ? 'overdue' : ''; ?>">
                                            <i class="fas fa-calendar-alt"></i>
                                            Due: <?php echo date('M d, Y g:i A', strtotime($quiz['due_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-star"></i>
                                            <?php echo $quiz['total_points']; ?> points
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <?php echo $quiz['time_limit'] ? $quiz['time_limit'] . ' min' : 'No limit'; ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-redo"></i>
                                            <?php echo $quiz['attempts_allowed']; ?> attempts
                                        </div>
                                    </div>
                                </div>

                                <div class="quiz-body">
                                    <?php if (!empty($quiz['description'])): ?>
                                        <div class="quiz-description">
                                            <?php echo htmlspecialchars($quiz['description']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="attempt-stats">
                                        <strong>Attempts:</strong>
                                        <span class="count"><?php echo $quiz['attempt_count']; ?></span> total •
                                        <span style="color: var(--success);"><?php echo $quiz['student_count']; ?></span> students •
                                        <span style="color: var(--info);"><?php echo $quiz['average_score'] ? round($quiz['average_score'], 1) : 0; ?>%</span> avg
                                    </div>
                                </div>

                                <div class="quiz-footer">
                                    <div class="attempt-stats">
                                        <i class="fas fa-users"></i>
                                        <?php echo $quiz['attempt_count']; ?> attempts
                                    </div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="quiz_builder.php?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="quiz_results.php?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-chart-line"></i> Results
                                        </a>
                                        <a href="?class_id=<?php echo $class_id; ?>&delete_quiz=<?php echo $quiz['id']; ?>"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Are you sure you want to delete this quiz? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Filters & Actions -->
            <div class="right-column">
                <!-- Filters -->
                <div class="filters-card">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <a href="?class_id=<?php echo $class_id; ?>" class="clear-filters">
                            Clear All
                        </a>
                    </div>

                    <form method="GET" action="">
                        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                        <div class="search-box">
                            <input type="text" name="search" placeholder="Search quizzes..."
                                onkeypress="if(event.key==='Enter') this.form.submit()">
                            <button type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Actions -->
                <div class="filters-card">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <div class="quick-actions">
                        <button class="action-item" onclick="openCreateModal()">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="action-label">New Quiz</div>
                        </button>
                        <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="action-label">Gradebook</div>
                        </a>
                        <a href="#" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-download"></i>
                            </div>
                            <div class="action-label">Export Results</div>
                        </a>
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>&action=create" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="action-label">New Assignment</div>
                        </a>
                    </div>
                </div>

                <!-- Quiz Stats -->
                <div class="filters-card">
                    <h3><i class="fas fa-chart-bar"></i> Performance Overview</h3>
                    <div style="font-size: 0.875rem; line-height: 1.6;">
                        <?php if (empty($attempts_stats)): ?>
                            <div style="color: var(--gray); font-style: italic;">
                                No quiz attempts yet
                            </div>
                        <?php else: ?>
                            <?php
                            $total_attempts = 0;
                            $total_students = 0;
                            $total_avg_score = 0;
                            $quiz_count = count($attempts_stats);

                            foreach ($attempts_stats as $stat) {
                                $total_attempts += $stat['total_attempts'];
                                $total_students += $stat['students_attempted'];
                                $total_avg_score += $stat['average_score'];
                            }

                            $overall_avg = $quiz_count > 0 ? $total_avg_score / $quiz_count : 0;
                            ?>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Total Attempts:</strong> <?php echo $total_attempts; ?>
                            </div>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Unique Students:</strong> <?php echo $total_students; ?>
                            </div>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Overall Average:</strong> <span style="color: var(--info);"><?php echo round($overall_avg, 1); ?>%</span>
                            </div>
                            <div>
                                <strong>Quizzes with attempts:</strong>
                                <div style="margin-top: 0.25rem;">
                                    <?php
                                    $active_quizzes = array_filter($attempts_stats, function ($stat) {
                                        return $stat['total_attempts'] > 0;
                                    });
                                    $progress = count($active_quizzes) / $quiz_count * 100;
                                    ?>
                                    <div style="background: #e2e8f0; border-radius: 10px; height: 8px; overflow: hidden; margin-bottom: 0.25rem;">
                                        <div style="background: var(--info); height: 100%; width: <?php echo $progress; ?>%;"></div>
                                    </div>
                                    <div style="text-align: center; font-size: 0.75rem; color: var(--gray);">
                                        <?php echo count($active_quizzes); ?> of <?php echo $quiz_count; ?> quizzes have attempts
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Quiz Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Quiz</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_quiz">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="modal-body">
                    <div class="form-group">
                        <label for="quiz_title" class="required">Quiz Title</label>
                        <input type="text" id="quiz_title" name="title" class="form-control"
                            placeholder="e.g., Mid-term Quiz" required>
                    </div>

                    <div class="form-group">
                        <label for="quiz_description">Description</label>
                        <textarea id="quiz_description" name="description" class="form-control" rows="3"
                            placeholder="Describe the quiz objectives and topics covered"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="quiz_instructions">Instructions</label>
                        <textarea id="quiz_instructions" name="instructions" class="form-control" rows="4"
                            placeholder="Instructions for students taking the quiz"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="quiz_type">Quiz Type</label>
                            <select id="quiz_type" name="quiz_type" class="form-control">
                                <option value="graded">Graded Quiz</option>
                                <option value="practice">Practice Quiz</option>
                                <option value="survey">Survey</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="total_points">Total Points</label>
                            <input type="number" id="total_points" name="total_points" class="form-control"
                                value="100" min="1" max="1000" step="0.5">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="time_limit">Time Limit (minutes)</label>
                            <input type="number" id="time_limit" name="time_limit" class="form-control"
                                value="0" min="0" max="300">
                            <div class="form-help">0 = no time limit</div>
                        </div>
                        <div class="form-group">
                            <label for="attempts_allowed">Attempts Allowed</label>
                            <input type="number" id="attempts_allowed" name="attempts_allowed" class="form-control"
                                value="1" min="1" max="10">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="available_from">Available From</label>
                            <input type="datetime-local" id="available_from" name="available_from" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="available_to">Available To</label>
                            <input type="datetime-local" id="available_to" name="available_to" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="due_date">Due Date</label>
                        <input type="datetime-local" id="due_date" name="due_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <h4 style="margin-bottom: 0.75rem;">Quiz Settings</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-check">
                                <input type="checkbox" id="shuffle_questions" name="shuffle_questions" value="1">
                                <label for="shuffle_questions">Shuffle Questions</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="shuffle_options" name="shuffle_options" value="1">
                                <label for="shuffle_options">Shuffle Options</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="show_correct_answers" name="show_correct_answers" value="1" checked>
                                <label for="show_correct_answers">Show Correct Answers</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="show_points" name="show_points" value="1" checked>
                                <label for="show_points">Show Points</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="auto_submit" name="auto_submit" value="1" checked>
                                <label for="auto_submit">Auto-submit when time expires</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Quiz & Add Questions
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openCreateModal() {
            document.getElementById('createModal').classList.add('show');
            document.body.style.overflow = 'hidden';

            // Set default dates
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(23, 59, 0, 0);

            // Format for datetime-local input
            function formatDateTime(date) {
                const pad = (n) => n.toString().padStart(2, '0');
                return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
            }

            document.getElementById('available_from').value = formatDateTime(now);
            document.getElementById('available_to').value = formatDateTime(tomorrow);
            document.getElementById('due_date').value = formatDateTime(tomorrow);
        }

        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close modals on outside click
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('createModal');
            if (event.target === modal) {
                closeCreateModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCreateModal();
            }
        });

        // Set minimum date to today for date inputs
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('available_from').min = today + 'T00:00';
        document.getElementById('available_to').min = today + 'T00:00';
        document.getElementById('due_date').min = today + 'T00:00';

        // Show/hide time limit warning
        document.getElementById('time_limit').addEventListener('change', function() {
            const timeLimit = parseInt(this.value);
            if (timeLimit > 0) {
                // Optional: Show a warning for long time limits
                if (timeLimit > 180) {
                    if (!confirm('Time limit is set to ' + timeLimit + ' minutes (3 hours). Is this correct?')) {
                        this.value = 60;
                    }
                }
            }
        });

        // Auto-hide success messages
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.message-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + N to create new quiz
                if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    openCreateModal();
                }

                // Ctrl + F to focus search
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    document.querySelector('input[name="search"]').focus();
                }
            });
        });
    </script>
</body>

</html>