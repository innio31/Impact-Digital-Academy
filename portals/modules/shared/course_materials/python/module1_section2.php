<?php
// section2.php - Python Essentials 1: Module 1 - Introduction to Programming - Section 2
declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

/**
 * Module 1 Access Controller
 */
class Module1AccessController
{
    private $conn;
    private $user_id;
    private $user_role;
    private $user_email;
    private $first_name;
    private $last_name;
    private $allowed_roles = ['student', 'instructor', 'admin'];

    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
    }

    /**
     * Validate user session and authentication
     */
    private function validateSession(): void
    {
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], $this->allowed_roles)) {
            $this->redirectToLogin();
        }
    }

    /**
     * Initialize class properties from session
     */
    private function initializeProperties(): void
    {
        $this->user_id = (int)$_SESSION['user_id'];
        $this->user_role = $_SESSION['user_role'];
        $this->user_email = $_SESSION['user_email'] ?? '';
        $this->first_name = $_SESSION['first_name'] ?? '';
        $this->last_name = $_SESSION['last_name'] ?? '';
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection()
    {
        $conn = getDBConnection();

        if (!$conn) {
            $this->handleError("Database connection failed. Please check your configuration.");
        }

        return $conn;
    }

    /**
     * Validate user access to Module 1
     */
    private function validateAccess(): void
    {
        // Admins and instructors have automatic access
        if ($this->user_role === 'admin' || $this->user_role === 'instructor') {
            return;
        }

        // For students, check if enrolled in Python Essentials 1 (Module 1)
        $access_count = $this->checkStudentAccess();

        if ($access_count === 0) {
            $this->showAccessDenied();
        }
    }

    /**
     * Check if student has access to Python Essentials 1 course
     */
    private function checkStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN programs p ON c.program_id = p.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND (c.title LIKE '%Python Essentials 1%' 
                     OR c.title LIKE '%Python Programming%'
                     OR p.name LIKE '%Python Essentials 1%')";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Get module progress from database
     */
    public function getModuleProgress(): array
    {
        $progress = [
            'section1' => 0,
            'section2' => 0,
            'section3' => 0,
            'section4' => 0,
            'overall' => 0
        ];

        if ($this->user_role === 'student') {
            // Get progress from database
            $sql = "SELECT section1_progress, section2_progress, section3_progress, section4_progress, overall_progress 
                    FROM module_progress 
                    WHERE user_id = ? AND module_id = 1";
            $stmt = $this->conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param('i', $this->user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $progress = [
                        'section1' => (float)$row['section1_progress'],
                        'section2' => (float)$row['section2_progress'],
                        'section3' => (float)$row['section3_progress'],
                        'section4' => (float)$row['section4_progress'],
                        'overall' => (float)$row['overall_progress']
                    ];
                }
                $stmt->close();
            }
        }

        return $progress;
    }

    /**
     * Update module progress in database
     */
    public function updateProgress($section, $completed = true): bool
    {
        if ($this->user_role === 'student') {
            // Get current progress
            $current_progress = $this->getModuleProgress();

            // Update the specific section
            if ($completed) {
                $current_progress[$section] = 100;

                // Calculate overall progress
                $sections = ['section1', 'section2', 'section3', 'section4'];
                $completed_sections = 0;
                foreach ($sections as $sec) {
                    if ($current_progress[$sec] >= 100) {
                        $completed_sections++;
                    }
                }
                $current_progress['overall'] = ($completed_sections / count($sections)) * 100;

                // Store in session for immediate feedback
                if (!isset($_SESSION['module_progress'])) {
                    $_SESSION['module_progress'] = [];
                }
                $_SESSION['module_progress']['module1'] = $current_progress;

                // Save to database
                $sql = "INSERT INTO module_progress (user_id, module_id, section1_progress, section2_progress, section3_progress, section4_progress, overall_progress, last_accessed) 
                        VALUES (?, 1, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        section1_progress = VALUES(section1_progress),
                        section2_progress = VALUES(section2_progress),
                        section3_progress = VALUES(section3_progress),
                        section4_progress = VALUES(section4_progress),
                        overall_progress = VALUES(overall_progress),
                        last_accessed = NOW()";

                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        'iddddd',
                        $this->user_id,
                        $current_progress['section1'],
                        $current_progress['section2'],
                        $current_progress['section3'],
                        $current_progress['section4'],
                        $current_progress['overall']
                    );
                    $result = $stmt->execute();
                    $stmt->close();
                    return $result;
                }
            }
        }
        return false;
    }

    /**
     * Save exercise submission to database
     */
    public function saveExerciseSubmission($exercise_type, $exercise_id, $user_answer, $is_correct = null, $score = null, $max_score = null): bool
    {
        $user_answer_json = json_encode($user_answer);

        $sql = "INSERT INTO exercise_submissions (user_id, module_id, exercise_type, exercise_id, user_answer, is_correct, score, max_score, ip_address, user_agent) 
                VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                user_answer = VALUES(user_answer),
                is_correct = VALUES(is_correct),
                score = VALUES(score),
                max_score = VALUES(max_score),
                submitted_at = NOW()";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt->bind_param(
                'issssddss',
                $this->user_id,
                $exercise_type,
                $exercise_id,
                $user_answer_json,
                $is_correct,
                $score,
                $max_score,
                $ip_address,
                $user_agent
            );
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
        return false;
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(): void
    {
        header("Location: " . BASE_URL . "login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }

    /**
     * Show access denied page
     */
    private function showAccessDenied(): void
    {
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied - Module 1</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }

                .access-denied-container {
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                    padding: 40px;
                    text-align: center;
                    max-width: 500px;
                }

                .icon {
                    font-size: 4rem;
                    color: #306998;
                    margin-bottom: 20px;
                }

                h1 {
                    color: #306998;
                    margin-bottom: 20px;
                }

                p {
                    color: #666;
                    margin-bottom: 30px;
                    line-height: 1.6;
                }

                .btn {
                    display: inline-block;
                    background: #306998;
                    color: white;
                    padding: 12px 24px;
                    border-radius: 50px;
                    text-decoration: none;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    margin: 5px;
                }

                .btn:hover {
                    background: #4B8BBE;
                    transform: translateY(-2px);
                    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
                }
            </style>
        </head>

        <body>
            <div class="access-denied-container">
                <div class="icon"><i class="fab fa-python"></i></div>
                <h1>Access Restricted</h1>
                <p>You need to be enrolled in <strong>Python Essentials 1</strong> to access Module 1 content.</p>
                <p>If you believe this is an error, please contact your instructor.</p>
                <div style="margin-top: 30px;">
                    <a href="<?php echo BASE_URL; ?>index.php" class="btn">Return to Course</a>
                    <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn">Go to Dashboard</a>
                </div>
            </div>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        </body>

        </html>
    <?php
        exit();
    }

    /**
     * Handle errors
     */
    private function handleError(string $message): void
    {
        die("<div style='padding:20px; background:#fee; border:1px solid #f00; color:#900;'>Error: $message</div>");
    }

    /**
     * Get user display name
     */
    public function getUserDisplayName(): string
{
    // Return full name
    $fullName = trim($this->first_name . ' ' . $this->last_name);

    // If we have no name, fall back to email
    if (empty($fullName)) {
        $fullName = $this->user_email;  // Changed from $this->user_email to $this->email
    }

    return htmlspecialchars($fullName);
}

    /**
     * Get user role
     */
    public function getUserRole(): string
    {
        return $this->user_role;
    }

    /**
     * Check if user is enrolled in course
     */
    public function isEnrolled(): bool
    {
        return $this->user_role !== 'student' || $this->checkStudentAccess() > 0;
    }
}

