<?php
// section1.php - Python Essentials 1: Module 1 - Introduction to Programming
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
                    $stmt->bind_param('iddddd', 
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
            
            $stmt->bind_param('issssddss', 
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
 * Get user display name - FIXED
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
                'mcq1_answer' => '',
                'tf_answers' => [],
                'python_code' => ''
            ]
        ];
    }

    // Initialize session storage for exercise completion
    if (!isset($_SESSION['exercise_completion'])) {
        $_SESSION['exercise_completion'] = [
            'module1' => [
                'mcq1_completed' => false,
                'tf1_completed' => false,
                'py1_completed' => false
            ]
        ];
    }

    // Handle exercise submissions
    $exercise_results = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exercise_type'])) {
        // Update section progress first
        $accessController->updateProgress('section1', true);

        switch ($_POST['exercise_type']) {
            case 'multiple_choice_1':
                $user_answer = $_POST['answer'] ?? '';
                $correct = ($user_answer === 'b');
                $exercise_results['mc1'] = [
                    'correct' => $correct,
                    'user_answer' => $user_answer,
                    'correct_answer' => 'b',
                    'feedback' => $correct ?
                        "Correct! The CPU can only understand machine language (binary code)." :
                        "Incorrect. The correct answer is: The CPU can only understand machine language (binary code)."
                ];
                
                // Store answer in session
                $_SESSION['exercise_answers']['module1']['mcq1_answer'] = $user_answer;
                $_SESSION['exercise_completion']['module1']['mcq1_completed'] = true;
                
                // Save to database
                $accessController->saveExerciseSubmission(
                    'multiple_choice',
                    'mcq1',
                    $user_answer,
                    $correct,
                    $correct ? 5 : 0,
                    5
                );
                break;

            case 'true_false_1':
                $answers = $_POST['tf_answers'] ?? [];
                $correct_answers = ['q1' => 'true', 'q2' => 'false', 'q3' => 'true'];
                $score = 0;
                foreach ($answers as $q => $a) {
                    if (isset($correct_answers[$q]) && $a === $correct_answers[$q]) {
                        $score++;
                    }
                }
                $percentage = round(($score / 3) * 100);
                $is_passing = $percentage >= 70;
                
                $exercise_results['tf1'] = [
                    'score' => $score,
                    'total' => 3,
                    'percentage' => $percentage,
                    'is_passing' => $is_passing
                ];
                
                // Store answers in session
                $_SESSION['exercise_answers']['module1']['tf_answers'] = $answers;
                $_SESSION['exercise_completion']['module1']['tf1_completed'] = true;
                
                // Save to database
                $accessController->saveExerciseSubmission(
                    'true_false',
                    'tf1',
                    $answers,
                    $is_passing,
                    $score * 2, // 2 points per question
                    6
                );
                break;

            case 'python_exercise_1':
                $user_code = $_POST['python_code'] ?? '';
                $exercise_results['py1'] = [
                    'submitted' => true,
                    'code' => $user_code
                ];
                
                // Store code in session
                $_SESSION['exercise_answers']['module1']['python_code'] = $user_code;
                $_SESSION['exercise_completion']['module1']['py1_completed'] = true;
                
                // Save to database
                $accessController->saveExerciseSubmission(
                    'python_code',
                    'py1',
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
                $_SESSION['exercise_answers']['module1']['mcq1_answer'] = '';
                $_SESSION['exercise_completion']['module1']['mcq1_completed'] = false;
                break;
            case 'reset_tf':
                $_SESSION['exercise_answers']['module1']['tf_answers'] = [];
                $_SESSION['exercise_completion']['module1']['tf1_completed'] = false;
                break;
            case 'reset_python':
                $_SESSION['exercise_answers']['module1']['python_code'] = '';
                $_SESSION['exercise_completion']['module1']['py1_completed'] = false;
                break;
        }
        
        // Update progress after reset
        $accessController->updateProgress('section1', false);
        $progress = $accessController->getModuleProgress();
    }
    
    // Get stored answers from session
    $stored_mcq_answer = $_SESSION['exercise_answers']['module1']['mcq1_answer'] ?? '';
    $stored_tf_answers = $_SESSION['exercise_answers']['module1']['tf_answers'] ?? [];
    $stored_python_code = $_SESSION['exercise_answers']['module1']['python_code'] ?? '';
    
    // Get completion status from session
    $mcq_completed = $_SESSION['exercise_completion']['module1']['mcq1_completed'] ?? false;
    $tf_completed = $_SESSION['exercise_completion']['module1']['tf1_completed'] ?? false;
    $py_completed = $_SESSION['exercise_completion']['module1']['py1_completed'] ?? false;
    
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
        <title>Module 1: Introduction to Programming - Python Essentials 1 - Impact Digital Academy</title>
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
            /* Add to your existing CSS */
