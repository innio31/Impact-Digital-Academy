<?php
// modules/shared/course_materials/MSExcel/excel_week8_view.php

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
 * Excel Week 8 Handout Viewer Class with PDF Download
 */
class ExcelWeek8HandoutViewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor'];
    
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
     * Load user details from database
     */
    private function loadUserDetails(): void
    {
        $sql = "SELECT email, first_name, last_name FROM users WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            $this->user_email = $_SESSION['user_email'] ?? '';
            $this->first_name = $_SESSION['first_name'] ?? '';
            $this->last_name = $_SESSION['last_name'] ?? '';
            return;
        }
        
        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $this->user_email = $row['email'];
            $this->first_name = $row['first_name'];
            $this->last_name = $row['last_name'];
        } else {
            $this->user_email = $_SESSION['user_email'] ?? '';
            $this->first_name = $_SESSION['first_name'] ?? '';
            $this->last_name = $_SESSION['last_name'] ?? '';
        }
        
        $stmt->close();
    }
    
    /**
     * Load instructor details from database
     */
    private function loadInstructorDetails(): void
    {
        // If we have a class_id, get the specific instructor for this class
        if ($this->class_id !== null) {
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
                    $stmt->close();
                    return;
                }
                $stmt->close();
            }
        }
        
        // Fallback to default instructor or session data
        $this->instructor_name = $_SESSION['instructor_name'] ?? 'Your Instructor';
        $this->instructor_email = $_SESSION['instructor_email'] ?? 'instructor@impactdigitalacademy.com';
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
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
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
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to Excel courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to Excel courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
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
            $mpdf->SetTitle('Week 8: Final Review, Collaboration & Exam Prep');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Final Review, Exam Prep, Collaboration, Mock Exam');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week8_FinalReview_' . date('Y-m-d') . '.pdf';
            
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
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 12pt;">
            <h1 style="color: #107c10; border-bottom: 2px solid #107c10; padding-bottom: 10px; font-size: 18pt;">
                Week 8: Final Review, Collaboration & Exam Prep
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 8 – The Final Sprint!</h2>
                <p style="margin-bottom: 15px;">
                    This week is the capstone of your 8-week journey to Excel mastery. We will consolidate your skills through a comprehensive mock business report, review crucial collaboration and inspection tools, and put your knowledge to the ultimate test with a timed MO-200 practice exam. This session is designed to simulate real-world tasks and exam conditions, ensuring you are confident and fully prepared to earn your Microsoft Excel certification.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Confidently complete a complex workbook from start to finish, utilizing skills across all exam domains</li>
                    <li>Inspect, protect, and share workbooks professionally</li>
                    <li>Navigate the MO-200 exam format with effective time-management and test-taking strategies</li>
                    <li>Identify final knowledge gaps and create a targeted plan for last-minute review</li>
                    <li>Understand the logistics of scheduling and taking the official certification exam</li>
                </ul>
            </div>
            
            <!-- Session Structure -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Session Structure</h2>
                
                <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 20px;">
                    <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Part 1: Capstone Project & Collaboration Review (60 minutes)</h3>
                    <p><strong>Instructor-Led Workshop:</strong> We will build a mock business report/dashboard together, integrating:</p>
                    <ul>
                        <li>Data import, cleaning, and formatting</li>
                        <li>Complex formulas and function nesting (e.g., INDEX-MATCH, SUMIFS)</li>
                        <li>PivotTables and chart creation for analysis</li>
                        <li>Final touches: page setup for printing, adding comments, and inspecting the document</li>
                    </ul>
                    <p><strong>Key Topics:</strong> Inspecting workbooks for issues, saving in alternative formats (PDF, .xlsx vs. .csv), printing settings, and using collaboration tools (Comments, Share, Protect Workbook).</p>
                </div>
                
                <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 20px;">
                    <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Part 2: Mock Exam & Review (90 minutes)</h3>
                    <p><strong>Timed Mock Exam (60 mins):</strong> You will take a full-length practice exam mirroring the real MO-200 in structure, question types (multiple choice, drag-and-drop, performance-based), and difficulty.</p>
                    <p><strong>Instructions:</strong> No external resources. Manage your time strategically.</p>
                    <p><strong>Answer Walkthrough (30 mins):</strong> We will review key questions, demonstrate solutions for performance-based tasks, and discuss common pitfalls and "trick" questions.</p>
                </div>
                
                <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 20px;">
                    <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Part 3: Final Q&A & Exam Logistics (30 minutes)</h3>
                    <p><strong>Open floor for last-minute questions</strong> on any topic—formulas, functions, or exam strategy.</p>
                    <p><strong>Practical guidance on:</strong></p>
                    <ul>
                        <li>Scheduling your exam via Certiport or Pearson VUE</li>
                        <li>What to expect on exam day (ID, environment, breaks)</li>
                        <li>Interpreting your score report and claiming your certification</li>
                    </ul>
                </div>
            </div>
            
            <!-- Mock Exam Overview -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Mock Exam Overview</h2>
                
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 12px; text-align: left; width: 25%;">Section</th>
                            <th style="padding: 12px; text-align: left; width: 30%;">Exam Objective Area</th>
                            <th style="padding: 12px; text-align: center; width: 15%;"># of Questions</th>
                            <th style="padding: 12px; text-align: center; width: 15%;">Approx. Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;">1</td>
                            <td style="padding: 10px;">Manage Worksheets & Workbooks</td>
                            <td style="padding: 10px; text-align: center;">5–7</td>
                            <td style="padding: 10px; text-align: center;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;">2</td>
                            <td style="padding: 10px;">Manage Data Cells & Ranges</td>
                            <td style="padding: 10px; text-align: center;">5–7</td>
                            <td style="padding: 10px; text-align: center;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;">3</td>
                            <td style="padding: 10px;">Manage Tables & Table Data</td>
                            <td style="padding: 10px; text-align: center;">5–7</td>
                            <td style="padding: 10px; text-align: center;">10 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;">4</td>
                            <td style="padding: 10px;">Perform Operations with Formulas & Functions</td>
                            <td style="padding: 10px; text-align: center;">10–12</td>
                            <td style="padding: 10px; text-align: center;">20 mins</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;">5</td>
                            <td style="padding: 10px;">Manage Charts</td>
                            <td style="padding: 10px; text-align: center;">5–7</td>
                            <td style="padding: 10px; text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px;">6</td>
                            <td style="padding: 10px;">Performance-Based Tasks</td>
                            <td style="padding: 10px; text-align: center;">3–5 tasks</td>
                            <td style="padding: 10px; text-align: center;">40 mins</td>
                        </tr>
                    </tbody>
                    <tfoot style="background-color: #f5f5f5; font-weight: bold;">
                        <tr>
                            <td style="padding: 12px;" colspan="2">Total</td>
                            <td style="padding: 12px; text-align: center;">Approx. 45–60 items</td>
                            <td style="padding: 12px; text-align: center;">100 mins</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Key Exam-Day Strategies -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Exam-Day Strategies</h2>
                
                <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 20px;">
                    <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Before the Exam:</h3>
                    <ul>
                        <li><strong>Practice Under Pressure:</strong> Complete at least one full, timed practice exam</li>
                        <li><strong>Rest & Prepare:</strong> Get adequate sleep and have your ID and confirmation details ready</li>
                        <li><strong>Test Your System:</strong> Complete any required pre-exam system checks ahead of time</li>
                    </ul>
                </div>
                
                <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 20px;">
                    <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">During the Exam:</h3>
                    <ul>
                        <li><strong>Skim First:</strong> In the performance section, quickly review all tasks to plan your approach</li>
                        <li><strong>Use Excel as You Know It:</strong> Rely on the ribbon, right-click menus, and keyboard shortcuts to save time</li>
                        <li><strong>Flag & Move On:</strong> Don't get stuck. Mark difficult questions and return if time allows</li>
                        <li><strong>Save Frequently:</strong> In the performance-based environment, your work may not auto-save. Get in the habit of clicking the "Save" button in the simulated interface</li>
                    </ul>
                </div>
                
                <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 20px;">
                    <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">After the Exam:</h3>
                    <ul>
                        <li><strong>Review Your Report:</strong> Your instant score report will highlight strengths and weaknesses. Use this if a retake is necessary</li>
                        <li><strong>Claim Your Credential:</strong> If you pass, download your digital badge and add it to your LinkedIn profile immediately</li>
                    </ul>
                </div>
            </div>
            
            <!-- Final Skills Checklist -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Final Skills Checklist</h2>
                <p style="margin-bottom: 15px;">Before the real exam, ensure you are proficient in:</p>
                
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 12px; text-align: left; width: 30%;">Skill Area</th>
                            <th style="padding: 12px; text-align: left; width: 70%;">Key Tasks to Master</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; background: #f9f9f9;"><strong>Workbook Management</strong></td>
                            <td style="padding: 10px;">Import data, inspect for issues, save as PDF/CSV, modify properties, print areas/settings</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;"><strong>Formulas & Functions</strong></td>
                            <td style="padding: 10px;">Use logical (IF, IFS), lookup (XLOOKUP, INDEX/MATCH), text (TEXTSPLIT, CONCAT), and aggregation (SUMIFS, COUNTIFS) functions. Understand absolute/relative referencing</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; background: #f9f9f9;"><strong>Data Analysis</strong></td>
                            <td style="padding: 10px;">Create and filter tables, build and slicer PivotTables, remove duplicates, validate data</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px;"><strong>Charts & Objects</strong></td>
                            <td style="padding: 10px;">Insert and format charts (Sparklines, Combo Charts), add and modify shapes, images, and SmartArt</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; background: #f9f9f9;"><strong>Collaboration</strong></td>
                            <td style="padding: 10px;">Insert comments, protect worksheets/workbooks, track changes (simulated), share workbooks</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Step-by-Step Activity -->
            <div style="background: #f0f9f0; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                <h2 style="color: #107c10; margin-top: 0; font-size: 16pt;">Step-by-Step: This Week's Activity</h2>
                <p><strong>Activity: Capstone Project & Final Self-Review</strong></p>
                <ol>
                    <li>Complete the <strong>Mock Business Report</strong> (available in the Course Portal) using all skills learned</li>
                    <li>Take the <strong>Timed Mock Exam</strong> under strict, 100-minute conditions. Note any challenging areas</li>
                    <li>Attend the <strong>Live Review Session</strong> to walk through answers and clarify final questions</li>
                    <li>Create a <strong>Personal Study Plan</strong> focusing on your weak areas for the final 48-hour review</li>
                    <li><strong>Schedule Your MO-200 Exam</strong> using the discount code provided in the portal</li>
                </ol>
            </div>
            
            <!-- FAQ -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Frequently Asked Questions (FAQ)</h2>
                
                <div style="background: #fff9e6; padding: 15px; margin-bottom: 15px; border-left: 4px solid #ff9800;">
                    <p><strong>Q: What is the passing score for the MO-200?</strong></p>
                    <p><strong>A:</strong> The passing score is typically 700 out of 1000.</p>
                </div>
                
                <div style="background: #e3f2fd; padding: 15px; margin-bottom: 15px; border-left: 4px solid #1976d2;">
                    <p><strong>Q: Are keyboard shortcuts allowed and recommended?</strong></p>
                    <p><strong>A:</strong> Absolutely. Proficiency with shortcuts (e.g., Ctrl+Shift+Arrow, Alt+=, Ctrl+T) is crucial for speed on the performance-based tasks.</p>
                </div>
                
                <div style="background: #f3e5f5; padding: 15px; margin-bottom: 15px; border-left: 4px solid #7b1fa2;">
                    <p><strong>Q: What if I don't pass on my first attempt?</strong></p>
                    <p><strong>A:</strong> You can retake the exam after 24 hours. Analyze your score report carefully to focus your study before retaking.</p>
                </div>
                
                <div style="background: #e8f4e8; padding: 15px; margin-bottom: 15px; border-left: 4px solid #107c10;">
                    <p><strong>Q: How long is the MOS Excel certification valid?</strong></p>
                    <p><strong>A:</strong> MOS certifications do not expire—they are a lifelong credential of your skills.</p>
                </div>
            </div>
            
            <!-- Final Encouragement -->
            <div style="background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%); color: white; padding: 25px; border-radius: 8px; margin-bottom: 25px;">
                <h2 style="color: white; margin-top: 0; font-size: 18pt;">Final Encouragement</h2>
                <p style="font-size: 14pt; line-height: 1.8;">
                    Over the past eight weeks, you have transformed from an Excel user into a proficient problem-solver. You have tackled complex formulas, mastered data analysis, and built professional reports. Trust in the skills you have built, stay calm and methodical during the exam, and remember: you are ready for this. Go forward with confidence!
                </p>
            </div>
            
            <!-- Post-Course Support -->
            <div style="background: #e8f4e8; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                <h2 style="color: #107c10; margin-top: 0; font-size: 16pt;">Post-Course Support</h2>
                
                <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1; min-width: 200px; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Material Access</h3>
                        <p>All handouts, practice files, and session recordings remain in your portal for 6 months.</p>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Instructor Access</h3>
                        <p>You may email with follow-up questions for up to 2 weeks after course completion.</p>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Professional Network</h3>
                        <p>Add your certification to LinkedIn and join the Impact Digital Academy Alumni Group for networking and advanced learning opportunities.</p>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Career Resources</h3>
                        <p>Check the portal for resume tips and interview talking points related to your new certification.</p>
                    </div>
                </div>
            </div>
            
            <!-- Additional Resources -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li><strong>Certiport MO-200 Exam Page:</strong> [Link to Official Page]</li>
                    <li><strong>Microsoft Excel 2019 Quick Reference Guide:</strong> (Located in Course Portal for last-minute review)</li>
                    <li><strong>Recommended Practice Datasets:</strong> [Link to provided datasets in portal]</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #107c10; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> Access through your dashboard</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
                <p><strong>Date Accessed:</strong> <?php echo date('F j, Y'); ?></p>
                <p style="margin-top: 10px; font-style: italic;">
                    *This handout concludes the Impact Digital Academy MO-200 Preparation Program. Congratulations on your dedication—now go demonstrate your Excel expertise and ace that exam!*
                </p>
                <p style="color: #666; margin-top: 10px; font-size: 10pt;">
                    © <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get PDF cover page
     */
    private function getPDFCoverPage(): string
    {
        $studentName = $this->first_name . ' ' . $this->last_name;
        
        return '
        <div style="text-align: center; padding: 50px 0;">
            <h1 style="color: #107c10; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #0e5c0e; font-size: 18pt; margin-bottom: 30px;">
                Microsoft Excel (MO-200) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #107c10; 
                border-bottom: 3px solid #107c10; padding: 20px 0; margin: 30px 0;">
                Week 8 Handout: Final Review, Collaboration & Exam Prep
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 16pt; color: #666; margin-bottom: 20px;">
                    <strong>THE FINAL SPRINT TO CERTIFICATION</strong>
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Email: ' . htmlspecialchars($this->user_email) . '
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
                    This handout contains final exam preparation materials for the MO-200 Excel Certification Exam. Unauthorized distribution is prohibited.
                </p>
                <p style="color: #888; font-size: 9pt; margin-top: 10px;">
                    This is the final week of the 8-week certification preparation program.
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
            Week 8: Final Review & Exam Prep | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-200 Final Preparation | Student: ' . htmlspecialchars($this->user_email) . '
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
        
        // Output the HTML page
        $this->renderHTMLPage();
    }
    
    /**
     * Render the HTML page
     */
    private function renderHTMLPage(): void
    {
        $studentName = $this->first_name . ' ' . $this->last_name;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 8: Final Review, Collaboration & Exam Prep - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #0e5c0e 0%, #107c10 100%);
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
            background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%);
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
            color: #107c10;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #107c10;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #0e5c0e;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #107c10;
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
            background-color: #107c10;
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
            background-color: #e8f4e8;
        }

        .shortcut-key {
            background: #107c10;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exam-box {
            background: #e8f4e8;
            border-left: 5px solid #107c10;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exam-title {
            color: #0e5c0e;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .strategy-box {
            background: #fff9e6;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .strategy-title {
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

        .faq-section {
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

        .support-section {
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

        .download-btn {
            display: inline-block;
            background: #107c10;
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
            background: #0e5c0e;
        }

        .learning-objectives {
            background: #f0f9f0;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #107c10;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .exam-overview-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #ddd;
        }

        .exam-overview-table th {
            background-color: #107c10;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .exam-overview-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .exam-overview-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .exam-overview-table tr:hover {
            background-color: #e8f4e8;
        }

        .skills-checklist {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .skills-checklist h3 {
            color: #555;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .skills-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .skills-table th {
            background-color: #107c10;
            color: white;
            padding: 12px;
            text-align: left;
        }

        .skills-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        .skills-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* Session Structure Cards */
        .session-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .session-card {
            flex: 1;
            min-width: 250px;
            padding: 25px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .session-card.part1 {
            background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%);
        }

        .session-card.part2 {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
        }

        .session-card.part3 {
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
        }

        .session-card h3 {
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .session-card p {
            margin-bottom: 15px;
            opacity: 0.9;
        }

        /* Support Cards */
        .support-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .support-card {
            flex: 1;
            min-width: 200px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #107c10;
        }

        .support-card h3 {
            color: #107c10;
            margin-bottom: 10px;
            font-size: 1.2rem;
        }

        /* Final Encouragement */
        .final-encouragement {
            background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%);
            color: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            margin: 40px 0;
        }

        .final-encouragement h2 {
            font-size: 2rem;
            margin-bottom: 20px;
            color: white;
        }

        .final-encouragement p {
            font-size: 1.2rem;
            line-height: 1.8;
            max-width: 800px;
            margin: 0 auto 20px;
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

        /* Progress Tracker */
        .progress-tracker {
            background: #e8f4e8;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .progress-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #107c10;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-weight: bold;
        }

        .progress-circle .number {
            font-size: 2rem;
        }

        .progress-circle .label {
            font-size: 0.9rem;
        }

        .progress-text {
            flex: 1;
        }

        .progress-text h3 {
            color: #0e5c0e;
            margin-bottom: 10px;
        }

        /* Mock Exam Timer */
        .mock-exam-timer {
            background: #fff3e0;
            border: 2px solid #ff9800;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 25px 0;
        }

        .timer-display {
            font-size: 3rem;
            font-weight: bold;
            color: #e65100;
            margin: 20px 0;
            font-family: monospace;
        }

        .timer-buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .timer-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .start-btn {
            background: #4caf50;
            color: white;
        }

        .pause-btn {
            background: #ff9800;
            color: white;
        }

        .reset-btn {
            background: #f44336;
            color: white;
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

            .session-cards, .support-cards {
                flex-direction: column;
            }

            .progress-tracker {
                flex-direction: column;
                text-align: center;
            }

            .exam-overview-table,
            .skills-table {
                font-size: 0.9rem;
            }

            .exam-overview-table th,
            .exam-overview-table td,
            .skills-table th,
            .skills-table td {
                padding: 8px;
            }

            .final-encouragement {
                padding: 20px;
            }

            .final-encouragement h2 {
                font-size: 1.6rem;
            }

            .final-encouragement p {
                font-size: 1rem;
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

            .mock-exam-timer {
                display: none;
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
                <strong>Access Granted:</strong> Excel Week 8 Handout
            </div>
            <div class="access-badge">
                <?php echo ucfirst($this->user_role); ?> Access
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-user-graduate"></i> Student View
                </div>
            <?php else: ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor View
                </div>
            <?php endif; ?>
        </div>
        <?php if ($this->class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/class_home.php?id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/dashboard.php" class="back-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        <?php endif; ?>
        <?php if ($this->class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week7_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 7
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 8 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0; font-weight: 600;">Final Review, Collaboration & Exam Prep</div>
            <div class="week-tag">Week 8 of 8 – FINAL WEEK</div>
        </div>

        <div class="content">
            <!-- Progress Tracker -->
            <div class="progress-tracker">
                <div class="progress-circle">
                    <div class="number">8/8</div>
                    <div class="label">WEEKS</div>
                </div>
                <div class="progress-text">
                    <h3>Congratulations! You've reached the final week of your Excel certification journey.</h3>
                    <p>Over the past 7 weeks, you've mastered Excel fundamentals, formulas, data analysis, and advanced features. Now it's time to consolidate your knowledge and prepare for the official MO-200 exam.</p>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-trophy"></i> Welcome to Week 8 – The Final Sprint!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week is the capstone of your 8-week journey to Excel mastery. We will consolidate your skills through a comprehensive mock business report, review crucial collaboration and inspection tools, and put your knowledge to the ultimate test with a timed MO-200 practice exam. This session is designed to simulate real-world tasks and exam conditions, ensuring you are confident and fully prepared to earn your Microsoft Excel certification.
                </p>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Confidently complete a complex workbook from start to finish, utilizing skills across all exam domains</li>
                    <li>Inspect, protect, and share workbooks professionally</li>
                    <li>Navigate the MO-200 exam format with effective time-management and test-taking strategies</li>
                    <li>Identify final knowledge gaps and create a targeted plan for last-minute review</li>
                    <li>Understand the logistics of scheduling and taking the official certification exam</li>
                </ul>
            </div>

            <!-- Session Structure -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i> Session Structure
                </div>

                <div class="session-cards">
                    <div class="session-card part1">
                        <h3><i class="fas fa-project-diagram"></i> Part 1: Capstone Project & Collaboration Review</h3>
                        <p><strong>60 minutes</strong></p>
                        <p><strong>Instructor-Led Workshop:</strong> We will build a mock business report/dashboard together, integrating:</p>
                        <ul style="padding-left: 20px; margin-top: 10px;">
                            <li>Data import, cleaning, and formatting</li>
                            <li>Complex formulas and function nesting</li>
                            <li>PivotTables and chart creation for analysis</li>
                            <li>Final touches for professional presentation</li>
                        </ul>
                        <p style="margin-top: 15px;"><strong>Key Topics:</strong> Inspecting workbooks, saving in alternative formats, printing settings, collaboration tools</p>
                    </div>

                    <div class="session-card part2">
                        <h3><i class="fas fa-clock"></i> Part 2: Mock Exam & Review</h3>
                        <p><strong>90 minutes</strong></p>
                        <p><strong>Timed Mock Exam (60 mins):</strong> Full-length practice exam mirroring the real MO-200 in structure and difficulty.</p>
                        <p><strong>Answer Walkthrough (30 mins):</strong> Review key questions, demonstrate solutions, discuss common pitfalls.</p>
                        <p style="margin-top: 15px;"><strong>Instructions:</strong> No external resources. Manage your time strategically.</p>
                    </div>

                    <div class="session-card part3">
                        <h3><i class="fas fa-question-circle"></i> Part 3: Final Q&A & Exam Logistics</h3>
                        <p><strong>30 minutes</strong></p>
                        <p>Open floor for last-minute questions on any topic—formulas, functions, or exam strategy.</p>
                        <p><strong>Practical guidance on:</strong></p>
                        <ul style="padding-left: 20px; margin-top: 10px;">
                            <li>Scheduling your exam</li>
                            <li>What to expect on exam day</li>
                            <li>Interpreting your score report</li>
                            <li>Claiming your certification</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Mock Exam Timer -->
            <div class="mock-exam-timer">
                <h3><i class="fas fa-stopwatch"></i> Mock Exam Timer Practice</h3>
                <p>Practice timing yourself with this simulated 100-minute exam timer:</p>
                <div class="timer-display" id="timerDisplay">100:00</div>
                <div class="timer-buttons">
                    <button class="timer-btn start-btn" onclick="startTimer()">
                        <i class="fas fa-play"></i> Start Timer
                    </button>
                    <button class="timer-btn pause-btn" onclick="pauseTimer()">
                        <i class="fas fa-pause"></i> Pause
                    </button>
                    <button class="timer-btn reset-btn" onclick="resetTimer()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
                <p style="margin-top: 15px; font-size: 0.9rem; color: #666;">
                    <i class="fas fa-lightbulb"></i> Tip: Practice with the timer to get comfortable with the 100-minute exam duration.
                </p>
            </div>

            <!-- Mock Exam Overview -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-clipboard-list"></i> Mock Exam Overview
                </div>

                <table class="exam-overview-table">
                    <thead>
                        <tr>
                            <th width="10%">Section</th>
                            <th width="35%">Exam Objective Area</th>
                            <th width="20%" style="text-align: center;"># of Questions</th>
                            <th width="20%" style="text-align: center;">Approx. Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>Manage Worksheets & Workbooks</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>Manage Data Cells & Ranges</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>Manage Tables & Table Data</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>Perform Operations with Formulas & Functions</td>
                            <td style="text-align: center;">10–12</td>
                            <td style="text-align: center;">20 mins</td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>Manage Charts</td>
                            <td style="text-align: center;">5–7</td>
                            <td style="text-align: center;">10 mins</td>
                        </tr>
                        <tr style="background-color: #e8f4e8; font-weight: bold;">
                            <td>6</td>
                            <td>Performance-Based Tasks</td>
                            <td style="text-align: center;">3–5 tasks</td>
                            <td style="text-align: center;">40 mins</td>
                        </tr>
                    </tbody>
                    <tfoot style="background-color: #f0f0f0;">
                        <tr>
                            <td colspan="2" style="padding: 12px;"><strong>Total</strong></td>
                            <td style="text-align: center; padding: 12px;"><strong>Approx. 45–60 items</strong></td>
                            <td style="text-align: center; padding: 12px;"><strong>100 mins</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Key Exam-Day Strategies -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chess-knight"></i> Key Exam-Day Strategies
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-clock"></i> Before the Exam:
                    </div>
                    <ul>
                        <li><strong>Practice Under Pressure:</strong> Complete at least one full, timed practice exam</li>
                        <li><strong>Rest & Prepare:</strong> Get adequate sleep and have your ID and confirmation details ready</li>
                        <li><strong>Test Your System:</strong> Complete any required pre-exam system checks ahead of time</li>
                        <li><strong>Review Weak Areas:</strong> Focus on topics where you struggled in practice exams</li>
                    </ul>
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-hourglass-half"></i> During the Exam:
                    </div>
                    <ul>
                        <li><strong>Skim First:</strong> In the performance section, quickly review all tasks to plan your approach</li>
                        <li><strong>Use Excel as You Know It:</strong> Rely on the ribbon, right-click menus, and keyboard shortcuts to save time</li>
                        <li><strong>Flag & Move On:</strong> Don't get stuck. Mark difficult questions and return if time allows</li>
                        <li><strong>Save Frequently:</strong> In the performance-based environment, your work may not auto-save. Get in the habit of clicking the "Save" button in the simulated interface</li>
                        <li><strong>Watch the Clock:</strong> Allocate time based on section weights (40% for performance tasks)</li>
                    </ul>
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-check-circle"></i> After the Exam:
                    </div>
                    <ul>
                        <li><strong>Review Your Report:</strong> Your instant score report will highlight strengths and weaknesses. Use this if a retake is necessary</li>
                        <li><strong>Claim Your Credential:</strong> If you pass, download your digital badge and add it to your LinkedIn profile immediately</li>
                        <li><strong>Update Your Resume:</strong> Add the certification to your resume and online profiles within 24 hours</li>
                        <li><strong>Celebrate Your Achievement:</strong> You've earned a valuable professional credential!</li>
                    </ul>
                </div>
            </div>

            <!-- Final Skills Checklist -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-check-square"></i> Final Skills Checklist
                </div>
                <p style="margin-bottom: 20px;">Before the real exam, ensure you are proficient in:</p>

                <div class="skills-checklist">
                    <table class="skills-table">
                        <thead>
                            <tr>
                                <th width="30%">Skill Area</th>
                                <th width="70%">Key Tasks to Master</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="background: #f9f9f9;"><strong>Workbook Management</strong></td>
                                <td>Import data, inspect for issues, save as PDF/CSV, modify properties, print areas/settings</td>
                            </tr>
                            <tr>
                                <td><strong>Formulas & Functions</strong></td>
                                <td>Use logical (IF, IFS), lookup (XLOOKUP, INDEX/MATCH), text (TEXTSPLIT, CONCAT), and aggregation (SUMIFS, COUNTIFS) functions. Understand absolute/relative referencing</td>
                            </tr>
                            <tr>
                                <td style="background: #f9f9f9;"><strong>Data Analysis</strong></td>
                                <td>Create and filter tables, build and slicer PivotTables, remove duplicates, validate data</td>
                            </tr>
                            <tr>
                                <td><strong>Charts & Objects</strong></td>
                                <td>Insert and format charts (Sparklines, Combo Charts), add and modify shapes, images, and SmartArt</td>
                            </tr>
                            <tr>
                                <td style="background: #f9f9f9;"><strong>Collaboration</strong></td>
                                <td>Insert comments, protect worksheets/workbooks, track changes (simulated), share workbooks</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Step-by-Step Activity -->
            <div class="exam-box">
                <div class="exam-title">
                    <i class="fas fa-list-ol"></i> Step-by-Step: This Week's Activity
                </div>
                <p><strong>Activity: Capstone Project & Final Self-Review</strong></p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Complete these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Complete the Mock Business Report</strong> (available in the Course Portal) using all skills learned</li>
                        <li><strong>Take the Timed Mock Exam</strong> under strict, 100-minute conditions. Note any challenging areas</li>
                        <li><strong>Attend the Live Review Session</strong> to walk through answers and clarify final questions</li>
                        <li><strong>Create a Personal Study Plan</strong> focusing on your weak areas for the final 48-hour review</li>
                        <li><strong>Schedule Your MO-200 Exam</strong> using the discount code provided in the portal</li>
                    </ol>
                </div>

                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i> Pro Tip
                    </div>
                    <p>When scheduling your exam, choose a time when you're typically most alert and focused. Avoid scheduling right after work or during your usual low-energy periods.</p>
                </div>
            </div>

            <!-- FAQ -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-question-circle"></i> Frequently Asked Questions (FAQ)
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-check-circle"></i> Passing Score & Requirements
                    </div>
                    <p><strong>Q: What is the passing score for the MO-200?</strong></p>
                    <p><strong>A:</strong> The passing score is typically 700 out of 1000. This means you need to answer approximately 70% of questions correctly.</p>
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-keyboard"></i> Shortcuts & Tools
                    </div>
                    <p><strong>Q: Are keyboard shortcuts allowed and recommended?</strong></p>
                    <p><strong>A:</strong> Absolutely. Proficiency with shortcuts (e.g., Ctrl+Shift+Arrow, Alt+=, Ctrl+T) is crucial for speed on the performance-based tasks. The exam environment includes the full Excel interface with all standard shortcuts available.</p>
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-redo"></i> Retake Policy
                    </div>
                    <p><strong>Q: What if I don't pass on my first attempt?</strong></p>
                    <p><strong>A:</strong> You can retake the exam after 24 hours. Analyze your score report carefully to focus your study before retaking. Most students who don't pass on the first attempt pass on the second with targeted review.</p>
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-calendar-check"></i> Certification Validity
                    </div>
                    <p><strong>Q: How long is the MOS Excel certification valid?</strong></p>
                    <p><strong>A:</strong> MOS certifications do not expire—they are a lifelong credential of your skills. However, as Excel updates, you may want to take newer versions to demonstrate current proficiency.</p>
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-laptop"></i> Exam Format
                    </div>
                    <p><strong>Q: What types of questions are on the exam?</strong></p>
                    <p><strong>A:</strong> The exam includes multiple choice, drag-and-drop, and performance-based tasks where you work directly in a simulated Excel environment to complete specific tasks.</p>
                </div>

                <div class="faq-section">
                    <div class="faq-title">
                        <i class="fas fa-file-alt"></i> Materials Allowed
                    </div>
                    <p><strong>Q: Can I bring notes or use Excel Help during the exam?</strong></p>
                    <p><strong>A:</strong> No external resources are allowed during the exam. The testing center will provide a basic calculator if needed, but you cannot access Excel Help, notes, or the internet.</p>
                </div>
            </div>

            <!-- Final Encouragement -->
            <div class="final-encouragement">
                <h2>Final Encouragement</h2>
                <p>
                    Over the past eight weeks, you have transformed from an Excel user into a proficient problem-solver. You have tackled complex formulas, mastered data analysis, and built professional reports. Trust in the skills you have built, stay calm and methodical during the exam, and remember: you are ready for this.
                </p>
                <p style="font-style: italic; margin-top: 20px;">
                    "Success is not final, failure is not fatal: it is the courage to continue that counts."
                </p>
                <p style="margin-top: 20px; font-size: 1.4rem; font-weight: bold;">
                    Go forward with confidence!
                </p>
            </div>

            <!-- Post-Course Support -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-hands-helping"></i> Post-Course Support
                </div>

                <div class="support-cards">
                    <div class="support-card">
                        <h3><i class="fas fa-folder-open"></i> Material Access</h3>
                        <p>All handouts, practice files, and session recordings remain in your portal for <strong>6 months</strong> after course completion.</p>
                    </div>
                    
                    <div class="support-card">
                        <h3><i class="fas fa-envelope"></i> Instructor Access</h3>
                        <p>You may email with follow-up questions for up to <strong>2 weeks</strong> after course completion.</p>
                    </div>
                    
                    <div class="support-card">
                        <h3><i class="fas fa-users"></i> Professional Network</h3>
                        <p>Add your certification to LinkedIn and join the <strong>Impact Digital Academy Alumni Group</strong> for networking and advanced learning opportunities.</p>
                    </div>
                    
                    <div class="support-card">
                        <h3><i class="fas fa-briefcase"></i> Career Resources</h3>
                        <p>Check the portal for resume tips and interview talking points related to your new certification.</p>
                    </div>
                </div>
            </div>

            <!-- Additional Resources -->
            <div class="support-section">
                <div class="support-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://certiport.pearsonvue.com/Certifications/Microsoft/MOS/Overview" target="_blank">Certiport MO-200 Official Exam Page</a></li>
                    <li><a href="https://support.microsoft.com/excel" target="_blank">Microsoft Excel Official Support</a></li>
                    <li><strong>Microsoft Excel 2019 Quick Reference Guide:</strong> (Located in Course Portal for last-minute review)</li>
                    <li><strong>Recommended Practice Datasets:</strong> Available in the Course Portal</li>
                    <li><strong>Final Review Flashcards:</strong> Downloadable from the portal</li>
                    <li><strong>Exam Day Checklist:</strong> Printable checklist for exam day preparation</li>
                </ul>
            </div>

            <!-- Help Section -->
            <div class="faq-section">
                <div class="faq-title">
                    <i class="fas fa-life-ring"></i> Need Help?
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></li>
                    <li><strong>Class Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a></li>
                    <li><strong>Final Office Hours:</strong> <?php echo date('l, F j', strtotime('+2 days')); ?>, 2:00 PM - 4:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week8.php">Week 8 Final Questions</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Certification Questions:</strong> <a href="mailto:certification@impactdigitalacademy.com">certification@impactdigitalacademy.com</a></li>
                </ul>
            </div>

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <a href="#" class="download-btn" onclick="printHandout()" style="margin-right: 15px;">
                    <i class="fas fa-print"></i> Print Handout
                </a>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/practice/MO200_Mock_Exam.xlsx" class="download-btn" style="background: #ff9800; margin-left: 15px;">
                    <i class="fas fa-download"></i> Download Mock Exam
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/practice/Capstone_Project.xlsx" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-project-diagram"></i> Capstone Project
                </a>
            </div>

            <!-- Final Note -->
            <div style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; margin: 30px 0;">
                <p style="font-style: italic; color: #666;">
                    <i class="fas fa-graduation-cap"></i> 
                    <strong>This handout concludes the Impact Digital Academy MO-200 Preparation Program.</strong><br>
                    Congratulations on your dedication—now go demonstrate your Excel expertise and ace that exam!
                </p>
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 8 Final Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This is the final handout of the 8-week Excel certification program. All materials remain accessible for 6 months.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?> - Program Completion Pending
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Access - <?php echo htmlspecialchars($this->user_email); ?> - Final Week
                </div>
            <?php endif; ?>
            <div style="margin-top: 15px; padding: 10px; background: #e8f4e8; border-radius: 5px;">
                <i class="fas fa-certificate"></i> 
                <strong>Certification Voucher Code:</strong> 
                <span id="voucherCode" style="font-family: monospace; background: white; padding: 2px 10px; border-radius: 3px; margin-left: 10px;">EXCEL200-<?php echo strtoupper(substr(md5($this->user_id . $this->class_id), 0, 8)); ?></span>
                <button onclick="copyVoucherCode()" style="margin-left: 10px; padding: 2px 10px; background: #107c10; color: white; border: none; border-radius: 3px; cursor: pointer;">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
        </footer>
    </div>

    <script>
        // Add current date to footer
        const currentDate = new Date();
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        document.getElementById('current-date').textContent = `Final handout accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Timer functionality
        let timer;
        let timeLeft = 100 * 60; // 100 minutes in seconds
        let isRunning = false;

        function updateTimerDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            document.getElementById('timerDisplay').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        function startTimer() {
            if (!isRunning) {
                isRunning = true;
                timer = setInterval(() => {
                    if (timeLeft > 0) {
                        timeLeft--;
                        updateTimerDisplay();
                    } else {
                        clearInterval(timer);
                        isRunning = false;
                        alert('Time\'s up! This would be the end of your mock exam. How did you do?');
                    }
                }, 1000);
            }
        }

        function pauseTimer() {
            clearInterval(timer);
            isRunning = false;
        }

        function resetTimer() {
            clearInterval(timer);
            isRunning = false;
            timeLeft = 100 * 60;
            updateTimerDisplay();
        }

        // Initialize timer display
        updateTimerDisplay();

        // Print functionality
        function printHandout() {
            window.print();
        }

        // PDF alert functionality
        function showPdfAlert() {
            const pdfAlert = document.getElementById('pdfAlert');
            const pdfAlertMessage = document.getElementById('pdfAlertMessage');
            
            pdfAlertMessage.textContent = 'Generating PDF... Please wait.';
            pdfAlert.style.display = 'block';
            
            // Auto-hide after 5 seconds
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Copy voucher code
        function copyVoucherCode() {
            const voucherCode = document.getElementById('voucherCode').textContent;
            navigator.clipboard.writeText(voucherCode).then(() => {
                alert('Voucher code copied to clipboard: ' + voucherCode);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }

        // Exam strategies popup
        function showExamStrategy(strategyType) {
            const strategies = {
                before: [
                    "Complete one full timed practice exam",
                    "Get 7-8 hours of sleep the night before",
                    "Have your ID and confirmation email ready",
                    "Test your system and internet connection",
                    "Review your weakest topics one last time"
                ],
                during: [
                    "Skim all performance tasks first",
                    "Use keyboard shortcuts to save time",
                    "Flag difficult questions and move on",
                    "Save frequently in the performance tasks",
                    "Watch the clock - allocate 40 minutes for performance tasks"
                ],
                after: [
                    "Review your score report carefully",
                    "Download and share your digital badge",
                    "Update your LinkedIn profile immediately",
                    "Add certification to your resume",
                    "Consider taking advanced Excel courses"
                ]
            };

            const titles = {
                before: "Before the Exam",
                during: "During the Exam",
                after: "After the Exam"
            };

            const strategyList = strategies[strategyType].map(item => `✓ ${item}`).join('\n');
            alert(`${titles[strategyType]} Strategies:\n\n${strategyList}`);
        }

        // Skills checklist interaction
        document.addEventListener('DOMContentLoaded', function() {
            const skillRows = document.querySelectorAll('.skills-table tbody tr');
            skillRows.forEach((row, index) => {
                row.addEventListener('click', function() {
                    const skillArea = this.querySelector('td:first-child').textContent.trim();
                    const keyTasks = this.querySelector('td:last-child').textContent.trim();
                    
                    const reviewQuestions = {
                        'Workbook Management': [
                            "How do you import data from a CSV file?",
                            "What are the steps to inspect a workbook for issues?",
                            "How do you save a workbook as PDF?",
                            "What print settings should you check before printing?"
                        ],
                        'Formulas & Functions': [
                            "When would you use INDEX-MATCH vs VLOOKUP?",
                            "How do nested IF functions work?",
                            "What's the difference between SUMIF and SUMIFS?",
                            "How do absolute references ($A$1) differ from relative references (A1)?"
                        ],
                        'Data Analysis': [
                            "What are the benefits of using Excel Tables?",
                            "How do you add a slicer to a PivotTable?",
                            "What's the process for removing duplicates?",
                            "How do you set up data validation rules?"
                        ],
                        'Charts & Objects': [
                            "When should you use a combo chart?",
                            "How do you add and format Sparklines?",
                            "What's the process for inserting SmartArt?",
                            "How do you align and group objects?"
                        ],
                        'Collaboration': [
                            "How do you protect a worksheet?",
                            "What information appears in document properties?",
                            "How do you add and review comments?",
                            "What are the options for sharing a workbook?"
                        ]
                    };

                    const questions = reviewQuestions[skillArea] || ["Review all tasks listed above"];
                    const questionList = questions.map((q, i) => `${i + 1}. ${q}`).join('\n');
                    
                    alert(`${skillArea} Review:\n\nKey Tasks: ${keyTasks}\n\nSelf-Check Questions:\n${questionList}`);
                });
            });

            // Session cards interaction
            const sessionCards = document.querySelectorAll('.session-card');
            sessionCards.forEach(card => {
                card.addEventListener('click', function() {
                    const title = this.querySelector('h3').textContent;
                    const content = this.innerHTML;
                    
                    // Create a modal view
                    const modal = document.createElement('div');
                    modal.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0,0,0,0.8);
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        z-index: 2000;
                        padding: 20px;
                    `;
                    
                    const modalContent = document.createElement('div');
                    modalContent.style.cssText = `
                        background: white;
                        padding: 30px;
                        border-radius: 10px;
                        max-width: 600px;
                        max-height: 80vh;
                        overflow-y: auto;
                    `;
                    modalContent.innerHTML = content;
                    
                    modal.appendChild(modalContent);
                    document.body.appendChild(modal);
                    
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            document.body.removeChild(modal);
                        }
                    });
                });
            });

            // Support cards interaction
            const supportCards = document.querySelectorAll('.support-card');
            supportCards.forEach(card => {
                card.addEventListener('click', function() {
                    const title = this.querySelector('h3').textContent;
                    const content = this.querySelector('p').textContent;
                    
                    const actionText = {
                        'Material Access': 'Access your materials at: ' + window.location.origin + '/portal',
                        'Instructor Access': 'Email your instructor at: <?php echo htmlspecialchars($this->instructor_email); ?>',
                        'Professional Network': 'Join our LinkedIn group: Impact Digital Academy Alumni',
                        'Career Resources': 'Visit the Career Resources section in your portal'
                    }[title] || '';
                    
                    alert(`${title}\n\n${content}\n\n${actionText}`);
                });
            });
        });

        // Keyboard shortcuts for exam practice
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+E for exam strategies
            if (e.ctrlKey && e.shiftKey && e.key === 'E') {
                e.preventDefault();
                const strategy = prompt('Which exam strategy would you like to review? (before/during/after)', 'during');
                if (strategy && ['before', 'during', 'after'].includes(strategy)) {
                    showExamStrategy(strategy);
                }
            }
            
            // Ctrl+Shift+T for timer control
            if (e.ctrlKey && e.shiftKey && e.key === 'T') {
                e.preventDefault();
                const action = prompt('Timer control: start/pause/reset', 'start');
                if (action === 'start') startTimer();
                else if (action === 'pause') pauseTimer();
                else if (action === 'reset') resetTimer();
            }
            
            // Ctrl+Shift+C for copying voucher
            if (e.ctrlKey && e.shiftKey && e.key === 'C') {
                e.preventDefault();
                copyVoucherCode();
            }
        });

        // Final exam tips popup
        function showFinalTips() {
            const tips = [
                "Arrive at the testing center 30 minutes early",
                "Bring two forms of ID (one with photo)",
                "Read each question carefully before answering",
                "For performance tasks, complete what you know first",
                "Don't second-guess yourself - go with your first instinct",
                "If unsure, eliminate obviously wrong answers first",
                "Use the review feature to check all questions before submitting",
                "Stay calm and breathe - you've prepared for this!"
            ];
            
            const tipList = tips.map((tip, i) => `${i + 1}. ${tip}`).join('\n');
            alert("Final Exam Day Tips:\n\n" + tipList);
        }

        // Add final tips button to FAQ section
        document.addEventListener('DOMContentLoaded', function() {
            const faqSection = document.querySelector('.faq-section:last-child');
            const tipsButton = document.createElement('button');
            tipsButton.innerHTML = '<i class="fas fa-lightbulb"></i> Show Final Exam Tips';
            tipsButton.style.cssText = `
                display: block;
                margin: 15px auto 0;
                padding: 10px 20px;
                background: #ff9800;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: bold;
            `;
            tipsButton.onclick = showFinalTips;
            faqSection.appendChild(tipsButton);
        });

        // Mock exam simulation
        function startMockExamSimulation() {
            if (confirm('Start a 10-question mock exam simulation? This will test random Excel knowledge areas.')) {
                let score = 0;
                const questions = [
                    {
                        q: "Which shortcut selects the entire worksheet?",
                        a: ["Ctrl+A", "Ctrl+Shift+A", "Alt+A", "Shift+A"],
                        correct: 0
                    },
                    {
                        q: "What function would you use to look up a value in a table?",
                        a: ["VLOOKUP", "SUMIF", "COUNT", "IFERROR"],
                        correct: 0
                    },
                    {
                        q: "How do you freeze the top row in Excel?",
                        a: ["View > Freeze Panes > Freeze Top Row", "Home > Freeze Row", "Data > Freeze", "Insert > Freeze"],
                        correct: 0
                    },
                    {
                        q: "Which file format preserves all Excel features?",
                        a: [".xlsx", ".csv", ".txt", ".pdf"],
                        correct: 0
                    },
                    {
                        q: "What does the $ symbol do in a cell reference?",
                        a: ["Makes it absolute", "Converts to currency", "Adds the cells", "Multiplies the cells"],
                        correct: 0
                    }
                ];
                
                for (let i = 0; i < Math.min(5, questions.length); i++) {
                    const q = questions[i];
                    const answer = prompt(`Question ${i + 1}: ${q.q}\n\nOptions:\n${q.a.map((opt, idx) => `${idx + 1}. ${opt}`).join('\n')}\n\nEnter the number of your answer:`);
                    
                    if (parseInt(answer) - 1 === q.correct) {
                        score++;
                        alert('Correct!');
                    } else {
                        alert(`Incorrect. The correct answer is: ${q.a[q.correct]}`);
                    }
                }
                
                alert(`Mock exam simulation complete!\n\nYou scored ${score} out of 5 (${(score/5*100).toFixed(0)}%)\n\nKeep practicing!`);
            }
        }

        // Add mock exam simulation button
        document.addEventListener('DOMContentLoaded', function() {
            const timerSection = document.querySelector('.mock-exam-timer');
            const simButton = document.createElement('button');
            simButton.innerHTML = '<i class="fas fa-play-circle"></i> Quick Practice Quiz';
            simButton.style.cssText = `
                margin-top: 15px;
                padding: 8px 16px;
                background: #9c27b0;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: bold;
            `;
            simButton.onclick = startMockExamSimulation;
            timerSection.appendChild(simButton);
        });

        // Countdown to certification
        function updateCertificationCountdown() {
            const examDate = new Date();
            examDate.setDate(examDate.getDate() + 14); // Assume exam in 2 weeks
            
            const now = new Date();
            const diff = examDate - now;
            
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            
            const countdownEl = document.createElement('div');
            countdownEl.id = 'countdown';
            countdownEl.style.cssText = `
                text-align: center;
                padding: 10px;
                background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%);
                color: white;
                border-radius: 5px;
                margin: 10px 0;
                font-weight: bold;
            `;
            
            if (days > 0) {
                countdownEl.innerHTML = `<i class="fas fa-clock"></i> Recommended exam date: ${examDate.toLocaleDateString()} (${days} days, ${hours} hours from now)`;
            } else {
                countdownEl.innerHTML = `<i class="fas fa-check-circle"></i> You're ready to take your exam! Schedule it today.`;
            }
            
            const header = document.querySelector('.access-header');
            if (header && !document.getElementById('countdown')) {
                header.parentNode.insertBefore(countdownEl, header.nextSibling);
            }
        }

        // Initialize countdown
        updateCertificationCountdown();
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
    $viewer = new ExcelWeek8HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>