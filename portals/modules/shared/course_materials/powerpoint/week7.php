<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week7_view.php

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
 * PowerPoint Week 7 Handout Viewer Class with PDF Download
 */
class PowerPointWeek7HandoutViewer
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
            $mpdf->SetTitle('Week 7: Inspection, Protection & Final Review');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Inspection, Protection, Security, PDF Export, Exam Preparation');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week7_Inspection_Protection_Review_' . date('Y-m-d') . '.pdf';
            
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
                Week 7: Inspection, Protection & Final Review
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 7!</h2>
                <p style="margin-bottom: 15px;">
                    This week shifts focus from creation to curation and security. As a PowerPoint professional, your responsibility extends to ensuring the final presentation is clean, secure, and accessible. You will learn to inspect documents for hidden information, protect sensitive content with encryption, and finalize your work for distribution in various formats. We will also solidify your exam readiness by reviewing the MO-300 structure, question types, and strategic test-taking approaches. This week is about dotting the i's, crossing the t's, and building your confidence for exam day.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Use the Inspect Document and Accessibility Checker tools to ensure a presentation is clean and inclusive</li>
                    <li>Protect a presentation with passwords for opening and/or modifying, and restrict editing permissions</li>
                    <li>Export a presentation to a variety of fixed-format file types, including PDF, video, and packaged formats</li>
                    <li>Understand the structure, environment, and common objectives of the MO-300 certification exam</li>
                    <li>Develop a personalized study plan to address weak areas identified in prior weeks</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. Final Inspection & Compliance</h3>
                <ul>
                    <li><strong>Inspect Document (File > Info > Check for Issues):</strong>
                        <ul>
                            <li><strong>Inspect for Hidden Properties and Personal Data:</strong> Remove document properties (author name, company), comments, ink annotations, and presentation notes before sharing</li>
                            <li><strong>Check Accessibility:</strong> Proactively identify issues like missing alt text on images, insufficient color contrast, and illogical reading order for screen readers</li>
                            <li><strong>Check Compatibility:</strong> Ensure features work in older versions of PowerPoint if required</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Securing Your Presentation</h3>
                <ul>
                    <li><strong>Protect Presentation (File > Info > Protect Presentation):</strong>
                        <ul>
                            <li><strong>Encrypt with Password:</strong> Set a password to open the file. This encryption cannot be recovered if lost</li>
                            <li><strong>Restrict Editing:</strong> Apply a password to prevent others from modifying the presentation. Users can view but not edit without the password</li>
                            <li><strong>Mark as Final:</strong> Set the file as read-only to discourage editing (not a security feature, as it can be overridden)</li>
                            <li><strong>Digital Signatures:</strong> Add a visible or invisible signature line to authenticate the origin of the document</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Exporting & Distributing Final Outputs</h3>
                <ul>
                    <li><strong>Save & Export (File > Export):</strong>
                        <ul>
                            <li><strong>Create PDF/XPS Document:</strong> Preserve formatting for universal viewing. Choose options for print quality, include comments, or create tagged PDFs for accessibility</li>
                            <li><strong>Create a Video (.mp4):</strong> Export your presentation as a video, complete with recorded timings, narration, and animations. Set video resolution and slide timing</li>
                            <li><strong>Package Presentation for CD (Package for CD):</strong> Bundle the presentation file with all linked media (video/audio) and a viewer for reliable playback on other computers</li>
                        </ul>
                    </li>
                    <li><strong>Change File Type:</strong>
                        <ul>
                            <li><strong>PowerPoint Show (.ppsx):</strong> File opens directly in Slide Show mode</li>
                            <li><strong>PowerPoint Picture Presentation (.pptx):</strong> Converts all slides to high-resolution images in a new file</li>
                            <li><strong>PowerPoint 97-2003 Format (.ppt):</strong> For backwards compatibility (some features may be lost)</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. MO-300 Exam Preparation & Strategy</h3>
                <ul>
                    <li><strong>Exam Structure & Environment:</strong>
                        <ul>
                            <li><strong>Format:</strong> Performance-based exam (live, in-app tasks). Approximately 35-45 tasks</li>
                            <li><strong>Domains:</strong> Based on the official skills outline:
                                <ul>
                                    <li>Manage Presentations (20-25%)</li>
                                    <li>Insert & Format Text, Shapes, and Images (20-25%)</li>
                                    <li>Insert Tables, Charts, SmartArt, 3D Models, and Media (10-15%)</li>
                                    <li>Apply Transitions and Animations (15-20%)</li>
                                    <li>Manage Multiple Presentations (15-20%)</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li><strong>Test-Day Strategy:</strong>
                        <ul>
                            <li><strong>Read Each Task Carefully:</strong> Identify the exact action, tab, and goal</li>
                            <li><strong>Use Provided Resources:</strong> The exam may provide files or instructions in a pane; read them</li>
                            <li><strong>Flag for Review:</strong> If unsure, flag a task and return to it</li>
                            <li><strong>Manage Time:</strong> Don't spend excessive time on a single task; the exam is about breadth of competency</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise: Secure, Inspect, and Finalize a "Confidential Q4 Report"</h3>
                <p><strong>Activity:</strong> Apply Week 7 skills to a realistic business scenario</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li><strong>Inspect and Clean the Document:</strong>
                        <ul>
                            <li>Open a practice presentation that contains a few comments and presenter notes</li>
                            <li>Go to <strong>File > Info > Check for Issues > Inspect Document</strong></li>
                            <li>Run the inspection, removing all Document Properties and Personal Data, and all comments</li>
                            <li>Run the <strong>Accessibility Checker</strong></li>
                            <li>Add descriptive Alt Text to any images and ensure all slides have unique, logical titles</li>
                        </ul>
                    </li>
                    <li><strong>Apply Protection:</strong>
                        <ul>
                            <li>Go to <strong>File > Info > Protect Presentation</strong></li>
                            <li>Choose <strong>Encrypt with Password</strong>. Use a test password (e.g., IDAMO300)</li>
                            <li>Save and close the file. Re-open it to verify the password is required</li>
                        </ul>
                    </li>
                    <li><strong>Export to Multiple Formats:</strong>
                        <ul>
                            <li>With the file open, go to <strong>File > Export</strong></li>
                            <li><strong>Create a PDF:</strong> Export as a PDF, choosing "Standard" quality and the option "Include non-printing information (Document properties and accessibility tags)"</li>
                            <li><strong>Create a Video:</strong> Export as an MP4 video using the "Use Recorded Timings and Narrations" setting (if none exist, use "Don't Use..." with 5 seconds per slide)</li>
                            <li><strong>Change File Type:</strong> Save a copy as a PowerPoint Show (.ppsx). Close and double-click the new file to confirm it opens in Slide Show mode</li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet (Week 7 Focus)</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F12</td>
                            <td style="padding: 6px 8px;">Open the Save As dialog box</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, E, C</td>
                            <td style="padding: 6px 8px;">Export to Create a PDF/XPS</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, I, P</td>
                            <td style="padding: 6px 8px;">Open the Inspect Document pane</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, I, E</td>
                            <td style="padding: 6px 8px;">Open Encrypt with Password dialog</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, T</td>
                            <td style="padding: 6px 8px;">Open the PowerPoint Options dialog</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + S</td>
                            <td style="padding: 6px 8px;">Save (always important!)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt + F, I, A</td>
                            <td style="padding: 6px 8px;">Check Accessibility</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Encryption:</strong> The process of encoding a file so that it cannot be opened without the correct password.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>PDF (Portable Document Format):</strong> A fixed-layout file format that preserves fonts, images, and layout, independent of software or operating systems.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Accessibility Checker:</strong> A tool that identifies potential difficulties people with disabilities might have when viewing a presentation.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Alt Text (Alternative Text):</strong> A textual description of an image or object, read by screen readers to make content accessible to users with visual impairments.</p>
                </div>
                <div>
                    <p><strong>Performance-Based Testing:</strong> An exam format that requires candidates to perform real-world tasks in the live application, as used in the MO-300.</p>
                </div>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>Before sending a presentation to a client, you need to remove your name from the file properties and delete all draft comments. Which specific feature should you use?</li>
                    <li>You want to send a presentation to a colleague for review but prevent them from making any changes to the design. What two protection methods could you use?</li>
                    <li>What is the key functional difference between saving a file as a .pptx and a .ppsx?</li>
                    <li>You need to ensure your presentation can be reliably viewed by someone who does not have PowerPoint installed. What are two suitable export options?</li>
                    <li>What is the primary tool within PowerPoint to ensure your slides are navigable and understandable for users who rely on assistive technologies?</li>
                </ol>
                <p style="margin-top: 15px;"><strong>Hint:</strong> Answers are covered in this week's materials. Review carefully!</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-300 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Inspect documents for issues</li>
                    <li>Check and improve accessibility</li>
                    <li>Protect presentations with passwords</li>
                    <li>Restrict editing permissions</li>
                    <li>Export presentations as PDF</li>
                    <li>Export presentations as video</li>
                    <li>Save presentations in other formats</li>
                    <li>Mark presentations as final</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Inspect Early, Inspect Often:</strong> Make document inspection part of your final review checklist for every presentation you distribute.</li>
                    <li><strong>Password Management is Critical:</strong> Never lose your encryption password. Microsoft cannot recover it. Consider using a secure password manager.</li>
                    <li><strong>Know Your Export Goals:</strong> Choose the format based on the need: PDF for printing/universal viewing, Video for web/unguided playback, .ppsx for kiosks.</li>
                    <li><strong>Simulate the Exam Environment:</strong> Practice performing tasks without relying on the "hover-over" tooltips for button names. Know the Ribbon tabs and group names.</li>
                    <li><strong>Review the Official Objectives:</strong> Before the exam, download and review the official MO-300 skills outline from Microsoft's website to identify any last-minute gaps.</li>
                    <li><strong>Practice Time Management:</strong> Use a timer when practicing to simulate exam conditions.</li>
                    <li><strong>Focus on Weak Areas:</strong> Use your performance in previous weeks' exercises to identify and strengthen weak areas.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Looking Ahead to Week 8: The Final Sprint</h3>
                <p>Next week is your mock exam and final review session. Come prepared to:</p>
                <ul>
                    <li>Tackle a full-length, timed practice exam that simulates the real testing environment</li>
                    <li>Participate in targeted Q&A to solidify your understanding</li>
                    <li>Review exam strategies and last-minute tips</li>
                    <li>Address any remaining questions or concerns</li>
                    <li>Build confidence for exam day</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Use this week's self-review questions and practice activities to identify your final areas for focused study.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft PowerPoint Inspection and Protection Guide</li>
                    <li>Accessibility Checker Tutorial Videos</li>
                    <li>MO-300 Official Exam Skills Outline</li>
                    <li>Practice exam files and templates available in the Course Portal</li>
                    <li>Microsoft Certification Exam Preparation Guide</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #d32f2f; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> Access through your dashboard</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
                <p><strong>Date Accessed:</strong> <?php echo date('F j, Y'); ?></p>
                <p><strong>MO-300 Exam Resources:</strong> Official Practice Test/Guide available in portal</p>
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
                Week 7 Handout: Inspection, Protection & Final Review
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
                    Exam Date: ' . date('F j, Y', strtotime('+14 days')) . '
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
            Week 7: Inspection, Protection & Final Review | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-300 Exam Prep Week 7 | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 7: Inspection, Protection & Final Review - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #1565c0 0%, #1976d2 100%);
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
            background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);
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
            color: #1565c0;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #1565c0;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #0d47a1;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #1565c0;
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
            background-color: #1565c0;
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
            background-color: #e3f2fd;
        }

        .shortcut-key {
            background: #1565c0;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exercise-box {
            background: #e3f2fd;
            border-left: 5px solid #1565c0;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #0d47a1;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .homework-box {
            background: #fff3e0;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .homework-title {
            color: #e65100;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tip-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .tip-title {
            color: #2e7d32;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .next-week {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .next-week-title {
            color: #7b1fa2;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .help-section {
            background: #fff3e0;
            border-left: 5px solid #ff9800;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .help-title {
            color: #e65100;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #1565c0;
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
            background: #0d47a1;
        }

        .learning-objectives {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #1565c0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .key-terms {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .key-terms h3 {
            color: #555;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .term {
            margin-bottom: 15px;
        }

        .term strong {
            color: #1565c0;
            display: block;
            margin-bottom: 5px;
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

        /* Security Features Demo */
        .security-features {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .security-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .security-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .security-icon {
            font-size: 3rem;
            color: #1565c0;
            margin-bottom: 15px;
        }

        /* Export Format Cards */
        .export-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .export-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .export-card.pdf {
            border-color: #f44336;
            background: #ffebee;
        }

        .export-card.video {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .export-card.show {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .export-card.package {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .export-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .export-card.pdf .export-icon {
            color: #f44336;
        }

        .export-card.video .export-icon {
            color: #2196f3;
        }

        .export-card.show .export-icon {
            color: #4caf50;
        }

        .export-card.package .export-icon {
            color: #9c27b0;
        }

        /* Exam Structure Demo */
        .exam-structure {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .exam-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-align: center;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s;
        }

        .exam-item:hover {
            background: #e3f2fd;
            border-color: #1565c0;
            transform: translateY(-3px);
        }

        .exam-item.active {
            background: #e3f2fd;
            border-color: #1565c0;
            border-width: 2px;
        }

        .exam-icon {
            font-size: 2rem;
            color: #1565c0;
            margin-bottom: 10px;
        }

        /* Accessibility Demo */
        .accessibility-demo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 25px 0;
        }

        .accessibility-level {
            width: 80%;
            padding: 20px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            position: relative;
        }

        .accessibility-level.critical {
            background: #ffebee;
            border-color: #f44336;
        }

        .accessibility-level.warning {
            background: #fff3e0;
            border-color: #ff9800;
        }

        .accessibility-level.suggestion {
            background: #e8f5e9;
            border-color: #4caf50;
        }

        .level-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .level-desc {
            font-size: 0.9rem;
            opacity: 0.9;
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

            .shortcut-table {
                font-size: 0.9rem;
            }

            .shortcut-table th,
            .shortcut-table td {
                padding: 10px;
            }

            .security-features,
            .export-cards,
            .exam-structure {
                flex-direction: column;
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

        /* Countdown Timer */
        .countdown-timer {
            background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
            font-size: 1.2rem;
        }

        .countdown-numbers {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
        }

        .countdown-item {
            text-align: center;
        }

        .countdown-value {
            font-size: 2rem;
            font-weight: bold;
        }

        .countdown-label {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        /* Progress Bar */
        .progress-container {
            margin: 20px 0;
        }

        .progress-bar {
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50, #8bc34a);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 0.9rem;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- PDF Alert -->
    <div id="pdfAlert" class="pdf-alert" style="display: none; position: fixed; top: 20px; right: 20px; background: #ff9800; color: white; padding: 15px; border-radius: 5px; box-shadow: 0 3px 10px rgba(0,0,0,0.2); z-index: 1000;">
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
                <strong>Access Granted:</strong> PowerPoint Week 7 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week6_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 6
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week8_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 8
            </a>
        <?php endif; ?>
    </div>

    <!-- Countdown Timer -->
    <div class="countdown-timer">
        <div><i class="fas fa-clock"></i> MO-300 Exam Countdown</div>
        <div class="countdown-numbers">
            <div class="countdown-item">
                <div class="countdown-value" id="days">14</div>
                <div class="countdown-label">Days</div>
            </div>
            <div class="countdown-item">
                <div class="countdown-value" id="hours">00</div>
                <div class="countdown-label">Hours</div>
            </div>
            <div class="countdown-item">
                <div class="countdown-value" id="minutes">00</div>
                <div class="countdown-label">Minutes</div>
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-container">
        <div class="progress-text">
            <span>Course Progress</span>
            <span>88% Complete</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" style="width: 88%;"></div>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 7 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Inspection, Protection & Final Review</div>
            <div class="week-tag">Week 7 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-shield-alt"></i> Welcome to Week 7!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week shifts focus from creation to curation and security. As a PowerPoint professional, your responsibility extends to ensuring the final presentation is clean, secure, and accessible. You will learn to inspect documents for hidden information, protect sensitive content with encryption, and finalize your work for distribution in various formats. We will also solidify your exam readiness by reviewing the MO-300 structure, question types, and strategic test-taking approaches. This week is about dotting the i's, crossing the t's, and building your confidence for exam day.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Final Review and Security"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+SW5zcGVjdGlvbiwgUHJvdGVjdGlvbiAmIEZpbmFsIFJldmlldzwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Final Review and Security - The Last Step Before Distribution</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Use the Inspect Document and Accessibility Checker tools to ensure a presentation is clean and inclusive</li>
                    <li>Protect a presentation with passwords for opening and/or modifying, and restrict editing permissions</li>
                    <li>Export a presentation to a variety of fixed-format file types, including PDF, video, and packaged formats</li>
                    <li>Understand the structure, environment, and common objectives of the MO-300 certification exam</li>
                    <li>Develop a personalized study plan to address weak areas identified in prior weeks</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-300 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Inspect documents for issues</li>
                        <li>Check and improve accessibility</li>
                        <li>Protect presentations with passwords</li>
                        <li>Restrict editing permissions</li>
                    </ul>
                    <ul>
                        <li>Export presentations as PDF</li>
                        <li>Export presentations as video</li>
                        <li>Save presentations in other formats</li>
                        <li>Mark presentations as final</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Final Inspection & Compliance -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-search"></i> 1. Final Inspection & Compliance
                </div>

                <div class="security-features">
                    <div class="security-item">
                        <div class="security-icon">
                            <i class="fas fa-eye-slash"></i>
                        </div>
                        <h4>Inspect Document</h4>
                        <p>File > Info > Check for Issues > Inspect Document</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Remove hidden properties, personal data, comments, and annotations</p>
                    </div>
                    <div class="security-item">
                        <div class="security-icon">
                            <i class="fas fa-universal-access"></i>
                        </div>
                        <h4>Accessibility Checker</h4>
                        <p>Review > Check Accessibility</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Identify and fix issues for users with disabilities</p>
                    </div>
                    <div class="security-item">
                        <div class="security-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h4>Compatibility Checker</h4>
                        <p>File > Info > Check for Issues</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Ensure features work in older PowerPoint versions</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exclamation-triangle"></i> Accessibility Issues Hierarchy</h3>
                    <div class="accessibility-demo">
                        <div class="accessibility-level critical">
                            <div class="level-label">Errors (Critical)</div>
                            <div class="level-desc">Must fix - prevents understanding (e.g., missing alt text)</div>
                        </div>
                        <div class="accessibility-level warning">
                            <div class="level-label">Warnings</div>
                            <div class="level-desc">Should fix - may cause difficulties (e.g., low contrast)</div>
                        </div>
                        <div class="accessibility-level suggestion">
                            <div class="level-label">Tips</div>
                            <div class="level-desc">Could fix - improves experience (e.g., reading order)</div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-trash-alt"></i> What Gets Removed During Inspection?</h3>
                    <ul>
                        <li><strong>Document Properties:</strong> Author name, company, manager, custom properties</li>
                        <li><strong>Comments and Annotations:</strong> All review comments and ink annotations</li>
                        <li><strong>Presenter Notes:</strong> Speaker notes (optional removal)</li>
                        <li><strong>Invisible Content:</strong> Off-slide content, hidden objects</li>
                        <li><strong>Personal Information:</strong> Email addresses, phone numbers in content</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Securing Your Presentation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-lock"></i> 2. Securing Your Presentation
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-key"></i> Protection Options</h3>
                    <div class="export-cards">
                        <div class="export-card pdf">
                            <div class="export-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <h4>Encrypt with Password</h4>
                            <p>Password to open</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Strong encryption</p>
                        </div>
                        <div class="export-card video">
                            <div class="export-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h4>Restrict Editing</h4>
                            <p>Password to modify</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">View-only access</p>
                        </div>
                        <div class="export-card show">
                            <div class="export-icon">
                                <i class="fas fa-flag"></i>
                            </div>
                            <h4>Mark as Final</h4>
                            <p>Read-only warning</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Discourages editing</p>
                        </div>
                        <div class="export-card package">
                            <div class="export-icon">
                                <i class="fas fa-signature"></i>
                            </div>
                            <h4>Digital Signature</h4>
                            <p>Authenticate origin</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Verify integrity</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exclamation-circle"></i> Important Security Notes</h3>
                    <ul>
                        <li><strong>Encryption Password Recovery:</strong> Microsoft cannot recover lost encryption passwords. Keep backups!</li>
                        <li><strong>Password Strength:</strong> Use strong passwords (12+ characters, mixed case, numbers, symbols)</li>
                        <li><strong>Mark as Final is Not Security:</strong> This can be easily overridden by users</li>
                        <li><strong>Digital Certificates:</strong> Require obtaining a digital ID from a certificate authority</li>
                        <li><strong>Best Practice:</strong> Use both open and modify passwords for maximum security</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Security Tip
                        </div>
                        <p>Always keep a separate, unencrypted backup of critical presentations. Password loss means permanent data loss!</p>
                    </div>
                </div>
            </div>

            <!-- Section 3: Exporting & Distributing -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-export"></i> 3. Exporting & Distributing Final Outputs
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-pdf"></i> Export Formats Overview</h3>
                    <div class="export-cards">
                        <div class="export-card pdf">
                            <div class="export-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <h4>PDF/XPS</h4>
                            <p>Universal viewing</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Preserves formatting</p>
                        </div>
                        <div class="export-card video">
                            <div class="export-icon">
                                <i class="fas fa-video"></i>
                            </div>
                            <h4>MP4 Video</h4>
                            <p>Self-running show</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Includes timings/narration</p>
                        </div>
                        <div class="export-card show">
                            <div class="export-icon">
                                <i class="fas fa-play"></i>
                            </div>
                            <h4>PowerPoint Show</h4>
                            <p>.ppsx format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Opens in slideshow mode</p>
                        </div>
                        <div class="export-card package">
                            <div class="export-icon">
                                <i class="fas fa-compact-disc"></i>
                            </div>
                            <h4>Package for CD</h4>
                            <p>Portable package</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Includes viewer & media</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> PDF Export Options</h3>
                    <ul>
                        <li><strong>Quality Options:</strong>
                            <ul>
                                <li><strong>Standard:</strong> Good for online publishing</li>
                                <li><strong>Minimum Size:</strong> For email/web</li>
                                <li><strong>High Quality:</strong> For professional printing</li>
                            </ul>
                        </li>
                        <li><strong>Include Options:</strong>
                            <ul>
                                <li>Include non-printing information</li>
                                <li>Create tagged PDF (accessibility)</li>
                                <li>PDF/A compliant (archival)</li>
                            </ul>
                        </li>
                        <li><strong>Range Options:</strong>
                            <ul>
                                <li>All slides</li>
                                <li>Current slide</li>
                                <li>Custom range (e.g., 1,3,5-8)</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-film"></i> Video Export Settings</h3>
                    <ul>
                        <li><strong>Resolution Options:</strong>
                            <ul>
                                <li>Ultra HD (4K) - 3840x2160</li>
                                <li>Full HD (1080p) - 1920x1080</li>
                                <li>HD (720p) - 1280x720</li>
                                <li>Standard (480p) - 852x480</li>
                            </ul>
                        </li>
                        <li><strong>Timing Options:</strong>
                            <ul>
                                <li>Use recorded timings and narrations</li>
                                <li>Don't use recorded timings and narrations</li>
                                <li>Seconds spent on each slide (default: 5)</li>
                            </ul>
                        </li>
                        <li><strong>Video Quality:</strong> Adjust for file size vs. quality</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: MO-300 Exam Preparation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-clipboard-check"></i> 4. MO-300 Exam Preparation & Strategy
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-chart-pie"></i> Exam Structure & Domains</h3>
                    <div style="margin: 20px 0; background: #f5f5f5; padding: 20px; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span>Manage Presentations</span>
                            <span style="font-weight: bold;">20-25%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span>Insert & Format Text, Shapes, and Images</span>
                            <span style="font-weight: bold;">20-25%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span>Insert Tables, Charts, SmartArt, 3D Models, and Media</span>
                            <span style="font-weight: bold;">10-15%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span>Apply Transitions and Animations</span>
                            <span style="font-weight: bold;">15-20%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Manage Multiple Presentations</span>
                            <span style="font-weight: bold;">15-20%</span>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-clock"></i> Test-Day Strategy</h3>
                    <div class="exam-structure">
                        <div class="exam-item" onclick="showExamTip('Read each task carefully. Identify the exact action, tab, and goal.')">
                            <div class="exam-icon">
                                <i class="fas fa-book-reader"></i>
                            </div>
                            <h4>Read Carefully</h4>
                            <p style="font-size: 0.9rem; color: #666;">Understand requirements</p>
                        </div>
                        <div class="exam-item" onclick="showExamTip('The exam provides files or instructions in a pane. Read them before starting.')">
                            <div class="exam-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <h4>Use Resources</h4>
                            <p style="font-size: 0.9rem; color: #666;">Follow provided instructions</p>
                        </div>
                        <div class="exam-item" onclick="showExamTip('If unsure, flag a task and return to it later. Don\'t waste time.')">
                            <div class="exam-icon">
                                <i class="fas fa-flag"></i>
                            </div>
                            <h4>Flag for Review</h4>
                            <p style="font-size: 0.9rem; color: #666;">Mark difficult questions</p>
                        </div>
                        <div class="exam-item" onclick="showExamTip('Don\'t spend excessive time on a single task. The exam tests breadth of competency.')">
                            <div class="exam-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <h4>Manage Time</h4>
                            <p style="font-size: 0.9rem; color: #666;">Keep moving forward</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-tasks"></i> Performance-Based Testing Format</h3>
                    <ul>
                        <li><strong>Live Application Tasks:</strong> You perform real-world tasks in PowerPoint</li>
                        <li><strong>Approximately 35-45 Tasks:</strong> Varies by exam version</li>
                        <li><strong>Time Limit:</strong> Typically 50 minutes</li>
                        <li><strong>Scoring:</strong> Each task is scored independently</li>
                        <li><strong>Passing Score:</strong> Usually 700 out of 1000</li>
                        <li><strong>Results:</strong> Immediate pass/fail with performance report</li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 5. Keyboard Shortcuts Cheat Sheet (Week 7 Focus)
                </div>

                <table class="shortcut-table">
                    <thead>
                        <tr>
                            <th width="40%">Shortcut</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="shortcut-key">F12</span></td>
                            <td>Open the Save As dialog box</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, E, C</span></td>
                            <td>Export to Create a PDF/XPS</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, I, P</span></td>
                            <td>Open the Inspect Document pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, I, E</span></td>
                            <td>Open Encrypt with Password dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, T</span></td>
                            <td>Open the PowerPoint Options dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save (always important!)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, I, A</span></td>
                            <td>Check Accessibility</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, E, V</span></td>
                            <td>Create a Video</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, E, P</span></td>
                            <td>Package Presentation for CD</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F, A</span></td>
                            <td>Save As (opens dialog)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Print (still useful!)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 6. Step-by-Step Practice Exercise: "Confidential Q4 Report"
                </div>
                <p><strong>Objective:</strong> Apply Week 7 skills to secure, inspect, and finalize a business presentation.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d47a1; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Inspect and Clean the Document:</strong>
                            <ul>
                                <li>Open a practice presentation with comments and presenter notes</li>
                                <li>Go to <strong>File > Info > Check for Issues > Inspect Document</strong></li>
                                <li>Run inspection, removing all Document Properties and Personal Data</li>
                                <li>Run the <strong>Accessibility Checker</strong></li>
                                <li>Add descriptive Alt Text to images</li>
                                <li>Ensure all slides have unique, logical titles</li>
                            </ul>
                        </li>
                        <li><strong>Apply Protection:</strong>
                            <ul>
                                <li>Go to <strong>File > Info > Protect Presentation</strong></li>
                                <li>Choose <strong>Encrypt with Password</strong></li>
                                <li>Use test password: <strong>IDAMO300</strong></li>
                                <li>Save and close the file, then re-open to verify password requirement</li>
                            </ul>
                        </li>
                        <li><strong>Export to Multiple Formats:</strong>
                            <ul>
                                <li>Go to <strong>File > Export</strong></li>
                                <li><strong>Create a PDF:</strong> Export with "Standard" quality and "Include non-printing information"</li>
                                <li><strong>Create a Video:</strong> Export as MP4 using "Use Recorded Timings and Narrations" or 5 seconds per slide</li>
                                <li><strong>Change File Type:</strong> Save copy as PowerPoint Show (.ppsx)</li>
                                <li>Test the .ppsx file by double-clicking it</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Security and Final Review Process"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Db25maWRlbnRpYWwgUTQgUmVwb3J0IEV4ZXJjaXNlPC90ZXh0Pjwvc3ZnPg='">
                    <div class="image-caption">Secure Your Confidential Business Presentations</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadPracticeFile()" style="background: #4caf50;">
                    <i class="fas fa-download"></i> Download Practice File
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 7. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Encryption</strong>
                    <p>The process of encoding a file so that it cannot be opened without the correct password. PowerPoint uses strong encryption that cannot be recovered if the password is lost.</p>
                </div>

                <div class="term">
                    <strong>PDF (Portable Document Format)</strong>
                    <p>A fixed-layout file format that preserves fonts, images, and layout, independent of software or operating systems. Ideal for universal sharing and printing.</p>
                </div>

                <div class="term">
                    <strong>Accessibility Checker</strong>
                    <p>A tool that identifies potential difficulties people with disabilities might have when viewing a presentation. It categorizes issues as Errors, Warnings, and Tips.</p>
                </div>

                <div class="term">
                    <strong>Alt Text (Alternative Text)</strong>
                    <p>A textual description of an image or object, read by screen readers to make content accessible to users with visual impairments. Essential for compliance and inclusion.</p>
                </div>

                <div class="term">
                    <strong>Performance-Based Testing</strong>
                    <p>An exam format that requires candidates to perform real-world tasks in the live application, as used in the MO-300 certification exam.</p>
                </div>

                <div class="term">
                    <strong>Digital Signature</strong>
                    <p>An electronic, encrypted stamp of authentication that confirms the origin and integrity of a document. Provides verification that hasn't been altered.</p>
                </div>

                <div class="term">
                    <strong>Package for CD</strong>
                    <p>A feature that bundles the presentation file with all linked media and a PowerPoint Viewer for reliable playback on computers without PowerPoint installed.</p>
                </div>

                <div class="term">
                    <strong>PowerPoint Show (.ppsx)</strong>
                    <p>A file format that opens directly in Slide Show mode when double-clicked, bypassing the editing interface. Useful for kiosks and automated presentations.</p>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 8. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <ol>
                        <li><strong>Before sending a presentation to a client, you need to remove your name from the file properties and delete all draft comments. Which specific feature should you use?</strong></li>
                        <li><strong>You want to send a presentation to a colleague for review but prevent them from making any changes to the design. What two protection methods could you use?</strong></li>
                        <li><strong>What is the key functional difference between saving a file as a .pptx and a .ppsx?</strong></li>
                        <li><strong>You need to ensure your presentation can be reliably viewed by someone who does not have PowerPoint installed. What are two suitable export options?</strong></li>
                        <li><strong>What is the primary tool within PowerPoint to ensure your slides are navigable and understandable for users who rely on assistive technologies?</strong></li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Check Your Answers:</strong> Press <kbd>Ctrl</kbd> + <kbd>Q</kbd> to reveal the answers (simulated for study).
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 9. Tips for Success
                </div>
                <ul>
                    <li><strong>Inspect Early, Inspect Often:</strong> Make document inspection part of your final review checklist for every presentation you distribute.</li>
                    <li><strong>Password Management is Critical:</strong> Never lose your encryption password. Microsoft cannot recover it. Consider using a secure password manager.</li>
                    <li><strong>Know Your Export Goals:</strong> Choose the format based on the need: PDF for printing/universal viewing, Video for web/unguided playback, .ppsx for kiosks.</li>
                    <li><strong>Simulate the Exam Environment:</strong> Practice performing tasks without relying on the "hover-over" tooltips for button names. Know the Ribbon tabs and group names.</li>
                    <li><strong>Review the Official Objectives:</strong> Before the exam, download and review the official MO-300 skills outline from Microsoft's website to identify any last-minute gaps.</li>
                    <li><strong>Practice Time Management:</strong> Use a timer when practicing to simulate exam conditions. The real exam has approximately 50 minutes for 35-45 tasks.</li>
                    <li><strong>Focus on Weak Areas:</strong> Use your performance in previous weeks' exercises to identify and strengthen weak areas before the exam.</li>
                    <li><strong>Create a Study Plan:</strong> Allocate specific time for each exam domain based on the weighting percentages.</li>
                    <li><strong>Test Your Knowledge:</strong> Use the self-review questions to identify areas that need more practice.</li>
                    <li><strong>Stay Calm:</strong> On exam day, take deep breaths and read each question carefully. You've prepared for this!</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://docs.microsoft.com/en-us/powerpoint/" target="_blank">Microsoft PowerPoint Official Documentation</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/power-point/accessibility/make-your-powerpoint-presentations-accessible" target="_blank">PowerPoint Accessibility Guide</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/learn/certifications/exams/ms-300" target="_blank">MO-300 Official Exam Page</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/learn/certifications/resources/mos-study-guides" target="_blank">Microsoft Certification Study Guides</a></li>
                    <li><a href="https://support.microsoft.com/office/protect-a-presentation-keeping-it-safe-and-secure-82f37646-4b30-41c2-a2b1-db9b08fc7c2e" target="_blank">Presentation Protection Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/video-save-as-a-video-file-3d0fc5e4-eee8-4a2b-8454-4b05889c8a7a" target="_blank">Save as Video Tutorial</a></li>
                    <li><strong>Practice Exam Files:</strong> Available in the Course Portal</li>
                    <li><strong>MO-300 Skills Outline:</strong> Download from Microsoft Learn</li>
                    <li><strong>Week 7 Quiz:</strong> Test your knowledge (available in portal)</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 10. Looking Ahead to Week 8: The Final Sprint
                </div>
                <p><strong>Week 8: Mock Exam & Final Review</strong></p>
                <p>Next week is your mock exam and final review session. Come prepared to:</p>
                <ul>
                    <li>Tackle a full-length, timed practice exam that simulates the real testing environment</li>
                    <li>Participate in targeted Q&A to solidify your understanding</li>
                    <li>Review exam strategies and last-minute tips</li>
                    <li>Address any remaining questions or concerns</li>
                    <li>Build confidence for exam day</li>
                    <li>Receive personalized feedback on your mock exam performance</li>
                    <li>Learn test-center procedures and what to expect on exam day</li>
                    <li>Get final study recommendations based on your mock exam results</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Use this week's self-review questions and practice activities to identify your final areas for focused study. Bring any remaining questions to the final session!</p>
            </div>

            <!-- Help Section -->
            <div class="help-section">
                <div class="help-title">
                    <i class="fas fa-question-circle"></i> Need Help?
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></li>
                    <li><strong>Virtual Office Hours:</strong> Link available in Course Portal</li>
                    <li><strong>Class Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a></li>
                    <li><strong>Office Hours:</strong> Mondays & Wednesdays, 3:00 PM - 5:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week7.php">Week 7 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>MO-300 Exam Resources:</strong> <a href="https://docs.microsoft.com/en-us/learn/certifications/exams/ms-300" target="_blank">Official Practice Test/Guide</a></li>
                    <li><strong>Microsoft Certification Support:</strong> <a href="https://aka.ms/CertSupport" target="_blank">Certification Support Portal</a></li>
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
                <!-- <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week7_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 7 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 7 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-300 PowerPoint Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #666;">
                    <i class="fas fa-calendar-check"></i> Recommended Exam Date: <?php echo date('F j, Y', strtotime('+14 days')); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
            <?php endif; ?>
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
        document.getElementById('current-date').textContent = `Handout accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Countdown timer to exam (14 days from now)
        function updateCountdown() {
            const examDate = new Date();
            examDate.setDate(examDate.getDate() + 14);
            
            const now = new Date().getTime();
            const distance = examDate.getTime() - now;
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            
            document.getElementById('days').textContent = days.toString().padStart(2, '0');
            document.getElementById('hours').textContent = hours.toString().padStart(2, '0');
            document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
        }
        
        // Update countdown every minute
        updateCountdown();
        setInterval(updateCountdown, 60000);

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

        // Simulate practice file download
        function downloadPracticeFile() {
            alert('Confidential Q4 Report practice file would download. This is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/practice_files/week7_confidential_report.pptx';
        }

        // Exam tips
        function showExamTip(tip) {
            alert(`Exam Tip:\n\n${tip}`);
        }

        // Accessibility demonstration
        function demonstrateAccessibility() {
            const steps = [
                "Accessibility Checker Walkthrough:",
                "1. Go to Review → Check Accessibility",
                "2. Panel opens showing:",
                "   • Errors: Must fix (e.g., missing alt text)",
                "   • Warnings: Should fix (e.g., low contrast)",
                "   • Tips: Could fix (e.g., reading order)",
                "3. Click an issue to see details and how to fix",
                "4. Fix issues directly from the panel",
                "\nKey Accessibility Requirements:",
                "• All images must have alt text",
                "• All slides must have unique titles",
                "• Sufficient color contrast (4.5:1 minimum)",
                "• Logical reading order for screen readers"
            ];
            alert(steps.join("\n"));
        }

        // Protection demonstration
        function demonstrateProtection() {
            const protection = [
                "Presentation Protection Options:",
                "\n1. Encrypt with Password:",
                "   • File → Info → Protect Presentation → Encrypt with Password",
                "   • Sets password to OPEN the file",
                "   • STRONG encryption - cannot be recovered if lost!",
                "\n2. Restrict Editing:",
                "   • File → Info → Protect Presentation → Restrict Editing",
                "   • Sets password to MODIFY the file",
                "   • Users can view but not edit without password",
                "\n3. Mark as Final:",
                "   • File → Info → Protect Presentation → Mark as Final",
                "   • Makes file read-only as a warning",
                "   • NOT a security feature - can be overridden",
                "\n4. Digital Signature:",
                "   • Requires digital certificate",
                "   • Verifies document authenticity and integrity"
            ];
            alert(protection.join("\n"));
        }

        // Export formats demonstration
        function demonstrateExportFormats() {
            const formats = [
                "Export Format Comparison:",
                "\nPDF/XPS:",
                "• Universal viewing, preserves formatting",
                "• Options: Standard, Minimum size, High quality",
                "• Can include accessibility tags",
                "\nMP4 Video:",
                "• Self-running presentation",
                "• Includes animations, transitions, timings",
                "• Resolutions: 4K, 1080p, 720p, 480p",
                "\nPowerPoint Show (.ppsx):",
                "• Opens directly in Slide Show mode",
                "• Great for kiosks, automated presentations",
                "• User cannot edit without saving as .pptx",
                "\nPackage for CD:",
                "• Includes PowerPoint Viewer",
                "• Bundles all linked media files",
                "• Ensures playback on any computer"
            ];
            alert(formats.join("\n"));
        }

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });

            // Interactive elements
            const securityItems = document.querySelectorAll('.security-item');
            securityItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:first-of-type').textContent;
                    const details = this.querySelector('p:last-of-type').textContent;
                    alert(`${title}\n\n${description}\n\n${details}\n\nTry this feature in PowerPoint!`);
                });
            });

            // Export cards interaction
            const exportCards = document.querySelectorAll('.export-card');
            exportCards.forEach(card => {
                card.addEventListener('click', function() {
                    const format = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:first-of-type').textContent;
                    const details = this.querySelector('p:last-of-type').textContent;
                    alert(`${format} Format\n\n${description}\n\n${details}\n\nAccess via: File → Export`);
                });
            });
        });

        // Track handout access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('PowerPoint Week 7 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Use the 'Inspect Document' feature (File > Info > Check for Issues > Inspect Document).",
                    "2. Use 'Restrict Editing' (password to modify) or 'Mark as Final' (read-only warning).",
                    "3. .pptx opens in editing mode; .ppsx opens directly in Slide Show mode.",
                    "4. Export as PDF or Package Presentation for CD (includes viewer).",
                    "5. The Accessibility Checker (Review > Check Accessibility)."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Exam simulation shortcut
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                alert("Exam Simulation Mode:\n\nYou have 50 minutes to complete 40 tasks.\n\nRead each instruction carefully.\nUse the provided resources.\nFlag difficult questions.\nManage your time effectively!\n\nGood luck!");
            }
        });

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveElements = document.querySelectorAll('a, button, .security-item, .export-card, .exam-item');
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

        // Study plan generator
        function generateStudyPlan() {
            const domains = [
                "Manage Presentations (20-25%) - 2 hours",
                "Insert & Format Text, Shapes, Images (20-25%) - 2 hours",
                "Insert Tables, Charts, SmartArt, Media (10-15%) - 1 hour",
                "Apply Transitions and Animations (15-20%) - 1.5 hours",
                "Manage Multiple Presentations (15-20%) - 1.5 hours"
            ];
            
            const plan = [
                "Personalized Study Plan for Next 7 Days:",
                "\nDay 1-2: Review all Week 1-7 materials",
                "Day 3: Practice exam tasks (4 hours)",
                "Day 4: Focus on weak areas identified",
                "Day 5: Take full mock exam (50 minutes)",
                "Day 6: Review incorrect answers",
                "Day 7: Final review and exam preparation",
                "\nDomain Focus Times:"
            ].concat(domains);
            
            alert(plan.join("\n"));
        }

        // Add CSS for animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                0% { opacity: 1; }
                70% { opacity: 1; }
                100% { opacity: 0; }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .pulse {
                animation: pulse 2s infinite;
            }
        `;
        document.head.appendChild(style);

        // Weekly progress update
        function updateProgress() {
            const progressFill = document.querySelector('.progress-fill');
            const progressText = document.querySelector('.progress-text span:last-child');
            
            // Simulate progress update (in real app, this would come from server)
            const currentProgress = 88;
            const targetProgress = 100;
            
            let progress = currentProgress;
            const interval = setInterval(() => {
                progress += 1;
                progressFill.style.width = `${progress}%`;
                progressText.textContent = `${progress}% Complete`;
                
                if (progress >= targetProgress) {
                    clearInterval(interval);
                    progressText.innerHTML = '<span class="pulse" style="color: #4caf50; font-weight: bold;">100% Complete! Ready for Exam!</span>';
                }
            }, 100);
        }

        // Initialize progress animation on page load
        setTimeout(updateProgress, 2000);

        // Export demonstration
        function demonstrateExport() {
            const exportDemo = [
                "Export Demonstration:",
                "\nTo export as PDF:",
                "1. File → Export → Create PDF/XPS Document",
                "2. Choose location and filename",
                "3. Select quality: Standard, Minimum size, or High quality",
                "4. Options: Include non-printing information, PDF/A compliant",
                "5. Click Publish",
                "\nTo export as Video:",
                "1. File → Export → Create a Video",
                "2. Choose resolution: Ultra HD, Full HD, HD, or Standard",
                "3. Select timing: Use recorded or set seconds per slide",
                "4. Click Create Video",
                "\nTo save as PowerPoint Show:",
                "1. File → Save As",
                "2. Choose location",
                "3. Save as type: PowerPoint Show (*.ppsx)",
                "4. Click Save"
            ];
            alert(exportDemo.join("\n"));
        }

        // Inspection demonstration
        function demonstrateInspection() {
            const inspection = [
                "Document Inspection Process:",
                "\nSteps:",
                "1. File → Info → Check for Issues → Inspect Document",
                "2. Select what to inspect:",
                "   ☑ Document Properties and Personal Data",
                "   ☑ Comments, Revisions, and Versions",
                "   ☑ Ink Annotations",
                "   ☑ Document Server Properties",
                "   ☑ Custom XML Data",
                "   ☑ Headers and Footers",
                "   ☑ Invisible Content",
                "3. Click Inspect",
                "4. Review results and click Remove All for each section",
                "5. Click Close",
                "\nImportant: This cannot be undone! Save a backup first."
            ];
            alert(inspection.join("\n"));
        }
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
    $viewer = new PowerPointWeek7HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>