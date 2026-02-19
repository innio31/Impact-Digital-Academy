<?php
// module1_test.php - Python Essentials 1: Module 1 Completion Test
declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

/**
 * Module 1 Test Controller
 */
class Module1TestController
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
     * Validate user access to Module 1 Test
     */
    private function validateAccess(): void
    {
        // Admins and instructors have automatic access
        if ($this->user_role === 'admin' || $this->user_role === 'instructor') {
            return;
        }

        // For students, check if they have completed Module 1 sections
        $progress_count = $this->checkModuleProgress();

        if ($progress_count < 70) { // At least 70% completion required
            $this->showAccessDenied();
        }
    }

    /**
     * Check if student has completed Module 1 sections
     */
    private function checkModuleProgress(): float
    {
        $sql = "SELECT overall_progress 
                FROM module_progress 
                WHERE user_id = ? AND module_id = 1";
        
        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (float)($row['overall_progress'] ?? 0);
    }

    /**
     * Get test questions (20 questions, 10 random per attempt)
     */
    public function getTestQuestions(): array
    {
        $all_questions = [
            // Question 1
            [
                'id' => 1,
                'question' => 'Which of the following best describes what a computer program is?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'A set of mathematical formulas',
                    'b' => 'A sequence of instructions that tells the computer what to do',
                    'c' => 'A type of hardware component',
                    'd' => 'An operating system feature'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'A computer program is a sequence of instructions that tells the computer what to do, written in a programming language.'
            ],

            // Question 2
            [
                'id' => 2,
                'question' => 'What does an interpreter do?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Translates the entire program to machine code before execution',
                    'b' => 'Executes program instructions line by line',
                    'c' => 'Only checks for syntax errors',
                    'd' => 'Creates executable files'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'An interpreter executes program instructions line by line, translating and running each instruction immediately.'
            ],

            // Question 3
            [
                'id' => 3,
                'question' => 'Which of the following is TRUE about Python?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Python is a low-level programming language',
                    'b' => 'Python requires a compiler to create executable files',
                    'c' => 'Python uses an interpreter to execute code',
                    'd' => 'Python was primarily designed for system programming'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Python Basics',
                'explanation' => 'Python is an interpreted, high-level programming language that uses an interpreter to execute code.'
            ],

            // Question 4
            [
                'id' => 4,
                'question' => 'What is the primary purpose of the "print()" function in Python?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'To read input from the user',
                    'b' => 'To perform mathematical calculations',
                    'c' => 'To display output on the screen',
                    'd' => 'To create variables'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Python Syntax',
                'explanation' => 'The print() function outputs text or values to the console/screen.'
            ],

            // Question 5
            [
                'id' => 5,
                'question' => 'Which of the following Python code snippets contains a syntax error?',
                'type' => 'multiple_choice',
                'code' => true,
                'options' => [
                    'a' => 'print("Hello, World!")',
                    'b' => 'print("Hello, World!"',
                    'c' => 'print("Hello, World!")',
                    'd' => 'print("Hello", "World!")'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Python Syntax',
                'explanation' => 'Option B is missing a closing parenthesis, which creates a syntax error.'
            ],

            // Question 6
            [
                'id' => 6,
                'question' => 'What does IDLE stand for in Python?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Interactive Development and Learning Environment',
                    'b' => 'Integrated Design and Learning Editor',
                    'c' => 'Integrated Development and Learning Environment',
                    'd' => 'Interactive Design and Learning Editor'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Python Tools',
                'explanation' => 'IDLE stands for Integrated Development and Learning Environment, which comes with Python installation.'
            ],

            // Question 7
            [
                'id' => 7,
                'question' => 'Which operating system often has Python pre-installed?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Windows',
                    'b' => 'Linux',
                    'c' => 'macOS',
                    'd' => 'None of the above'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Python Installation',
                'explanation' => 'Linux distributions often have Python pre-installed because it is used by many system components.'
            ],

            // Question 8
            [
                'id' => 8,
                'question' => 'What is the correct file extension for Python source files?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => '.py',
                    'b' => '.python',
                    'c' => '.pt',
                    'd' => '.src'
                ],
                'correct_answer' => 'a',
                'points' => 10,
                'domain' => 'Python Files',
                'explanation' => 'Python source files use the .py extension.'
            ],

            // Question 9
            [
                'id' => 9,
                'question' => 'What will be the output of the following code?<br><code>print("Hello")<br>print("World")</code>',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Hello World',
                    'b' => 'Hello<br>World',
                    'c' => 'HelloWorld',
                    'd' => 'Hello World (on separate lines)'
                ],
                'correct_answer' => 'd',
                'points' => 10,
                'domain' => 'Python Output',
                'explanation' => 'Each print() statement outputs text on a new line by default.'
            ],

            // Question 10
            [
                'id' => 10,
                'question' => 'What is the main difference between a compiler and an interpreter?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Compilers are faster but interpreters are more accurate',
                    'b' => 'Compilers translate entire programs before execution, interpreters translate line by line',
                    'c' => 'Interpreters only work with Python, compilers work with all languages',
                    'd' => 'There is no significant difference'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'Compilers translate the entire source code to machine code before execution, while interpreters translate and execute line by line.'
            ],

            // Question 11
            [
                'id' => 11,
                'question' => 'Which of these is NOT a valid Python program?',
                'type' => 'multiple_choice',
                'code' => true,
                'options' => [
                    'a' => 'print("Python is fun!")',
                    'b' => 'Print("Python is fun!")',
                    'c' => 'print("Python is fun!")',
                    'd' => 'print("Python" + " is fun!")'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Python Syntax',
                'explanation' => 'Python is case-sensitive. "Print" with capital P is not the same as "print".'
            ],

            // Question 12
            [
                'id' => 12,
                'question' => 'What is an algorithm?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'A programming language',
                    'b' => 'A step-by-step procedure to solve a problem',
                    'c' => 'A type of computer hardware',
                    'd' => 'A mathematical equation'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'An algorithm is a finite sequence of well-defined instructions to solve a specific problem.'
            ],

            // Question 13
            [
                'id' => 13,
                'question' => 'Which of the following statements about Python is FALSE?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Python is an interpreted language',
                    'b' => 'Python is platform-independent',
                    'c' => 'Python code must be compiled before running',
                    'd' => 'Python has a simple and readable syntax'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Python Basics',
                'explanation' => 'Python does not require compilation before running; it uses an interpreter.'
            ],

            // Question 14
            [
                'id' => 14,
                'question' => 'What should you do if you encounter a syntax error in Python?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Ignore it and continue running the program',
                    'b' => 'Check the code for typos, missing punctuation, or incorrect syntax',
                    'c' => 'Restart the computer',
                    'd' => 'Reinstall Python'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Debugging',
                'explanation' => 'Syntax errors occur due to incorrect code structure. Check for typos, missing characters, or incorrect syntax.'
            ],

            // Question 15
            [
                'id' => 15,
                'question' => 'Which of these is the correct way to write a comment in Python?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => '// This is a comment',
                    'b' => '/* This is a comment */',
                    'c' => '# This is a comment',
                    'd' => '<!-- This is a comment -->'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Python Syntax',
                'explanation' => 'In Python, comments start with the # symbol.'
            ],

            // Question 16
            [
                'id' => 16,
                'question' => 'What is the purpose of an Integrated Development Environment (IDE)?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'To play video games',
                    'b' => 'To provide tools for writing, testing, and debugging code',
                    'c' => 'To manage computer hardware',
                    'd' => 'To browse the internet'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Tools',
                'explanation' => 'An IDE provides comprehensive tools for software development including code editing, debugging, and testing.'
            ],

            // Question 17
            [
                'id' => 17,
                'question' => 'Which statement about programming languages is TRUE?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'All programming languages are the same',
                    'b' => 'Python is the only programming language that exists',
                    'c' => 'Different programming languages have different strengths and purposes',
                    'd' => 'Programming languages are only for mathematics'
                ],
                'correct_answer' => 'c',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'Different programming languages are designed for different purposes (web development, data science, system programming, etc.).'
            ],

            // Question 18
            [
                'id' => 18,
                'question' => 'What does the term "syntax" refer to in programming?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'The meaning of code',
                    'b' => 'The rules for writing valid code',
                    'c' => 'The speed of program execution',
                    'd' => 'The size of the program'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Programming Concepts',
                'explanation' => 'Syntax refers to the set of rules that define the structure of a programming language.'
            ],

            // Question 19
            [
                'id' => 19,
                'question' => 'Why is Python considered a good language for beginners?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'It has simple, readable syntax',
                    'b' => 'It has extensive libraries',
                    'c' => 'It has a large community',
                    'd' => 'All of the above'
                ],
                'correct_answer' => 'd',
                'points' => 10,
                'domain' => 'Python Basics',
                'explanation' => 'Python is beginner-friendly due to its simple syntax, extensive libraries, and large supportive community.'
            ],

            // Question 20
            [
                'id' => 20,
                'question' => 'What is the first step in solving a problem with programming?',
                'type' => 'multiple_choice',
                'options' => [
                    'a' => 'Start writing code immediately',
                    'b' => 'Understand the problem and plan a solution',
                    'c' => 'Choose a programming language',
                    'd' => 'Install development tools'
                ],
                'correct_answer' => 'b',
                'points' => 10,
                'domain' => 'Problem Solving',
                'explanation' => 'The first step in programming is to thoroughly understand the problem and plan an algorithmic solution before writing code.'
            ],
        ];

        // Shuffle questions and select 10 for this attempt
        shuffle($all_questions);
        return array_slice($all_questions, 0, 10);
    }

    /**
     * Save test attempt to database
     */
    public function saveTestAttempt(array $answers, float $score, bool $passed): int
    {
        $answers_json = json_encode($answers);
        
        $sql = "INSERT INTO exercise_submissions (user_id, module_id, exercise_type, exercise_id, user_answer, is_correct, score, max_score, ip_address, user_agent) 
                VALUES (?, 1, 'module_test', 'module1_completion_test', ?, ?, ?, 100, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $is_correct = $passed ? 1 : 0;

            $stmt->bind_param(
                'isddss',
                $this->user_id,
                $answers_json,
                $is_correct,
                $score,
                $ip_address,
                $user_agent
            );
            $result = $stmt->execute();
            $attempt_id = $stmt->insert_id;
            $stmt->close();
            
            // Update module progress to 100% if passed
            if ($passed) {
                $this->updateModuleCompletion();
            }
            
            return $attempt_id;
        }
        return 0;
    }

    /**
     * Update module completion to 100%
     */
    private function updateModuleCompletion(): bool
    {
        $sql = "INSERT INTO module_progress (user_id, module_id, section1_progress, section2_progress, section3_progress, section4_progress, overall_progress, last_accessed) 
                VALUES (?, 1, 100, 100, 100, 100, 100, NOW())
                ON DUPLICATE KEY UPDATE 
                section4_progress = 100,
                overall_progress = 100,
                last_accessed = NOW()";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $this->user_id);
            $result = $stmt->execute();
            $stmt->close();
            
            // Update session
            if (!isset($_SESSION['module_progress'])) {
                $_SESSION['module_progress'] = [];
            }
            $_SESSION['module_progress']['module1'] = [
                'section1' => 100,
                'section2' => 100,
                'section3' => 100,
                'section4' => 100,
                'overall' => 100
            ];
            
            return $result;
        }
        return false;
    }

    /**
     * Get user's previous test attempts
     */
    public function getPreviousAttempts(): array
    {
        $attempts = [];
        
        $sql = "SELECT id, user_answer, score, is_correct, submitted_at 
                FROM exercise_submissions 
                WHERE user_id = ? AND module_id = 1 AND exercise_type = 'module_test' 
                ORDER BY submitted_at DESC 
                LIMIT 5";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $attempts[] = [
                    'id' => $row['id'],
                    'score' => (float)$row['score'],
                    'passed' => (bool)$row['is_correct'],
                    'submitted_at' => $row['submitted_at'],
                    'answers' => json_decode($row['user_answer'], true) ?? []
                ];
            }
            $stmt->close();
        }
        
        return $attempts;
    }

    /**
     * Get user display name
     */
    public function getUserDisplayName(): string
    {
        $fullName = trim($this->first_name . ' ' . $this->last_name);
        if (empty($fullName)) {
            $fullName = $this->user_email;
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
            <title>Access Denied - Module 1 Test</title>
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
                h1 { color: #306998; margin-bottom: 20px; }
                p { color: #666; margin-bottom: 30px; line-height: 1.6; }
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
                .progress {
                    background: #e9ecef;
                    border-radius: 20px;
                    height: 10px;
                    margin: 20px 0;
                    overflow: hidden;
                }
                .progress-bar {
                    background: #306998;
                    height: 100%;
                    border-radius: 20px;
                }
            </style>
        </head>
        <body>
            <div class="access-denied-container">
                <div class="icon"><i class="fab fa-python"></i></div>
                <h1>Test Access Restricted</h1>
                <p>You need to complete at least <strong>70% of Module 1</strong> to take the completion test.</p>
                <p>Please complete the previous sections first.</p>
                <div style="margin-top: 30px;">
                    <a href="<?php echo BASE_URL; ?>modules/student/module1.php" class="btn">Return to Module 1</a>
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
}

// Initialize test controller
try {
    $testController = new Module1TestController();
    $previous_attempts = $testController->getPreviousAttempts();
    
    // Check if form was submitted
    $test_submitted = false;
    $test_results = null;
    $user_answers = [];
    $current_questions = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_submit'])) {
        // Process test submission
        $user_answers = $_POST['answers'] ?? [];
        $current_questions = json_decode($_POST['questions_data'], true) ?? [];
        
        // Calculate score
        $score = 0;
        $total_questions = count($current_questions);
        $correct_answers = [];
        
        foreach ($current_questions as $question) {
            $question_id = $question['id'];
            $user_answer = $user_answers[$question_id] ?? '';
            $is_correct = ($user_answer === $question['correct_answer']);
            
            if ($is_correct) {
                $score += $question['points'];
            }
            
            $correct_answers[$question_id] = [
                'user_answer' => $user_answer,
                'correct_answer' => $question['correct_answer'],
                'is_correct' => $is_correct,
                'explanation' => $question['explanation'] ?? ''
            ];
        }
        
        $percentage = ($score / 100) * 100; // Each question is 10 points, total 100
        $passed = $percentage >= 70;
        
        // Save attempt
        $attempt_id = $testController->saveTestAttempt($correct_answers, $percentage, $passed);
        
        $test_results = [
            'score' => $percentage,
            'passed' => $passed,
            'total_questions' => $total_questions,
            'correct_answers' => count(array_filter($correct_answers, fn($a) => $a['is_correct'])),
            'answers' => $correct_answers,
            'attempt_id' => $attempt_id
        ];
        
        $test_submitted = true;
        
        // Refresh previous attempts
        $previous_attempts = $testController->getPreviousAttempts();
    } else {
        // Start new test
        $current_questions = $testController->getTestQuestions();
    }
    
    // Get user info
    $user_name = $testController->getUserDisplayName();
    $user_role = $testController->getUserRole();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module 1: Completion Test - Python Essentials 1</title>
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
            padding-top: 80px;
        }

        /* Header */
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
        }

        .logo-icon { font-size: 2rem; color: var(--secondary); }
        .logo-text h1 { font-size: 1.2rem; font-weight: 700; }
        .logo-text p { font-size: 0.8rem; opacity: 0.9; }

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

        .logout-btn:hover { background: rgba(255, 255, 255, 0.3); }

        /* Test Header */
        .test-header {
            background: white;
            padding: 2rem 1.5rem;
            box-shadow: 0 2px 10px var(--shadow);
            margin-bottom: 2rem;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            border-radius: 10px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            color: #666;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .test-title {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }

        .test-info {
            background: #e9f7fe;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            border-left: 4px solid var(--accent);
        }

        /* Test Container */
        .test-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .test-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 5px 15px var(--shadow);
            margin-bottom: 2rem;
        }

        /* Results Card */
        .results-card {
            text-align: center;
            border-top: 5px solid var(--success);
        }

        .results-icon {
            font-size: 4rem;
            color: var(--success);
            margin-bottom: 1rem;
        }

        .results-score {
            font-size: 3rem;
            font-weight: bold;
            color: var(--primary);
            margin: 1rem 0;
        }

        .results-message {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            padding: 1rem;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .passed { color: var(--success); background: #d4edda !important; }
        .failed { color: var(--danger); background: #f8d7da !important; }

        /* Question Styles */
        .question-container {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #eee;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .question-number {
            background: var(--primary);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .question-text {
            flex: 1;
            margin-left: 1rem;
            font-size: 1.1rem;
            line-height: 1.5;
        }

        .question-points {
            background: var(--secondary);
            color: var(--dark);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .code-snippet {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-family: 'Fira Code', 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }

        /* Answer Options */
        .options-container {
            margin: 1.5rem 0;
        }

        .option {
            margin-bottom: 0.8rem;
            padding: 1rem;
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }

        .option:hover { border-color: var(--accent); background: #e9f7fe; }
        .option.selected { border-color: var(--primary); background: #e9f7fe; }
        .option.correct { border-color: var(--success); background: #d4edda; }
        .option.incorrect { border-color: var(--danger); background: #f8d7da; }
        .option.disabled { cursor: default; opacity: 0.7; }

        .option input[type="radio"] {
            margin-right: 1rem;
            transform: scale(1.2);
        }

        .option-label {
            font-weight: 600;
            margin-right: 10px;
            min-width: 20px;
        }

        /* Explanation */
        .explanation {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--warning);
            display: none;
        }

        .explanation.show { display: block; }

        /* Test Controls */
        .test-controls {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
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
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover { background: #218838; }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-warning:hover { background: #e0a800; }

        /* Previous Attempts */
        .attempts-container {
            margin-top: 3rem;
        }

        .attempt-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .attempt-passed { border-left-color: var(--success); }
        .attempt-failed { border-left-color: var(--danger); }

        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 2rem 1.5rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
        }

        .copyright {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #444;
            color: #aaa;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body { padding-top: 70px; }
            .test-header { padding: 1.5rem 1rem; }
            .test-title { font-size: 1.4rem; }
            .test-card { padding: 1.5rem; }
            .question-header { flex-direction: column; }
            .question-text { margin-left: 0; margin-top: 1rem; }
            .test-controls { flex-direction: column; gap: 1rem; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="nav-container">
            <div class="logo">
                <div class="logo-icon"><i class="fab fa-python"></i></div>
                <div class="logo-text">
                    <h1>Python Essentials 1</h1>
                    <p>Module 1 Completion Test</p>
                </div>
            </div>
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user"></i></div>
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem;"><?php echo $user_name; ?></div>
                    <div style="font-size: 0.8rem; opacity: 0.8;"><?php echo ucfirst($user_role); ?></div>
                </div>
                <a href="<?php echo BASE_URL; ?>logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Test Header -->
    <section class="test-header">
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>index.php"><i class="fas fa-home"></i> Home</a>
            <i class="fas fa-chevron-right"></i>
            <a href="module1.php">Module 1</a>
            <i class="fas fa-chevron-right"></i>
            <span>Completion Test</span>
        </div>
        <h1 class="test-title">Module 1: Completion Test</h1>
        <p>Test your knowledge of Python programming fundamentals covered in Module 1.</p>
        
        <div class="test-info">
            <p><strong>Test Details:</strong></p>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li><strong>10 questions</strong> (randomly selected from 20)</li>
                <li><strong>70% required to pass</strong> (7 out of 10 questions)</li>
                <li><strong>Unlimited attempts</strong> until you pass</li>
                <li><strong>No time limit</strong> per attempt</li>
                <li>Each question is worth <strong>10 points</strong></li>
                <li>Total points: <strong>100 points</strong></li>
            </ul>
        </div>
    </section>

    <!-- Test Container -->
    <div class="test-container">
        <?php if ($test_submitted && $test_results): ?>
            <!-- Results Display -->
            <div class="test-card results-card">
                <div class="results-icon">
                    <i class="fas fa-<?php echo $test_results['passed'] ? 'trophy' : 'redo-alt'; ?>"></i>
                </div>
                <h2><?php echo $test_results['passed'] ? 'Congratulations!' : 'Test Attempt Completed'; ?></h2>
                
                <div class="results-score"><?php echo round($test_results['score'], 1); ?>%</div>
                
                <div class="results-message <?php echo $test_results['passed'] ? 'passed' : 'failed'; ?>">
                    <p>
                        <?php if ($test_results['passed']): ?>
                            <strong>You passed!</strong> You scored <?php echo $test_results['correct_answers']; ?> out of 
                            <?php echo $test_results['total_questions']; ?> questions correctly.
                        <?php else: ?>
                            <strong>You need 70% to pass.</strong> You scored <?php echo $test_results['correct_answers']; ?> out of 
                            <?php echo $test_results['total_questions']; ?> questions correctly.
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Answer Review -->
                <h3 style="text-align: left; margin-top: 2rem; color: var(--primary);">
                    <i class="fas fa-clipboard-check"></i> Your Answers
                </h3>
                
                <?php foreach ($current_questions as $index => $question): 
                    $question_num = $index + 1;
                    $question_id = $question['id'];
                    $user_answer = $test_results['answers'][$question_id]['user_answer'] ?? '';
                    $correct_answer = $test_results['answers'][$question_id]['correct_answer'];
                    $is_correct = $test_results['answers'][$question_id]['is_correct'];
                    $explanation = $test_results['answers'][$question_id]['explanation'];
                ?>
                <div class="question-container">
                    <div class="question-header">
                        <div class="question-number"><?php echo $question_num; ?></div>
                        <div class="question-text"><?php echo $question['question']; ?></div>
                        <div class="question-points">
                            <?php echo $is_correct ? '✓ 10 points' : '✗ 0 points'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($question['code']) && $question['code']): ?>
                        <div class="code-snippet"><?php echo $question['options'][$question['correct_answer']]; ?></div>
                    <?php endif; ?>
                    
                    <div class="options-container">
                        <?php foreach ($question['options'] as $key => $option): ?>
                            <?php 
                            $option_class = 'option';
                            $option_class .= ($user_answer === $key) ? ' selected' : '';
                            $option_class .= ($correct_answer === $key) ? ' correct' : '';
                            $option_class .= ($user_answer === $key && !$is_correct) ? ' incorrect' : '';
                            $option_class .= ' disabled';
                            ?>
                            <div class="<?php echo $option_class; ?>">
                                <div class="option-label"><?php echo strtoupper($key); ?></div>
                                <div><?php echo $option; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="explanation show">
                        <p><strong>Explanation:</strong> <?php echo $explanation; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="test-controls">
                    <a href="module1_test.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Take Test Again
                    </a>
                    <?php if ($test_results['passed']): ?>
                        <a href="module2_section1.php" class="btn btn-success">
                            <i class="fas fa-arrow-right"></i> Continue to Next Module
                        </a>
                    <?php else: ?>
                        <a href="module1.php" class="btn btn-warning">
                            <i class="fas fa-book"></i> Review Module Content
                        </a>
                    <?php endif; ?>
                    <a href="module1.php" class="btn btn-warning">
                            <i class="fas fa-book"></i> Review Module Content
                        </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Test Form -->
            <form method="POST" id="testForm">
                <div class="test-card">
                    <h2 style="color: var(--primary); margin-bottom: 1.5rem;">
                        <i class="fas fa-question-circle"></i> Test Questions
                    </h2>
                    
                    <?php foreach ($current_questions as $index => $question): 
                        $question_num = $index + 1;
                    ?>
                    <div class="question-container">
                        <div class="question-header">
                            <div class="question-number"><?php echo $question_num; ?></div>
                            <div class="question-text"><?php echo $question['question']; ?></div>
                            <div class="question-points">10 points</div>
                        </div>
                        
                        <?php if (isset($question['code']) && $question['code']): ?>
                            <div class="code-snippet"><?php echo $question['options'][$question['correct_answer']]; ?></div>
                        <?php endif; ?>
                        
                        <div class="options-container">
                            <?php foreach ($question['options'] as $key => $option): ?>
                                <label class="option" onclick="selectOption(this, <?php echo $question['id']; ?>, '<?php echo $key; ?>')">
                                    <input type="radio" 
                                           name="answers[<?php echo $question['id']; ?>]" 
                                           value="<?php echo $key; ?>" 
                                           required>
                                    <div class="option-label"><?php echo strtoupper($key); ?></div>
                                    <div><?php echo $option; ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <input type="hidden" name="questions_data" value="<?php echo htmlspecialchars(json_encode($current_questions)); ?>">
                    <input type="hidden" name="test_submit" value="1">
                    
                    <div class="test-controls">
                        <button type="submit" class="btn btn-success" style="font-size: 1rem; padding: 1rem 2rem;">
                            <i class="fas fa-paper-plane"></i> Submit Test
                        </button>
                        <button type="button" class="btn btn-warning" onclick="resetTest()">
                            <i class="fas fa-redo"></i> Clear All Answers
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <!-- Previous Attempts -->
        <?php if (!empty($previous_attempts)): ?>
        <div class="attempts-container">
            <h3 style="color: var(--primary); margin-bottom: 1rem;">
                <i class="fas fa-history"></i> Your Previous Attempts
            </h3>
            <?php foreach ($previous_attempts as $attempt): ?>
            
            <div class="attempt-card <?php echo $attempt['passed'] ? 'attempt-passed' : 'attempt-failed'; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>Score: <?php echo round($attempt['score'], 1); ?>%</strong>
                        <span style="margin-left: 1rem; font-size: 0.9rem;">
                            <?php echo $attempt['passed'] ? '✓ Passed' : '✗ Needs Improvement'; ?>
                        </span>
                    </div>
                    <div style="font-size: 0.9rem; color: #666;">
                        <?php echo date('M j, Y g:i A', strtotime($attempt['submitted_at'])); ?>
                    </div>
                </div>
                <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                    Correct: <?php echo count(array_filter($attempt['answers'], fn($a) => $a['is_correct'])); ?> out of 
                    <?php echo count($attempt['answers']); ?> questions
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

     <a href="index.php" class="btn btn-warning">
                            <i class="fas fa-book"></i> Review Module Content
                        </a>
           
    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <p><strong>Python Essentials 1 - Module 1 Completion Test</strong></p>
            <p>Test your understanding of Python programming fundamentals</p>
            <div class="copyright">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.</p>
                <p style="margin-top: 0.5rem; font-size: 0.8rem;">Python is a trademark of the Python Software Foundation</p>
            </div>
        </div>
    </footer>

    <script>
        // Handle option selection
        function selectOption(element, questionId, answerValue) {
            // Remove selected class from all options in this question
            const questionContainer = element.closest('.question-container');
            const allOptions = questionContainer.querySelectorAll('.option');
            allOptions.forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            
            // Set the radio button value
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
        }
        
        // Reset test form
        function resetTest() {
            if (confirm('Are you sure you want to clear all answers?')) {
                const allOptions = document.querySelectorAll('.option');
                allOptions.forEach(opt => {
                    opt.classList.remove('selected');
                    const radio = opt.querySelector('input[type="radio"]');
                    if (radio) radio.checked = false;
                });
            }
        }
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter to submit form
            if (e.ctrlKey && e.key === 'Enter') {
                const submitBtn = document.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.click();
                }
            }
            
            // Number keys 1-4 to select options (for accessibility)
            if (e.key >= '1' && e.key <= '4') {
                const focusedElement = document.activeElement;
                if (focusedElement.type === 'radio' || focusedElement.closest('.option')) {
                    const questionContainer = focusedElement.closest('.question-container');
                    if (questionContainer) {
                        const options = questionContainer.querySelectorAll('.option');
                        const optionIndex = parseInt(e.key) - 1;
                        if (options[optionIndex]) {
                            const radio = options[optionIndex].querySelector('input[type="radio"]');
                            if (radio) {
                                radio.checked = true;
                                selectOption(options[optionIndex], 0, radio.value);
                            }
                        }
                    }
                }
            }
        });
        
        // Auto-save answers (for longer tests, could be implemented with localStorage)
        let autoSaveTimer;
        const testForm = document.getElementById('testForm');
        if (testForm) {
            testForm.addEventListener('change', function() {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => {
                    console.log('Answers saved locally');
                    // Could implement localStorage saving here
                }, 1000);
            });
        }
        
        // Scroll to top on page load if there are results
        <?php if ($test_submitted): ?>
        window.scrollTo({ top: 0, behavior: 'smooth' });
        <?php endif; ?>
    </script>
</body>
</html>
<?php
} catch (Exception $e) {
    die("<div style='padding:20px; background:#fee; border:1px solid #f00; color:#900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>