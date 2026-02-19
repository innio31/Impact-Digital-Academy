<?php
// modules/shared/course_materials/LifeSkills/life_skills_syllabus_view.php

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
 * Life Skills Syllabus Viewer Class with PDF Download
 */
class LifeSkillsSyllabusViewer
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
        $access_count = $this->user_role === 'student'
            ? $this->checkGeneralStudentAccess()
            : $this->checkGeneralInstructorAccess();
        
        if ($access_count === 0) {
            $this->redirectToDashboard();
        }
    }
    
    /**
     * Check general student access to Life Skills courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND (c.title LIKE '%Life Skills%' OR c.title LIKE '%Personal Development%')";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to Life Skills courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND (c.title LIKE '%Life Skills%' OR c.title LIKE '%Personal Development%')";
        
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
            
            // Initialize mPDF with dark yellow theme colors
            $mpdfConfig = [
                'mode' => 'utf-8',
                'format' => 'A4',
                'default_font_size' => 11,
                'default_font' => 'dejavusans',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'margin_header' => 10,
                'margin_footer' => 10,
                'tempDir' => sys_get_temp_dir()
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
            $mpdf->SetTitle('Life Skills: Constructing Your Personal Pathway');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Personal Development, Pathway Construction');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Personal Development, Pathway, Stewardship, Productivity, Financial Management, Mindset, Growth');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Personal_Pathway_Syllabus_' . date('Y-m-d') . '.pdf';
            
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
                <button onclick="window.print()" style="padding: 10px 20px; background: #b8860b; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    <i class="fas fa-print"></i> Print Syllabus
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
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 11pt;">
            <h1 style="color: #8B7500; border-bottom: 3px solid #DAA520; padding-bottom: 15px; font-size: 20pt; text-align: center;">
                Life Skills: Constructing Your Personal Pathway
            </h1>
            
            <h2 style="color: #B8860B; font-size: 16pt; margin-top: 25px; margin-bottom: 20px;">
                Complete Course Syllabus
            </h2>
            
            <!-- Program Overview -->
            <div style="margin-bottom: 25px; padding: 20px; background: #FFF8DC; border-radius: 8px;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #E6D690; padding-bottom: 10px;">
                    <i class="fas fa-bullseye"></i> Program Goal
                </h3>
                <p style="font-size: 12pt; line-height: 1.8;">
                    Equip learners with the foundational life skills and personal frameworks necessary to intentionally discern, construct, and pursue their unique pathway in life, fostering resilience, stewardship, and proactive growth.
                </p>
            </div>
            
            <!-- Course Description -->
            <div style="margin-bottom: 25px; padding: 20px; background: #F5F5DC; border-left: 4px solid #DAA520; border-radius: 8px;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt; margin-bottom: 10px;">
                    <i class="fas fa-book-open"></i> Course Description
                </h3>
                <p style="font-size: 12pt; line-height: 1.8;">
                    This course invites you to discern your pathway in life and strengthen your ability to pursue it. Your pathway is a road that you construct based on an understanding of your stewardships, your aspirations, strengths and talents. It is based on an understanding of the challenges and constraints that you need to endure, reframe, or overcome. Activities invite you to learn about and practice educational stewardship, time management, financial management, avoiding thinking errors, and talent development.
                </p>
            </div>
            
            <!-- Course Outcomes -->
            <div style="margin-bottom: 25px; padding: 20px; background: #FAFAD2; border-radius: 8px;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #E6D690; padding-bottom: 10px;">
                    <i class="fas fa-graduation-cap"></i> Course Outcomes
                </h3>
                <p style="font-size: 12pt; line-height: 1.8; margin-bottom: 15px;">
                    Upon successful completion, students will be able to:
                </p>
                <ul style="margin: 10px 0 10px 20px; font-size: 11pt;">
                    <li>Demonstrate confidence in the ability to pursue an education.</li>
                    <li>Learn how to learn.</li>
                    <li>Demonstrate how to get things done.</li>
                    <li>Explain how to overcome a thinking error.</li>
                    <li>Apply basic financial management skills to a personal budget.</li>
                    <li>Explain how various personal characteristics can lead to perseverance.</li>
                    <li>Commit to applying two life skills from the course.</li>
                </ul>
            </div>
            
            <!-- Program Structure -->
            <div style="margin-bottom: 25px; padding: 20px; background: #F8F9fa; border: 1px solid #e0e0e0; border-radius: 8px;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-calendar-alt"></i> Program Structure
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 10px; border: 1px solid #ddd; width: 30%; font-weight: bold;">Duration</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">8 Weeks (16 hours total)</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Format</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Weekly modules with reflective journals, practical exercises, and pathway development</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Target Audience</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Students and individuals seeking personal development and life skills</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Certification</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Life Skills Pathway Certificate</td>
                    </tr>
                </table>
            </div>
            
            <!-- Weekly Breakdown Header -->
            <div style="text-align: center; margin: 30px 0; padding: 15px; background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%); color: white; border-radius: 8px;">
                <h3 style="margin: 0; font-size: 16pt;">
                    <i class="fas fa-list-ol"></i> Weekly Breakdown
                </h3>
            </div>
            
            <!-- Week 1 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #8B7500; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-road"></i> Week 1: Charting Your Pathway – Stewardship & Aspiration
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Define the concept of a personal "pathway" and life stewardship.</li>
                            <li>Identify core personal aspirations, values, and intrinsic motivations.</li>
                            <li>Begin drafting a personal "Pathway Statement."</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Introduction to the Pathway Framework: Stewardships, Aspirations, Constraints.</li>
                            <li>Self-Inventory: Identifying Roles, Responsibilities, and Core Values.</li>
                            <li>Articulating Personal & Educational Aspirations.</li>
                            <li>Introduction to the "Pathway Document" (living portfolio).</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a personal stewardship map and draft a first-version Pathway Statement outlining your core aspirations for education and life.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 2 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #DAA520; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-brain"></i> Week 2: Learning How to Learn – Educational Stewardship
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Identify personal learning styles and effective study techniques.</li>
                            <li>Develop strategies for active reading, note-taking, and information retention.</li>
                            <li>Build confidence as an independent, capable learner.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Metacognition: Understanding how you learn best.</li>
                            <li>Active Learning vs. Passive Consumption.</li>
                            <li>Effective Study Systems (e.g., Pomodoro, Spaced Repetition).</li>
                            <li>Resource Stewardship: Utilizing instructors, peers, and digital tools.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Analyze a past learning challenge and design a personalized "Learning Protocol" for your current studies using two new techniques.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 3 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #8B7500; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-clock"></i> Week 3: The Mechanics of Productivity – Time & Task Management
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Apply principles of prioritization to personal and academic tasks.</li>
                            <li>Construct and utilize a simple, effective productivity system.</li>
                            <li>Demonstrate how to break down goals into actionable steps.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Eisenhower Matrix: Urgent vs. Important.</li>
                            <li>Task Batching, Time Blocking, and Theming.</li>
                            <li>Overcoming Procrastination: The 5-Minute Rule and implementation intentions.</li>
                            <li>Tools: Digital calendars, to-do lists, and the "Get Things Done" (GTD) workflow.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Plan your upcoming week using time-blocking in a calendar, applying prioritization to a mix of academic, personal, and stewardship tasks.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 4 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #DAA520; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-cogs"></i> Week 4: The Mind's Architecture – Identifying & Overcoming Thinking Errors
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Recognize common cognitive distortions (thinking errors).</li>
                            <li>Analyze how thinking errors impact emotions, decisions, and progress.</li>
                            <li>Apply a reframing technique to overcome a personal thinking error.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Common Thinking Errors: All-or-Nothing, Catastrophizing, Overgeneralization, Personalization.</li>
                            <li>The Connection Between Thoughts, Feelings, and Behaviors.</li>
                            <li>Cognitive Reframing and Evidence-Based Challenge.</li>
                            <li>Developing a growth mindset in the face of challenges.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Identify a recent thinking error you experienced, document its impact, and write a rational reframe for it.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 5 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #8B7500; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-chart-pie"></i> Week 5: Financial Stewardship – Foundations of Personal Budgeting
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Explain the core components of a personal budget.</li>
                            <li>Differentiate between needs, wants, and savings/investment.</li>
                            <li>Apply basic financial management skills to create a simple personal budget.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Money In/Money Out: Tracking Income and Expenses.</li>
                            <li>The 50/30/20 Rule (Needs/Wants/Savings) as a framework.</li>
                            <li>The Importance of an Emergency Fund.</li>
                            <li>Avoiding Debt Traps and Understanding Interest.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a projected monthly budget for a hypothetical scenario (e.g., first internship, part-time job) using a provided template.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 6 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #DAA520; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-mountain"></i> Week 6: Cultivating Grit – Strengths, Talents, and Perseverance
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Identify personal strengths and talents through reflection and feedback.</li>
                            <li>Explain how characteristics like curiosity, conscientiousness, and resilience fuel perseverance.</li>
                            <li>Develop a plan for intentional talent development.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Strengths vs. Talents: Innate Abilities vs. Developed Skills.</li>
                            <li>The Anatomy of Perseverance: Passion and Sustained Effort.</li>
                            <li>The Role of Setbacks in Growth (Post-Traumatic Growth vs. Resilience).</li>
                            <li>Designing "Deliberate Practice" for a chosen skill.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Complete a strengths reflection survey and create a 90-day "Talent Development Plan" for one skill you want to strengthen.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 7 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #8B7500; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-puzzle-piece"></i> Week 7: Integration – Synthesizing Your Life Skills Toolkit
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Review and connect all course modules into an integrated personal framework.</li>
                            <li>Commit to applying two specific life skills over the next 90 days.</li>
                            <li>Present a refined and actionable Pathway Document.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>How Skills Interconnect: How financial stability supports learning, how mindset affects productivity, etc.</li>
                            <li>Anticipating and Planning for Constraints.</li>
                            <li>Building a Personal Support System and Accountability.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Finalize your Pathway Document, incorporating insights from all weeks. Prepare a 2-minute verbal commitment statement for the two life skills you will actively apply.</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 8 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #DAA520; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-award"></i> Week 8: Pathway Presentation & Commitment Ceremony
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Articulate key insights from the course and their personal pathway.</li>
                            <li>Demonstrate commitment to ongoing application of life skills.</li>
                            <li>Provide peer feedback and solidify a personal action plan.</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #B8860B;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Effective Reflection and Forward Planning.</li>
                            <li>The Cycle of Continuous Improvement.</li>
                            <li>Course Wrap-up and Resources for Ongoing Growth.</li>
                        </ul>
                    </div>
                    
                    <div style="background: #FFF8DC; padding: 15px; border-left: 4px solid #DAA520;">
                        <strong style="color: #8B7500;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Participate in a small-group "Pathway Presentation" session, sharing your commitment statement and one key insight from your Pathway Document. Engage in structured peer feedback.</p>
                    </div>
                </div>
            </div>
            
            <!-- Course Materials -->
            <div style="margin-bottom: 25px; padding: 20px; background: #FFF8DC; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #E6D690; padding-bottom: 10px;">
                    <i class="fas fa-book"></i> Course Materials Provided
                </h3>
                <ul style="margin: 15px 0 0 20px;">
                    <li>Weekly reflection journals and worksheets</li>
                    <li>The "Pathway Document" template (digital portfolio)</li>
                    <li>Access to recorded mini-lectures and expert interviews</li>
                    <li>Curated articles and videos on productivity, finance, and mindset</li>
                    <li>Budgeting and planning templates</li>
                    <li>Strengths assessment tools</li>
                    <li>Cognitive reframing worksheets</li>
                </ul>
            </div>
            
            <!-- Assessment -->
            <div style="margin-bottom: 25px; padding: 20px; background: #F8F9fa; border: 1px solid #e0e0e0; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                    <i class="fas fa-clipboard-check"></i> Assessment Breakdown
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Weekly Reflection Journals & Worksheets</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">30%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Practical Skill Application Assignments</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">40%</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Final Pathway Document & Commitment Presentation</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">30%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background-color: #FAFAD2;">Total</td>
                        <td style="padding: 12px; border: 1px solid #ddd; background-color: #FAFAD2; font-weight: bold;">100%</td>
                    </tr>
                </table>
            </div>
            
            <!-- Learning Philosophy -->
            <div style="margin-bottom: 25px; padding: 20px; background: #FAFAD2; border-left: 5px solid #DAA520; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #8B7500; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-lightbulb"></i> Learning Philosophy
                </h3>
                <p>This course is built on the belief that:</p>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Every individual has a unique pathway that can be intentionally constructed</li>
                    <li>Life skills are not innate but can be developed through practice and reflection</li>
                    <li>Stewardship - of time, resources, talents, and relationships - is foundational to success</li>
                    <li>Personal growth happens through both success and setbacks</li>
                    <li>Community and accountability accelerate personal development</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 20px; margin-top: 30px; font-size: 10pt;">
                <h4 style="color: #8B7500; margin-bottom: 10px; font-size: 12pt;">Program Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
                <p><strong>Program Duration:</strong> 8 Weeks (<?php echo date('F j, Y'); ?> - <?php echo date('F j, Y', strtotime('+8 weeks')); ?>)</p>
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
            <h1 style="color: #8B7500; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #DAA520; font-size: 18pt; margin-bottom: 30px;">
                Life Skills: Constructing Your Personal Pathway
            </h2>
            <h3 style="color: #333; font-size: 22pt; border-top: 3px solid #8B7500; 
                border-bottom: 3px solid #8B7500; padding: 25px 0; margin: 40px 0;">
                Complete Course Syllabus
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Email: ' . htmlspecialchars($this->user_email) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Program Start Date: ' . date('F j, Y') . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Access Level: ' . ucfirst($this->user_role) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Certification: Life Skills Pathway Certificate
                </p>
            </div>
            <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 10pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 9pt;">
                    This syllabus outlines the complete Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills: Constructing Your Personal Pathway | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | Life Skills Pathway Program | Student: ' . htmlspecialchars($this->user_email) . '
        </div>';
    }
    
    /**
     * Display the syllabus HTML page
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
        $startDate = date('F j, Y');
        $endDate = date('F j, Y', strtotime('+8 weeks'));
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Life Skills: Constructing Your Personal Pathway - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
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
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
            color: white;
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect fill="none" width="100" height="100"/><path fill="rgba(255,255,255,0.05)" d="M20,0 L80,0 L100,20 L100,80 L80,100 L20,100 L0,80 L0,20 Z"/></svg>') repeat;
            opacity: 0.3;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .header .subtitle {
            font-size: 1.6rem;
            opacity: 0.9;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header .cert-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 25px;
            border-radius: 30px;
            margin-top: 20px;
            font-weight: 600;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
            border: 2px solid rgba(255, 255, 255, 0.3);
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
            color: #8B7500;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #F0E68C;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #DAA520;
        }

        .week-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 25px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .week-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(139, 117, 0, 0.15);
        }

        .week-header {
            background: #8B7500;
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .week-header.alt {
            background: #DAA520;
        }

        .week-number {
            background: rgba(255, 255, 255, 0.2);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .week-title {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .week-content {
            padding: 25px;
        }

        .objectives-box {
            margin-bottom: 20px;
        }

        .objectives-title {
            color: #8B7500;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topics-box {
            margin-bottom: 20px;
        }

        .topics-title {
            color: #B8860B;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .activity-box {
            background: #FFF8DC;
            padding: 20px;
            border-left: 4px solid #DAA520;
            border-radius: 0 5px 5px 0;
        }

        .activity-title {
            color: #8B7500;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        ul, ol {
            padding-left: 25px;
            margin-bottom: 15px;
        }

        li {
            margin-bottom: 8px;
        }

        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .material-card {
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #F0E68C;
            transition: all 0.3s;
        }

        .material-card:hover {
            background: #FAFAD2;
            border-color: #DAA520;
            transform: translateY(-5px);
        }

        .material-icon {
            font-size: 3rem;
            color: #B8860B;
            margin-bottom: 15px;
        }

        .assessment-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .assessment-table th {
            background: #8B7500;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .assessment-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .assessment-table tr:nth-child(even) {
            background: #FFF8DC;
        }

        .assessment-table tr:last-child td {
            background: #FAFAD2;
            font-weight: bold;
        }

        .outcome-box {
            background: #FAFAD2;
            border-left: 5px solid #DAA520;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .outcome-title {
            color: #8B7500;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .philosophy-box {
            background: #FFF8DC;
            border-left: 5px solid #B8860B;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .philosophy-title {
            color: #8B7500;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .program-timeline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            margin: 30px 0;
            position: relative;
        }

        .timeline-item {
            text-align: center;
            flex: 1;
            position: relative;
        }

        .timeline-item:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 25px;
            right: -10%;
            width: 20%;
            height: 2px;
            background: #DAA520;
        }

        .timeline-icon {
            font-size: 2.5rem;
            color: #B8860B;
            margin-bottom: 10px;
        }

        .timeline-text {
            font-weight: 600;
            color: #8B7500;
        }

        .download-btn {
            display: inline-block;
            background: #B8860B;
            color: white;
            padding: 12px 25px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            margin: 20px 0;
            transition: background 0.3s;
            cursor: pointer;
            border: none;
            font-size: 1rem;
        }

        .download-btn:hover {
            background: #8B7500;
        }

        .download-section {
            text-align: center;
            margin: 40px 0;
            padding: 30px;
            background: #FFF8DC;
            border-radius: 8px;
        }

        .pdf-alert {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background: #FF9800;
            color: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        footer {
            text-align: center;
            padding: 30px;
            background-color: #FFF8DC;
            color: #8B7500;
            font-size: 0.9rem;
            border-top: 1px solid #F0E68C;
        }

        .pathway-icon {
            display: inline-block;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8B7500, #DAA520);
            border-radius: 50%;
            margin: 20px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
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

            .program-timeline {
                flex-direction: column;
                gap: 30px;
            }

            .timeline-item:not(:last-child)::after {
                display: none;
            }

            .materials-grid {
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

            .week-card {
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
                <strong>Access Granted:</strong> Life Skills Personal Pathway
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
    </div>

    <div class="container">
        <div class="header">
            <div class="pathway-icon">
                <i class="fas fa-road"></i>
            </div>
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">Life Skills: Constructing Your Personal Pathway</div>
            <div style="font-size: 1.8rem; margin: 20px 0; font-weight: 300;">Foundational Skills for Intentional Living</div>
            <div class="cert-badge">
                <i class="fas fa-certificate"></i> Life Skills Pathway Certificate
            </div>
        </div>

        <div class="content">
            <!-- Program Overview -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Program Goal
                </div>
                <p style="font-size: 1.2rem; line-height: 1.8; margin-bottom: 20px; color: #555;">
                    Equip learners with the foundational life skills and personal frameworks necessary to intentionally discern, construct, and pursue their unique pathway in life, fostering resilience, stewardship, and proactive growth.
                </p>

                <!-- Course Description -->
                <div style="background: #FFF8DC; padding: 25px; border-left: 4px solid #DAA520; border-radius: 8px; margin: 25px 0;">
                    <h3 style="color: #8B7500; margin-top: 0; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-book-open"></i> Course Description
                    </h3>
                    <p style="font-size: 1.1rem; line-height: 1.8;">
                        This course invites you to discern your pathway in life and strengthen your ability to pursue it. Your pathway is a road that you construct based on an understanding of your stewardships, your aspirations, strengths and talents. It is based on an understanding of the challenges and constraints that you need to endure, reframe, or overcome. Activities invite you to learn about and practice educational stewardship, time management, financial management, avoiding thinking errors, and talent development.
                    </p>
                </div>

                <!-- Program Timeline -->
                <div class="program-timeline">
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="timeline-text">
                            Start Date<br>
                            <span style="font-size: 1.2rem; font-weight: bold;"><?php echo $startDate; ?></span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="timeline-text">
                            Duration<br>
                            <span style="font-size: 1.2rem; font-weight: bold;">8 Weeks</span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="timeline-text">
                            End Date<br>
                            <span style="font-size: 1.2rem; font-weight: bold;"><?php echo $endDate; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Course Outcomes -->
                <div style="background: #FAFAD2; padding: 25px; border-radius: 8px; margin-top: 30px;">
                    <h3 style="color: #8B7500; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-graduation-cap"></i> Course Outcomes
                    </h3>
                    <p style="margin-bottom: 15px; font-size: 1.1rem;">Upon successful completion, students will be able to:</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 20px;">
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Educational Confidence</strong>
                            <p>Demonstrate confidence in the ability to pursue an education and learn how to learn effectively</p>
                        </div>
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Productivity Skills</strong>
                            <p>Demonstrate how to get things done through effective time and task management</p>
                        </div>
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Mindset Mastery</strong>
                            <p>Explain how to overcome a thinking error and cultivate a growth mindset</p>
                        </div>
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Financial Stewardship</strong>
                            <p>Apply basic financial management skills to create and maintain a personal budget</p>
                        </div>
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Perseverance Development</strong>
                            <p>Explain how various personal characteristics can lead to perseverance and grit</p>
                        </div>
                        <div style="padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #DAA520;">
                            <strong style="color: #8B7500; display: block; margin-bottom: 8px;">Skill Application</strong>
                            <p>Commit to applying two life skills from the course to personal growth and development</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Breakdown -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-list-ol"></i> Weekly Breakdown
                </div>

                <!-- Week 1 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">1</div>
                        <div class="week-title">Charting Your Pathway – Stewardship & Aspiration</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Define the concept of a personal "pathway" and life stewardship</li>
                                <li>Identify core personal aspirations, values, and intrinsic motivations</li>
                                <li>Begin drafting a personal "Pathway Statement"</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Introduction to the Pathway Framework: Stewardships, Aspirations, Constraints</li>
                                <li>Self-Inventory: Identifying Roles, Responsibilities, and Core Values</li>
                                <li>Articulating Personal & Educational Aspirations</li>
                                <li>Introduction to the "Pathway Document" (living portfolio)</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Create a personal stewardship map and draft a first-version Pathway Statement outlining your core aspirations for education and life.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 2 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">2</div>
                        <div class="week-title">Learning How to Learn – Educational Stewardship</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Identify personal learning styles and effective study techniques</li>
                                <li>Develop strategies for active reading, note-taking, and information retention</li>
                                <li>Build confidence as an independent, capable learner</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Metacognition: Understanding how you learn best</li>
                                <li>Active Learning vs. Passive Consumption</li>
                                <li>Effective Study Systems (e.g., Pomodoro, Spaced Repetition)</li>
                                <li>Resource Stewardship: Utilizing instructors, peers, and digital tools</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Analyze a past learning challenge and design a personalized "Learning Protocol" for your current studies using two new techniques.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 3 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">3</div>
                        <div class="week-title">The Mechanics of Productivity – Time & Task Management</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Apply principles of prioritization to personal and academic tasks</li>
                                <li>Construct and utilize a simple, effective productivity system</li>
                                <li>Demonstrate how to break down goals into actionable steps</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Eisenhower Matrix: Urgent vs. Important</li>
                                <li>Task Batching, Time Blocking, and Theming</li>
                                <li>Overcoming Procrastination: The 5-Minute Rule and implementation intentions</li>
                                <li>Tools: Digital calendars, to-do lists, and the "Get Things Done" (GTD) workflow</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Plan your upcoming week using time-blocking in a calendar, applying prioritization to a mix of academic, personal, and stewardship tasks.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 4 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">4</div>
                        <div class="week-title">The Mind's Architecture – Identifying & Overcoming Thinking Errors</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Recognize common cognitive distortions (thinking errors)</li>
                                <li>Analyze how thinking errors impact emotions, decisions, and progress</li>
                                <li>Apply a reframing technique to overcome a personal thinking error</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Common Thinking Errors: All-or-Nothing, Catastrophizing, Overgeneralization, Personalization</li>
                                <li>The Connection Between Thoughts, Feelings, and Behaviors</li>
                                <li>Cognitive Reframing and Evidence-Based Challenge</li>
                                <li>Developing a growth mindset in the face of challenges</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Identify a recent thinking error you experienced, document its impact, and write a rational reframe for it.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 5 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">5</div>
                        <div class="week-title">Financial Stewardship – Foundations of Personal Budgeting</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Explain the core components of a personal budget</li>
                                <li>Differentiate between needs, wants, and savings/investment</li>
                                <li>Apply basic financial management skills to create a simple personal budget</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Money In/Money Out: Tracking Income and Expenses</li>
                                <li>The 50/30/20 Rule (Needs/Wants/Savings) as a framework</li>
                                <li>The Importance of an Emergency Fund</li>
                                <li>Avoiding Debt Traps and Understanding Interest</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Create a projected monthly budget for a hypothetical scenario (e.g., first internship, part-time job) using a provided template.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 6 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">6</div>
                        <div class="week-title">Cultivating Grit – Strengths, Talents, and Perseverance</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Identify personal strengths and talents through reflection and feedback</li>
                                <li>Explain how characteristics like curiosity, conscientiousness, and resilience fuel perseverance</li>
                                <li>Develop a plan for intentional talent development</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Strengths vs. Talents: Innate Abilities vs. Developed Skills</li>
                                <li>The Anatomy of Perseverance: Passion and Sustained Effort</li>
                                <li>The Role of Setbacks in Growth (Post-Traumatic Growth vs. Resilience)</li>
                                <li>Designing "Deliberate Practice" for a chosen skill</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Complete a strengths reflection survey and create a 90-day "Talent Development Plan" for one skill you want to strengthen.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 7 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">7</div>
                        <div class="week-title">Integration – Synthesizing Your Life Skills Toolkit</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Review and connect all course modules into an integrated personal framework</li>
                                <li>Commit to applying two specific life skills over the next 90 days</li>
                                <li>Present a refined and actionable Pathway Document</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>How Skills Interconnect: How financial stability supports learning, how mindset affects productivity, etc.</li>
                                <li>Anticipating and Planning for Constraints</li>
                                <li>Building a Personal Support System and Accountability</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Finalize your Pathway Document, incorporating insights from all weeks. Prepare a 2-minute verbal commitment statement for the two life skills you will actively apply.</p>
                        </div>
                    </div>
                </div>

                <!-- Week 8 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">8</div>
                        <div class="week-title">Pathway Presentation & Commitment Ceremony</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Articulate key insights from the course and their personal pathway</li>
                                <li>Demonstrate commitment to ongoing application of life skills</li>
                                <li>Provide peer feedback and solidify a personal action plan</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Effective Reflection and Forward Planning</li>
                                <li>The Cycle of Continuous Improvement</li>
                                <li>Course Wrap-up and Resources for Ongoing Growth</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Participate in a small-group "Pathway Presentation" session, sharing your commitment statement and one key insight from your Pathway Document. Engage in structured peer feedback.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course Materials -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Course Materials Provided
                </div>
                
                <div class="materials-grid">
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-journal-whills"></i>
                        </div>
                        <h3>Reflection Journals</h3>
                        <p>Weekly reflection journals and worksheets for personal insight and growth tracking</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-road"></i>
                        </div>
                        <h3>Pathway Document</h3>
                        <p>The "Pathway Document" template - a living digital portfolio of your personal journey</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <h3>Expert Content</h3>
                        <p>Access to recorded mini-lectures and expert interviews on life skills development</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>Curated Resources</h3>
                        <p>Curated articles and videos on productivity, finance, mindset, and personal growth</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3>Planning Templates</h3>
                        <p>Budgeting, time management, and talent development planning templates</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <h3>Assessment Tools</h3>
                        <p>Strengths assessment tools and cognitive reframing worksheets</p>
                    </div>
                </div>
            </div>

            <!-- Assessment -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-clipboard-check"></i> Assessment & Grading
                </div>
                
                <p style="margin-bottom: 20px; font-size: 1.1rem;">
                    Progress is measured through reflective practice and practical application, emphasizing personal growth over traditional testing:
                </p>
                
                <table class="assessment-table">
                    <thead>
                        <tr>
                            <th>Assessment Type</th>
                            <th>Description</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Weekly Reflection Journals & Worksheets</strong></td>
                            <td>Documenting personal insights, growth, and application of concepts each week</td>
                            <td>30%</td>
                        </tr>
                        <tr>
                            <td><strong>Practical Skill Application Assignments</strong></td>
                            <td>Hands-on projects including budget creation, learning protocol, and talent development plan</td>
                            <td>40%</td>
                        </tr>
                        <tr>
                            <td><strong>Final Pathway Document & Commitment Presentation</strong></td>
                            <td>Comprehensive personal pathway portfolio and public commitment to skill application</td>
                            <td>30%</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Total</strong></td>
                            <td><strong>100%</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 25px; padding: 20px; background: #FFF8DC; border-radius: 8px;">
                    <h4 style="color: #8B7500; margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle"></i> Assessment Philosophy
                    </h4>
                    <p style="margin-bottom: 15px;">This course uses a growth-oriented assessment approach:</p>
                    <ul style="margin-left: 20px;">
                        <li><strong>Focus on Application:</strong> Success is measured by practical skill application, not just knowledge recall</li>
                        <li><strong>Personal Relevance:</strong> Assessments are tailored to individual goals and life contexts</li>
                        <li><strong>Growth Mindset:</strong> Emphasis on progress and development rather than perfection</li>
                        <li><strong>Self-Assessment:</strong> Students learn to evaluate their own growth and set future goals</li>
                    </ul>
                </div>
            </div>

            <!-- Learning Philosophy -->
            <div class="philosophy-box">
                <div class="philosophy-title">
                    <i class="fas fa-lightbulb"></i> Learning Philosophy
                </div>
                <p>This course is built on the belief that:</p>
                <ul>
                    <li><strong>Every individual has a unique pathway</strong> that can be intentionally constructed through self-awareness and deliberate action</li>
                    <li><strong>Life skills are not innate</strong> but can be systematically developed through practice, reflection, and mentorship</li>
                    <li><strong>Stewardship is foundational</strong> - of time, resources, talents, relationships, and opportunities</li>
                    <li><strong>Personal growth happens through both success and setbacks</strong>, with resilience being a learnable skill</li>
                    <li><strong>Community and accountability</strong> accelerate personal development and provide essential support</li>
                    <li><strong>Intentionality transforms potential into reality</strong> - purpose-driven action creates meaningful outcomes</li>
                </ul>
            </div>

            <!-- Learning Outcomes -->
            <div class="outcome-box">
                <div class="outcome-title">
                    <i class="fas fa-graduation-cap"></i> Detailed Learning Outcomes
                </div>
                <p>Upon successful completion of this program, students will be able to:</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Pathway Construction</strong>
                        <p style="margin-top: 8px;">Define personal stewardships and aspirations to construct a clear, actionable life pathway</p>
                    </div>
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Learning Mastery</strong>
                        <p style="margin-top: 8px;">Develop and implement personalized learning strategies for academic and personal growth</p>
                    </div>
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Productivity Systems</strong>
                        <p style="margin-top: 8px;">Design and maintain effective productivity systems for time and task management</p>
                    </div>
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Cognitive Resilience</strong>
                        <p style="margin-top: 8px;">Identify and reframe cognitive distortions to maintain healthy thought patterns</p>
                    </div>
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Financial Literacy</strong>
                        <p style="margin-top: 8px;">Create and manage personal budgets using fundamental financial principles</p>
                    </div>
                    <div style="padding: 15px; background: #FFF8DC; border-radius: 5px;">
                        <strong style="color: #8B7500;">Talent Development</strong>
                        <p style="margin-top: 8px;">Cultivate personal strengths and develop deliberate practice plans for skill mastery</p>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div class="download-section">
                <h3 style="color: #8B7500; margin-bottom: 20px;">
                    <i class="fas fa-download"></i> Download Syllabus
                </h3>
                <p style="margin-bottom: 25px; max-width: 600px; margin-left: auto; margin-right: auto;">
                    Download a printable version of this syllabus for your reference. The PDF includes all program details, weekly breakdown, assessment information, and learning outcomes.
                </p>
                
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <button onclick="printSyllabus()" class="download-btn">
                        <i class="fas fa-print"></i> Print Syllabus
                    </button>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </a>
                </div>
            </div>

            <!-- Help Section -->
            <div style="background: #FFF8DC; padding: 25px; border-radius: 8px; margin-top: 40px; border-left: 5px solid #B8860B;">
                <h3 style="color: #8B7500; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-question-circle"></i> Need Help or Have Questions?
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <h4 style="color: #8B7500; margin-bottom: 10px;">Instructor Support</h4>
                        <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                        <p><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</p>
                        <p><strong>Response Time:</strong> 24-48 hours for email inquiries</p>
                    </div>
                    <div>
                        <h4 style="color: #8B7500; margin-bottom: 10px;">Program Support</h4>
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #8B7500; text-decoration: none; font-weight: bold;">Access Portal</a></p>
                        <p><strong>Discussion Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/life-skills.php" style="color: #8B7500; text-decoration: none; font-weight: bold;">Life Skills Pathway Questions</a></p>
                        <p><strong>Technical Issues:</strong> support@impactdigitalacademy.com</p>
                        <p><strong>Program Questions:</strong> lifeskills@impactdigitalacademy.com</p>
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills: Constructing Your Personal Pathway - Complete Syllabus</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Life Skills Development Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #8B7500;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.8rem; color: #8B7500;">
                <i class="fas fa-exclamation-triangle"></i> This syllabus outlines the complete Life Skills Personal Pathway Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.9rem; color: #8B7500;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.9rem; color: #8B7500;">
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
            day: 'numeric'
        };
        document.getElementById('current-date').textContent = `Syllabus accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Print functionality
        function printSyllabus() {
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

        // Week card interaction
        document.addEventListener('DOMContentLoaded', function() {
            const weekCards = document.querySelectorAll('.week-card');
            weekCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (!e.target.closest('a')) {
                        const content = this.querySelector('.week-content');
                        if (content.style.maxHeight && content.style.maxHeight !== '0px') {
                            content.style.maxHeight = '0';
                            content.style.opacity = '0';
                        } else {
                            content.style.maxHeight = content.scrollHeight + 'px';
                            content.style.opacity = '1';
                        }
                    }
                });
            });
        });

        // Track syllabus access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('Life Skills syllabus access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Quick navigation to weeks
        function scrollToWeek(weekNumber) {
            const weekElement = document.querySelector(`[data-week="${weekNumber}"]`);
            if (weekElement) {
                weekElement.scrollIntoView({ behavior: 'smooth' });
            }
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

// Initialize and display the syllabus
try {
    $viewer = new LifeSkillsSyllabusViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>