.sandbox-stats {
    display: flex;
    gap: 15px;
    align-items: center;
    font-size: 0.85rem;
}

.sandbox-stats i {
    margin-right: 5px;
}

#pythonOutput pre {
    margin: 0;
    padding: 10px;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 3px;
    font-family: 'Fira Code', 'Courier New', monospace;
    font-size: 0.9rem;
    line-height: 1.4;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Syntax highlighting in editor */
#pythonEditor {
    color: #d4d4d4;
}

#pythonEditor .comment {
    color: #6a9955;
}

#pythonEditor .keyword {
    color: #569cd6;
}

#pythonEditor .string {
    color: #ce9178;
}

#pythonEditor .function {
    color: #dcdcaa;
}

#pythonEditor .number {
    color: #b5cea8;
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
                        <p>Impact Digital Academy | Module 1</p>
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
                    <span>Section 1</span>
                </div>
                <h1 class="section-title">Section 1: Introduction to Programming</h1>
                <p class="section-subtitle">Understanding how computers execute programs and the fundamental concepts of
                    programming languages</p>
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
                            echo round($progress['section1']);
                            ?>%
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="sectionProgressBar"
                            style="width: <?php echo round($progress['section1']); ?>%"></div>
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
                <!-- How Programs Work -->
                <section class="content-section" id="how-programs-work">
                    <h2><i class="fas fa-play-circle"></i> 1.1.1 How does a computer program work?</h2>

                    <p>A program makes a computer usable. Without a program, a computer, even the most powerful one, is
                        nothing more than an object. Similarly, without a player, a piano is nothing more than a wooden box.
                    </p>

                    <p>Computers are able to perform very complex tasks, but this ability is not innate. A computer's nature
                        is quite different. It can execute only extremely simple operations. For example, a computer cannot
                        understand the value of a complicated mathematical function by itself, although this isn't beyond
                        the realms of possibility in the near future.</p>

                    <div class="concept-box">
                        <h4>Key Insight</h4>
                        <p>Contemporary computers can only evaluate the results of very fundamental operations, like adding
                            or dividing, but they can do it very fast, and can repeat these actions virtually any number of
                            times.</p>
                    </div>

                    <!-- Multiple Choice Exercise -->
                    <div class="exercise-container" id="mcq-exercise-1">
                        <div class="exercise-header">
                            <i class="fas fa-question-circle"></i>
                            <div class="exercise-title">Quick Check: Computer Understanding</div>
                            <div class="exercise-points">5 points</div>
                        </div>

                        <p><strong>Question:</strong> Which of the following statements is TRUE about how computers
                            understand programs?</p>

                        <form method="POST" id="mcqForm1">
                            <input type="hidden" name="exercise_type" value="multiple_choice_1">
                            <div class="mcq-options">
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'a') ? 'selected' : ''; ?>" onclick="selectOption(this, 'a')">
                                    <input type="radio" name="answer" value="a" required <?php echo ($stored_mcq_answer === 'a') ? 'checked' : ''; ?>>
                                    Computers can understand high-level programming languages directly
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'b') ? 'selected' : ''; ?>" onclick="selectOption(this, 'b')">
                                    <input type="radio" name="answer" value="b" required <?php echo ($stored_mcq_answer === 'b') ? 'checked' : ''; ?>>
                                    The CPU can only understand machine language (binary code)
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'c') ? 'selected' : ''; ?>" onclick="selectOption(this, 'c')">
                                    <input type="radio" name="answer" value="c" required <?php echo ($stored_mcq_answer === 'c') ? 'checked' : ''; ?>>
                                    Computers have innate intelligence to solve complex problems
                                </label>
                                <label class="mcq-option <?php echo ($stored_mcq_answer === 'd') ? 'selected' : ''; ?>" onclick="selectOption(this, 'd')">
                                    <input type="radio" name="answer" value="d" required <?php echo ($stored_mcq_answer === 'd') ? 'checked' : ''; ?>>
                                    Programs are unnecessary for basic computer operation
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

                <!-- Natural vs Programming Languages -->
                <section class="content-section" id="natural-vs-programming">
                    <h2><i class="fas fa-language"></i> 1.1.2 Natural languages vs. programming languages</h2>

                    <p>A language is a means (and a tool) for expressing and recording thoughts. There are many languages
                        all around us. Some of them require neither speaking nor writing, such as body language; it's
                        possible to express your deepest feelings very precisely without saying a word.</p>

                    <p>Another language you use each day is your mother tongue, which you use to manifest your will and to
                        ponder reality. Computers have their own language, too, called <strong>machine language</strong>,
                        which is very rudimentary.</p>

                    <div class="concept-box">
                        <h4>Computer Intelligence</h4>
                        <p>A computer, even the most technically sophisticated, is devoid of even a trace of intelligence.
                            You could say that it is like a well-trained dog - it responds only to a predetermined set of
                            known commands. The commands it recognizes are very simple.</p>
                    </div>

                    <p>A complete set of known commands is called an <strong>instruction list</strong>,
                    sometimes abbreviated to <strong>IL</strong>. Different types of computers may vary
                    depending on the size of their ILs, and the instructions could be
                    completely different in different models.</p>

                    <p><strong>Note:</strong> machine languages are developed by humans.
                    No computer is currently capable of creating a new language. However,
                    that may change soon. Just as people use a number of very different
                    languages, machines have many different languages, too. The difference,
                    though, is that human languages developed naturally.</p>

                    <p>Moreover, they are still evolving, and new words are created every day
                    as old words disappear. These languages are called <strong>natural languages</strong>.</p>

                    <!-- True/False Exercise -->
                    <div class="exercise-container" id="tf-exercise-1">
                        <div class="exercise-header">
                            <i class="fas fa-check-double"></i>
                            <div class="exercise-title">True or False</div>
                            <div class="exercise-points">6 points</div>
                        </div>

                        <p><strong>Instructions:</strong> Mark each statement as True or False.</p>

                        <form method="POST" id="tfForm1">
                            <input type="hidden" name="exercise_type" value="true_false_1">
                            <div class="true-false-grid">
                                <div class="tf-question">
                                    <p><strong>1.</strong> Machine language is the native language of computers.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q1']) && $stored_tf_answers['q1'] === 'true') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q1', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q1']) && $stored_tf_answers['q1'] === 'false') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q1', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q1]" id="q1_answer" value="<?php echo $stored_tf_answers['q1'] ?? ''; ?>">
                                </div>

                                <div class="tf-question">
                                    <p><strong>2.</strong> Computers have innate intelligence like humans.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q2']) && $stored_tf_answers['q2'] === 'true') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q2', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q2']) && $stored_tf_answers['q2'] === 'false') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q2', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q2]" id="q2_answer" value="<?php echo $stored_tf_answers['q2'] ?? ''; ?>">
                                </div>

                                <div class="tf-question">
                                    <p><strong>3.</strong> High-level programming languages are closer to human language
                                        than machine language.</p>
                                    <div class="tf-options">
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q3']) && $stored_tf_answers['q3'] === 'true') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q3', 'true')">True</div>
                                        <div class="tf-option <?php echo (isset($stored_tf_answers['q3']) && $stored_tf_answers['q3'] === 'false') ? 'selected' : ''; ?>" 
                                             onclick="selectTF(this, 'q3', 'false')">False</div>
                                    </div>
                                    <input type="hidden" name="tf_answers[q3]" id="q3_answer" value="<?php echo $stored_tf_answers['q3'] ?? ''; ?>">
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

                <!-- What Makes a Language? -->
                <section class="content-section" id="what-makes-language">
                    <h2><i class="fas fa-book"></i> 1.1.3 What makes a language?</h2>

                    <p>We can say that each language (machine or natural, it doesn't matter)
                    consists of the following elements:</p>

                    <div class="definition-list">
                        <li>
                            <strong>An Alphabet</strong> - a set of symbols used to build words of a certain language 
                            (e.g., the Latin alphabet for English, the Cyrillic alphabet for Russian, Kanji for 
                            Japanese, and so on)
                        </li>
                        <li>
                            <strong>A Lexis</strong> - (aka a dictionary) a set of words the language offers its users 
                            (e.g., the word "computer" comes from the English language dictionary, while "cmoptrue" 
                            doesn't; the word "chat" is present both in English and French dictionaries, but their 
                            meanings are different)
                        </li>
                        <li>
                            <strong>A Syntax</strong> - a set of rules (formal or informal, written or felt intuitively) 
                            used to determine if a certain string of words forms a valid sentence (e.g., "I am a python" 
                            is a syntactically correct phrase, while "I a python am" isn't)
                        </li>
                        <li>
                            <strong>Semantics</strong> - a set of rules determining if a certain phrase makes sense 
                            (e.g., "I ate a doughnut" makes sense, but "A doughnut ate me" doesn't)
                        </li>
                    </div>
                </section>

                <!-- Machine Language vs High-Level Language -->
                <section class="content-section" id="machine-vs-highlevel">
                    <h2><i class="fas fa-layer-group"></i> 1.1.4 Machine language vs. high-level language</h2>

                    <p>The IL is, in fact, <strong>the alphabet of a machine language</strong>. This is the
                    simplest and most primary set of symbols we can use to give commands to
                    a computer. It's the computer's mother tongue.</p>

                    <p>Unfortunately, this mother tongue is a far cry from a human mother
                    tongue. We both (computers and humans) need something else, a common
                    language for computers and humans, or a bridge between the two different
                    worlds.</p>

                    <div class="concept-box">
                        <h4>High-Level Programming Languages</h4>
                        <p>We need a language in which humans can write their programs and a
                        language that computers may use to execute the programs, one that is far
                        more complex than machine language and yet far simpler than natural language.</p>
                    </div>

                    <p>Such languages are often called high-level programming languages. They
                    are at least somewhat similar to natural ones in that they use symbols,
                    words and conventions readable to humans. These languages enable humans
                    to express commands to computers that are much more complex than those
                    offered by ILs.</p>

                    <p>A program written in a high-level programming language is called
                    a <strong>source code</strong> (in contrast to the machine code executed by
                    computers). Similarly, the file containing the source code is called
                    the <strong>source file</strong>.</p>
                </section>

                <!-- Compilation vs Interpretation -->
                <section class="content-section" id="compilation-vs-interpretation">
                    <h2><i class="fas fa-cogs"></i> 1.1.5 Compilation vs. Interpretation</h2>

                    <p>Computer programming is the act of composing the selected programming
                    language's elements in the order that will cause the desired effect.
                    The effect could be different in every specific case -- it's up to the
                    programmer's imagination, knowledge and experience.</p>

                    <p>Of course, such a composition has to be correct in many senses:</p>

                    <div class="definition-list">
                        <li><strong>Alphabetically</strong> -- a program needs to be written in a recognizable
                            script, such as Roman, Cyrillic, etc.</li>
                        <li><strong>Lexically</strong> -- each programming language has its dictionary and you
                            need to master it; thankfully, it's much simpler and smaller than the
                            dictionary of any natural language;</li>
                        <li><strong>Syntactically</strong> -- each language has its rules and they must be
                            obeyed;</li>
                        <li><strong>Semantically</strong> -- the program has to make sense.</li>
                    </div>

                    <div class="concept-box">
                        <h4>Programmer Mistakes</h4>
                        <p>Unfortunately, a programmer can also make mistakes with each of the
                        above four senses. Each of them can cause the program to become
                        completely useless.</p>
                    </div>

                    <p>Let's assume that you've successfully written a program. How do we
                    persuade the computer to execute it? You have to render your program
                    into machine language. Luckily, the translation can be done by a
                    computer itself, making the whole process fast and efficient.</p>

                    <p>There are two different ways of <strong>transforming a program from a
                    high-level programming language into machine language</strong>:</p>

                    <table class="comparison-table">
                        <tr>
                            <th>Compilation</th>
                            <th>Interpretation</th>
                        </tr>
                        <tr>
                            <td>Entire program is translated at once</td>
                            <td>Program is translated line by line during execution</td>
                        </tr>
                        <tr>
                            <td>Produces standalone executable file</td>
                            <td>Requires interpreter to run</td>
                        </tr>
                        <tr>
                            <td>Generally faster execution</td>
                            <td>Easier debugging and testing</td>
                        </tr>
                    </table>

                    <p>Due to some very fundamental reasons, a particular high-level
                    programming language is designed to fall into one of these two
                    categories. There are very few languages that can be both compiled and
                    interpreted. Usually, a programming language is projected with this
                    factor in its constructors' minds -- will it be compiled or interpreted?</p>
                </section>

                <!-- What Does the Interpreter Do? -->
                <section class="content-section" id="what-does-interpreter-do">
                    <h2><i class="fas fa-terminal"></i> 1.1.6 What does the interpreter do?</h2>

                    <p>Let's assume once more that you have written a program. Now, it exists
                    as a <strong>computer file</strong>: a computer program is actually a piece of text,
                    so the source code is usually placed in <strong>text files</strong>.</p>

                    <div class="concept-box">
                        <h4>Important Note</h4>
                        <p>It has to be <strong>pure text</strong>, without any decorations like different
                        fonts, colors, embedded images or other media.</p>
                    </div>

                    <p>Now you have to invoke the interpreter and let it read your source file.
                    The interpreter reads the source code in a way that is common in Western
                    culture: from top to bottom and from left to right. There are some
                    exceptions - they'll be covered later in the course.</p>

                    <p>First of all, the interpreter checks if all subsequent lines are correct
                    (using the four aspects covered earlier). If the interpreter finds an error, 
                    it finishes its work immediately. The only result in this case is an 
                    <strong>error message</strong>.</p>

                    <p>The interpreter will inform you where the error is located and what
                    caused it. However, these messages may be misleading, as the interpreter
                    isn't able to follow your exact intentions, and may detect errors at
                    some distance from their real causes.</p>

                    <div class="concept-box">
                        <h4>Error Detection Example</h4>
                        <p>For example, if you try to use an entity of an unknown name, it
                        will cause an error, but the error will be discovered in the place
                        where it tries to use the entity, not where the new entity's name was
                        introduced. In other words, the actual reason is usually located a
                        little earlier in the code.</p>
                    </div>

                    <p>If the line looks good, the interpreter tries to execute it (note: each
                    line is usually executed separately, so the trio "read-check-execute"
                    can be repeated many times - more times than the actual number of lines
                    in the source file, as some parts of the code may be executed more than
                    once).</p>

                    <p>It is also possible that a significant part of the code may be executed
                    successfully before the interpreter finds an error. This is normal
                    behavior in this execution model.</p>
                </section>

                <!-- Compilation vs Interpretation Advantages/Disadvantages -->
                <section class="content-section" id="advantages-disadvantages">
                    <h2><i class="fas fa-balance-scale"></i> 1.1.7 Compilation vs. Interpretation -- Advantages and Disadvantages</h2>

                    <p>You may ask now: which is better? The "compiling" model or the
                    "interpreting" model? There is no obvious answer. If there had been,
                    one of these models would have ceased to exist a long time ago. Both of
                    them have their advantages and their disadvantages.</p>

                    <table class="comparison-table">
                        <tr>
                            <th>Compilation Advantages</th>
                            <th>Interpretation Advantages</th>
                        </tr>
                        <tr>
                            <td>Faster execution</td>
                            <td>Easier debugging</td>
                        </tr>
                        <tr>
                            <td>Standalone executables</td>
                            <td>Platform independence</td>
                        </tr>
                        <tr>
                            <td>Better optimization</td>
                            <td>Immediate feedback</td>
                        </tr>
                        <tr>
                            <td>Protection of source code</td>
                            <td>Easier to learn and test</td>
                        </tr>
                    </table>

                    <div class="concept-box">
                        <h4>What This Means for Python</h4>
                        <p>Python is an <strong>interpreted language</strong>. This means that it inherits all
                        the described advantages and disadvantages. Of course, it adds some of
                        its unique features to both sets.</p>
                    </div>

                    <p>If you want to program in Python, you'll need the <strong>Python
                    interpreter</strong>. You won't be able to run your code without it.
                    Fortunately, <strong>Python is free</strong>. This is one of its most important
                    advantages.</p>

                    <p>Due to historical reasons, languages designed to be utilized in the
                    interpretation manner are often called <strong>scripting languages</strong>, while
                    the source programs encoded using them are called <strong>scripts</strong>.</p>

                    <div class="concept-box" style="background: #e9f7fe; border-left-color: #306998;">
                        <h4><i class="fas fa-star"></i> Key Takeaway</h4>
                        <p>Now that you understand the fundamentals of programming languages,
                        compilation vs. interpretation, and why Python is an interpreted language,
                        you're ready to start learning Python itself!</p>
                    </div>
                </section>

                <!-- Python Sandbox Exercise -->
<section class="content-section" id="python-exercise">
    <h2><i class="fas fa-code"></i> Hands-on Python Exercise</h2>

    <div class="sandbox-container">
        <div class="sandbox-title">
            <i class="fas fa-laptop-code"></i>
            <h3>Write Your First Python Program</h3>
        </div>

        <p>Practice what you've learned by writing a simple Python program. The task is to calculate the
            area of a rectangle.</p>

        <form method="POST" id="pythonForm1">
            <input type="hidden" name="exercise_type" value="python_exercise_1">

            <div class="sandbox-editor">
                <div class="editor-header">
                    <span><i class="fab fa-python"></i> rectangle_area.py</span>
                    <span>Python 3.10</span>
                </div>
                <div class="editor-body" id="pythonEditor" contenteditable="true" spellcheck="false">
<?php 
if (!empty($stored_python_code)) {
    echo htmlspecialchars($stored_python_code);
} else {
    echo '# Calculate area of a rectangle
# You can change these values
length = 10
width = 5

# Calculate area (you can modify the formula)
area = length * width

# Print the result
print(f"The area of the rectangle is: {area}")

# Try modifying the values or formula!
# Example: area = length * width * 2  # for perimeter
# Example: length = 20  # change the length';
}
?>
                </div>
            </div>

            <textarea name="python_code" id="pythonCodeInput" style="display:none;"><?php echo htmlspecialchars($stored_python_code); ?></textarea>

            <div class="sandbox-controls">
                <button type="button" class="btn btn-primary" onclick="runPythonCode()">
                    <i class="fas fa-play"></i> Run Code
                </button>
                <button type="button" class="btn btn-primary" onclick="showHint()">
                    <i class="fas fa-lightbulb"></i> Show Hint
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
                style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 5px; border: 1px solid #dee2e6; min-height: 100px; font-family: 'Courier New', monospace; white-space: pre-wrap;">
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
</section>
            </main>
        </div>

        <!-- Navigation Buttons -->
        <div class="navigation-buttons">
            <a href="<?php echo BASE_URL; ?>index.php" class="nav-btn">
                <i class="fas fa-arrow-left"></i> Back to Course
            </a>
            <a href="module1_section2.php" class="nav-btn" id="nextSectionBtn">
                Next Section: Python Installation <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Module 1 Progress</h3>
                    <p>Section 1:
                        <?php 
                        echo round($progress['section1']);
                        ?>% complete
                    </p>
                    <p><strong>Next:</strong> Section 2: Python Installation</p>
                </div>

                
                <div class="footer-section">
                    <h3>About This Module</h3>
                    <p>Module 1 introduces fundamental programming concepts that form the foundation for Python programming.
                    </p>
                </div>
            </div>

            <div class="copyright">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. Python Essentials 1 - Module 1 - Section 1</p>
                <p style="margin-top: 0.5rem; font-size: 0.8rem;">All code exercises run in secure sandbox environment</p>
            </div>
        </footer>

        <script>
    // Exercise completion tracking
    let completedExercises = <?php echo $completed_exercises_count; ?>;
    const totalExercises = 3;
    let isExecuting = false;
    let lastExecutionTime = null;

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
        const moduleProgress = 25 + (sectionProgress * 0.75); // Section 1 contributes 75% to module progress
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

    // Python Sandbox Functions
    function getPythonCode() {
        const editor = document.getElementById('pythonEditor');
        return editor.textContent;
    }

    function setPythonCode(code) {
        const editor = document.getElementById('pythonEditor');
        editor.textContent = code;
        updatePythonInput();
        updateCodeStats();
    }

    function updatePythonInput() {
        const code = getPythonCode();
        document.getElementById('pythonCodeInput').value = code;
    }

    function updateCodeStats() {
        const code = getPythonCode();
        const lines = code.split('\n').filter(line => line.trim().length > 0);
        const lineCount = lines.length;
        const charCount = code.length;
        
        if (document.getElementById('lineCount')) {
            document.getElementById('lineCount').textContent = `${lineCount} line${lineCount !== 1 ? 's' : ''}`;
        }
        if (document.getElementById('charCount')) {
            document.getElementById('charCount').textContent = `${charCount} character${charCount !== 1 ? 's' : ''}`;
        }
    }

    function formatCode() {
        const code = getPythonCode();
        // Simple formatting - remove multiple empty lines
        const formatted = code.split('\n')
            .map(line => line.replace(/\s+$/, '')) // Trim trailing whitespace
            .filter((line, index, arr) => !(line === '' && arr[index - 1] === '')) // Remove consecutive empty lines
            .join('\n');
        
        setPythonCode(formatted);
        
        // Show notification
        showToast('Code formatted successfully', 'success');
    }

    function resetPythonEditor() {
        if (confirm('Are you sure you want to reset the code editor? This will clear your current code.')) {
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
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Toast notification function
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
            color: white;
            border-radius: 5px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            animation: slideIn 0.3s ease;
            font-size: 0.9rem;
        `;
        
        const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-times-circle' : 'fa-info-circle';
        toast.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Production Python Execution Function
    async function runPythonCode() {
        if (isExecuting) {
            showToast('Please wait for the current execution to complete', 'error');
            return;
        }
        
        const pythonCode = getPythonCode();
        const outputDiv = document.getElementById('outputContent');
        const outputContainer = document.getElementById('pythonOutput');
        const statusSpan = document.getElementById('executionStatus');
        
        // Clear previous output
        outputDiv.innerHTML = '';
        outputContainer.style.borderColor = '#333';
        
        // Check if code is empty or just comments
        const cleanCode = pythonCode.trim();
        if (!cleanCode || cleanCode.replace(/#.*/g, '').trim() === '') {
            outputDiv.innerHTML = '<span style="color: #ff6b6b;"><i class="fas fa-exclamation-triangle"></i> Please write some Python code to execute.</span>';
            showToast('Please write some Python code first', 'error');
            return;
        }
        
        // Set executing state
        isExecuting = true;
        if (statusSpan) {
            statusSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Executing...';
            statusSpan.style.color = '#ffa500';
        }
        
        // Show loading indicator in output
        outputDiv.innerHTML = '<span style="color: #888;"><i class="fas fa-spinner fa-spin"></i> Executing Python code in secure sandbox... (Max 5 seconds)</span>';
        
        try {
            const startTime = performance.now();
            
            // Call the backend API for Python execution
            const response = await fetch('/api/execute_python.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ 
                    code: pythonCode,
                    timestamp: new Date().toISOString()
                }),
                credentials: 'same-origin'
            });
            
            const executionTime = Math.round(performance.now() - startTime);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.error) {
                outputDiv.innerHTML = `<div style="color: #ff6b6b;">
                    <i class="fas fa-times-circle"></i> <strong>Error:</strong> ${escapeHtml(result.error)}
                </div>`;
                outputContainer.style.borderColor = '#dc3545';
                if (statusSpan) {
                    statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Error';
                    statusSpan.style.color = '#dc3545';
                }
                showToast('Execution error occurred', 'error');
            } else {
                // Display results
                let outputHTML = '';
                
                if (result.stderr) {
                    outputHTML += `<div style="color: #ff6b6b; margin-bottom: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Error Output:</strong>
                        <pre style="background: rgba(255,107,107,0.1); padding: 10px; border-radius: 3px; margin: 5px 0; overflow-x: auto;">${escapeHtml(result.stderr)}</pre>
                    </div>`;
                    outputContainer.style.borderColor = '#dc3545';
                    if (statusSpan) {
                        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Error';
                        statusSpan.style.color = '#dc3545';
                    }
                    showToast('Code executed with errors', 'error');
                } else {
                    if (statusSpan) {
                        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Success';
                        statusSpan.style.color = '#28a745';
                    }
                    outputContainer.style.borderColor = '#28a745';
                    showToast('Code executed successfully', 'success');
                }
                
                if (result.stdout) {
                    outputHTML += `<div style="color: #51cf66; margin-bottom: 10px;">
                        <i class="fas fa-check-circle"></i> <strong>Program Output:</strong>
                        <pre style="background: rgba(81,207,102,0.1); padding: 10px; border-radius: 3px; margin: 5px 0; overflow-x: auto;">${escapeHtml(result.stdout)}</pre>
                    </div>`;
                }
                
                if (!result.stdout && !result.stderr) {
                    outputHTML += `<div style="color: #888;">
                        <i class="fas fa-info-circle"></i> Program executed successfully but produced no output.
                        <br><small>Add a print() statement to see output.</small>
                    </div>`;
                }
                
                // Add execution info
                outputHTML += `<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #444; color: #888; font-size: 0.85rem;">
                    <i class="fas fa-clock"></i> Execution time: ${executionTime}ms | 
                    <i class="fas fa-code"></i> Exit code: ${result.exit_code || 0} | 
                    <i class="fas fa-calendar"></i> ${new Date().toLocaleTimeString()}
                </div>`;
                
                outputDiv.innerHTML = outputHTML;
                
                // Store last execution time
                lastExecutionTime = new Date();
                
                // Update exercise completion if successful
                if (result.success && result.stdout && !result.stderr) {
                    // You could auto-mark as completed or show encouragement
                    setTimeout(() => {
                        if (!<?php echo $py_completed ? 'true' : 'false'; ?>) {
                            const successDiv = document.createElement('div');
                            successDiv.className = 'feedback correct';
                            successDiv.style.marginTop = '1rem';
                            successDiv.style.animation = 'fadeIn 0.5s';
                            successDiv.innerHTML = '<i class="fas fa-check-circle"></i> Great! Your code executed successfully. Don\'t forget to click "Save" to record your progress.';
                            
                            const form = document.getElementById('pythonForm1');
                            if (!form.querySelector('.feedback.correct')) {
                                form.appendChild(successDiv);
                                updateProgress();
                            }
                        }
                    }, 500);
                }
            }
            
        } catch (error) {
            console.error('Execution error:', error);
            outputDiv.innerHTML = `<div style="color: #ff6b6b;">
                <i class="fas fa-times-circle"></i> <strong>Execution Error:</strong><br>
                ${escapeHtml(error.message)}<br>
                <small style="color: #888;">Please check your network connection and try again.</small>
            </div>`;
            outputContainer.style.borderColor = '#dc3545';
            if (statusSpan) {
                statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Failed';
                statusSpan.style.color = '#dc3545';
            }
            showToast('Failed to execute code: ' + error.message, 'error');
        } finally {
            isExecuting = false;
            
            // Auto-save code after execution
            updatePythonInput();
        }
    }

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
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                alert('Mobile menu would open here. In full implementation, this would show navigation links.');
            });
        }

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

        // Initialize code stats
        setTimeout(updateCodeStats, 100);
        
        // Add syntax highlighting helper for Python editor
        const pythonEditor = document.getElementById('pythonEditor');
        if (pythonEditor) {
            // Add keyboard shortcut for running code (Ctrl+Enter or Cmd+Enter)
            pythonEditor.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    runPythonCode();
                }
                
                // Tab key support (4 spaces)
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    const value = this.textContent;
                    
                    // Insert 4 spaces at cursor position
                    this.textContent = value.substring(0, start) + '    ' + value.substring(end);
                    
                    // Move cursor to after the inserted spaces
                    this.selectionStart = this.selectionEnd = start + 4;
                }
            });
            
            // Update stats when typing
            pythonEditor.addEventListener('input', function() {
                updateCodeStats();
                updatePythonInput();
                
                // Update status
                const statusSpan = document.getElementById('executionStatus');
                if (statusSpan) {
                    statusSpan.innerHTML = '<i class="fas fa-edit"></i> Editing';
                    statusSpan.style.color = '#4B8BBE';
                }
            });
            
            // Add focus styling
            pythonEditor.addEventListener('focus', function() {
                this.style.outline = '2px solid #4B8BBE';
                this.style.outlineOffset = '2px';
            });
            
            pythonEditor.addEventListener('blur', function() {
                this.style.outline = 'none';
            });
        }

        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .progress-fill {
                transition: width 0.5s ease-in-out;
            }
        `;
        document.head.appendChild(style);
    });

    // Handle window resize for mobile
    window.addEventListener('resize', function() {
        // Update any responsive elements as needed
    });

    // Save code on page unload
    window.addEventListener('beforeunload', function(e) {
        const hasUnsavedChanges = document.getElementById('pythonCodeInput') && 
                                 document.getElementById('pythonCodeInput').value !== getPythonCode();
        
        if (hasUnsavedChanges && !isExecuting) {
            // Auto-save before leaving
            updatePythonInput();
            
            // You could trigger an AJAX save here
            // const formData = new FormData();
            // formData.append('auto_save', getPythonCode());
            // navigator.sendBeacon('/api/auto_save.php', formData);
        }
    });
</script>
    </body>

    </html>
<?php
} catch (Exception $e) {
    die("<div style='padding:20px; background:#fee; border:1px solid #f00; color:#900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>