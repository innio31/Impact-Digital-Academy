<?php
// modules/student/quizzes/quiz_results.php

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

// Get attempt ID from URL
if (!isset($_GET['attempt_id']) || !is_numeric($_GET['attempt_id'])) {
    header('Location: index.php');
    exit();
}

$attempt_id = (int)$_GET['attempt_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get quiz attempt details - CORRECTED: removed show_correct_answers field
$sql = "SELECT a.*, 
               q.title as quiz_title, 
               q.description as quiz_description,
               q.instructions as quiz_instructions,
               q.total_points, 
               q.time_limit,
               q.quiz_type,
               q.class_id,
               cb.batch_code,
               c.title as course_title,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM quiz_attempts a 
        JOIN quizzes q ON a.quiz_id = q.id
        JOIN class_batches cb ON q.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN users u ON cb.instructor_id = u.id
        WHERE a.id = ? AND a.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $attempt_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: quizzes.php');
    exit();
}

$attempt = $result->fetch_assoc();
$stmt->close();

$class_id = $attempt['class_id'];

// Get ALL quiz answers for this attempt - FIXED: Removed LIMIT/WHERE issues
$answers_sql = "SELECT qa.*, qq.question_text, qq.question_type, qq.points as question_points
                FROM quiz_answers qa 
                JOIN quiz_questions qq ON qa.question_id = qq.id
                WHERE qa.attempt_id = ?
                ORDER BY qa.id";
$stmt = $conn->prepare($answers_sql);
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$answers_result = $stmt->get_result();
$answers = $answers_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get options for questions
$question_ids = array_column($answers, 'question_id');
$options = [];
$selected_options = [];

if (!empty($question_ids)) {
    $placeholders = implode(',', array_fill(0, count($question_ids), '?'));

    // Get all options for these questions
    $options_sql = "SELECT qo.* FROM quiz_options qo 
                   WHERE qo.question_id IN ($placeholders) 
                   ORDER BY qo.question_id, qo.order_number";
    $stmt = $conn->prepare($options_sql);
    $stmt->bind_param(str_repeat('i', count($question_ids)), ...$question_ids);
    $stmt->execute();
    $options_result = $stmt->get_result();

    while ($option = $options_result->fetch_assoc()) {
        $options[$option['question_id']][] = $option;
    }
    $stmt->close();

    // Get selected options - CORRECTED: using a.option_id instead of o.option_id
    $selected_sql = "SELECT a.answer_id, a.option_id 
                    FROM quiz_answer_options a 
                    JOIN quiz_options o ON a.option_id = o.id
                    WHERE a.answer_id IN (
                        SELECT id FROM quiz_answers WHERE attempt_id = ?
                    )";
    $stmt = $conn->prepare($selected_sql);
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $selected_result = $stmt->get_result();

    while ($selected = $selected_result->fetch_assoc()) {
        $selected_options[$selected['answer_id']][] = $selected['option_id'];
    }
    $stmt->close();
}

// Get class average for comparison
$stats_sql = "SELECT 
                COUNT(*) as total_attempts,
                AVG(percentage) as average_score,
                MAX(percentage) as highest_score,
                MIN(percentage) as lowest_score
              FROM quiz_attempts 
              WHERE quiz_id = ? AND status = 'graded'";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $attempt['quiz_id']);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stmt->close();

$conn->close();

// Function to get grade color
function getGradeColor($percentage)
{
    if ($percentage >= 90) return 'grade-excellent';
    if ($percentage >= 80) return 'grade-good';
    if ($percentage >= 70) return 'grade-average';
    if ($percentage >= 60) return 'grade-poor';
    return 'grade-fail';
}

