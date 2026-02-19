<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week6_view.php

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
 * PowerPoint Week 6 Handout Viewer Class with PDF Download
 */
class PowerPointWeek6HandoutViewer
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
            $mpdf->SetTitle('Week 6: Collaboration, Review & Animation Finale');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Animation, Transitions, Collaboration, Presentation Delivery');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week6_Collaboration_Animation_' . date('Y-m-d') . '.pdf';
            
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
                Week 6: Collaboration, Review & Animation Finale
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 6!</h2>
                <p style="margin-bottom: 15px;">
                    This week marks the crucial transition from content <strong>creation</strong> to presentation <strong>perfection</strong>. You will learn to orchestrate your slides with professional transitions and precise animations that guide your audience's attention. Furthermore, we dive into the essential collaborative tools of PowerPoint, enabling you to refine your work with feedback, manage multiple versions, and prepare flawlessly for delivery. By the end of this week, you will be equipped to polish, secure, and present your work with the confidence of a true PowerPoint professional.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Apply and configure sophisticated slide transitions to control narrative flow</li>
                    <li>Animate text, graphics, and 3D models using entrances, emphases, exits, and motion paths</li>
                    <li>Master the Animation Pane to sequence, trigger, and time complex animations</li>
                    <li>Collaborate effectively using Comments, Notes, and the Compare & Merge tools</li>
                    <li>Prepare for delivery by creating custom shows, using Presenter View, and securing final outputs</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. Professional Slide Transitions</h3>
                <ul>
                    <li><strong>Applying Transitions (Transitions Tab):</strong>
                        <ul>
                            <li><strong>Subtle & Exciting Categories:</strong> Apply effects like Fade, Push, Wipe, or Morph</li>
                            <li><strong>Dynamic Content (Morph Transition):</strong> Seamlessly animate objects, text, and shapes between slides for cinematic effects</li>
                        </ul>
                    </li>
                    <li><strong>Configuring Transition Effects:</strong>
                        <ul>
                            <li><strong>Effect Options:</strong> Customize direction (e.g., From Right, From Top-Left) and behavior</li>
                            <li><strong>Sound & Duration:</strong> Add a sound effect and control the speed of the transition</li>
                            <li><strong>Advancing Slides:</strong> Set to advance <strong>On Mouse Click</strong> or <strong>After</strong> a specific time interval</li>
                            <li><strong>Applying to All:</strong> Use <strong>Apply To All</strong> for a consistent transition scheme</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Mastering Custom Animations</h3>
                <ul>
                    <li><strong>Applying Animations (Animations Tab):</strong>
                        <ul>
                            <li><strong>The Four Types:</strong> <strong>Entrance</strong> (appear), <strong>Emphasis</strong> (draw attention), <strong>Exit</strong> (disappear), and <strong>Motion Paths</strong> (move along a drawn path)</li>
                            <li><strong>Animation Pane (Advanced > Animation Pane):</strong> Your control center for managing all animations on a slide</li>
                        </ul>
                    </li>
                    <li><strong>Configuring Animation Effects:</strong>
                        <ul>
                            <li><strong>Effect Options:</strong> Set direction, size, and smoothness</li>
                            <li><strong>Timing Group:</strong> Set <strong>Start</strong> (On Click, With Previous, After Previous), <strong>Duration</strong> (speed), and <strong>Delay</strong></li>
                            <li><strong>Reordering:</strong> Drag items in the <strong>Animation Pane</strong> or use <strong>Move Earlier/Later</strong> buttons</li>
                            <li><strong>Triggers:</strong> Start an animation by clicking a specific object on the slide</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Collaboration & Review Tools</h3>
                <ul>
                    <li><strong>Comments (Review Tab > New Comment):</strong>
                        <ul>
                            <li>Add threaded comments to specific slide elements for feedback</li>
                            <li>Use <strong>Show Comments</strong> to navigate through feedback and <strong>Resolve</strong> them when addressed</li>
                        </ul>
                    </li>
                    <li><strong>Compare & Merge (Review Tab > Compare):</strong>
                        <ul>
                            <li>Integrate changes from a different version of the presentation</li>
                            <li>Accept or reject individual edits slide-by-slide</li>
                        </ul>
                    </li>
                    <li><strong>Language & Accessibility:</strong>
                        <ul>
                            <li><strong>Spelling & Thesaurus (Review Tab):</strong> Run checks and find synonyms</li>
                            <li><strong>Check Accessibility (Review Tab > Check Accessibility):</strong> Identify and fix issues for viewers with disabilities</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. Final Preparation & Delivery</h3>
                <ul>
                    <li><strong>Custom Slide Shows (Slide Show Tab > Custom Slide Show):</strong>
                        <ul>
                            <li>Create tailored presentations from a subset of slides for different audiences</li>
                        </ul>
                    </li>
                    <li><strong>Rehearsal Tools:</strong>
                        <ul>
                            <li><strong>Rehearse Timings:</strong> Practice your presentation and record time spent on each slide for automatic advancement</li>
                            <li><strong>Presenter View:</strong> Use the speaker's private view with notes, next slides, and a timer during the slide show</li>
                        </ul>
                    </li>
                    <li><strong>Securing & Exporting:</strong>
                        <ul>
                            <li><strong>Inspect Presentation (File > Info > Check for Issues):</strong> Remove hidden metadata or personal info</li>
                            <li><strong>Protect Presentation:</strong> Encrypt with a password or restrict editing permissions</li>
                            <li><strong>Final Outputs:</strong> Use <strong>Save As</strong> to create a PowerPoint Show (<code>.ppsx</code>) or export to PDF/video</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Polish & Present a "Project Launch" Presentation</h3>
                <p><strong>Objective:</strong> Apply professional animations, transitions, and collaboration tools to prepare a presentation for delivery.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Open a presentation with at least 4 slides</li>
                    <li>On a content slide, apply the <strong>Morph</strong> transition</li>
                    <li>Apply a <strong>Fade</strong> transition to all other slides, set to advance automatically after <strong>3 seconds</strong></li>
                    <li>Create a complex animation sequence using the Animation Pane</li>
                    <li>Add a <strong>Comment</strong> to an animated shape with feedback</li>
                    <li>Run <strong>Spelling & Grammar</strong> check</li>
                    <li>Run the <strong>Accessibility Checker</strong> and add Alt Text</li>
                    <li>Create a <strong>Custom Slide Show</strong> that omits appendix slides</li>
                    <li>Practice with <strong>Rehearse Timings</strong></li>
                    <li>Enter <strong>Presenter View</strong> to see your notes</li>
                    <li>Save as a <strong>PowerPoint Show (.ppsx)</strong> file</li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Essential Shortcuts for Week 6</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > S > T</td>
                            <td style="padding: 6px 8px;">Open Transitions Tab</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > A > A</td>
                            <td style="padding: 6px 8px;">Open Animations Tab</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > A > P</td>
                            <td style="padding: 6px 8px;">Open the Animation Pane</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > R > K</td>
                            <td style="padding: 6px 8px;">Start Slide Show from beginning</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > R > B</td>
                            <td style="padding: 6px 8px;">Rehearse Timings</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + W, C</td>
                            <td style="padding: 6px 8px;">Enter Presenter View</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > R > C</td>
                            <td style="padding: 6px 8px;">Insert a New Comment</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + F5</td>
                            <td style="padding: 6px 8px;">Start slideshow from current slide</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + S</td>
                            <td style="padding: 6px 8px;">Save (always!)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Morph Transition:</strong> A sophisticated transition that creates a smooth, animated movement of objects between two slides.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Animation Pane:</strong> A task pane that lists all animations on the current slide in sequence, allowing for detailed timing and effect management.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Trigger:</strong> An object on a slide that starts an animation effect when clicked during a presentation.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Presenter View:</strong> A special presentation view visible only to the presenter, showing notes, next slides, a timer, and navigation tools.</p>
                </div>
                <div>
                    <p><strong>Compare & Merge:</strong> A feature that allows you to combine and review changes from two versions of a presentation.</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Animation Mastery:</strong>
                        <ul>
                            <li>Create a 3-slide presentation with complex animation sequences</li>
                            <li>Use all four animation types (entrance, emphasis, exit, motion paths)</li>
                            <li>Apply the Morph transition between two similar slides</li>
                            <li>Set up automatic slide advancement with transitions</li>
                        </ul>
                    </li>
                    <li><strong>Collaboration Practice:</strong>
                        <ul>
                            <li>Add comments to 3 different slides with feedback suggestions</li>
                            <li>Run Accessibility Checker and fix any issues found</li>
                            <li>Check spelling and use the thesaurus to improve word choices</li>
                        </ul>
                    </li>
                    <li><strong>Delivery Preparation:</strong>
                        <ul>
                            <li>Create a custom slide show for a specific audience</li>
                            <li>Practice with Rehearse Timings and record your presentation pace</li>
                            <li>Save in three formats: .pptx, .ppsx, and .pdf</li>
                            <li>Add password protection to your final presentation</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed presentation via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-300 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Apply and configure slide transitions</li>
                    <li>Create and modify animations</li>
                    <li>Use the Animation Pane</li>
                    <li>Work with motion paths</li>
                    <li>Add and manage comments</li>
                    <li>Compare and merge presentations</li>
                    <li>Use proofing tools</li>
                    <li>Create custom slide shows</li>
                    <li>Use Presenter View</li>
                    <li>Rehearse slide timings</li>
                    <li>Protect and secure presentations</li>
                    <li>Export presentations</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Animation with Purpose:</strong> Every animation should have a reason—to reveal information sequentially, emphasize a key point, or guide the visual story.</li>
                    <li><strong>Consistency is Key:</strong> Use a consistent set of 2-3 transition types throughout your presentation to maintain a professional feel.</li>
                    <li><strong>Master the Pane:</strong> The Animation Pane is your most powerful tool for troubleshooting and fine-tuning complex sequences.</li>
                    <li><strong>Always Rehearse with Technology:</strong> Practice your presentation with the actual computer and projector/clicker you will use.</li>
                    <li><strong>Protect Your Work:</strong> Always password-protect or finalize sensitive presentations before distribution.</li>
                    <li><strong>Use Comments Effectively:</strong> Provide specific, constructive feedback using the comments feature.</li>
                    <li><strong>Check Accessibility:</strong> Ensure your presentations are accessible to all audience members.</li>
                </ul>
            </div>
            
            <!-- Course Conclusion -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Course Conclusion & Next Steps</h3>
                <p>Congratulations on completing the core curriculum of the MO-300 Exam Preparation Program! You have built a comprehensive skill set—from foundational design and data visualization to advanced animation and professional collaboration. You are now prepared to create powerful, polished presentations that meet professional standards.</p>
                <p style="margin-top: 10px;">Continue to practice with the provided materials, revisit the self-review questions, and explore the official Microsoft exam resources. We wish you the greatest success on your MO-300 certification exam!</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft PowerPoint Animation Tutorial Videos</li>
                    <li>Advanced Transition Effects Guide</li>
                    <li>Collaboration Best Practices for Teams</li>
                    <li>MO-300 Official Practice Test and Study Guide</li>
                    <li>Practice files and templates available in the Course Portal</li>
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
                Week 6 Handout: Collaboration, Review & Animation Finale
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
            Week 6: Collaboration, Review & Animation Finale | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-300 PowerPoint Certification Prep | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 6: Collaboration, Review & Animation Finale - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #4a148c 0%, #7b1fa2 100%);
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
            background: linear-gradient(135deg, #7b1fa2 0%, #4a148c 100%);
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
            color: #4a148c;
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

        .exercise-box {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #4a148c;
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

        .next-week {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .next-week-title {
            color: #2e7d32;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .help-section {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .help-title {
            color: #7b1fa2;
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
            background: #4a148c;
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
            color: #7b1fa2;
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

        /* Animation Types Demo */
        .animation-types {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .animation-type {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .animation-type:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #7b1fa2;
        }

        .animation-icon {
            font-size: 3rem;
            color: #7b1fa2;
            margin-bottom: 15px;
        }

        .animation-type.entrance {
            border-color: #4caf50;
        }

        .animation-type.emphasis {
            border-color: #ff9800;
        }

        .animation-type.exit {
            border-color: #f44336;
        }

        .animation-type.motion {
            border-color: #2196f3;
        }

        .animation-type.entrance .animation-icon {
            color: #4caf50;
        }

        .animation-type.emphasis .animation-icon {
            color: #ff9800;
        }

        .animation-type.exit .animation-icon {
            color: #f44336;
        }

        .animation-type.motion .animation-icon {
            color: #2196f3;
        }

        /* Transitions Demo */
        .transition-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 25px 0;
        }

        .transition-item {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            transition: all 0.3s;
        }

        .transition-item:hover {
            background: #f3e5f5;
            border-color: #7b1fa2;
        }

        .transition-icon {
            font-size: 2.5rem;
            color: #7b1fa2;
            margin-bottom: 10px;
        }

        /* Collaboration Tools */
        .collab-tools {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .collab-tool {
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            color: white;
            transition: transform 0.3s;
        }

        .collab-tool:hover {
            transform: translateY(-5px);
        }

        .collab-tool.comments {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
        }

        .collab-tool.compare {
            background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
        }

        .collab-tool.review {
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
        }

        .collab-tool.accessibility {
            background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
        }

        .collab-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        /* Animation Timeline */
        .timeline-demo {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .timeline {
            position: relative;
            height: 100px;
            margin: 20px 0;
        }

        .timeline-line {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: #7b1fa2;
            transform: translateY(-50%);
        }

        .timeline-item {
            position: absolute;
            top: 50%;
            width: 80px;
            text-align: center;
            transform: translate(-50%, -50%);
        }

        .timeline-item:nth-child(1) {
            left: 10%;
        }

        .timeline-item:nth-child(2) {
            left: 30%;
        }

        .timeline-item:nth-child(3) {
            left: 50%;
        }

        .timeline-item:nth-child(4) {
            left: 70%;
        }

        .timeline-item:nth-child(5) {
            left: 90%;
        }

        .timeline-dot {
            width: 20px;
            height: 20px;
            background: #7b1fa2;
            border-radius: 50%;
            margin: 0 auto 10px;
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
            
            .animation-types,
            .transition-demo,
            .collab-tools,
            .timeline-demo {
                page-break-inside: avoid;
            }
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

            .animation-types,
            .collab-tools {
                grid-template-columns: 1fr;
            }
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
                <strong>Access Granted:</strong> PowerPoint Week 6 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week5_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 5
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 6 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Collaboration, Review & Animation Finale</div>
            <div class="week-tag">Week 6 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 6!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week marks the crucial transition from content <strong>creation</strong> to presentation <strong>perfection</strong>. You will learn to orchestrate your slides with professional transitions and precise animations that guide your audience's attention. Furthermore, we dive into the essential collaborative tools of PowerPoint, enabling you to refine your work with feedback, manage multiple versions, and prepare flawlessly for delivery. By the end of this week, you will be equipped to polish, secure, and present your work with the confidence of a true PowerPoint professional.
                </p>

                <div class="image-container">
                    <img src="images/powerpoint_animations.png"
                        alt="PowerPoint Animation and Collaboration Tools"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjNmM2YzIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzc1NzU3NSI+UG93ZXJQb2ludCBBbmltYXRpb24gJiBDb2xsYWJvcmF0aW9uIFRvb2xzPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Professional PowerPoint Animation and Collaboration Features</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Apply and configure sophisticated slide transitions to control narrative flow</li>
                    <li>Animate text, graphics, and 3D models using entrances, emphases, exits, and motion paths</li>
                    <li>Master the Animation Pane to sequence, trigger, and time complex animations</li>
                    <li>Collaborate effectively using Comments, Notes, and the Compare & Merge tools</li>
                    <li>Prepare for delivery by creating custom shows, using Presenter View, and securing final outputs</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-300 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Apply and configure slide transitions</li>
                        <li>Create and modify animations</li>
                        <li>Use the Animation Pane</li>
                        <li>Work with motion paths</li>
                        <li>Add and manage comments</li>
                        <li>Compare and merge presentations</li>
                    </ul>
                    <ul>
                        <li>Use proofing tools</li>
                        <li>Create custom slide shows</li>
                        <li>Use Presenter View</li>
                        <li>Rehearse slide timings</li>
                        <li>Protect and secure presentations</li>
                        <li>Export presentations</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Professional Slide Transitions -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-sliders-h"></i> 1. Professional Slide Transitions
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exchange-alt"></i> Applying Transitions</h3>
                    <ul>
                        <li><strong>Transitions Tab:</strong> Access all transition effects</li>
                        <li><strong>Subtle Category:</strong> Fade, Cut, Push, Wipe, Split</li>
                        <li><strong>Exciting Category:</strong> Flip, Clock, Ripple, Honeycomb</li>
                        <li><strong>Dynamic Content:</strong> Morph transition for cinematic effects between similar slides</li>
                        <li><strong>Effect Options:</strong> Customize direction and behavior</li>
                    </ul>

                    <div class="transition-demo">
                        <div class="transition-item" onclick="demonstrateTransition('Fade')">
                            <div class="transition-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <h4>Fade</h4>
                            <p style="font-size: 0.9rem; color: #666;">Smooth transition</p>
                        </div>
                        <div class="transition-item" onclick="demonstrateTransition('Push')">
                            <div class="transition-icon">
                                <i class="fas fa-arrows-alt-h"></i>
                            </div>
                            <h4>Push</h4>
                            <p style="font-size: 0.9rem; color: #666;">Slide pushes content</p>
                        </div>
                        <div class="transition-item" onclick="demonstrateTransition('Morph')">
                            <div class="transition-icon">
                                <i class="fas fa-shapes"></i>
                            </div>
                            <h4>Morph</h4>
                            <p style="font-size: 0.9rem; color: #666;">Animated transformation</p>
                        </div>
                        <div class="transition-item" onclick="demonstrateTransition('Zoom')">
                            <div class="transition-icon">
                                <i class="fas fa-search-plus"></i>
                            </div>
                            <h4>Zoom</h4>
                            <p style="font-size: 0.9rem; color: #666;">Focus transition</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> Configuring Transition Effects</h3>
                    <ul>
                        <li><strong>Duration:</strong> Control speed (0.25s to 5.00s)</li>
                        <li><strong>Sound:</strong> Add sound effects (applause, whoosh, etc.)</li>
                        <li><strong>Advance Slide:</strong>
                            <ul>
                                <li><strong>On Mouse Click:</strong> Manual control</li>
                                <li><strong>After:</strong> Automatic timing (set seconds)</li>
                            </ul>
                        </li>
                        <li><strong>Apply To All:</strong> Consistent transitions across presentation</li>
                        <li><strong>Preview:</strong> Always preview before finalizing</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Transition Tips
                        </div>
                        <ul>
                            <li>Use <strong>consistent</strong> transition types throughout presentation</li>
                            <li><strong>Morph</strong> works best with similar slide layouts</li>
                            <li>Avoid too many different transitions</li>
                            <li>Use <strong>automatic advancement</strong> for kiosk or self-running presentations</li>
                            <li><strong>Preview</strong> transitions in Slide Show mode</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Section 2: Mastering Custom Animations -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-film"></i> 2. Mastering Custom Animations
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-play-circle"></i> Animation Types</h3>
                    <div class="animation-types">
                        <div class="animation-type entrance" onclick="demonstrateAnimation('Entrance')">
                            <div class="animation-icon">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <h4>Entrance</h4>
                            <p>How objects appear</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Fade, Fly In, Zoom</p>
                        </div>
                        <div class="animation-type emphasis" onclick="demonstrateAnimation('Emphasis')">
                            <div class="animation-icon">
                                <i class="fas fa-expand-alt"></i>
                            </div>
                            <h4>Emphasis</h4>
                            <p>Draw attention to objects</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Spin, Pulse, Grow/Shrink</p>
                        </div>
                        <div class="animation-type exit" onclick="demonstrateAnimation('Exit')">
                            <div class="animation-icon">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <h4>Exit</h4>
                            <p>How objects disappear</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Fade, Fly Out, Zoom</p>
                        </div>
                        <div class="animation-type motion" onclick="demonstrateAnimation('Motion')">
                            <div class="animation-icon">
                                <i class="fas fa-route"></i>
                            </div>
                            <h4>Motion Paths</h4>
                            <p>Move objects along paths</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Lines, Arcs, Custom paths</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Animation Pane Mastery</h3>
                    <ul>
                        <li><strong>Access:</strong> Animations Tab → Animation Pane</li>
                        <li><strong>Timing Options:</strong>
                            <ul>
                                <li><strong>Start:</strong> On Click, With Previous, After Previous</li>
                                <li><strong>Duration:</strong> Speed of animation (0.5s to 10s)</li>
                                <li><strong>Delay:</strong> Pause before animation starts</li>
                            </ul>
                        </li>
                        <li><strong>Effect Options:</strong> Customize direction, size, smoothness</li>
                        <li><strong>Reordering:</strong> Drag animations in the pane</li>
                        <li><strong>Triggers:</strong> Start animations by clicking specific objects</li>
                        <li><strong>Preview:</strong> Play button in Animation Pane</li>
                    </ul>

                    <div class="timeline-demo">
                        <h4 style="color: #7b1fa2; margin-bottom: 15px;">Animation Sequence Example:</h4>
                        <div class="timeline">
                            <div class="timeline-line"></div>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div>Title Fly In</div>
                                <small>On Click</small>
                            </div>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div>Bullet 1</div>
                                <small>After 0.5s</small>
                            </div>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div>Bullet 2</div>
                                <small>After 0.5s</small>
                            </div>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div>Chart Growth</div>
                                <small>With Previous</small>
                            </div>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div>Emphasis</div>
                                <small>After 1s</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-road"></i> Motion Paths</h3>
                    <ul>
                        <li><strong>Basic Paths:</strong> Lines, Arcs, Turns, Loops</li>
                        <li><strong>Custom Paths:</strong> Draw your own path</li>
                        <li><strong>Editing Points:</strong> Modify path shape</li>
                        <li><strong>Reversing Path:</strong> Change direction</li>
                        <li><strong>Smooth Start/End:</strong> Natural movement</li>
                        <li><strong>Auto-reverse:</strong> Return to start position</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Collaboration & Review Tools -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-users"></i> 3. Collaboration & Review Tools
                </div>

                <div class="collab-tools">
                    <div class="collab-tool comments" onclick="demonstrateTool('Comments')">
                        <div class="collab-icon">
                            <i class="fas fa-comment-dots"></i>
                        </div>
                        <h3>Comments</h3>
                        <p>Add feedback to specific elements</p>
                        <p style="font-size: 0.9rem; opacity: 0.9; margin-top: 10px;">Review Tab → New Comment</p>
                    </div>
                    <div class="collab-tool compare" onclick="demonstrateTool('Compare')">
                        <div class="collab-icon">
                            <i class="fas fa-code-branch"></i>
                        </div>
                        <h3>Compare & Merge</h3>
                        <p>Combine presentation versions</p>
                        <p style="font-size: 0.9rem; opacity: 0.9; margin-top: 10px;">Review Tab → Compare</p>
                    </div>
                    <div class="collab-tool review" onclick="demonstrateTool('Review')">
                        <div class="collab-icon">
                            <i class="fas fa-spell-check"></i>
                        </div>
                        <h3>Proofing Tools</h3>
                        <p>Spelling, grammar, thesaurus</p>
                        <p style="font-size: 0.9rem; opacity: 0.9; margin-top: 10px;">Review Tab → Spelling</p>
                    </div>
                    <div class="collab-tool accessibility" onclick="demonstrateTool('Accessibility')">
                        <div class="collab-icon">
                            <i class="fas fa-universal-access"></i>
                        </div>
                        <h3>Accessibility</h3>
                        <p>Check for accessibility issues</p>
                        <p style="font-size: 0.9rem; opacity: 0.9; margin-top: 10px;">Review Tab → Check Accessibility</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-comments"></i> Working with Comments</h3>
                    <ul>
                        <li><strong>Add Comments:</strong> Select object → Review Tab → New Comment</li>
                        <li><strong>Threaded Comments:</strong> Reply to comments for discussions</li>
                        <li><strong>Resolve Comments:</strong> Mark as resolved when addressed</li>
                        <li><strong>Show Comments:</strong> Navigate through all comments</li>
                        <li><strong>Delete Comments:</strong> Remove resolved or outdated comments</li>
                        <li><strong>Print Comments:</strong> Include comments in printouts</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-code-branch"></i> Compare & Merge Presentations</h3>
                    <ul>
                        <li><strong>Compare:</strong> Review Tab → Compare → Choose file to compare</li>
                        <li><strong>Review Changes:</strong> Side-by-side comparison</li>
                        <li><strong>Accept/Reject:</strong> Individual or all changes</li>
                        <li><strong>End Review:</strong> Finalize comparison</li>
                        <li><strong>Revision History:</strong> Track changes over time</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-universal-access"></i> Accessibility Checker</h3>
                    <ul>
                        <li><strong>Run Checker:</strong> Review Tab → Check Accessibility</li>
                        <li><strong>Common Issues:</strong>
                            <ul>
                                <li>Missing Alt Text for images/charts</li>
                                <li>Poor color contrast</li>
                                <li>Incorrect reading order</li>
                                <li>Missing slide titles</li>
                                <li>Table headers not defined</li>
                            </ul>
                        </li>
                        <li><strong>Fix Issues:</strong> Click each issue for repair options</li>
                        <li><strong>Generate Report:</strong> Create accessibility report</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Final Preparation & Delivery -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-rocket"></i> 4. Final Preparation & Delivery
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Custom Slide Shows</h3>
                    <ul>
                        <li><strong>Create:</strong> Slide Show Tab → Custom Slide Show → Custom Shows</li>
                        <li><strong>Select Slides:</strong> Choose specific slides for different audiences</li>
                        <li><strong>Multiple Shows:</strong> Create different versions from same presentation</li>
                        <li><strong>Edit Shows:</strong> Modify existing custom shows</li>
                        <li><strong>Present Show:</strong> Select and run custom show</li>
                        <li><strong>Use Cases:</strong>
                            <ul>
                                <li>Executive summary (slides 1, 3, 5, 8)</li>
                                <li>Technical deep dive (slides 2, 4, 6, 7)</li>
                                <li>Quick overview (slides 1, 5, 10)</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-clock"></i> Rehearsal Tools</h3>
                    <ul>
                        <li><strong>Rehearse Timings:</strong>
                            <ul>
                                <li>Slide Show Tab → Rehearse Timings</li>
                                <li>Practice presentation with recording</li>
                                <li>Timer shows current slide time and total time</li>
                                <li>Accept recorded timings for automatic advancement</li>
                            </ul>
                        </li>
                        <li><strong>Presenter View:</strong>
                            <ul>
                                <li>Slide Show Tab → check "Use Presenter View"</li>
                                <li>Speaker view with notes, next slides, timer</li>
                                <li>Audience sees only the current slide</li>
                                <li>Tools: Pen, laser pointer, zoom, black screen</li>
                            </ul>
                        </li>
                        <li><strong>Presentation Tools:</strong>
                            <ul>
                                <li>Laser pointer (Ctrl + click and hold)</li>
                                <li>Pen and highlighter (Ctrl + P)</li>
                                <li>Zoom (Ctrl + plus/minus)</li>
                                <li>Black/white screen (B or W key)</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lock"></i> Securing & Exporting Presentations</h3>
                    <ul>
                        <li><strong>Inspect Document:</strong>
                            <ul>
                                <li>File → Info → Check for Issues → Inspect Document</li>
                                <li>Remove hidden metadata, comments, personal info</li>
                                <li>Check for accessibility issues</li>
                            </ul>
                        </li>
                        <li><strong>Protect Presentation:</strong>
                            <ul>
                                <li>Encrypt with Password (open/ modify permissions)</li>
                                <li>Restrict Access (IRM - Information Rights Management)</li>
                                <li>Add Digital Signature</li>
                                <li>Mark as Final (read-only warning)</li>
                            </ul>
                        </li>
                        <li><strong>Export Formats:</strong>
                            <ul>
                                <li><strong>PowerPoint Show (.ppsx):</strong> Opens directly in Slide Show mode</li>
                                <li><strong>PDF:</strong> Fixed layout, universal access</li>
                                <li><strong>Video (.mp4):</strong> Recorded presentation with timings</li>
                                <li><strong>Package for CD:</strong> Include linked files and viewer</li>
                                <li><strong>Handouts (Word):</strong> Export slides to Word for notes</li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 5. Essential Shortcuts for Week 6
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
                            <td><span class="shortcut-key">Alt > S > T</span></td>
                            <td>Open Transitions Tab</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > A > A</span></td>
                            <td>Open Animations Tab</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > A > P</span></td>
                            <td>Open the Animation Pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > R > K</span></td>
                            <td>Start Slide Show from beginning</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > R > B</span></td>
                            <td>Rehearse Timings</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W, C</span></td>
                            <td>Enter Presenter View</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > R > C</span></td>
                            <td>Insert a New Comment</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + F5</span></td>
                            <td>Start slideshow from current slide</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">B</span> or <span class="shortcut-key">.</span></td>
                            <td>Black screen during slideshow</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">W</span> or <span class="shortcut-key">,</span></td>
                            <td>White screen during slideshow</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Pen tool during slideshow</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + A</span></td>
                            <td>Arrow tool during slideshow</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + M</span></td>
                            <td>Show/Hide ink markup</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save (always!)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F12</span></td>
                            <td>Save As</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + F1</span></td>
                            <td>Show/Hide Ribbon</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 6. Hands-On Exercise: Project Launch Presentation
                </div>
                <p><strong>Objective:</strong> Apply professional animations, transitions, and collaboration tools to prepare a presentation for delivery.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #4a148c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open a presentation with at least 4 slides (create one if needed)</li>
                        <li>On a content slide, apply the <strong>Morph</strong> transition (Transitions Tab)</li>
                        <li>Apply a <strong>Fade</strong> transition to all other slides, set to advance automatically after <strong>3 seconds</strong></li>
                        <li>Create a complex animation sequence:
                            <ul>
                                <li>Title: <strong>Fly In</strong> from left (Start: On Click)</li>
                                <li>Bullet List: <strong>Fade</strong> (Start: After Previous with 0.5s delay)</li>
                                <li>Shape: <strong>Grow/Shrink</strong> emphasis followed by a custom Motion Path</li>
                            </ul>
                        </li>
                        <li>Use the <strong>Animation Pane</strong> to reorder and time animations</li>
                        <li>Add a <strong>Comment</strong> to an animated shape: "Should this color be more bold?"</li>
                        <li>Run <strong>Spelling & Grammar</strong> check (Review Tab)</li>
                        <li>Run the <strong>Accessibility Checker</strong> and add Alt Text to any missing elements</li>
                        <li>Create a <strong>Custom Slide Show</strong> that omits appendix slides</li>
                        <li>Practice with <strong>Rehearse Timings</strong> for 2-3 slides</li>
                        <li>Enter <strong>Presenter View</strong> to see your notes and upcoming slides</li>
                        <li>Save as a <strong>PowerPoint Show (.ppsx)</strong> file</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Professional Presentation Delivery"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Qcm9mZXNzaW9uYWwgUHJlc2VudGF0aW9uIERlbGl2ZXJ5PC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Professional Presentation Delivery with Animation and Collaboration</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadExerciseTemplate()">
                    <i class="fas fa-download"></i> Download Project Launch Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 7. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Morph Transition</strong>
                    <p>A sophisticated transition that creates a smooth, animated movement of objects between two slides with similar layouts, creating cinematic effects.</p>
                </div>

                <div class="term">
                    <strong>Animation Pane</strong>
                    <p>A task pane that lists all animations on the current slide in sequence, allowing for detailed timing, effect management, and reordering of animations.</p>
                </div>

                <div class="term">
                    <strong>Trigger</strong>
                    <p>An object on a slide that starts an animation effect when clicked during a presentation, allowing for interactive and non-linear presentations.</p>
                </div>

                <div class="term">
                    <strong>Presenter View</strong>
                    <p>A special presentation view visible only to the presenter, showing notes, next slides, a timer, and navigation tools while the audience sees only the current slide.</p>
                </div>

                <div class="term">
                    <strong>Compare & Merge</strong>
                    <p>A feature that allows you to combine and review changes from two versions of a presentation, accepting or rejecting individual edits slide-by-slide.</p>
                </div>

                <div class="term">
                    <strong>Motion Path</strong>
                    <p>A predefined or custom path along which an object moves during an animation, allowing for complex movement patterns.</p>
                </div>

                <div class="term">
                    <strong>Custom Slide Show</strong>
                    <p>A tailored presentation created from a subset of slides from the main presentation, designed for specific audiences or situations.</p>
                </div>

                <div class="term">
                    <strong>PowerPoint Show (.ppsx)</strong>
                    <p>A presentation file format that opens directly in Slide Show mode, bypassing the editing interface for immediate presentation.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 8. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete these exercises:</h4>
                    <ol>
                        <li><strong>Animation Mastery:</strong>
                            <ul>
                                <li>Create a 3-slide presentation with complex animation sequences</li>
                                <li>Use all four animation types (entrance, emphasis, exit, motion paths)</li>
                                <li>Apply the Morph transition between two similar slides</li>
                                <li>Set up automatic slide advancement with transitions</li>
                                <li>Use the Animation Pane to sequence and time animations</li>
                            </ul>
                        </li>
                        <li><strong>Collaboration Practice:</strong>
                            <ul>
                                <li>Add comments to 3 different slides with feedback suggestions</li>
                                <li>Run Accessibility Checker and fix any issues found</li>
                                <li>Check spelling and use the thesaurus to improve word choices</li>
                                <li>Compare two versions of a presentation and merge changes</li>
                            </ul>
                        </li>
                        <li><strong>Delivery Preparation:</strong>
                            <ul>
                                <li>Create a custom slide show for a specific audience</li>
                                <li>Practice with Rehearse Timings and record your presentation pace</li>
                                <li>Save in three formats: .pptx (editable), .ppsx (show), and .pdf</li>
                                <li>Add password protection to your final presentation</li>
                                <li>Use Presenter View during practice sessions</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Submit your <strong>AnimatedPresentation.pptx</strong> and the three export files (.ppsx, .pdf, password-protected version) via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 9. Tips for Success
                </div>
                <ul>
                    <li><strong>Animation with Purpose:</strong> Every animation should have a reason—to reveal information sequentially, emphasize a key point, or guide the visual story. Avoid animation for mere decoration.</li>
                    <li><strong>Consistency is Key:</strong> Use a consistent set of 2-3 transition types throughout your presentation to maintain a professional feel and avoid distracting your audience.</li>
                    <li><strong>Master the Pane:</strong> The Animation Pane is your most powerful tool for troubleshooting and fine-tuning complex animation sequences. Learn to use it effectively.</li>
                    <li><strong>Always Rehearse with Technology:</strong> Practice your presentation with the actual computer and projector/clicker you will use to avoid technical surprises during the actual presentation.</li>
                    <li><strong>Protect Your Work:</strong> Always password-protect or finalize sensitive presentations before distribution to prevent unwanted edits or unauthorized access.</li>
                    <li><strong>Use Comments Effectively:</strong> Provide specific, constructive feedback using the comments feature. Resolve comments when addressed to keep track of changes.</li>
                    <li><strong>Check Accessibility:</strong> Ensure your presentations are accessible to all audience members, including those with visual or hearing impairments.</li>
                    <li><strong>Custom Shows for Different Audiences:</strong> Create tailored versions of your presentation for different stakeholders or presentation contexts.</li>
                    <li><strong>Presenter View Confidence:</strong> Practice using Presenter View to build confidence in delivering presentations without constantly looking at the main screen.</li>
                    <li><strong>Backup and Version Control:</strong> Save multiple versions of your presentation and use descriptive file names to track your progress and revisions.</li>
                </ul>
            </div>

            <!-- Course Conclusion -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-trophy"></i> 10. Course Conclusion & Next Steps
                </div>
                <p><strong>Congratulations on completing the core curriculum of the MO-300 Exam Preparation Program!</strong></p>
                <p style="margin: 15px 0;">You have built a comprehensive skill set—from foundational design and data visualization to advanced animation and professional collaboration. You are now prepared to create powerful, polished presentations that meet professional standards and impress any audience.</p>
                
                <div style="background: rgba(255, 255, 255, 0.3); padding: 15px; border-radius: 5px; margin: 20px 0;">
                    <h4 style="color: #4a148c; margin-bottom: 10px;">Your Next Steps:</h4>
                    <ol>
                        <li><strong>Practice:</strong> Continue to practice with the provided materials and create sample presentations</li>
                        <li><strong>Review:</strong> Revisit the self-review questions from all weeks</li>
                        <li><strong>Explore:</strong> Check out the official Microsoft exam resources and practice tests</li>
                        <li><strong>Apply:</strong> Use your new skills in real-world projects and presentations</li>
                        <li><strong>Prepare:</strong> Schedule your MO-300 certification exam when you feel ready</li>
                    </ol>
                </div>
                
                <p style="font-style: italic; color: #4a148c; border-left: 3px solid #7b1fa2; padding-left: 15px; margin-top: 20px;">
                    "The success of your presentation will be judged not by the knowledge you send but by what the listener receives." - Lilly Walters
                </p>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/animate-text-or-objects-305a1c94-83b1-4778-8df5-fcf7a9b7b7c6" target="_blank">Microsoft PowerPoint Animation Tutorial</a></li>
                    <li><a href="https://support.microsoft.com/office/use-the-morph-transition-in-powerpoint-8dd1c7b9-b5a5-4c01-8e46-8a8e18f5a7b4" target="_blank">Morph Transition Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/collaborate-on-powerpoint-presentations-73a3a6a0-eb6a-4d6b-b2a3-6c9c8b9c9b4c" target="_blank">Collaboration in PowerPoint</a></li>
                    <li><a href="https://support.microsoft.com/office/make-your-powerpoint-presentations-accessible-to-people-with-disabilities-6f7772b2-2f33-4bd2-8ca7-dae3b2b3ef25" target="_blank">Accessibility in PowerPoint</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/learn/certifications/exams/mo-300" target="_blank">MO-300 Official Exam Page</a></li>
                    <li><a href="https://docs.microsoft.com/en-us/learn/paths/powerpoint-2019/" target="_blank">Microsoft Learn PowerPoint Path</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Week 6 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>MO-300 Practice Test</strong> (available in portal)</li>
                </ul>
            </div>

            <!-- Help Section -->
            <div class="help-section">
                <div class="help-title">
                    <i class="fas fa-question-circle"></i> Need Help?
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></li>
                    <li><strong>Class Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a></li>
                    <li><strong>Office Hours:</strong> Mondays & Wednesdays, 3:00 PM - 5:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week6.php">Week 6 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft PowerPoint Help:</strong> <a href="https://support.microsoft.com/powerpoint" target="_blank">Official Support</a></li>
                    <li><strong>PowerPoint Community:</strong> <a href="https://techcommunity.microsoft.com/t5/powerpoint/ct-p/PowerPoint" target="_blank">Microsoft Tech Community</a></li>
                    <li><strong>MO-300 Exam Resources:</strong> <a href="https://docs.microsoft.com/en-us/learn/certifications/powerpoint" target="_blank">Official Certification Page</a></li>
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
                <!-- <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week6_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 6 Quiz
                </a>
                <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/mo300_practice_test.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #ff9800; margin-left: 15px;">
                    <i class="fas fa-graduation-cap"></i> Practice Test
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 6 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-300 PowerPoint Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
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

        // Simulate template download
        function downloadExerciseTemplate() {
            alert('Project Launch presentation template would download. This is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/templates/week6_project_launch.potx';
        }

        // Animation demonstration
        function demonstrateAnimation(type) {
            const examples = {
                'Entrance': 'Examples: Fade, Fly In, Zoom, Split, Wipe\nUse: Introduce elements onto the slide',
                'Emphasis': 'Examples: Spin, Pulse, Grow/Shrink, Color Pulse\nUse: Highlight important elements',
                'Exit': 'Examples: Fade, Fly Out, Zoom, Disappear\nUse: Remove elements from view',
                'Motion': 'Examples: Lines, Arcs, Loops, Custom Paths\nUse: Move elements along paths'
            };
            
            alert(`${type} Animations\n\n${examples[type]}\n\nApply from Animations Tab → select object → choose animation`);
        }

        // Transition demonstration
        function demonstrateTransition(type) {
            const descriptions = {
                'Fade': 'One slide fades into the next. Professional and subtle.',
                'Push': 'Current slide pushes off as new slide enters. Directional.',
                'Morph': 'Objects transform between slides. Requires similar layouts.',
                'Zoom': 'Focuses attention with zoom effect. Great for emphasis.'
            };
            
            alert(`${type} Transition\n\n${descriptions[type]}\n\nApply from Transitions Tab → select slide → choose transition`);
        }

        // Tool demonstration
        function demonstrateTool(tool) {
            const instructions = {
                'Comments': 'Add feedback: Select object → Review Tab → New Comment\nResolve comments when addressed.',
                'Compare': 'Merge versions: Review Tab → Compare → Choose file\nAccept/reject changes individually.',
                'Review': 'Proofing: Review Tab → Spelling/Thesaurus\nCheck language and improve text.',
                'Accessibility': 'Check: Review Tab → Check Accessibility\nFix issues for all users.'
            };
            
            alert(`${tool} Tool\n\n${instructions[tool]}`);
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
                console.log('PowerPoint Week 6 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. After Previous with 0.5s delay between each animation.",
                    "2. Review Tab → Compare feature.",
                    "3. Opens directly in Slide Show mode, bypassing the editing interface.",
                    "4. Rehearse Timings tool (Alt > R > B).",
                    "5. Effect Options in the Animations Tab."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Animation practice
        document.addEventListener('keydown', function(e) {
            const animationShortcuts = {
                '1': 'Alt > A > A (Animations Tab)',
                '2': 'Alt > S > T (Transitions Tab)',
                '3': 'Shift + F5 (Start from current slide)',
                '4': 'B (Black screen during slideshow)',
                '5': 'W (White screen during slideshow)'
            };
            
            if (animationShortcuts[e.key]) {
                const shortcutAlert = document.createElement('div');
                shortcutAlert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #7b1fa2;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    z-index: 1000;
                    animation: fadeOut 2s forwards;
                `;
                shortcutAlert.textContent = `PowerPoint Shortcut: ${animationShortcuts[e.key]}`;
                document.body.appendChild(shortcutAlert);
                
                setTimeout(() => {
                    shortcutAlert.remove();
                }, 2000);
            }
        });

        // Add CSS for animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                0% { opacity: 1; }
                70% { opacity: 1; }
                100% { opacity: 0; }
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
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveElements = document.querySelectorAll('a, button, .animation-type, .transition-item, .collab-tool');
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

        // Morph transition demonstration
        function demonstrateMorph() {
            const morphInfo = [
                "Morph Transition Guide:",
                "\nRequirements:",
                "• Similar slide layouts",
                "• Same objects on both slides",
                "• PowerPoint 2019 or later",
                "\nBest Practices:",
                "• Use consistent object names",
                "• Position objects logically",
                "• Test transition effect",
                "\nCreative Uses:",
                "• Zoom into details",
                "• Move objects across slides",
                "• Transform shapes",
                "• Create animated diagrams"
            ];
            alert(morphInfo.join("\n"));
        }

        // Presenter View demonstration
        function demonstratePresenterView() {
            const pvInfo = [
                "Presenter View Features:",
                "\nVisible to Presenter:",
                "• Current slide notes",
                "• Next slide preview",
                "• Elapsed time timer",
                "• Slide navigation",
                "• Annotation tools",
                "\nVisible to Audience:",
                "• Current slide only",
                "\nSetup:",
                "1. Connect second display/projector",
                "2. Slide Show Tab → Use Presenter View",
                "3. Extend display in Windows settings",
                "4. Start slideshow (F5)"
            ];
            alert(pvInfo.join("\n"));
        }

        // Animation timing demonstration
        function demonstrateTiming() {
            const timingInfo = [
                "Animation Timing Options:",
                "\nStart Options:",
                "• On Click: Manual control",
                "• With Previous: Simultaneous",
                "• After Previous: Sequential",
                "\nTiming Controls:",
                "• Duration: 0.25s to 10.00s",
                "• Delay: Pause before starting",
                "• Repeat: Loop animations",
                "• Rewind: Return to original state",
                "\nPro Tips:",
                "• Use consistent timing",
                "• Match animation to content pace",
                "• Preview in Animation Pane",
                "• Use triggers for interactivity"
            ];
            alert(timingInfo.join("\n"));
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
    $viewer = new PowerPointWeek6HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>