<?php
// modules/shared/course_materials/LifeSkills/week6_handout.php

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
 * Life Skills Week 6 Handout Class with PDF Download
 */
class LifeSkillsWeek6Handout
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
            $mpdf->SetTitle('Life Skills Week 6: Cultivating Grit');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Grit, Perseverance, Strengths, Talents');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Grit, Perseverance, Strengths, Talents, Development, Week 6');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week6_Handout_' . date('Y-m-d') . '.pdf';
            
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
                Life Skills Course - Week 6 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                Cultivating Grit – Strengths, Talents, and Perseverance
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
                        <td><strong>Week:</strong> 6 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 6!
                </h4>
                <p>
                    Last week, you consecrated your finances, transforming money from a source of anxiety into a tool for faithful stewardship. This week, we turn our focus inward to the engine that drives all consecrated action: you. True discipleship and impact require not just good intentions, but sustained effort—the spiritual and emotional stamina to press forward when the path is steep and the outcome is unclear. This is grit. Grit is perseverance fueled by passion; it is the marriage of a "why" that matters with the diligence to see it through. Poor perseverance often stems from thinking errors like Powerlessness ("I can't do this") or Short-Termism ("This is too hard, so I'll quit"). Conversely, gritty perseverance is the fruit of a consecrated mind that understands setbacks are not stop signs but divinely-tutorial detours. This lesson moves beyond simple encouragement to "not give up." You will learn to identify your unique strengths, understand the anatomy of perseverance, and design a practical plan for developing resilience. By the end of this session, you will not just hope for more stamina; you will have a covenant plan to build it, turning your innate talents into refined tools for lifelong impact.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Identify personal strengths and core talents through structured reflection and feedback, distinguishing between innate capacities and developed skills.</li>
                    <li>Explain how key characteristics like curiosity, conscientiousness, and resilience fuel perseverance and contribute to post-traumatic growth.</li>
                    <li>Develop a practical, 90-day "Talent Development Plan" for one chosen skill, incorporating principles of deliberate practice and faithful persistence.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": Grit as Sacred Stewardship of Self</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Your mind, heart, and capacities are your first and most sacred stewardship. Perseverance (or diligence) is the covenant-keeping action of regularly doing what you know is right, even when it's difficult. Grit is that perseverance, made intentional and strategic. A person with grit doesn't just blindly push ahead; they have a purpose, set achievable goals, reflect on progress, and use lessons to fuel further growth. As with financial stewardship, the world's "common approach" is to quit when a task becomes inconvenient, painful, or boring—prioritizing immediate comfort over eternal development. The Lord's "self-reliant approach" invites us to see effort itself as a form of worship and struggle as a necessary tutor (see Romans 5:3-4). Cultivating grit is how we heed the call to "endure to the end," not as passive victims of circumstance, but as active, faithful builders of our own character and God's kingdom.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. The Foundation: Know Your Strengths & Talents</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>Strengths vs. Talents:</strong> Strengths are innate, God-given capacities—your natural ways of thinking, feeling, and behaving (e.g., empathy, analytical thinking, loyalty). Talents are skills developed over time through the application of effort to strengths (e.g., counseling, data analysis, faithful service). Your journey begins by recognizing the raw materials (strengths) you have been given to develop (into talents), as illustrated in the Parable of the Talents (Matthew 25:14-30).
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>The Anatomy of Perseverance:</strong> Grit is powered by two core components:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Passion (Your "Why"):</strong> A sustained, enduring interest in a purpose larger than yourself. This is your guiding star.</li>
                        <li><strong>Perseverance (Your "How"):</strong> The sustained application of effort toward that purpose, especially in the face of obstacles, boredom, or disappointment.</li>
                    </ul>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>The Role of Setbacks:</strong> Failure is not evidence of a lack of grit; it is the required curriculum for developing it. Distinguish between:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Resilience:</strong> The ability to "bounce back" to your previous state after a hardship.</li>
                        <li><strong>Post-Traumatic Growth:</strong> The potential to not just recover, but to actually become stronger, wiser, and more compassionate because of the struggle—to be "wounded healers."</li>
                    </ul>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Consecrated Path: Principles for Perseverance</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Grit is built by applying gospel principles to daily effort. These are not just ideas; they are spiritual technologies for endurance.
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Principle 1: Have a Purpose.</strong> Your "why" is your fuel. A righteous purpose transforms education from a task into a calling.</li>
                        <li><strong>Principle 2: Step into the Unknown.</strong> Faith is always forward-looking, requiring steps into darkness (see Hebrews 11:8-10).</li>
                        <li><strong>Principle 3: Deal with Disappointment.</strong> Disappointment stems from unmet expectations. The consecrated mind reframes it through gratitude and understanding the law of increasing rewards.</li>
                        <li><strong>Principle 4: Work with Limited Resources.</strong> The most critical resource is YOU—your agency, your effort, your spirit. This is self-reliance.</li>
                    </ul>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">4. Grit in Action: Deliberate Practice & The Development Plan</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        A wish without a plan is a thinking error. To intentionally develop grit and a talent:
                    </p>
                    <ul style="margin-left: 30px;">
                        <li><strong>Embrace Deliberate Practice:</strong> This is not just "doing the activity." It is focused, structured effort aimed at improving a specific aspect of performance.</li>
                        <li><strong>Hold Yourself Accountable:</strong> A plan without review is abandoned. Set regular check-ins, celebrate micro-wins, and adjust your approach.</li>
                        <li><strong>Counsel with the Lord:</strong> Take your strengths inventory, your development plan, and your fears of failure to God in prayer.</li>
                    </ul>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: Create Your 90-Day Talent Development Plan</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Foundation – Strengths & "Why" Assessment</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Strengths Reflection:</strong> In your Pathway Document, under a new "Week 6" section, list 3-5 core strengths (innate capacities). Then, list 2-3 talents (developed skills) you are proud of. For one talent, write how it grew from a core strength.</li>
                        <li><strong>Purpose Statement:</strong> Write your current "Why" for your education and personal development.</li>
                        <li><strong>Setback Reflection:</strong> Briefly describe a past disappointment or failure. Write one sentence on how you showed resilience then. Now, write one way that experience could lead to post-traumatic growth for you now.</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Consecrated Planning – Designing Deliberate Practice</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Skill Selection:</strong> Choose ONE specific skill you want to develop over the next 90 days.</li>
                        <li><strong>Plan Components:</strong>
                            <ul style="margin-left: 20px; margin-top: 8px;">
                                <li><strong>End Goal:</strong> What will you be able to do in 90 days that you can't do as well now?</li>
                                <li><strong>Deliberate Practice Routine:</strong> What will you do, specifically, for 30-60 minutes, 3-4 times a week?</li>
                                <li><strong>Feedback Mechanism:</strong> How will you get feedback?</li>
                                <li><strong>Resource Identification:</strong> What/who will you need?</li>
                            </ul>
                        </li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 3: The Covenant – Integration for Perseverance</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>Anticipate the "Dip":</strong> What is the most likely obstacle or point of disappointment you will face in your 90-day plan? Write a pre-emptive reframe for it using Principle 3.</li>
                        <li><strong>Prayer of Dedication:</strong> Write a short prayer offering your strengths, your chosen skill, and your development plan to the Lord. Ask for the gift of grit—for passion to sustain you and perseverance to carry you through the difficult phases of practice.</li>
                    </ol>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Purpose & Power:</strong> How does connecting your daily efforts (like studying) to a deep, righteous purpose change your emotional experience of the work? How can this protect you from the thinking error of "Justification" when things get hard?</li>
                    <li><strong>The Gift of Struggle:</strong> Why is "stepping into the unknown," like Abraham, a necessary principle for spiritual growth, not just academic growth? How can faith and grit work together in such moments?</li>
                    <li><strong>Divine Partnership:</strong> In what practical ways can you "draw nigh to God" (James 4:8) in your efforts to develop a talent or persevere in a difficult course, as Peter learned to do?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Grit</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Passion and sustained perseverance applied toward long-term goals, especially in the face of adversity.</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Strength</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">An innate, God-given capacity for a certain pattern of thought, feeling, or behavior.</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Talent</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A skill or ability developed over time through the investment of effort and practice into a strength.</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Deliberate Practice</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Focused, structured repetition of a skill with the specific intent of improving performance, often involving feedback.</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Post-Traumatic Growth</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Positive psychological change experienced as a result of the struggle with highly challenging life circumstances.</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>How does the Parable of the Talents (Matthew 25:14-30) teach us about the relationship between our God-given strengths (the talents given) and our personal responsibility to develop them (trading/growing them)? Which of your "talents" are you most stewarding well right now?</li>
                    <li>Of the four principles of perseverance (Purpose, Unknown, Disappointment, Resources), which one feels most challenging for you right now? What is one small action you can take this week to practice it?</li>
                    <li>How can the "Stop, Think, Act, Reflect" model from Week 4 be used in the middle of a deliberate practice session when you feel stuck or frustrated?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Start Small, Think 90 Days:</strong> Your development plan should be challenging but achievable. A 90-day horizon is long enough for progress, short enough to stay focused.</li>
                    <li><strong>Schedule Your Practice:</strong> Treat your deliberate practice time like a sacred appointment. Put it in your calendar.</li>
                    <li><strong>Focus on Process, Not Just Outcome:</strong> Celebrate showing up and doing the practice itself. The skill improvement is the inevitable result of consistent process.</li>
                    <li><strong>Practice Grace & Persistence:</strong> Some days your practice will be poor. Some weeks you'll miss a session. This is part of the journey. Repent (adjust your plan), reframe, and recommit.</li>
                </ul>
            </div>
            
            <!-- Quote -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FFF8DC; border-radius: 5px;">
                "But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Isaiah 40:31</div>
            </div>
            
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FAFAD2; border-radius: 5px;">
                "And let us not be weary in well doing: for in due season we shall reap, if we faint not." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Galatians 6:9</div>
            </div>
            
            <!-- Next Week Preview -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Next Week Preview
                </h4>
                <p>
                    Having fortified your inner capacity for grit, you are ready to project your consecrated efforts outward. Week 7 begins our focus on Digital Communication. We will explore how to use technology—social media, email, and digital presentations—as a tool for authentic connection, professional outreach, and sharing light, ensuring your online presence powerfully reflects your consecrated identity and purpose.
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
                <p><strong>Week Completed:</strong> 6 of 8</p>
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
                Life Skills: Constructing Your Personal Pathway
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #8B7500; 
                border-bottom: 3px solid #8B7500; padding: 20px 0; margin: 30px 0;">
                Week 6 Handout<br>Cultivating Grit – Strengths, Talents, and Perseverance
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
                    Week: 6 of 8
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
                    This Week 6 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 6: Cultivating Grit | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 6 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 6: Cultivating Grit – Impact Digital Academy</title>
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

        .grit-icon {
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

        .scripture-box {
            text-align: center;
            font-style: italic;
            padding: 25px;
            margin: 30px 0;
            color: #8B7500;
            font-size: 1.1rem;
            background: #FAFAD2;
            border-radius: 8px;
            border-left: 5px solid #8B7500;
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
                <strong>Week 6 Handout:</strong> Cultivating Grit
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
            <div class="grit-icon">
                <i class="fas fa-mountain"></i>
            </div>
            <div class="week-title">Week 6 Handout: Cultivating Grit – Strengths, Talents, and Perseverance</div>
            <div class="week-subtitle">Building Spiritual and Emotional Stamina for Lifelong Impact</div>
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
                        <strong>Week:</strong> 6 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 6!
                </div>
                <p>Last week, you consecrated your finances, transforming money from a source of anxiety into a tool for faithful stewardship. This week, we turn our focus inward to the engine that drives all consecrated action: you. True discipleship and impact require not just good intentions, but sustained effort—the spiritual and emotional stamina to press forward when the path is steep and the outcome is unclear. This is grit. Grit is perseverance fueled by passion; it is the marriage of a "why" that matters with the diligence to see it through. Poor perseverance often stems from thinking errors like Powerlessness ("I can't do this") or Short-Termism ("This is too hard, so I'll quit"). Conversely, gritty perseverance is the fruit of a consecrated mind that understands setbacks are not stop signs but divinely-tutorial detours. This lesson moves beyond simple encouragement to "not give up." You will learn to identify your unique strengths, understand the anatomy of perseverance, and design a practical plan for developing resilience. By the end of this session, you will not just hope for more stamina; you will have a covenant plan to build it, turning your innate talents into refined tools for lifelong impact.</p>
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
                        <li>Identify personal strengths and core talents through structured reflection and feedback, distinguishing between innate capacities and developed skills.</li>
                        <li>Explain how key characteristics like curiosity, conscientiousness, and resilience fuel perseverance and contribute to post-traumatic growth.</li>
                        <li>Develop a practical, 90-day "Talent Development Plan" for one chosen skill, incorporating principles of deliberate practice and faithful persistence.</li>
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
                        <div class="topic-title">The "Why": Grit as Sacred Stewardship of Self</div>
                        <p>Your mind, heart, and capacities are your first and most sacred stewardship. Perseverance (or diligence) is the covenant-keeping action of regularly doing what you know is right, even when it's difficult. Grit is that perseverance, made intentional and strategic.</p>
                        <p style="margin-top: 10px;">A person with grit doesn't just blindly push ahead; they have a purpose, set achievable goals, reflect on progress, and use lessons to fuel further growth.</p>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px; font-size: 0.9rem;">
                            <strong>Scriptural Insight:</strong> "And not only so, but we glory in tribulations also: knowing that tribulation worketh patience; And patience, experience; and experience, hope" (Romans 5:3-4).
                        </div>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">The Foundation: Know Your Strengths & Talents</div>
                        <p><strong>Strengths vs. Talents:</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Strengths:</strong> Innate, God-given capacities (e.g., empathy, analytical thinking)</li>
                            <li><strong>Talents:</strong> Skills developed through effort applied to strengths (e.g., counseling, data analysis)</li>
                        </ul>
                        <p><strong>The Anatomy of Perseverance:</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Passion:</strong> Your "Why" – guiding star</li>
                            <li><strong>Perseverance:</strong> Your "How" – sustained effort</li>
                        </ul>
                        <p><strong>The Role of Setbacks:</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Resilience:</strong> Bouncing back</li>
                            <li><strong>Post-Traumatic Growth:</strong> Becoming stronger through struggle</li>
                        </ul>
                    </div>
                    
                    <!-- Topic 3 -->
                    <div class="topic-card">
                        <div class="topic-number">3</div>
                        <div class="topic-title">The Consecrated Path: Principles for Perseverance</div>
                        <p>Grit is built by applying gospel principles to daily effort:</p>
                        <ul style="margin: 15px 0 10px 20px;">
                            <li><strong>Principle 1:</strong> Have a Purpose – Your "why" is your fuel</li>
                            <li><strong>Principle 2:</strong> Step into the Unknown – Faith requires forward movement</li>
                            <li><strong>Principle 3:</strong> Deal with Disappointment – Reframe through gratitude</li>
                            <li><strong>Principle 4:</strong> Work with Limited Resources – Start with your agency</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px; font-size: 0.9rem;">
                            <strong>Example:</strong> Like Abraham who "went out, not knowing whither he went" (Hebrews 11:8), stepping into the unknown is where faith and grit are forged.
                        </div>
                    </div>
                    
                    <!-- Topic 4 -->
                    <div class="topic-card">
                        <div class="topic-number">4</div>
                        <div class="topic-title">Grit in Action: Deliberate Practice & The Development Plan</div>
                        <p>A wish without a plan is a thinking error. To intentionally develop grit:</p>
                        <ul style="margin: 15px 0 10px 20px;">
                            <li><strong>Embrace Deliberate Practice:</strong> Focused, structured effort with feedback</li>
                            <li><strong>Hold Yourself Accountable:</strong> Regular check-ins and adjustments</li>
                            <li><strong>Counsel with the Lord:</strong> Take your plan and fears to God in prayer</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                            <strong>Key Concept:</strong> Deliberate practice is not just "doing the activity" – it's targeted improvement at the edge of your abilities.
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
                        <i class="fas fa-tasks"></i> Activity: Create Your 90-Day Talent Development Plan
                    </div>
                    <p>Follow these steps to apply your Week 6 knowledge and build your grit:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-foundation"></i> Part 1: Foundation – Strengths & "Why" Assessment
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Strengths Reflection:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>In your Pathway Document, create a new "Week 6" section.</li>
                                    <li>List 3-5 core strengths (innate capacities).</li>
                                    <li>List 2-3 talents (developed skills) you are proud of.</li>
                                    <li>For one talent, write how it grew from a core strength.</li>
                                </ul>
                            </li>
                            <li><strong>Purpose Statement:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Write your current "Why" for your education and personal development.</li>
                                    <li>Example: "<em>To become a more capable provider and spiritual leader so I can bless my family and serve in my community.</em>"</li>
                                </ul>
                            </li>
                            <li><strong>Setback Reflection:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Briefly describe a past disappointment or failure.</li>
                                    <li>Write one sentence on how you showed resilience then.</li>
                                    <li>Write one way that experience could lead to post-traumatic growth for you now.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-edit"></i> Part 2: Consecrated Planning – Designing Deliberate Practice
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Skill Selection:</strong> Choose ONE specific skill you want to develop over the next 90 days (e.g., academic writing, public speaking, coding, consistent scripture study).</li>
                            <li><strong>Plan Components:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>End Goal:</strong> What will you be able to do in 90 days that you can't do as well now?</li>
                                    <li><strong>Deliberate Practice Routine:</strong> What will you do, specifically, for 30-60 minutes, 3-4 times a week?</li>
                                    <li><strong>Feedback Mechanism:</strong> How will you get feedback? (Writing Center, study group, speech app, prayer)</li>
                                    <li><strong>Resource Identification:</strong> What/who will you need? (Instructor office hours, specific apps, study materials)</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 3 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-hands-praying"></i> Part 3: The Covenant – Integration for Perseverance
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Anticipate the "Dip":</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>What is the most likely obstacle or point of disappointment you will face in your 90-day plan?</li>
                                    <li>Write a pre-emptive reframe for it using Principle 3 (Deal with Disappointment).</li>
                                    <li>Example: "<em>If I feel bored, I will remember my 'why' and treat practice like a spiritual discipline, giving thanks for the chance to grow.</em>"</li>
                                </ul>
                            </li>
                            <li><strong>Prayer of Dedication:</strong>
                                <div style="margin-top: 10px; padding: 15px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0; font-style: italic;">
                                    Write a short prayer offering your strengths, your chosen skill, and your development plan to the Lord. Ask for the gift of grit—for passion to sustain you and perseverance to carry you through the difficult phases of practice.
                                </div>
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
                        <strong>Purpose & Power:</strong> How does connecting your daily efforts (like studying) to a deep, righteous purpose change your emotional experience of the work? How can this protect you from the thinking error of "Justification" when things get hard?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Gift of Struggle:</strong> Why is "stepping into the unknown," like Abraham, a necessary principle for spiritual growth, not just academic growth? How can faith and grit work together in such moments?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Divine Partnership:</strong> In what practical ways can you "draw nigh to God" (James 4:8) in your efforts to develop a talent or persevere in a difficult course, as Peter learned to do?
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
                        <div class="term-title">Grit</div>
                        <p>Passion and sustained perseverance applied toward long-term goals, especially in the face of adversity.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Strength</div>
                        <p>An innate, God-given capacity for a certain pattern of thought, feeling, or behavior.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Talent</div>
                        <p>A skill or ability developed over time through the investment of effort and practice into a strength.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Deliberate Practice</div>
                        <p>Focused, structured repetition of a skill with the specific intent of improving performance, often involving feedback.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Post-Traumatic Growth</div>
                        <p>Positive psychological change experienced as a result of the struggle with highly challenging life circumstances.</p>
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
                        How does the Parable of the Talents (Matthew 25:14-30) teach us about the relationship between our God-given strengths (the talents given) and our personal responsibility to develop them (trading/growing them)? Which of your "talents" are you most stewarding well right now?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        Of the four principles of perseverance (Purpose, Unknown, Disappointment, Resources), which one feels most challenging for you right now? What is one small action you can take this week to practice it?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        How can the "Stop, Think, Act, Reflect" model from Week 4 be used in the middle of a deliberate practice session when you feel stuck or frustrated?
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
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div>
                            <strong>Start Small, Think 90 Days:</strong> Your development plan should be challenging but achievable. A 90-day horizon is long enough for progress, short enough to stay focused.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <strong>Schedule Your Practice:</strong> Treat your deliberate practice time like a sacred appointment. Put it in your calendar and protect it.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div>
                            <strong>Focus on Process, Not Just Outcome:</strong> Celebrate showing up and doing the practice itself. The skill improvement is the inevitable result of consistent process.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <strong>Practice Grace & Persistence:</strong> Some days your practice will be poor. Some weeks you'll miss a session. This is part of the journey. Repent (adjust your plan), reframe, and recommit. Grit is built over the long haul, knowing that God's strength is "made perfect in weakness" (2 Corinthians 12:9).
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scripture Quotes -->
            <div class="scripture-box">
                "But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint."
                <div style="margin-top: 10px; font-size: 1rem; color: #8B7500; font-weight: bold;">– Isaiah 40:31</div>
            </div>
            
            <div class="scripture-box">
                "And let us not be weary in well doing: for in due season we shall reap, if we faint not."
                <div style="margin-top: 10px; font-size: 1rem; color: #8B7500; font-weight: bold;">– Galatians 6:9</div>
            </div>

            <!-- Next Week Preview -->
            <div class="preview-box">
                <div class="preview-title">
                    <i class="fas fa-arrow-right"></i> Next Week Preview
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    Having fortified your inner capacity for grit, you are ready to project your consecrated efforts outward. Week 7 begins our focus on Digital Communication. We will explore how to use technology—social media, email, and digital presentations—as a tool for authentic connection, professional outreach, and sharing light, ensuring your online presence powerfully reflects your consecrated identity and purpose.
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week7_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 7
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 6 Handout: Cultivating Grit</strong></p>
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
                    console.log('Week 6 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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
    $handout = new LifeSkillsWeek6Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>