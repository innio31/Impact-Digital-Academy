<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week8_view.php

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
 * PowerPoint Week 8 Mock Exam & Certification Finale Viewer Class with PDF Download
 */
class PowerPointWeek8MockExamViewer
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
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
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
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to PowerPoint courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to PowerPoint courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
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
                'tempDir' => sys_get_temp_dir()
            ];
            
            // Try different mPDF class names
            try {
                $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            } catch (Exception $e) {
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            // Set document information
            $mpdf->SetTitle('Week 8: Mock Exam & Certification Finale');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Final Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Mock Exam, Certification, Final Preparation');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week8_MockExam_Finale_' . date('Y-m-d') . '.pdf';
            
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
                </ol>
                
                <p><strong>Temporary Solution:</strong> Use the print function instead:</p>
                <button onclick="window.print()" style="padding: 10px 20px; background: #d32f2f; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #d32f2f; border-bottom: 2px solid #d32f2f; padding-bottom: 10px; font-size: 18pt;">
                Week 8: Mock Exam & Certification Finale
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 8 - The Final Challenge!</h2>
                <p style="margin-bottom: 15px;">
                    This week is the culmination of your journey through the MO-300 preparation program. You will put your comprehensive PowerPoint skills to the ultimate test in a simulated exam environment. This session is designed to build your mental stamina, reveal final knowledge gaps, and solidify your test-taking strategy. Following the mock exam, we'll conduct a targeted review to transform mistakes into learning opportunities. By the end of this week, you'll walk away with not just readiness, but the confidence to pass the official MO-300 certification exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Successfully navigate the timed pressure and format of a full-length MO-300 style exam</li>
                    <li>Apply strategic time management and problem-solving approaches to performance-based tasks</li>
                    <li>Identify and remediate persistent weak areas in the PowerPoint skillset through guided review</li>
                    <li>Complete the final steps for exam registration and understand post-exam procedures</li>
                    <li>Articulate the value of the MOS certification for professional advancement</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. The Mock Exam Experience</h3>
                <p><strong>Simulated Environment:</strong></p>
                <ul>
                    <li><strong>Duration:</strong> 50-minute timed session (matching typical exam length)</li>
                    <li><strong>Format:</strong> Performance-based tasks only—no multiple choice</li>
                    <li><strong>Interface Simulation:</strong> Tasks presented in a separate pane, requiring interaction with a live PowerPoint file</li>
                    <li><strong>Tools Disabled:</strong> No access to external help, notes, or the "Tell me" feature during the exam period</li>
                </ul>
                
                <p><strong>Content Coverage:</strong></p>
                <ul>
                    <li>Comprehensive coverage across all five MO-300 objective domains:
                        <ul>
                            <li>Manage Presentations (Create & Manage; Slides; Print & Export)</li>
                            <li>Insert and Format Text, Shapes, and Images</li>
                            <li>Insert Tables, Charts, SmartArt, 3D Models, and Media</li>
                            <li>Apply Transitions and Animations</li>
                            <li>Manage Multiple Presentations</li>
                        </ul>
                    </li>
                    <li>Emphasis on higher-difficulty tasks: Morph transitions, complex Animation Pane sequencing, Slide Master edits, protecting presentations, and inspecting documents</li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Post-Exam Analysis & Strategy Clinic</h3>
                <p><strong>Question-by-Question Review:</strong></p>
                <ul>
                    <li>Walkthrough of the most challenging mock exam tasks</li>
                    <li>Clarification on exact steps, Ribbon navigation paths, and alternative methods</li>
                    <li>Discussion of common pitfalls and misinterpreting task instructions</li>
                </ul>
                
                <p><strong>Time Management Debrief:</strong></p>
                <ul>
                    <li>Analysis of where time was well-spent vs. wasted</li>
                    <li>Strategy for "flagging and moving on" from difficult tasks</li>
                </ul>
                
                <p><strong>Final Gap Identification:</strong></p>
                <ul>
                    <li>Personalized review of which objective domains require last-minute study</li>
                    <li>Creation of a final 48-hour study plan based on mock exam performance</li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Exam Day Logistics & Beyond</h3>
                <p><strong>Final Registration Steps:</strong></p>
                <ul>
                    <li>How to schedule your exam with Pearson VUE (in-person or online)</li>
                    <li>What to expect with online proctoring: ID requirements, environment check, and rules</li>
                    <li>Using your exam voucher or discount code</li>
                </ul>
                
                <p><strong>The Day Of:</strong></p>
                <ul>
                    <li>What to bring (physical IDs for test centers)</li>
                    <li>Recommended arrival time and mental preparation</li>
                    <li>Understanding the exam interface tutorial (provided before the exam starts)</li>
                </ul>
                
                <p><strong>Post-Exam:</strong></p>
                <ul>
                    <li>How and when you receive your score report</li>
                    <li>Understanding the scoring methodology (700/1000 to pass)</li>
                    <li>Steps to claim and share your Microsoft Office Specialist: PowerPoint Associate (Microsoft 365 Apps) digital badge via Credly</li>
                </ul>
            </div>
            
            <!-- Step-by-Step Session Activity -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Step-by-Step Session Activity</h3>
                
                <h4 style="color: #1565c0; font-size: 13pt;">Part 1: The Mock Exam (50 Minutes)</h4>
                <ol>
                    <li><strong>Environment Setup:</strong> Close all unnecessary applications. Have only PowerPoint and the exam interface (simulated via our LMS) open.</li>
                    <li><strong>Timed Execution:</strong> Start the timer. Read each task carefully in the instructions pane. Complete all tasks in the provided MO300_MockExam_Final.pptx file.</li>
                    <li><strong>Self-Monitoring:</strong> If stuck on a task for more than 2 minutes, flag it mentally and move to the next. Aim to attempt every question.</li>
                </ol>
                
                <h4 style="color: #1565c0; font-size: 13pt; margin-top: 15px;">Part 2: Guided Review & Feedback (60 Minutes)</h4>
                <ol>
                    <li><strong>Self-Scoring:</strong> Compare your final presentation against the provided MO300_MockExam_Solution.pptx file. Note specific tasks where your result differed.</li>
                    <li><strong>Instructor-Led Deep Dive:</strong> The instructor will:
                        <ul>
                            <li>Demonstrate the correct steps for the 5-10 most-missed tasks</li>
                            <li>Solicit questions on any unclear task</li>
                            <li>Provide the rationale behind specific task requirements</li>
                        </ul>
                    </li>
                    <li><strong>Personal Action Plan:</strong> On your handout, note your 2-3 weakest objective domains. Commit to reviewing the corresponding Week 1-7 materials before your scheduled exam date.</li>
                </ol>
            </div>
            
            <!-- Exam Day Quick-Reference Sheet -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Exam Day Quick-Reference Sheet</h3>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt; border: 1px solid #ddd;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 10px; text-align: left; width: 33%;">Before the Exam</th>
                            <th style="padding: 10px; text-align: left; width: 33%;">During the Exam</th>
                            <th style="padding: 10px; text-align: left; width: 34%;">After the Exam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; vertical-align: top;">
                                <div style="margin-bottom: 5px;">✅ Schedule with Pearson VUE</div>
                                <div style="margin-bottom: 5px;">✅ Test your system (if online)</div>
                                <div style="margin-bottom: 5px;">✅ Have TWO forms of ID ready</div>
                                <div style="margin-bottom: 5px;">✅ Arrive 30 mins early (test center)</div>
                                <div>✅ Clear your desk/work area</div>
                            </td>
                            <td style="padding: 10px; vertical-align: top;">
                                <div style="margin-bottom: 5px;">✅ Use the tutorial—it doesn't count against your time</div>
                                <div style="margin-bottom: 5px;">✅ Read every word of each task—what tab? what button?</div>
                                <div style="margin-bottom: 5px;">✅ Flag for Review if uncertain</div>
                                <div style="margin-bottom: 5px;">✅ Manage time—keep moving forward</div>
                                <div>✅ Stay calm—you are prepared!</div>
                            </td>
                            <td style="padding: 10px; vertical-align: top;">
                                <div style="margin-bottom: 5px;">✅ You'll get a provisional score immediately</div>
                                <div style="margin-bottom: 5px;">✅ Official score report via email within hours</div>
                                <div style="margin-bottom: 5px;">✅ Claim your digital badge on Credly</div>
                                <div style="margin-bottom: 5px;">✅ Add certification to LinkedIn & resume</div>
                                <div>✅ Consider next cert (Expert level)</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Final Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Final Self-Review Questions</h3>
                <ol>
                    <li>During the exam, you are unsure how to complete a task on manipulating the Slide Master. What is the best strategy to avoid wasting precious time?</li>
                    <li>A task asks you to "Apply a Morph transition between Slide 5 and Slide 6 to animate the movement of the blue rectangle." What is one critical prerequisite for Morph to work correctly that you must check?</li>
                    <li>You finish the exam with 10 minutes remaining. What should you do?</li>
                    <li>Where should you go to schedule your official MO-300 exam?</li>
                    <li>What is the passing score for the MO-300 exam, and how will you receive your results?</li>
                </ol>
            </div>
            
            <!-- Final Tips for Success -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">Final Tips for Success</h3>
                <ul>
                    <li><strong>Trust Your Training:</strong> You have practiced every skill required. The exam tests proficiency, not obscure trivia.</li>
                    <li><strong>The Instructions Are Your Map:</strong> The task description tells you exactly what to do. Look for keywords: "On the Animations tab...", "Use the Format Painter...", "In the Backstage view..."</li>
                    <li><strong>Do Not Leave Tasks Blank:</strong> An attempted task, even if partially correct, is better than a blank. Make your best attempt.</li>
                    <li><strong>Celebrate Your Achievement:</strong> Completing this program is a significant professional investment. Recognize your commitment and walk into the exam center with confidence.</li>
                    <li><strong>You Are Part of a Community:</strong> Share your success! Connect with your cohort and instructor on LinkedIn. The Impact Digital Academy network is a resource for your ongoing career growth.</li>
                </ul>
            </div>
            
            <!-- Course Conclusion -->
            <div style="background: #f3e5f5; padding: 20px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h2 style="color: #7b1fa2; margin-top: 0; font-size: 16pt;">Course Conclusion & Congratulations!</h2>
                <p style="margin-bottom: 15px;">
                    You have reached the finish line of the structured MO-300 Exam Preparation Program. From foundational slides to advanced animation sequences, from collaborative review to secure distribution—you have built a comprehensive, professional-level mastery of Microsoft PowerPoint.
                </p>
                
                <p><strong>Your final steps:</strong></p>
                <ol>
                    <li>Review your mock exam feedback and weak areas.</li>
                    <li>Schedule your official exam within the next 7-14 days while knowledge is peak.</li>
                    <li>Execute with confidence and claim your certification.</li>
                </ol>
                
                <p style="margin-top: 15px; font-style: italic;">
                    We are incredibly proud of the work you've put in and are excited to welcome you to the community of Microsoft Office Specialists. You have the skills. You have the strategy. Now, go and certify!
                </p>
                
                <p style="margin-top: 15px; font-weight: bold;">
                    We wish you the utmost success on your exam and in all your future presentations.
                </p>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #d32f2f; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> [Link to LMS/Website - Includes ALL session recordings, handouts, and practice files]</p>
                <p><strong>Exam Scheduling Portal:</strong> [Link to Pearson VUE]</p>
                <p><strong>Claim Your Badge:</strong> [Link to Credly/Microsoft Learn]</p>
                <p><strong>Next Steps:</strong> [Link to Info on MOS: PowerPoint Expert (MO-301) or other courses]</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
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
        $studentName = $this->first_name . ' ' . $this->last_name;
        
        return '
        <div style="text-align: center; padding: 50px 0;">
            <h1 style="color: #d32f2f; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #b71c1c; font-size: 18pt; margin-bottom: 30px;">
                Microsoft PowerPoint (MO-300) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #d32f2f; 
                border-bottom: 3px solid #d32f2f; padding: 20px 0; margin: 30px 0;">
                Week 8 Handout: Mock Exam & Certification Finale
            </h3>
            <div style="margin: 40px 0;">
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
                <p style="font-size: 14pt; color: #666; margin-top: 20px;">
                    <strong>Final Week of 8-Week Program</strong>
                </p>
            </div>
            <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 10pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 9pt;">
                    This handout is part of the MO-300 PowerPoint Certification Prep Program. Unauthorized distribution is prohibited.
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
            Week 8: Mock Exam & Certification Finale | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-300 Final Preparation | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 8: Mock Exam & Certification Finale - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #7b1fa2 0%, #9c27b0 100%);
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
            background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
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

        .final-badge {
            display: inline-block;
            background: #ffeb3b;
            color: #333;
            padding: 8px 20px;
            border-radius: 20px;
            margin-top: 10px;
            font-weight: 700;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
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
            color: #7b1fa2;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #7b1fa2;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #6a1b9a;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #7b1fa2;
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
            background-color: #7b1fa2;
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
            background-color: #f3e5f5;
        }

        .shortcut-key {
            background: #7b1fa2;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exam-box {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exam-title {
            color: #6a1b9a;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .strategy-box {
            background: #e3f2fd;
            border-left: 5px solid #1976d2;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .strategy-title {
            color: #1976d2;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logistics-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .logistics-title {
            color: #4caf50;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tip-box {
            background: #fff3e0;
            border-left: 5px solid #ff9800;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .tip-title {
            color: #ff9800;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .conclusion {
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            border-left: 5px solid #7b1fa2;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .conclusion-title {
            color: #7b1fa2;
            font-size: 1.4rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .review-questions {
            background: #ffebee;
            border-left: 5px solid #d32f2f;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .review-title {
            color: #d32f2f;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #7b1fa2;
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
            background: #6a1b9a;
        }

        .learning-objectives {
            background: #f3e5f5;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #7b1fa2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quick-reference {
            margin: 25px 0;
        }

        .quick-reference h3 {
            color: #6a1b9a;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .reference-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #ddd;
        }

        .reference-table th {
            background-color: #7b1fa2;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .reference-table td {
            padding: 15px;
            border: 1px solid #ddd;
            vertical-align: top;
        }

        .reference-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .checklist-item {
            margin-bottom: 8px;
            padding-left: 25px;
            position: relative;
        }

        .checklist-item:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #4caf50;
            font-weight: bold;
        }

        .domain-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .domain-card {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            background: #f5f5f5;
            transition: all 0.3s;
            border: 2px solid #ddd;
        }

        .domain-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .domain-card.manage {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .domain-card.insert {
            border-color: #1976d2;
            background: #e3f2fd;
        }

        .domain-card.tables {
            border-color: #388e3c;
            background: #e8f5e9;
        }

        .domain-card.transitions {
            border-color: #f57c00;
            background: #fff3e0;
        }

        .domain-card.multiple {
            border-color: #7b1fa2;
            background: #f3e5f5;
        }

        .domain-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .domain-card.manage .domain-icon {
            color: #d32f2f;
        }

        .domain-card.insert .domain-icon {
            color: #1976d2;
        }

        .domain-card.tables .domain-icon {
            color: #388e3c;
        }

        .domain-card.transitions .domain-icon {
            color: #f57c00;
        }

        .domain-card.multiple .domain-icon {
            color: #7b1fa2;
        }

        .timer-display {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin: 25px 0;
            padding: 20px;
            background: linear-gradient(135deg, #7b1fa2 0%, #9c27b0 100%);
            color: white;
            border-radius: 10px;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .timer-digit {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 2rem;
        }

        .countdown-container {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f3e5f5;
            border-radius: 10px;
        }

        .countdown-title {
            color: #7b1fa2;
            margin-bottom: 15px;
            font-size: 1.4rem;
        }

        .countdown-timer {
            font-size: 2.5rem;
            font-weight: bold;
            color: #d32f2f;
            font-family: monospace;
            margin: 20px 0;
        }

        .exam-interface {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .interface-pane {
            flex: 1;
            min-width: 300px;
            padding: 20px;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .interface-pane.instructions {
            border-color: #1976d2;
            background: #e3f2fd;
        }

        .interface-pane.powerpoint {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .pane-title {
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
        }

        .instructions-content {
            font-family: monospace;
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #ccc;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .instructions-content .task {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #ddd;
        }

        .instructions-content .task:last-child {
            border-bottom: none;
        }

        .task-number {
            color: #1976d2;
            font-weight: bold;
        }

        .task-desc {
            margin-top: 5px;
        }

        .mock-exam-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-btn.start {
            background: #4caf50;
            color: white;
        }

        .action-btn.start:hover {
            background: #388e3c;
        }

        .action-btn.pause {
            background: #ff9800;
            color: white;
        }

        .action-btn.pause:hover {
            background: #f57c00;
        }

        .action-btn.review {
            background: #2196f3;
            color: white;
        }

        .action-btn.review:hover {
            background: #1976d2;
        }

        .action-btn.reset {
            background: #9e9e9e;
            color: white;
        }

        .action-btn.reset:hover {
            background: #757575;
        }

        .certificate-preview {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            border: 2px solid #ffd54f;
            border-radius: 10px;
        }

        .certificate-title {
            color: #ff8f00;
            font-size: 1.8rem;
            margin-bottom: 20px;
        }

        .certificate-border {
            border: 3px solid #ff8f00;
            padding: 30px;
            border-radius: 15px;
            background: white;
            position: relative;
        }

        .certificate-border:before {
            content: '';
            position: absolute;
            top: -5px;
            left: -5px;
            right: -5px;
            bottom: -5px;
            border: 2px solid #ffd54f;
            border-radius: 20px;
            pointer-events: none;
        }

        .certificate-text {
            font-size: 1.2rem;
            margin: 20px 0;
            color: #333;
        }

        .certificate-name {
            font-size: 2rem;
            color: #7b1fa2;
            margin: 30px 0;
            font-weight: bold;
            text-decoration: underline;
        }

        .badge-display {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin: 25px 0;
            flex-wrap: wrap;
        }

        .badge-item {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: #f5f5f5;
            border: 2px solid #ddd;
            transition: all 0.3s;
        }

        .badge-item:hover {
            transform: scale(1.05);
        }

        .badge-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .badge-item.digital .badge-icon {
            color: #2196f3;
        }

        .badge-item.linkedin .badge-icon {
            color: #0a66c2;
        }

        .badge-item.credly .badge-icon {
            color: #ff6b6b;
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

            .reference-table {
                display: block;
                overflow-x: auto;
            }

            .exam-interface {
                flex-direction: column;
            }

            .mock-exam-actions {
                flex-direction: column;
                align-items: center;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .timer-display {
                flex-direction: column;
                gap: 10px;
            }
        }

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

            .mock-exam-actions {
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
                <strong>Access Granted:</strong> PowerPoint Week 8 Final Handout
            </div>
            <div class="access-badge">
                <?php echo ucfirst($this->user_role); ?> Access
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-user-graduate"></i> Final Week Access
                </div>
            <?php else: ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Final Week Access
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
        <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/powerpoint/week7.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Week 7
        </a>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 8 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Mock Exam & Certification Finale</div>
            <div class="week-tag">Week 8 of 8 – FINAL WEEK</div>
            <div class="final-badge">
                <i class="fas fa-trophy"></i> CERTIFICATION READY
            </div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-flag-checkered"></i> Welcome to Week 8 - The Final Challenge!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week is the culmination of your journey through the MO-300 preparation program. You will put your comprehensive PowerPoint skills to the ultimate test in a simulated exam environment. This session is designed to build your mental stamina, reveal final knowledge gaps, and solidify your test-taking strategy. Following the mock exam, we'll conduct a targeted review to transform mistakes into learning opportunities. By the end of this week, you'll walk away with not just readiness, but the confidence to pass the official MO-300 certification exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Certification Success"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+TW9jayBFeGFtICZhbXA7IENlcnRpZmljYXRpb24gRmluYWxlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Final Preparation for MO-300 Certification Success</div>
                </div>
            </div>

            <!-- Countdown Timer -->
            <div class="countdown-container">
                <div class="countdown-title">
                    <i class="fas fa-clock"></i> Countdown to Your Official Exam
                </div>
                <div id="countdownTimer" class="countdown-timer">07:00:00:00</div>
                <p style="color: #666; font-size: 0.9rem;">Recommended: Schedule your exam within 7 days of completing this program</p>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Successfully navigate the timed pressure and format of a full-length MO-300 style exam</li>
                    <li>Apply strategic time management and problem-solving approaches to performance-based tasks</li>
                    <li>Identify and remediate persistent weak areas in the PowerPoint skillset through guided review</li>
                    <li>Complete the final steps for exam registration and understand post-exam procedures</li>
                    <li>Articulate the value of the MOS certification for professional advancement</li>
                </ul>
            </div>

            <!-- Section 1: Mock Exam Experience -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-alt"></i> 1. The Mock Exam Experience
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-laptop-code"></i> Simulated Exam Environment</h3>
                    <div class="exam-interface">
                        <div class="interface-pane instructions">
                            <div class="pane-title">
                                <i class="fas fa-list-ol"></i> Exam Instructions Pane
                            </div>
                            <div class="instructions-content">
                                <div class="task">
                                    <div class="task-number">Task 1 of 15:</div>
                                    <div class="task-desc">Open the Slide Master. On the parent layout, change the title placeholder font to Calibri, size 44, color Dark Blue.</div>
                                </div>
                                <div class="task">
                                    <div class="task-number">Task 2 of 15:</div>
                                    <div class="task-desc">On Slide 5, apply a Morph transition to animate the movement of the blue rectangle to the position on Slide 6.</div>
                                </div>
                                <div class="task">
                                    <div class="task-number">Task 3 of 15:</div>
                                    <div class="task-desc">Protect the presentation with the password "secure123" to require a password to modify.</div>
                                </div>
                                <div class="task">
                                    <div class="task-number">Task 4 of 15:</div>
                                    <div class="task-desc">Create a custom animation sequence where the title fades in, then the bullet points appear one by one with a 1-second delay.</div>
                                </div>
                            </div>
                        </div>
                        <div class="interface-pane powerpoint">
                            <div class="pane-title">
                                <i class="fas fa-file-powerpoint"></i> PowerPoint Workspace
                            </div>
                            <div style="text-align: center; padding: 40px 0; color: #666;">
                                <i class="fas fa-file-powerpoint" style="font-size: 4rem; margin-bottom: 15px; display: block; color: #d32f2f;"></i>
                                <p>MO300_MockExam_Final.pptx</p>
                                <p style="font-size: 0.9rem; margin-top: 10px;">Interactive PowerPoint file for exam tasks</p>
                                <div style="margin-top: 20px; padding: 10px; background: #e0e0e0; border-radius: 5px; font-family: monospace;">
                                    Ribbon tabs disabled during exam
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-book"></i> Content Coverage & Exam Domains</h3>
                    <p style="margin-bottom: 15px;">Comprehensive coverage across all five MO-300 objective domains:</p>
                    
                    <div class="domain-cards">
                        <div class="domain-card manage">
                            <div class="domain-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <h4>Manage Presentations</h4>
                            <p style="font-size: 0.9rem; color: #666;">Create & Manage; Slides; Print & Export</p>
                        </div>
                        <div class="domain-card insert">
                            <div class="domain-icon">
                                <i class="fas fa-font"></i>
                            </div>
                            <h4>Insert & Format</h4>
                            <p style="font-size: 0.9rem; color: #666;">Text, Shapes, and Images</p>
                        </div>
                        <div class="domain-card tables">
                            <div class="domain-icon">
                                <i class="fas fa-table"></i>
                            </div>
                            <h4>Tables & Charts</h4>
                            <p style="font-size: 0.9rem; color: #666;">SmartArt, 3D Models, Media</p>
                        </div>
                        <div class="domain-card transitions">
                            <div class="domain-icon">
                                <i class="fas fa-film"></i>
                            </div>
                            <h4>Transitions & Animations</h4>
                            <p style="font-size: 0.9rem; color: #666;">Morph, Animation Pane</p>
                        </div>
                        <div class="domain-card multiple">
                            <div class="domain-icon">
                                <i class="fas fa-copy"></i>
                            </div>
                            <h4>Multiple Presentations</h4>
                            <p style="font-size: 0.9rem; color: #666;">Compare, Merge, Co-author</p>
                        </div>
                    </div>
                </div>

                <!-- Mock Exam Timer -->
                <div class="timer-display">
                    <div style="text-align: center;">
                        <div style="font-size: 1rem; opacity: 0.9;">Mock Exam Duration</div>
                        <div style="display: flex; align-items: baseline; gap: 5px;">
                            <div class="timer-digit" id="minutes">50</div>
                            <div style="font-size: 1.5rem;">:</div>
                            <div class="timer-digit" id="seconds">00</div>
                        </div>
                        <div style="font-size: 0.9rem; margin-top: 10px;">Minutes : Seconds</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1rem; opacity: 0.9;">Average Time Per Task</div>
                        <div class="timer-digit" style="font-size: 1.8rem;">03:20</div>
                        <div style="font-size: 0.9rem; margin-top: 10px;">15 tasks total</div>
                    </div>
                </div>

                <!-- Mock Exam Actions -->
                <div class="mock-exam-actions">
                    <button class="action-btn start" onclick="startMockExam()">
                        <i class="fas fa-play-circle"></i> Start Mock Exam (50 min)
                    </button>
                    <button class="action-btn pause" onclick="pauseMockExam()">
                        <i class="fas fa-pause-circle"></i> Pause Exam
                    </button>
                    <button class="action-btn review" onclick="reviewExam()">
                        <i class="fas fa-search"></i> Review Answers
                    </button>
                    <button class="action-btn reset" onclick="resetMockExam()">
                        <i class="fas fa-redo"></i> Reset Exam
                    </button>
                </div>
            </div>

            <!-- Section 2: Post-Exam Analysis -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> 2. Post-Exam Analysis & Strategy Clinic
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-search"></i> Question-by-Question Review
                    </div>
                    <ul>
                        <li><strong>Walkthrough of challenging tasks:</strong> Instructor demonstrates correct steps for the 5-10 most-missed tasks</li>
                        <li><strong>Navigation clarification:</strong> Exact Ribbon paths, keyboard shortcuts, and alternative methods</li>
                        <li><strong>Common pitfalls:</strong> Discussion of typical mistakes and misinterpretations of task instructions</li>
                        <li><strong>Rationale explanation:</strong> Understanding why specific approaches are required for exam success</li>
                    </ul>
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-clock"></i> Time Management Debrief
                    </div>
                    <ul>
                        <li><strong>Time allocation analysis:</strong> Identify where time was well-spent vs. wasted during the mock exam</li>
                        <li><strong>Flagging strategy:</strong> When and how to use "Flag for Review" to avoid getting stuck</li>
                        <li><strong>Pacing techniques:</strong> Maintaining steady progress through all 15 tasks</li>
                        <li><strong>Time checkpoints:</strong> Recommended progress markers at 10, 25, and 40 minutes</li>
                    </ul>
                </div>

                <div class="strategy-box">
                    <div class="strategy-title">
                        <i class="fas fa-exclamation-triangle"></i> Final Gap Identification
                    </div>
                    <div style="margin: 15px 0; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                        <h4 style="color: #7b1fa2; margin-bottom: 10px;">Personalized Weak Area Assessment:</h4>
                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <div style="flex: 1; min-width: 150px;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Domain 1: Manage Presentations</div>
                                <div style="height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                                    <div id="domain1" style="height: 100%; background: #4caf50; width: 85%;"></div>
                                </div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Domain 2: Insert & Format</div>
                                <div style="height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                                    <div id="domain2" style="height: 100%; background: #4caf50; width: 92%;"></div>
                                </div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Domain 3: Tables & Charts</div>
                                <div style="height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                                    <div id="domain3" style="height: 100%; background: #ff9800; width: 70%;"></div>
                                </div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Domain 4: Transitions & Animations</div>
                                <div style="height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                                    <div id="domain4" style="height: 100%; background: #d32f2f; width: 60%;"></div>
                                </div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Domain 5: Multiple Presentations</div>
                                <div style="height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                                    <div id="domain5" style="height: 100%; background: #4caf50; width: 88%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p style="margin-top: 15px;"><strong>Action Plan:</strong> Based on your mock exam performance, focus your final review on:</p>
                    <ol>
                        <li>Transitions & Animations (Domain 4) – Review Week 5 materials</li>
                        <li>Tables & Charts (Domain 3) – Review Week 3 materials</li>
                        <li>Re-watch tutorial videos for challenging topics</li>
                        <li>Complete extra practice exercises in the Course Portal</li>
                    </ol>
                </div>
            </div>

            <!-- Section 3: Exam Day Logistics -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-calendar-check"></i> 3. Exam Day Logistics & Beyond
                </div>

                <div class="quick-reference">
                    <h3><i class="fas fa-clipboard-list"></i> Exam Day Quick-Reference Sheet</h3>
                    
                    <table class="reference-table">
                        <thead>
                            <tr>
                                <th width="33%">Before the Exam</th>
                                <th width="33%">During the Exam</th>
                                <th width="34%">After the Exam</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="checklist-item">Schedule with Pearson VUE</div>
                                    <div class="checklist-item">Test your system (if online)</div>
                                    <div class="checklist-item">Have TWO forms of ID ready</div>
                                    <div class="checklist-item">Arrive 30 mins early (test center)</div>
                                    <div class="checklist-item">Clear your desk/work area</div>
                                </td>
                                <td>
                                    <div class="checklist-item">Use the tutorial—it doesn't count against your time</div>
                                    <div class="checklist-item">Read every word of each task—what tab? what button?</div>
                                    <div class="checklist-item">Flag for Review if uncertain</div>
                                    <div class="checklist-item">Manage time—keep moving forward</div>
                                    <div class="checklist-item">Stay calm—you are prepared!</div>
                                </td>
                                <td>
                                    <div class="checklist-item">You'll get a provisional score immediately</div>
                                    <div class="checklist-item">Official score report via email within hours</div>
                                    <div class="checklist-item">Claim your digital badge on Credly</div>
                                    <div class="checklist-item">Add certification to LinkedIn & resume</div>
                                    <div class="checklist-item">Consider next cert (Expert level)</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-user-check"></i> Registration & Proctoring</h3>
                    <ul>
                        <li><strong>Pearson VUE Scheduling:</strong>
                            <ul>
                                <li>Visit: <a href="https://home.pearsonvue.com/microsoft" target="_blank">Pearson VUE Microsoft Certification</a></li>
                                <li>Select: MO-300: Microsoft PowerPoint (Microsoft 365 Apps)</li>
                                <li>Choose: Test center or Online proctored exam</li>
                                <li>Apply discount code if provided by Impact Digital Academy</li>
                            </ul>
                        </li>
                        <li><strong>Online Proctoring Requirements:</strong>
                            <ul>
                                <li>Quiet, private room with closed door</li>
                                <li>Clear desk area (no papers, books, phones)</li>
                                <li>Webcam and microphone for proctor monitoring</li>
                                <li>Government-issued photo ID (driver's license, passport)</li>
                                <li>System check performed 30 minutes before exam</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-medal"></i> Post-Exam: Results & Badging</h3>
                    
                    <div class="certificate-preview">
                        <div class="certificate-title">
                            <i class="fas fa-award"></i> Certificate Preview
                        </div>
                        <div class="certificate-border">
                            <div style="font-size: 1.5rem; color: #333; margin-bottom: 10px;">Microsoft Office Specialist</div>
                            <div class="certificate-text">Certifies that</div>
                            <div class="certificate-name"><?php echo htmlspecialchars($studentName); ?></div>
                            <div class="certificate-text">has successfully demonstrated proficiency in</div>
                            <div style="font-size: 1.3rem; color: #d32f2f; margin: 20px 0; font-weight: bold;">
                                PowerPoint (Microsoft 365 Apps)
                            </div>
                            <div class="certificate-text">Exam: MO-300 | Score: 850/1000</div>
                            <div style="margin-top: 30px; color: #666; font-size: 0.9rem;">
                                Microsoft Corporation | <?php echo date('F j, Y'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="badge-display">
                        <div class="badge-item digital">
                            <div class="badge-icon">
                                <i class="fas fa-certificate"></i>
                            </div>
                            <h4>Digital Certificate</h4>
                            <p style="font-size: 0.9rem; color: #666;">Downloadable PDF certificate</p>
                        </div>
                        <div class="badge-item linkedin">
                            <div class="badge-icon">
                                <i class="fab fa-linkedin"></i>
                            </div>
                            <h4>LinkedIn Badge</h4>
                            <p style="font-size: 0.9rem; color: #666;">Share on LinkedIn profile</p>
                        </div>
                        <div class="badge-item credly">
                            <div class="badge-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <h4>Credly Digital Badge</h4>
                            <p style="font-size: 0.9rem; color: #666;">Verifiable digital credential</p>
                        </div>
                    </div>

                    <ul style="margin-top: 20px;">
                        <li><strong>Scoring:</strong> 700/1000 points required to pass (70%)</li>
                        <li><strong>Results:</strong> Provisional score immediately, official report within 24 hours</li>
                        <li><strong>Badge Claim:</strong> Visit <a href="https://www.credly.com/org/microsoft" target="_blank">Credly Microsoft page</a> to claim your digital badge</li>
                        <li><strong>Recertification:</strong> MOS certifications do not expire</li>
                        <li><strong>Next Level:</strong> Consider MOS: PowerPoint Expert (MO-301) certification</li>
                    </ul>
                </div>
            </div>

            <!-- Final Self-Review Questions -->
            <div class="review-questions">
                <div class="review-title">
                    <i class="fas fa-question-circle"></i> Final Self-Review Questions
                </div>
                <ol>
                    <li>During the exam, you are unsure how to complete a task on manipulating the Slide Master. What is the best strategy to avoid wasting precious time?</li>
                    <li>A task asks you to "Apply a Morph transition between Slide 5 and Slide 6 to animate the movement of the blue rectangle." What is one critical prerequisite for Morph to work correctly that you must check?</li>
                    <li>You finish the exam with 10 minutes remaining. What should you do?</li>
                    <li>Where should you go to schedule your official MO-300 exam?</li>
                    <li>What is the passing score for the MO-300 exam, and how will you receive your results?</li>
                </ol>
                
                <button onclick="showReviewAnswers()" class="download-btn" style="margin-top: 15px; background: #4caf50;">
                    <i class="fas fa-eye"></i> View Answer Key
                </button>
            </div>

            <!-- Final Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> Final Tips for Success
                </div>
                <ul>
                    <li><strong>Trust Your Training:</strong> You have practiced every skill required. The exam tests proficiency, not obscure trivia.</li>
                    <li><strong>The Instructions Are Your Map:</strong> The task description tells you exactly what to do. Look for keywords: "On the Animations tab...", "Use the Format Painter...", "In the Backstage view..."</li>
                    <li><strong>Do Not Leave Tasks Blank:</strong> An attempted task, even if partially correct, is better than a blank. Make your best attempt.</li>
                    <li><strong>Celebrate Your Achievement:</strong> Completing this program is a significant professional investment. Recognize your commitment and walk into the exam center with confidence.</li>
                    <li><strong>You Are Part of a Community:</strong> Share your success! Connect with your cohort and instructor on LinkedIn. The Impact Digital Academy network is a resource for your ongoing career growth.</li>
                </ul>
            </div>

            <!-- Course Conclusion -->
            <div class="conclusion">
                <div class="conclusion-title">
                    <i class="fas fa-graduation-cap"></i> Course Conclusion & Congratulations!
                </div>
                <p style="margin-bottom: 15px; font-size: 1.1rem;">
                    You have reached the finish line of the structured MO-300 Exam Preparation Program. From foundational slides to advanced animation sequences, from collaborative review to secure distribution—you have built a comprehensive, professional-level mastery of Microsoft PowerPoint.
                </p>
                
                <div style="background: rgba(255, 255, 255, 0.7); padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h4 style="color: #7b1fa2; margin-bottom: 15px;">Your Final Steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Review your mock exam feedback</strong> and weak areas</li>
                        <li><strong>Schedule your official exam</strong> within the next 7-14 days while knowledge is peak</li>
                        <li><strong>Execute with confidence</strong> and claim your certification</li>
                    </ol>
                </div>
                
                <p style="margin-top: 15px; font-style: italic; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <i class="fas fa-quote-left" style="color: #7b1fa2; margin-right: 10px;"></i>
                    We are incredibly proud of the work you've put in and are excited to welcome you to the community of Microsoft Office Specialists. You have the skills. You have the strategy. Now, go and certify!
                    <i class="fas fa-quote-right" style="color: #7b1fa2; margin-left: 10px;"></i>
                </p>
                
                <p style="margin-top: 15px; font-weight: bold; font-size: 1.2rem; text-align: center;">
                    We wish you the utmost success on your exam and in all your future presentations.
                </p>
            </div>

            <!-- Resources & Links -->
            <div class="logistics-box">
                <div class="logistics-title">
                    <i class="fas fa-external-link-alt"></i> Important Resources & Links
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></li>
                    <li><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></li>
                    <li><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal (Includes ALL session recordings, handouts, and practice files)</a></li>
                    <li><strong>Exam Scheduling:</strong> <a href="https://home.pearsonvue.com/microsoft" target="_blank">Pearson VUE Microsoft Certification Portal</a></li>
                    <li><strong>Claim Your Badge:</strong> <a href="https://www.credly.com/org/microsoft" target="_blank">Credly Microsoft Digital Badges</a></li>
                    <li><strong>Next Steps:</strong> <a href="<?php echo BASE_URL; ?>courses/microsoft-powerpoint-expert">MOS: PowerPoint Expert (MO-301) Information</a></li>
                    <li><strong>Microsoft Learn:</strong> <a href="https://docs.microsoft.com/learn/certifications/powerpoint" target="_blank">Official Microsoft PowerPoint Certification Page</a></li>
                    <li><strong>Practice Tests:</strong> <a href="<?php echo BASE_URL; ?>modules/student/practice_tests.php">Additional Practice Exams</a></li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week8.php">Week 8 Discussion & Q&A</a></li>
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
                <a href="https://home.pearsonvue.com/microsoft" target="_blank" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-calendar-alt"></i> Schedule Your Exam
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/powerpoint/mock_exam_start.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #ff9800; margin-left: 15px;">
                    <i class="fas fa-play-circle"></i> Start Mock Exam
                </a>
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 8 Final Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-300 PowerPoint Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                <i class="fas fa-award"></i> Certification Candidate - <?php echo htmlspecialchars($this->user_email); ?>
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

        // Countdown timer for exam scheduling
        function updateCountdown() {
            const now = new Date();
            const examDate = new Date(now);
            examDate.setDate(examDate.getDate() + 7); // 7 days from now
            
            const diff = examDate - now;
            
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            document.getElementById('countdownTimer').textContent = 
                `${days.toString().padStart(2, '0')}:${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Mock exam timer
        let mockExamTime = 50 * 60; // 50 minutes in seconds
        let mockExamRunning = false;
        let mockExamInterval;
        
        function updateMockExamTimer() {
            if (mockExamTime <= 0) {
                clearInterval(mockExamInterval);
                mockExamRunning = false;
                alert("Time's up! The mock exam has ended. Please proceed to review your answers.");
                return;
            }
            
            mockExamTime--;
            const minutes = Math.floor(mockExamTime / 60);
            const seconds = mockExamTime % 60;
            
            document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
            document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
            
            // Change color when time is running low
            if (minutes < 10) {
                document.getElementById('minutes').style.background = '#ff9800';
                document.getElementById('seconds').style.background = '#ff9800';
            }
            if (minutes < 5) {
                document.getElementById('minutes').style.background = '#d32f2f';
                document.getElementById('seconds').style.background = '#d32f2f';
            }
        }
        
        function startMockExam() {
            if (!mockExamRunning) {
                mockExamRunning = true;
                mockExamInterval = setInterval(updateMockExamTimer, 1000);
                alert("Mock exam started! You have 50 minutes to complete 15 tasks. Good luck!");
            }
        }
        
        function pauseMockExam() {
            if (mockExamRunning) {
                clearInterval(mockExamInterval);
                mockExamRunning = false;
                alert("Mock exam paused. Click 'Start Mock Exam' to resume.");
            }
        }
        
        function reviewExam() {
            const answers = [
                "1. Flag the question and move on. Return to it if time remains.",
                "2. The object (blue rectangle) must be present and named identically on both slides.",
                "3. Use the time to review flagged questions, then check all tasks for completion.",
                "4. Pearson VUE website: https://home.pearsonvue.com/microsoft",
                "5. 700/1000 points. Provisional score immediately, official report within 24 hours."
            ];
            alert("Review Answer Key:\n\n" + answers.join("\n\n"));
        }
        
        function resetMockExam() {
            clearInterval(mockExamInterval);
            mockExamRunning = false;
            mockExamTime = 50 * 60;
            document.getElementById('minutes').textContent = '50';
            document.getElementById('seconds').textContent = '00';
            document.getElementById('minutes').style.background = '#7b1fa2';
            document.getElementById('seconds').style.background = '#7b1fa2';
            alert("Mock exam reset. Ready to start fresh!");
        }

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
            
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Show review answers
        function showReviewAnswers() {
            const answers = [
                "Question 1: Flag the question and move on. Return to it if time remains after completing other tasks.",
                "Question 2: The object (blue rectangle) must be present and named identically on both slides for Morph to work.",
                "Question 3: Review flagged questions first, then systematically check each task for completion and accuracy.",
                "Question 4: Schedule at Pearson VUE: https://home.pearsonvue.com/microsoft",
                "Question 5: Passing score is 700/1000. You'll see a provisional score immediately and receive an official report via email within 24 hours."
            ];
            
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
            `;
            
            modal.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 10px; max-width: 600px; max-height: 80vh; overflow-y: auto;">
                    <h3 style="color: #7b1fa2; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-key"></i> Self-Review Answer Key
                    </h3>
                    <div style="margin-bottom: 25px;">
                        ${answers.map((answer, index) => `
                            <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee; ${index === answers.length - 1 ? 'border-bottom: none;' : ''}">
                                ${answer}
                            </div>
                        `).join('')}
                    </div>
                    <div style="text-align: center;">
                        <button onclick="this.parentElement.parentElement.parentElement.remove()" style="padding: 10px 25px; background: #7b1fa2; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600;">
                            Close Answer Key
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        }

        // Domain performance animation
        document.addEventListener('DOMContentLoaded', function() {
            const domains = ['domain1', 'domain2', 'domain3', 'domain4', 'domain5'];
            const targetWidths = [85, 92, 70, 60, 88];
            
            domains.forEach((domainId, index) => {
                const domain = document.getElementById(domainId);
                let currentWidth = 0;
                const interval = setInterval(() => {
                    if (currentWidth >= targetWidths[index]) {
                        clearInterval(interval);
                    } else {
                        currentWidth++;
                        domain.style.width = currentWidth + '%';
                    }
                }, 20);
            });
        });

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });
        });

        // Track final week access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('PowerPoint Week 8 final handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log final week access
            }
        });

        // Certification celebration
        function celebrateCertification() {
            const celebration = document.createElement('div');
            celebration.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(123, 31, 162, 0.95);
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                z-index: 3000;
                color: white;
                text-align: center;
                animation: fadeIn 0.5s;
            `;
            
            celebration.innerHTML = `
                <i class="fas fa-trophy" style="font-size: 5rem; margin-bottom: 20px; animation: bounce 1s infinite;"></i>
                <h2 style="font-size: 3rem; margin-bottom: 20px;">Congratulations!</h2>
                <p style="font-size: 1.5rem; max-width: 600px; margin-bottom: 30px;">
                    You've completed the MO-300 PowerPoint Certification Prep Program!
                </p>
                <p style="font-size: 1.2rem; margin-bottom: 40px; max-width: 500px;">
                    You're now ready to take and pass the official Microsoft certification exam.
                </p>
                <button onclick="this.parentElement.remove()" style="padding: 15px 40px; background: white; color: #7b1fa2; border: none; border-radius: 50px; font-size: 1.2rem; font-weight: bold; cursor: pointer;">
                    Continue to Certification
                </button>
            `;
            
            document.body.appendChild(celebration);
            
            // Add celebration animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes bounce {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(-20px); }
                }
            `;
            document.head.appendChild(style);
        }

        // Trigger celebration after 10 seconds on page
        setTimeout(celebrateCertification, 10000);

        // Keyboard shortcut for quick review
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                reviewExam();
            }
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                startMockExam();
            }
            if (e.key === 'F1') {
                e.preventDefault();
                showReviewAnswers();
            }
        });

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveElements = document.querySelectorAll('a, button, .action-btn, .domain-card');
            interactiveElements.forEach(el => {
                el.setAttribute('tabindex', '0');
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });
        });

        // Exam readiness assessment
        function assessReadiness() {
            const scores = [85, 92, 70, 60, 88];
            const average = scores.reduce((a, b) => a + b) / scores.length;
            
            let message = "";
            if (average >= 85) {
                message = "Excellent! You're ready for the certification exam. Schedule it within the next week.";
            } else if (average >= 70) {
                message = "Good! Focus on your weaker domains and schedule your exam in 1-2 weeks.";
            } else {
                message = "Needs improvement. Spend extra time on practice exercises before scheduling your exam.";
            }
            
            alert(`Exam Readiness Assessment:\n\nAverage Score: ${Math.round(average)}%\n\nRecommendation: ${message}`);
        }

        // Auto-assess readiness when page loads
        window.addEventListener('load', function() {
            setTimeout(assessReadiness, 3000);
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
    $viewer = new PowerPointWeek8MockExamViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>