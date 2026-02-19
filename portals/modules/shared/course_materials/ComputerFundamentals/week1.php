<?php
// modules/shared/course_materials/ComputerFundamentals/week1.php

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
 * Computer Fundamentals Week 1 Handout Viewer Class
 */
class ComputerFundamentalsWeek1Viewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor', 'admin'];
    
    // User details
    private $user_email;
    private $first_name;
    private $last_name;
    private $instructor_name;
    private $instructor_email;
    
    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
        $this->loadUserDetails();
        $this->loadInstructorDetails();
    }
    
    /**
     * Validate user session and authentication
     */
    private function validateSession(): void
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
            $this->redirectToLogin();
        }
        
        if (!in_array($_SESSION['user_role'], $this->allowed_roles)) {
            $this->showAccessDenied("Invalid user role");
        }
    }
    
    /**
     * Initialize class properties from session and request
     */
    private function initializeProperties(): void
    {
        $this->user_id = (int)$_SESSION['user_id'];
        $this->user_role = $_SESSION['user_role'];
        $this->class_id = $this->getValidatedClassId();
    }
    
    /**
     * Get and validate class ID from query parameters
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
        // Use your existing database connection function
        if (function_exists('getDBConnection')) {
            $conn = getDBConnection();
        } else {
            // Fallback connection (modify with your credentials)
            $servername = "localhost";
            $username = "your_username";
            $password = "your_password";
            $dbname = "your_database";
            
            $conn = new mysqli($servername, $username, $password, $dbname);
        }
        
        if (!$conn || $conn->connect_error) {
            $this->handleError("Database connection failed. Please check your configuration.");
        }
        
        return $conn;
    }
    
    /**
     * Load user details from database
     */
    private function loadUserDetails(): void
    {
        // Try to get from session first
        $this->user_email = $_SESSION['user_email'] ?? '';
        $this->first_name = $_SESSION['first_name'] ?? '';
        $this->last_name = $_SESSION['last_name'] ?? '';
        
        // If we have a database connection, try to load from DB
        if ($this->conn) {
            try {
                $sql = "SELECT email, first_name, last_name FROM users WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param('i', $this->user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $this->user_email = $row['email'];
                        $this->first_name = $row['first_name'];
                        $this->last_name = $row['last_name'];
                    }
                    
                    $stmt->close();
                }
            } catch (Exception $e) {
                // Use session data if DB fails
                error_log("Failed to load user details: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Load instructor details from database
     */
    private function loadInstructorDetails(): void
    {
        // Default values
        $this->instructor_name = 'Your Instructor';
        $this->instructor_email = 'instructor@impactdigitalacademy.com';
        
        // If we have a class_id, get the specific instructor for this class
        if ($this->class_id !== null && $this->conn) {
            try {
                $sql = "SELECT u.email, u.first_name, u.last_name 
                        FROM class_batches cb 
                        JOIN users u ON cb.instructor_id = u.id 
                        WHERE cb.id = ?";
                
                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('i', $this->class_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $this->instructor_name = $row['first_name'] . ' ' . $row['last_name'];
                        $this->instructor_email = $row['email'];
                    }
                    $stmt->close();
                }
            } catch (Exception $e) {
                error_log("Failed to load instructor details: " . $e->getMessage());
            }
        }
        
        // Fallback to session data if available
        if (isset($_SESSION['instructor_name'])) {
            $this->instructor_name = $_SESSION['instructor_name'];
        }
        if (isset($_SESSION['instructor_email'])) {
            $this->instructor_email = $_SESSION['instructor_email'];
        }
    }
    
    /**
     * Validate user access to the Computer Fundamentals course material
     */
    private function validateAccess(): void
    {
        // Admins always have access
        if ($this->user_role === 'admin') {
            return;
        }
        
        // If no database connection, allow access (for testing)
        if (!$this->conn) {
            return;
        }
        
        $access_count = 0;
        
        if ($this->class_id !== null) {
            $access_count = $this->user_role === 'student' 
                ? $this->checkStudentAccess() 
                : $this->checkInstructorAccess();
        } else {
            $access_count = $this->user_role === 'student'
                ? $this->checkGeneralStudentAccess()
                : $this->checkGeneralInstructorAccess();
        }
        
        if ($access_count === 0) {
            $this->showAccessDenied("You are not enrolled in Computer Fundamentals course");
        }
    }
    
    /**
     * Check if student has access to specific Computer Fundamentals class
     */
    private function checkStudentAccess(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM enrollments e 
                    JOIN class_batches cb ON e.class_id = cb.id
                    JOIN courses c ON cb.course_id = c.id
                    WHERE e.student_id = ? 
                    AND e.class_id = ? 
                    AND e.status IN ('active', 'completed')
                    AND (c.title LIKE '%Computer Fundamentals%' 
                         OR c.title LIKE '%Computer Basics%'
                         OR c.title LIKE '%Introduction to Computers%')";
            
            return $this->executeAccessQuery($sql, [$this->user_id, $this->class_id]);
        } catch (Exception $e) {
            error_log("Student access check failed: " . $e->getMessage());
            return 1; // Allow access for testing if query fails
        }
    }
    
    /**
     * Check if instructor has access to specific Computer Fundamentals class
     */
    private function checkInstructorAccess(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM class_batches cb 
                    JOIN courses c ON cb.course_id = c.id
                    WHERE cb.id = ? 
                    AND cb.instructor_id = ?
                    AND (c.title LIKE '%Computer Fundamentals%' 
                         OR c.title LIKE '%Computer Basics%'
                         OR c.title LIKE '%Introduction to Computers%')";
            
            return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
        } catch (Exception $e) {
            error_log("Instructor access check failed: " . $e->getMessage());
            return 1; // Allow access for testing if query fails
        }
    }
    
    /**
     * Check general student access to Computer Fundamentals courses
     */
    private function checkGeneralStudentAccess(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM enrollments e 
                    JOIN class_batches cb ON e.class_id = cb.id
                    JOIN courses c ON cb.course_id = c.id
                    WHERE e.student_id = ? 
                    AND e.status IN ('active', 'completed')
                    AND (c.title LIKE '%Computer Fundamentals%' 
                         OR c.title LIKE '%Computer Basics%'
                         OR c.title LIKE '%Introduction to Computers%')";
            
            return $this->executeAccessQuery($sql, [$this->user_id]);
        } catch (Exception $e) {
            error_log("General student access check failed: " . $e->getMessage());
            return 1; // Allow access for testing if query fails
        }
    }
    
    /**
     * Check general instructor access to Computer Fundamentals courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM class_batches cb 
                    JOIN courses c ON cb.course_id = c.id
                    WHERE cb.instructor_id = ?
                    AND (c.title LIKE '%Computer Fundamentals%' 
                         OR c.title LIKE '%Computer Basics%'
                         OR c.title LIKE '%Introduction to Computers%')";
            
            return $this->executeAccessQuery($sql, [$this->user_id]);
        } catch (Exception $e) {
            error_log("General instructor access check failed: " . $e->getMessage());
            return 1; // Allow access for testing if query fails
        }
    }
    
    /**
     * Execute access check query
     */
    private function executeAccessQuery(string $sql, array $params): int
    {
        if (!$this->conn) {
            return 0;
        }
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log("Failed to prepare access query: " . $this->conn->error);
            return 0;
        }
        
        try {
            $types = str_repeat('i', count($params));
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $stmt->close();
            
            return (int)($row['count'] ?? 0);
        } catch (Exception $e) {
            error_log("Access query execution failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Generate PDF using mPDF
     */
    public function generatePDF(): void
    {
        // Check if PDF generation is requested
        if (!isset($_GET['download']) || $_GET['download'] !== 'pdf') {
            return;
        }
        
        // For now, just show a message that PDF is not available
        // In production, you would implement actual PDF generation
        $this->showPDFError("PDF generation is temporarily unavailable. Please use the print function instead.");
    }
    
    /**
     * Show PDF error
     */
    private function showPDFError(string $errorMessage = ''): void
    {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>PDF Generation</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; }
                .error-box { background: #ffebee; border: 1px solid #f44336; padding: 20px; border-radius: 5px; margin: 20px 0; }
                button { padding: 10px 20px; background: #0d3d8c; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 10px; }
            </style>
        </head>
        <body>
            <h1>PDF Download</h1>
            
            <div class="error-box">
                <h3>Information:</h3>
                <p>' . htmlspecialchars($errorMessage) . '</p>
            </div>
            
            <div>
                <button onclick="window.print()">
                    <i class="fas fa-print"></i> Print Handout Instead
                </button>
                <button onclick="history.back()">
                    <i class="fas fa-arrow-left"></i> Go Back
                </button>
            </div>
        </body>
        </html>';
        exit();
    }
    
    /**
     * Get HTML content for PDF generation
     */
    private function getHTMLContentForPDF(): string
    {
        ob_start();
        $this->renderPDFContent();
        return ob_get_clean();
    }
    
    /**
     * Render PDF content
     */
    private function renderPDFContent(): void
    {
        $studentName = $this->first_name . ' ' . $this->last_name;
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6;">
            <h1 style="color: #0d3d8c; text-align: center; margin-bottom: 30px;">
                Computer Fundamentals - Week 1: Introduction to Computers
            </h1>
            
            <!-- Student Information -->
            <div style="margin-bottom: 20px; text-align: center;">
                <p><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y'); ?></p>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Content would go here -->
            <h2>Week 1: Introduction to Laptop Computers</h2>
            <p>This is a simplified PDF version. For full content, please use the online handout.</p>
            
        </div>
        <?php
    }
    
    /**
     * Display the enhanced handout HTML page
     */
    public function display(): void
    {
        // Check if PDF download is requested
        if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
            $this->generatePDF();
            exit();
        }
        
        // Output the enhanced HTML page
        $this->renderEnhancedHTMLPage();
    }
    
    /**
     * Render enhanced HTML page for 3-hour class with week-long engagement
     */
    private function renderEnhancedHTMLPage(): void
    {
        $studentName = $this->first_name . ' ' . $this->last_name;
        $base_url = defined('BASE_URL') ? BASE_URL : '/';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 1: Introduction to Laptop Computers - Computer Fundamentals</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0d3d8c;
            --secondary-color: #185abd;
            --accent-color: #4caf50;
            --warning-color: #ff9800;
            --danger-color: #f44336;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--light-bg);
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        /* Access Header */
        .access-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .access-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .access-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .back-link {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            transition: background 0.3s;
        }

        .back-link:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Header */
        .course-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .course-header h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
        }

        .course-subtitle {
            font-size: 1.4rem;
            opacity: 0.9;
            margin-bottom: 15px;
        }

        .week-tag {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
        }

        /* Content */
        .content {
            padding: 40px;
        }

        /* Sections */
        .content-section {
            margin-bottom: 50px;
            padding-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-title {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .section-title i {
            color: var(--secondary-color);
        }

        .subsection {
            margin-bottom: 30px;
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            border-left: 5px solid var(--secondary-color);
        }

        .subsection-title {
            color: var(--secondary-color);
            font-size: 1.4rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Activity Cards */
        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin: 30px 0;
        }

        .activity-card {
            background: var(--card-bg);
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 25px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .activity-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .activity-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
            text-align: center;
        }

        .activity-time {
            display: inline-block;
            background: #e8f0ff;
            color: var(--secondary-color);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin: 10px 0;
        }

        /* Laptop Parts */
        .laptop-parts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .part-item {
            background: var(--card-bg);
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
        }

        .part-item:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Weekly Plan */
        .week-plan {
            background: #fff9e6;
            border: 2px solid var(--warning-color);
            border-radius: 10px;
            padding: 30px;
            margin: 40px 0;
        }

        .day-plan {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px dashed #ffcc80;
        }

        .day-plan:last-child {
            border-bottom: none;
        }

        /* Quiz */
        .quiz-box {
            background: #e8f5e9;
            border: 2px solid var(--accent-color);
            border-radius: 10px;
            padding: 25px;
            margin: 30px 0;
        }

        .quiz-question {
            margin-bottom: 20px;
        }

        .quiz-options {
            display: grid;
            gap: 15px;
            margin: 20px 0;
        }

        .quiz-option {
            padding: 15px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .quiz-option:hover {
            border-color: var(--secondary-color);
            background: #e8f0ff;
        }

        /* Buttons */
        .download-btn {
            display: inline-block;
            background: var(--secondary-color);
            color: white;
            padding: 12px 25px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            margin: 10px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .download-btn:hover {
            background: var(--primary-color);
            transform: translateY(-2px);
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 30px;
            background: #f2f2f2;
            color: #666;
            border-top: 1px solid #ddd;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content {
                padding: 20px;
            }
            
            .course-header {
                padding: 30px 20px;
            }
            
            .course-header h1 {
                font-size: 1.8rem;
            }
            
            .activities-grid {
                grid-template-columns: 1fr;
            }
            
            .laptop-parts {
                grid-template-columns: 1fr;
            }
            
            .access-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Access Header -->
    <div class="access-header">
        <div class="access-info">
            <div>
                <strong>Computer Fundamentals - Week 1</strong>
            </div>
            <div class="access-badge">
                <?php echo ucfirst($this->user_role); ?> Access
            </div>
            <div style="font-size: 0.9rem; opacity: 0.9;">
                <?php if ($this->user_role === 'student'): ?>
                    <i class="fas fa-user-graduate"></i> Student View
                <?php elseif ($this->user_role === 'instructor'): ?>
                    <i class="fas fa-chalkboard-teacher"></i> Instructor View
                <?php else: ?>
                    <i class="fas fa-user-shield"></i> Admin View
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($this->class_id): ?>
            <a href="<?php echo $base_url; ?>modules/<?php echo $this->user_role; ?>/classes/class_home.php?id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
        <?php else: ?>
            <a href="<?php echo $base_url; ?>modules/<?php echo $this->user_role; ?>/dashboard.php" class="back-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <!-- Course Header -->
        <div class="course-header">
            <h1>Impact Digital Academy</h1>
            <div class="course-subtitle">Computer Fundamentals Program</div>
            <div style="font-size: 1.6rem; margin: 15px 0; font-weight: 600;">
                Week 1: Mastering Your Laptop Computer
            </div>
            <div class="week-tag">Week 1 of 4 • Beginner Level • 3-Hour Saturday Class</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div id="welcome" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-door-open"></i> Welcome to Your First Laptop Class!</h2>
                
                <div class="subsection">
                    <p style="font-size: 1.2rem; line-height: 1.8; margin-bottom: 20px;">
                        Welcome to your journey into the digital world! This <strong>3-hour Saturday class</strong> is designed specifically for complete beginners. We'll focus on <strong>laptop computers</strong> as they're the most practical device for learning and daily use.
                    </p>
                    
                    <div style="background: #e8f0ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <h3 style="color: var(--primary-color); margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Class Structure</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div style="text-align: center; padding: 15px; background: white; border-radius: 5px;">
                                <i class="fas fa-chalkboard-teacher" style="font-size: 2rem; color: var(--secondary-color); margin-bottom: 10px;"></i>
                                <h4>Live Instruction</h4>
                                <p>90 minutes guided learning</p>
                            </div>
                            <div style="text-align: center; padding: 15px; background: white; border-radius: 5px;">
                                <i class="fas fa-hands-helping" style="font-size: 2rem; color: var(--accent-color); margin-bottom: 10px;"></i>
                                <h4>Hands-On Practice</h4>
                                <p>60 minutes practical exercises</p>
                            </div>
                            <div style="text-align: center; padding: 15px; background: white; border-radius: 5px;">
                                <i class="fas fa-question-circle" style="font-size: 2rem; color: var(--warning-color); margin-bottom: 10px;"></i>
                                <h4>Q&A Session</h4>
                                <p>30 minutes questions & answers</p>
                            </div>
                        </div>
                    </div>
                    
                    <p style="font-size: 1.1rem; line-height: 1.8;">
                        <strong>Your Week-Long Journey:</strong> After our Saturday class, you'll have daily practice activities to build your skills throughout the week. By next Saturday, you'll be comfortable with basic laptop operations!
                    </p>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div id="objectives" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-bullseye"></i> Week 1 Learning Objectives</h2>
                
                <div class="subsection">
                    <h3 class="subsection-title"><i class="fas fa-graduation-cap"></i> By the end of this week, you will be able to:</h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 25px 0;">
                        <div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid var(--accent-color);">
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                <div style="background: var(--accent-color); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-laptop"></i>
                                </div>
                                <h4 style="color: var(--primary-color);">Laptop Setup</h4>
                            </div>
                            <ul>
                                <li>Identify all key laptop components</li>
                                <li>Set up laptop for first-time use</li>
                                <li>Connect to Wi-Fi network</li>
                                <li>Create user account with secure password</li>
                            </ul>
                        </div>
                        
                        <div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid var(--secondary-color);">
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                <div style="background: var(--secondary-color); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-keyboard"></i>
                                </div>
                                <h4 style="color: var(--primary-color);">Basic Operations</h4>
                            </div>
                            <ul>
                                <li>Power laptop on/off properly</li>
                                <li>Use touchpad with confidence</li>
                                <li>Type basic text using keyboard</li>
                                <li>Navigate Windows interface</li>
                            </ul>
                        </div>
                        
                        <div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid var(--warning-color);">
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                <div style="background: var(--warning-color); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <h4 style="color: var(--primary-color);">Laptop Care & Safety</h4>
                            </div>
                            <ul>
                                <li>Practice safe laptop handling</li>
                                <li>Create system restore point</li>
                                <li>Basic laptop maintenance</li>
                                <li>Setup antivirus protection</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Laptop Parts -->
            <div id="laptop-basics" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-laptop"></i> Understanding Your Laptop Computer</h2>
                
                <div class="subsection">
                    <h3 class="subsection-title"><i class="fas fa-search"></i> Laptop Parts Identification</h3>
                    
                    <div class="laptop-parts">
                        <div class="part-item">
                            <div class="activity-icon">
                                <i class="fas fa-tv"></i>
                            </div>
                            <h4>Screen/Display</h4>
                            <p>Shows everything - like a TV</p>
                            <div style="background: #e8f0ff; padding: 5px 10px; border-radius: 15px; margin-top: 10px; font-size: 0.9rem;">
                                <i class="fas fa-lightbulb"></i> Tip: Adjust brightness for comfort
                            </div>
                        </div>
                        
                        <div class="part-item">
                            <div class="activity-icon">
                                <i class="fas fa-keyboard"></i>
                            </div>
                            <h4>Keyboard</h4>
                            <p>For typing text and commands</p>
                            <div style="background: #e8f0ff; padding: 5px 10px; border-radius: 15px; margin-top: 10px; font-size: 0.9rem;">
                                <i class="fas fa-lightbulb"></i> Tip: Find Fn key for special functions
                            </div>
                        </div>
                        
                        <div class="part-item">
                            <div class="activity-icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <h4>Touchpad</h4>
                            <p>Controls pointer - like a mouse</p>
                            <div style="background: #e8f0ff; padding: 5px 10px; border-radius: 15px; margin-top: 10px; font-size: 0.9rem;">
                                <i class="fas fa-lightbulb"></i> Tip: Two-finger scroll for web pages
                            </div>
                        </div>
                        
                        <div class="part-item">
                            <div class="activity-icon">
                                <i class="fas fa-power-off"></i>
                            </div>
                            <h4>Power Button</h4>
                            <p>Turns laptop on/off</p>
                            <div style="background: #e8f0ff; padding: 5px 10px; border-radius: 15px; margin-top: 10px; font-size: 0.9rem;">
                                <i class="fas fa-lightbulb"></i> Tip: Usually top-right of keyboard
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Activities -->
            <div id="class-activities" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-tasks"></i> 3-Hour Class Activities</h2>
                
                <div class="activities-grid">
                    <div class="activity-card">
                        <div class="activity-icon">
                            <i class="fas fa-power-off"></i>
                        </div>
                        <h3>Activity 1: Power On Ritual</h3>
                        <div class="activity-time">30 minutes</div>
                        <p>Practice turning laptop on/off 5 times. Learn proper shutdown sequence.</p>
                        <div style="background: #f0f7ff; padding: 10px; border-radius: 5px; margin-top: 15px;">
                            <strong>Steps:</strong>
                            <ol style="margin-top: 5px; padding-left: 20px;">
                                <li>Press power button</li>
                                <li>Wait for login screen</li>
                                <li>Enter password</li>
                                <li>Start → Power → Shutdown</li>
                            </ol>
                        </div>
                    </div>
                    
                    <div class="activity-card">
                        <div class="activity-icon">
                            <i class="fas fa-mouse-pointer"></i>
                        </div>
                        <h3>Activity 2: Touchpad Olympics</h3>
                        <div class="activity-time">45 minutes</div>
                        <p>Complete touchpad challenges: clicking, dragging, scrolling, right-clicking.</p>
                        <div style="background: #f0f7ff; padding: 10px; border-radius: 5px; margin-top: 15px;">
                            <strong>Challenges:</strong>
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Move pointer to each screen corner</li>
                                <li>Drag icons across desktop</li>
                                <li>Scroll through long document</li>
                                <li>Open context menus</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="activity-card">
                        <div class="activity-icon">
                            <i class="fas fa-keyboard"></i>
                        </div>
                        <h3>Activity 3: Typing Bootcamp</h3>
                        <div class="activity-time">60 minutes</div>
                        <p>Learn proper finger placement and type simple sentences. Focus on accuracy.</p>
                        <div style="background: #f0f7ff; padding: 10px; border-radius: 5px; margin-top: 15px;">
                            <strong>Exercises:</strong>
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Type name 10 times</li>
                                <li>Type alphabet A-Z</li>
                                <li>Type numbers 0-9</li>
                                <li>Type simple paragraph</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Practice Plan -->
            <div id="week-plan" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-calendar-week"></i> Week-Long Practice Plan</h2>
                
                <div class="week-plan">
                    <h3 style="color: var(--warning-color); margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-calendar-alt"></i> Daily Practice Activities (20-30 minutes each)
                    </h3>
                    
                    <div class="day-plan">
                        <h4 style="color: var(--primary-color); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-sun"></i> Sunday: Laptop Setup Day
                        </h4>
                        <ul>
                            <li>Set up laptop at home (follow guide)</li>
                            <li>Create folder on desktop named "My Work"</li>
                            <li>Take photo of your setup</li>
                            <li><strong>Goal:</strong> Comfortable with basic setup</li>
                        </ul>
                    </div>
                    
                    <div class="day-plan">
                        <h4 style="color: var(--primary-color); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-moon"></i> Monday: Touchpad Mastery Day
                        </h4>
                        <ul>
                            <li>Practice all touchpad gestures 10 times each</li>
                            <li>Play simple online mouse games</li>
                            <li>Organize desktop icons by dragging</li>
                            <li><strong>Goal:</strong> Confident touchpad control</li>
                        </ul>
                    </div>
                    
                    <div class="day-plan">
                        <h4 style="color: var(--primary-color); margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-keyboard"></i> Tuesday: Keyboard Skills Day
                        </h4>
                        <ul>
                            <li>Type your full address 5 times</li>
                            <li>Practice Shift key for capital letters</li>
                            <li>Use online typing tutor for 15 minutes</li>
                            <li><strong>Goal:</strong> Type without looking at keys</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Interactive Quiz -->
            <div id="assessment" class="content-section animate-in">
                <h2 class="section-title"><i class="fas fa-clipboard-check"></i> Week 1 Knowledge Check</h2>
                
                <div class="quiz-box">
                    <h3 style="color: #2e7d32; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-brain"></i> Interactive Quiz: Test Your Understanding
                    </h3>
                    
                    <div class="quiz-question">
                        <p><strong>Question 1:</strong> Where is the power button typically located on a laptop?</p>
                        <div class="quiz-options">
                            <div class="quiz-option" onclick="checkAnswer(this, 'correct')">Top-right of keyboard area</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Bottom of the screen</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Left side near USB ports</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Under the touchpad</div>
                        </div>
                    </div>
                    
                    <div class="quiz-question">
                        <p><strong>Question 2:</strong> What does the Fn key help you do?</p>
                        <div class="quiz-options">
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Turn laptop off</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Open Start menu</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'correct')">Access special functions (brightness, volume)</div>
                            <div class="quiz-option" onclick="checkAnswer(this, 'wrong')">Connect to Wi-Fi</div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 30px; text-align: center;">
                        <button class="download-btn" onclick="showQuizResults()">
                            <i class="fas fa-chart-bar"></i> See Quiz Results
                        </button>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <h3 style="color: var(--primary-color); margin-bottom: 20px;">Download Learning Materials</h3>
                <button class="download-btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Handout
                </button>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <?php if ($this->class_id): ?>
                    <a href="<?php echo $base_url; ?>modules/shared/course_materials/ComputerFundamentals/week2.php?class_id=<?php echo $this->class_id; ?>" class="download-btn" style="background: var(--accent-color);">
                        <i class="fas fa-arrow-right"></i> Go to Week 2
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer>
            <p style="font-size: 1.1rem; margin-bottom: 10px;">Computer Fundamentals Program – Week 1: Mastering Your Laptop</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Beginner Computer Education</p>
            
            <div style="display: flex; justify-content: center; gap: 30px; margin: 20px 0; flex-wrap: wrap;">
                <div style="text-align: center;">
                    <div style="font-size: 0.9rem; color: #666;">Student</div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($studentName); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.9rem; color: #666;">Instructor</div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($this->instructor_name); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.9rem; color: #666;">Access Level</div>
                    <div style="font-weight: 600;"><?php echo ucfirst($this->user_role); ?></div>
                </div>
            </div>
            
            <?php if ($this->user_role === 'admin'): ?>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9rem; color: #666;">
                    <i class="fas fa-user-shield"></i> Admin Access – Full System Permissions
                </div>
            <?php endif; ?>
        </footer>
    </div>

    <script>
        // Quiz functionality
        let correctAnswers = 0;
        let totalQuestions = 2;
        
        function checkAnswer(element, result) {
            // Reset all options in this question
            const question = element.closest('.quiz-question');
            const options = question.querySelectorAll('.quiz-option');
            
            options.forEach(option => {
                option.style.background = '';
                option.style.color = '';
            });
            
            // Highlight selected option
            if (result === 'correct') {
                element.style.background = '#4caf50';
                element.style.color = 'white';
                correctAnswers++;
            } else {
                element.style.background = '#f44336';
                element.style.color = 'white';
                
                // Find and highlight correct answer
                const correctOption = question.querySelector('.quiz-option[onclick*="correct"]');
                if (correctOption) {
                    correctOption.style.background = '#4caf50';
                    correctOption.style.color = 'white';
                }
            }
            
            // Disable all options in this question
            options.forEach(opt => {
                opt.style.pointerEvents = 'none';
            });
        }
        
        function showQuizResults() {
            const score = Math.round((correctAnswers / totalQuestions) * 100);
            alert(`Quiz Results:\n\nCorrect Answers: ${correctAnswers}/${totalQuestions}\nScore: ${score}%\n\n${score >= 70 ? 'Excellent! Ready for Week 2!' : 'Review the material and try again!'}`);
        }
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 100,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // Mark activities as completed
        function markActivityComplete(activityNumber) {
            const activities = document.querySelectorAll('.activity-card');
            if (activities[activityNumber - 1]) {
                const activity = activities[activityNumber - 1];
                activity.style.border = '3px solid #4caf50';
                const checkmark = document.createElement('div');
                checkmark.innerHTML = '<div style="margin-top: 15px; color: #4caf50; font-weight: bold;"><i class="fas fa-check-circle"></i> Completed!</div>';
                activity.appendChild(checkmark);
                alert('Activity marked as complete! Great work!');
            }
        }
        
        // Add completion buttons to activities
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.activity-card').forEach((card, index) => {
                const button = document.createElement('button');
                button.innerHTML = '<i class="fas fa-check"></i> Mark Complete';
                button.style.cssText = 'margin-top: 15px; padding: 8px 15px; background: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer;';
                button.onclick = () => markActivityComplete(index + 1);
                card.appendChild(button);
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl + H for help
            if (e.ctrlKey && e.key === 'h') {
                e.preventDefault();
                alert('Week 1 Help:\n\n1. Practice daily for 20-30 minutes\n2. Follow the weekly plan\n3. Ask questions if stuck\n4. Complete all activities');
            }
        });
    </script>
</body>
</html>
        <?php
    }
    
    /**
     * Redirect to login page
     */
    private function redirectToLogin(): void
    {
        $login_url = defined('BASE_URL') ? BASE_URL . 'login.php' : '/login.php';
        header("Location: " . $login_url);
        exit();
    }
    
    /**
     * Show access denied page
     */
    private function showAccessDenied(string $message = ''): void
    {
        header("HTTP/1.0 403 Forbidden");
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; text-align: center; }
                .error-box { background: #ffebee; border: 1px solid #f44336; padding: 30px; border-radius: 5px; margin: 20px auto; max-width: 600px; }
                h1 { color: #d32f2f; }
                .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0d3d8c; color: white; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <h1><i class="fas fa-lock"></i> Access Denied</h1>
            <div class="error-box">
                <p>' . htmlspecialchars($message ?: 'You do not have permission to access Computer Fundamentals materials.') . '</p>
                <p>Please ensure you are enrolled in the Computer Fundamentals course.</p>
            </div>
            <a href="' . (defined('BASE_URL') ? BASE_URL : '/') . '" class="btn">
                <i class="fas fa-home"></i> Return to Home
            </a>
        </body>
        </html>';
        exit();
    }
    
    /**
     * Handle errors
     */
    private function handleError(string $message): void
    {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Error</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; }
                .error { background: #fee; border: 1px solid #f00; color: #900; padding: 20px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="error">
                <h3>Error</h3>
                <p>' . htmlspecialchars($message) . '</p>
                <p>Please contact technical support if this error persists.</p>
            </div>
        </body>
        </html>';
        exit();
    }
}

// Initialize and display the handout
try {
    $viewer = new ComputerFundamentalsWeek1Viewer();
    $viewer->display();
} catch (Exception $e) {
    echo '<div style="padding: 20px; background: #fee; border: 1px solid #f00; color: #900;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>