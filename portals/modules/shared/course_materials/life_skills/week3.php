<?php
// modules/shared/course_materials/LifeSkills/week3_handout.php

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
 * Life Skills Week 3 Handout Class with PDF Download
 */
class LifeSkillsWeek3Handout
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
                'default_font_size' => 10,
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
            $mpdf->SetTitle('Life Skills Week 3: The Mechanics of Productivity');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Productivity, Time Management, Stewardship');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Productivity, Time Management, Stewardship, Goals, Week 3');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week3_Handout_' . date('Y-m-d') . '.pdf';
            
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
        $studentName = $this->first_name . ' ' . $this->last_name;
        $currentDate = date('F j, Y');
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 10pt;">
            <h1 style="color: #8B7500; border-bottom: 3px solid #DAA520; padding-bottom: 15px; font-size: 18pt; text-align: center;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #B8860B; font-size: 14pt; text-align: center; margin-top: 10px;">
                Life Skills Course - Week 3 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                The Mechanics of Productivity – Consecrated Time & Task Management
            </h3>
            
            <!-- Student Info -->
            <div style="margin-bottom: 20px; padding: 15px; background: #FFF8DC; border-radius: 5px; font-size: 9pt;">
                <table style="width: 100%;">
                    <tr>
                        <td><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></td>
                        <td><strong>Email:</strong> <?php echo htmlspecialchars($this->user_email); ?></td>
                        <td><strong>Date:</strong> <?php echo $currentDate; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></td>
                        <td><strong>Week:</strong> 3 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 3!
                </h4>
                <p>
                    Last week, you consecrated your learning process to become a true steward of your mind. This week, we consecrate your time—your most precious, non-renewable mortal resource. True stewardship extends beyond knowledge to the responsible and inspired management of the hours and days entrusted to you. As taught by prophets, we will one day give an account of how we used our time, talents, and blessings. This lesson moves beyond secular productivity "hacks" to frame time management as a sacred covenant—a daily practice of aligning your actions with your divine identity and potential. You will learn to diagnose your time use, apply spiritually-grounded prioritization frameworks, and build a personal productivity system that transforms your daily schedule from a list of tasks into a living testimony of your values. By the end of this session, you will not just manage time; you will sanctify it, turning your daily routine into a stepping stone toward eternal goals.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate the doctrine of time as a sacred stewardship and apply the three-step process (Evaluate, Align, Prioritize) to your current life.</li>
                    <li>Construct a personal goal hierarchy that connects daily tasks to your top-level, divine potential.</li>
                    <li>Implement practical productivity tools (Eisenhower Matrix, Time Blocking, GTD principles) within a framework of consecrated intent.</li>
                    <li>Create and execute a weekly plan that deliberately invests time in key eternal relationships (with God, family, others, and self).</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": Time as a Sacred Stewardship</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Time is not merely a commodity to be spent, but a divine gift to be invested. God has given you agency over your time, with the warning not to "idle away thy time," for you will one day provide a stewardship of it. As President Thomas S. Monson taught, "What are we doing with today?" If we perpetually live for a tomorrow that never comes, we accumulate empty yesterdays. Effective time management begins with this spiritual imperative: our daily use of time is the primary evidence of what we truly value. It is the practice of consecration in its most tangible form.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. The Foundational Process: Evaluate, Align, Prioritize</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        True stewardship of time requires more than a new app; it requires a change of heart and mind, guided by a three-step process:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>STEP 1: EVALUATE How You Spend Your Time</strong> - Honest assessment of current time use</li>
                        <li><strong>STEP 2: ALIGN Your Daily Activities with Your Values</strong> - Ensuring actions reflect desired identity</li>
                        <li><strong>STEP 3: PRIORITIZE the Things That Matter Most</strong> - Investing in eternal relationships</li>
                    </ul>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Strategic Framework: Goals as Consecrated Action</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Goals are the engines of stewardship, transforming righteous desires into action. They must be organized not just for efficiency, but for eternal progression through a Goal Hierarchy connecting daily tasks to divine potential.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">4. The Tactical Toolkit: Consecrated Productivity Systems</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Practical tools including the Eisenhower Matrix, Time Blocking, Task Batching, and the 5-Minute Rule to bring disciplined focus to your stewardship. The heart of the system is habit formation through consistent daily rituals.
                    </p>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: Craft Your Consecrated Weekly Plan</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Foundation - Evaluate & Align</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Evaluate (Start, Stop, Continue):</strong> In your Pathway Document, under a new "Week 3" section, spend 10 minutes on this reflection.</li>
                        <li><strong>Align (Relationship Check):</strong> Review Elder Uchtdorf's four relationships. For each, note one specific way you will invest time this week.</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Strategy - Build Your Goal Hierarchy & Weekly Plan</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>Draft a Goal Hierarchy:</strong> Choose ONE of your top-level goals and break it down into mid-level and lower-level tasks.</li>
                        <li><strong>Create a Consecrated Schedule:</strong> Time block your week, prioritize using the Eisenhower Matrix, and include buffer & rest time.</li>
                    </ol>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-left: 3px solid #DAA520; font-style: italic;">
                        <strong>Example Goal Hierarchy:</strong><br>
                        Top-Level: "Excel in my current studies to serve others"<br>
                        Mid-Level: 1. Master core concepts; 2. Actively participate in gatherings; 3. Apply learning to a real problem<br>
                        Lower-Level: "Complete Week 3 reading by Tuesday using Cornell Notes"
                    </div>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>The Stewardship Audit:</strong> At day's end, ask: "If I were to give a stewardship report to the Lord today on my use of time, what would I say? Did my actions reflect my stated values?"</li>
                    <li><strong>The Relationship ROI:</strong> Reflect on the time you invested in the four key relationships. What was the spiritual or emotional yield? How did it differ from time spent on tasks alone?</li>
                    <li><strong>Procrastination Diagnosis:</strong> When you felt resistance to a task, what was the underlying fear? Was it perfectionism, confusion, or lack of relevance? How can your goal hierarchy or the 5-minute rule help next time?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Time Stewardship</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The sacred responsibility to invest one's time in alignment with divine potential and eternal relationships</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Goal Hierarchy</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A structured system linking daily tasks to major themes and ultimate life purposes</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Eisenhower Matrix</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A prioritization tool that categorizes tasks by Urgency and Importance</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Time Blocking</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The practice of scheduling fixed blocks of time for specific activities</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Implementation Intention</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A pre-decided plan formatted as "If [Situation], then I will [Behavior]"</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>How does viewing time as a "stewardship" change your emotional response to a busy or "unproductive" day?</li>
                    <li>Looking at your drafted goal hierarchy, does your current weekly schedule reflect a proportional investment in your top-level goal? If not, what is one adjustment you can make?</li>
                    <li>Which productivity tool (Matrix, Time Blocking, 5-Minute Rule) feels most inspired for your current challenges? Why?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Consecrate Your Planning Session:</strong> Begin your weekly planning with a prayer for discernment to see what matters most.</li>
                    <li><strong>Protect Your Sabbath:</strong> Let it be the unwavering anchor that realigns your week with eternity. It is the ultimate priority filter.</li>
                    <li><strong>Review and Revise:</strong> Your weekly plan is a living document. Conduct a brief nightly review to adjust tomorrow's blocks based on today's realities.</li>
                    <li><strong>Grace in the Gap:</strong> When your plan and reality diverge, practice self-compassion. Stewardship is about diligent direction, not perfect execution. Repent, adjust, and move forward.</li>
                </ul>
            </div>
            
            <!-- Quote -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FFF8DC; border-radius: 5px;">
                "This is the day which the Lord hath made; we will rejoice and be glad in it." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Psalm 118:24</div>
                <div style="margin-top: 10px; font-style: italic;">
                    "Do not let the things which matter most be at the mercy of the things which matter least."
                </div>
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Johann Wolfgang von Goethe, as echoed by modern prophets</div>
            </div>
            
            <!-- Next Week Preview -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Next Week Preview
                </h4>
                <p>
                    Having established stewardship over your mind and time, Week 4 turns to the cornerstone of all stewardship: Financial Principles. We will explore how to manage resources providently, avoid debt, budget with purpose, and view money as a tool for building God's kingdom—completing the triad of personal consecration (Mind, Time, Means) to prepare you for outward impact.
                </p>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 20px; font-size: 9pt;">
                <h4 style="color: #8B7500; margin-bottom: 8px; font-size: 11pt;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> <?php echo BASE_URL; ?>modules/student/portal.php</p>
                <p><strong>Support Email:</strong> lifeskills@impactdigitalacademy.com</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></p>
                <p><strong>Week Completed:</strong> 3 of 8</p>
                <p><strong>Date Accessed:</strong> <?php echo $currentDate; ?></p>
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
        <div style="text-align: center; padding: 40px 0;">
            <h1 style="color: #8B7500; font-size: 22pt; margin-bottom: 15px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #DAA520; font-size: 16pt; margin-bottom: 20px;">
                Life Skills: The Mechanics of Productivity
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #8B7500; 
                border-bottom: 3px solid #8B7500; padding: 20px 0; margin: 30px 0;">
                Week 3 Handout<br>Consecrated Time & Task Management
            </h3>
            <div style="margin: 30px 0;">
                <p style="font-size: 12pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 12pt; color: #666;">
                    Email: ' . htmlspecialchars($this->user_email) . '
                </p>
                <p style="font-size: 12pt; color: #666;">
                    Instructor: ' . htmlspecialchars($this->instructor_name) . '
                </p>
                <p style="font-size: 12pt; color: #666;">
                    Week: 3 of 8
                </p>
                <p style="font-size: 12pt; color: #666;">
                    Date: ' . date('F j, Y') . '
                </p>
            </div>
            <div style="margin-top: 40px; padding-top: 15px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 9pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 8pt;">
                    This Week 3 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
        <div style="text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 5px; font-size: 8pt; color: #666;">
            Life Skills Week 3: The Mechanics of Productivity | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 3 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
        $currentDate = date('F j, Y');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 3: The Mechanics of Productivity – Impact Digital Academy</title>
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
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
            color: white;
            padding: 30px;
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
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><path fill="rgba(255,255,255,0.05)" d="M40,0 C62.1,0 80,17.9 80,40 C80,62.1 62.1,80 40,80 C17.9,80 0,62.1 0,40 C0,17.9 17.9,0 40,0 Z M40,10 C23.4,10 10,23.4 10,40 C10,56.6 23.4,70 40,70 C56.6,70 70,56.6 70,40 C70,23.4 56.6,10 40,10 Z"/></svg>') repeat;
            opacity: 0.2;
        }

        .academy-title {
            font-size: 1.8rem;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .program-title {
            font-size: 1.4rem;
            opacity: 0.9;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .week-title {
            font-size: 2rem;
            font-weight: 600;
            margin: 20px 0;
            position: relative;
            z-index: 1;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.3);
        }

        .week-subtitle {
            font-size: 1.3rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .productivity-icon {
            display: inline-block;
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 10px auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            position: relative;
            z-index: 1;
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
            margin-bottom: 0;
            padding-bottom: 0;
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

        .welcome-box {
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            border-left: 5px solid #DAA520;
            margin-bottom: 30px;
        }

        .welcome-title {
            color: #8B7500;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, #DAA520, transparent);
            margin: 30px 0;
        }

        .objectives-box {
            background: #FAFAD2;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .objectives-list {
            margin-left: 20px;
            margin-top: 15px;
        }

        .objectives-list li {
            margin-bottom: 12px;
            position: relative;
            padding-left: 25px;
        }

        .objectives-list li:before {
            content: "•";
            color: #8B7500;
            font-size: 1.5rem;
            position: absolute;
            left: 0;
            top: -5px;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .topic-card {
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            border-top: 4px solid #DAA520;
            transition: all 0.3s;
        }

        .topic-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(139, 117, 0, 0.1);
        }

        .topic-number {
            display: inline-block;
            background: #8B7500;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .topic-title {
            color: #8B7500;
            font-size: 1.2rem;
            margin-bottom: 15px;
        }

        .exercise-box {
            background: #FAFAD2;
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
            border-left: 5px solid #8B7500;
        }

        .exercise-title {
            color: #8B7500;
            font-size: 1.4rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .part-box {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #F0E68C;
        }

        .part-title {
            color: #8B7500;
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .step-list {
            margin-left: 20px;
            margin-top: 15px;
        }

        .step-list li {
            margin-bottom: 15px;
            position: relative;
            padding-left: 30px;
        }

        .step-list li:before {
            content: counter(step);
            counter-increment: step;
            position: absolute;
            left: 0;
            top: 0;
            background: #DAA520;
            color: white;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            text-align: center;
            line-height: 22px;
            font-size: 0.9rem;
            font-weight: bold;
        }

        ol.step-list {
            counter-reset: step;
        }

        .reflection-box {
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 5px solid #B8860B;
        }

        .reflection-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px dashed #E6D690;
        }

        .reflection-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .terms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .term-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #F0E68C;
            transition: all 0.3s;
        }

        .term-card:hover {
            border-color: #DAA520;
            box-shadow: 0 5px 10px rgba(139, 117, 0, 0.1);
        }

        .term-title {
            color: #8B7500;
            font-size: 1.1rem;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .questions-box {
            background: #FAFAD2;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
        }

        .question-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E6D690;
        }

        .question-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .question-number {
            display: inline-block;
            background: #8B7500;
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            text-align: center;
            line-height: 25px;
            margin-right: 10px;
            font-size: 0.9rem;
            font-weight: bold;
        }

        .tips-box {
            background: #FFF8DC;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
        }

        .tip-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
        }

        .tip-icon {
            color: #8B7500;
            font-size: 1.2rem;
            margin-top: 3px;
        }

        .preview-box {
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin: 40px 0 20px;
            text-align: center;
        }

        .preview-title {
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .instructor-box {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-top: 30px;
            border-top: 3px solid #8B7500;
        }

        .instructor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .quote-box {
            text-align: center;
            font-style: italic;
            padding: 25px;
            margin: 30px 0;
            color: #8B7500;
            font-size: 1.2rem;
            position: relative;
        }

        .quote-box:before {
            content: "❝";
            font-size: 3rem;
            color: #F0E68C;
            position: absolute;
            top: 0;
            left: 20px;
            opacity: 0.5;
        }

        .quote-box:after {
            content: "❞";
            font-size: 3rem;
            color: #F0E68C;
            position: absolute;
            bottom: -10px;
            right: 20px;
            opacity: 0.5;
        }

        footer {
            text-align: center;
            padding: 30px;
            background-color: #FFF8DC;
            color: #8B7500;
            font-size: 0.9rem;
            border-top: 1px solid #F0E68C;
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

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin: 30px 0;
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

        @media (max-width: 768px) {
            .content {
                padding: 20px;
            }
            
            .header {
                padding: 20px;
            }
            
            .week-title {
                font-size: 1.6rem;
            }
            
            .topics-grid {
                grid-template-columns: 1fr;
            }
            
            .terms-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .download-btn {
                width: 100%;
                max-width: 300px;
                text-align: center;
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
            
            .action-buttons {
                display: none;
            }
            
            .download-btn {
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
                <strong>Week 3 Handout:</strong> The Mechanics of Productivity
            </div>
            <div class="access-badge">
                <?php echo ucfirst($this->user_role); ?> Access
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-user-graduate"></i> Student: <?php echo htmlspecialchars($this->first_name); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor: <?php echo htmlspecialchars($this->first_name); ?>
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
            <div class="academy-title">Impact Digital Academy</div>
            <div class="program-title">General Studies Program: Life Skills Course</div>
            <div class="productivity-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="week-title">Week 3 Handout: The Mechanics of Productivity – Consecrated Time & Task Management</div>
            <div class="week-subtitle">Transforming Your Daily Schedule into Sacred Stewardship</div>
        </div>

        <div class="content">
            <!-- Student Info -->
            <div style="background: #FFF8DC; padding: 15px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid #DAA520;">
                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?>
                    </div>
                    <div>
                        <strong>Email:</strong> <?php echo htmlspecialchars($this->user_email); ?>
                    </div>
                    <div>
                        <strong>Date:</strong> <?php echo $currentDate; ?>
                    </div>
                    <div>
                        <strong>Week:</strong> 3 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 3!
                </div>
                <p>Last week, you consecrated your learning process to become a true steward of your mind. This week, we consecrate your time—your most precious, non-renewable mortal resource. True stewardship extends beyond knowledge to the responsible and inspired management of the hours and days entrusted to you. As taught by prophets, we will one day give an account of how we used our time, talents, and blessings. This lesson moves beyond secular productivity "hacks" to frame time management as a sacred covenant—a daily practice of aligning your actions with your divine identity and potential. You will learn to diagnose your time use, apply spiritually-grounded prioritization frameworks, and build a personal productivity system that transforms your daily schedule from a list of tasks into a living testimony of your values. By the end of this session, you will not just manage time; you will sanctify it, turning your daily routine into a stepping stone toward eternal goals.</p>
            </div>

            <div class="divider"></div>

            <!-- Learning Objectives -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Learning Objectives
                </div>
                <div class="objectives-box">
                    <p>By the end of this week, you will be able to:</p>
                    <ul class="objectives-list">
                        <li>Articulate the doctrine of time as a sacred stewardship and apply the three-step process (Evaluate, Align, Prioritize) to your current life.</li>
                        <li>Construct a personal goal hierarchy that connects daily tasks to your top-level, divine potential.</li>
                        <li>Implement practical productivity tools (Eisenhower Matrix, Time Blocking, GTD principles) within a framework of consecrated intent.</li>
                        <li>Create and execute a weekly plan that deliberately invests time in key eternal relationships (with God, family, others, and self).</li>
                    </ul>
                </div>
            </div>

            <!-- Key Topics -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Key Topics Covered
                </div>
                
                <div class="topics-grid">
                    <!-- Topic 1 -->
                    <div class="topic-card">
                        <div class="topic-number">1</div>
                        <div class="topic-title">The "Why": Time as a Sacred Stewardship</div>
                        <p>Time is not merely a commodity to be spent, but a divine gift to be invested. God has given you agency over your time, with the warning not to "idle away thy time," for you will one day provide a stewardship of it. As President Thomas S. Monson taught, "What are we doing with today?"</p>
                        <p style="margin-top: 10px;">If we perpetually live for a tomorrow that never comes, we accumulate empty yesterdays. Effective time management begins with this spiritual imperative: our daily use of time is the primary evidence of what we truly value. It is the practice of consecration in its most tangible form.</p>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">The Foundational Process: Evaluate, Align, Prioritize</div>
                        <p>True stewardship of time requires more than a new app; it requires a change of heart and mind, guided by a three-step process:</p>
                        <ul style="margin-top: 10px;">
                            <li><strong>STEP 1: EVALUATE How You Spend Your Time</strong><br>Honest assessment using the "Start, Stop, Continue" framework</li>
                            <li><strong>STEP 2: ALIGN Your Daily Activities with Your Values</strong><br>Ensuring actions reflect who you want to become</li>
                            <li><strong>STEP 3: PRIORITIZE the Things That Matter Most</strong><br>Investing in eternal relationships over tasks</li>
                        </ul>
                    </div>
                    
                    <!-- Topic 3 -->
                    <div class="topic-card">
                        <div class="topic-number">3</div>
                        <div class="topic-title">The Strategic Framework: Goals as Consecrated Action</div>
                        <p>Goals are the engines of stewardship, transforming righteous desires into action. They must be organized not just for efficiency, but for eternal progression through a Goal Hierarchy:</p>
                        <ul style="margin-top: 10px;">
                            <li><strong>Top-Level Goals:</strong> Your 2-3 paramount pursuits grounded in divine potential</li>
                            <li><strong>Mid-Level Goals:</strong> Major themes breaking down top-level goals</li>
                            <li><strong>Lower-Level Goals & Tasks:</strong> Specific, actionable daily steps</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                            <strong>SMART Goals:</strong> Specific, Measurable, Actionable, Relevant, Time-Bound
                        </div>
                    </div>
                    
                    <!-- Topic 4 -->
                    <div class="topic-card">
                        <div class="topic-number">4</div>
                        <div class="topic-title">The Tactical Toolkit: Consecrated Productivity Systems</div>
                        <p>Practical tools to bring disciplined focus to your stewardship:</p>
                        <ul style="margin-top: 10px;">
                            <li><strong>Eisenhower Matrix:</strong> Prioritize by Urgency vs. Importance</li>
                            <li><strong>Time Blocking & Task Batching:</strong> Schedule focused work blocks</li>
                            <li><strong>5-Minute Rule & Implementation Intentions:</strong> Overcome procrastination</li>
                            <li><strong>GTD Principles:</strong> Capture, clarify, organize, review</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                            <strong>Core Principle:</strong> Goals are achieved through consistent habits and daily rituals.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Practice Exercise -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-laptop-code"></i> Step-by-Step Practice Exercise
                </div>
                
                <div class="exercise-box">
                    <div class="exercise-title">
                        <i class="fas fa-tasks"></i> Activity: Craft Your Consecrated Weekly Plan
                    </div>
                    <p>Follow these steps to apply your Week 3 knowledge:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-compass"></i> Part 1: Foundation - Evaluate & Align
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Evaluate (Start, Stop, Continue):</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>In your Pathway Document, under a new "Week 3" section, spend 10 minutes on this reflection.</li>
                                    <li><strong>START:</strong> List 1-2 specific, meaningful actions you feel prompted to add to your week.</li>
                                    <li><strong>STOP:</strong> Identify 1-2 time-wasters or over-commitments that dilute your focus.</li>
                                    <li><strong>CONTINUE:</strong> Acknowledge 2-3 current practices you will protect.</li>
                                </ul>
                            </li>
                            <li><strong>Align (Relationship Check):</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Review Elder Uchtdorf's four relationships. For each, note one specific way you will invest time this week:</li>
                                    <li><strong>With God:</strong> (e.g., "Morning prayer and scripture study")</li>
                                    <li><strong>With Family:</strong> (e.g., "Family dinner without devices")</li>
                                    <li><strong>With Others:</strong> (e.g., "Reach out to a friend in need")</li>
                                    <li><strong>With Self:</strong> (e.g., "30 minutes for learning or exercise")</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-project-diagram"></i> Part 2: Strategy - Build Your Goal Hierarchy & Weekly Plan
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Draft a Goal Hierarchy:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Choose ONE of your top-level goals (e.g., "Excel in my current studies to serve others").</li>
                                    <li><strong>Mid-Level (2-3):</strong> Break it down (e.g., 1. Master core concepts; 2. Actively participate; 3. Apply learning).</li>
                                    <li><strong>Lower-Level (This Week's Tasks):</strong> For one mid-level goal, list 3-5 specific, SMART tasks.</li>
                                </ul>
                            </li>
                            <li><strong>Create a Consecrated Schedule:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>Time Block Your Week:</strong> Use a digital or paper calendar. Block out non-negotiables first.</li>
                                    <li>Assign dedicated blocks for key tasks from your hierarchy.</li>
                                    <li>Apply the Eisenhower Matrix to remaining tasks. Schedule Important/Not Urgent items.</li>
                                    <li><strong>Include Buffer & Rest:</strong> Schedule open time for the unexpected and genuine rest.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Reflection Prompts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-brain"></i> Reflection Prompts (Ponder & Prove)
                </div>
                
                <div class="reflection-box">
                    <div class="reflection-item">
                        <strong>The Stewardship Audit:</strong> At day's end, ask: "If I were to give a stewardship report to the Lord today on my use of time, what would I say? Did my actions reflect my stated values?"
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Relationship ROI:</strong> Reflect on the time you invested in the four key relationships. What was the spiritual or emotional yield? How did it differ from time spent on tasks alone?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Procrastination Diagnosis:</strong> When you felt resistance to a task, what was the underlying fear? Was it perfectionism, confusion, or lack of relevance? How can your goal hierarchy or the 5-minute rule help next time?
                    </div>
                </div>
            </div>

            <!-- Key Terms -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-key"></i> Key Terms to Remember
                </div>
                
                <div class="terms-grid">
                    <div class="term-card">
                        <div class="term-title">Time Stewardship</div>
                        <p>The sacred responsibility to invest one's time in alignment with divine potential and eternal relationships, accountable to God.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Goal Hierarchy</div>
                        <p>A structured system linking specific daily tasks to major themes and ultimate life purposes.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Eisenhower Matrix</div>
                        <p>A prioritization tool that categorizes tasks by Urgency and Importance to focus on value-driven activities.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Time Blocking</div>
                        <p>The practice of scheduling fixed blocks of time on a calendar for specific activities or task types.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Implementation Intention</div>
                        <p>A pre-decided plan formulated as "If [Situation], then I will [Behavior]" to automate positive action.</p>
                    </div>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-question-circle"></i> Self-Review Questions
                </div>
                
                <div class="questions-box">
                    <div class="question-item">
                        <span class="question-number">1</span>
                        How does viewing time as a "stewardship" change your emotional response to a busy or "unproductive" day?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        Looking at your drafted goal hierarchy, does your current weekly schedule reflect a proportional investment in your top-level goal? If not, what is one adjustment you can make?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        Which productivity tool (Matrix, Time Blocking, 5-Minute Rule) feels most inspired for your current challenges? Why?
                    </div>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-lightbulb"></i> Tips for Success
                </div>
                
                <div class="tips-box">
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-pray"></i>
                        </div>
                        <div>
                            <strong>Consecrate Your Planning Session:</strong> Begin your weekly planning with a prayer for discernment to see what matters most.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-church"></i>
                        </div>
                        <div>
                            <strong>Protect Your Sabbath:</strong> Let it be the unwavering anchor that realigns your week with eternity. It is the ultimate priority filter.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                        <div>
                            <strong>Review and Revise:</strong> Your weekly plan is a living document. Conduct a brief nightly review to adjust tomorrow's blocks based on today's realities.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <strong>Grace in the Gap:</strong> When your plan and reality diverge, practice self-compassion. Stewardship is about diligent direction, not perfect execution. Repent, adjust, and move forward.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quote -->
            <div class="quote-box">
                "This is the day which the Lord hath made; we will rejoice and be glad in it." 
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Psalm 118:24</div>
                <div style="margin-top: 15px; font-style: italic;">
                    "Do not let the things which matter most be at the mercy of the things which matter least."
                </div>
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Johann Wolfgang von Goethe, as echoed by modern prophets</div>
            </div>

            <!-- Next Week Preview -->
            <div class="preview-box">
                <div class="preview-title">
                    <i class="fas fa-arrow-right"></i> Next Week Preview
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    Having established stewardship over your mind and time, Week 4 turns to the cornerstone of all stewardship: Financial Principles. We will explore how to manage resources providently, avoid debt, budget with purpose, and view money as a tool for building God's kingdom—completing the triad of personal consecration (Mind, Time, Means) to prepare you for outward impact.
                </p>
            </div>

            <!-- Instructor Info -->
            <div class="instructor-box">
                <div class="section-title" style="border-bottom: none; margin-bottom: 10px;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Information
                </div>
                
                <div class="instructor-grid">
                    <div>
                        <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                        <p><strong>Virtual Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</p>
                    </div>
                    <div>
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #8B7500; text-decoration: none; font-weight: bold;">Access Portal</a></p>
                        <p><strong>Response Time:</strong> 24-48 hours for email inquiries</p>
                        <p><strong>Support Email:</strong> lifeskills@impactdigitalacademy.com</p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button onclick="window.print()" class="download-btn">
                    <i class="fas fa-print"></i> Print Handout
                </button>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <?php if ($this->class_id): ?>
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week4_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 4
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 3 Handout: The Mechanics of Productivity</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Life Skills Development Program</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #8B7500;">Syllabus accessed on: <?php echo $currentDate; ?></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.8rem; color: #8B7500;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the Life Skills: Constructing Your Personal Pathway course. Unauthorized distribution is prohibited.
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

        // Interactive elements
        document.addEventListener('DOMContentLoaded', function() {
            // Add click animation to topic cards
            const topicCards = document.querySelectorAll('.topic-card');
            topicCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'translateY(-5px)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(0)';
                    }, 300);
                });
            });

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('Week 3 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                    // In production, send AJAX request to log access
                }
            });
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
    $handout = new LifeSkillsWeek3Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>