// Function to get grade letter
function getGradeLetter($percentage)
{
    if ($percentage >= 90) return 'A';
    if ($percentage >= 80) return 'B';
    if ($percentage >= 70) return 'C';
    if ($percentage >= 60) return 'D';
    if ($percentage >= 50) return 'E';
    return 'F';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Results: <?php echo htmlspecialchars($attempt['quiz_title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== GLOBAL STYLES ===== */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #edf2ff;
            --secondary: #7209b7;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #06b6d4;
            --info-light: #cffafe;
            --dark: #1e293b;
            --gray-dark: #475569;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --gray-lighter: #f1f5f9;
            --white: #ffffff;
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: var(--dark);
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
        }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            color: var(--white);
        }

        .btn-secondary {
            background: var(--gray-light);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: var(--gray);
            color: var(--white);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #0da271;
            color: var(--white);
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #dc2626;
            color: var(--white);
        }

        .btn-info {
            background: var(--info);
            color: var(--white);
        }

        .btn-info:hover {
            background: #0891b2;
            color: var(--white);
        }

        /* ===== BADGES ===== */
        .status-badge {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .grade-excellent {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border: 3px solid #a7f3d0;
        }

        .grade-good {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
            border: 3px solid #bfdbfe;
        }

        .grade-average {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            color: white;
            border: 3px solid #fde68a;
        }

        .grade-poor {
            background: linear-gradient(135deg, #f97316 0%, #fb923c 100%);
            color: white;
            border: 3px solid #fed7aa;
        }

        .grade-fail {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: white;
            border: 3px solid #fecaca;
        }

        /* ===== BREADCRUMB ===== */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            padding: 1rem 1.5rem;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--gray);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .breadcrumb .separator {
            color: var(--gray-light);
        }

        .breadcrumb span:last-child {
            color: var(--dark);
            font-weight: 600;
        }

        /* ===== MODAL ===== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem 1.5rem 1rem;
            border-bottom: 2px solid var(--gray-lighter);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem 1.5rem;
            border-top: 2px solid var(--gray-lighter);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* ===== FORM CONTROLS ===== */
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        /* ===== QUIZ RESULTS SPECIFIC ===== */
        .results-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .results-header {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-left: 6px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .results-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--info), var(--success));
        }

        .score-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4rem;
            margin: 3rem 0;
            flex-wrap: wrap;
        }

        .score-circle {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border: 12px solid;
            position: relative;
            background: var(--white);
            box-shadow: var(--shadow-lg);
        }

        .score-circle::after {
            content: '';
            position: absolute;
            top: -12px;
            left: -12px;
            right: -12px;
            bottom: -12px;
            border-radius: 50%;
            border: 2px dashed var(--gray-light);
            z-index: -1;
        }

        .score-value {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .score-label {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gray);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin: 3rem 0;
        }

        .stat-item {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: var(--border-radius);
            padding: 1.75rem;
            text-align: center;
            border: 2px solid var(--gray-lighter);
            transition: var(--transition);
        }

        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .stat-value {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray);
            font-weight: 600;
        }

        .question-review {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .question-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            border-left: 6px solid var(--gray-light);
            transition: var(--transition);
        }

        .question-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .question-card.correct {
            border-left-color: var(--success);
            background: linear-gradient(to right, var(--success-light) 0%, transparent 100%);
        }

        .question-card.incorrect {
            border-left-color: var(--danger);
            background: linear-gradient(to right, var(--danger-light) 0%, transparent 100%);
        }

        .question-card.partial {
            border-left-color: var(--warning);
            background: linear-gradient(to right, var(--warning-light) 0%, transparent 100%);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--gray-lighter);
        }

        .question-points {
            background: var(--gray-lighter);
            padding: 0.5rem 1.25rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .question-points.correct {
            background: var(--success-light);
            color: #065f46;
        }

        .question-points.incorrect {
            background: var(--danger-light);
            color: #991b1b;
        }

        .question-points.partial {
            background: var(--warning-light);
            color: #92400e;
        }

        .options-review {
            margin-top: 1.5rem;
        }

        .option-item {
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            border-radius: var(--border-radius-sm);
            border: 2px solid var(--gray-lighter);
            background: var(--white);
            transition: var(--transition);
        }

        .option-item:hover {
            transform: translateX(5px);
        }

        .option-item.correct {
            border-color: var(--success);
            background: var(--success-light);
            box-shadow: 0 0 0 1px var(--success);
        }

        .option-item.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .option-item.selected.incorrect {
            border-color: var(--danger);
            background: var(--danger-light);
            box-shadow: 0 0 0 1px var(--danger);
        }

        .option-item.selected.correct {
            border-color: var(--success);
            background: #a7f3d0;
            box-shadow: 0 0 0 1px var(--success);
        }

        .answer-feedback {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1rem;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
        }

        .answer-feedback i {
            font-size: 1.25rem;
        }

        .feedback-correct {
            color: #065f46;
            background: var(--success-light);
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius-sm);
        }

        .feedback-incorrect {
            color: #991b1b;
            background: var(--danger-light);
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius-sm);
        }

        .performance-chart {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin: 2.5rem 0;
            box-shadow: var(--shadow-sm);
            border: 2px solid var(--gray-lighter);
        }

        .comparison-bar {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            gap: 1.5rem;
        }

        .bar-label {
            width: 150px;
            font-weight: 600;
            color: var(--dark);
        }

        .bar-container {
            flex: 1;
            height: 36px;
            background: var(--gray-lighter);
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .bar-fill {
            height: 100%;
            border-radius: 18px;
            transition: width 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            min-width: 40px;
        }

        .bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg,
                    transparent 0%,
                    rgba(255, 255, 255, 0.3) 50%,
                    transparent 100%);
        }

        .bar-value {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 700;
            color: var(--white);
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 2rem;
        }

        /* ===== QUIZ TAKING SPECIFIC ===== */
        .quiz-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 1rem;
        }

        .quiz-header {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-left: 6px solid var(--primary);
            position: relative;
        }

        .quiz-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -6px;
            right: -6px;
            bottom: 0;
            border: 6px solid transparent;
            border-image: linear-gradient(135deg, var(--primary), var(--secondary)) 1;
            border-radius: var(--border-radius);
            z-index: -1;
        }

        .quiz-timer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem 2rem;
            box-shadow: var(--shadow-lg);
            font-weight: 700;
            z-index: 1000;
            border: 3px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 140px;
            justify-content: center;
            animation: timerPulse 2s infinite alternate;
        }

        @keyframes timerPulse {
            from {
                box-shadow: var(--shadow-lg);
            }

            to {
                box-shadow: 0 0 20px rgba(67, 97, 238, 0.3), var(--shadow-lg);
            }
        }

        .timer-warning {
            border-color: var(--warning);
            background: var(--warning-light);
            animation: pulseWarning 2s infinite;
        }

        .timer-danger {
            border-color: var(--danger);
            background: var(--danger-light);
            animation: pulseDanger 1s infinite;
        }

        @keyframes pulseWarning {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        @keyframes pulseDanger {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .question-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .option-label {
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            padding: 1.25rem;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .option-label::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(67, 97, 238, 0.05) 100%);
            opacity: 0;
            transition: var(--transition);
        }

        .option-label:hover::before {
            opacity: 1;
        }

        .option-label:hover {
            border-color: var(--primary);
            transform: translateX(5px);
        }

        .option-label.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .option-label.selected::before {
            opacity: 1;
        }

        .option-text {
            flex: 1;
            font-size: 1.125rem;
            line-height: 1.6;
        }

        .quiz-navigation {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            padding: 1.5rem 2rem;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            gap: 2rem;
        }

        .progress-bar {
            flex: 1;
            margin: 0 2rem;
        }

        .progress-container {
            background: var(--gray-lighter);
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg,
                    transparent 0%,
                    rgba(255, 255, 255, 0.3) 50%,
                    transparent 100%);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .progress-fill {
            background: linear-gradient(90deg, var(--primary), var(--info));
            height: 100%;
            transition: width 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }

        .question-list {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 1rem;
        }

        .question-nav-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--border-radius-sm);
            border: 2px solid var(--gray-light);
            background: var(--white);
            color: var(--dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            transition: var(--transition);
            position: relative;
        }

        .question-nav-btn:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .question-nav-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border-color: transparent;
            box-shadow: var(--shadow);
        }

        .question-nav-btn.answered {
            background: var(--success);
            color: var(--white);
            border-color: transparent;
        }

        .question-nav-btn.answered::after {
            content: '✓';
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--white);
            color: var(--success);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--success);
        }

        .file-upload-container {
            border: 3px dashed var(--gray-light);
            border-radius: var(--border-radius);
            padding: 3rem 2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--gray-lighter);
            position: relative;
            overflow: hidden;
        }

        .file-upload-container:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .file-upload-container i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
            transition: var(--transition);
        }

        .file-upload-container:hover i {
            transform: scale(1.1) translateY(-5px);
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 1024px) {
            .score-display {
                gap: 2rem;
            }

            .score-circle {
                width: 160px;
                height: 160px;
            }

            .score-value {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 768px) {

            .results-container,
            .quiz-container {
                padding: 0.5rem;
                margin: 1rem auto;
            }

            .results-header,
            .quiz-header,
            .question-review {
                padding: 1.5rem;
            }

            .score-display {
                flex-direction: column;
                gap: 2rem;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .question-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .quiz-timer {
                position: relative;
                top: auto;
                right: auto;
                margin: 1rem auto;
                width: fit-content;
            }

            .quiz-navigation {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .progress-bar {
                margin: 0;
                width: 100%;
            }

            .bar-label {
                width: 120px;
                font-size: 0.875rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .score-circle {
                width: 140px;
                height: 140px;
            }

            .score-value {
                font-size: 2rem;
            }

            .question-nav-btn {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }

            .btn {
                padding: 0.625rem 1.25rem;
                font-size: 0.875rem;
            }
        }

        /* ===== PRINT STYLES ===== */
        @media print {

            .quiz-timer,
            .quiz-navigation,
            .btn,
            .question-nav-btn {
                display: none !important;
            }

            body {
                background: white !important;
                font-size: 12pt !important;
            }

            .results-container,
            .quiz-container {
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .results-header,
            .quiz-header,
            .question-review,
            .question-card,
            .stat-item {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }

            .score-circle {
                border-width: 6px !important;
            }

            a {
                color: black !important;
                text-decoration: none !important;
            }
        }

        .results-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .results-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 6px solid var(--primary);
        }

        .score-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .score-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border: 8px solid;
            position: relative;
        }

        .score-value {
            font-size: 2.5rem;
            font-weight: 700;
        }

        .score-label {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.25rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }

        .stat-item {
            background: #f8fafc;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        .question-review {
            margin-top: 2rem;
        }

        .question-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #e2e8f0;
        }

        .question-card.correct {
            border-left-color: #10b981;
            background: #f0fdf4;
        }

        .question-card.incorrect {
            border-left-color: #ef4444;
            background: #fef2f2;
        }

        .question-card.partial {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .question-points {
            background: #e2e8f0;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .question-points.correct {
            background: #d1fae5;
            color: #065f46;
        }

        .question-points.incorrect {
            background: #fee2e2;
            color: #991b1b;
        }

        .question-points.partial {
            background: #fef3c7;
            color: #92400e;
        }

        .options-review {
            margin-top: 1rem;
        }

        .option-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: white;
        }

        .option-item.correct {
            border-color: #10b981;
            background: #d1fae5;
        }

        .option-item.selected {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .option-item.selected.incorrect {
            border-color: #ef4444;
            background: #fee2e2;
        }

        .option-item.selected.correct {
            border-color: #10b981;
            background: #a7f3d0;
        }

        .answer-feedback {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-weight: 600;
        }

        .feedback-correct {
            color: #10b981;
        }

        .feedback-incorrect {
            color: #ef4444;
        }

        .performance-chart {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin: 2rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .comparison-bar {
            display: flex;
            align-items: center;
            margin: 1rem 0;
        }

        .bar-label {
            width: 150px;
            font-weight: 600;
        }

        .bar-container {
            flex: 1;
            height: 30px;
            background: #e2e8f0;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }

        .bar-fill {
            height: 100%;
            border-radius: 15px;
            transition: width 1s ease;
        }

        .bar-value {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 600;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>

<body>
    <div class="results-container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($attempt['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>">
                Quizzes
            </a>
            <span class="separator">/</span>
            <span>Results</span>
        </div>

        <!-- Results Header -->
        <div class="results-header">
            <h1><?php echo htmlspecialchars($attempt['quiz_title']); ?></h1>
            <div style="color: var(--gray); margin-bottom: 1rem;">
                <?php echo htmlspecialchars($attempt['course_title']); ?> •
                Attempt #<?php echo $attempt['attempt_number']; ?> •
                <?php echo date('M d, Y g:i A', strtotime($attempt['start_time'])); ?>
            </div>

            <!-- Score Display -->
            <div class="score-display">
                <div class="score-circle <?php echo getGradeColor($attempt['percentage']); ?>">
                    <div class="score-value"><?php echo round($attempt['percentage'], 1); ?>%</div>
                    <div class="score-label">Score</div>
                </div>

                <div>
                    <div style="font-size: 2rem; font-weight: 700; color: var(--dark);">
                        <?php echo $attempt['total_score']; ?> / <?php echo $attempt['total_points']; ?>
                    </div>
                    <div style="color: var(--gray); margin-top: 0.5rem;">
                        Points Earned
                    </div>
                    <div style="margin-top: 1rem;">
                        <span class="status-badge <?php echo getGradeColor($attempt['percentage']); ?>">
                            Grade: <?php echo getGradeLetter($attempt['percentage']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo round($attempt['time_taken'] / 60, 1); ?> min</div>
                    <div class="stat-label">Time Taken</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo count($answers); ?></div>
                    <div class="stat-label">Questions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php
                        $correct = count(array_filter($answers, fn($a) => $a['is_correct']));
                        echo $correct . '/' . count($answers);
                        ?>
                    </div>
                    <div class="stat-label">Correct</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php echo round($attempt['percentage'] - $stats['average_score'], 1); ?>%
                    </div>
                    <div class="stat-label">vs Class Avg</div>
                </div>
            </div>

            <!-- Performance Chart -->
            <div class="performance-chart">
                <h3>Performance Comparison</h3>
                <div class="comparison-bar">
                    <div class="bar-label">Your Score</div>
                    <div class="bar-container">
                        <div class="bar-fill"
                            style="width: <?php echo min($attempt['percentage'], 100); ?>%; background: var(--primary);">
                            <div class="bar-value"><?php echo round($attempt['percentage'], 1); ?>%</div>
                        </div>
                    </div>
                </div>
                <div class="comparison-bar">
                    <div class="bar-label">Class Average</div>
                    <div class="bar-container">
                        <div class="bar-fill"
                            style="width: <?php echo min($stats['average_score'], 100); ?>%; background: var(--info);">
                            <div class="bar-value"><?php echo round($stats['average_score'], 1); ?>%</div>
                        </div>
                    </div>
                </div>
                <div class="comparison-bar">
                    <div class="bar-label">Highest Score</div>
                    <div class="bar-container">
                        <div class="bar-fill"
                            style="width: <?php echo min($stats['highest_score'], 100); ?>%; background: var(--success);">
                            <div class="bar-value"><?php echo round($stats['highest_score'], 1); ?>%</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Question Review -->
        <div class="question-review">
            <h2>Question Review</h2>
            <p style="color: var(--gray); margin-bottom: 1.5rem;">
                Review your answers and see explanations
            </p>

            <?php foreach ($answers as $index => $answer): ?>
                <?php
                $question_options = $options[$answer['question_id']] ?? [];
                // CORRECTED: Using $answer['id'] which is the primary key from quiz_answers table
                $selected = $selected_options[$answer['id']] ?? [];

                $card_class = '';
                if ($answer['is_correct']) {
                    $card_class = 'correct';
                } elseif ($answer['points_awarded'] > 0) {
                    $card_class = 'partial';
                } else {
                    $card_class = 'incorrect';
                }
                ?>

                <div class="question-card <?php echo $card_class; ?>">
                    <div class="question-header">
                        <div style="flex: 1;">
                            <h3>Question <?php echo $index + 1; ?></h3>
                            <div style="font-size: 0.875rem; color: var(--gray); margin-top: 0.25rem;">
                                <?php echo ucfirst(str_replace('_', ' ', $answer['question_type'])); ?>
                            </div>
                        </div>
                        <div class="question-points <?php echo $card_class; ?>">
                            <?php echo $answer['points_awarded']; ?> / <?php echo $answer['question_points']; ?> pts
                        </div>
                    </div>

                    <div class="question-text">
                        <?php echo nl2br(htmlspecialchars($answer['question_text'])); ?>
                    </div>

                    <!-- Answer Feedback -->
                    <div class="answer-feedback">
                        <?php if ($answer['is_correct']): ?>
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            <span class="feedback-correct">Correct Answer</span>
                        <?php elseif ($answer['points_awarded'] > 0): ?>
                            <i class="fas fa-check-circle" style="color: #f59e0b;"></i>
                            <span style="color: #f59e0b;">Partial Credit</span>
                        <?php else: ?>
                            <i class="fas fa-times-circle" style="color: #ef4444;"></i>
                            <span class="feedback-incorrect">Incorrect Answer</span>
                        <?php endif; ?>
                    </div>

                    <!-- Options Review -->
                    <?php if (!empty($question_options)): ?>
                        <div class="options-review">
                            <h4>Options:</h4>
                            <?php foreach ($question_options as $option): ?>
                                <?php
                                $is_correct = $option['is_correct'] == 1;
                                // CORRECTED: Check against $answer['id'] array
                                $is_selected = in_array($option['id'], $selected);
                                $option_class = '';

                                if ($is_correct && $is_selected) {
                                    $option_class = 'selected correct';
                                } elseif ($is_correct && !$is_selected) {
                                    $option_class = 'correct';
                                } elseif (!$is_correct && $is_selected) {
                                    $option_class = 'selected incorrect';
                                }
                                ?>

                                <div class="option-item <?php echo $option_class; ?>">
                                    <div style="display: flex; align-items: flex-start; gap: 1rem;">
                                        <?php if (in_array($answer['question_type'], ['multiple_choice', 'true_false', 'dropdown'])): ?>
                                            <div style="width: 20px; height: 20px; border: 2px solid #cbd5e1; 
                                                      border-radius: <?php echo $answer['question_type'] === 'multiple_select' ? '4px' : '50%'; ?>; 
                                                      margin-top: 2px; 
                                                      <?php echo $is_selected ? 'background: var(--primary); border-color: var(--primary);' : ''; ?>
                                                      <?php echo $is_correct ? 'background: #10b981; border-color: #10b981;' : ''; ?>">
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <?php echo htmlspecialchars($option['option_text']); ?>

                                            <?php if ($is_correct): ?>
                                                <span style="color: #10b981; margin-left: 0.5rem;">
                                                    <i class="fas fa-check"></i> Correct Answer
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($is_selected && !$is_correct): ?>
                                                <span style="color: #ef4444; margin-left: 0.5rem;">
                                                    <i class="fas fa-times"></i> Your Answer
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- User's Answer for non-multiple choice -->
                    <?php if (!in_array($answer['question_type'], ['multiple_choice', 'multiple_select', 'true_false', 'dropdown'])): ?>
                        <div class="options-review">
                            <h4>Your Answer:</h4>
                            <div class="option-item selected">
                                <div style="white-space: pre-wrap;"><?php echo htmlspecialchars($answer['answer_text']); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Navigation Buttons -->
        <div style="display: flex; justify-content: space-between; margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #f1f5f9;">
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Quizzes
            </a>

            <div style="display: flex; gap: 1rem;">
                <?php
                // Check if there are more attempts allowed
                $attempts_used = $attempt['attempt_number'];
                $attempts_allowed = 3; // This should come from quiz settings

                if ($attempts_used < $attempts_allowed): ?>
                    <a href="take_quiz.php?quiz_id=<?php echo $attempt['quiz_id']; ?>"
                        class="btn btn-primary">
                        <i class="fas fa-redo"></i> Retake Quiz
                    </a>
                <?php endif; ?>

                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-success">
                    <i class="fas fa-chart-line"></i> View All Grades
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animate progress bars
            document.querySelectorAll('.bar-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });

            // Add print functionality
            const printBtn = document.createElement('button');
            printBtn.className = 'btn btn-info';
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print Results';
            printBtn.style.marginLeft = '1rem';
            printBtn.onclick = () => window.print();

            document.querySelector('.question-review').previousElementSibling.appendChild(printBtn);

            // Highlight correct/incorrect answers on hover
            document.querySelectorAll('.option-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    if (this.classList.contains('correct')) {
                        this.style.transform = 'scale(1.02)';
                    }
                });
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+P to print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }

                // Arrow keys for navigation between questions
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    window.scrollBy({
                        top: 100,
                        behavior: 'smooth'
                    });
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    window.scrollBy({
                        top: -100,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>
</body>

</html>