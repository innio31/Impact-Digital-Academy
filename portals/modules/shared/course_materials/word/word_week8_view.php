<?php
// modules/shared/course_materials/MSWord/word_week8_view.php

declare(strict_types=1);

// Error reporting for development (disable in production)
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
 * Word Week 8 Handout Viewer Class with PDF Download
 */
class WordWeek8HandoutViewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor'];
    
    // User details
    private $user_email;
    private $user_name;
    private $instructor_id;
    private $instructor_name;
    private $instructor_email;
    
    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
        $this->fetchUserDetails();
        $this->fetchInstructorDetails();
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
     * Initialize class properties from session and request
     */
    private function initializeProperties(): void
    {
        $this->user_id = (int)$_SESSION['user_id'];
        $this->user_role = $_SESSION['user_role'];
        $this->user_email = $_SESSION['user_email'] ?? '';
        $this->user_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
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
        $conn = getDBConnection();
        
        if (!$conn) {
            $this->handleError("Database connection failed. Please check your configuration.");
        }
        
        return $conn;
    }
    
    /**
     * Fetch user details from database
     */
    private function fetchUserDetails(): void
    {
        $sql = "SELECT u.email, u.first_name, u.last_name, u.phone 
        FROM users u 
        WHERE u.id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        
        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $this->user_email = $row['email'] ?? $this->user_email;
            $this->user_name = ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '');
        }
        
        $stmt->close();
    }
    
    /**
     * Fetch instructor details for the class
     */
    private function fetchInstructorDetails(): void
    {
        if ($this->class_id === null) {
            // If no class_id, try to get the instructor from user's courses
            $this->fetchGeneralInstructorDetails();
            return;
        }
        
        $sql = "SELECT u.id as instructor_id, u.first_name, u.last_name, u.email, 
                       u.phone, up.qualifications, up.experience_years
                FROM class_batches cb
                JOIN users u ON cb.instructor_id = u.id
                LEFT JOIN user_profiles up ON u.id = up.user_id
                WHERE cb.id = ? 
                AND u.role = 'instructor'
                AND u.status = 'active'";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        
        $stmt->bind_param('i', $this->class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $this->instructor_id = $row['instructor_id'];
            $this->instructor_name = $row['first_name'] . ' ' . $row['last_name'];
            $this->instructor_email = $row['email'];
        } else {
            // Fallback to default instructor
            $this->instructor_name = "Your Instructor";
            $this->instructor_email = "instructor@impactdigitalacademy.com";
        }
        
        $stmt->close();
    }
    
    /**
     * Fetch general instructor details when no specific class
     */
    private function fetchGeneralInstructorDetails(): void
    {
        // Try to get the most recent instructor for the user's Word course
        $sql = "SELECT DISTINCT u.id as instructor_id, u.first_name, u.last_name, u.email
                FROM enrollments e
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN users u ON cb.instructor_id = u.id
                WHERE e.student_id = ?
                AND c.title LIKE '%Microsoft Word (Office 2019)%'
                AND u.role = 'instructor'
                AND u.status = 'active'
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->instructor_name = "Your Instructor";
            $this->instructor_email = "instructor@impactdigitalacademy.com";
            return;
        }
        
        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $this->instructor_id = $row['instructor_id'];
            $this->instructor_name = $row['first_name'] . ' ' . $row['last_name'];
            $this->instructor_email = $row['email'];
        } else {
            // Fallback to default instructor
            $this->instructor_name = "Your Instructor";
            $this->instructor_email = "instructor@impactdigitalacademy.com";
        }
        
        $stmt->close();
    }
    
    /**
     * Validate user access to the course material
     */
    private function validateAccess(): void
    {
        if ($this->class_id !== null) {
            $access_count = $this->user_role === 'student' 
                ? $this->checkStudentAccess() 
                : $this->checkInstructorAccess();
            
            if ($access_count === 0) {
                $this->showAccessDenied();
            }
        } else {
            $access_count = $this->user_role === 'student'
                ? $this->checkGeneralStudentAccess()
                : $this->checkGeneralInstructorAccess();
            
            if ($access_count === 0) {
                $this->redirectToDashboard();
            }
        }
    }
    
    /**
     * Check if student has access to specific class
     */
    private function checkStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.class_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id, $this->class_id]);
    }
    
    /**
     * Check if instructor has access to specific class
     */
    private function checkInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.id = ? 
                AND cb.instructor_id = ?
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to Word courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to Word courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Execute access check query
     */
    private function executeAccessQuery(string $sql, array $params): int
    {
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return 0;
        }
        
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $stmt->close();
        
        return (int)($row['count'] ?? 0);
    }
    
    /**
     * Check if mPDF is available
     */
    private function isMPDFAvailable(): bool
    {
        // Check multiple possible locations
        $possiblePaths = [
            __DIR__ . '/../../../../vendor/mpdf/mpdf/src/Mpdf.php',
            __DIR__ . '/../../../../vendor/autoload.php',
            '/usr/share/php/mpdf/mpdf/src/Mpdf.php',
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return true;
            }
        }
        
        return false;
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
        
        // First, try to include Composer autoloader
        $autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        } else {
            // Fallback: try to include mPDF directly
            $mpdfPath = __DIR__ . '/../../../../vendor/mpdf/mpdf/src/Mpdf.php';
            if (file_exists($mpdfPath)) {
                require_once $mpdfPath;
            } else {
                // If mPDF is not found, show error with installation instructions
                $this->showPDFError();
                return;
            }
        }
        
        // Get HTML content
        $htmlContent = $this->getHTMLContentForPDF();
        
        try {
            // Check PHP version compatibility
            if (version_compare(PHP_VERSION, '7.1.0') < 0) {
                throw new Exception('PHP 7.1.0 or higher is required for mPDF 8+. Your PHP version: ' . PHP_VERSION);
            }
            
            // Initialize mPDF
            $mpdfConfig = [
                'mode' => 'utf-8',
                'format' => 'A4',
                'default_font_size' => 12,
                'default_font' => 'dejavusans',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'margin_header' => 10,
                'margin_footer' => 10,
                'tempDir' => sys_get_temp_dir() // Set temp directory
            ];
            
            // Try different mPDF class names based on version
            try {
                $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            } catch (Exception $e) {
                // Try with older class name
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            // Set document information
            $mpdf->SetTitle('Week 8: Mock Exam & Final Review Session');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Certification, Mock Exam, Final Review');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week8_Mock_Exam_Final_Review_' . date('Y-m-d') . '.pdf';
            
            // Clear any previous output
            if (ob_get_length()) {
                ob_end_clean();
            }
            
            // Set headers for download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            
            $mpdf->Output($filename, 'D');
            exit();
            
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('PDF Generation Error: ' . $e->getMessage());
            
            // Show user-friendly error
            $this->showPDFError($e->getMessage());
        }
    }
    
    /**
     * Show PDF error with troubleshooting tips
     */
    private function showPDFError(string $errorMessage = ''): void
    {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>PDF Generation Error</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; background: #f8f9fa; }
                .error-box { background: #ffebee; border: 1px solid #f44336; padding: 20px; border-radius: 5px; margin: 20px 0; }
                .solution-box { background: #e8f5e9; border: 1px solid #4caf50; padding: 20px; border-radius: 5px; }
                code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
            </style>
        </head>
        <body>
            <h1>PDF Generation Error</h1>
            
            <div class="error-box">
                <h3>Error Details:</h3>
                <p>' . htmlspecialchars($errorMessage ?: 'mPDF library not found or not properly installed') . '</p>
            </div>
            
            <div class="solution-box">
                <h3>Solution:</h3>
                <p>Please install mPDF using Composer:</p>
                <ol>
                    <li>SSH into your server or use your hosting control panel terminal</li>
                    <li>Navigate to your website root directory</li>
                    <li>Run: <code>composer require mpdf/mpdf</code></li>
                    <li>If you don\'t have Composer, install it first: <code>curl -sS https://getcomposer.org/installer | php</code></li>
                </ol>
                
                <p><strong>Alternative:</strong> Download and install mPDF manually:</p>
                <ol>
                    <li>Download from: <a href="https://github.com/mpdf/mpdf/releases" target="_blank">https://github.com/mpdf/mpdf/releases</a></li>
                    <li>Extract to: <code>' . htmlspecialchars(__DIR__ . '/../../../../vendor/mpdf/mpdf/') . '</code></li>
                    <li>Ensure the directory structure is: <code>vendor/mpdf/mpdf/src/Mpdf.php</code></li>
                </ol>
                
                <p><strong>Temporary Solution:</strong> Use the print function instead:</p>
                <button onclick="window.print()" style="padding: 10px 20px; background: #0d3d8c; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    <i class="fas fa-print"></i> Print Handout
                </button>
                <button onclick="history.back()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                    <i class="fas fa-arrow-left"></i> Go Back
                </button>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: #e3f2fd; border-radius: 5px;">
                <h4>Technical Information:</h4>
                <ul>
                    <li>PHP Version: ' . PHP_VERSION . '</li>
                    <li>Server: ' . $_SERVER['SERVER_SOFTWARE'] . '</li>
                    <li>mPDF Status: ' . ($this->isMPDFAvailable() ? 'Detected' : 'Not Found') . '</li>
                </ul>
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
        $user_role = $this->user_role;
        $user_name = $this->user_name;
        $user_email = $this->user_email;
        $instructor_name = $this->instructor_name;
        $instructor_email = $this->instructor_email;
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 12pt;">
            <h1 style="color: #0d3d8c; border-bottom: 2px solid #0d3d8c; padding-bottom: 10px; font-size: 18pt;">
                Week 8: Mock Exam & Final Review Session
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 8 – Final Session!</h2>
                <p style="margin-bottom: 15px;">
                    This week marks the culmination of your 8-week journey. We will simulate the actual MO-100 exam experience with a timed mock exam, followed by a comprehensive review and Q&A session. This final class is designed to build your confidence, identify any remaining knowledge gaps, and ensure you are fully prepared to pass the Microsoft Word (Office 2019) certification exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Confidently navigate the format and structure of the MO-100 exam.</li>
                    <li>Apply time-management strategies in a simulated exam environment.</li>
                    <li>Review and reinforce key skills across all exam domains.</li>
                    <li>Identify and address final areas of improvement.</li>
                    <li>Understand the process for scheduling and taking the official certification exam.</li>
                </ul>
            </div>
            
            <!-- Session Structure -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Session Structure</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">Part 1: Mock Exam (90 minutes)</h3>
                <ul>
                    <li>You will take a full-length, timed practice exam that mirrors the real MO-100 in structure, difficulty, and question types.</li>
                    <li>The exam includes:
                        <ul>
                            <li>Multiple-choice questions</li>
                            <li>Drag-and-drop tasks</li>
                            <li>Performance-based tasks (simulated Word environment)</li>
                        </ul>
                    </li>
                    <li><strong>Instructions:</strong>
                        <ul>
                            <li>No external resources, notes, or assistance.</li>
                            <li>Manage your time wisely—aim to complete all questions.</li>
                            <li>Flag questions you're unsure of and return to them if time allows.</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">Part 2: Exam Review & Answer Walkthrough (60 minutes)</h3>
                <ul>
                    <li>We will review each section of the mock exam together.</li>
                    <li>The instructor will:
                        <ul>
                            <li>Provide correct answers and explanations.</li>
                            <li>Demonstrate solutions for performance-based tasks.</li>
                            <li>Discuss common pitfalls and exam "trick" questions.</li>
                        </ul>
                    </li>
                    <li>Bring your questions! This is your chance to clarify anything before the real exam.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">Part 3: Final Q&A & Exam Logistics (30 minutes)</h3>
                <ul>
                    <li>Open floor for last-minute questions on any topic.</li>
                    <li>Guidance on:
                        <ul>
                            <li>Scheduling your exam (via Certiport or Pearson VUE).</li>
                            <li>What to bring (ID, confirmation number).</li>
                            <li>Exam day tips (arrival time, environment, breaks).</li>
                            <li>Post-exam steps (score report, certification badge).</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Mock Exam Overview -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Mock Exam Overview</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #0d3d8c; color: white;">
                            <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Section</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Exam Objective Area</th>
                            <th style="padding: 8px; text-align: center; border: 1px solid #ddd;"># of Questions</th>
                            <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Approx. Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">1</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Manage Documents</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">5–7</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">2</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Insert and Format Text, Paragraphs, Sections</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">5–7</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">3</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Manage Tables and Lists</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">4–6</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">4</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Insert and Format Graphic Elements</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">4–6</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">5</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Create and Manage References</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">2–4</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">5 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">6</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Manage Document Collaboration</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">2–4</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">5 mins</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">7</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Performance-Based Tasks</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">3–5 tasks</td>
                            <td style="padding: 6px 8px; text-align: center; border: 1px solid #ddd;">40 mins</td>
                        </tr>
                        <tr style="background-color: #f0f7ff; font-weight: bold;">
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;" colspan="2">Total</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">Approx. 45–60 items</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">90 mins</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Exam-Day Strategies -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Exam-Day Strategies</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">Before the Exam:</h3>
                <ul>
                    <li><strong>Rest Well:</strong> Get a good night's sleep.</li>
                    <li><strong>Arrive Early:</strong> Log in 15 minutes before your scheduled time.</li>
                    <li><strong>Check Your Setup:</strong> Ensure your computer, internet, and testing software are working.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">During the Exam:</h3>
                <ul>
                    <li><strong>Read Carefully:</strong> Pay close attention to wording like "NOT," "BEST," "FIRST."</li>
                    <li><strong>Manage Time:</strong> Don't linger on one question. Flag and return later.</li>
                    <li><strong>Use the Tools:</strong> In performance tasks, use the Word interface as you've practiced.</li>
                    <li><strong>Review:</strong> If time permits, check flagged questions before submitting.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">After the Exam:</h3>
                <ul>
                    <li><strong>Instant Results:</strong> You'll see your score immediately.</li>
                    <li><strong>Score Report:</strong> Review areas of strength and weakness.</li>
                    <li><strong>Certification:</strong> If you pass, download your digital badge and certificate.</li>
                </ul>
            </div>
            
            <!-- Final Skills Checklist -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Final Skills Checklist</h3>
                <p style="margin-bottom: 15px;">Before taking the real exam, ensure you are comfortable with:</p>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #ff9800; color: white;">
                            <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Skill Area</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Key Tasks to Master</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Document Management</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Save as PDF, inspect document, modify properties, share</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Formatting</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Styles, sections, columns, Format Painter, clear formatting</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Tables & Lists</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Convert text/table, sort, merge cells, custom bullets, multi-level lists</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Graphics</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Insert pictures/Shapes/SmartArt, wrap text, alt text, remove backgrounds</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">References</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Footnotes, citations, bibliography, TOC</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Collaboration</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Comments, track changes, accept/reject, lock tracking</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Inspection</td>
                            <td style="padding: 6px 8px; border: 1px solid #ddd;">Accessibility checker, compatibility, document inspector</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Step-by-Step Activity -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">Step-by-Step: This Week's Activity</h3>
                <p><strong>Activity: Full Mock Exam & Self-Review</strong></p>
                <ol>
                    <li>Take the Mock Exam (available in the Course Portal) under timed conditions (90 minutes).</li>
                    <li>After completing, note any questions or tasks you found challenging.</li>
                    <li>Attend the live review session to go over answers and ask questions.</li>
                    <li>Create a personal study plan for your final review before the real exam, focusing on weak areas.</li>
                    <li>Schedule your MO-100 exam before the end of the week (discount code provided in portal).</li>
                </ol>
            </div>
            
            <!-- FAQ -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Frequently Asked Questions (FAQ)</h3>
                
                <div style="margin-bottom: 15px;">
                    <p><strong>Q: What score do I need to pass?</strong></p>
                    <p>A: The passing score is typically 700 out of 1000.</p>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <p><strong>Q: Can I use keyboard shortcuts during the exam?</strong></p>
                    <p>A: Yes, and it's encouraged! They save time.</p>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <p><strong>Q: What if I fail?</strong></p>
                    <p>A: You can retake the exam after 24 hours. Review your score report and focus on weak areas.</p>
                </div>
                
                <div>
                    <p><strong>Q: How long is the certification valid?</strong></p>
                    <p>A: MOS certifications do not expire.</p>
                </div>
            </div>
            
            <!-- Final Encouragement -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Final Encouragement</h3>
                <p>You have worked hard over the past eight weeks to build a strong foundation in Microsoft Word. You have practiced, asked questions, and developed the skills needed to succeed. Trust your preparation, stay calm, and approach the exam with confidence. You are ready!</p>
            </div>
            
            <!-- Post-Course Support -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Post-Course Support</h3>
                <ul>
                    <li><strong>Access to materials:</strong> All handouts, recordings, and practice files remain available in the portal for 6 months.</li>
                    <li><strong>Instructor contact:</strong> You may reach out with questions up to 2 weeks after course completion.</li>
                    <li><strong>LinkedIn:</strong> Add your certification to your LinkedIn profile under "Licenses & Certifications."</li>
                    <li><strong>Alumni network:</strong> Join the Impact Digital Academy alumni group for networking and continued learning.</li>
                </ul>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li><a href="https://certiport.pearsonvue.com/Certifications/Microsoft/MOS/Overview" target="_blank">Certiport MO-100 Exam Page</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/learn/certifications/mos-word-2019" target="_blank">Microsoft Office Specialist Certification Overview</a></li>
                    <li><a href="https://support.microsoft.com/en-us/office/word-2019-quick-start-guide-0a7c0e6e-9d67-44b5-9053-5f80de09b8f5" target="_blank">Word 2019 Quick Reference Guide</a> – For last-minute review.</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #0d3d8c; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($instructor_email); ?></p>
                <p><strong>Course Portal:</strong> Access through your dashboard</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($user_name . ' (' . $user_email . ')'); ?></p>
                <p><strong>Date Accessed:</strong> <?php echo date('F j, Y'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get PDF cover page
     */
    private function getPDFCoverPage(): string
    {
        $studentEmail = $this->user_email;
        $studentName = $this->user_name;
        
        return '
        <div style="text-align: center; padding: 50px 0;">
            <h1 style="color: #0d3d8c; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #185abd; font-size: 18pt; margin-bottom: 30px;">
                Microsoft Word (MO-100) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #0d3d8c; 
                border-bottom: 3px solid #0d3d8c; padding: 20px 0; margin: 30px 0;">
                Week 8 Handout: Mock Exam & Final Review Session
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Email: ' . htmlspecialchars($studentEmail) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Date: ' . date('F j, Y') . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Access Level: ' . ucfirst($this->user_role) . '
                </p>
            </div>
            <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 10pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 9pt;">
                    This handout is part of the MO-100 Word Certification Prep Program. Unauthorized distribution is prohibited.
                </p>
            </div>
        </div>';
    }
    
    /**
     * Get PDF header
     */
    private function getPDFHeader(): string
    {
        return '
        <div style="text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 5px; font-size: 9pt; color: #666;">
            Week 8: Mock Exam & Final Review | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-100 Word Certification Prep | Student: ' . htmlspecialchars($this->user_name) . '
        </div>';
    }
    
    /**
     * Display the handout HTML page
     */
    public function display(): void
    {
        // Check if PDF download is requested
        if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
            $this->generatePDF();
            exit();
        }
        
        // Extract variables for the view
        $user_role = $this->user_role;
        $class_id = $this->class_id;
        $user_name = $this->user_name;
        $user_email = $this->user_email;
        $instructor_name = $this->instructor_name;
        $instructor_email = $this->instructor_email;
        
        // Output the HTML page
        $this->renderHTMLPage($user_role, $class_id, $user_name, $user_email, $instructor_name, $instructor_email);
    }
    
    /**
     * Render the HTML page
     */
    private function renderHTMLPage($user_role, $class_id, $user_name, $user_email, $instructor_name, $instructor_email): void
    {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 8: Mock Exam & Final Review Session - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        /* Access Control Header */
        .access-header {
            background: linear-gradient(135deg, #185abd 0%, #0d3d8c 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #0d3d8c 0%, #185abd 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
        }

        .header .subtitle {
            font-size: 1.4rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .header .week-tag {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 20px;
            border-radius: 20px;
            margin-top: 15px;
            font-weight: 600;
        }

        .content {
            padding: 40px;
        }

        .section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eaeaea;
        }

        .section:last-child {
            border-bottom: none;
        }

        .section-title {
            color: #185abd;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #185abd;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #0d3d8c;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #185abd;
        }

        ul, ol {
            padding-left: 25px;
            margin-bottom: 20px;
        }

        li {
            margin-bottom: 8px;
            position: relative;
        }

        .image-container {
            margin: 25px 0;
            text-align: center;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .image-container img {
            max-width: 100%;
            height: auto;
            border-radius: 5px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .image-caption {
            font-size: 0.9rem;
            color: #666;
            margin-top: 10px;
            font-style: italic;
        }

        .shortcut-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #f9f9f9;
            border-radius: 8px;
            overflow: hidden;
        }

        .shortcut-table th {
            background-color: #0d3d8c;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .shortcut-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .shortcut-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .shortcut-table tr:hover {
            background-color: #e8f0ff;
        }

        .shortcut-key {
            background: #185abd;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exam-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .exam-table th {
            background: #0d3d8c;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #ddd;
        }

        .exam-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            border: 1px solid #ddd;
        }

        .exam-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .exam-table tr:hover {
            background: #e8f0ff;
        }

        .exam-table .total-row {
            background: #e3f2fd;
            font-weight: bold;
        }

        .exercise-box {
            background: #e8f0ff;
            border-left: 5px solid #185abd;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #0d3d8c;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checklist-box {
            background: #fff9e6;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .checklist-title {
            color: #e65100;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tip-box {
            background: #e3f2fd;
            border-left: 5px solid #1976d2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .tip-title {
            color: #1976d2;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .faq-box {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .faq-title {
            color: #7b1fa2;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .support-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .support-title {
            color: #2e7d32;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .encouragement-box {
            background: #fff3e0;
            border-left: 5px solid #f57c00;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .encouragement-title {
            color: #e65100;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #185abd;
            color: white;
            padding: 12px 25px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            margin: 20px 0;
            transition: background 0.3s;
            cursor: pointer;
        }

        .download-btn:hover {
            background: #0d3d8c;
        }

        .learning-objectives {
            background: #f0f7ff;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #0d3d8c;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .resource-list {
            background: #f9f0ff;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .resource-list h3 {
            color: #7b1fa2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .resource-item {
            margin-bottom: 15px;
            padding: 10px;
            border-left: 3px solid #7b1fa2;
            background: #f5f0ff;
        }

        .resource-item a {
            color: #0d3d8c;
            text-decoration: none;
            font-weight: 600;
        }

        .resource-item a:hover {
            text-decoration: underline;
        }

        .exam-focus {
            background: #e8f5e9;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
            border-left: 5px solid #4caf50;
        }

        .exam-focus h3 {
            color: #2e7d32;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .strategy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .strategy-card {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .strategy-card h4 {
            color: #0d3d8c;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        .skill-checklist {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .skill-category {
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            background: #f9f9f9;
        }

        .skill-category h5 {
            color: #0d3d8c;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .skill-category ul {
            padding-left: 20px;
            margin: 0;
        }

        .skill-category li {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .faq-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #ddd;
        }

        .faq-item:last-child {
            border-bottom: none;
        }

        .faq-item strong {
            color: #0d3d8c;
            display: block;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        footer {
            text-align: center;
            padding: 20px;
            background-color: #f2f2f2;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #ddd;
        }

        .pdf-alert {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ff9800;
            color: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.8rem;
            }

            .header .subtitle {
                font-size: 1.2rem;
            }

            .content {
                padding: 20px;
            }

            .access-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .exam-table,
            .shortcut-table {
                font-size: 0.9rem;
            }

            .exam-table th,
            .exam-table td,
            .shortcut-table th,
            .shortcut-table td {
                padding: 10px;
            }

            .strategy-grid,
            .skill-checklist {
                grid-template-columns: 1fr;
            }
        }

        /* Print styles */
        @media print {
            body {
                background-color: white;
                padding: 0;
            }

            .access-header {
                display: none;
            }

            .container {
                box-shadow: none;
                border-radius: 0;
            }

            .download-btn {
                display: none;
            }

            .image-container {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- PDF Alert -->
    <div id="pdfAlert" class="pdf-alert">
        <i class="fas fa-info-circle"></i> 
        <span id="pdfAlertMessage">Generating PDF...</span>
        <button onclick="hidePdfAlert()" style="background: none; border: none; color: white; margin-left: 15px; cursor: pointer;">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Access Control Header -->
    <div class="access-header">
        <div class="access-info">
            <div>
                <strong>Access Granted:</strong> Word Week 8 Handout
            </div>
            <div class="access-badge">
                <?php echo ucfirst($user_role); ?> Access
            </div>
            <?php if ($user_role === 'student'): ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-user-graduate"></i> Student View
                </div>
            <?php else: ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor View
                </div>
            <?php endif; ?>
        </div>
        <?php if ($class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $user_role; ?>/classes/class_home.php?id=<?php echo $class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $user_role; ?>/dashboard.php" class="back-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        <?php endif; ?>
        <?php if ($class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week7_view.php?class_id=<?php echo $class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 7
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 8 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Mock Exam & Final Review Session</div>
            <div class="week-tag">Week 8 of 8 - FINAL SESSION</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-trophy"></i> Welcome to Week 8 – Final Session!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week marks the culmination of your 8-week journey. We will simulate the actual MO-100 exam experience with a timed mock exam, followed by a comprehensive review and Q&A session. This final class is designed to build your confidence, identify any remaining knowledge gaps, and ensure you are fully prepared to pass the Microsoft Word (Office 2019) certification exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1553877522-43269d4ea984?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Certification Success"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+Q2VydGlmaWNhdGlvbiBFeGFtIFByZXBhcmF0aW9uPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Final Preparation for MO-100 Certification Success</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Confidently navigate the format and structure of the MO-100 exam.</li>
                    <li>Apply time-management strategies in a simulated exam environment.</li>
                    <li>Review and reinforce key skills across all exam domains.</li>
                    <li>Identify and address final areas of improvement.</li>
                    <li>Understand the process for scheduling and taking the official certification exam.</li>
                </ul>
            </div>

            <!-- Session Structure -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i> Session Structure
                </div>

                <div class="strategy-grid">
                    <div class="strategy-card">
                        <h4><i class="fas fa-clock"></i> Part 1: Mock Exam (90 minutes)</h4>
                        <ul>
                            <li>Full-length, timed practice exam</li>
                            <li>Mirrors real MO-100 structure</li>
                            <li>Multiple question types</li>
                            <li>Simulated Word environment</li>
                        </ul>
                        <div class="tip-box" style="margin: 15px 0 0; padding: 10px;">
                            <strong>Instructions:</strong> No external resources, manage time wisely, flag uncertain questions.
                        </div>
                    </div>

                    <div class="strategy-card">
                        <h4><i class="fas fa-chalkboard-teacher"></i> Part 2: Exam Review (60 minutes)</h4>
                        <ul>
                            <li>Section-by-section review</li>
                            <li>Correct answers & explanations</li>
                            <li>Performance task demonstrations</li>
                            <li>Common pitfalls discussion</li>
                        </ul>
                        <div class="tip-box" style="margin: 15px 0 0; padding: 10px;">
                            <strong>Bring your questions!</strong> Last chance to clarify before the real exam.
                        </div>
                    </div>

                    <div class="strategy-card">
                        <h4><i class="fas fa-question-circle"></i> Part 3: Final Q&A (30 minutes)</h4>
                        <ul>
                            <li>Open floor for questions</li>
                            <li>Exam scheduling guidance</li>
                            <li>What to bring checklist</li>
                            <li>Exam day tips & post-exam steps</li>
                        </ul>
                        <div class="tip-box" style="margin: 15px 0 0; padding: 10px;">
                            <strong>Preparation:</strong> Review any remaining topics before this session.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mock Exam Overview -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-alt"></i> Mock Exam Overview
                </div>

                <table class="exam-table">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Exam Objective Area</th>
                            <th># of Questions</th>
                            <th>Approx. Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>Manage Documents</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>Insert and Format Text, Paragraphs, Sections</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>Manage Tables and Lists</td>
                            <td style="text-align: center;">4–6</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>Insert and Format Graphic Elements</td>
                            <td style="text-align: center;">4–6</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>Create and Manage References</td>
                            <td style="text-align: center;">2–4</td>
                            <td style="text-align: center;">5 mins</td>
                        </tr>
                        <tr>
                            <td>6</td>
                            <td>Manage Document Collaboration</td>
                            <td style="text-align: center;">2–4</td>
                            <td style="text-align: center;">5 mins</td>
                        </tr>
                        <tr>
                            <td>7</td>
                            <td>Performance-Based Tasks</td>
                            <td style="text-align: center;">3–5 tasks</td>
                            <td style="text-align: center;">40 mins</td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="2"><strong>Total</strong></td>
                            <td style="text-align: center;"><strong>Approx. 45–60 items</strong></td>
                            <td style="text-align: center;"><strong>90 mins</strong></td>
                        </tr>
                    </tbody>
                </table>

                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i> Timing Strategy
                    </div>
                    <p>Allocate your time wisely: Spend about 1-2 minutes per multiple-choice question and 5-8 minutes per performance task. Leave 10 minutes at the end for review.</p>
                </div>
            </div>

            <!-- Key Exam-Day Strategies -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chess-board"></i> Key Exam-Day Strategies
                </div>

                <div class="strategy-grid">
                    <div class="strategy-card">
                        <h4><i class="fas fa-bed"></i> Before the Exam</h4>
                        <ul>
                            <li><strong>Rest Well:</strong> Get 7-8 hours of sleep</li>
                            <li><strong>Arrive Early:</strong> Log in 15+ minutes before</li>
                            <li><strong>Check Setup:</strong> Computer, internet, software</li>
                            <li><strong>Prepare ID:</strong> Government-issued photo ID</li>
                            <li><strong>Test Environment:</strong> Quiet, well-lit space</li>
                        </ul>
                    </div>

                    <div class="strategy-card">
                        <h4><i class="fas fa-hourglass-half"></i> During the Exam</h4>
                        <ul>
                            <li><strong>Read Carefully:</strong> Watch for "NOT," "BEST," "FIRST"</li>
                            <li><strong>Manage Time:</strong> Flag and return to difficult questions</li>
                            <li><strong>Use Tools:</strong> Word interface as practiced</li>
                            <li><strong>Keyboard Shortcuts:</strong> Save time where possible</li>
                            <li><strong>Review:</strong> Check flagged questions before submitting</li>
                        </ul>
                    </div>

                    <div class="strategy-card">
                        <h4><i class="fas fa-medal"></i> After the Exam</h4>
                        <ul>
                            <li><strong>Instant Results:</strong> Score appears immediately</li>
                            <li><strong>Score Report:</strong> Review strength/weakness areas</li>
                            <li><strong>Certification:</strong> Download digital badge if passed</li>
                            <li><strong>Retake Plan:</strong> If needed, wait 24+ hours</li>
                            <li><strong>Celebrate:</strong> Share success on LinkedIn!</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Final Skills Checklist -->
            <div class="checklist-box">
                <div class="checklist-title">
                    <i class="fas fa-check-double"></i> Final Skills Checklist
                </div>
                <p style="margin-bottom: 20px;">Before taking the real exam, ensure you are comfortable with:</p>

                <div class="skill-checklist">
                    <div class="skill-category">
                        <h5>Document Management</h5>
                        <ul>
                            <li>Save as PDF/DOCX</li>
                            <li>Inspect document</li>
                            <li>Modify properties</li>
                            <li>Share documents</li>
                            <li>Protect documents</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>Formatting</h5>
                        <ul>
                            <li>Apply and modify styles</li>
                            <li>Create sections</li>
                            <li>Format columns</li>
                            <li>Use Format Painter</li>
                            <li>Clear formatting</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>Tables & Lists</h5>
                        <ul>
                            <li>Convert text to table</li>
                            <li>Sort table data</li>
                            <li>Merge/split cells</li>
                            <li>Custom bullets</li>
                            <li>Multi-level lists</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>Graphics</h5>
                        <ul>
                            <li>Insert pictures/shapes</li>
                            <li>Create SmartArt</li>
                            <li>Text wrapping</li>
                            <li>Alt text (accessibility)</li>
                            <li>Remove backgrounds</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>References</h5>
                        <ul>
                            <li>Footnotes & endnotes</li>
                            <li>Citations</li>
                            <li>Bibliography</li>
                            <li>Table of contents</li>
                            <li>Captions</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>Collaboration</h5>
                        <ul>
                            <li>Add comments</li>
                            <li>Track changes</li>
                            <li>Accept/reject changes</li>
                            <li>Compare documents</li>
                            <li>Lock tracking</li>
                        </ul>
                    </div>

                    <div class="skill-category">
                        <h5>Inspection</h5>
                        <ul>
                            <li>Accessibility checker</li>
                            <li>Compatibility check</li>
                            <li>Document inspector</li>
                            <li>Check for issues</li>
                            <li>Manage versions</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Step-by-Step Activity -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> Step-by-Step: This Week's Activity
                </div>
                <p><strong>Activity: Full Mock Exam & Self-Review</strong></p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Take the Mock Exam (available in the Course Portal) under timed conditions (90 minutes).</li>
                        <li>After completing, note any questions or tasks you found challenging.</li>
                        <li>Attend the live review session to go over answers and ask questions.</li>
                        <li>Create a personal study plan for your final review before the real exam, focusing on weak areas.</li>
                        <li>Schedule your MO-100 exam before the end of the week (discount code provided in portal).</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Mock Exam Preparation"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Nb2NrIEV4YW0gUHJlcGFyYXRpb248L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Simulated Exam Environment Preparation</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadMockExam()">
                    <i class="fas fa-download"></i> Access Mock Exam (Course Portal)
                </a>
            </div>

            <!-- FAQ -->
            <div class="faq-box">
                <div class="faq-title">
                    <i class="fas fa-question-circle"></i> Frequently Asked Questions (FAQ)
                </div>

                <div class="faq-item">
                    <strong>Q: What score do I need to pass the MO-100 exam?</strong>
                    <p>A: The passing score is typically 700 out of 1000 points. Exact passing scores may vary slightly by testing center.</p>
                </div>

                <div class="faq-item">
                    <strong>Q: Can I use keyboard shortcuts during the exam?</strong>
                    <p>A: Yes, absolutely! Keyboard shortcuts are encouraged as they save time. All standard Word 2019 keyboard shortcuts are allowed.</p>
                </div>

                <div class="faq-item">
                    <strong>Q: What if I fail the exam on my first attempt?</strong>
                    <p>A: You can retake the exam after 24 hours. Review your score report, focus on weak areas, and practice those skills before retaking.</p>
                </div>

                <div class="faq-item">
                    <strong>Q: How long is the Microsoft Office Specialist certification valid?</strong>
                    <p>A: MOS certifications do not expire. They are lifetime certifications that remain valid indefinitely.</p>
                </div>

                <div class="faq-item">
                    <strong>Q: What should I bring to the testing center?</strong>
                    <p>A: Bring two forms of identification (one government-issued photo ID), your exam confirmation number, and any required testing accommodations documentation.</p>
                </div>

                <div class="faq-item">
                    <strong>Q: How soon will I get my results?</strong>
                    <p>A: Results are immediate. You'll see your score on screen as soon as you submit the exam, and you'll receive a printed score report.</p>
                </div>
            </div>

            <!-- Final Encouragement -->
            <div class="encouragement-box">
                <div class="encouragement-title">
                    <i class="fas fa-heart"></i> Final Encouragement
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8;">
                    You have worked hard over the past eight weeks to build a strong foundation in Microsoft Word. You have practiced, asked questions, and developed the skills needed to succeed. Trust your preparation, stay calm, and approach the exam with confidence. Remember:
                </p>
                <ul style="margin-top: 15px;">
                    <li>You know the material – you've practiced it for 8 weeks</li>
                    <li>You understand the exam format – you've taken mock exams</li>
                    <li>You have strategies – for time management and difficult questions</li>
                    <li>You have support – from your instructor and classmates</li>
                </ul>
                <p style="margin-top: 15px; font-weight: bold; font-size: 1.1rem; color: #0d3d8c;">
                    You are ready! Go ace that exam!
                </p>
            </div>

            <!-- Post-Course Support -->
            <div class="support-box">
                <div class="support-title">
                    <i class="fas fa-hands-helping"></i> Post-Course Support
                </div>
                <div class="strategy-grid">
                    <div class="strategy-card">
                        <h5><i class="fas fa-archive"></i> Access to Materials</h5>
                        <p>All handouts, recordings, and practice files remain available in the portal for 6 months after course completion.</p>
                    </div>

                    <div class="strategy-card">
                        <h5><i class="fas fa-envelope"></i> Instructor Contact</h5>
                        <p>You may reach out with questions up to 2 weeks after course completion via email or course portal messaging.</p>
                    </div>

                    <div class="strategy-card">
                        <h5><i class="fab fa-linkedin"></i> Professional Networking</h5>
                        <p>Add your certification to your LinkedIn profile under "Licenses & Certifications" to showcase your achievement.</p>
                    </div>

                    <div class="strategy-card">
                        <h5><i class="fas fa-users"></i> Alumni Network</h5>
                        <p>Join the Impact Digital Academy alumni group for networking opportunities and continued learning resources.</p>
                    </div>
                </div>
            </div>

            <!-- Additional Resources -->
            <div class="resource-list">
                <h3><i class="fas fa-external-link-alt"></i> Additional Resources</h3>
                
                <div class="resource-item">
                    <a href="https://certiport.pearsonvue.com/Certifications/Microsoft/MOS/Overview" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Certiport MO-100 Exam Page
                    </a>
                    <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">Official exam information, registration, and preparation resources.</p>
                </div>

                <div class="resource-item">
                    <a href="https://docs.microsoft.com/en-us/learn/certifications/mos-word-2019" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Microsoft Office Specialist Certification Overview
                    </a>
                    <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">Official Microsoft certification details and learning paths.</p>
                </div>

                <div class="resource-item">
                    <a href="https://support.microsoft.com/en-us/office/word-2019-quick-start-guide-0a7c0e6e-9d67-44b5-9053-5f80de09b8f5" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Word 2019 Quick Reference Guide
                    </a>
                    <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">Quick reference for last-minute review of key features and shortcuts.</p>
                </div>

                <div class="resource-item">
                    <a href="https://support.microsoft.com/en-us/training" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Microsoft Office Training Center
                    </a>
                    <p style="margin-top: 5px; font-size: 0.9rem; color: #666;">Free training courses and tutorials for continued learning.</p>
                </div>
            </div>

            <!-- Help Section -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-question-circle"></i> Need Help?
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 10px;">
                    <div>
                        <strong>Instructor:</strong><br>
                        <?php echo htmlspecialchars($instructor_name); ?>
                    </div>
                    <div>
                        <strong>Email:</strong><br>
                        <a href="mailto:<?php echo htmlspecialchars($instructor_email); ?>">
                            <?php echo htmlspecialchars($instructor_email); ?>
                        </a>
                    </div>
                    <div>
                        <strong>Class Portal:</strong><br>
                        <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a>
                    </div>
                    <div>
                        <strong>Support Forum:</strong><br>
                        <a href="<?php echo BASE_URL; ?>modules/forum/word-week8.php">Week 8 Discussion</a>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <a href="#" class="download-btn" onclick="printHandout()" style="margin-right: 15px;">
                    <i class="fas fa-print"></i> Print Handout
                </a>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <a href="https://home.pearsonvue.com/microsoft" target="_blank" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-calendar-alt"></i> Schedule Your Exam
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/word/mock_exam_start.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #ff9800; margin-left: 15px;">
                    <i class="fas fa-play-circle"></i> Start Mock Exam
                </a>
            </div>
        </div>

        <footer>
            <p>MO-100: Microsoft Word Certification Prep Program – Week 8 Handout (Final Session)</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout concludes the Impact Digital Academy MO-100 Preparation Program. Congratulations on reaching this milestone!
            </div>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                <?php if ($user_role === 'student'): ?>
                    <i class="fas fa-user-graduate"></i> Student: <?php echo htmlspecialchars($user_name); ?> • <?php echo htmlspecialchars($user_email); ?>
                <?php else: ?>
                    <i class="fas fa-chalkboard-teacher"></i> Instructor: <?php echo htmlspecialchars($user_name); ?> • <?php echo htmlspecialchars($user_email); ?>
                <?php endif; ?>
            </div>
        </footer>
    </div>

    <script>
        // Add current date to footer
        const currentDate = new Date();
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        document.getElementById('current-date').textContent = `Handout accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Print functionality
        function printHandout() {
            window.print();
        }

        // PDF alert functionality
        function showPdfAlert() {
            const pdfAlert = document.getElementById('pdfAlert');
            const pdfAlertMessage = document.getElementById('pdfAlertMessage');
            
            // Check if mPDF might be available
            pdfAlertMessage.textContent = 'Generating PDF... Please wait.';
            pdfAlert.style.display = 'block';
            
            // Auto-hide after 5 seconds
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Simulate mock exam download
        function downloadMockExam() {
            alert('Mock exam would open in the Course Portal. This is a demo.');
            // In production, this would link to the mock exam page
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/mock_exam.php?class_id=<?php echo $class_id; ?>';
        }

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });
        });

        // Track handout access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('Word Week 8 handout access logged for: <?php echo htmlspecialchars($user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // FAQ expand functionality
        document.addEventListener('DOMContentLoaded', function() {
            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(item => {
                const question = item.querySelector('strong');
                if (question) {
                    question.style.cursor = 'pointer';
                    question.addEventListener('click', function() {
                        const answer = this.nextElementSibling;
                        if (answer.style.display === 'none' || answer.style.display === '') {
                            answer.style.display = 'block';
                            this.innerHTML = this.innerHTML.replace('▶', '▼');
                        } else {
                            answer.style.display = 'none';
                            this.innerHTML = this.innerHTML.replace('▼', '▶');
                        }
                    });
                    
                    // Add arrow indicator
                    question.innerHTML = '▶ ' + question.innerHTML;
                    const answer = question.nextElementSibling;
                    answer.style.display = 'none';
                }
            });

            // Checklist toggle functionality
            const checklistItems = document.querySelectorAll('.skill-category li');
            checklistItems.forEach(item => {
                item.style.cursor = 'pointer';
                item.addEventListener('click', function() {
                    if (this.style.textDecoration === 'line-through') {
                        this.style.textDecoration = 'none';
                        this.style.color = '';
                        this.style.opacity = '1';
                    } else {
                        this.style.textDecoration = 'line-through';
                        this.style.color = '#666';
                        this.style.opacity = '0.7';
                    }
                });
            });
        });

        // Countdown timer for exam
        function startCountdown(minutes) {
            let time = minutes * 60;
            const timerElement = document.createElement('div');
            timerElement.style.cssText = 'position: fixed; top: 10px; right: 10px; background: #0d3d8c; color: white; padding: 10px 15px; border-radius: 5px; z-index: 1000; font-weight: bold;';
            document.body.appendChild(timerElement);

            const interval = setInterval(() => {
                const minutesLeft = Math.floor(time / 60);
                const secondsLeft = time % 60;
                timerElement.textContent = `Time: ${minutesLeft}:${secondsLeft < 10 ? '0' : ''}${secondsLeft}`;
                
                if (time <= 0) {
                    clearInterval(interval);
                    timerElement.textContent = 'Time\'s up!';
                    timerElement.style.background = '#f44336';
                    alert('Mock exam time is up! Please submit your answers.');
                }
                time--;
            }, 1000);
        }

        // Keyboard shortcuts for exam practice
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+M to simulate mock exam start
            if (e.ctrlKey && e.shiftKey && e.key === 'M') {
                e.preventDefault();
                const startExam = confirm('Start 90-minute mock exam timer?');
                if (startExam) {
                    startCountdown(90);
                }
            }
            
            // Ctrl+Shift+A to show answers
            if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                const answers = [
                    "Passing score: 700/1000",
                    "Retake after: 24 hours",
                    "Certification: Lifetime",
                    "ID required: Government-issued photo ID",
                    "Results: Immediate"
                ];
                alert("Quick Answers:\n\n" + answers.join("\n"));
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
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
    
    /**
     * Show access denied page
     */
    private function showAccessDenied(): void
    {
        header("HTTP/1.0 403 Forbidden");
        echo "<h1>Access Denied</h1><p>You do not have permission to access this material.</p>";
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
     * Handle errors
     */
    private function handleError(string $message): void
    {
        die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: $message</div>");
    }
}

// Initialize and display the handout
try {
    $viewer = new WordWeek8HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>