<?php
// modules/student/quizzes/take_quiz.php

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
$quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get quiz details and check if student is enrolled
$sql = "SELECT q.*, cb.batch_code, c.title as course_title,
               e.id as enrollment_id,
               COUNT(DISTINCT a.id) as previous_attempts
        FROM quizzes q 
        JOIN class_batches cb ON q.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN enrollments e ON e.class_id = cb.id AND e.student_id = ?
        LEFT JOIN quiz_attempts a ON a.quiz_id = q.id AND a.student_id = ? 
                               AND a.status IN ('submitted', 'graded')
        WHERE q.id = ? AND q.is_published = 1
        GROUP BY q.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $student_id, $student_id, $quiz_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$quiz = $result->fetch_assoc();
$stmt->close();

// Check if quiz is available
$now = date('Y-m-d H:i:s');
if ($quiz['available_from'] && $now < $quiz['available_from']) {
    die("This quiz is not available yet. Available from: " . $quiz['available_from']);
}

if ($quiz['available_to'] && $now > $quiz['available_to']) {
    die("This quiz is no longer available. It was available until: " . $quiz['available_to']);
}

// Check attempts limit
if ($quiz['attempts_allowed'] > 0 && $quiz['previous_attempts'] >= $quiz['attempts_allowed']) {
    die("You have reached the maximum number of attempts ({$quiz['attempts_allowed']}) for this quiz.");
}

// Start or continue attempt
$attempt_id = 0;
$attempt_status = 'new';

// Check for existing in-progress attempt
$sql = "SELECT id, start_time, status FROM quiz_attempts 
        WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress' 
        ORDER BY start_time DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $quiz_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $existing_attempt = $result->fetch_assoc();
    $attempt_id = $existing_attempt['id'];
    $attempt_status = 'continue';
} else {
    // Create new attempt
    $sql = "INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, start_time, status, ip_address, user_agent) 
            SELECT ?, ?, COALESCE(MAX(attempt_number), 0) + 1, NOW(), 'in_progress', ?, ?
            FROM quiz_attempts 
            WHERE quiz_id = ? AND student_id = ?";
    $stmt = $conn->prepare($sql);
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'];
    $stmt->bind_param("iiissi", $quiz_id, $student_id, $ip, $agent, $quiz_id, $student_id);

    if ($stmt->execute()) {
        $attempt_id = $stmt->insert_id;
    }
    $stmt->close();
}

