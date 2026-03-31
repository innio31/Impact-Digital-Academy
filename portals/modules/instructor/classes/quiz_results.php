<?php
// modules/instructor/classes/quiz_results.php

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

// Get class ID and quiz ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id']) || !isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) {
    header('Location: quizzes.php');
    exit();
}

$class_id = (int)$_GET['class_id'];
$quiz_id = (int)$_GET['quiz_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify quiz belongs to instructor and class
$quiz_sql = "SELECT q.*, c.batch_code, c.name as class_name, 
                    CONCAT(u.first_name, ' ', u.last_name) as instructor_name
             FROM quizzes q
             JOIN class_batches c ON q.class_id = c.id
             JOIN users u ON q.instructor_id = u.id
             WHERE q.id = ? AND q.instructor_id = ? AND q.class_id = ?";
$stmt = $conn->prepare($quiz_sql);
$stmt->bind_param("iii", $quiz_id, $instructor_id, $class_id);
$stmt->execute();
$quiz_result = $stmt->get_result();

if ($quiz_result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: quizzes.php?class_id=' . $class_id);
    exit();
}

$quiz = $quiz_result->fetch_assoc();
$stmt->close();

// Handle regrading action
$regrade_success = false;
$regrade_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'regrade_question' && isset($_POST['answer_id']) && isset($_POST['points_awarded'])) {
        $answer_id = (int)$_POST['answer_id'];
        $points_awarded = floatval($_POST['points_awarded']);

        // Get answer details
        $answer_sql = "SELECT a.*, q.points as max_points, q.question_text, q.question_type
                       FROM quiz_answers a
                       JOIN quiz_questions q ON a.question_id = q.id
                       WHERE a.id = ? AND q.quiz_id = ?";
        $stmt = $conn->prepare($answer_sql);
        $stmt->bind_param("ii", $answer_id, $quiz_id);
        $stmt->execute();
        $answer_result = $stmt->get_result();

        if ($answer_result->num_rows > 0) {
            $answer = $answer_result->fetch_assoc();
            $max_points = floatval($answer['max_points']);
            $points_awarded = min($points_awarded, $max_points);
            $is_correct = ($points_awarded >= $max_points) ? 1 : 0;

            // Update answer
            $update_sql = "UPDATE quiz_answers 
                           SET points_awarded = ?, is_correct = ?, graded_by = ?, graded_at = NOW()
                           WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("diii", $points_awarded, $is_correct, $instructor_id, $answer_id);
            $update_stmt->execute();
            $update_stmt->close();

            // Recalculate attempt total score
            $attempt_id = $answer['attempt_id'];
            $recalc_sql = "SELECT SUM(points_awarded) as total_awarded, SUM(max_points) as total_possible
                           FROM quiz_answers 
                           WHERE attempt_id = ?";
            $recalc_stmt = $conn->prepare($recalc_sql);
            $recalc_stmt->bind_param("i", $attempt_id);
            $recalc_stmt->execute();
            $recalc_result = $recalc_stmt->get_result();
            $totals = $recalc_result->fetch_assoc();
            $recalc_stmt->close();

            $total_score = floatval($totals['total_awarded']);
            $max_score = floatval($totals['total_possible']);
            $percentage = ($max_score > 0) ? ($total_score / $max_score) * 100 : 0;

            // Update attempt
            $update_attempt_sql = "UPDATE quiz_attempts 
                                   SET total_score = ?, max_score = ?, percentage = ?, status = 'graded'
                                   WHERE id = ?";
            $update_attempt_stmt = $conn->prepare($update_attempt_sql);
            $update_attempt_stmt->bind_param("dddi", $total_score, $max_score, $percentage, $attempt_id);
            $update_attempt_stmt->execute();
            $update_attempt_stmt->close();

            $regrade_success = true;
            logActivity('quiz_regraded', "Regraded answer ID: $answer_id for quiz: {$quiz['title']}", 'quizzes', $quiz_id);
        } else {
            $regrade_error = "Answer not found";
        }
        $stmt->close();
    } elseif ($_POST['action'] === 'bulk_regrade' && isset($_POST['attempt_id']) && isset($_POST['answers'])) {
        $attempt_id = (int)$_POST['attempt_id'];
        $answers = $_POST['answers'];

        foreach ($answers as $answer_id => $points) {
            $points_awarded = floatval($points);

            // Get answer details to get max points
            $answer_sql = "SELECT a.*, q.points as max_points
                           FROM quiz_answers a
                           JOIN quiz_questions q ON a.question_id = q.id
                           WHERE a.id = ? AND q.quiz_id = ?";
            $stmt = $conn->prepare($answer_sql);
            $stmt->bind_param("ii", $answer_id, $quiz_id);
            $stmt->execute();
            $answer_result = $stmt->get_result();

            if ($answer_result->num_rows > 0) {
                $answer = $answer_result->fetch_assoc();
                $max_points = floatval($answer['max_points']);
                $points_awarded = min($points_awarded, $max_points);
                $is_correct = ($points_awarded >= $max_points) ? 1 : 0;

                $update_sql = "UPDATE quiz_answers 
                               SET points_awarded = ?, is_correct = ?, graded_by = ?, graded_at = NOW()
                               WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("diii", $points_awarded, $is_correct, $instructor_id, $answer_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $stmt->close();
        }

        // Recalculate attempt total
        $recalc_sql = "SELECT SUM(points_awarded) as total_awarded, SUM(max_points) as total_possible
                       FROM quiz_answers 
                       WHERE attempt_id = ?";
        $recalc_stmt = $conn->prepare($recalc_sql);
        $recalc_stmt->bind_param("i", $attempt_id);
        $recalc_stmt->execute();
        $recalc_result = $recalc_stmt->get_result();
        $totals = $recalc_result->fetch_assoc();
        $recalc_stmt->close();

        $total_score = floatval($totals['total_awarded']);
        $max_score = floatval($totals['total_possible']);
        $percentage = ($max_score > 0) ? ($total_score / $max_score) * 100 : 0;

        $update_attempt_sql = "UPDATE quiz_attempts 
                               SET total_score = ?, max_score = ?, percentage = ?, status = 'graded'
                               WHERE id = ?";
        $update_attempt_stmt = $conn->prepare($update_attempt_sql);
        $update_attempt_stmt->bind_param("dddi", $total_score, $max_score, $percentage, $attempt_id);
        $update_attempt_stmt->execute();
        $update_attempt_stmt->close();

        $regrade_success = true;
        logActivity('quiz_bulk_regraded', "Bulk regraded attempt ID: $attempt_id for quiz: {$quiz['title']}", 'quizzes', $quiz_id);
    }
}

// Handle download results as CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="quiz_results_' . $quiz['title'] . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, [
        'Student Name',
        'Student Email',
        'Attempt',
        'Start Time',
        'End Time',
        'Time Taken (sec)',
        'Score',
        'Max Score',
        'Percentage',
        'Status',
        'Auto Submitted'
    ]);

    // Get attempts data
    $export_sql = "SELECT a.*, u.first_name, u.last_name, u.email
                   FROM quiz_attempts a
                   JOIN users u ON a.student_id = u.id
                   WHERE a.quiz_id = ?
                   ORDER BY a.start_time DESC";
    $export_stmt = $conn->prepare($export_sql);
    $export_stmt->bind_param("i", $quiz_id);
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();

    while ($row = $export_result->fetch_assoc()) {
        fputcsv($output, [
            $row['first_name'] . ' ' . $row['last_name'],
            $row['email'],
            $row['attempt_number'],
            $row['start_time'],
            $row['end_time'],
            $row['time_taken'],
            $row['total_score'],
            $row['max_score'],
            $row['percentage'],
            $row['status'],
            $row['auto_submitted'] ? 'Yes' : 'No'
        ]);
    }

    $export_stmt->close();
    fclose($output);
    exit();
}

