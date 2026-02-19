<?php
// modules/student/quizzes/quizzes.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

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

// Verify student is enrolled in this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e 
        JOIN class_batches cb ON e.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        JOIN users u ON cb.instructor_id = u.id 
        WHERE e.class_id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $student_id);
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

// Handle filters
$filter_status = $_GET['status'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// Get student's quiz attempts for this class
$attempts_sql = "SELECT a.*, q.title as quiz_title, q.total_points, q.time_limit,
                        q.attempts_allowed, q.quiz_type
                 FROM quiz_attempts a 
                 JOIN quizzes q ON a.quiz_id = q.id
                 WHERE a.student_id = ? AND q.class_id = ?";
$stmt = $conn->prepare($attempts_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$attempts_result = $stmt->get_result();
$student_attempts = [];
while ($row = $attempts_result->fetch_assoc()) {
    $student_attempts[$row['quiz_id']][] = $row;
}
$stmt->close();

// Build query for quizzes
$query = "SELECT q.*, 
                 COUNT(DISTINCT a.id) as attempt_count,
                 MAX(a.percentage) as best_score,
                 MAX(a.start_time) as last_attempt_time
          FROM quizzes q 
          LEFT JOIN quiz_attempts a ON q.id = a.quiz_id AND a.student_id = ?
          WHERE q.class_id = ? AND q.is_published = 1";

$params = [$student_id, $class_id];
$types = "ii";

// Apply status filter
if ($filter_status === 'upcoming') {
    $query .= " AND q.available_from > NOW()";
} elseif ($filter_status === 'available') {
    $query .= " AND q.available_from <= NOW() AND (q.available_to IS NULL OR q.available_to >= NOW())";
} elseif ($filter_status === 'past_due') {
    $query .= " AND q.available_to < NOW()";
} elseif ($filter_status === 'attempted') {
    $query .= " AND a.id IS NOT NULL";
} elseif ($filter_status === 'not_attempted') {
    $query .= " AND a.id IS NULL AND (q.available_to IS NULL OR q.available_to >= NOW())";
} elseif ($filter_status === 'completed') {
    $query .= " AND a.status = 'graded'";
} elseif ($filter_status === 'in_progress') {
    $query .= " AND a.status = 'in_progress'";
}

// Apply search filter
if (!empty($filter_search)) {
    $query .= " AND (q.title LIKE ? OR q.description LIKE ?)";
    $search_param = "%$filter_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$query .= " GROUP BY q.id ORDER BY q.available_from ASC";

// Get quizzes
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$quizzes = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();

// Function to get quiz status badge
function getQuizStatusBadge($quiz, $attempts)
{
    $now = time();
    $available_from = strtotime($quiz['available_from']);
    $available_to = $quiz['available_to'] ? strtotime($quiz['available_to']) : null;

    if ($attempts && count($attempts) > 0) {
        $last_attempt = end($attempts);

        if ($last_attempt['status'] === 'graded') {
            return '<span class="status-badge status-graded">Completed</span>';
        } elseif ($last_attempt['status'] === 'submitted') {
            return '<span class="status-badge status-submitted">Submitted</span>';
        } elseif ($last_attempt['status'] === 'in_progress') {
            return '<span class="status-badge status-in-progress">In Progress</span>';
        }
    }

    if ($available_to && $now > $available_to) {
        return '<span class="status-badge status-missing">Past Due</span>';
    } elseif ($now < $available_from) {
        return '<span class="status-badge status-upcoming">Upcoming</span>';
    } else {
        return '<span class="status-badge status-available">Available</span>';
    }
}

// Function to get quiz type label
function getQuizTypeLabel($type)
{
    $labels = [
        'practice' => 'Practice Quiz',
        'graded' => 'Graded Quiz',
        'exam' => 'Exam',
        'survey' => 'Survey'
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Quizzes</title>
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
            --purple: #8b5cf6;
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
            padding-bottom: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .class-info h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .class-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-title h2 {
            font-size: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title p {
            color: var(--gray);
            margin-top: 0.5rem;
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
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.submitted {
            border-top-color: var(--success);
        }

        .stat-card.missing {
            border-top-color: var(--warning);
        }

        .stat-card.graded {
            border-top-color: var(--info);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--dark);
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

        /* Search and Filter */
        .search-filter {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .clear-filters {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .clear-filters:hover {
            text-decoration: underline;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
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

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Assignment Cards */
        .assignments-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .assignment-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .assignment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .assignment-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            position: relative;
        }

        .assignment-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .assignment-due {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .assignment-due.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #e5e7eb;
            color: #374151;
        }

        .status-submitted {
            background: #d1fae5;
            color: #065f46;
        }

        .status-graded {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .status-missing {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-due-soon {
            background: #fef3c7;
            color: #92400e;
        }

        .assignment-body {
            padding: 1.5rem;
        }

        .assignment-description {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .assignment-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .assignment-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .grade-display {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .grade-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.25rem;
        }

        .grade-excellent {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-good {
            background: #dbeafe;
            color: #1e40af;
        }

        .grade-average {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-poor {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-fail {
            background: #fee2e2;
            color: #991b1b;
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
            max-width: 700px;
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

        .file-upload-area {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .file-upload-area.dragover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Submission Files */
        .submission-files {
            margin-top: 1rem;
        }

        .file-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-icon {
            color: var(--primary);
        }

        .feedback-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .feedback-content {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .back-button:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .assignment-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .assignment-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .search-input {
                min-width: 100%;
            }

            .tabs {
                justify-content: center;
            }

            .tab {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }

        .quiz-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 6px solid var(--primary);
        }

        .quiz-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .quiz-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .quiz-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .quiz-meta {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .attempt-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 3px solid var(--info);
        }

        .attempt-score {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-right: 1rem;
        }

        .status-in-progress {
            background: #fef3c7;
            color: #92400e;
        }

        .status-available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-upcoming {
            background: #dbeafe;
            color: #1e40af;
        }

        .quiz-type-badge {
            background: #8b5cf6;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .quiz-description {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin: 1rem 0;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .quiz-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: 6px;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Quizzes</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?> - <?php echo htmlspecialchars($class['program_name']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-book"></i> Materials
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-users"></i> Classmates
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i> Join Class
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>
                    <i class="fas fa-question-circle"></i>
                    Quizzes & Tests
                </h2>
                <p>Take quizzes and view your results for <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="stats">
                <span><i class="fas fa-clipboard-check"></i> <?php echo count($quizzes); ?> quizzes</span>
                <?php
                $attempted_count = count(array_filter($quizzes, fn($q) => $q['attempt_count'] > 0));
                $completed_count = count(array_filter(
                    $student_attempts,
                    fn($attempts) =>
                    array_filter($attempts, fn($a) => $a['status'] === 'graded')
                ));
                ?>
                <span><i class="fas fa-check-circle"></i> <?php echo $attempted_count; ?> attempted</span>
            </div>
        </div>

        <!-- Stats -->
        <?php
        $total_quizzes = count($quizzes);
        $attempted_count = count(array_filter($quizzes, fn($q) => $q['attempt_count'] > 0));
        $completed_count = 0;
        $in_progress_count = 0;

        foreach ($student_attempts as $attempts) {
            foreach ($attempts as $attempt) {
                if ($attempt['status'] === 'graded') $completed_count++;
                if ($attempt['status'] === 'in_progress') $in_progress_count++;
            }
        }
        ?>
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $total_quizzes; ?></div>
                <div class="stat-label">Total Quizzes</div>
            </div>
            <div class="stat-card submitted">
                <div class="stat-value"><?php echo $attempted_count; ?></div>
                <div class="stat-label">Attempted</div>
            </div>
            <div class="stat-card missing">
                <div class="stat-value"><?php echo $in_progress_count; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card graded">
                <div class="stat-value"><?php echo $completed_count; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=all"
                class="tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Quizzes
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=upcoming"
                class="tab <?php echo $filter_status === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> Upcoming
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=available"
                class="tab <?php echo $filter_status === 'available' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Available Now
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=in_progress"
                class="tab <?php echo $filter_status === 'in_progress' ? 'active' : ''; ?>">
                <i class="fas fa-spinner"></i> In Progress
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=completed"
                class="tab <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Completed
            </a>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>&status=not_attempted"
                class="tab <?php echo $filter_status === 'not_attempted' ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle"></i> Not Attempted
            </a>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> Search Quizzes</h3>
                <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                    <a href="?class_id=<?php echo $class_id; ?>" class="clear-filters">
                        Clear All
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="" class="search-form" id="filterForm">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search quizzes by title or description..."
                    value="<?php echo htmlspecialchars($filter_search); ?>"
                    id="searchInput">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if (!empty($filter_search) || $filter_status !== 'all'): ?>
                    <a href="?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Quizzes List -->
        <?php if (empty($quizzes)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>No Quizzes Found</h3>
                <p>
                    <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                        No quizzes match your current filters. <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">Clear filters</a> to see all quizzes.
                    <?php else: ?>
                        No quizzes have been published for this class yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="assignments-grid">
                <?php foreach ($quizzes as $quiz): ?>
                    <?php $attempts = $student_attempts[$quiz['id']] ?? []; ?>
                    <div class="quiz-card">
                        <div class="quiz-header">
                            <div class="quiz-title">
                                <span><?php echo htmlspecialchars($quiz['title']); ?></span>
                                <div>
                                    <?php echo getQuizStatusBadge($quiz, $attempts); ?>
                                    <span class="quiz-type-badge"><?php echo getQuizTypeLabel($quiz['quiz_type']); ?></span>
                                </div>
                            </div>

                            <div class="quiz-meta">
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    Available: <?php echo date('M d, Y', strtotime($quiz['available_from'])); ?>
                                    <?php if ($quiz['available_to']): ?>
                                        - <?php echo date('M d, Y', strtotime($quiz['available_to'])); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $quiz['time_limit']; ?> minutes
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-star"></i>
                                    <?php echo $quiz['total_points']; ?> points
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-redo"></i>
                                    <?php echo $quiz['attempts_allowed'] ?: '∞'; ?> attempts allowed
                                </div>
                            </div>

                            <?php if (!empty($quiz['description'])): ?>
                                <div class="quiz-description">
                                    <?php echo nl2br(htmlspecialchars($quiz['description'])); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($quiz['instructions'])): ?>
                                <div class="assignment-description">
                                    <strong>Instructions:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($attempts)): ?>
                                <div class="attempt-info">
                                    <h4>Your Attempts</h4>
                                    <div class="quiz-stats">
                                        <?php
                                        $best_score = 0;
                                        $latest_attempt_time = '';
                                        if (!empty($attempts)) {
                                            $percentages = array_column($attempts, 'percentage');
                                            $best_score = max($percentages);
                                            $latest_attempt = end($attempts);
                                            $latest_attempt_time = $latest_attempt['start_time'];
                                        }
                                        ?>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo count($attempts); ?></div>
                                            <div class="stat-label">Attempts</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo round($best_score, 1); ?>%</div>
                                            <div class="stat-label">Best Score</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $latest_attempt_time ? date('M d', strtotime($latest_attempt_time)) : 'N/A'; ?></div>
                                            <div class="stat-label">Last Attempt</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="assignment-footer">
                            <div>
                                <?php
                                $now = time();
                                $available_from = strtotime($quiz['available_from']);
                                $available_to = $quiz['available_to'] ? strtotime($quiz['available_to']) : null;

                                if ($available_to && $now > $available_to): ?>
                                    <span style="color: var(--danger);">
                                        <i class="fas fa-times-circle"></i> Closed
                                    </span>
                                <?php elseif ($now < $available_from): ?>
                                    <span style="color: var(--info);">
                                        <i class="fas fa-clock"></i> Available in
                                        <?php echo ceil(($available_from - $now) / (60 * 60 * 24)); ?> days
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--success);">
                                        <i class="fas fa-check-circle"></i> Available Now
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <?php
                                $in_progress_attempt = null;
                                foreach ($attempts as $attempt) {
                                    if ($attempt['status'] === 'in_progress') {
                                        $in_progress_attempt = $attempt;
                                        break;
                                    }
                                }

                                if ($in_progress_attempt): ?>
                                    <a href="take_quiz.php?quiz_id=<?php echo $quiz['id']; ?>"
                                        class="btn btn-primary">
                                        <i class="fas fa-play-circle"></i> Continue Quiz
                                    </a>
                                <?php elseif (count($attempts) > 0 && $quiz['attempts_allowed'] > 0 && count($attempts) >= $quiz['attempts_allowed']): ?>
                                    <a href="quiz_results.php?attempt_id=<?php echo $attempts[0]['id']; ?>"
                                        class="btn btn-success">
                                        <i class="fas fa-chart-bar"></i> View Results
                                    </a>
                                <?php elseif ($available_from && $now >= $available_from && (!$available_to || $now <= $available_to)): ?>
                                    <a href="take_quiz.php?quiz_id=<?php echo $quiz['id']; ?>"
                                        class="btn btn-primary">
                                        <i class="fas fa-play"></i> Start Quiz
                                    </a>
                                <?php elseif ($available_to && $now > $available_to && !empty($attempts)): ?>
                                    <a href="quiz_results.php?attempt_id=<?php echo $attempts[0]['id']; ?>"
                                        class="btn btn-secondary">
                                        <i class="fas fa-eye"></i> View Attempt
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($attempts)): ?>
                                    <a href="quiz_results.php?attempt_id=<?php echo $attempts[0]['id']; ?>"
                                        class="btn btn-info">
                                        <i class="fas fa-chart-line"></i> Results
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <script>
        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Search with Enter key
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('filterForm').submit();
                }
            });

            // Debounced search for better UX
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        document.getElementById('filterForm').submit();
                    }
                }, 500);
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + F to focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    document.getElementById('searchInput').focus();
                }
            });
        });
    </script>
</body>

</html>