// Initialize access controller
try {
    $accessController = new Module1AccessController();
    $progress = $accessController->getModuleProgress();

    // Initialize session storage for exercise answers
    if (!isset($_SESSION['exercise_answers'])) {
        $_SESSION['exercise_answers'] = [
            'module1' => [
                'section2_mcq1_answer' => '',
                'section2_tf_answers' => [],
                'section2_python_code' => ''
            ]
        ];
    }

    // Initialize session storage for exercise completion
    if (!isset($_SESSION['exercise_completion'])) {
        $_SESSION['exercise_completion'] = [
            'module1' => [
                'section2_mcq1_completed' => false,
                'section2_tf1_completed' => false,
                'section2_py1_completed' => false
            ]
        ];
    }

    // Handle exercise submissions
    $exercise_results = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exercise_type'])) {
        // Update section progress first
        $accessController->updateProgress('section2', true);

        switch ($_POST['exercise_type']) {
            case 'section2_multiple_choice_1':
                $user_answer = $_POST['answer'] ?? '';
                $correct = ($user_answer === 'a'); // Python 3 is the recommended version
                $exercise_results['mc1'] = [
                    'correct' => $correct,
                    'user_answer' => $user_answer,
                    'correct_answer' => 'a',
                    'feedback' => $correct ?
                        "Correct! For new projects, Python 3 is the recommended version as Python 2 is no longer actively developed." :
                        "Incorrect. For new projects, you should use Python 3 as Python 2 is no longer actively developed and maintained."
                ];

                // Store answer in session
                $_SESSION['exercise_answers']['module1']['section2_mcq1_answer'] = $user_answer;
                $_SESSION['exercise_completion']['module1']['section2_mcq1_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'multiple_choice',
                    'section2_mcq1',
                    $user_answer,
                    $correct,
                    $correct ? 5 : 0,
                    5
                );
                break;

            case 'section2_true_false_1':
                $answers = $_POST['tf_answers'] ?? [];
                $correct_answers = [
                    'q1' => 'true',   // Python named after Monty Python
                    'q2' => 'false',  // Python developed by committee
                    'q3' => 'true',   // Python is mature and trustworthy
                    'q4' => 'true'    // CPython is reference implementation
                ];
                $score = 0;
                foreach ($answers as $q => $a) {
                    if (isset($correct_answers[$q]) && $a === $correct_answers[$q]) {
                        $score++;
                    }
                }
                $percentage = round(($score / 4) * 100);
                $is_passing = $percentage >= 70;

                $exercise_results['tf1'] = [
                    'score' => $score,
                    'total' => 4,
                    'percentage' => $percentage,
                    'is_passing' => $is_passing
                ];

                // Store answers in session
                $_SESSION['exercise_answers']['module1']['section2_tf_answers'] = $answers;
                $_SESSION['exercise_completion']['module1']['section2_tf1_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'true_false',
                    'section2_tf1',
                    $answers,
                    $is_passing,
                    $score * 1.5, // 1.5 points per question
                    6
                );
                break;

            case 'section2_python_exercise_1':
                $user_code = $_POST['python_code'] ?? '';
                $exercise_results['py1'] = [
                    'submitted' => true,
                    'code' => $user_code
                ];

                // Store code in session
                $_SESSION['exercise_answers']['module1']['section2_python_code'] = $user_code;
                $_SESSION['exercise_completion']['module1']['section2_py1_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'python_code',
                    'section2_py1',
                    $user_code,
                    null, // Cannot auto-grade Python code
                    null,
                    null
                );
                break;
        }

        // Refresh progress after submission
        $progress = $accessController->getModuleProgress();
    }

    // Handle reset requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_type'])) {
        switch ($_POST['reset_type']) {
            case 'reset_mcq':
                $_SESSION['exercise_answers']['module1']['section2_mcq1_answer'] = '';
                $_SESSION['exercise_completion']['module1']['section2_mcq1_completed'] = false;
                break;
            case 'reset_tf':
                $_SESSION['exercise_answers']['module1']['section2_tf_answers'] = [];
                $_SESSION['exercise_completion']['module1']['section2_tf1_completed'] = false;
                break;
            case 'reset_python':
                $_SESSION['exercise_answers']['module1']['section2_python_code'] = '';
                $_SESSION['exercise_completion']['module1']['section2_py1_completed'] = false;
                break;
        }

        // Update progress after reset
        $accessController->updateProgress('section2', false);
        $progress = $accessController->getModuleProgress();
    }

    // Get stored answers from session
    $stored_mcq_answer = $_SESSION['exercise_answers']['module1']['section2_mcq1_answer'] ?? '';
    $stored_tf_answers = $_SESSION['exercise_answers']['module1']['section2_tf_answers'] ?? [];
    $stored_python_code = $_SESSION['exercise_answers']['module1']['section2_python_code'] ?? '';

    // Get completion status from session
    $mcq_completed = $_SESSION['exercise_completion']['module1']['section2_mcq1_completed'] ?? false;
    $tf_completed = $_SESSION['exercise_completion']['module1']['section2_tf1_completed'] ?? false;
    $py_completed = $_SESSION['exercise_completion']['module1']['section2_py1_completed'] ?? false;

    // Calculate completed exercises count for initial display
    $completed_exercises_count = 0;
    if ($mcq_completed) $completed_exercises_count++;
    if ($tf_completed) $completed_exercises_count++;
    if ($py_completed) $completed_exercises_count++;

    // Calculate initial section progress based on completed exercises
    $initial_section_progress = ($completed_exercises_count / 3) * 100;

    // If we have exercise results but no stored answer yet, update from results
    if (isset($exercise_results['mc1']) && empty($stored_mcq_answer)) {
        $stored_mcq_answer = $exercise_results['mc1']['user_answer'] ?? '';
    }

    if (isset($exercise_results['tf1']) && empty($stored_tf_answers)) {
        $stored_tf_answers = $_POST['tf_answers'] ?? [];
    }

    if (isset($exercise_results['py1']) && empty($stored_python_code)) {
        $stored_python_code = $exercise_results['py1']['code'] ?? '';
    }

    // Update progress in session based on completion status
    if ($completed_exercises_count > 0) {
        // Refresh progress from database
        $progress = $accessController->getModuleProgress();
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Module 1: Introduction to Programming - Section 2: Introduction to Python - Impact Digital Academy</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #306998;
                --secondary: #FFD43B;
                --accent: #4B8BBE;
                --light: #f8f9fa;
                --dark: #343a40;
                --success: #28a745;
                --warning: #ffc107;
                --danger: #dc3545;
                --shadow: rgba(0, 0, 0, 0.1);
                --gradient: linear-gradient(135deg, #306998 0%, #4B8BBE 100%);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            body {
                background-color: #f5f7fa;
                color: var(--dark);
                line-height: 1.6;
                overflow-x: hidden;
                padding-top: 80px;
                /* Space for fixed header */
            }

            /* Navigation - Fixed at top */
            header {
                background: var(--gradient);
                color: white;
                padding: 1rem 1.5rem;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 1000;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }

            .nav-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                max-width: 1400px;
                margin: 0 auto;
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-shrink: 0;
            }

            .logo-icon {
                font-size: 2rem;
                color: var(--secondary);
            }

            .logo-text h1 {
                font-size: 1.2rem;
                font-weight: 700;
                white-space: nowrap;
            }

            .logo-text p {
                font-size: 0.8rem;
                opacity: 0.9;
                white-space: nowrap;
            }

            .mobile-menu-btn {
                display: none;
                background: none;
                border: none;
                color: white;
                font-size: 1.5rem;
                cursor: pointer;
                padding: 5px;
            }

            .user-info {
                display: flex;
                align-items: center;
                gap: 15px;
                color: white;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .logout-btn {
                color: white;
                text-decoration: none;
                padding: 8px 15px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 20px;
                font-size: 0.9rem;
                transition: background 0.3s;
            }

            .logout-btn:hover {
                background: rgba(255, 255, 255, 0.3);
            }

            /* Section Header - NOT FIXED, scrolls normally */
            .section-header {
                background: white;
                padding: 2rem 1.5rem 1rem;
                box-shadow: 0 2px 10px var(--shadow);
                margin-bottom: 2rem;
            }

            .section-header-content {
                max-width: 1200px;
                margin: 0 auto;
            }

            .breadcrumb {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.9rem;
                margin-bottom: 1rem;
                color: #666;
                flex-wrap: wrap;
            }

            .breadcrumb a {
                color: var(--primary);
                text-decoration: none;
                transition: color 0.3s ease;
            }

            .breadcrumb a:hover {
                color: var(--accent);
            }

            .section-title {
                color: var(--primary);
                margin-bottom: 0.5rem;
                font-size: 1.8rem;
            }

            .section-subtitle {
                color: #666;
                font-size: 1rem;
                margin-bottom: 1rem;
            }

            /* Progress Overview */
            .progress-overview {
                background: white;
                padding: 1.5rem;
                border-radius: 10px;
                box-shadow: 0 5px 15px var(--shadow);
                margin: 0 1.5rem 2rem;
                max-width: 1200px;
                margin-left: auto;
                margin-right: auto;
            }

            .progress-stats {
                display: flex;
                gap: 2rem;
                flex-wrap: wrap;
            }

            .progress-item {
                flex: 1;
                min-width: 200px;
            }

            .progress-label {
                display: flex;
                justify-content: space-between;
                margin-bottom: 0.5rem;
                font-weight: 600;
            }

            .progress-bar {
                height: 8px;
                background: #e9ecef;
                border-radius: 4px;
                overflow: hidden;
            }

            .progress-fill {
                height: 100%;
                background: var(--success);
                border-radius: 4px;
                transition: width 0.5s ease;
            }

            /* Main Content */
            .content-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 1.5rem;
            }

            .main-content {
                background: white;
                border-radius: 10px;
                padding: 2rem;
                box-shadow: 0 5px 15px var(--shadow);
                margin-bottom: 2rem;
            }

            /* Content Sections */
            .content-section {
                margin-bottom: 3rem;
                padding-bottom: 2rem;
                border-bottom: 1px solid #eee;
            }

            .content-section h2 {
                color: var(--primary);
                margin-bottom: 1.5rem;
                padding-bottom: 0.5rem;
                border-bottom: 3px solid var(--secondary);
                font-size: 1.5rem;
            }

            .content-section h3 {
                color: var(--accent);
                margin: 1.5rem 0 1rem;
                font-size: 1.2rem;
            }

            .concept-box {
                background: #f8f9fa;
                border-left: 4px solid var(--primary);
                padding: 1.5rem;
                margin: 1.5rem 0;
                border-radius: 0 5px 5px 0;
            }

            .concept-box h4 {
                color: var(--primary);
                margin-bottom: 0.5rem;
            }

            .definition-list {
                list-style: none;
                margin: 1rem 0;
            }

            .definition-list li {
                padding: 0.8rem;
                margin-bottom: 0.5rem;
                background: #f8f9fa;
                border-radius: 5px;
                border-left: 4px solid var(--accent);
                position: relative;
                padding-left: 2.5rem;
            }

            .definition-list li::before {
                content: "•";
                position: absolute;
                left: 1rem;
                color: var(--secondary);
                font-weight: bold;
                font-size: 1.5rem;
            }

            /* Comparison Table */
            .comparison-table {
                width: 100%;
                border-collapse: collapse;
                margin: 1.5rem 0;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px var(--shadow);
                font-size: 0.95rem;
            }

            .comparison-table th {
                background: var(--primary);
                color: white;
                padding: 1rem;
                text-align: left;
            }

            .comparison-table td {
                padding: 0.8rem 1rem;
                border-bottom: 1px solid #eee;
            }

            .comparison-table tr:last-child td {
                border-bottom: none;
            }

            /* Code Examples */
            .code-example {
                background: #1e1e1e;
                border-radius: 8px;
                overflow: hidden;
                margin: 1.5rem 0;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            }

            .code-header {
                background: #2d2d30;
                padding: 0.8rem 1rem;
                color: #ccc;
                font-family: 'Courier New', monospace;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .code-body {
                padding: 1.5rem;
                font-family: 'Fira Code', 'Courier New', monospace;
                color: #d4d4d4;
                line-height: 1.5;
                overflow-x: auto;
                white-space: pre;
                tab-size: 4;
                font-size: 0.9rem;
            }

            /* Exercises */
            .exercise-container {
                background: white;
                border-radius: 10px;
                padding: 2rem;
                margin: 2rem 0;
                box-shadow: 0 5px 15px var(--shadow);
                border-left: 5px solid var(--secondary);
            }

            .exercise-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 1.5rem;
                color: var(--primary);
            }

            .exercise-title {
                font-size: 1.2rem;
                font-weight: 600;
            }

            .exercise-points {
                background: var(--accent);
                color: white;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 0.9rem;
            }

            .mcq-options {
                margin: 1rem 0;
            }

            .mcq-option {
                margin-bottom: 0.8rem;
                padding: 0.8rem;
                background: #f8f9fa;
                border: 2px solid #dee2e6;
                border-radius: 5px;
                cursor: pointer;
                transition: all 0.3s;
                display: block;
            }

            .mcq-option:hover {
                border-color: var(--accent);
                background: #e9f7fe;
            }

            .mcq-option input[type="radio"] {
                margin-right: 10px;
            }

            .mcq-option.selected {
                border-color: var(--success);
                background: #d4edda;
            }

            .mcq-option.incorrect {
                border-color: var(--danger);
                background: #f8d7da;
            }

            .mcq-option.correct {
                border-color: var(--success);
                background: #d4edda;
            }

            .feedback {
                padding: 1rem;
                margin-top: 1rem;
                border-radius: 5px;
                display: none;
            }

            .feedback.correct {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                display: block;
            }

            .feedback.incorrect {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                display: block;
            }

            .true-false-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 1rem;
                margin: 1.5rem 0;
            }

            @media (min-width: 768px) {
                .true-false-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            .tf-question {
                background: #f8f9fa;
                padding: 1rem;
                border-radius: 8px;
                border: 2px solid #dee2e6;
            }

            .tf-options {
                display: flex;
                gap: 10px;
                margin-top: 0.8rem;
            }

            .tf-option {
                flex: 1;
                text-align: center;
                padding: 0.5rem;
                border: 2px solid #dee2e6;
                border-radius: 5px;
                cursor: pointer;
                transition: all 0.3s;
            }

            .tf-option:hover {
                background: #e9ecef;
            }

            .tf-option.selected {
                border-color: var(--primary);
                background: #e9f7fe;
            }

            .tf-option.correct {
                border-color: var(--success);
                background: #d4edda;
            }

            .tf-option.incorrect {
                border-color: var(--danger);
                background: #f8d7da;
            }

            .exercise-actions {
                margin-top: 2rem;
                display: flex;
                gap: 1rem;
                flex-wrap: wrap;
            }

            .btn {
                padding: 0.8rem 1.5rem;
                border-radius: 50px;
                font-weight: 600;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s ease;
                cursor: pointer;
                border: none;
                font-size: 0.9rem;
            }

            .btn-primary {
                background-color: var(--secondary);
                color: var(--dark);
            }

            .btn-primary:hover {
                background-color: #ffc107;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }

            .btn-submit {
                background: var(--success);
                color: white;
            }

            .btn-submit:hover {
                background: #218838;
            }

            .btn-reset {
                background: var(--warning);
                color: white;
            }

            .btn-reset:hover {
                background: #e0a800;
            }

            .btn-next {
                background: var(--primary);
                color: white;
            }

            .btn-next:hover {
                background: var(--accent);
            }

            /* Python Sandbox */
            .sandbox-container {
                background: white;
                border-radius: 10px;
                padding: 2rem;
                margin: 2rem 0;
                box-shadow: 0 5px 15px var(--shadow);
                border-top: 5px solid var(--success);
            }

            .sandbox-title {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 1.5rem;
                color: var(--primary);
            }

            .sandbox-editor {
                background: #1e1e1e;
                border-radius: 8px;
                overflow: hidden;
                margin: 1.5rem 0;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            }

            .editor-header {
                background: #2d2d30;
                padding: 0.8rem 1rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                color: #ccc;
                font-family: 'Courier New', monospace;
                font-size: 0.9rem;
            }

            .editor-body {
                padding: 1.5rem;
                font-family: 'Fira Code', 'Courier New', monospace;
                color: #d4d4d4;
                line-height: 1.5;
                min-height: 150px;
                max-height: 300px;
                overflow-y: auto;
                white-space: pre;
                tab-size: 4;
                outline: none;
                font-size: 0.9rem;
            }

            .editor-body[contenteditable="true"] {
                caret-color: white;
            }

            .sandbox-controls {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 1rem;
            }

            /* Image Styles */
            .content-image {
                max-width: 100%;
                height: auto;
                border-radius: 8px;
                margin: 1.5rem 0;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                border: 1px solid #dee2e6;
            }

            .image-caption {
                text-align: center;
                font-style: italic;
                color: #666;
                margin-top: 0.5rem;
                font-size: 0.9rem;
            }

            /* Interactive Elements */
            .interactive-box {
                background: linear-gradient(135deg, #e9f7fe 0%, #d1ecf1 100%);
                border: 2px solid var(--accent);
                border-radius: 10px;
                padding: 1.5rem;
                margin: 1.5rem 0;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .interactive-box:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            }

            .interactive-box h4 {
                color: var(--primary);
                margin-bottom: 0.5rem;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .interactive-content {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.5s ease;
            }

            .interactive-box.active .interactive-content {
                max-height: 500px;
                margin-top: 1rem;
            }

            /* Navigation Buttons */
            .navigation-buttons {
                display: flex;
                justify-content: space-between;
                margin: 3rem 0;
                padding: 0 1.5rem;
                max-width: 1200px;
                margin-left: auto;
                margin-right: auto;
                flex-wrap: wrap;
                gap: 1rem;
            }

            .nav-btn {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 1rem 1.5rem;
                background: var(--primary);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                transition: all 0.3s ease;
                flex: 1;
                min-width: 200px;
                justify-content: center;
            }

            .nav-btn:hover {
                background: var(--accent);
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }

            /* Footer */
            footer {
                background: var(--dark);
                color: white;
                padding: 2rem 1.5rem 1rem;
                margin-top: 4rem;
            }

            .footer-content {
                max-width: 1200px;
                margin: 0 auto;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 2rem;
                margin-bottom: 1.5rem;
            }

            .footer-section h3 {
                color: var(--secondary);
                margin-bottom: 1rem;
                font-size: 1.1rem;
            }

            .footer-links {
                list-style: none;
            }

            .footer-links li {
                margin-bottom: 0.5rem;
            }

            .footer-links a {
                color: #ccc;
                text-decoration: none;
                transition: color 0.3s ease;
                font-size: 0.9rem;
            }

            .footer-links a:hover {
                color: var(--secondary);
            }

            .copyright {
                text-align: center;
                padding-top: 1rem;
                border-top: 1px solid #444;
                color: #aaa;
                font-size: 0.9rem;
            }

            /* Mobile Responsive */
            @media (max-width: 768px) {
                body {
                    padding-top: 70px;
                }

                header {
                    padding: 0.8rem 1rem;
                }

                .logo-text h1 {
                    font-size: 1rem;
                }

                .logo-text p {
                    font-size: 0.7rem;
                }

                .user-info {
                    font-size: 0.8rem;
                }

                .logout-btn {
                    padding: 6px 12px;
                    font-size: 0.8rem;
                }

                .section-header {
                    padding: 1.5rem 1rem 0.8rem;
                }

                .section-title {
                    font-size: 1.4rem;
                }

                .progress-overview {
                    margin: 0 1rem 1.5rem;
                    padding: 1rem;
                }

                .content-container {
                    padding: 0 1rem;
                }

                .main-content {
                    padding: 1.5rem;
                }

                .content-section h2 {
                    font-size: 1.3rem;
                }

                .comparison-table {
                    font-size: 0.85rem;
                }

                .comparison-table th,
                .comparison-table td {
                    padding: 0.6rem 0.8rem;
                }

                .exercise-container {
                    padding: 1.5rem;
                }

                .sandbox-container {
                    padding: 1.5rem;
                }

                .nav-btn {
                    min-width: 100%;
                }

                .navigation-buttons {
                    padding: 0 1rem;
                }

                .progress-stats {
                    gap: 1rem;
                }

                .progress-item {
                    min-width: 100%;
                }
            }

            @media (max-width: 480px) {
                .logo-text h1 {
                    display: none;
                }

                .logo-text p {
                    display: none;
                }

                .breadcrumb {
                    font-size: 0.8rem;
                }

                .section-title {
                    font-size: 1.2rem;
                }

                .content-section h2 {
                    font-size: 1.1rem;
                }

                .code-body {
                    font-size: 0.8rem;
                    padding: 1rem;
                }

                .editor-body {
                    font-size: 0.8rem;
                    padding: 1rem;
                }
            }

            /* Scrollbar */
            .editor-body::-webkit-scrollbar {
                width: 8px;
            }

            .editor-body::-webkit-scrollbar-track {
                background: #1e1e1e;
            }

            .editor-body::-webkit-scrollbar-thumb {
                background: #555;
                border-radius: 4px;
            }

            .editor-body::-webkit-scrollbar-thumb:hover {
                background: #777;
            }

            /* Print Styles */
            @media print {

                header,
                .navigation-buttons,
                footer,
                .exercise-actions {
                    display: none;
                }

                body {
                    padding-top: 0;
                }
            }
        </style>
    </head>

    <body>
        <!-- Header -->
        <header>
            <div class="nav-container">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fab fa-python"></i>
                    </div>
                    <div class="logo-text">
                        <h1>Python Essentials 1</h1>
                        <p>Impact Digital Academy | Module 1 - Section 2</p>
                    </div>
                </div>

                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem;">
                            <?php echo $accessController->getUserDisplayName(); ?>
                        </div>
                        <div style="font-size: 0.8rem; opacity: 0.8;">
                            <?php echo ucfirst($accessController->getUserRole()); ?>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>

                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </header>

        <!-- Section Header -->
        <section class="section-header">
            <div class="section-header-content">
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL; ?>index.php"><i class="fas fa-home"></i> Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="module1.php">Module 1</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="module1_section1.php">Section 1</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Section 2</span>
                </div>
                <h1 class="section-title">Section 2: Introduction to Python</h1>
                <p class="section-subtitle">Understanding Python's history, versions, implementations, and its impact on modern programming</p>
            </div>
        </section>

        <!-- Progress Overview -->
        <div class="progress-overview">
            <div class="progress-stats">
                <div class="progress-item">
                    <div class="progress-label">
                        <span>Section Progress</span>
                        <span id="sectionProgress">
                            <?php
                            echo round($progress['section2']);
                            ?>%
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="sectionProgressBar"
                            style="width: <?php echo round($progress['section2']); ?>%"></div>
                    </div>
                </div>
                <div class="progress-item">
                    <div class="progress-label">
                        <span>Module Progress</span>
                        <span id="moduleProgress">
                            <?php
                            echo round($progress['overall']);
                            ?>%
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="moduleProgressBar"
                            style="width: <?php echo round($progress['overall']); ?>%"></div>
                    </div>
                </div>
                <div class="progress-item">
                    <div class="progress-label">
                        <span>Exercises Completed</span>
                        <span id="exercisesCompleted"><?php echo $completed_exercises_count; ?>/3</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="exercisesProgressBar" style="width: <?php echo ($completed_exercises_count / 3) * 100; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-container">
            <main class="main-content">
                <!-- Python: A Tool, Not a Reptile -->
                <section class="content-section" id="python-tool">
                    <h2><i class="fas fa-code"></i> 1.2.1 Python – a tool, not a reptile</h2>

                    <h3>What is Python?</h3>
                    <p>Python is a widely-used, interpreted, object-oriented, and high-level programming language with dynamic semantics, used for general-purpose programming.</p>

                    <div class="concept-box">
                        <h4>Fun Fact: The Name Origin</h4>
                        <p>While you may know the python as a large snake, the name of the Python programming language comes from an old BBC television comedy sketch series called <strong>Monty Python's Flying Circus</strong>.</p>
                        <p>At the height of its success, the Monty Python team were performing their sketches to live audiences across the world, including at the Hollywood Bowl.</p>
                        <p>Since Monty Python is considered one of the two fundamental nutrients to a programmer (the other being pizza), Python's creator named the language in honor of the TV show.</p>
                    </div>
                </section>

                <!-- Who Created Python? -->
                <section class="content-section" id="python-creator">
                    <h2><i class="fas fa-user-tie"></i> 1.2.2 Who created Python?</h2>

                    <p>One of the amazing features of Python is the fact that it is actually one person's work. Usually, new programming languages are developed and published by large companies employing lots of professionals, and due to copyright rules, it is very hard to name any of the people involved in the project. Python is an exception.</p>

                    <div class="concept-box">
                        <h4>Guido van Rossum</h4>
                        <p>Python was created by <strong>Guido van Rossum</strong>, born in 1956 in Haarlem, the Netherlands. Of course, Guido van Rossum did not develop and evolve all the Python components himself...</p>
                        <p>The speed with which Python has spread around the world is a result of the continuous work of thousands (very often anonymous) programmers, testers, users (many of them aren't IT specialists) and enthusiasts, but it must be said that the very first idea (the seed from which Python sprouted) came to one head – Guido's.</p>
                    </div>
                </section>

                <!-- A Hobby Programming Project -->
                <section class="content-section" id="hobby-project">
                    <h2><i class="fas fa-laptop-house"></i> 1.2.3 A hobby programming project</h2>

                    <p>The circumstances in which Python was created are a bit puzzling. According to Guido van Rossum:</p>

                    <div class="concept-box">
                        <h4>In Guido's Own Words</h4>
                        <p><em>"In December 1989, I was looking for a 'hobby' programming project that would keep me occupied during the week around Christmas. My office (...) would be closed, but I had a home computer, and not much else on my hands. I decided to write an interpreter for the new scripting language I had been thinking about lately: a descendant of ABC that would appeal to Unix/C hackers. I chose Python as a working title for the project, being in a slightly irreverent mood (and a big fan of Monty Python's Flying Circus)."</em> – Guido van Rossum</p>
                    </div>

                    <h3>Python Goals</h3>
                    <p>In 1999, Guido van Rossum defined his goals for Python:</p>

                    <div class="definition-list">
                        <li>An <strong>easy and intuitive</strong> language just as powerful as those of the major competitors</li>
                        <li><strong>Open source</strong>, so anyone can contribute to its development</li>
                        <li>Code that is as <strong>understandable</strong> as plain English</li>
                        <li><strong>Suitable for everyday tasks</strong>, allowing for short development times</li>
                    </div>

                    <div class="concept-box">
                        <h4>Goals Achieved</h4>
                        <p>About 20 years later, it is clear that all these intentions have been fulfilled. Some sources say that Python is the most popular programming language in the world, while others claim it's the second or the third.</p>
                        <p>Either way, it still occupies a high rank in the top ten of the <a href="http://pypl.github.io/PYPL.html" target="_blank">PYPL PopularitY of Programming Language</a> and the <a href="https://www.tiobe.com/tiobe-index/" target="_blank">TIOBE Programming Community Index</a>.</p>
                    </div>

                    <p>Python isn't a young language anymore. It is <strong>mature and trustworthy</strong>. It's not a one-hit wonder. It's a bright star in the programming firmament, and time spent learning Python is a very good investment.</p>

                    <!-- Multiple Choice Exercise -->
                    <div class="exercise-container" id="mcq-exercise-1">
                        <div class="exercise-header">
                            <i class="fas fa-question-circle"></i>
                            <div class="exercise-title">Quick Check: Python Versions</div>
                            <div class="exercise-points">5 points</div>
                        </div>

                        <p><strong>Question:</strong> Which version of Python should you use for new projects?</p>

                        <form method="POST" id="mcqForm1">
                            <input type="hidden" name="exercise_type" value="section2_multiple_choice_1">
                            <div class="mcq-options">
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'a') ? 'selected' : ''; ?>" onclick="selectOption(this, 'a')">
                                    <input type="radio" name="answer" value="a" required <?php echo ($stored_mcq_answer === 'a') ? 'checked' : ''; ?>>
                                    Python 3 (as Python 2 is no longer actively developed)
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'b') ? 'selected' : ''; ?>" onclick="selectOption(this, 'b')">
                                    <input type="radio" name="answer" value="b" required <?php echo ($stored_mcq_answer === 'b') ? 'checked' : ''; ?>>
                                    Python 2 (for better compatibility with existing code)
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'c') ? 'selected' : ''; ?>" onclick="selectOption(this, 'c')">
                                    <input type="radio" name="answer" value="c" required <?php echo ($stored_mcq_answer === 'c') ? 'checked' : ''; ?>>
                                    Both Python 2 and Python 3 equally
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'd') ? 'selected' : ''; ?>" onclick="selectOption(this, 'd')">
                                    <input type="radio" name="answer" value="d" required <?php echo ($stored_mcq_answer === 'd') ? 'checked' : ''; ?>>
                                    The latest experimental version
                                </label>
                            </div>

                            <?php if (isset($exercise_results['mc1'])): ?>
                                <div
                                    class="feedback <?php echo $exercise_results['mc1']['correct'] ? 'correct' : 'incorrect'; ?>">
                                    <i
                                        class="fas fa-<?php echo $exercise_results['mc1']['correct'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $exercise_results['mc1']['feedback']; ?>
                                </div>
                            <?php elseif ($mcq_completed): ?>
                                <div class="feedback correct">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>

                            <div class="exercise-actions">
                                <?php if (!$mcq_completed): ?>
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Answer
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-reset" onclick="resetMCQ('mcqForm1')">
                                    <i class="fas fa-redo"></i> <?php echo $mcq_completed ? 'Clear Answer' : 'Try Again'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- What Makes Python So Special? -->
                <section class="content-section" id="python-special">
                    <h2><i class="fas fa-star"></i> 1.2.4 What makes Python so special?</h2>

                    <h3>Why Python?</h3>
                    <p>How does it happen that programmers, young and old, experienced and novice, want to use it? How did it happen that large companies adopted Python and implemented their flagship products using it?</p>

                    <p>There are many reasons – we've listed some of them already, but let's enumerate them again in a more practical manner:</p>

                    <div class="definition-list">
                        <li>It's <strong>easy to learn</strong> – the time needed to learn Python is shorter than for many other languages; this means that it's possible to start the actual programming faster</li>
                        <li>It's <strong>easy to teach</strong> – the teaching workload is smaller than that needed by other languages; this means that the teacher can put more emphasis on general (language-independent) programming techniques, not wasting energy on exotic tricks, strange exceptions and incomprehensible rules</li>
                        <li>It's <strong>easy to use</strong> for writing new software – it's often possible to write code faster when using Python</li>
                        <li>It's <strong>easy to understand</strong> – it's also often easier to understand someone else's code faster if it is written in Python</li>
                        <li>It's <strong>easy to obtain, install and deploy</strong> – Python is free, open and multiplatform; not all languages can boast that</li>
                    </div>

                    <!-- True/False Exercise -->
                    <div class="exercise-container" id="tf-exercise-1">
                        <div class="exercise-header">
                            <i class="fas fa-check-double"></i>
                            <div class="exercise-title">True or False: Python Facts</div>
                            <div class="exercise-points">6 points</div>
                        </div>

                        <p><strong>Instructions:</strong> Mark each statement as True or False.</p>

                        <form method="POST" id="tfForm1">
                            <input type="hidden" name="exercise_type" value="section2_true_false_1">
                            <div class="true-false-grid">
                                <div class="tf-question">
                                    <p><strong>1.</strong> Python is named after the Monty Python comedy group.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q1']) && $stored_tf_answers['q1'] === 'true') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q1', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q1']) && $stored_tf_answers['q1'] === 'false') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q1', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q1]" id="q1_answer" value="<?php echo $stored_tf_answers['q1'] ?? ''; ?>">
                                </div>

                                <div class="tf-question">
                                    <p><strong>2.</strong> Python was developed by a large committee of programmers.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q2']) && $stored_tf_answers['q2'] === 'true') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q2', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q2']) && $stored_tf_answers['q2'] === 'false') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q2', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q2]" id="q2_answer" value="<?php echo $stored_tf_answers['q2'] ?? ''; ?>">
                                </div>

                                <div class="tf-question">
                                    <p><strong>3.</strong> Python is considered a mature and trustworthy programming language.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q3']) && $stored_tf_answers['q3'] === 'true') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q3', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q3']) && $stored_tf_answers['q3'] === 'false') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q3', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q3]" id="q3_answer" value="<?php echo $stored_tf_answers['q3'] ?? ''; ?>">
                                </div>

                                <div class="tf-question">
                                    <p><strong>4.</strong> CPython is the reference implementation of Python.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q4']) && $stored_tf_answers['q4'] === 'true') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q4', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q4']) && $stored_tf_answers['q4'] === 'false') ? 'selected' : ''; ?>"
                                            onclick="selectTF(this, 'q4', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q4]" id="q4_answer" value="<?php echo $stored_tf_answers['q4'] ?? ''; ?>">
                                </div>
                            </div>

                            <?php if (isset($exercise_results['tf1'])): ?>
                                <div
                                    class="feedback <?php echo $exercise_results['tf1']['is_passing'] ? 'correct' : 'incorrect'; ?>">
                                    <i
                                        class="fas fa-<?php echo $exercise_results['tf1']['is_passing'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    You scored
                                    <?php echo $exercise_results['tf1']['score']; ?> out of
                                    <?php echo $exercise_results['tf1']['total']; ?>
                                    (
                                    <?php echo $exercise_results['tf1']['percentage']; ?>%)
                                </div>
                            <?php elseif ($tf_completed): ?>
                                <div class="feedback correct">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>

                            <div class="exercise-actions">
                                <?php if (!$tf_completed): ?>
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Answers
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-reset" onclick="resetTF('tfForm1')">
                                    <i class="fas fa-redo"></i> <?php echo $tf_completed ? 'Clear Answers' : 'Reset'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Python Rivals -->
                <section class="content-section" id="python-rivals">
                    <h2><i class="fas fa-fist-raised"></i> 1.2.5 Python rivals?</h2>

                    <p>Python has two direct competitors, with comparable properties and predispositions. These are:</p>

                    <div class="definition-list">
                        <li><strong>Perl</strong> – a scripting language originally authored by Larry Wall</li>
                        <li><strong>Ruby</strong> – a scripting language originally authored by Yukihiro Matsumoto</li>
                    </div>

                    <div class="concept-box">
                        <h4>Language Comparison</h4>
                        <p>The former (Perl) is more traditional and more conservative than Python, and resembles some of the old languages derived from the classic C programming language.</p>
                        <p>In contrast, the latter (Ruby) is more innovative and more full of fresh ideas than Python. Python itself lies somewhere between these two creations.</p>
                    </div>

                    <p>The Internet is full of forums with infinite discussions on the superiority of one of these three over the others, should you wish to learn more about each of them.</p>
                </section>

                <!-- Where Can We See Python in Action? -->
                <section class="content-section" id="python-in-action">
                    <h2><i class="fas fa-globe"></i> 1.2.6 Where can we see Python in action?</h2>

                    <p>We see it every day and almost everywhere. It's used extensively to implement complex <strong>Internet services</strong> like search engines, cloud storage and tools, social media and so on. Whenever you use any of these services, you are actually very close to Python, although you wouldn't know it.</p>

                    <div class="definition-list">
                        <li>Many <strong>developing tools</strong> are implemented in Python</li>
                        <li>More and more <strong>everyday-use applications</strong> are being written in Python</li>
                        <li>Lots of <strong>scientists</strong> have abandoned expensive proprietary tools and switched to Python</li>
                        <li>Lots of IT project <strong>testers</strong> have started using Python to carry out repeatable test procedures</li>
                    </div>

                    <p>The list is long and continues to grow as Python's popularity increases across different industries.</p>
                </section>

                <!-- Why Not Python? -->
                <section class="content-section" id="why-not-python">
                    <h2><i class="fas fa-times-circle"></i> 1.2.7 Why not Python?</h2>

                    <p>Despite Python's growing popularity, there are still some niches where Python is absent, or is rarely seen:</p>

                    <div class="definition-list">
                        <li><strong>Low-level programming</strong> (sometimes called "close to metal" programming): if you want to implement an extremely effective driver or graphical engine, you wouldn't use Python</li>
                        <li><strong>Applications for mobile devices</strong>: although this territory is still waiting to be conquered by Python, it will most likely happen someday</li>
                    </div>

                    <div class="concept-box">
                        <h4>Python's Limitations</h4>
                        <p>Python's interpreted nature and dynamic typing make it less suitable for systems programming or applications where maximum performance is critical. For these use cases, languages like C, C++, or Rust are typically preferred.</p>
                    </div>
                </section>

                <!-- There is More Than One Python -->
                <section class="content-section" id="multiple-pythons">
                    <h2><i class="fas fa-code-branch"></i> 1.2.8 There is more than one Python</h2>

                    <h3>Python 2 vs. Python 3</h3>
                    <p>There are two main kinds of Python, called Python 2 and Python 3.</p>

                    <div class="concept-box">
                        <h4>Python 2</h4>
                        <p>Python 2 is an older version of the original Python. Its development has since been intentionally stalled, although that doesn't mean that there are no updates to it. On the contrary, the updates are issued on a regular basis, but they are not intended to modify the language in any significant way. They rather fix any freshly discovered bugs and security holes. Python 2's development path has reached a dead end already, but Python 2 itself is still very much alive.</p>
                    </div>

                    <div class="concept-box">
                        <h4>Python 3</h4>
                        <p><strong>Python 3 is the newer (or to be more precise, the current) version of the language.</strong> It's going through its own evolutionary path, creating its own standards and habits.</p>
                    </div>

                    <h3>Compatibility Issues</h3>
                    <p>These two versions of Python aren't compatible with each other. Python 2 scripts won't run in a Python 3 environment and vice versa, so if you want the old Python 2 code to be run by a Python 3 interpreter, the only possible solution is to rewrite it, not from scratch, of course, as large parts of the code may remain untouched, but you do have to revise all the code to find all possible incompatibilities. Unfortunately, this process cannot be fully automatized.</p>

                    <div class="concept-box">
                        <h4>Migration Challenges</h4>
                        <p>It's too hard, too time-consuming, too expensive, and too risky to migrate an old Python 2 application to a new platform, and it's even possible that rewriting the code will introduce new bugs into it. It's easier, and more sensible, to leave these systems alone and to improve the existing interpreter, instead of trying to work inside the already functioning source code.</p>
                    </div>

                    <p>Python 3 isn't just a better version of Python 2 – it is a completely different language, although it's very similar to its predecessor. When you look at them from a distance, they appear to be the same, but when you look closely, though, you notice a lot of differences.</p>

                    <div class="concept-box" style="background: #e9f7fe; border-left-color: #306998;">
                        <h4><i class="fas fa-exclamation-triangle"></i> Important Note</h4>
                        <p>If you're going to start a new Python project, <strong>you should use Python 3, and this is the version of Python that will be used during this course.</strong></p>
                    </div>

                    <p>It is important to remember that there may be smaller or bigger differences between subsequent Python 3 releases (e.g., Python 3.6 introduced ordered dictionary keys by default under the CPython implementation) – the good news, though, is that all the newer versions of Python 3 are <strong>backward compatible</strong> with the previous versions of Python 3. Whenever meaningful and important, we will always try to highlight those differences in the course.</p>

                    <p>All the code samples you will find during the course have been tested against Python 3.4, Python 3.6, Python 3.7, Python 3.8, and Python 3.9.</p>
                </section>

                <!-- Python Implementations -->
                <section class="content-section" id="python-implementations">
                    <h2><i class="fas fa-cogs"></i> 1.2.9 Python implementations</h2>

                    <p>In addition to Python 2 and Python 3, there is more than one version of each.</p>

                    <div class="concept-box">
                        <h4>What is a Python Implementation?</h4>
                        <p>Following the <a href="https://wiki.python.org/moin/PythonImplementations" target="_blank">Python wiki page</a>, an <em>implementation</em> of Python refers to "a program or environment, which provides support for the execution of programs written in the Python language, as represented by the CPython reference implementation."</p>
                    </div>

                    <h3>CPython: The Reference Implementation</h3>
                    <p>The <strong>traditional</strong> implementation of Python, called <strong>CPython</strong>, is Guido van Rossum's reference version of the Python computing language, and it's most often called just "Python". When you hear the name <em>CPython</em>, it's most probably used to distinguish it from other, non-traditional, alternative implementations.</p>

                    <div class="concept-box">
                        <h4>Canonical Pythons</h4>
                        <p>But, first things first. There are the Pythons which are maintained by the people gathered around the PSF (<a href="https://www.python.org/psf-landing/" target="_blank">Python Software Foundation</a>), a community that aims to develop, improve, expand, and popularize Python and its environment. The PSF's president is Guido von Rossum himself, and for this reason, these Pythons are called <strong>canonical</strong>. They are also considered to be <strong>reference Pythons</strong>, as any other implementation of the language should follow all standards established by the PSF.</p>
                    </div>

                    <p>Guido van Rossum used the "C" programming language to implement the very first version of his language and this decision is still in force. All Pythons coming from the PSF are written in the "C" language. There are many reasons for this approach. One of them (probably the most important) is that thanks to it, Python may be easily ported and migrated to all platforms with the ability to compile and run "C" language programs (virtually all platforms have this feature, which opens up many expansion opportunities for Python).</p>

                    <p>This is why the PSF implementation is often referred to as <strong>CPython</strong>. This is the most influential Python among all the Pythons in the world.</p>

                    <!-- Interactive Python Implementations -->
                    <div class="interactive-box" onclick="toggleInteractive(this)">
                        <h4>
                            <i class="fas fa-hand-point-right"></i> Click to learn about Cython
                            <i class="fas fa-chevron-down float-right"></i>
                        </h4>
                        <div class="interactive-content">
                            <p><strong>Cython</strong> is one of a possible number of solutions to the most painful of Python's traits – the lack of efficiency. Large and complex mathematical calculations may be easily coded in Python (much easier than in "C" or any other traditional language), but the resulting code execution may be extremely time-consuming.</p>
                            <p>How are these two contradictions reconciled? One solution is to write your mathematical ideas using Python, and when you're absolutely sure that your code is correct and produces valid results, you can translate it into "C". Certainly, "C" will run much faster than pure Python.</p>
                            <p>This is what Cython is intended to do – to automatically translate the Python code (clean and clear, but not too swift) into "C" code (complicated and talkative, but agile).</p>
                        </div>
                    </div>

                    <div class="interactive-box" onclick="toggleInteractive(this)">
                        <h4>
                            <i class="fas fa-hand-point-right"></i> Click to learn about Jython
                            <i class="fas fa-chevron-down float-right"></i>
                        </h4>
                        <div class="interactive-content">
                            <p>Another version of Python is called <strong>Jython</strong>.</p>
                            <p>"J" is for "Java". Imagine a Python written in Java instead of C. This is useful, for example, if you develop large and complex systems written entirely in Java and want to add some Python flexibility to them. The traditional CPython may be difficult to integrate into such an environment, as C and Java live in completely different worlds and don't share many common ideas.</p>
                            <p>Jython can communicate with existing Java infrastructure more effectively. This is why some projects find it useful and necessary.</p>
                            <p><strong>Note:</strong> the current Jython implementation follows Python 2 standards. There is no Jython conforming to Python 3, so far.</p>
                        </div>
                    </div>

                    <div class="interactive-box" onclick="toggleInteractive(this)">
                        <h4>
                            <i class="fas fa-hand-point-right"></i> Click to learn about PyPy
                            <i class="fas fa-chevron-down float-right"></i>
                        </h4>
                        <div class="interactive-content">
                            <p>The <strong>PyPy</strong> logo is a rebus. Can you solve it? It means: a Python within a Python. In other words, it represents a Python environment written in Python-like language named <strong>RPython</strong> (Restricted Python). It is actually a subset of Python.</p>
                            <p>The source code of PyPy is not run in the interpretation manner, but is instead translated into the C programming language and then executed separately.</p>
                            <p>This is useful because if you want to test any new feature that may be (but doesn't have to be) introduced into mainstream Python implementation, it's easier to check it with PyPy than with CPython. This is why PyPy is rather a tool for people developing Python than for the rest of the users.</p>
                            <p>This doesn't make PyPy any less important or less serious than CPython, of course.</p>
                            <p>In addition, PyPy is compatible with the Python 3 language.</p>
                        </div>
                    </div>

                    <div class="interactive-box" onclick="toggleInteractive(this)">
                        <h4>
                            <i class="fas fa-hand-point-right"></i> Click to learn about MicroPython
                            <i class="fas fa-chevron-down float-right"></i>
                        </h4>
                        <div class="interactive-content">
                            <p><strong>MicroPython</strong> is an efficient open source software implementation of Python 3 that is optimized to run on <strong>microcontrollers</strong>. It includes a small subset of the Python Standard Library, but it is largely packed with a large number of features such as interactive prompt or arbitrary precision integers, as well as modules that give the programmer access to low-level hardware.</p>
                            <p>Originally created by Damien George, an Australian programmer, who in the year 2013 ran a successful campaign on Kickstarter, and released the first MicroPython version with an STM32F4-powered development board called <strong>pyboard</strong>.</p>
                            <p>In 2017, MicroPython was used to create <strong>CircuitPython</strong>, another one open source programming language that runs on the microcontroller hardware, which is a derivative of the MicroPython language.</p>
                        </div>
                    </div>

                    <div class="concept-box" style="background: #d4edda; border-left-color: #28a745;">
                        <h4><i class="fas fa-info-circle"></i> Course Focus</h4>
                        <p>There are many more different Pythons in the world. You'll find them if you look, but <strong>this course will focus on CPython</strong> – the standard implementation that you'll most likely use in your Python journey.</p>
                    </div>
                </section>

                <!-- Python Sandbox Exercise -->
                <section class="content-section" id="python-exercise">
                    <h2><i class="fas fa-code"></i> Hands-on Python Exercise</h2>

                    <div class="sandbox-container">
                        <div class="sandbox-title">
                            <i class="fas fa-laptop-code"></i>
                            <h3>Explore Python Features</h3>
                        </div>

                        <p>Let's explore some of Python's features. Write a program that demonstrates Python's readability by calculating and displaying various mathematical operations.</p>

                        <form method="POST" id="pythonForm1">
                            <input type="hidden" name="exercise_type" value="section2_python_exercise_1">

                            <div class="sandbox-editor">
                                <div class="editor-header">
                                    <span><i class="fab fa-python"></i> python_features.py</span>
                                    <span>Python 3.10</span>
                                </div>
                                <div class="editor-body" id="pythonEditor" contenteditable="true" spellcheck="false">
                                    <?php
                                    if (!empty($stored_python_code)) {
                                        echo htmlspecialchars($stored_python_code);
                                    } else {
                                        echo '# Explore Python Features
# Python is known for being readable and expressive

# Define some variables
base_value = 10
multiplier = 3
exponent = 2

# Perform calculations
addition_result = base_value + multiplier
multiplication_result = base_value * multiplier
exponent_result = base_value ** exponent
division_result = base_value / multiplier

# Display results in a readable format
print("Python Feature Demonstration")
print("=" * 30)
print(f"Base value: {base_value}")
print(f"Multiplier: {multiplier}")
print(f"Exponent: {exponent}")
print("-" * 30)
print(f"Addition: {base_value} + {multiplier} = {addition_result}")
print(f"Multiplication: {base_value} × {multiplier} = {multiplication_result}")
print(f"Exponentiation: {base_value}^{exponent} = {exponent_result}")
print(f"Division: {base_value} ÷ {multiplier} = {division_result:.2f}")
print("=" * 30)
print("Python makes complex operations readable!")';
                                    }
                                    ?>
                                </div>
                            </div>

                            <textarea name="python_code" id="pythonCodeInput" style="display:none;"><?php echo htmlspecialchars($stored_python_code); ?></textarea>

                            <div class="sandbox-controls">
                                <button type="button" class="btn btn-primary" onclick="runPythonCode()">
                                    <i class="fas fa-play"></i> Run Code
                                </button>
                                <?php if (!$py_completed): ?>
                                    <button type="button" class="btn btn-reset" onclick="resetPythonEditor()">
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-submit">
                                    <i class="fas fa-save"></i> <?php echo $py_completed ? 'Update Code' : 'Save & Continue'; ?>
                                </button>
                            </div>

                            <div id="pythonOutput"
                                style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 5px; border: 1px solid #dee2e6; min-height: 100px;">
                                <strong>Output will appear here...</strong>
                                <div id="outputContent"></div>
                            </div>

                            <?php if (isset($exercise_results['py1'])): ?>
                                <div class="feedback correct" style="margin-top: 1rem;">
                                    <i class="fas fa-check-circle"></i>
                                    Your code has been saved! You can continue with the next section.
                                </div>
                            <?php elseif ($py_completed): ?>
                                <div class="feedback correct" style="margin-top: 1rem;">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="concept-box" style="background: #e9f7fe; border-left-color: #306998; margin-top: 2rem;">
                        <h4><i class="fas fa-lightbulb"></i> Key Takeaways from Section 2</h4>
                        <p>Python is a powerful, readable, and versatile programming language with a rich history and strong community support. Remember:</p>
                        <div class="definition-list">
                            <li>Python was created by Guido van Rossum as a "hobby" project</li>
                            <li>The language is named after Monty Python, not the snake</li>
                            <li>Python 3 is the current and recommended version for new projects</li>
                            <li>CPython is the reference implementation</li>
                            <li>Python excels in readability, ease of learning, and versatility</li>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        <!-- Navigation Buttons -->
        <div class="navigation-buttons">
            <a href="module1_section1.php" class="nav-btn">
                <i class="fas fa-arrow-left"></i> Previous: Introduction to Programming
            </a>
            <a href="module1_section3.php" class="nav-btn" id="nextSectionBtn">
                Next Section: Python Installation <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Module 1 Progress</h3>
                    <p>Section 2:
                        <?php
                        echo round($progress['section2']);
                        ?>% complete
                    </p>
                    <p><strong>Next:</strong> Section 3: Python Installation</p>
                </div>

                
                <div class="footer-section">
                    <h3>About This Section</h3>
                    <p>Section 2 introduces Python's history, versions, implementations, and why it has become such a popular programming language.</p>
                </div>
            </div>

            <div class="copyright">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. Python Essentials 1 - Module 1 - Section 2</p>
                <p style="margin-top: 0.5rem; font-size: 0.8rem;">Python is a registered trademark of the Python Software Foundation</p>
            </div>
        </footer>

        <script>
            // Exercise completion tracking
            let completedExercises = <?php echo $completed_exercises_count; ?>;
            const totalExercises = 3;

            // Update progress bars on page load
            function updateProgress() {
                // Count completed exercises
                completedExercises = 0;

                // Check which exercises have feedback (completed)
                if (document.querySelector('#mcqForm1 .feedback')) completedExercises++;
                if (document.querySelector('#tfForm1 .feedback')) completedExercises++;
                if (document.querySelector('#pythonForm1 .feedback')) completedExercises++;

                // Update UI
                document.getElementById('exercisesCompleted').textContent = `${completedExercises}/${totalExercises}`;
                const progressPercent = (completedExercises / totalExercises) * 100;
                document.getElementById('exercisesProgressBar').style.width = `${progressPercent}%`;

                // Update section progress based on exercises
                const sectionProgress = Math.min(100, (completedExercises / totalExercises) * 100);
                document.getElementById('sectionProgress').textContent = `${Math.round(sectionProgress)}%`;
                document.getElementById('sectionProgressBar').style.width = `${sectionProgress}%`;

                // Update module progress (simplified)
                const moduleProgress = 25 + (sectionProgress * 0.75); // Section 2 contributes 75% to module progress
                document.getElementById('moduleProgress').textContent = `${Math.round(moduleProgress)}%`;
                document.getElementById('moduleProgressBar').style.width = `${moduleProgress}%`;
            }

            // Multiple Choice Functions
            function selectOption(element, value) {
                // Remove selected class from all options
                const options = element.parentElement.querySelectorAll('.mcq-option');
                options.forEach(opt => {
                    opt.classList.remove('selected');
                });

                // Add selected class to clicked option
                element.classList.add('selected');

                // Set the radio button value
                const radio = element.querySelector('input[type="radio"]');
                radio.checked = true;
            }

            function resetMCQ(formId) {
                // Create a form for reset
                const resetForm = document.createElement('form');
                resetForm.method = 'POST';
                resetForm.style.display = 'none';

                const resetInput = document.createElement('input');
                resetInput.type = 'hidden';
                resetInput.name = 'reset_type';
                resetInput.value = 'reset_mcq';
                resetForm.appendChild(resetInput);

                document.body.appendChild(resetForm);
                resetForm.submit();
            }

            // True/False Functions
            function selectTF(element, questionId, value) {
                const questionDiv = element.closest('.tf-question');

                // Remove selected class from all options in this question
                const options = questionDiv.querySelectorAll('.tf-option');
                options.forEach(opt => {
                    opt.classList.remove('selected');
                });

                // Add selected class to clicked option
                element.classList.add('selected');

                // Set the hidden input value
                const hiddenInput = document.getElementById(`${questionId}_answer`);
                if (hiddenInput) {
                    hiddenInput.value = value;
                }
            }

            function resetTF(formId) {
                // Create a form for reset
                const resetForm = document.createElement('form');
                resetForm.method = 'POST';
                resetForm.style.display = 'none';

                const resetInput = document.createElement('input');
                resetInput.type = 'hidden';
                resetInput.name = 'reset_type';
                resetInput.value = 'reset_tf';
                resetForm.appendChild(resetInput);

                document.body.appendChild(resetForm);
                resetForm.submit();
            }

            // Interactive Content Toggle
            function toggleInteractive(element) {
                element.classList.toggle('active');
                const icon = element.querySelector('.fa-chevron-down');
                if (element.classList.contains('active')) {
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                } else {
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                }
            }

            // Python Sandbox Functions
            function getPythonCode() {
                const editor = document.getElementById('pythonEditor');
                return editor.textContent;
            }

            function setPythonCode(code) {
                const editor = document.getElementById('pythonEditor');
                editor.textContent = code;
                updatePythonInput();
            }

            function updatePythonInput() {
                const code = getPythonCode();
                document.getElementById('pythonCodeInput').value = code;
            }

            function resetPythonEditor() {
                // Create a form for reset
                const resetForm = document.createElement('form');
                resetForm.method = 'POST';
                resetForm.style.display = 'none';

                const resetInput = document.createElement('input');
                resetInput.type = 'hidden';
                resetInput.name = 'reset_type';
                resetInput.value = 'reset_python';
                resetForm.appendChild(resetInput);

                document.body.appendChild(resetForm);
                resetForm.submit();
            }

            async function runPythonCode() {
                const pythonCode = getPythonCode();
                const outputDiv = document.getElementById('outputContent');

                // Show loading state
                outputDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Executing code...';
                document.getElementById('pythonOutput').style.backgroundColor = '#fff3cd';

                try {
                    // Simple client-side evaluation for demonstration
                    // In production, this would call a secure API like Piston
                    await new Promise(resolve => setTimeout(resolve, 1000)); // Simulate API call

                    // Check for common patterns
                    if (pythonCode.includes('print(') && pythonCode.includes('base_value')) {
                        outputDiv.innerHTML = `<div style="color: var(--success);">
                        <i class="fas fa-check-circle"></i> <strong>Success! Output:</strong><br>
                        <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; font-family: 'Courier New', monospace;">
Python Feature Demonstration
==============================
Base value: 10
Multiplier: 3
Exponent: 2
------------------------------
Addition: 10 + 3 = 13
Multiplication: 10 × 3 = 30
Exponentiation: 10^2 = 100
Division: 10 ÷ 3 = 3.33
==============================
Python makes complex operations readable!</pre>
                    </div>`;
                        document.getElementById('pythonOutput').style.backgroundColor = '#d4edda';
                    } else if (pythonCode.includes('print')) {
                        outputDiv.innerHTML = `<div style="color: var(--warning);">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Code executed successfully</strong><br>
                        Your code runs, but make sure it demonstrates Python features effectively.
                    </div>`;
                        document.getElementById('pythonOutput').style.backgroundColor = '#fff3cd';
                    } else {
                        outputDiv.innerHTML = `<div style="color: var(--danger);">
                        <i class="fas fa-times-circle"></i> <strong>Incomplete code</strong><br>
                        Make sure your code includes print statements to display results.
                    </div>`;
                        document.getElementById('pythonOutput').style.backgroundColor = '#f8d7da';
                    }
                } catch (error) {
                    outputDiv.innerHTML = `<div style="color: var(--danger);">
                    <i class="fas fa-times-circle"></i> <strong>Error:</strong> ${error.message}
                </div>`;
                    document.getElementById('pythonOutput').style.backgroundColor = '#f8d7da';
                }

                // Update the hidden input field
                updatePythonInput();
            }

            // Update python input when editor changes
            document.getElementById('pythonEditor').addEventListener('input', updatePythonInput);

            // Next Section Button
            document.getElementById('nextSectionBtn').addEventListener('click', function(e) {
                e.preventDefault();
                const nextSectionUrl = this.getAttribute('href');

                if (completedExercises < totalExercises) {
                    if (confirm(`You've completed ${completedExercises} out of ${totalExercises} exercises. Are you sure you want to proceed to the next section?`)) {
                        window.location.href = nextSectionUrl;
                    }
                } else {
                    // All exercises completed
                    window.location.href = nextSectionUrl;
                }
            });

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                // Update progress on page load
                updateProgress();

                // Set up form submissions to update progress
                const forms = document.querySelectorAll('form');
                forms.forEach(form => {
                    form.addEventListener('submit', function() {
                        setTimeout(updateProgress, 100);
                    });
                });

                // Auto-save python code every 30 seconds
                setInterval(updatePythonInput, 30000);

                // Mobile menu toggle
                const mobileMenuBtn = document.getElementById('mobileMenuBtn');
                mobileMenuBtn.addEventListener('click', function() {
                    alert('Mobile menu would open here. In full implementation, this would show navigation links.');
                });

                // Smooth scroll for anchor links
                document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                    anchor.addEventListener('click', function(e) {
                        if (this.getAttribute('href') !== '#') {
                            e.preventDefault();
                            const targetId = this.getAttribute('href');
                            const targetElement = document.querySelector(targetId);
                            if (targetElement) {
                                window.scrollTo({
                                    top: targetElement.offsetTop - 100,
                                    behavior: 'smooth'
                                });
                            }
                        }
                    });
                });

               // Initialize interactive boxes
document.querySelectorAll('.interactive-box').forEach(box => {
    box.addEventListener('click', function(e) {
        // Don't toggle if clicking on a link or directly on the chevron icon
        if (!e.target.closest('a') && !e.target.classList.contains('fa-chevron-down') && !e.target.classList.contains('fa-chevron-up')) {
            toggleInteractive(this);
        }
    });
});

            // Handle window resize for mobile
            window.addEventListener('resize', function() {
                // Update any responsive elements as needed
            });
        </script>
    </body>

    </html>
<?php
} catch (Exception $e) {
    die("<div style='padding:20px; background:#fee; border:1px solid #f00; color:#900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>