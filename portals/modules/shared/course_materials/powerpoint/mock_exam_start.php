<?php
// modules/shared/course_materials/MSPowerPoint/mock_exam_start.php

declare(strict_types=1);

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include dependencies
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

/**
 * MO-300 Mock Exam Simulation Class
 */
class MO300MockExam
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $exam_duration = 3000; // 50 minutes in seconds
    private $max_score = 1000;
    private $passing_score = 700;
    private $questions = [];
    private $selected_questions = [];
    private $total_exam_questions = 50; // Always show 50 questions
    
    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
        $this->loadQuestionsFromDatabase();
        $this->initializeExamSession();
        $this->handleExamActions();
    }
    
    /**
     * Validate user session
     */
    private function validateSession(): void
    {
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'instructor'])) {
            $this->redirectToLogin();
        }
    }
    
    /**
     * Initialize properties
     */
    private function initializeProperties(): void
    {
        $this->user_id = (int)$_SESSION['user_id'];
        $this->user_role = $_SESSION['user_role'];
        $this->class_id = $this->getValidatedClassId();
    }
    
    /**
     * Get validated class ID
     */
    private function getValidatedClassId(): ?int
    {
        if (!isset($_GET['class_id'])) {
            return null;
        }
        
        $class_id = $_GET['class_id'];
        
        if (!is_numeric($class_id)) {
            return null;
        }
        
        $class_id = (int)$class_id;
        
        return $class_id > 0 ? $class_id : null;
    }
    
    /**
     * Get database connection
     */
    private function getDatabaseConnection()
    {
        $conn = getDBConnection();
        
        if (!$conn) {
            $this->handleError("Database connection failed.");
        }
        
        return $conn;
    }
    
    /**
     * Validate user access
     */
    private function validateAccess(): void
    {
        if ($this->user_role === 'student') {
            $sql = "SELECT COUNT(*) as count 
                    FROM enrollments e 
                    JOIN class_batches cb ON e.class_id = cb.id
                    JOIN courses c ON cb.course_id = c.id
                    WHERE e.student_id = ? 
                    AND e.status IN ('active', 'completed')
                    AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $this->user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                if ((int)($row['count'] ?? 0) === 0) {
                    $this->redirectToDashboard();
                }
            }
        }
    }
    
    /**
     * Load questions from database
     */
    private function loadQuestionsFromDatabase(): void
    {
        // Check if we have shuffled questions saved in session
        if (isset($_SESSION['mock_exam']['shuffled_questions']) && 
            !empty($_SESSION['mock_exam']['shuffled_questions']) &&
            $_SESSION['mock_exam']['started']) {
            
            // Use the previously shuffled questions from session
            $this->questions = $_SESSION['mock_exam']['shuffled_questions'];
            return;
        }
        
        // First load all questions from database
        $all_questions = [];
        $performance_questions = [];
        $other_questions = [];
        
        // Try to load from database first
        $sql = "SELECT * FROM mock_exam_questions 
                WHERE exam_type = 'MO-300' AND is_active = 1";
        
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $question_data = [
                    'id' => $row['id'],
                    'question_number' => $row['question_number'],
                    'domain' => $row['question_domain'],
                    'text' => $row['question_text'],
                    'type' => $row['question_type'],
                    'options' => [
                        'A' => $row['option_a'] ?? '',
                        'B' => $row['option_b'] ?? '',
                        'C' => $row['option_c'] ?? '',
                        'D' => $row['option_d'] ?? '',
                        'E' => $row['option_e'] ?? ''
                    ],
                    'correct_answer' => $row['correct_answer'],
                    'points' => (float)$row['points'],
                    'instructions' => $row['performance_instructions'] ?? '',
                    'db_id' => $row['id']
                ];
                
                $all_questions[$row['id']] = $question_data;
                
                // Separate performance questions from others
                if (strtolower($row['question_type']) === 'performance') {
                    $performance_questions[$row['id']] = $question_data;
                } else {
                    $other_questions[$row['id']] = $question_data;
                }
            }
            
            // Shuffle both arrays
            shuffle($performance_questions);
            shuffle($other_questions);
            
            // Always select 15 performance questions (or all if less than 15)
            $performance_count = count($performance_questions);
            $selected_performance_count = min(15, $performance_count);
            
            // Select remaining questions from other types
            $remaining_count = $this->total_exam_questions - $selected_performance_count;
            $other_count = count($other_questions);
            $selected_other_count = min($remaining_count, $other_count);
            
            // Select questions
            $selected_performance = array_slice($performance_questions, 0, $selected_performance_count);
            $selected_other = array_slice($other_questions, 0, $selected_other_count);
            
            // Merge and shuffle final selection
            $this->selected_questions = array_merge($selected_performance, $selected_other);
            shuffle($this->selected_questions);
            
            // Reindex with sequential numbers
            $this->questions = [];
            $question_number = 1;
            foreach ($this->selected_questions as $question) {
                $question['id'] = $question_number; // Use sequential ID for the exam
                $question['original_id'] = $question['db_id']; // Keep original DB ID
                $this->questions[$question_number] = $question;
                $question_number++;
            }
            
            // If we don't have enough questions, use static ones
            if (count($this->questions) < 5) {
                $this->questions = $this->getStaticQuestions();
            }
            
            // Save shuffled questions to session
            $_SESSION['mock_exam']['shuffled_questions'] = $this->questions;
            
        } else {
            // Fallback to static questions if database is empty
            $this->questions = $this->getStaticQuestions();
            // Save to session
            $_SESSION['mock_exam']['shuffled_questions'] = $this->questions;
        }
    }
    
    /**
     * Get static questions (fallback)
     */
    private function getStaticQuestions(): array
    {
        // Same static questions as before...
        return [
            1 => [
                'id' => 1,
                'question_number' => 1,
                'domain' => 'Manage Presentations',
                'text' => 'You need to change the slide size of the presentation to Widescreen (16:9). Which ribbon tab contains the Slide Size option?',
                'options' => [
                    'A' => 'Home tab',
                    'B' => 'Design tab',
                    'C' => 'View tab',
                    'D' => 'Slide Show tab'
                ],
                'correct_answer' => 'B',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 1
            ],
            
            2 => [
                'id' => 2,
                'question_number' => 2,
                'domain' => 'Manage Presentations',
                'text' => 'You want to print handouts with 3 slides per page and lines for notes. Which print layout should you select?',
                'options' => [
                    'A' => 'Full Page Slides',
                    'B' => 'Notes Pages',
                    'C' => 'Outline',
                    'D' => 'Handouts (3 slides)'
                ],
                'correct_answer' => 'D',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 2
            ],
            
            3 => [
                'id' => 3,
                'question_number' => 3,
                'domain' => 'Insert and Format',
                'text' => 'Which of the following is NOT a way to insert a new slide?',
                'options' => [
                    'A' => 'Press Ctrl+M',
                    'B' => 'Right-click a slide and select "New Slide"',
                    'C' => 'Click New Slide on the Home tab',
                    'D' => 'Press Ctrl+N'
                ],
                'correct_answer' => 'D',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 3
            ],
            
            4 => [
                'id' => 4,
                'question_number' => 4,
                'domain' => 'Insert and Format',
                'text' => 'You need to add alternative text to an image for accessibility. Where do you find this option?',
                'options' => [
                    'A' => 'Picture Format tab > Alt Text',
                    'B' => 'Home tab > Font group',
                    'C' => 'Insert tab > Images group',
                    'D' => 'View tab > Show group'
                ],
                'correct_answer' => 'A',
                'points' => 15,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 4
            ],
            
            5 => [
                'id' => 5,
                'question_number' => 5,
                'domain' => 'Tables and Charts',
                'text' => 'You want to change the chart type from a column chart to a line chart. Which tab appears when a chart is selected?',
                'options' => [
                    'A' => 'Chart Design tab',
                    'B' => 'Format tab',
                    'C' => 'Design tab',
                    'D' => 'Layout tab'
                ],
                'correct_answer' => 'A',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 5
            ],
            
            6 => [
                'id' => 6,
                'question_number' => 6,
                'domain' => 'Tables and Charts',
                'text' => 'Which SmartArt layout is best for showing a process or timeline?',
                'options' => [
                    'A' => 'List',
                    'B' => 'Cycle',
                    'C' => 'Process',
                    'D' => 'Hierarchy'
                ],
                'correct_answer' => 'C',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 6
            ],
            
            7 => [
                'id' => 7,
                'question_number' => 7,
                'domain' => 'Transitions and Animations',
                'text' => 'Which transition creates a smooth movement between slides by morphing similar objects?',
                'options' => [
                    'A' => 'Fade',
                    'B' => 'Push',
                    'C' => 'Morph',
                    'D' => 'Zoom'
                ],
                'correct_answer' => 'C',
                'points' => 15,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 7
            ],
            
            8 => [
                'id' => 8,
                'question_number' => 8,
                'domain' => 'Transitions and Animations',
                'text' => 'Where can you see and reorder all animations on a slide?',
                'options' => [
                    'A' => 'Slide Sorter view',
                    'B' => 'Animation Pane',
                    'C' => 'Selection Pane',
                    'D' => 'Notes Page view'
                ],
                'correct_answer' => 'B',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 8
            ],
            
            9 => [
                'id' => 9,
                'question_number' => 9,
                'domain' => 'Multiple Presentations',
                'text' => 'You want to combine changes from two versions of the same presentation. Which feature should you use?',
                'options' => [
                    'A' => 'Compare',
                    'B' => 'Combine',
                    'C' => 'Merge',
                    'D' => 'Integrate'
                ],
                'correct_answer' => 'A',
                'points' => 15,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 9
            ],
            
            10 => [
                'id' => 10,
                'question_number' => 10,
                'domain' => 'Multiple Presentations',
                'text' => 'Which view allows you to see thumbnails of all slides for easy reorganization?',
                'options' => [
                    'A' => 'Normal view',
                    'B' => 'Slide Sorter view',
                    'C' => 'Reading view',
                    'D' => 'Outline view'
                ],
                'correct_answer' => 'B',
                'points' => 10,
                'type' => 'multiple_choice',
                'instructions' => '',
                'original_id' => 10
            ],
            
            11 => [
                'id' => 11,
                'question_number' => 11,
                'domain' => 'Performance Task',
                'text' => 'PERFORMANCE TASK: In the provided presentation, apply a "Fade" transition to all slides with a duration of 1.5 seconds.',
                'options' => [
                    'A' => 'Completed',
                    'B' => 'Not Completed',
                    'C' => 'Partially Completed',
                    'D' => 'Not Attempted'
                ],
                'correct_answer' => 'A',
                'points' => 25,
                'type' => 'performance',
                'instructions' => 'Open MO300_MockTask1.pptx. On the Transitions tab, select Fade. Click "Apply To All". Set Duration to 01.50. Save the file.',
                'original_id' => 11
            ],
            
            12 => [
                'id' => 12,
                'question_number' => 12,
                'domain' => 'Performance Task',
                'text' => 'PERFORMANCE TASK: On Slide 3, format the title with: Font: Calibri, Size: 44, Color: Dark Blue, Text Shadow offset: Bottom Right.',
                'options' => [
                    'A' => 'Completed',
                    'B' => 'Not Completed',
                    'C' => 'Partially Completed',
                    'D' => 'Not Attempted'
                ],
                'correct_answer' => 'A',
                'points' => 30,
                'type' => 'performance',
                'instructions' => 'Select the title on Slide 3. On Home tab, set Font to Calibri, Size 44, Font Color to Dark Blue. In Font dialog box (click arrow in Font group), select Text Effects, choose Shadow > Offset: Bottom Right.',
                'original_id' => 12
            ],
            
            13 => [
                'id' => 13,
                'question_number' => 13,
                'domain' => 'Performance Task',
                'text' => 'PERFORMANCE TASK: Insert a new slide after Slide 5 using the "Title and Content" layout. Add the text "Quarterly Results" as the title.',
                'options' => [
                    'A' => 'Completed',
                    'B' => 'Not Completed',
                    'C' => 'Partially Completed',
                    'D' => 'Not Attempted'
                ],
                'correct_answer' => 'A',
                'points' => 20,
                'type' => 'performance',
                'instructions' => 'Select Slide 5. On Home tab, click New Slide arrow, choose "Title and Content". Click title placeholder, type "Quarterly Results".',
                'original_id' => 13
            ],
            
            14 => [
                'id' => 14,
                'question_number' => 14,
                'domain' => 'Performance Task',
                'text' => 'PERFORMANCE TASK: On Slide 7, animate the bullet points to appear "One by One" with a 1-second delay between animations.',
                'options' => [
                    'A' => 'Completed',
                    'B' => 'Not Completed',
                    'C' => 'Partially Completed',
                    'D' => 'Not Attempted'
                ],
                'correct_answer' => 'A',
                'points' => 30,
                'type' => 'performance',
                'instructions' => 'Select the bullet points on Slide 7. On Animations tab, choose an entrance effect (e.g., Fade). Click Effect Options, choose "By Paragraph". In Timing group, set Start: "After Previous", Duration: 01.00, Delay: 01.00.',
                'original_id' => 14
            ],
            
            15 => [
                'id' => 15,
                'question_number' => 15,
                'domain' => 'Performance Task',
                'text' => 'PERFORMANCE TASK: Protect the presentation with the password "secure123" to require a password to modify (not to open).',
                'options' => [
                    'A' => 'Completed',
                    'B' => 'Not Completed',
                    'C' => 'Partially Completed',
                    'D' => 'Not Attempted'
                ],
                'correct_answer' => 'A',
                'points' => 35,
                'type' => 'performance',
                'instructions' => 'Go to File > Info > Protect Presentation > Encrypt with Password. Enter "secure123". Click OK. Re-enter password. Save the presentation.',
                'original_id' => 15
            ]
        ];
    }
    
    /**
     * Initialize exam session
     */
    private function initializeExamSession(): void
    {
        // Check if we need to reset due to different question set
        $current_question_count = $_SESSION['mock_exam']['questions_loaded'] ?? 0;
        
        if (!isset($_SESSION['mock_exam'])) {
            $_SESSION['mock_exam'] = [
                'started' => false,
                'start_time' => null,
                'time_remaining' => $this->exam_duration,
                'current_question' => 1,
                'answers' => [],
                'flagged' => [],
                'completed' => false,
                'score' => 0,
                'submitted' => false,
                'questions_loaded' => 0,
                'shuffled_questions' => [],
                'original_ids' => []
            ];
        }
    }
    
    /**
     * Handle exam actions
     */
    private function handleExamActions(): void
    {
        // Reset exam if requested
        if (isset($_GET['reset']) && $_GET['reset'] === 'true') {
            $this->resetExam();
        }
        
        // Start exam if requested
        if (isset($_GET['start']) && $_GET['start'] === 'true' && !$_SESSION['mock_exam']['started']) {
            $this->startExam();
        }
        
        // Navigate to specific question
        if (isset($_GET['question']) && is_numeric($_GET['question'])) {
            $question_num = (int)$_GET['question'];
            if ($question_num >= 1 && $question_num <= count($this->questions)) {
                $_SESSION['mock_exam']['current_question'] = $question_num;
            }
        }
        
        // Save time if requested
        if (isset($_GET['save_time']) && isset($_GET['time'])) {
            $_SESSION['mock_exam']['time_remaining'] = (int)$_GET['time'];
            exit(); // AJAX response
        }
        
        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Submit exam
            if (isset($_POST['submit_exam'])) {
                $this->submitExam();
            }
            
            // Save answer
            if (isset($_POST['save_answer']) && isset($_POST['question_id']) && isset($_POST['answer'])) {
                $this->saveAnswer((int)$_POST['question_id'], $_POST['answer']);
            }
            
            // Flag question
            if (isset($_POST['flag_question']) && isset($_POST['question_id'])) {
                $this->flagQuestion((int)$_POST['question_id']);
            }
        }
    }
    
    /**
     * Start the mock exam
     */
    private function startExam(): void
    {
        // Generate new shuffled questions when exam starts
        $this->generateShuffledQuestions();
        
        $_SESSION['mock_exam']['started'] = true;
        $_SESSION['mock_exam']['start_time'] = time();
        $_SESSION['mock_exam']['time_remaining'] = $this->exam_duration;
        $_SESSION['mock_exam']['current_question'] = 1;
        $_SESSION['mock_exam']['answers'] = [];
        $_SESSION['mock_exam']['flagged'] = [];
        $_SESSION['mock_exam']['completed'] = false;
        $_SESSION['mock_exam']['score'] = 0;
        $_SESSION['mock_exam']['submitted'] = false;
        $_SESSION['mock_exam']['questions_loaded'] = count($this->questions);
        
        // Store original ID mapping
        $_SESSION['mock_exam']['original_ids'] = [];
        foreach ($this->questions as $exam_id => $question) {
            $_SESSION['mock_exam']['original_ids'][$exam_id] = $question['original_id'] ?? $exam_id;
        }
        
        // Log exam start
        $this->logExamActivity('exam_started');
        
        // Redirect to remove query string
        $redirect_url = 'mock_exam_start.php' . ($this->class_id ? '?class_id=' . $this->class_id : '');
        header("Location: " . $redirect_url);
        exit();
    }
    
    /**
     * Generate new shuffled questions
     */
    private function generateShuffledQuestions(): void
    {
        // First load all questions from database
        $all_questions = [];
        $performance_questions = [];
        $other_questions = [];
        
        // Try to load from database first
        $sql = "SELECT * FROM mock_exam_questions 
                WHERE exam_type = 'MO-300' AND is_active = 1";
        
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $question_data = [
                    'id' => $row['id'],
                    'question_number' => $row['question_number'],
                    'domain' => $row['question_domain'],
                    'text' => $row['question_text'],
                    'type' => $row['question_type'],
                    'options' => [
                        'A' => $row['option_a'] ?? '',
                        'B' => $row['option_b'] ?? '',
                        'C' => $row['option_c'] ?? '',
                        'D' => $row['option_d'] ?? '',
                        'E' => $row['option_e'] ?? ''
                    ],
                    'correct_answer' => $row['correct_answer'],
                    'points' => (float)$row['points'],
                    'instructions' => $row['performance_instructions'] ?? '',
                    'db_id' => $row['id']
                ];
                
                $all_questions[$row['id']] = $question_data;
                
                // Separate performance questions from others
                if (strtolower($row['question_type']) === 'performance') {
                    $performance_questions[$row['id']] = $question_data;
                } else {
                    $other_questions[$row['id']] = $question_data;
                }
            }
            
            // Shuffle both arrays
            shuffle($performance_questions);
            shuffle($other_questions);
            
            // Always select 15 performance questions (or all if less than 15)
            $performance_count = count($performance_questions);
            $selected_performance_count = min(15, $performance_count);
            
            // Select remaining questions from other types
            $remaining_count = $this->total_exam_questions - $selected_performance_count;
            $other_count = count($other_questions);
            $selected_other_count = min($remaining_count, $other_count);
            
            // Select questions
            $selected_performance = array_slice($performance_questions, 0, $selected_performance_count);
            $selected_other = array_slice($other_questions, 0, $selected_other_count);
            
            // Merge and shuffle final selection
            $selected_questions = array_merge($selected_performance, $selected_other);
            shuffle($selected_questions);
            
            // Reindex with sequential numbers
            $shuffled_questions = [];
            $question_number = 1;
            foreach ($selected_questions as $question) {
                $question['id'] = $question_number; // Use sequential ID for the exam
                $question['original_id'] = $question['db_id']; // Keep original DB ID
                $shuffled_questions[$question_number] = $question;
                $question_number++;
            }
            
            // If we don't have enough questions, use static ones
            if (count($shuffled_questions) < 5) {
                $shuffled_questions = $this->getStaticQuestions();
            }
            
            // Save shuffled questions to session
            $_SESSION['mock_exam']['shuffled_questions'] = $shuffled_questions;
            $this->questions = $shuffled_questions;
            
        } else {
            // Fallback to static questions if database is empty
            $shuffled_questions = $this->getStaticQuestions();
            // Save to session
            $_SESSION['mock_exam']['shuffled_questions'] = $shuffled_questions;
            $this->questions = $shuffled_questions;
        }
    }
    
    /**
     * Reset the exam
     */
    private function resetExam(): void
    {
        // Clear shuffled questions when resetting
        $_SESSION['mock_exam'] = [
            'started' => false,
            'start_time' => null,
            'time_remaining' => $this->exam_duration,
            'current_question' => 1,
            'answers' => [],
            'flagged' => [],
            'completed' => false,
            'score' => 0,
            'submitted' => false,
            'questions_loaded' => 0,
            'shuffled_questions' => [],
            'original_ids' => []
        ];
        
        // Log exam reset
        $this->logExamActivity('exam_reset');
        
        // Redirect to remove query string
        $redirect_url = 'mock_exam_start.php' . ($this->class_id ? '?class_id=' . $this->class_id : '');
        header("Location: " . $redirect_url);
        exit();
    }
    
    /**
     * Submit the exam
     */
    private function submitExam(): void
    {
        if ($_SESSION['mock_exam']['started'] && !$_SESSION['mock_exam']['submitted']) {
            $_SESSION['mock_exam']['completed'] = true;
            $_SESSION['mock_exam']['submitted'] = true;
            $_SESSION['mock_exam']['score'] = $this->calculateScore();
            
            // Save exam results to database
            $this->saveExamResults();
            
            // Log exam submission
            $this->logExamActivity('exam_submitted', $_SESSION['mock_exam']['score']);
            
            // Redirect to remove POST data
            $redirect_url = 'mock_exam_start.php' . ($this->class_id ? '?class_id=' . $this->class_id : '');
            header("Location: " . $redirect_url);
            exit();
        }
    }
    
    /**
     * Save answer for current question
     */
    private function saveAnswer(int $question_id, string $answer): void
    {
        $_SESSION['mock_exam']['answers'][$question_id] = $answer;
        
        // Log answer save
        $this->logExamActivity('answer_saved', ['question_id' => $question_id, 'answer' => $answer]);
    }
    
    /**
     * Flag/unflag a question
     */
    private function flagQuestion(int $question_id): void
    {
        if (in_array($question_id, $_SESSION['mock_exam']['flagged'])) {
            // Unflag
            $_SESSION['mock_exam']['flagged'] = array_diff($_SESSION['mock_exam']['flagged'], [$question_id]);
        } else {
            // Flag
            $_SESSION['mock_exam']['flagged'][] = $question_id;
            $_SESSION['mock_exam']['flagged'] = array_unique($_SESSION['mock_exam']['flagged']);
        }
    }
    
    /**
     * Calculate exam score
     */
    private function calculateScore(): int
    {
        $total_questions = count($this->questions);
        $correct_answers = 0;
        $total_points = 0;
        $earned_points = 0;
        
        foreach ($this->questions as $question_id => $question) {
            $total_points += $question['points'];
            
            if (isset($_SESSION['mock_exam']['answers'][$question_id])) {
                $user_answer = $_SESSION['mock_exam']['answers'][$question_id];
                if ($user_answer === $question['correct_answer']) {
                    $correct_answers++;
                    $earned_points += $question['points'];
                }
            }
        }
        
        // Calculate score based on 1000-point scale
        if ($total_points > 0) {
            $percentage = ($earned_points / $total_points) * 100;
            $score = ($percentage / 100) * $this->max_score;
            return (int)round($score);
        }
        
        return 0;
    }
    
    /**
     * Save exam results to database
     */
    private function saveExamResults(): void
    {
        $total_questions = count($this->questions);
        $answered = count($_SESSION['mock_exam']['answers']);
        $flagged = count($_SESSION['mock_exam']['flagged']);
        $score = $_SESSION['mock_exam']['score'];
        $passed = $score >= $this->passing_score ? 1 : 0;
        $time_spent = $this->exam_duration - $_SESSION['mock_exam']['time_remaining'];
        
        $sql = "INSERT INTO mock_exam_results 
                (user_id, class_id, exam_type, total_questions, questions_answered, 
                 flagged_questions, score, passed, time_spent_seconds, submitted_at) 
                VALUES (?, ?, 'MO-300', ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                'iiiiiidi',
                $this->user_id,
                $this->class_id,
                $total_questions,
                $answered,
                $flagged,
                $score,
                $passed,
                $time_spent
            );
            $stmt->execute();
            $result_id = $stmt->insert_id;
            $stmt->close();
            
            // Save detailed answers
            $this->saveDetailedAnswers($result_id);
        }
    }
    
    /**
     * Save detailed answers to database
     */
    private function saveDetailedAnswers(int $result_id): void
    {
        foreach ($_SESSION['mock_exam']['answers'] as $question_id => $answer) {
            $question = $this->questions[$question_id] ?? null;
            if ($question) {
                $is_correct = ($answer === $question['correct_answer']) ? 1 : 0;
                
                // Get original database ID
                $original_id = $_SESSION['mock_exam']['original_ids'][$question_id] ?? $question_id;
                
                // Get question data safely
                $question_text = $question['text'] ?? '';
                $question_options = json_encode($question['options'] ?? []);
                $question_domain = $question['domain'] ?? '';
                $question_type = $question['type'] ?? '';
                $points_possible = (float)($question['points'] ?? 0);
                $points_awarded = $is_correct ? (float)($question['points'] ?? 0) : 0.0;
                
                // Get correct answer text safely
                $correct_answer_key = $question['correct_answer'] ?? '';
                $correct_answer_text = $question['options'][$correct_answer_key] ?? $correct_answer_key;
                
                $sql = "INSERT INTO mock_exam_answers 
                        (result_id, question_id, question_text, question_options, 
                         question_domain, question_type, user_answer, correct_answer, 
                         is_correct, points_possible, points_awarded, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        'iissssssidd',
                        $result_id,
                        $original_id,
                        $question_text,
                        $question_options,
                        $question_domain,
                        $question_type,
                        $answer,
                        $correct_answer_text,
                        $is_correct,
                        $points_possible,
                        $points_awarded
                    );
                    $stmt->execute();
                    $stmt->close();
                } else {
                    error_log("Failed to prepare statement for mock exam answer: " . $this->conn->error);
                }
            }
        }
    }
    
    /**
     * Log exam activity
     */
    private function logExamActivity(string $action, $data = null): void
    {
        $sql = "INSERT INTO exam_activity_log 
                (user_id, class_id, exam_type, action, data, ip_address, user_agent, created_at) 
                VALUES (?, ?, 'MO-300', ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $data_json = $data ? json_encode($data) : null;
            $stmt->bind_param('iissss', $this->user_id, $this->class_id, $action, $data_json, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    /**
     * Get current question
     */
    private function getCurrentQuestion(): array
    {
        $current = $_SESSION['mock_exam']['current_question'];
        return $this->questions[$current] ?? $this->questions[1];
    }
    
    /**
     * Get progress percentage
     */
    private function getProgressPercentage(): int
    {
        $total = count($this->questions);
        $answered = count($_SESSION['mock_exam']['answers']);
        return $total > 0 ? (int)round(($answered / $total) * 100) : 0;
    }
    
    /**
     * Get time remaining in readable format
     */
    private function getTimeRemaining(): string
    {
        $seconds = $_SESSION['mock_exam']['time_remaining'];
        
        if ($seconds <= 0) {
            return '00:00';
        }
        
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        return sprintf('%02d:%02d', $minutes, $seconds);
    }
    
    /**
     * Update time remaining
     */
    private function updateTimeRemaining(): void
    {
        if ($_SESSION['mock_exam']['started'] && !$_SESSION['mock_exam']['completed']) {
            $elapsed = time() - $_SESSION['mock_exam']['start_time'];
            $_SESSION['mock_exam']['time_remaining'] = max(0, $this->exam_duration - $elapsed);
            
            // Auto-submit if time runs out
            if ($_SESSION['mock_exam']['time_remaining'] <= 0) {
                $this->submitExam();
            }
        }
    }
    
    /**
     * Redirect to login
     */
    private function redirectToLogin(): void
    {
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
    
    /**
     * Redirect to dashboard
     */
    private function redirectToDashboard(): void
    {
        header("Location: " . BASE_URL . "modules/" . $this->user_role . "/dashboard.php");
        exit();
    }
    
    /**
     * Handle error
     */
    private function handleError(string $message): void
    {
        die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: $message</div>");
    }
    
    /**
     * Display the mock exam interface
     */
    public function display(): void
    {
        $this->updateTimeRemaining();
        
        if ($_SESSION['mock_exam']['completed']) {
            $this->displayResults();
        } elseif ($_SESSION['mock_exam']['started']) {
            $this->displayExamInterface();
        } else {
            $this->displayStartScreen();
        }
    }
    
    /**
     * Display start screen
     */
    private function displayStartScreen(): void
    {
        // For start screen, we need to load questions to show stats
        // But we don't save them to session yet
        $temp_questions = $this->getTempQuestionsForStartScreen();
        
        // Count performance questions
        $performance_count = 0;
        $multiple_choice_count = 0;
        foreach ($temp_questions as $question) {
            if (strtolower($question['type']) === 'performance') {
                $performance_count++;
            } else {
                $multiple_choice_count++;
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>MO-300 Mock Exam - Impact Digital Academy</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                /* Styles remain the same as before */
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }

                body {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 20px;
                }

                .container {
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    overflow: hidden;
                    max-width: 900px;
                    width: 100%;
                }

                .header {
                    background: linear-gradient(135deg, #d32f2f 0%, #b71c1c 100%);
                    color: white;
                    padding: 40px;
                    text-align: center;
                }

                .header h1 {
                    font-size: 2.5rem;
                    margin-bottom: 10px;
                }

                .header .subtitle {
                    font-size: 1.2rem;
                    opacity: 0.9;
                }

                .exam-badge {
                    display: inline-block;
                    background: rgba(255,255,255,0.2);
                    padding: 8px 20px;
                    border-radius: 20px;
                    margin-top: 15px;
                    font-weight: 600;
                    font-size: 0.9rem;
                }

                .content {
                    padding: 40px;
                }

                .exam-info-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }

                .info-card {
                    background: #f8f9fa;
                    border-radius: 10px;
                    padding: 25px;
                    text-align: center;
                    border: 1px solid #e0e0e0;
                    transition: transform 0.3s, box-shadow 0.3s;
                }

                .info-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
                }

                .info-card i {
                    font-size: 2.5rem;
                    color: #d32f2f;
                    margin-bottom: 15px;
                }

                .info-card h3 {
                    color: #333;
                    margin-bottom: 10px;
                    font-size: 1.2rem;
                }

                .info-card p {
                    color: #666;
                    font-size: 0.9rem;
                }

                .warning-box {
                    background: #fff3e0;
                    border-left: 5px solid #ff9800;
                    padding: 20px;
                    margin: 30px 0;
                    border-radius: 0 10px 10px 0;
                }

                .warning-box h3 {
                    color: #ff9800;
                    margin-bottom: 10px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .instructions {
                    background: #e8f5e9;
                    border-left: 5px solid #4caf50;
                    padding: 20px;
                    margin: 30px 0;
                    border-radius: 0 10px 10px 0;
                }

                .instructions h3 {
                    color: #4caf50;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .instructions ol {
                    padding-left: 25px;
                }

                .instructions li {
                    margin-bottom: 10px;
                }

                .start-button {
                    display: block;
                    width: 100%;
                    padding: 20px;
                    background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
                    color: white;
                    border: none;
                    border-radius: 10px;
                    font-size: 1.3rem;
                    font-weight: bold;
                    cursor: pointer;
                    transition: all 0.3s;
                    text-align: center;
                    text-decoration: none;
                    margin-top: 30px;
                }

                .start-button:hover {
                    background: linear-gradient(135deg, #388e3c 0%, #1b5e20 100%);
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
                }

                .start-button i {
                    margin-right: 10px;
                }

                .back-link {
                    display: inline-block;
                    margin-top: 20px;
                    color: #666;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                }

                .back-link:hover {
                    color: #d32f2f;
                }

                .domain-list {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }

                .domain-item {
                    background: white;
                    padding: 15px;
                    border-radius: 8px;
                    border: 1px solid #ddd;
                    text-align: center;
                    font-size: 0.9rem;
                    font-weight: 600;
                    color: #333;
                }

                .domain-item:nth-child(1) { border-color: #d32f2f; background: #ffebee; }
                .domain-item:nth-child(2) { border-color: #1976d2; background: #e3f2fd; }
                .domain-item:nth-child(3) { border-color: #388e3c; background: #e8f5e9; }
                .domain-item:nth-child(4) { border-color: #ff9800; background: #fff3e0; }
                .domain-item:nth-child(5) { border-color: #7b1fa2; background: #f3e5f5; }

                @media (max-width: 768px) {
                    .header h1 {
                        font-size: 2rem;
                    }
                    
                    .content {
                        padding: 20px;
                    }
                    
                    .exam-info-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>MO-300 Mock Exam</h1>
                    <div class="subtitle">Microsoft PowerPoint (Office 2019) Certification Simulation</div>
                    <div class="exam-badge">Final Week Assessment • Timed Exam</div>
                </div>
                
                <div class="content">
                    <div class="exam-info-grid">
                        <div class="info-card">
                            <i class="fas fa-clock"></i>
                            <h3>Exam Duration</h3>
                            <p>50 Minutes</p>
                            <p style="font-size: 0.8rem; color: #888; margin-top: 5px;">3000 seconds total</p>
                        </div>
                        
                        <div class="info-card">
                            <i class="fas fa-question-circle"></i>
                            <h3>Total Questions</h3>
                            <p><?php echo $this->total_exam_questions; ?> Tasks</p>
                            <p style="font-size: 0.8rem; color: #888; margin-top: 5px;"><?php echo ($this->total_exam_questions - 15); ?> Multiple Choice + 15 Performance</p>
                        </div>
                        
                        <div class="info-card">
                            <i class="fas fa-trophy"></i>
                            <h3>Passing Score</h3>
                            <p>700 / 1000</p>
                            <p style="font-size: 0.8rem; color: #888; margin-top: 5px;">70% minimum to pass</p>
                        </div>
                    </div>
                    
                    <div class="warning-box">
                        <h3><i class="fas fa-exclamation-triangle"></i> Important Notice</h3>
                        <p>This is a timed exam simulation. Once started, the timer cannot be paused. The exam will auto-submit when time expires.</p>
                        <p style="margin-top: 10px; font-weight: bold;">Questions are shuffled on each attempt!</p>
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <li>Each exam attempt shows different questions</li>
                            <li>Always includes 15 performance tasks</li>
                            <li>Total of <?php echo $this->total_exam_questions; ?> questions selected from database</li>
                            <li>Questions remain consistent during the exam session</li>
                        </ul>
                    </div>
                    
                    <div class="instructions">
                        <h3><i class="fas fa-list-ol"></i> Exam Structure</h3>
                        
                        <h4 style="color: #333; margin: 15px 0 10px 0;">Covered Domains:</h4>
                        <div class="domain-list">
                            <?php
                            $domains = $this->getAllDomainsFromDatabase();
                            foreach ($domains as $domain):
                            ?>
                            <div class="domain-item"><?php echo htmlspecialchars($domain); ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <h4 style="color: #333; margin: 20px 0 10px 0;">Question Types:</h4>
                        <ol>
                            <li><strong>Multiple Choice (<?php echo ($this->total_exam_questions - 15); ?> questions):</strong> Test your knowledge of PowerPoint features and functions</li>
                            <li><strong>Performance Tasks (15 questions):</strong> Simulate actual exam tasks - you'll need to complete actions in PowerPoint</li>
                        </ol>
                        
                        <h4 style="color: #333; margin: 20px 0 10px 0;">Download Required Files:</h4>
                        <div style="background: white; padding: 15px; border-radius: 5px; margin: 15px 0;">
                            <p><i class="fas fa-file-powerpoint"></i> <a href="#" style="color: #d32f2f; text-decoration: none; font-weight: bold;">MO300_MockExam_Final.pptx</a> - Main exam file</p>
                            <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">Contains all slides needed for performance tasks</p>
                        </div>
                    </div>
                    
                    <a href="?start=true<?php echo $this->class_id ? '&class_id=' . $this->class_id : ''; ?>" class="start-button">
                        <i class="fas fa-play-circle"></i> Start Mock Exam Now
                    </a>
                    
                    <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week8_view.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="back-link">
                        <i class="fas fa-arrow-left"></i> Return to Week 8 Handout
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Get temporary questions for start screen display
     */
    private function getTempQuestionsForStartScreen(): array
    {
        // Load a sample set for display purposes only
        $temp_questions = [];
        
        $sql = "SELECT DISTINCT question_domain FROM mock_exam_questions 
                WHERE exam_type = 'MO-300' AND is_active = 1 LIMIT 5";
        
        $result = $this->conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $i = 1;
            while ($row = $result->fetch_assoc()) {
                $temp_questions[$i] = [
                    'id' => $i,
                    'domain' => $row['question_domain'],
                    'type' => $i <= 15 ? 'performance' : 'multiple_choice',
                    'text' => 'Sample question from ' . $row['question_domain']
                ];
                $i++;
            }
        }
        
        return $temp_questions;
    }
    
    /**
     * Get all domains from database
     */
    private function getAllDomainsFromDatabase(): array
    {
        $domains = [];
        
        $sql = "SELECT DISTINCT question_domain FROM mock_exam_questions 
                WHERE exam_type = 'MO-300' AND is_active = 1 
                ORDER BY question_domain";
        
        $result = $this->conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $domains[] = $row['question_domain'];
            }
        }
        
        // If no domains found, return default ones
        if (empty($domains)) {
            $domains = [
                'Manage Presentations',
                'Insert and Format',
                'Tables and Charts',
                'Transitions and Animations',
                'Multiple Presentations'
            ];
        }
        
        return $domains;
    }
    
    /**
     * Display exam interface
     */
    private function displayExamInterface(): void
    {
        $current_question = $this->getCurrentQuestion();
        $progress = $this->getProgressPercentage();
        $time_remaining = $this->getTimeRemaining();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>MO-300 Mock Exam In Progress - Impact Digital Academy</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                /* Styles remain the same as before - truncated for brevity */
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

                .exam-header {
                    background: white;
                    padding: 15px 30px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    position: sticky;
                    top: 0;
                    z-index: 100;
                }

                .exam-title {
                    color: #d32f2f;
                    font-size: 1.5rem;
                    font-weight: bold;
                }

                .timer-container {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }

                .timer {
                    background: #d32f2f;
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
                    background: #f44336;
                    animation: pulse 0.5s infinite;
                }

                @keyframes pulse {
                    0% { opacity: 1; }
                    50% { opacity: 0.7; }
                    100% { opacity: 1; }
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
                    background: linear-gradient(90deg, #4caf50, #8bc34a);
                    width: <?php echo $progress; ?>%;
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
                    border-color: #2196f3;
                    background: #e3f2fd;
                }

                .question-btn.current {
                    border-color: #d32f2f;
                    background: #ffebee;
                    color: #d32f2f;
                }

                .question-btn.answered {
                    border-color: #4caf50;
                    background: #e8f5e9;
                    color: #388e3c;
                }

                .question-btn.flagged {
                    border-color: #ff9800;
                    background: #fff3e0;
                    color: #ff9800;
                }

                .question-btn.flagged::after {
                    content: '!';
                    position: absolute;
                    top: -5px;
                    right: -5px;
                    background: #ff9800;
                    color: white;
                    width: 15px;
                    height: 15px;
                    border-radius: 50%;
                    font-size: 0.7rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .domain-info {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                }

                .domain-info h4 {
                    color: #333;
                    margin-bottom: 10px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .domain-tag {
                    display: inline-block;
                    padding: 3px 10px;
                    background: #e0e0e0;
                    border-radius: 3px;
                    font-size: 0.8rem;
                    margin-right: 5px;
                    margin-bottom: 5px;
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
                    box-shadow: 0 3px 15px rgba(0,0,0,0.1);
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

                .performance-instructions {
                    background: #e3f2fd;
                    border-left: 4px solid #2196f3;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 0 8px 8px 0;
                }

                .performance-instructions h4 {
                    color: #1976d2;
                    margin-bottom: 10px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
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
                    border-color: #2196f3;
                    background: #e3f2fd;
                }

                .option.selected {
                    border-color: #4caf50;
                    background: #e8f5e9;
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
                    background: #4caf50;
                }

                .option-text {
                    display: inline;
                    font-size: 1.1rem;
                }

                .exam-controls {
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
                    background: #2196f3;
                    color: white;
                }

                .next-btn:hover {
                    background: #1976d2;
                }

                .flag-btn {
                    background: #fff3e0;
                    color: #ff9800;
                }

                .flag-btn:hover {
                    background: #ffe0b2;
                }

                .flag-btn.flagged {
                    background: #ff9800;
                    color: white;
                }

                .submit-btn {
                    background: #d32f2f;
                    color: white;
                    padding: 15px 40px;
                    font-size: 1.1rem;
                }

                .submit-btn:hover {
                    background: #b71c1c;
                }

                .exam-footer {
                    background: white;
                    padding: 20px;
                    border-top: 1px solid #e0e0e0;
                    text-align: center;
                    position: sticky;
                    bottom: 0;
                    z-index: 100;
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
                    .exam-header {
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
                    
                    .exam-controls {
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
            <div class="exam-header">
                <div class="exam-title">MO-300 Mock Exam</div>
                
                <div class="timer-container">
                    <div class="timer <?php 
                        $minutes = floor($_SESSION['mock_exam']['time_remaining'] / 60);
                        if ($minutes < 10) echo 'warning';
                        if ($minutes < 5) echo 'critical';
                    ?>" id="timerDisplay">
                        <?php echo $time_remaining; ?>
                    </div>
                    
                    <div class="progress-container">
                        <span style="font-size: 0.9rem; color: #666;">Progress:</span>
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <span style="font-weight: bold; color: #333;"><?php echo $progress; ?>%</span>
                    </div>
                </div>
            </div>
            
            <div class="container">
                <div class="sidebar">
                    <div class="question-nav">
                        <h3><i class="fas fa-list-ol"></i> Question Navigation</h3>
                        <div class="question-grid">
                            <?php foreach ($this->questions as $id => $question): ?>
                                <button class="question-btn 
                                    <?php if ($id == $_SESSION['mock_exam']['current_question']) echo 'current'; ?>
                                    <?php if (isset($_SESSION['mock_exam']['answers'][$id])) echo 'answered'; ?>
                                    <?php if (in_array($id, $_SESSION['mock_exam']['flagged'])) echo 'flagged'; ?>"
                                    onclick="goToQuestion(<?php echo $id; ?>)">
                                    <?php echo $id; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="domain-info">
                        <h4><i class="fas fa-layer-group"></i> Current Domain</h4>
                        <div class="domain-tag"><?php echo htmlspecialchars($current_question['domain']); ?></div>
                        <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                            Question <?php echo $_SESSION['mock_exam']['current_question']; ?> of <?php echo count($this->questions); ?>
                        </p>
                        <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">
                            Points: <?php echo $current_question['points']; ?>
                        </p>
                        <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">
                            Type: <?php echo strtoupper($current_question['type']); ?>
                        </p>
                    </div>
                    
                    <div class="instructions-sidebar">
                        <h4><i class="fas fa-lightbulb"></i> Exam Tips</h4>
                        <ul>
                            <li>Read each question carefully</li>
                            <li>Flag questions you're unsure about</li>
                            <li>Manage your time wisely</li>
                            <li>For performance tasks, follow instructions exactly</li>
                            <li>You can change answers before submitting</li>
                        </ul>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 8px;">
                        <h4 style="color: #ff9800; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-exclamation-circle"></i> Need Help?
                        </h4>
                        <p style="font-size: 0.9rem; color: #666;">
                            For technical issues, contact your instructor at: 
                            <strong>support@impactdigitalacademy.com</strong>
                        </p>
                    </div>
                </div>
                
                <div class="main-content">
                    <div class="question-container">
                        <div class="question-header">
                            <div class="question-number">
                                Question <?php echo $_SESSION['mock_exam']['current_question']; ?>
                            </div>
                            <div class="question-type">
                                <?php echo strtoupper($current_question['type']); ?> QUESTION
                            </div>
                        </div>
                        
                        <div class="question-text">
                            <?php echo htmlspecialchars($current_question['text']); ?>
                        </div>
                        
                        <?php if ($current_question['type'] === 'performance' && !empty($current_question['instructions'])): ?>
                        <div class="performance-instructions">
                            <h4><i class="fas fa-tasks"></i> Performance Task Instructions</h4>
                            <p><?php echo htmlspecialchars($current_question['instructions']); ?></p>
                            <p style="margin-top: 10px; font-weight: bold; color: #1976d2;">
                                <i class="fas fa-file-download"></i> 
                                Use the file: <strong>MO300_MockExam_Final.pptx</strong>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="answerForm">
                            <input type="hidden" name="question_id" value="<?php echo $current_question['id']; ?>">
                            <input type="hidden" name="save_answer" value="1">
                            
                            <div class="options-container">
                                <?php foreach ($current_question['options'] as $key => $option): 
                                    if (!empty($option)): ?>
                                <label class="option <?php 
                                    if (isset($_SESSION['mock_exam']['answers'][$current_question['id']]) && 
                                        $_SESSION['mock_exam']['answers'][$current_question['id']] === $key) echo 'selected';
                                ?>">
                                    <input type="radio" 
                                           name="answer" 
                                           value="<?php echo $key; ?>" 
                                           style="display: none;"
                                           <?php 
                                           if (isset($_SESSION['mock_exam']['answers'][$current_question['id']]) && 
                                               $_SESSION['mock_exam']['answers'][$current_question['id']] === $key) echo 'checked';
                                           ?>
                                           onchange="document.getElementById('answerForm').submit()">
                                    <span class="option-label"><?php echo $key; ?></span>
                                    <span class="option-text"><?php echo htmlspecialchars($option); ?></span>
                                </label>
                                <?php endif; 
                                endforeach; ?>
                            </div>
                        </form>
                        
                        <div class="exam-controls">
                            <div>
                                <?php if ($_SESSION['mock_exam']['current_question'] > 1): ?>
                                <button class="control-btn prev-btn" onclick="goToQuestion(<?php echo $_SESSION['mock_exam']['current_question'] - 1; ?>)">
                                    <i class="fas fa-arrow-left"></i> Previous
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="question_id" value="<?php echo $current_question['id']; ?>">
                                    <input type="hidden" name="flag_question" value="1">
                                    <button class="control-btn flag-btn <?php 
                                        if (in_array($current_question['id'], $_SESSION['mock_exam']['flagged'])) echo 'flagged';
                                    ?>" type="submit">
                                        <i class="fas fa-flag"></i>
                                        <?php echo in_array($current_question['id'], $_SESSION['mock_exam']['flagged']) ? 'Unflag' : 'Flag'; ?>
                                    </button>
                                </form>
                                
                                <?php if ($_SESSION['mock_exam']['current_question'] < count($this->questions)): ?>
                                <button class="control-btn next-btn" onclick="goToQuestion(<?php echo $_SESSION['mock_exam']['current_question'] + 1; ?>)">
                                    Next <i class="fas fa-arrow-right"></i>
                                </button>
                                <?php else: ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="submit_exam" value="1">
                                    <button class="control-btn submit-btn" type="submit" onclick="return confirmSubmit()">
                                        <i class="fas fa-paper-plane"></i> Submit Exam
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 3px 15px rgba(0,0,0,0.1);">
                        <h3 style="color: #333; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-chart-bar"></i> Exam Statistics
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: bold; color: #4caf50;">
                                    <?php echo count($_SESSION['mock_exam']['answers']); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">Questions Answered</div>
                            </div>
                            
                            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: bold; color: #ff9800;">
                                    <?php echo count($_SESSION['mock_exam']['flagged']); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">Flagged Questions</div>
                            </div>
                            
                            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: bold; color: #2196f3;">
                                    <?php echo count($this->questions) - count($_SESSION['mock_exam']['answers']); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">Remaining Questions</div>
                            </div>
                            
                            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: bold; color: #d32f2f;">
                                    <?php echo $minutes; ?>:<?php echo sprintf('%02d', $_SESSION['mock_exam']['time_remaining'] % 60); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">Time Remaining</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="exam-footer">
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="submit_exam" value="1">
                    <button class="control-btn submit-btn" type="submit" onclick="return confirmSubmit()" style="padding: 12px 30px;">
                        <i class="fas fa-stop-circle"></i> Submit Exam Now
                    </button>
                </form>
                <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                    You can submit early or wait for the timer to expire. Once submitted, you cannot return to the exam.
                </p>
            </div>
            
            <script>
                // Timer countdown
                let timeRemaining = <?php echo $_SESSION['mock_exam']['time_remaining']; ?>;
                let examSubmitted = <?php echo $_SESSION['mock_exam']['submitted'] ? 'true' : 'false'; ?>;
                
                function updateTimer() {
                    if (timeRemaining <= 0 || examSubmitted) {
                        document.getElementById('timerDisplay').textContent = '00:00';
                        if (!examSubmitted && timeRemaining <= 0) {
                            autoSubmitExam();
                        }
                        return;
                    }
                    
                    timeRemaining--;
                    
                    const minutes = Math.floor(timeRemaining / 60);
                    const seconds = timeRemaining % 60;
                    
                    const timerDisplay = document.getElementById('timerDisplay');
                    timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                    
                    // Update timer styling
                    if (minutes < 10) {
                        timerDisplay.className = 'timer warning';
                    }
                    if (minutes < 5) {
                        timerDisplay.className = 'timer critical';
                    }
                    
                    // Auto-save time every 30 seconds
                    if (timeRemaining % 30 === 0) {
                        saveTimeRemaining();
                    }
                }
                
                // Update timer every second
                setInterval(updateTimer, 1000);
                
                // Save time remaining to server
                function saveTimeRemaining() {
                    fetch('?save_time=true&time=' + timeRemaining + '<?php echo $this->class_id ? "&class_id=" . $this->class_id : ""; ?>', {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                }
                
                // Navigate to question
                function goToQuestion(questionId) {
                    window.location.href = '?question=' + questionId + '<?php echo $this->class_id ? "&class_id=" . $this->class_id : ""; ?>';
                }
                
                // Confirm exam submission
                function confirmSubmit() {
                    const answered = <?php echo count($_SESSION['mock_exam']['answers']); ?>;
                    const total = <?php echo count($this->questions); ?>;
                    
                    return confirm('Are you sure you want to submit the exam?\n\nOnce submitted, you cannot return to the exam.\n\nAnswered: ' + answered + ' / ' + total + ' questions');
                }
                
                // Auto-submit exam when time expires
                function autoSubmitExam() {
                    examSubmitted = true;
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'submit_exam';
                    input.value = '1';
                    
                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                }
                
                // Keyboard shortcuts
                document.addEventListener('keydown', function(e) {
                    // Next question: Right arrow or Space
                    if (e.key === 'ArrowRight' || e.key === ' ') {
                        e.preventDefault();
                        if (<?php echo $_SESSION['mock_exam']['current_question']; ?> < <?php echo count($this->questions); ?>) {
                            goToQuestion(<?php echo $_SESSION['mock_exam']['current_question']; ?> + 1);
                        }
                    }
                    
                    // Previous question: Left arrow
                    if (e.key === 'ArrowLeft') {
                        e.preventDefault();
                        if (<?php echo $_SESSION['mock_exam']['current_question']; ?> > 1) {
                            goToQuestion(<?php echo $_SESSION['mock_exam']['current_question']; ?> - 1);
                        }
                    }
                    
                    // Select answer: 1-4 keys
                    if (e.key >= '1' && e.key <= '4') {
                        e.preventDefault();
                        const answerKey = String.fromCharCode(64 + parseInt(e.key)); // A, B, C, or D
                        const radioInput = document.querySelector(`input[name="answer"][value="${answerKey}"]`);
                        if (radioInput) {
                            radioInput.checked = true;
                            document.getElementById('answerForm').submit();
                        }
                    }
                    
                    // Select answer: A-D keys
                    if (e.key >= 'a' && e.key <= 'd') {
                        e.preventDefault();
                        const answerKey = e.key.toUpperCase();
                        const radioInput = document.querySelector(`input[name="answer"][value="${answerKey}"]`);
                        if (radioInput) {
                            radioInput.checked = true;
                            document.getElementById('answerForm').submit();
                        }
                    }
                    
                    // Submit exam: Ctrl+Enter
                    if (e.ctrlKey && e.key === 'Enter') {
                        e.preventDefault();
                        if (confirmSubmit()) {
                            document.querySelector('input[name="submit_exam"]').closest('form').submit();
                        }
                    }
                });
                
                // Auto-save on page visibility change
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden) {
                        saveTimeRemaining();
                    }
                });
            </script>
        </body>
        </html>
        <?php
    }
    
    /**
     * Display exam results
     */
    private function displayResults(): void
    {
        $score = $_SESSION['mock_exam']['score'];
        $passed = $score >= $this->passing_score;
        $total_questions = count($this->questions);
        $answered = count($_SESSION['mock_exam']['answers']);
        $correct = 0;
        
        foreach ($_SESSION['mock_exam']['answers'] as $question_id => $answer) {
            $question = $this->questions[$question_id] ?? null;
            if ($question && $answer === $question['correct_answer']) {
                $correct++;
            }
        }
        
        $percentage = $total_questions > 0 ? round(($correct / $total_questions) * 100) : 0;
        $time_spent = $this->exam_duration - $_SESSION['mock_exam']['time_remaining'];
        $time_spent_formatted = sprintf('%02d:%02d', floor($time_spent / 60), $time_spent % 60);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>MO-300 Mock Exam Results - Impact Digital Academy</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                /* Styles remain the same as before - truncated for brevity */
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }

                body {
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 20px;
                }

                .container {
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.1);
                    overflow: hidden;
                    max-width: 1000px;
                    width: 100%;
                }

                .header {
                    background: linear-gradient(135deg, <?php echo $passed ? '#4caf50' : '#d32f2f'; ?> 0%, <?php echo $passed ? '#2e7d32' : '#b71c1c'; ?> 100%);
                    color: white;
                    padding: 40px;
                    text-align: center;
                    position: relative;
                    overflow: hidden;
                }

                .header::before {
                    content: '';
                    position: absolute;
                    top: -50%;
                    left: -50%;
                    width: 200%;
                    height: 200%;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
                    background-size: 30px 30px;
                    animation: float 20s linear infinite;
                    opacity: 0.3;
                }

                @keyframes float {
                    0% { transform: translate(0, 0) rotate(0deg); }
                    100% { transform: translate(-30px, -30px) rotate(360deg); }
                }

                .result-icon {
                    font-size: 4rem;
                    margin-bottom: 20px;
                    animation: bounce 1s;
                }

                @keyframes bounce {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(-20px); }
                }

                .header h1 {
                    font-size: 2.5rem;
                    margin-bottom: 10px;
                }

                .header .score {
                    font-size: 4rem;
                    font-weight: bold;
                    margin: 20px 0;
                    text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
                }

                .pass-badge {
                    display: inline-block;
                    background: rgba(255,255,255,0.2);
                    padding: 10px 30px;
                    border-radius: 50px;
                    font-weight: bold;
                    font-size: 1.2rem;
                    margin-top: 10px;
                }

                .content {
                    padding: 40px;
                }

                .results-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }

                .result-card {
                    background: #f8f9fa;
                    border-radius: 10px;
                    padding: 25px;
                    text-align: center;
                    border: 1px solid #e0e0e0;
                }

                .result-card i {
                    font-size: 2rem;
                    color: #666;
                    margin-bottom: 15px;
                }

                .result-card .value {
                    font-size: 2rem;
                    font-weight: bold;
                    color: #333;
                    margin-bottom: 5px;
                }

                .result-card .label {
                    color: #666;
                    font-size: 0.9rem;
                }

                .domain-breakdown {
                    margin: 40px 0;
                }

                .domain-breakdown h3 {
                    color: #333;
                    margin-bottom: 20px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .domain-bar {
                    margin-bottom: 15px;
                }

                .domain-name {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 5px;
                    font-weight: 600;
                    color: #333;
                }

                .bar-container {
                    height: 20px;
                    background: #e0e0e0;
                    border-radius: 10px;
                    overflow: hidden;
                }

                .bar-fill {
                    height: 100%;
                    border-radius: 10px;
                    transition: width 1s ease-out;
                }

                .domain-1 .bar-fill { background: linear-gradient(90deg, #d32f2f, #ff5252); }
                .domain-2 .bar-fill { background: linear-gradient(90deg, #1976d2, #42a5f5); }
                .domain-3 .bar-fill { background: linear-gradient(90deg, #388e3c, #66bb6a); }
                .domain-4 .bar-fill { background: linear-gradient(90deg, #ff9800, #ffb74d); }
                .domain-5 .bar-fill { background: linear-gradient(90deg, #7b1fa2, #ab47bc); }

                .recommendations {
                    background: <?php echo $passed ? '#e8f5e9' : '#ffebee'; ?>;
                    border-left: 5px solid <?php echo $passed ? '#4caf50' : '#d32f2f'; ?>;
                    padding: 25px;
                    margin: 30px 0;
                    border-radius: 0 10px 10px 0;
                }

                .recommendations h3 {
                    color: <?php echo $passed ? '#2e7d32' : '#b71c1c'; ?>;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .action-buttons {
                    display: flex;
                    gap: 15px;
                    margin-top: 30px;
                    flex-wrap: wrap;
                }

                .action-btn {
                    flex: 1;
                    min-width: 200px;
                    padding: 15px;
                    border: none;
                    border-radius: 8px;
                    font-weight: bold;
                    font-size: 1.1rem;
                    cursor: pointer;
                    transition: all 0.3s;
                    text-align: center;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                }

                .action-btn.retake {
                    background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
                    color: white;
                }

                .action-btn.retake:hover {
                    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(25, 118, 210, 0.3);
                }

                .action-btn.review {
                    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
                    color: white;
                }

                .action-btn.review:hover {
                    background: linear-gradient(135deg, #f57c00 0%, #e65100 100%);
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(245, 124, 0, 0.3);
                }

                .action-btn.dashboard {
                    background: linear-gradient(135deg, #9e9e9e 0%, #757575 100%);
                    color: white;
                }

                .action-btn.dashboard:hover {
                    background: linear-gradient(135deg, #757575 0%, #616161 100%);
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(117, 117, 117, 0.3);
                }

                .action-btn.certification {
                    background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
                    color: white;
                }

                .action-btn.certification:hover {
                    background: linear-gradient(135deg, #388e3c 0%, #1b5e20 100%);
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(56, 142, 60, 0.3);
                }

                .detailed-results {
                    margin-top: 40px;
                }

                .detailed-results h3 {
                    color: #333;
                    margin-bottom: 20px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .question-review {
                    margin-bottom: 20px;
                    padding: 20px;
                    background: #f8f9fa;
                    border-radius: 8px;
                    border: 1px solid #e0e0e0;
                }

                .question-header {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 10px;
                }

                .question-status {
                    padding: 3px 10px;
                    border-radius: 3px;
                    font-size: 0.8rem;
                    font-weight: bold;
                }

                .correct { background: #e8f5e9; color: #2e7d32; }
                .incorrect { background: #ffebee; color: #d32f2f; }
                .unanswered { background: #f5f5f5; color: #666; }

                .correct-answer {
                    background: #e8f5e9;
                    padding: 10px;
                    border-radius: 5px;
                    margin-top: 10px;
                    font-weight: bold;
                    color: #2e7d32;
                }

                .your-answer {
                    background: #fff3e0;
                    padding: 10px;
                    border-radius: 5px;
                    margin-top: 5px;
                    color: #f57c00;
                }

                @media (max-width: 768px) {
                    .header h1 {
                        font-size: 2rem;
                    }
                    
                    .header .score {
                        font-size: 3rem;
                    }
                    
                    .content {
                        padding: 20px;
                    }
                    
                    .results-grid {
                        grid-template-columns: 1fr;
                    }
                    
                    .action-buttons {
                        flex-direction: column;
                    }
                    
                    .action-btn {
                        min-width: 100%;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="result-icon">
                        <i class="fas <?php echo $passed ? 'fa-trophy' : 'fa-redo'; ?>"></i>
                    </div>
                    <h1><?php echo $passed ? 'Congratulations!' : 'Practice Makes Perfect!'; ?></h1>
                    <div class="score"><?php echo $score; ?>/1000</div>
                    <div class="pass-badge">
                        <?php echo $passed ? 'PASSED' : 'NOT PASSED'; ?> 
                        (Required: <?php echo $this->passing_score; ?>)
                    </div>
                    <p style="margin-top: 20px; opacity: 0.9;">
                        <?php echo $passed 
                            ? 'You have demonstrated proficiency in PowerPoint skills!' 
                            : 'Review the areas below and try again.'; ?>
                    </p>
                </div>
                
                <div class="content">
                    <div class="results-grid">
                        <div class="result-card">
                            <i class="fas fa-percentage"></i>
                            <div class="value"><?php echo $percentage; ?>%</div>
                            <div class="label">Overall Score</div>
                        </div>
                        
                        <div class="result-card">
                            <i class="fas fa-check-circle"></i>
                            <div class="value"><?php echo $correct; ?>/<?php echo $total_questions; ?></div>
                            <div class="label">Correct Answers</div>
                        </div>
                        
                        <div class="result-card">
                            <i class="fas fa-clock"></i>
                            <div class="value"><?php echo $time_spent_formatted; ?></div>
                            <div class="label">Time Spent</div>
                        </div>
                        
                        <div class="result-card">
                            <i class="fas fa-flag"></i>
                            <div class="value"><?php echo count($_SESSION['mock_exam']['flagged']); ?></div>
                            <div class="label">Flagged Questions</div>
                        </div>
                    </div>
                    
                    <div class="domain-breakdown">
                        <h3><i class="fas fa-chart-pie"></i> Performance by Domain</h3>
                        <?php
                        $domains = [];
                        $domain_correct = [];
                        $domain_total = [];
                        
                        foreach ($this->questions as $question) {
                            $domain = $question['domain'] ?? 'Unknown';
                            if (!isset($domains[$domain])) {
                                $domains[$domain] = true;
                                $domain_correct[$domain] = 0;
                                $domain_total[$domain] = 0;
                            }
                            $domain_total[$domain]++;
                            
                            if (isset($_SESSION['mock_exam']['answers'][$question['id']]) && 
                                $_SESSION['mock_exam']['answers'][$question['id']] === $question['correct_answer']) {
                                $domain_correct[$domain]++;
                            }
                        }
                        
                        $i = 1;
                        foreach ($domains as $domain_name => $value):
                            if ($domain_total[$domain_name] > 0):
                                $domain_percentage = round(($domain_correct[$domain_name] / $domain_total[$domain_name]) * 100);
                        ?>
                        <div class="domain-bar domain-<?php echo $i; ?>">
                            <div class="domain-name">
                                <span><?php echo htmlspecialchars($domain_name); ?></span>
                                <span><?php echo $domain_percentage; ?>%</span>
                            </div>
                            <div class="bar-container">
                                <div class="bar-fill" style="width: <?php echo $domain_percentage; ?>%;"></div>
                            </div>
                            <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                                <?php echo $domain_correct[$domain_name]; ?> / <?php echo $domain_total[$domain_name]; ?> correct
                            </div>
                        </div>
                        <?php
                            $i++;
                            endif;
                        endforeach;
                        ?>
                    </div>
                    
                    <div class="recommendations">
                        <h3><i class="fas fa-lightbulb"></i> 
                            <?php echo $passed ? 'Next Steps:' : 'Areas for Improvement:'; ?>
                        </h3>
                        
                        <?php if ($passed): ?>
                        <ul>
                            <li>You're ready for the official MO-300 certification exam!</li>
                            <li>Schedule your exam within the next 7-14 days while knowledge is fresh</li>
                            <li>Review any flagged questions from this mock exam</li>
                            <li>Practice with additional materials in the course portal</li>
                            <li>Visit Pearson VUE to schedule your official exam</li>
                        </ul>
                        <?php else: ?>
                        <ul>
                            <li>Focus on domains where you scored below 70%</li>
                            <li>Review Week 5 materials (Transitions & Animations) - this was your weakest area</li>
                            <li>Practice performance tasks with the provided PowerPoint files</li>
                            <li>Take advantage of additional practice quizzes in the course portal</li>
                            <li>Consider retaking this mock exam after additional study</li>
                        </ul>
                        <?php endif; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="?reset=true<?php echo $this->class_id ? '&class_id=' . $this->class_id : ''; ?>" 
                           class="action-btn retake">
                            <i class="fas fa-redo"></i> Retake Mock Exam
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week8_view.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" 
                           class="action-btn review">
                            <i class="fas fa-book"></i> Review Week 8 Materials
                        </a>
                        
                        <?php if ($passed): ?>
                        <a href="https://home.pearsonvue.com/microsoft" 
                           target="_blank" 
                           class="action-btn certification">
                            <i class="fas fa-calendar-alt"></i> Schedule Certification Exam
                        </a>
                        <?php endif; ?>
                        
                        <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/dashboard.php" 
                           class="action-btn dashboard">
                            <i class="fas fa-home"></i> Return to Dashboard
                        </a>
                    </div>
                    
                    <div class="detailed-results">
                        <h3><i class="fas fa-list"></i> Detailed Question Review</h3>
                        <?php foreach ($this->questions as $question): 
                            $user_answer = $_SESSION['mock_exam']['answers'][$question['id']] ?? null;
                            $is_correct = $user_answer && $user_answer === $question['correct_answer'];
                            $status = $user_answer 
                                ? ($is_correct ? 'correct' : 'incorrect') 
                                : 'unanswered';
                            
                            // Safely get option text
                            $correct_answer_key = $question['correct_answer'] ?? '';
                            $correct_option_text = $question['options'][$correct_answer_key] ?? $correct_answer_key;
                            
                            $user_option_text = '';
                            if ($user_answer && isset($question['options'][$user_answer])) {
                                $user_option_text = $question['options'][$user_answer];
                            }
                        ?>
                        <div class="question-review">
                            <div class="question-header">
                                <div style="font-weight: bold; color: #333;">
                                    Question <?php echo $question['id']; ?>: <?php echo htmlspecialchars($question['domain']); ?>
                                </div>
                                <div class="question-status <?php echo $status; ?>">
                                    <?php echo strtoupper($status); ?>
                                </div>
                            </div>
                            
                            <p style="margin-bottom: 10px;"><?php echo htmlspecialchars($question['text']); ?></p>
                            
                            <div class="correct-answer">
                                <i class="fas fa-check"></i> 
                                Correct Answer: <?php echo htmlspecialchars($correct_answer_key); ?> - 
                                <?php echo htmlspecialchars($correct_option_text); ?>
                            </div>
                            
                            <?php if ($user_answer): ?>
                            <div class="your-answer">
                                <i class="fas fa-user"></i> 
                                Your Answer: <?php echo htmlspecialchars($user_answer); ?> - 
                                <?php echo htmlspecialchars($user_option_text); ?>
                            </div>
                            <?php else: ?>
                            <div class="your-answer">
                                <i class="fas fa-times"></i> 
                                You did not answer this question
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($question['type'] === 'performance' && !empty($question['instructions'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: #e3f2fd; border-radius: 5px; font-size: 0.9rem;">
                                <strong>Performance Task:</strong> <?php echo htmlspecialchars($question['instructions']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <script>
                // Animate domain bars
                document.addEventListener('DOMContentLoaded', function() {
                    const bars = document.querySelectorAll('.bar-fill');
                    bars.forEach(bar => {
                        const width = bar.style.width;
                        bar.style.width = '0%';
                        setTimeout(() => {
                            bar.style.width = width;
                        }, 500);
                    });
                });
                
                // Print results
                function printResults() {
                    window.print();
                }
                
                // Add keyboard shortcut for printing
                document.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && e.key === 'p') {
                        e.preventDefault();
                        printResults();
                    }
                });
            </script>
        </body>
        </html>
        <?php
    }
}

// Initialize and display the mock exam
try {
    $mockExam = new MO300MockExam();
    $mockExam->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>