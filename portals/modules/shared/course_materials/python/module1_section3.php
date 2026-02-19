<?php
// module1_section3.php - Python Essentials 1: Module 1 - Introduction to Programming - Section 3
declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

/**
 * Module 1 Access Controller for Section 3
 */
class Module1Section3Controller
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
    $accessController = new Module1Section3Controller();
    $progress = $accessController->getModuleProgress();

    // Initialize session storage for exercise answers
    if (!isset($_SESSION['exercise_answers'])) {
        $_SESSION['exercise_answers'] = [
            'module1' => [
                'section3_mcq1_answer' => '',
                'section3_mcq2_answer' => '',
                'section3_code_analysis_answer' => '',
                'section3_python_code' => ''
            ]
        ];
    }

    // Initialize session storage for exercise completion
    if (!isset($_SESSION['exercise_completion'])) {
        $_SESSION['exercise_completion'] = [
            'module1' => [
                'section3_mcq1_completed' => false,
                'section3_mcq2_completed' => false,
                'section3_code_analysis_completed' => false,
                'section3_python_exercise_completed' => false
            ]
        ];
    }

    // Handle exercise submissions
    $exercise_results = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exercise_type'])) {
        // Update section progress first
        $accessController->updateProgress('section3', true);

        switch ($_POST['exercise_type']) {
            case 'section3_multiple_choice_1':
                $user_answer = $_POST['answer'] ?? '';
                $correct = ($user_answer === 'b'); // Linux users most probably have Python already installed
                $exercise_results['mc1'] = [
                    'correct' => $correct,
                    'user_answer' => $user_answer,
                    'correct_answer' => 'b',
                    'feedback' => $correct ?
                        "Correct! Python is intensively used by many Linux OS components, so Linux users often have it pre-installed." :
                        "Incorrect. Linux users typically have Python already installed because it's used by many Linux OS components."
                ];

                // Store answer in session
                $_SESSION['exercise_answers']['module1']['section3_mcq1_answer'] = $user_answer;
                $_SESSION['exercise_completion']['module1']['section3_mcq1_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'multiple_choice',
                    'section3_mcq1',
                    $user_answer,
                    $correct,
                    $correct ? 5 : 0,
                    5
                );
                break;

            case 'section3_multiple_choice_2':
                $user_answer = $_POST['answer'] ?? '';
                $correct = ($user_answer === 'c'); // IDLE - Integrated Development and Learning Environment
                $exercise_results['mc2'] = [
                    'correct' => $correct,
                    'user_answer' => $user_answer,
                    'correct_answer' => 'c',
                    'feedback' => $correct ?
                        "Correct! IDLE stands for Integrated Development and Learning Environment, which is included with Python standard installation." :
                        "Incorrect. IDLE stands for Integrated Development and Learning Environment, a simple but useful application that comes with Python."
                ];

                // Store answer in session
                $_SESSION['exercise_answers']['module1']['section3_mcq2_answer'] = $user_answer;
                $_SESSION['exercise_completion']['module1']['section3_mcq2_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'multiple_choice',
                    'section3_mcq2',
                    $user_answer,
                    $correct,
                    $correct ? 5 : 0,
                    5
                );
                break;

            case 'section3_code_analysis':
                $user_answer = $_POST['code_issue'] ?? '';
                $correct = ($user_answer === 'b'); // Missing closing parenthesis
                $exercise_results['code_analysis'] = [
                    'correct' => $correct,
                    'user_answer' => $user_answer,
                    'correct_answer' => 'b',
                    'feedback' => $correct ?
                        "Correct! The code is missing a closing parenthesis, which creates a syntax error. Python requires all parentheses to be properly paired." :
                        "Incorrect. The main issue with this code is the missing closing parenthesis. Python requires proper pairing of parentheses."
                ];

                // Store answer in session
                $_SESSION['exercise_answers']['module1']['section3_code_analysis_answer'] = $user_answer;
                $_SESSION['exercise_completion']['module1']['section3_code_analysis_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'code_analysis',
                    'section3_code_analysis',
                    $user_answer,
                    $correct,
                    $correct ? 10 : 0,
                    10
                );
                break;

            case 'section3_python_exercise_1':
                $user_code = $_POST['python_code'] ?? '';
                $exercise_results['py1'] = [
                    'submitted' => true,
                    'code' => $user_code
                ];

                // Store code in session
                $_SESSION['exercise_answers']['module1']['section3_python_code'] = $user_code;
                $_SESSION['exercise_completion']['module1']['section3_python_exercise_completed'] = true;

                // Save to database
                $accessController->saveExerciseSubmission(
                    'python_code',
                    'section3_py1',
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
            case 'reset_mcq1':
                $_SESSION['exercise_answers']['module1']['section3_mcq1_answer'] = '';
                $_SESSION['exercise_completion']['module1']['section3_mcq1_completed'] = false;
                break;
            case 'reset_mcq2':
                $_SESSION['exercise_answers']['module1']['section3_mcq2_answer'] = '';
                $_SESSION['exercise_completion']['module1']['section3_mcq2_completed'] = false;
                break;
            case 'reset_code_analysis':
                $_SESSION['exercise_answers']['module1']['section3_code_analysis_answer'] = '';
                $_SESSION['exercise_completion']['module1']['section3_code_analysis_completed'] = false;
                break;
            case 'reset_python':
                $_SESSION['exercise_answers']['module1']['section3_python_code'] = '';
                $_SESSION['exercise_completion']['module1']['section3_python_exercise_completed'] = false;
                break;
        }

        // Update progress after reset
        $accessController->updateProgress('section3', false);
        $progress = $accessController->getModuleProgress();
    }

    // Get stored answers from session
    $stored_mcq1_answer = $_SESSION['exercise_answers']['module1']['section3_mcq1_answer'] ?? '';
    $stored_mcq2_answer = $_SESSION['exercise_answers']['module1']['section3_mcq2_answer'] ?? '';
    $stored_code_analysis_answer = $_SESSION['exercise_answers']['module1']['section3_code_analysis_answer'] ?? '';
    $stored_python_code = $_SESSION['exercise_answers']['module1']['section3_python_code'] ?? '';

    // Get completion status from session
    $mcq1_completed = $_SESSION['exercise_completion']['module1']['section3_mcq1_completed'] ?? false;
    $mcq2_completed = $_SESSION['exercise_completion']['module1']['section3_mcq2_completed'] ?? false;
    $code_analysis_completed = $_SESSION['exercise_completion']['module1']['section3_code_analysis_completed'] ?? false;
    $py_exercise_completed = $_SESSION['exercise_completion']['module1']['section3_python_exercise_completed'] ?? false;

    // Calculate completed exercises count for initial display
    $completed_exercises_count = 0;
    if ($mcq1_completed) $completed_exercises_count++;
    if ($mcq2_completed) $completed_exercises_count++;
    if ($code_analysis_completed) $completed_exercises_count++;
    if ($py_exercise_completed) $completed_exercises_count++;

    // Calculate initial section progress based on completed exercises
    $initial_section_progress = ($completed_exercises_count / 4) * 100;

    // If we have exercise results but no stored answer yet, update from results
    if (isset($exercise_results['mc1']) && empty($stored_mcq1_answer)) {
        $stored_mcq1_answer = $exercise_results['mc1']['user_answer'] ?? '';
    }

    if (isset($exercise_results['mc2']) && empty($stored_mcq2_answer)) {
        $stored_mcq2_answer = $exercise_results['mc2']['user_answer'] ?? '';
    }

    if (isset($exercise_results['code_analysis']) && empty($stored_code_analysis_answer)) {
        $stored_code_analysis_answer = $exercise_results['code_analysis']['user_answer'] ?? '';
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
        <title>Module 1: Introduction to Programming - Section 3: Python Installation and First Program - Impact Digital Academy</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            /* Reuse the same CSS styles from section2.php */
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
                        <p>Impact Digital Academy | Module 1 - Section 3</p>
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
                    <a href="module1_section2.php">Section 2</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Section 3</span>
                </div>
                <h1 class="section-title">Section 3: Python Installation and First Program</h1>
                <p class="section-subtitle">Learn how to install Python, set up your development environment, and write your first Python program</p>
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
                            echo round($progress['section3']);
                            ?>%
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="sectionProgressBar"
                            style="width: <?php echo round($progress['section3']); ?>%"></div>
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
                        <span id="exercisesCompleted"><?php echo $completed_exercises_count; ?>/4</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="exercisesProgressBar" style="width: <?php echo ($completed_exercises_count / 4) * 100; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-container">
            <main class="main-content">
                <!-- 1.3.1 Begin your Python journey -->
                <section class="content-section" id="begin-journey">
                    <h2><i class="fas fa-play-circle"></i> 1.3.1 Begin your Python journey</h2>
                    <h3>How to get Python and how to get to use it</h3>
                    
                    <p>There are several ways to get your own copy of Python 3, depending on the operating system you use.</p>

                    <div class="concept-box">
                        <h4><i class="fab fa-linux"></i> Linux Users</h4>
                        <p><strong>Linux users most probably have Python already installed</strong> – this is the most likely scenario, as Python's infrastructure is intensively used by many Linux OS components.</p>
                        <p>For example, some distributors may couple their specific tools together with the system and many of these tools, like package managers, are often written in Python. Some parts of graphical environments available in the Linux world may use Python, too.</p>
                        <p>If you're a Linux user, open the terminal/console, and type:</p>
                        <div class="code-example">
                            <div class="code-header">
                                <span>Terminal Command</span>
                            </div>
                            <div class="code-body">python3</div>
                        </div>
                        <p>at the shell prompt, press <em>Enter</em> and wait. If you see something like a Python prompt (>>>), then you don't have to do anything else.</p>
                        <p>If Python 3 is absent, then refer to your Linux documentation in order to find out how to use your package manager to download and install a new package – the one you need is named <strong>python3</strong> or its name begins with that.</p>
                    </div>

                    <div class="concept-box">
                        <h4><i class="fab fa-windows"></i> <i class="fab fa-apple"></i> Windows and macOS Users</h4>
                        <p>All non-Linux users can download a copy at <a href="https://www.python.org/downloads/" target="_blank">https://www.python.org/downloads/</a>.</p>
                    </div>

                    <!-- Multiple Choice Exercise 1 -->
                    <div class="exercise-container" id="mcq-exercise-1">
                        <div class="exercise-header">
                            <i class="fas fa-question-circle"></i>
                            <div class="exercise-title">Quick Check: Python Installation</div>
                            <div class="exercise-points">5 points</div>
                        </div>

                        <p><strong>Question:</strong> Which of the following statements about Python installation is TRUE?</p>

                        <form method="POST" id="mcqForm1">
                            <input type="hidden" name="exercise_type" value="section3_multiple_choice_1">
                            <div class="mcq-options">
                                <label class="mcq-option <?php echo ($stored_mcq1_answer === 'a') ? 'selected' : ''; ?>" onclick="selectOption(this, 'a')">
                                    <input type="radio" name="answer" value="a" required <?php echo ($stored_mcq1_answer === 'a') ? 'checked' : ''; ?>>
                                    Python is never pre-installed on any operating system
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq1_answer === 'b') ? 'selected' : ''; ?>" onclick="selectOption(this, 'b')">
                                    <input type="radio" name="answer" value="b" required <?php echo ($stored_mcq1_answer === 'b') ? 'checked' : ''; ?>>
                                    Linux users most probably have Python already installed
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq1_answer === 'c') ? 'selected' : ''; ?>" onclick="selectOption(this, 'c')">
                                    <input type="radio" name="answer" value="c" required <?php echo ($stored_mcq1_answer === 'c') ? 'checked' : ''; ?>>
                                    You must pay to download Python from the official website
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq1_answer === 'd') ? 'selected' : ''; ?>" onclick="selectOption(this, 'd')">
                                    <input type="radio" name="answer" value="d" required <?php echo ($stored_mcq1_answer === 'd') ? 'checked' : ''; ?>>
                                    Python only runs on Windows operating systems
                                </label>
                            </div>

                            <?php if (isset($exercise_results['mc1'])): ?>
                                <div
                                    class="feedback <?php echo $exercise_results['mc1']['correct'] ? 'correct' : 'incorrect'; ?>">
                                    <i
                                        class="fas fa-<?php echo $exercise_results['mc1']['correct'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $exercise_results['mc1']['feedback']; ?>
                                </div>
                            <?php elseif ($mcq1_completed): ?>
                                <div class="feedback correct">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>

                            <div class="exercise-actions">
                                <?php if (!$mcq1_completed): ?>
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Answer
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-reset" onclick="resetMCQ('mcqForm1', 'reset_mcq1')">
                                    <i class="fas fa-redo"></i> <?php echo $mcq1_completed ? 'Clear Answer' : 'Try Again'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- 1.3.2 How to download, install, and configure Python -->
                <section class="content-section" id="install-configure">
                    <h2><i class="fas fa-download"></i> 1.3.2 How to download, install, and configure Python</h2>
                    
                    <p>Because the browser tells the site you've entered the OS you use, the only step you have to take is to click the appropriate Python version you want.</p>
                    <p>In this case, select Python 3. The site always offers you the latest version of it.</p>

                    <div class="concept-box">
                        <h4><i class="fab fa-windows"></i> Windows Installation Tips</h4>
                        <p>If you're a <strong>Windows user</strong>, start the downloaded .exe file and follow all the steps.</p>
                        <p>Leave the default settings the installer suggests for now, with one exception - look at the checkbox named <strong>Add Python 3.x to PATH</strong> and check it.</p>
                        <p>This will make things easier.</p>
                    </div>

                    <div class="concept-box">
                        <h4><i class="fab fa-apple"></i> macOS Installation Tips</h4>
                        <p>If you're a <strong>macOS user</strong>, a version of Python 2 may already have been preinstalled on your computer, but since we will be working with Python 3, you will still need to download and install the relevant .pkg file from the Python site.</p>
                    </div>
                </section>

                <!-- 1.3.3 Starting your work with Python -->
                <section class="content-section" id="starting-work">
                    <h2><i class="fas fa-rocket"></i> 1.3.3 Starting your work with Python</h2>
                    
                    <p>Now that you have Python 3 installed, it's time to check if it works and make the very first use of it.</p>
                    <p>This will be a very simple procedure, but it should be enough to convince you that the Python environment is complete and functional.</p>
                    
                    <p>There are many ways of utilizing Python, especially if you're going to be a Python developer.</p>
                    <p>To start your work, you need the following tools:</p>
                    
                    <div class="definition-list">
                        <li>An <strong>editor</strong> which will support you in writing the code (it should have some special features, not available in simple tools); this dedicated editor will give you more than the standard OS equipment</li>
                        <li>A <strong>console</strong> in which you can launch your newly written code and stop it forcibly when it gets out of control</li>
                        <li>A tool named a <strong>debugger</strong>, able to launch your code step-by-step, which will allow you to inspect it at each moment of execution</li>
                    </div>
                    
                    <div class="concept-box">
                        <h4><i class="fas fa-id-card"></i> Introducing IDLE</h4>
                        <p>Besides its many useful components, the Python 3 standard installation contains a very simple but extremely useful application named <strong>IDLE</strong>.</p>
                        <p><strong>IDLE</strong> is an acronym: <strong>I</strong>ntegrated <strong>D</strong>evelopment and <strong>L</strong>earning <strong>E</strong>nvironment.</p>
                        <p>Navigate through your OS menus, find IDLE somewhere under Python 3.x and launch it. You should see a Python shell window where you can type and execute Python commands immediately.</p>
                    </div>

                    <!-- Multiple Choice Exercise 2 -->
                    <div class="exercise-container" id="mcq-exercise-2">
                        <div class="exercise-header">
                            <i class="fas fa-question-circle"></i>
                            <div class="exercise-title">Quick Check: IDLE</div>
                            <div class="exercise-points">5 points</div>
                        </div>

                        <p><strong>Question:</strong> What does IDLE stand for?</p>

                        <form method="POST" id="mcqForm2">
                            <input type="hidden" name="exercise_type" value="section3_multiple_choice_2">
                            <div class="mcq-options">
                                <label class="mcq-option <?php echo ($stored_mcq2_answer === 'a') ? 'selected' : ''; ?>" onclick="selectOption(this, 'a')">
                                    <input type="radio" name="answer" value="a" required <?php echo ($stored_mcq2_answer === 'a') ? 'checked' : ''; ?>>
                                    Integrated Design and Learning Environment
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq2_answer === 'b') ? 'selected' : ''; ?>" onclick="selectOption(this, 'b')">
                                    <input type="radio" name="answer" value="b" required <?php echo ($stored_mcq2_answer === 'b') ? 'checked' : ''; ?>>
                                    Interactive Development and Learning Editor
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq2_answer === 'c') ? 'selected' : ''; ?>" onclick="selectOption(this, 'c')">
                                    <input type="radio" name="answer" value="c" required <?php echo ($stored_mcq2_answer === 'c') ? 'checked' : ''; ?>>
                                    Integrated Development and Learning Environment
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq2_answer === 'd') ? 'selected' : ''; ?>" onclick="selectOption(this, 'd')">
                                    <input type="radio" name="answer" value="d" required <?php echo ($stored_mcq2_answer === 'd') ? 'checked' : ''; ?>>
                                    Interactive Design and Learning Editor
                                </label>
                            </div>

                            <?php if (isset($exercise_results['mc2'])): ?>
                                <div
                                    class="feedback <?php echo $exercise_results['mc2']['correct'] ? 'correct' : 'incorrect'; ?>">
                                    <i
                                        class="fas fa-<?php echo $exercise_results['mc2']['correct'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $exercise_results['mc2']['feedback']; ?>
                                </div>
                            <?php elseif ($mcq2_completed): ?>
                                <div class="feedback correct">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>

                            <div class="exercise-actions">
                                <?php if (!$mcq2_completed): ?>
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Answer
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-reset" onclick="resetMCQ('mcqForm2', 'reset_mcq2')">
                                    <i class="fas fa-redo"></i> <?php echo $mcq2_completed ? 'Clear Answer' : 'Try Again'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- 1.3.4 Your very first program before your first program... -->
                <section class="content-section" id="first-program">
                    <h2><i class="fas fa-code"></i> 1.3.4 Your very first program before your first program...</h2>
                    
                    <p>It is now time to write and run your first Python 3 program. It will be very simple, for now.</p>
                    
                    <h3>Creating Your First Python File</h3>
                    <p>The first step is to create a new source file and fill it with code. Click <em>File</em> in the IDLE menu and choose <em>New file</em>.</p>
                    <p>As you can see, IDLE opens a new window for you. You can use it to write and amend your code.</p>
                    <p>This is the <strong>editor window</strong>. Its only purpose is to be a workplace in which your source code is treated. Do not confuse the editor window with the shell window. They perform different functions.</p>
                    
                    <div class="concept-box">
                        <h4><i class="fas fa-save"></i> Saving Your File</h4>
                        <p>The editor window is currently untitled, but it's good practice to start work by naming the source file.</p>
                        <p>Click <em>File</em> (in the new window), then click <em>Save as...</em>, select a folder for the new file (the desktop is a good place for your first programming attempts) and chose a name for the new file.</p>
                        <p><strong>Note:</strong> don't set any extension for the file name you are going to use. Python needs its files to have the <strong>.py</strong> extension, so you should rely on the dialog window's defaults. Using the standard .py extension enables the OS to properly open these files.</p>
                    </div>
                    
                    <h3>Writing Your First Program</h3>
                    <p>Now put just one line into your newly opened and named editor window.</p>
                    <p>The line looks like this:</p>
                    
                    <div class="code-example">
                        <div class="code-header">
                            <span>Your First Python Program</span>
                        </div>
                        <div class="code-body">print("Hisssssss...")</div>
                    </div>
                    
                    <p>You can use the clipboard to copy the text into the file.</p>
                    <p>We're not going to explain the meaning of the program right now. You'll find a detailed discussion in the next chapter.</p>
                    
                    <div class="concept-box" style="background: #fff3cd; border-left-color: #ffc107;">
                        <h4><i class="fas fa-exclamation-triangle"></i> Important: Quotation Marks</h4>
                        <p>Take a closer look at the quotation marks. These are the simplest form of quotation marks (neutral, straight, dumb, etc.) commonly used in source files. Do not try to use typographic quotes (curved, curly, smart, etc.), used by advanced text processors, as Python doesn't accept them.</p>
                    </div>
                    
                    <h3>Running Your Program</h3>
                    <p>Save the file (<em>File</em> → <em>Save</em>) and run the program (<em>Run</em> → <em>Run Module</em>).</p>
                    <p>If everything goes okay and there are no mistakes in the code, the console window will show you the effects caused by running the program.</p>
                    <p>In this case, the program <strong>hisses</strong>.</p>
                    <p>Try to run it once again. And once more.</p>
                    <p>Now close both windows now and return to the desktop.</p>
                </section>

                <!-- 1.3.5 How to spoil and fix your code -->
                <section class="content-section" id="spoil-fix-code">
                    <h2><i class="fas fa-bug"></i> 1.3.5 How to spoil and fix your code</h2>
                    
                    <p>Now start IDLE again.</p>
                    
                    <div class="definition-list">
                        <li>Click <em>File</em>, <em>Open</em>, point to the file you saved previously and let IDLE read it in.</li>
                        <li>Try to run it again by pressing <em>F5</em> when the editor window is active.</li>
                    </div>
                    
                    <p>As you can see, IDLE is able to save your code and retrieve it when you need it again.</p>
                    
                    <div class="concept-box">
                        <h4><i class="fas fa-code"></i> IDLE's Helpful Feature</h4>
                        <p>IDLE contains one additional and helpful feature.</p>
                        <div class="definition-list">
                            <li>First, remove the closing parenthesis.</li>
                            <li>Then enter the parenthesis again.</li>
                        </div>
                        <p>Every time you put the closing parenthesis in your program, IDLE will show the part of the text limited with a pair of corresponding parentheses. This helps you to remember to <strong>place them in pairs</strong>.</p>
                    </div>
                    
                    <h3>Creating and Fixing Errors</h3>
                    <p>Remove the closing parenthesis again. The code becomes erroneous. It contains a syntax error now. IDLE should not let you run it.</p>
                    <p>Try to run the program again. IDLE will remind you to save the modified file. Follow the instructions.</p>
                    <p>Watch all the windows carefully.</p>
                    <p>A new window appears -- it says that the interpreter has encountered an EOF (<em>end-of-file</em>) although (in its opinion) the code should contain some more text.</p>
                    <p>The editor window shows clearly where it happened.</p>
                    
                    <h3>Fixing the Error</h3>
                    <p>Fix the code now. It should look like this:</p>
                    
                    <div class="code-example">
                        <div class="code-header">
                            <span>Fixed Python Program</span>
                        </div>
                        <div class="code-body">print("Hisssssss...")</div>
                    </div>
                    
                    <p>Run it to see if it "hisses" again.</p>
                    
                    <h3>Creating Another Error</h3>
                    <p>Let's spoil the code one more time. Remove one letter from the word <em>print</em>. Run the code by pressing <em>F5</em>. What happens now? As you can see, Python is not able to recognize the instruction.</p>
                    
                    <p>You may have noticed that the error message generated for the previous error is quite different from the first one.</p>
                    <p>This is because the nature of the error is <strong>different</strong> and the error is discovered at a <strong>different stage</strong> of interpretation.</p>
                    
                    <div class="concept-box">
                        <h4><i class="fas fa-bug"></i> Understanding Error Messages</h4>
                        <p>The editor window will not provide any useful information regarding the error, but the console windows might.</p>
                        <p>The message (in red) shows (in the subsequent lines):</p>
                        <div class="definition-list">
                            <li>The <strong>traceback</strong> (which is the path that the code traverses through different parts of the program -- you can ignore it for now, as it is empty in such a simple code)</li>
                            <li>The <strong>location of the error</strong> (the name of the file containing the error, line number and module name); note: the number may be misleading, as Python usually shows the place where it first notices the effects of the error, not necessarily the error itself</li>
                            <li>The <strong>content of the erroneous line</strong>; note: IDLE's editor window doesn't show line numbers, but it displays the current cursor location at the bottom-right corner; use it to locate the erroneous line in a long source code</li>
                            <li>The <strong>name of the error</strong> and a short explanation</li>
                        </div>
                    </div>
                    
                    <!-- Code Analysis Exercise -->
                    <div class="exercise-container" id="code-analysis-exercise">
                        <div class="exercise-header">
                            <i class="fas fa-search"></i>
                            <div class="exercise-title">Code Analysis: Error Identification</div>
                            <div class="exercise-points">10 points</div>
                        </div>

                        <p><strong>Question:</strong> What is wrong with the following Python code?</p>
                        
                        <div class="code-example" style="margin: 1rem 0;">
                            <div class="code-header">
                                <span>Problematic Python Code</span>
                            </div>
                            <div class="code-body">print("Hello, World!"</div>
                        </div>

                        <form method="POST" id="codeAnalysisForm">
                            <input type="hidden" name="exercise_type" value="section3_code_analysis">
                            <div class="mcq-options">
                                <label class="mcq-option <?php echo ($stored_code_analysis_answer === 'a') ? 'selected' : ''; ?>" onclick="selectOption(this, 'a')">
                                    <input type="radio" name="code_issue" value="a" required <?php echo ($stored_code_analysis_answer === 'a') ? 'checked' : ''; ?>>
                                    The word "print" is misspelled
                                </label>
                                <label class="mcq-option <?php echo ($stored_code_analysis_answer === 'b') ? 'selected' : ''; ?>" onclick="selectOption(this, 'b')">
                                    <input type="radio" name="code_issue" value="b" required <?php echo ($stored_code_analysis_answer === 'b') ? 'checked' : ''; ?>>
                                    Missing closing parenthesis
                                </label>
                                <label class="mcq-option <?php echo ($stored_code_analysis_answer === 'c') ? 'selected' : ''; ?>" onclick="selectOption(this, 'c')">
                                    <input type="radio" name="code_issue" value="c" required <?php echo ($stored_code_analysis_answer === 'c') ? 'checked' : ''; ?>>
                                    Using typographic quotation marks
                                </label>
                                <label class="mcq-option <?php echo ($stored_code_analysis_answer === 'd') ? 'selected' : ''; ?>" onclick="selectOption(this, 'd')">
                                    <input type="radio" name="code_issue" value="d" required <?php echo ($stored_code_analysis_answer === 'd') ? 'checked' : ''; ?>>
                                    The message should be in single quotes
                                </label>
                            </div>

                            <?php if (isset($exercise_results['code_analysis'])): ?>
                                <div
                                    class="feedback <?php echo $exercise_results['code_analysis']['correct'] ? 'correct' : 'incorrect'; ?>">
                                    <i
                                        class="fas fa-<?php echo $exercise_results['code_analysis']['correct'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $exercise_results['code_analysis']['feedback']; ?>
                                </div>
                            <?php elseif ($code_analysis_completed): ?>
                                <div class="feedback correct">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>

                            <div class="exercise-actions">
                                <?php if (!$code_analysis_completed): ?>
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Answer
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-reset" onclick="resetCodeAnalysis('codeAnalysisForm')">
                                    <i class="fas fa-redo"></i> <?php echo $code_analysis_completed ? 'Clear Answer' : 'Try Again'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <h3>Experiment and Learn</h3>
                    <p>Experiment with creating new files and running your code. Try to output a different message to the screen, e.g., <code>roar!</code>, <code>meow</code>, or even maybe an <code>oink!</code>. Try to spoil and fix your code -- see what happens.</p>
                </section>

                <!-- Python Sandbox Exercise -->
                <section class="content-section" id="python-exercise">
                    <h2><i class="fas fa-laptop-code"></i> Hands-on Python Exercise: Your First Program</h2>

                    <div class="sandbox-container">
                        <div class="sandbox-title">
                            <i class="fas fa-code"></i>
                            <h3>Create Your First Python Program</h3>
                        </div>

                        <p>Let's practice what you've learned. Create a Python program that prints a custom message. Follow these steps:</p>
                        
                        <div class="definition-list">
                            <li>Write a program that prints "Hello, Python!"</li>
                            <li>Then modify it to print your own creative message</li>
                            <li>Intentionally create an error (like missing parenthesis) and see the error message</li>
                            <li>Fix the error and run the program successfully</li>
                        </div>

                        <form method="POST" id="pythonForm1">
                            <input type="hidden" name="exercise_type" value="section3_python_exercise_1">

                            <div class="sandbox-editor">
                                <div class="editor-header">
                                    <span><i class="fab fa-python"></i> my_first_program.py</span>
                                    <span>Python 3.10</span>
                                </div>
                                <div class="editor-body" id="pythonEditor" contenteditable="true" spellcheck="false">
                                    <?php
                                    if (!empty($stored_python_code)) {
                                        echo htmlspecialchars($stored_python_code);
                                    } else {
                                        echo '# Your First Python Program
# Follow the instructions to create and test your code

# Step 1: Print a simple message
print("Hello, Python!")

# Step 2: Print your own creative message
print("Welcome to Python programming!")

# Step 3: Try creating an error (uncomment the next line to see)
# print("This line has an error

# Step 4: Fix the error and add more messages
print("Python is fun to learn!")
print("I can write multiple print statements.")';
                                    }
                                    ?>
                                </div>
                            </div>

                            <textarea name="python_code" id="pythonCodeInput" style="display:none;"><?php echo htmlspecialchars($stored_python_code); ?></textarea>

                            <div class="sandbox-controls">
                                <button type="button" class="btn btn-primary" onclick="runPythonCode()">
                                    <i class="fas fa-play"></i> Run Code
                                </button>
                                <?php if (!$py_exercise_completed): ?>
                                    <button type="button" class="btn btn-reset" onclick="resetPythonEditor()">
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-submit">
                                    <i class="fas fa-save"></i> <?php echo $py_exercise_completed ? 'Update Code' : 'Save & Continue'; ?>
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
                            <?php elseif ($py_exercise_completed): ?>
                                <div class="feedback correct" style="margin-top: 1rem;">
                                    <i class="fas fa-check-circle"></i>
                                    You have completed this exercise.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="concept-box" style="background: #e9f7fe; border-left-color: #306998; margin-top: 2rem;">
                        <h4><i class="fas fa-lightbulb"></i> Key Takeaways from Section 3</h4>
                        <p>You've learned the essential steps to start programming in Python:</p>
                        <div class="definition-list">
                            <li>Python installation varies by operating system (Linux often has it pre-installed)</li>
                            <li>IDLE (Integrated Development and Learning Environment) comes with Python</li>
                            <li>Your first program uses the <code>print()</code> function to display text</li>
                            <li>Python files should have the <code>.py</code> extension</li>
                            <li>Understanding error messages helps you debug your code</li>
                            <li>Always use straight quotation marks in Python code</li>
                            <li>Experimenting with code (including breaking it) is a great way to learn</li>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        <!-- Navigation Buttons -->
        <div class="navigation-buttons">
            <a href="module1_section2.php" class="nav-btn">
                <i class="fas fa-arrow-left"></i> Previous: Introduction to Python
            </a>
            <a href="module1_test.php" class="nav-btn" id="nextSectionBtn">
                Next Section: Module 1 Completion Test <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Module 1 Progress</h3>
                    <p>Section 3:
                        <?php
                        echo round($progress['section3']);
                        ?>% complete
                    </p>
                    <p><strong>Next:</strong> Section 4: Module 1 Completion Test</p>
                </div>

                
                <div class="footer-section">
                    <h3>About This Section</h3>
                    <p>Section 3 covers Python installation, setting up your development environment with IDLE, writing your first Python program, and understanding basic error messages.</p>
                </div>
            </div>

            <div class="copyright">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. Python Essentials 1 - Module 1 - Section 3</p>
                <p style="margin-top: 0.5rem; font-size: 0.8rem;">Python and IDLE are trademarks of the Python Software Foundation</p>
            </div>
        </footer>

        <script>
            // Exercise completion tracking
            let completedExercises = <?php echo $completed_exercises_count; ?>;
            const totalExercises = 4;

            // Update progress bars on page load
            function updateProgress() {
                // Count completed exercises
                completedExercises = 0;

                // Check which exercises have feedback (completed)
                if (document.querySelector('#mcqForm1 .feedback')) completedExercises++;
                if (document.querySelector('#mcqForm2 .feedback')) completedExercises++;
                if (document.querySelector('#codeAnalysisForm .feedback')) completedExercises++;
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
                const moduleProgress = 50 + (sectionProgress * 0.5); // Section 3 contributes 50% to module progress
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

            function resetMCQ(formId, resetType) {
                // Create a form for reset
                const resetForm = document.createElement('form');
                resetForm.method = 'POST';
                resetForm.style.display = 'none';

                const resetInput = document.createElement('input');
                resetInput.type = 'hidden';
                resetInput.name = 'reset_type';
                resetInput.value = resetType;
                resetForm.appendChild(resetInput);

                document.body.appendChild(resetForm);
                resetForm.submit();
            }

            function resetCodeAnalysis(formId) {
                // Create a form for reset
                const resetForm = document.createElement('form');
                resetForm.method = 'POST';
                resetForm.style.display = 'none';

                const resetInput = document.createElement('input');
                resetInput.type = 'hidden';
                resetInput.name = 'reset_type';
                resetInput.value = 'reset_code_analysis';
                resetForm.appendChild(resetInput);

                document.body.appendChild(resetForm);
                resetForm.submit();
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
                    if (pythonCode.includes('print(') && pythonCode.includes('Hello, Python!')) {
                        outputDiv.innerHTML = `<div style="color: var(--success);">
                        <i class="fas fa-check-circle"></i> <strong>Success! Output:</strong><br>
                        <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; font-family: 'Courier New', monospace;">
Hello, Python!
Welcome to Python programming!
Python is fun to learn!
I can write multiple print statements.</pre>
                    </div>`;
                        document.getElementById('pythonOutput').style.backgroundColor = '#d4edda';
                    } else if (pythonCode.includes('print')) {
                        outputDiv.innerHTML = `<div style="color: var(--warning);">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Code executed successfully</strong><br>
                        Your code runs, but make sure it includes the required "Hello, Python!" message.
                    </div>`;
                        document.getElementById('pythonOutput').style.backgroundColor = '#fff3cd';
                    } else {
                        outputDiv.innerHTML = `<div style="color: var(--danger);">
                        <i class="fas fa-times-circle"></i> <strong>Incomplete code</strong><br>
                        Make sure your code includes print statements to display messages.
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