// Get total questions count first
$total_count_sql = "SELECT COUNT(*) as total FROM quiz_questions WHERE quiz_id = ?";
$stmt = $conn->prepare($total_count_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$total_count_result = $stmt->get_result();
$total_questions_count = $total_count_result->fetch_assoc()['total'] ?? 0;
$stmt->close();

// Determine how many questions to show
$questions_to_show = $total_questions_count; // Default to all

if ($quiz['question_selection_method'] === 'random_count' && $quiz['questions_to_show'] > 0) {
    $questions_to_show = min($quiz['questions_to_show'], $total_questions_count);
} elseif ($quiz['question_selection_method'] === 'random_percentage' && $quiz['questions_percentage'] > 0) {
    $questions_to_show = round($total_questions_count * ($quiz['questions_percentage'] / 100));
    $questions_to_show = max(1, min($questions_to_show, $total_questions_count));
}

// Get questions for this quiz based on selection settings
if ($questions_to_show >= $total_questions_count) {
    // Show all questions
    $order_clause = $quiz['shuffle_questions'] ? "ORDER BY RAND()" : "ORDER BY qq.order_number, qq.id";
    $questions_sql = "SELECT qq.* 
                     FROM quiz_questions qq 
                     WHERE qq.quiz_id = ?
                     $order_clause";
    $stmt = $conn->prepare($questions_sql);
    $stmt->bind_param("i", $quiz_id);
} else {
    // Show subset of questions
    if ($quiz['randomize_per_student']) {
        // Different random set for each student
        $order_clause = $quiz['shuffle_questions'] ? "ORDER BY RAND()" : "ORDER BY RAND()"; // Always random for subset
        $questions_sql = "SELECT qq.* 
                         FROM quiz_questions qq 
                         WHERE qq.quiz_id = ?
                         $order_clause
                         LIMIT ?";
        $stmt = $conn->prepare($questions_sql);
        $stmt->bind_param("ii", $quiz_id, $questions_to_show);
    } else {
        // Consistent random set for all students
        // Use a seed based on quiz_id and attempt number to ensure consistency
        $seed = $quiz_id . '_' . $quiz['previous_attempts'];
        $questions_sql = "SELECT qq.* 
                         FROM quiz_questions qq 
                         WHERE qq.quiz_id = ?
                         ORDER BY (RAND(?)) 
                         LIMIT ?";
        $stmt = $conn->prepare($questions_sql);
        $stmt->bind_param("iii", $quiz_id, $seed, $questions_to_show);
    }
}

$stmt->execute();
$questions_result = $stmt->get_result();
$questions = $questions_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If no questions found
if (empty($questions)) {
    die("No questions available for this quiz. Please contact your instructor.");
}

// Get options for questions (shuffled if needed)
$question_ids = array_column($questions, 'id');
$options = [];

if (!empty($question_ids)) {
    $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
    $order_clause = $quiz['shuffle_options'] ? "ORDER BY RAND()" : "ORDER BY qo.order_number";

    $options_sql = "SELECT qo.* FROM quiz_options qo 
                   WHERE qo.question_id IN ($placeholders) 
                   $order_clause";
    $stmt = $conn->prepare($options_sql);

    // Dynamically bind parameters
    $types = str_repeat('i', count($question_ids));
    $stmt->bind_param($types, ...$question_ids);
    $stmt->execute();
    $options_result = $stmt->get_result();

    while ($option = $options_result->fetch_assoc()) {
        $options[$option['question_id']][] = $option;
    }
    $stmt->close();
}

// Get previous answers if continuing an attempt
$previous_answers = [];
if ($attempt_status === 'continue') {
    $answers_sql = "SELECT qa.question_id, qa.answer_text, qa.answer_file 
                   FROM quiz_answers qa 
                   WHERE qa.attempt_id = ?";
    $stmt = $conn->prepare($answers_sql);
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $answers_result = $stmt->get_result();

    while ($answer = $answers_result->fetch_assoc()) {
        $previous_answers[$answer['question_id']] = $answer;
    }
    $stmt->close();
}

// Get flagged questions if continuing
$flagged_questions = [];
if ($attempt_status === 'continue') {
    $flagged_sql = "SELECT question_id FROM quiz_flagged_questions 
                   WHERE attempt_id = ?";
    $stmt = $conn->prepare($flagged_sql);
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $flagged_result = $stmt->get_result();

    while ($flagged = $flagged_result->fetch_assoc()) {
        $flagged_questions[] = $flagged['question_id'];
    }
    $stmt->close();
}

$conn->close();

// Initialize session for quiz tracking if new attempt
if ($attempt_status === 'new') {
    $_SESSION['quiz_attempt'] = [
        'answers' => [],
        'flagged' => [],
        'current_question' => 1
    ];
} else {
    // Load previous session data
    $_SESSION['quiz_attempt'] = [
        'answers' => $previous_answers,
        'flagged' => $flagged_questions,
        'current_question' => 1
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz: <?php echo htmlspecialchars($quiz['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f5f5f5;
            min-height: 100vh;
        }

        .quiz-header {
            background: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .quiz-title {
            color: #4f46e5;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .quiz-title small {
            display: block;
            font-size: 0.9rem;
            color: #666;
            font-weight: normal;
            margin-top: 5px;
        }

        .quiz-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-left: 10px;
            font-weight: normal;
        }

        .timer-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .timer {
            background: #4f46e5;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 1.5rem;
            font-weight: bold;
            min-width: 120px;
            text-align: center;
        }

        .timer.warning {
            background: #ff9800;
            animation: pulse 1s infinite;
        }

        .timer.critical {
            background: #ef4444;
            animation: pulse 0.5s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .progress-bar {
            width: 200px;
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #818cf8);
            transition: width 0.3s;
        }

        .container {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        .sidebar {
            width: 300px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 20px;
            overflow-y: auto;
        }

        .question-nav {
            margin-bottom: 30px;
        }

        .question-nav h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .question-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
        }

        .question-btn {
            width: 40px;
            height: 40px;
            border: 2px solid #ddd;
            border-radius: 5px;
            background: white;
            color: #333;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .question-btn:hover {
            border-color: #4f46e5;
            background: #eef2ff;
        }

        .question-btn.current {
            border-color: #4f46e5;
            background: #eef2ff;
            color: #4f46e5;
        }

        .question-btn.answered {
            border-color: #10b981;
            background: #d1fae5;
            color: #047857;
        }

        .question-btn.flagged {
            border-color: #f59e0b;
            background: #fef3c7;
            color: #d97706;
        }

        .question-btn.flagged::after {
            content: '!';
            position: absolute;
            top: -5px;
            right: -5px;
            background: #f59e0b;
            color: white;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quiz-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .quiz-info h4 {
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .info-label {
            color: #666;
        }

        .info-value {
            font-weight: 600;
            color: #333;
        }

        .info-note {
            margin-top: 10px;
            padding: 10px;
            background: #e0f2fe;
            border-radius: 5px;
            font-size: 0.85rem;
            color: #0369a1;
        }

        .instructions-sidebar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .instructions-sidebar h4 {
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .instructions-sidebar ul {
            padding-left: 20px;
            color: #666;
        }

        .instructions-sidebar li {
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .question-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .question-number {
            font-size: 1.2rem;
            color: #666;
        }

        .question-type {
            background: #e0e0e0;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .question-text {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 30px;
            color: #333;
        }

        .options-container {
            margin: 30px 0;
        }

        .option {
            display: block;
            margin-bottom: 15px;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .option:hover {
            border-color: #4f46e5;
            background: #eef2ff;
        }

        .option.selected {
            border-color: #10b981;
            background: #d1fae5;
        }

        .option-label {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #666;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-right: 15px;
            font-weight: bold;
        }

        .option.selected .option-label {
            background: #10b981;
        }

        .option-text {
            display: inline;
            font-size: 1.1rem;
        }

        .quiz-controls {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .control-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .prev-btn {
            background: #f5f5f5;
            color: #666;
        }

        .prev-btn:hover {
            background: #e0e0e0;
        }

        .next-btn {
            background: #4f46e5;
            color: white;
        }

        .next-btn:hover {
            background: #4338ca;
        }

        .flag-btn {
            background: #fef3c7;
            color: #d97706;
        }

        .flag-btn:hover {
            background: #fde68a;
        }

        .flag-btn.flagged {
            background: #f59e0b;
            color: white;
        }

        .submit-btn {
            background: #ef4444;
            color: white;
            padding: 15px 40px;
            font-size: 1.1rem;
        }

        .submit-btn:hover {
            background: #dc2626;
        }

        .quiz-footer {
            background: white;
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            position: sticky;
            bottom: 0;
            z-index: 100;
        }

        .essay-textarea {
            width: 100%;
            min-height: 200px;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            line-height: 1.6;
            resize: vertical;
            transition: border-color 0.2s;
        }

        .essay-textarea:focus {
            outline: none;
            border-color: #4f46e5;
        }

        .start-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.95);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            backdrop-filter: blur(10px);
        }

        .start-box {
            max-width: 600px;
            padding: 2.5rem;
            background: #1e293b;
            border-radius: 20px;
            border: 1px solid #334155;
            text-align: center;
        }

        .warning-icon {
            font-size: 4rem;
            color: #fbbf24;
            margin-bottom: 1.5rem;
        }

        .btn-start {
            background: #4f46e5;
            color: white;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 2rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-start:hover {
            background: #4338ca;
            transform: translateY(-2px);
        }

        .selection-info {
            background: #334155;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }

            .question-grid {
                grid-template-columns: repeat(10, 1fr);
            }
        }

        @media (max-width: 768px) {
            .quiz-header {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }

            .timer-container {
                width: 100%;
                justify-content: space-between;
            }

            .question-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .main-content {
                padding: 20px;
            }

            .question-container {
                padding: 20px;
            }

            .quiz-controls {
                flex-direction: column;
                gap: 10px;
            }

            .control-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <?php if (!isset($_SESSION['quiz_started']) || !$_SESSION['quiz_started']): ?>
        <div class="start-overlay">
            <div class="start-box">
                <i class="fas fa-exclamation-triangle warning-icon"></i>
                <h2>Ready to Start Quiz?</h2>
                <p style="color: #94a3b8; margin: 1.5rem 0; line-height: 1.6;">
                    You are about to start: <strong><?php echo htmlspecialchars($quiz['title']); ?></strong>
                </p>

                <div style="background: #334155; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: left;">
                    <div class="info-item">
                        <span class="info-label">Total Questions in Bank:</span>
                        <span class="info-value"><?php echo $total_questions_count; ?></span>
                    </div>
                    <?php if ($questions_to_show < $total_questions_count): ?>
                        <div class="info-item">
                            <span class="info-label">Questions You'll See:</span>
                            <span class="info-value"><?php echo $questions_to_show; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($quiz['time_limit'] > 0): ?>
                        <div class="info-item">
                            <span class="info-label">Time Limit:</span>
                            <span class="info-value"><?php echo $quiz['time_limit']; ?> minutes</span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Course:</span>
                        <span class="info-value"><?php echo htmlspecialchars($quiz['course_title']); ?></span>
                    </div>
                    <?php if ($quiz['attempts_allowed'] > 0): ?>
                        <div class="info-item">
                            <span class="info-label">Attempts:</span>
                            <span class="info-value"><?php echo $quiz['previous_attempts'] + 1; ?> of <?php echo $quiz['attempts_allowed']; ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($questions_to_show < $total_questions_count): ?>
                    <div class="selection-info">
                        <p><i class="fas fa-random" style="color: #fbbf24;"></i> <strong>Random Selection Active:</strong></p>
                        <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 5px;">
                            <?php if ($quiz['randomize_per_student']): ?>
                                Each student gets a different random set of <?php echo $questions_to_show; ?> questions from the question bank.
                            <?php else: ?>
                                All students will see the same random set of <?php echo $questions_to_show; ?> questions.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div style="text-align: left; margin: 20px 0;">
                    <h4 style="color: #fbbf24; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Important Instructions:</h4>
                    <ul style="color: #94a3b8; padding-left: 20px;">
                        <?php if ($quiz['time_limit'] > 0): ?>
                            <li>The quiz is timed. Timer starts when you click "Start Quiz".</li>
                        <?php endif; ?>
                        <li>Answer all questions before submitting.</li>
                        <li>You can flag questions to review later.</li>
                        <li>Use the question navigation to move between questions.</li>
                        <?php if ($quiz['shuffle_questions'] && $questions_to_show < $total_questions_count): ?>
                            <li>The <?php echo $questions_to_show; ?> questions shown are randomly selected from the question bank.</li>
                        <?php elseif ($quiz['shuffle_questions']): ?>
                            <li>Questions are shuffled for each attempt.</li>
                        <?php endif; ?>
                        <?php if ($quiz['shuffle_options']): ?>
                            <li>Answer options are shuffled for each question.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <button class="btn-start" onclick="startQuiz()">
                    Start Quiz <i class="fas fa-play-circle"></i>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div id="quiz-content" style="<?php echo (!isset($_SESSION['quiz_started']) || !$_SESSION['quiz_started']) ? 'display: none;' : ''; ?>">
        <div class="quiz-header">
            <div class="quiz-title">
                <?php echo htmlspecialchars($quiz['title']); ?>
                <small><?php echo htmlspecialchars($quiz['course_title']); ?></small>
                <?php if ($questions_to_show < $total_questions_count): ?>
                    <span class="quiz-badge">
                        <i class="fas fa-random"></i> <?php echo $questions_to_show; ?>/<?php echo $total_questions_count; ?> random questions
                    </span>
                <?php endif; ?>
            </div>

            <div class="timer-container">
                <?php if ($quiz['time_limit'] > 0): ?>
                    <div class="timer" id="timerDisplay">
                        00:00
                    </div>
                <?php endif; ?>

                <div class="progress-container">
                    <span style="font-size: 0.9rem; color: #666;">Progress:</span>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <span style="font-weight: bold; color: #333;" id="progressText">0%</span>
                </div>
            </div>
        </div>

        <div class="container">
            <div class="sidebar">
                <div class="question-nav">
                    <h3><i class="fas fa-list-ol"></i> Question Navigation</h3>
                    <div class="question-grid" id="questionGrid">
                        <?php foreach ($questions as $index => $question): ?>
                            <button class="question-btn 
                            <?php if ($index == 0) echo 'current'; ?>
                            <?php if (isset($_SESSION['quiz_attempt']['answers'][$question['id']])) echo 'answered'; ?>
                            <?php if (in_array($question['id'], $_SESSION['quiz_attempt']['flagged'])) echo 'flagged'; ?>"
                                onclick="goToQuestion(<?php echo $index + 1; ?>)"
                                data-question-id="<?php echo $question['id']; ?>">
                                <?php echo $index + 1; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="quiz-info">
                    <h4><i class="fas fa-info-circle"></i> Quiz Information</h4>
                    <div class="info-item">
                        <span class="info-label">Questions in Quiz:</span>
                        <span class="info-value"><?php echo count($questions); ?></span>
                    </div>
                    <?php if ($total_questions_count > count($questions)): ?>
                        <div class="info-item">
                            <span class="info-label">Question Bank:</span>
                            <span class="info-value"><?php echo $total_questions_count; ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Answered:</span>
                        <span class="info-value" id="answeredCount">0</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Flagged:</span>
                        <span class="info-value" id="flaggedCount">0</span>
                    </div>
                    <?php if ($quiz['time_limit'] > 0): ?>
                        <div class="info-item">
                            <span class="info-label">Time Left:</span>
                            <span class="info-value" id="timeLeft"><?php echo $quiz['time_limit']; ?>:00</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($total_questions_count > count($questions)): ?>
                        <div class="info-note">
                            <i class="fas fa-random"></i>
                            <strong>Random Selection:</strong> You're seeing <?php echo count($questions); ?> randomly selected questions.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="instructions-sidebar">
                    <h4><i class="fas fa-lightbulb"></i> Quick Tips</h4>
                    <ul>
                        <li>Click on question numbers to navigate</li>
                        <li>Flag questions you want to review later</li>
                        <li>Answers are saved automatically</li>
                        <li>Review all questions before submitting</li>
                        <?php if ($quiz['time_limit'] > 0): ?>
                            <li>Keep an eye on the timer</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="main-content">
                <form id="quizForm" method="POST" action="submit_quiz.php" enctype="multipart/form-data">
                    <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                    <input type="hidden" name="time_taken" id="timeTaken" value="0">

                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-container" id="question-<?php echo $index + 1; ?>" style="<?php echo $index > 0 ? 'display: none;' : ''; ?>">
                            <div class="question-header">
                                <div class="question-number">
                                    Question <?php echo $index + 1; ?> of <?php echo count($questions); ?>
                                    <div style="font-size: 0.9rem; color: #666; margin-top: 5px;">
                                        Points: <?php echo $question['points']; ?>
                                    </div>
                                </div>
                                <div class="question-type">
                                    <?php echo strtoupper(str_replace('_', ' ', $question['question_type'])); ?>
                                </div>
                            </div>

                            <div class="question-text">
                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                            </div>

                            <div class="options-container">
                                <?php
                                switch ($question['question_type']):
                                    case 'multiple_choice':
                                    case 'true_false':
                                        $current_options = ($question['question_type'] == 'true_false')
                                            ? [['id' => 'true', 'option_text' => 'True'], ['id' => 'false', 'option_text' => 'False']]
                                            : ($options[$question['id']] ?? []);
                                        foreach ($current_options as $opt):
                                            $option_id = $opt['id'] ?? $opt;
                                            $option_text = $opt['option_text'] ?? $opt;
                                ?>
                                            <label class="option <?php
                                                                    if (
                                                                        isset($_SESSION['quiz_attempt']['answers'][$question['id']]) &&
                                                                        $_SESSION['quiz_attempt']['answers'][$question['id']] == $option_id
                                                                    ) echo 'selected';
                                                                    ?>" onclick="selectAnswer(this, <?php echo $question['id']; ?>, '<?php echo $option_id; ?>')">
                                                <input type="radio"
                                                    name="answers[<?php echo $question['id']; ?>]"
                                                    value="<?php echo $option_id; ?>"
                                                    style="display:none;"
                                                    <?php
                                                    if (
                                                        isset($_SESSION['quiz_attempt']['answers'][$question['id']]) &&
                                                        $_SESSION['quiz_attempt']['answers'][$question['id']] == $option_id
                                                    ) echo 'checked';
                                                    ?>>
                                                <span class="option-label"><?php echo chr(65 + $index); ?></span>
                                                <span class="option-text"><?php echo htmlspecialchars($option_text); ?></span>
                                            </label>
                                        <?php endforeach;
                                        break;
                                    case 'essay': ?>
                                        <textarea name="answers[<?php echo $question['id']; ?>]"
                                            class="essay-textarea"
                                            oninput="saveEssayAnswer(<?php echo $question['id']; ?>, this.value)"
                                            placeholder="Type your answer here..."><?php
                                                                                    echo isset($_SESSION['quiz_attempt']['answers'][$question['id']])
                                                                                        ? htmlspecialchars($_SESSION['quiz_attempt']['answers'][$question['id']])
                                                                                        : '';
                                                                                    ?></textarea>
                                <?php break;
                                endswitch; ?>
                            </div>

                            <div class="quiz-controls">
                                <div>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="control-btn prev-btn" onclick="goToQuestion(<?php echo $index; ?>)">
                                            <i class="fas fa-arrow-left"></i> Previous
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="button" class="control-btn flag-btn <?php
                                                                                        if (in_array($question['id'], $_SESSION['quiz_attempt']['flagged'])) echo 'flagged';
                                                                                        ?>" onclick="toggleFlag(<?php echo $question['id']; ?>)">
                                        <i class="fas fa-flag"></i>
                                        <?php echo in_array($question['id'], $_SESSION['quiz_attempt']['flagged']) ? 'Unflag' : 'Flag'; ?>
                                    </button>

                                    <?php if ($index < count($questions) - 1): ?>
                                        <button type="button" class="control-btn next-btn" onclick="goToQuestion(<?php echo $index + 2; ?>)">
                                            Next <i class="fas fa-arrow-right"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="control-btn submit-btn" onclick="submitQuiz()">
                                            <i class="fas fa-paper-plane"></i> Submit Quiz
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>

                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 3px 15px rgba(0,0,0,0.1);">
                    <h3 style="color: #333; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-chart-bar"></i> Quiz Statistics
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #4f46e5;" id="statAnswered">
                                0
                            </div>
                            <div style="font-size: 0.9rem; color: #666;">Answered</div>
                        </div>

                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #f59e0b;" id="statFlagged">
                                0
                            </div>
                            <div style="font-size: 0.9rem; color: #666;">Flagged</div>
                        </div>

                        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #6b7280;" id="statRemaining">
                                <?php echo count($questions); ?>
                            </div>
                            <div style="font-size: 0.9rem; color: #666;">Remaining</div>
                        </div>

                        <?php if ($quiz['time_limit'] > 0): ?>
                            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: bold; color: #10b981;" id="statTime">
                                    <?php echo sprintf('%02d', $quiz['time_limit']); ?>:00
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">Time Left</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="quiz-footer">
            <button type="button" class="control-btn submit-btn" onclick="submitQuiz()" style="padding: 12px 30px;">
                <i class="fas fa-stop-circle"></i> Submit Quiz Now
            </button>
            <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                You can submit early or wait for the timer to expire. Once submitted, you cannot return to the quiz.
            </p>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentQuestion = 1;
        const totalQuestions = <?php echo count($questions); ?>;
        let timeLimit = <?php echo $quiz['time_limit'] * 60; ?>;
        let timeSpent = 0;
        let quizStarted = false;
        let timeInterval;

        function startQuiz() {
            // Start the quiz session
            fetch('start_quiz_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'quiz_id=<?php echo $quiz_id; ?>&attempt_id=<?php echo $attempt_id; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Hide start overlay and show quiz
                        document.querySelector('.start-overlay').style.display = 'none';
                        document.getElementById('quiz-content').style.display = 'block';
                        quizStarted = true;

                        // Start timer if time limit exists
                        if (timeLimit > 0) {
                            startTimer();
                        }

                        // Update statistics
                        updateStatistics();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to start quiz. Please try again.');
                });
        }

        function startTimer() {
            timeInterval = setInterval(() => {
                timeSpent++;
                document.getElementById('timeTaken').value = timeSpent;

                if (timeLimit > 0) {
                    let remaining = timeLimit - timeSpent;

                    if (remaining <= 0) {
                        clearInterval(timeInterval);
                        alert("Time is up!");
                        submitQuiz();
                        return;
                    }

                    let m = Math.floor(remaining / 60);
                    let s = remaining % 60;

                    // Update timer display
                    const timerDisplay = document.getElementById('timerDisplay');
                    timerDisplay.textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                    document.getElementById('statTime').textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                    document.getElementById('timeLeft').textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;

                    // Update timer styling
                    if (m < 10) {
                        timerDisplay.className = 'timer warning';
                    }
                    if (m < 5) {
                        timerDisplay.className = 'timer critical';
                    }
                }
            }, 1000);
        }

        function goToQuestion(questionNum) {
            if (questionNum >= 1 && questionNum <= totalQuestions) {
                // Hide current question
                document.querySelector(`#question-${currentQuestion}`).style.display = 'none';

                // Remove current class from previous question button
                document.querySelector(`.question-btn.current`).classList.remove('current');

                // Show new question
                document.querySelector(`#question-${questionNum}`).style.display = 'block';

                // Add current class to new question button
                document.querySelector(`.question-btn[onclick="goToQuestion(${questionNum})"]`).classList.add('current');

                currentQuestion = questionNum;

                // Update control buttons
                updateControlButtons();
            }
        }

        function updateControlButtons() {
            const prevBtn = document.querySelector('.prev-btn');
            const nextBtn = document.querySelector('.next-btn');

            if (prevBtn) {
                prevBtn.style.display = currentQuestion > 1 ? 'flex' : 'none';
            }

            if (nextBtn) {
                if (currentQuestion < totalQuestions) {
                    nextBtn.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
                    nextBtn.onclick = function() {
                        goToQuestion(currentQuestion + 1);
                    };
                    nextBtn.className = 'control-btn next-btn';
                } else {
                    nextBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Quiz';
                    nextBtn.onclick = submitQuiz;
                    nextBtn.className = 'control-btn submit-btn';
                }
            }
        }

        function selectAnswer(element, questionId, answer) {
            // Remove selected class from all options in this question
            const options = element.closest('.options-container').querySelectorAll('.option');
            options.forEach(opt => opt.classList.remove('selected'));

            // Add selected class to clicked option
            element.classList.add('selected');

            // Check the radio button
            const radio = element.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }

            // Save answer
            saveAnswer(questionId, answer);
        }

        function saveAnswer(questionId, answer) {
            // Save to session via AJAX
            fetch('save_answer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `attempt_id=<?php echo $attempt_id; ?>&question_id=${questionId}&answer=${encodeURIComponent(answer)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update question button
                        const questionBtn = document.querySelector(`.question-btn[data-question-id="${questionId}"]`);
                        if (questionBtn) {
                            questionBtn.classList.add('answered');
                        }

                        // Update session
                        if (!<?php echo json_encode($_SESSION['quiz_attempt']['answers']); ?>) {
                            <?php $_SESSION['quiz_attempt']['answers'][$question['id']] = $answer; ?>
                        }

                        // Update statistics
                        updateStatistics();
                    }
                });
        }

        function saveEssayAnswer(questionId, answer) {
            // Save essay answer via AJAX
            fetch('save_answer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `attempt_id=<?php echo $attempt_id; ?>&question_id=${questionId}&answer=${encodeURIComponent(answer)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update question button
                        const questionBtn = document.querySelector(`.question-btn[data-question-id="${questionId}"]`);
                        if (questionBtn) {
                            if (answer.trim() !== '') {
                                questionBtn.classList.add('answered');
                            } else {
                                questionBtn.classList.remove('answered');
                            }
                        }

                        // Update statistics
                        updateStatistics();
                    }
                });
        }

        function toggleFlag(questionId) {
            fetch('flag_question.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `attempt_id=<?php echo $attempt_id; ?>&question_id=${questionId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update button appearance
                        const flagBtn = document.querySelector('.flag-btn');
                        const questionBtn = document.querySelector(`.question-btn[data-question-id="${questionId}"]`);

                        if (data.flagged) {
                            flagBtn.innerHTML = '<i class="fas fa-flag"></i> Unflag';
                            flagBtn.classList.add('flagged');
                            questionBtn.classList.add('flagged');
                        } else {
                            flagBtn.innerHTML = '<i class="fas fa-flag"></i> Flag';
                            flagBtn.classList.remove('flagged');
                            questionBtn.classList.remove('flagged');
                        }

                        // Update statistics
                        updateStatistics();
                    }
                });
        }

        function updateStatistics() {
            // Count answered questions
            const answeredCount = document.querySelectorAll('.question-btn.answered').length;
            const flaggedCount = document.querySelectorAll('.question-btn.flagged').length;
            const progress = Math.round((answeredCount / totalQuestions) * 100);

            // Update display
            document.getElementById('answeredCount').textContent = answeredCount;
            document.getElementById('flaggedCount').textContent = flaggedCount;
            document.getElementById('statAnswered').textContent = answeredCount;
            document.getElementById('statFlagged').textContent = flaggedCount;
            document.getElementById('statRemaining').textContent = totalQuestions - answeredCount;
            document.getElementById('progressFill').style.width = `${progress}%`;
            document.getElementById('progressText').textContent = `${progress}%`;
        }

        function submitQuiz() {
            if (confirm('Are you sure you want to submit the quiz?\n\nOnce submitted, you cannot return to the quiz.')) {
                clearInterval(timeInterval);
                document.getElementById('quizForm').submit();
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (!quizStarted) return;

            // Next question: Right arrow or Space
            if (e.key === 'ArrowRight' || e.key === ' ') {
                e.preventDefault();
                if (currentQuestion < totalQuestions) {
                    goToQuestion(currentQuestion + 1);
                }
            }

            // Previous question: Left arrow
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                if (currentQuestion > 1) {
                    goToQuestion(currentQuestion - 1);
                }
            }

            // Select answer: 1-4 keys
            if (e.key >= '1' && e.key <= '4') {
                e.preventDefault();
                const currentContainer = document.querySelector(`#question-${currentQuestion}`);
                const options = currentContainer.querySelectorAll('.option');
                const index = parseInt(e.key) - 1;
                if (options[index]) {
                    options[index].click();
                }
            }

            // Select answer: A-D keys
            if (e.key >= 'a' && e.key <= 'd') {
                e.preventDefault();
                const currentContainer = document.querySelector(`#question-${currentQuestion}`);
                const options = currentContainer.querySelectorAll('.option');
                const index = e.key.charCodeAt(0) - 97; // a=0, b=1, c=2, d=3
                if (options[index]) {
                    options[index].click();
                }
            }

            // Flag question: F key
            if (e.key === 'f' || e.key === 'F') {
                e.preventDefault();
                const currentContainer = document.querySelector(`#question-${currentQuestion}`);
                const radio = currentContainer.querySelector('input[type="radio"]');
                if (radio) {
                    const questionId = radio.name.match(/\[(\d+)\]/)[1];
                    toggleFlag(questionId);
                } else {
                    const textarea = currentContainer.querySelector('textarea');
                    if (textarea) {
                        const questionId = textarea.name.match(/\[(\d+)\]/)[1];
                        toggleFlag(questionId);
                    }
                }
            }

            // Submit quiz: Ctrl+Enter
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                submitQuiz();
            }
        });

        // Initialize
        updateStatistics();
        updateControlButtons();

        // Auto-save time every 30 seconds
        if (timeLimit > 0) {
            setInterval(() => {
                if (quizStarted) {
                    fetch('save_time.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `attempt_id=<?php echo $attempt_id; ?>&time_taken=${timeSpent}`
                    });
                }
            }, 30000);
        }
    </script>
</body>

</html>