// Get quiz statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT a.id) as total_attempts,
                COUNT(DISTINCT a.student_id) as students_attempted,
                AVG(a.percentage) as average_percentage,
                MIN(a.percentage) as min_percentage,
                MAX(a.percentage) as max_percentage,
                SUM(CASE WHEN a.status = 'graded' THEN 1 ELSE 0 END) as graded_attempts,
                SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_attempts,
                SUM(CASE WHEN a.status = 'expired' THEN 1 ELSE 0 END) as expired_attempts
              FROM quiz_attempts a
              WHERE a.quiz_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Convert null values to 0 for numeric fields
$stats['total_attempts'] = $stats['total_attempts'] ?? 0;
$stats['students_attempted'] = $stats['students_attempted'] ?? 0;
$stats['average_percentage'] = $stats['average_percentage'] ?? 0;
$stats['min_percentage'] = $stats['min_percentage'] ?? 0;
$stats['max_percentage'] = $stats['max_percentage'] ?? 0;
$stats['graded_attempts'] = $stats['graded_attempts'] ?? 0;
$stats['in_progress_attempts'] = $stats['in_progress_attempts'] ?? 0;
$stats['expired_attempts'] = $stats['expired_attempts'] ?? 0;

// Get question statistics
$questions_sql = "SELECT q.id, q.question_text, q.question_type, q.points,
                         COUNT(a.id) as attempts_count,
                         AVG(an.points_awarded) as avg_points,
                         SUM(CASE WHEN an.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
                  FROM quiz_questions q
                  LEFT JOIN quiz_answers an ON q.id = an.question_id
                  LEFT JOIN quiz_attempts a ON an.attempt_id = a.id AND a.status IN ('graded', 'submitted')
                  WHERE q.quiz_id = ?
                  GROUP BY q.id
                  ORDER BY q.order_number ASC";
$stmt = $conn->prepare($questions_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions_stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get attempts for this quiz with student info
$attempts_sql = "SELECT a.*, u.id as student_id, u.first_name, u.last_name, u.email
                 FROM quiz_attempts a
                 JOIN users u ON a.student_id = u.id
                 WHERE a.quiz_id = ?
                 ORDER BY a.start_time DESC";
$stmt = $conn->prepare($attempts_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all enrolled students for this class (for completion tracking)
$enrolled_sql = "SELECT u.id, u.first_name, u.last_name, u.email
                 FROM enrollments e
                 JOIN users u ON e.student_id = u.id
                 WHERE e.class_id = ? AND e.status = 'active'";
$stmt = $conn->prepare($enrolled_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$enrolled_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Map attempted students
$attempted_student_ids = array_column($attempts, 'student_id');
$students_not_attempted = array_filter($enrolled_students, function ($student) use ($attempted_student_ids) {
    return !in_array($student['id'], $attempted_student_ids);
});

// Get answers for specific attempt if requested
$selected_attempt = null;
$selected_attempt_answers = [];
if (isset($_GET['view_attempt']) && is_numeric($_GET['view_attempt'])) {
    $attempt_id = (int)$_GET['view_attempt'];
    $attempt_sql = "SELECT a.*, u.first_name, u.last_name, u.email
                    FROM quiz_attempts a
                    JOIN users u ON a.student_id = u.id
                    WHERE a.id = ? AND a.quiz_id = ?";
    $stmt = $conn->prepare($attempt_sql);
    $stmt->bind_param("ii", $attempt_id, $quiz_id);
    $stmt->execute();
    $attempt_result = $stmt->get_result();
    if ($attempt_result->num_rows > 0) {
        $selected_attempt = $attempt_result->fetch_assoc();

        // Get answers for this attempt with question details
        $answers_sql = "SELECT an.*, q.question_text, q.question_type, q.points as max_points, q.order_number,
                               q.explanation, q.question_image
                        FROM quiz_answers an
                        JOIN quiz_questions q ON an.question_id = q.id
                        WHERE an.attempt_id = ?
                        ORDER BY q.order_number ASC";
        $answers_stmt = $conn->prepare($answers_sql);
        $answers_stmt->bind_param("i", $attempt_id);
        $answers_stmt->execute();
        $selected_attempt_answers = $answers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $answers_stmt->close();
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Results: <?php echo htmlspecialchars($quiz['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            flex-wrap: wrap;
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
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .quiz-info h1 {
            font-size: 1.75rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .quiz-info p {
            color: var(--gray);
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--light);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: #e2e8f0;
            color: var(--dark);
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
            padding: 1.25rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .stat-sub {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: none;
            border-radius: 8px 8px 0 0;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            color: var(--primary);
            background: #f8fafc;
        }

        .tab.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: white;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--dark);
        }

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #e0f2fe;
            color: #0369a1;
        }

        .score-high {
            color: var(--success);
            font-weight: 600;
        }

        .score-medium {
            color: var(--warning);
            font-weight: 600;
        }

        .score-low {
            color: var(--danger);
            font-weight: 600;
        }

        /* Question Analysis Cards */
        .question-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .question-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .question-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .question-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
        }

        .question-body {
            padding: 1.5rem;
        }

        .question-text {
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .progress-bar {
            background: #e2e8f0;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            background: var(--primary);
            height: 100%;
            border-radius: 10px;
        }

        /* Attempt Detail View */
        .attempt-detail {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .attempt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .answer-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .answer-correct {
            border-left: 4px solid var(--success);
        }

        .answer-incorrect {
            border-left: 4px solid var(--danger);
        }

        .answer-partial {
            border-left: 4px solid var(--warning);
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--gray);
        }

        /* Form */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
        }

        /* Messages */
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .message-success {
            background: #d1fae5;
            color: #065f46;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Charts */
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        canvas {
            max-height: 300px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            th,
            td {
                padding: 0.75rem;
            }

            .question-header {
                flex-direction: column;
                align-items: flex-start;
            }
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
                <?php echo htmlspecialchars($quiz['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>">
                <i class="fas fa-question-circle"></i> Quizzes
            </a>
            <span class="separator">/</span>
            <span>Results: <?php echo htmlspecialchars($quiz['title']); ?></span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="quiz-info">
                    <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>
                    <p><?php echo htmlspecialchars($quiz['class_name']); ?> •
                        <?php echo $quiz['total_points']; ?> points •
                        <?php echo $quiz['time_limit'] ? $quiz['time_limit'] . ' min' : 'No time limit'; ?>
                    </p>
                </div>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Quizzes
                </a>
            </div>
            <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>&export=csv" class="btn btn-secondary">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <a href="quiz_builder.php?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Edit Quiz
                </a>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_attempts']; ?></div>
                <div class="stat-label">Total Attempts</div>
                <div class="stat-sub"><?php echo $stats['students_attempted']; ?> students attempted</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['average_percentage'], 1); ?>%</div>
                <div class="stat-label">Average Score</div>
                <div class="stat-sub">Min: <?php echo number_format($stats['min_percentage'], 1); ?>% • Max: <?php echo number_format($stats['max_percentage'], 1); ?>%</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['graded_attempts']; ?></div>
                <div class="stat-label">Graded Attempts</div>
                <div class="stat-sub"><?php echo $stats['in_progress_attempts']; ?> in progress, <?php echo $stats['expired_attempts']; ?> expired</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($students_not_attempted); ?></div>
                <div class="stat-label">Not Attempted</div>
                <div class="stat-sub">Out of <?php echo count($enrolled_students); ?> enrolled students</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" data-tab="overview">
                <i class="fas fa-chart-line"></i> Overview
            </button>
            <button class="tab" data-tab="attempts">
                <i class="fas fa-users"></i> Student Attempts
            </button>
            <button class="tab" data-tab="questions">
                <i class="fas fa-question-circle"></i> Question Analysis
            </button>
            <button class="tab" data-tab="not-attempted">
                <i class="fas fa-user-slash"></i> Not Attempted
            </button>
        </div>

        <!-- Tab: Overview -->
        <div id="overview" class="tab-content active">
            <div class="chart-container">
                <canvas id="scoreDistributionChart"></canvas>
            </div>

            <div class="chart-container">
                <canvas id="questionDifficultyChart"></canvas>
            </div>
        </div>

        <!-- Tab: Student Attempts -->
        <div id="attempts" class="tab-content">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Attempt</th>
                            <th>Start Time</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $attempt):
                            $scoreClass = '';
                            if ($attempt['percentage'] >= 70) $scoreClass = 'score-high';
                            elseif ($attempt['percentage'] >= 50) $scoreClass = 'score-medium';
                            else $scoreClass = 'score-low';

                            $statusClass = '';
                            $statusText = '';
                            if ($attempt['status'] === 'graded') {
                                $statusClass = 'badge-success';
                                $statusText = 'Graded';
                            } elseif ($attempt['status'] === 'submitted') {
                                $statusClass = 'badge-warning';
                                $statusText = 'Submitted';
                            } elseif ($attempt['status'] === 'in_progress') {
                                $statusClass = 'badge-info';
                                $statusText = 'In Progress';
                            } else {
                                $statusClass = 'badge-danger';
                                $statusText = 'Expired';
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($attempt['email']); ?></td>
                                <td><?php echo $attempt['attempt_number']; ?></td>
                                <td><?php echo date('M d, Y g:i A', strtotime($attempt['start_time'])); ?></td>
                                <td><?php echo $attempt['total_score']; ?> / <?php echo $attempt['max_score']; ?></td>
                                <td class="<?php echo $scoreClass; ?>"><?php echo round($attempt['percentage'], 1); ?>%</td>
                                <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                <td>
                                    <a href="?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>&view_attempt=<?php echo $attempt['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($attempts)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--gray);">
                                    No attempts yet for this quiz.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab: Question Analysis -->
        <div id="questions" class="tab-content">
            <?php foreach ($questions_stats as $qstat):
                $attempts_count = $qstat['attempts_count'] ?? 0;
                $correct_count = $qstat['correct_count'] ?? 0;
                $avg_points = $qstat['avg_points'] ?? 0;
                $points = $qstat['points'] ?? 1;

                $correct_rate = $attempts_count > 0 ? ($correct_count / $attempts_count) * 100 : 0;
                $avg_points_rate = $points > 0 ? ($avg_points / $points) * 100 : 0;
            ?>
                <div class="question-card">
                    <div class="question-header">
                        <div class="question-title">
                            <i class="fas fa-question-circle"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $qstat['question_type'])); ?> Question
                        </div>
                        <div class="question-stats">
                            <span><i class="fas fa-check-circle"></i> <?php echo round($correct_rate, 1); ?>% correct</span>
                            <span><i class="fas fa-star"></i> Avg: <?php echo round($avg_points_rate, 1); ?>%</span>
                            <span><i class="fas fa-chart-simple"></i> <?php echo $qstat['attempts_count']; ?> responses</span>
                        </div>
                    </div>
                    <div class="question-body">
                        <div class="question-text">
                            <?php echo htmlspecialchars($qstat['question_text']); ?>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $correct_rate; ?>%; background: var(--success);"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.875rem; color: var(--gray);">
                            <span><?php echo $qstat['correct_count']; ?> correct</span>
                            <span><?php echo $qstat['attempts_count'] - $qstat['correct_count']; ?> incorrect</span>
                            <span>Points: <?php echo $qstat['points']; ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($questions_stats)): ?>
                <div style="background: white; border-radius: 12px; padding: 2rem; text-align: center; color: var(--gray);">
                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>No questions found for this quiz.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Not Attempted -->
        <div id="not-attempted" class="tab-content">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students_not_attempted as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                <td><span class="badge badge-danger">Not Started</span></td>
                                <td>
                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>?subject=Reminder: Quiz <?php echo urlencode($quiz['title']); ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-envelope"></i> Remind
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students_not_attempted)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--gray);">
                                    <i class="fas fa-check-circle" style="color: var(--success);"></i> All enrolled students have attempted this quiz!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Attempt Detail Modal (hidden by default, shown via URL param) -->
        <?php if ($selected_attempt): ?>
            <div id="attemptModal" class="modal show">
                <div class="modal-content" style="max-width: 900px;">
                    <div class="modal-header">
                        <h3>
                            <i class="fas fa-user-graduate"></i>
                            <?php echo htmlspecialchars($selected_attempt['first_name'] . ' ' . $selected_attempt['last_name']); ?>
                            - Attempt #<?php echo $selected_attempt['attempt_number']; ?>
                        </h3>
                        <button class="modal-close" onclick="closeAttemptModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; justify-content: space-between; flex-wrap: wrap;">
                            <div><strong>Started:</strong> <?php echo date('M d, Y g:i A', strtotime($selected_attempt['start_time'])); ?></div>
                            <div><strong>Completed:</strong> <?php echo $selected_attempt['end_time'] ? date('M d, Y g:i A', strtotime($selected_attempt['end_time'])) : 'Not completed'; ?></div>
                            <div><strong>Time Taken:</strong> <?php echo $selected_attempt['time_taken'] ? gmdate("H:i:s", $selected_attempt['time_taken']) : 'N/A'; ?></div>
                            <div><strong>Score:</strong> <?php echo $selected_attempt['total_score']; ?> / <?php echo $selected_attempt['max_score']; ?> (<?php echo round($selected_attempt['percentage'], 1); ?>%)</div>
                            <div><strong>Status:</strong> <?php echo ucfirst($selected_attempt['status']); ?></div>
                        </div>

                        <h4 style="margin-bottom: 1rem;">Student Answers</h4>

                        <?php foreach ($selected_attempt_answers as $answer):
                            $answerClass = 'answer-item';
                            if ($answer['points_awarded'] >= $answer['max_points']) $answerClass .= ' answer-correct';
                            elseif ($answer['points_awarded'] > 0) $answerClass .= ' answer-partial';
                            else $answerClass .= ' answer-incorrect';
                        ?>
                            <div class="<?php echo $answerClass; ?>">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <strong><?php echo $answer['question_text']; ?></strong>
                                    <span><?php echo $answer['points_awarded']; ?> / <?php echo $answer['max_points']; ?> points</span>
                                </div>
                                <div style="margin-bottom: 0.5rem; color: var(--gray);">
                                    <strong>Student Answer:</strong>
                                    <?php
                                    if ($answer['question_type'] === 'essay') {
                                        echo nl2br(htmlspecialchars($answer['answer_text'] ?? 'No answer provided'));
                                    } elseif ($answer['question_type'] === 'file_upload') {
                                        echo '<a href="' . htmlspecialchars($answer['answer_file']) . '" target="_blank"><i class="fas fa-file"></i> View File</a>';
                                    } else {
                                        echo htmlspecialchars($answer['answer_text'] ?? 'No answer selected');
                                    }
                                    ?>
                                </div>

                                <?php if ($answer['question_type'] === 'essay' || $answer['question_type'] === 'short_answer'): ?>
                                    <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e2e8f0;">
                                        <form method="POST" action="" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                                            <input type="hidden" name="action" value="regrade_question">
                                            <input type="hidden" name="answer_id" value="<?php echo $answer['id']; ?>">
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label>Points Awarded</label>
                                                <input type="number" name="points_awarded" value="<?php echo $answer['points_awarded']; ?>"
                                                    step="0.5" min="0" max="<?php echo $answer['max_points']; ?>" style="width: 100px;">
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="fas fa-save"></i> Update Grade
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($answer['explanation'])): ?>
                                    <div style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--info); background: #e0f2fe; padding: 0.5rem; border-radius: 6px;">
                                        <strong><i class="fas fa-info-circle"></i> Explanation:</strong> <?php echo htmlspecialchars($answer['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                        <a href="?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>" class="btn btn-secondary">Close</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.dataset.tab;
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Close attempt modal
        function closeAttemptModal() {
            const modal = document.getElementById('attemptModal');
            if (modal) {
                modal.classList.remove('show');
                // Remove the modal from DOM or just hide? We'll remove URL param by redirecting
                window.location.href = '?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>';
            }
        }

        // Close modal on background click
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('attemptModal');
            if (modal && event.target === modal) {
                closeAttemptModal();
            }
        });

        // Score Distribution Chart
        <?php if (!empty($attempts)):
            $scoreRanges = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
            $rangeCounts = array_fill(0, count($scoreRanges) - 1, 0);
            foreach ($attempts as $attempt) {
                $percent = floatval($attempt['percentage'] ?? 0);
                for ($i = 0; $i < count($scoreRanges) - 1; $i++) {
                    if ($percent >= $scoreRanges[$i] && ($i == count($scoreRanges) - 2 || $percent < $scoreRanges[$i + 1])) {
                        $rangeCounts[$i]++;
                        break;
                    }
                }
            }
            $labels = [];
            for ($i = 0; $i < count($scoreRanges) - 1; $i++) {
                $labels[] = $scoreRanges[$i] . '-' . ($scoreRanges[$i + 1] == 100 ? '100' : $scoreRanges[$i + 1] - 1);
            }
        ?>
            const ctx1 = document.getElementById('scoreDistributionChart').getContext('2d');
            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($labels); ?>,
                    datasets: [{
                        label: 'Number of Students',
                        data: <?php echo json_encode($rangeCounts); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.6)',
                        borderColor: '#3b82f6',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Score Distribution'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Students'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Score Range (%)'
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        <?php if (!empty($questions_stats)):
            $questionLabels = [];
            $correctRates = [];
            foreach ($questions_stats as $q) {
                $attempts_count = $q['attempts_count'] ?? 0;
                $correct_count = $q['correct_count'] ?? 0;
                $questionLabels[] = 'Q' . ($q['order_number'] ?? 0);
                $correctRates[] = $attempts_count > 0 ? ($correct_count / $attempts_count) * 100 : 0;
            }
        ?>
            const ctx2 = document.getElementById('questionDifficultyChart').getContext('2d');
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($questionLabels); ?>,
                    datasets: [{
                        label: 'Correct Rate (%)',
                        data: <?php echo json_encode($correctRates); ?>,
                        backgroundColor: function(context) {
                            const value = context.raw;
                            if (value >= 70) return 'rgba(16, 185, 129, 0.6)';
                            if (value >= 50) return 'rgba(245, 158, 11, 0.6)';
                            return 'rgba(239, 68, 68, 0.6)';
                        },
                        borderColor: function(context) {
                            const value = context.raw;
                            if (value >= 70) return '#10b981';
                            if (value >= 50) return '#f59e0b';
                            return '#ef4444';
                        },
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Question Difficulty Analysis'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Correct Rate: ${context.raw.toFixed(1)}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Correct Rate (%)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Question Number'
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

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
        });
    </script>
</body>

</html>