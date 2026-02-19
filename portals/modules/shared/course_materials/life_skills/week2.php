<?php
// modules/shared/course_materials/LifeSkills/week2_handout.php

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
 * Life Skills Week 2 Handout Class with PDF Download
 */
class LifeSkillsWeek2Handout
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
            $mpdf->SetTitle('Life Skills Week 2: Learning How to Learn – Educational Stewardship');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Educational Stewardship, Learning, Growth Mindset');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Learning, Educational Stewardship, Growth Mindset, Metacognition, Week 2');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week2_Learning_Stewardship_' . date('Y-m-d') . '.pdf';
            
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
                Life Skills Course - Week 2 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                Learning How to Learn – Educational Stewardship
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
                        <td><strong>Week:</strong> 2 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 2!
                </h4>
                <p>
                    Last week, you charted your pathway by defining your stewardships and aspirations. This week, we consecrate that direction to a sacred purpose: becoming a true Steward of Your Learning. Elder David A. Bednar taught that a central purpose of our mortal journey is "to learn how to learn... and to learn to love learning." This moves us far beyond passive consumption. It is about consciously and responsibly managing how you acquire, retain, and apply knowledge—turning your education into an offering. You will learn to diagnose your learning process, apply divinely-inspired models, and build a personalized toolkit to become a more confident, effective, and sanctified learner. By the end of this session, you will own your learning journey, equipped with strategies that make study time both productive and purposeful.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate the spiritual imperative for learning and define "metacognition" in the context of your personal journey.</li>
                    <li>Explain the Growth Mindset as a principle of faith and identify where a "False Growth Mindset" may hinder you.</li>
                    <li>Implement the three-step Learning Model (Prepare, Teach One Another, Ponder & Prove) and integrate active learning techniques.</li>
                    <li>Create a personalized "Learning Protocol" that consecrates your educational efforts.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": Learning as a Stewardship and a Commandment</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Learning is not merely academic; it is a spiritual stewardship. As we are commanded to learn "of things both in heaven and in the earth," we recognize that our minds are gifts to be developed. This frames <strong>Educational Stewardship</strong>: the responsible management of our God-given capacity to learn, understand, and grow. It begins with <strong>metacognition</strong>—thinking about our thinking—to understand how we learn best, so we can more effectively gather knowledge "both temporally important and eternally essential."
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. The Foundation: Cultivating a True Growth Mindset</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>Fixed vs. Growth:</strong> A Fixed Mindset believes ability is static, leading to a fear of failure that caps potential. A Growth Mindset, as defined by Carol Dweck, believes abilities can be developed through dedication. This aligns with learning by faith—it removes self-imposed restrictions and allows our brains, like muscles, to strengthen through righteous effort.
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>The Neural Pathway of Faith:</strong> When we struggle, our brains actively build new neural pathways. A growth mindset uses this activity to analyze mistakes and try new approaches, turning failure into a learning opportunity.
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>Beware the "False Growth Mindset":</strong> It is not mere effort without analysis. True growth requires meaningful work, honest feedback, effective strategies, and revision. It is the opposite of "doing the same thing over and over expecting different results."
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Core Strategy: The Learning Model</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        This three-step model sanctifies the learning process, transforming it from a task into a consecrated offering.
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>PREPARE (Activate Your Mind):</strong> Begin by consecrating your study. Ask, "How could I use this knowledge to bless others?" Complete assignments early. Engage with materials actively (e.g., the SQ3R Method: Survey, Question, Read, Recite, Review). Bring specific questions to gatherings.</li>
                        <li><strong>TEACH ONE ANOTHER (Sanctify Through Sharing):</strong> Learning deepens when shared. Teaching invites the Spirit of revelation to clarify and correct understanding. Participate sincerely in weekly gatherings. Listen, respond, and share insights. Explain concepts to peers or family.</li>
                        <li><strong>PONDER AND PROVE (Internalize and Apply):</strong> Reflection turns information into integrated understanding and testimony. Use the Ponder and Record prompts throughout lessons. Apply techniques like the Feynman Technique or Spaced Repetition to solidify memory.</li>
                    </ul>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">4. Building Your Learning Protocol: Strategies for Stewardship</p>
                    <p style="margin-left: 15px; margin-bottom: 5px;">
                        Effective strategies are the "skills" that point your mental satellite dish for a clear signal:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Active vs. Passive:</strong> Replace passive re-reading with active engagement: strategic note-taking (like Cornell Notes), self-quizzing, and concept mapping.</li>
                        <li><strong>Stewarding Your Focus:</strong> Use tools like the Pomodoro Technique (focused work intervals) to manage your time and attention as sacred resources.</li>
                        <li><strong>Stewarding Your Resources:</strong> Proactively identify and use your learning ecosystem: instructors, peer study groups, and digital tools. Asking for help is part of wise stewardship.</li>
                    </ul>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: Design Your Consecrated Learning Protocol</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Foundation - Mindset & Model</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Create Your Document Section:</strong> In your Pathway Document, create a new section: "Week 2: My Consecrated Learning Protocol."</li>
                        <li><strong>Growth Mindset Reflection:</strong> Briefly describe a recent learning challenge. Did you initially react with a fixed mindset ("I can't do this")? How could you reframe it with a growth mindset, seeing it as a chance to build a new "neural pathway"?</li>
                        <li><strong>Learning Model Integration:</strong> For your current coursework, write one specific action you will take for each phase this week:
                            <ul style="margin-left: 20px; margin-top: 5px;">
                                <li><strong>Prepare:</strong> "I will prepare spiritually and academically by..."</li>
                                <li><strong>Teach One Another:</strong> "I will share my learning by..."</li>
                                <li><strong>Ponder & Prove:</strong> "I will reflect and apply by..."</li>
                            </ul>
                        </li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Protocol Design for a Current Challenge</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>Identify a Current Learning Goal:</strong> Choose one specific topic or skill from your current studies.</li>
                        <li><strong>Build Your Tactical Protocol:</strong>
                            <ul style="margin-left: 20px; margin-top: 5px;">
                                <li><strong>My Active Learning Technique:</strong> (Choose one aligned with Prepare/Ponder) e.g., "I will use Cornell Notes during my reading to better Prepare, leaving a cue column for later review."</li>
                                <li><strong>My Focus Strategy:</strong> (Steward your time) e.g., "I will use three 25-minute Pomodoros to deep-dive on this topic, beginning with a prayer for focus."</li>
                                <li><strong>My Resource & Feedback Plan:</strong> (Align with Teach One Another) e.g., "I will bring my specific question about this to the gathering, and if still unclear, I will schedule time with the instructor."</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>The Stewardship Question:</strong> As you study this week, pause and ask: "How is what I'm learning right now helping me become a better steward of my talents, my relationships, and my divine potential?"</li>
                    <li><strong>The "Teach It" Test:</strong> After studying a concept, imagine explaining it to a family member during a gospel discussion. Does your understanding hold up? This is the heart of "Teach One Another."</li>
                    <li><strong>False Mindset Check:</strong> Am I praising myself for busy effort, or am I analyzing what's working and strategically adjusting my approach? (True Growth Mindset).</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Educational Stewardship</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The responsible and consecrated management of one's own learning process</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Growth Mindset</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The belief that abilities can be developed through diligent effort and strategies</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Metacognition</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Awareness of one's own learning processes; "thinking about your thinking"</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>The Learning Model</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Three-step spiritual framework: Prepare, Teach One Another, Ponder & Prove</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Learning Protocol</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Personalized plan combining mindset, model, and practical strategies</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>In your own words, how does adopting a Growth Mindset involve more than just "trying harder"?</li>
                    <li>How does the "Teach One Another" phase of the Learning Model transform your education from a personal acquisition into a divine offering?</li>
                    <li>Looking at your draft protocol, which element feels most inspired? Which feels most challenging, and what does that teach you about your next step in learning stewardship?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Consecrate Your Study Space:</strong> Begin each study session with a moment of prayerful intent to focus and learn.</li>
                    <li><strong>Embrace the Model as a Cycle:</strong> You don't just do the steps in order. You Prepare for gatherings, Teach in them, then Ponder on what was shared to Prepare for deeper learning.</li>
                    <li><strong>Your Protocol is a Revelation Log:</strong> Update it weekly. Note what strategies invited clarity and which didn't. This is how you "learn how to learn."</li>
                    <li><strong>Seek Feedback, Not Just Validation:</strong> Ask instructors and peers, "What's one gap in my understanding?" This is true growth.</li>
                </ul>
            </div>
            
            <!-- Quote -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FFF8DC; border-radius: 5px;">
                "The mind is not a vessel to be filled, but a fire to be kindled." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Plutarch</div>
                <div style="margin-top: 10px; border-top: 1px solid #E6D690; padding-top: 10px;">
                    "You and I are here on the earth... to learn how to learn, and to learn to love learning."
                    <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Elder David A. Bednar</div>
                </div>
            </div>
            
            <!-- Next Week Preview -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Next Week Preview
                </h4>
                <p>
                    Having established stewardship over your learning, Week 3 turns outward to Professional Fundamentals. We'll focus on crafting a virtuous digital presence, communicating with clarity and integrity, and building the foundational habits of a reliable professional—bridging your consecrated personal pathway to meaningful service and impact in the world.
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
                <p><strong>Week Completed:</strong> 2 of 8</p>
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
                Week 2 Handout<br>Learning How to Learn – Educational Stewardship
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
                    Week: 2 of 8
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
                    This Week 2 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 2: Learning How to Learn | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 2 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 2: Learning How to Learn – Impact Digital Academy</title>
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

        .learning-icon {
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

        .model-diagram {
            background: #FAFAD2;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            text-align: center;
        }

        .model-steps {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }

        .model-step {
            flex: 1;
            min-width: 200px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #F0E68C;
        }

        .step-number {
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

        .mindset-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }

        .mindset-column {
            padding: 20px;
            border-radius: 8px;
        }

        .fixed-mindset {
            background: #FFEBEE;
            border: 1px solid #F44336;
        }

        .growth-mindset {
            background: #E8F5E9;
            border: 1px solid #4CAF50;
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
            
            .mindset-comparison {
                grid-template-columns: 1fr;
            }
            
            .model-steps {
                flex-direction: column;
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
                <strong>Week 2 Handout:</strong> Learning How to Learn
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
            <div class="learning-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="week-title">Week 2 Handout: Learning How to Learn – Educational Stewardship</div>
            <div class="week-subtitle">Consecrating Your Capacity to Learn and Grow</div>
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
                        <strong>Week:</strong> 2 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 2!
                </div>
                <p>Last week, you charted your pathway by defining your stewardships and aspirations. This week, we consecrate that direction to a sacred purpose: becoming a true Steward of Your Learning. Elder David A. Bednar taught that a central purpose of our mortal journey is "to learn how to learn... and to learn to love learning." This moves us far beyond passive consumption. It is about consciously and responsibly managing how you acquire, retain, and apply knowledge—turning your education into an offering. You will learn to diagnose your learning process, apply divinely-inspired models, and build a personalized toolkit to become a more confident, effective, and sanctified learner. By the end of this session, you will own your learning journey, equipped with strategies that make study time both productive and purposeful.</p>
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
                        <li>Articulate the spiritual imperative for learning and define "metacognition" in the context of your personal journey.</li>
                        <li>Explain the Growth Mindset as a principle of faith and identify where a "False Growth Mindset" may hinder you.</li>
                        <li>Implement the three-step Learning Model (Prepare, Teach One Another, Ponder & Prove) and integrate active learning techniques.</li>
                        <li>Create a personalized "Learning Protocol" that consecrates your educational efforts.</li>
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
                        <div class="topic-title">The "Why": Learning as a Stewardship and a Commandment</div>
                        <p>Learning is not merely academic; it is a spiritual stewardship. As we are commanded to learn "of things both in heaven and in the earth," we recognize that our minds are gifts to be developed.</p>
                        <p><strong>Educational Stewardship</strong>: the responsible management of our God-given capacity to learn, understand, and grow.</p>
                        <p><strong>Metacognition</strong>: thinking about our thinking—to understand how we learn best, so we can more effectively gather knowledge "both temporally important and eternally essential."</p>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">The Foundation: Cultivating a True Growth Mindset</div>
                        
                        <div class="mindset-comparison">
                            <div class="mindset-column fixed-mindset">
                                <h4 style="color: #D32F2F; margin-bottom: 10px;">Fixed Mindset</h4>
                                <ul style="margin-left: 15px;">
                                    <li>Believes ability is static</li>
                                    <li>Fear of failure caps potential</li>
                                    <li>Avoids challenges</li>
                                    <li>Gives up easily</li>
                                </ul>
                            </div>
                            
                            <div class="mindset-column growth-mindset">
                                <h4 style="color: #388E3C; margin-bottom: 10px;">Growth Mindset</h4>
                                <ul style="margin-left: 15px;">
                                    <li>Believes abilities can be developed</li>
                                    <li>Embraces challenges as opportunities</li>
                                    <li>Learns from criticism</li>
                                    <li>Finds lessons in failure</li>
                                </ul>
                            </div>
                        </div>
                        
                        <p><strong>The Neural Pathway of Faith:</strong> When we struggle, our brains actively build new neural pathways. A growth mindset uses this activity to analyze mistakes and try new approaches, turning failure into a learning opportunity.</p>
                        
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                            <strong>Beware the "False Growth Mindset":</strong> It is not mere effort without analysis. True growth requires meaningful work, honest feedback, effective strategies, and revision. It is the opposite of "doing the same thing over and over expecting different results."
                        </div>
                    </div>
                </div>
                
                <!-- Learning Model Diagram -->
                <div class="model-diagram">
                    <h3 style="color: #8B7500; margin-bottom: 20px;">The Learning Model: A Consecrated Cycle</h3>
                    
                    <div class="model-steps">
                        <div class="model-step">
                            <div class="step-number">1</div>
                            <h4 style="color: #8B7500;">PREPARE</h4>
                            <p>Activate Your Mind<br>Consecrate your study<br>Complete assignments early<br>Engage actively</p>
                        </div>
                        
                        <div class="model-step">
                            <div class="step-number">2</div>
                            <h4 style="color: #8B7500;">TEACH ONE ANOTHER</h4>
                            <p>Sanctify Through Sharing<br>Participate in gatherings<br>Explain concepts to others<br>Invite revelation</p>
                        </div>
                        
                        <div class="model-step">
                            <div class="step-number">3</div>
                            <h4 style="color: #8B7500;">PONDER & PROVE</h4>
                            <p>Internalize and Apply<br>Reflect deeply<br>Use learning techniques<br>Apply in real life</p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; font-style: italic; color: #666;">
                        This model sanctifies the learning process, transforming it from a task into a consecrated offering.
                    </div>
                </div>
                
                <!-- Topic 4 -->
                <div class="topic-card" style="margin-top: 20px;">
                    <div class="topic-number">3</div>
                    <div class="topic-title">Building Your Learning Protocol: Strategies for Stewardship</div>
                    <p>Effective strategies are the "skills" that point your mental satellite dish for a clear signal. We will integrate secular techniques within the spiritual framework of the Learning Model.</p>
                    
                    <div style="margin-top: 15px;">
                        <p><strong>Active vs. Passive Learning:</strong></p>
                        <ul style="margin-left: 20px; margin-bottom: 15px;">
                            <li><strong>Passive:</strong> Re-reading, highlighting without thinking</li>
                            <li><strong>Active:</strong> Strategic note-taking (Cornell Notes), self-quizzing, concept mapping, explaining concepts aloud</li>
                        </ul>
                        
                        <p><strong>Stewarding Your Focus:</strong></p>
                        <ul style="margin-left: 20px; margin-bottom: 15px;">
                            <li><strong>Pomodoro Technique:</strong> 25-minute focused work intervals followed by 5-minute breaks</li>
                            <li>Manage time and attention as sacred resources</li>
                            <li>Begin sessions with prayerful intent</li>
                        </ul>
                        
                        <p><strong>Stewarding Your Resources:</strong></p>
                        <ul style="margin-left: 20px;">
                            <li>Identify your learning ecosystem: instructors, peer study groups, digital tools</li>
                            <li>Asking for help is part of wise stewardship</li>
                            <li>Seek feedback, not just validation</li>
                        </ul>
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
                        <i class="fas fa-tasks"></i> Activity: Design Your Consecrated Learning Protocol
                    </div>
                    <p>Follow these steps to apply your Week 2 knowledge:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-brain"></i> Part 1: Foundation - Mindset & Model
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Create Your Document Section:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>In your Pathway Document, create a new section: "<em>Week 2: My Consecrated Learning Protocol.</em>"</li>
                                </ul>
                            </li>
                            <li><strong>Growth Mindset Reflection:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Briefly describe a recent learning challenge.</li>
                                    <li>Did you initially react with a fixed mindset ("I can't do this")?</li>
                                    <li>How could you reframe it with a growth mindset, seeing it as a chance to build a new "neural pathway"?</li>
                                </ul>
                            </li>
                            <li><strong>Learning Model Integration:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>For your current coursework, write one specific action you will take for each phase this week:</li>
                                    <li><strong>Prepare:</strong> "I will prepare spiritually and academically by..."</li>
                                    <li><strong>Teach One Another:</strong> "I will share my learning by..."</li>
                                    <li><strong>Ponder & Prove:</strong> "I will reflect and apply by..."</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-tools"></i> Part 2: Protocol Design for a Current Challenge
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Identify a Current Learning Goal:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Choose one specific topic or skill from your current studies that you find challenging.</li>
                                </ul>
                            </li>
                            <li><strong>Build Your Tactical Protocol:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>My Active Learning Technique:</strong> (Choose one aligned with Prepare/Ponder)
                                        <ul>
                                            <li>Example: "I will use Cornell Notes during my reading to better Prepare, leaving a cue column for later review."</li>
                                        </ul>
                                    </li>
                                    <li><strong>My Focus Strategy:</strong> (Steward your time)
                                        <ul>
                                            <li>Example: "I will use three 25-minute Pomodoros to deep-dive on this topic, beginning with a prayer for focus."</li>
                                        </ul>
                                    </li>
                                    <li><strong>My Resource & Feedback Plan:</strong> (Align with Teach One Another)
                                        <ul>
                                            <li>Example: "I will bring my specific question about this to the gathering, and if still unclear, I will schedule time with the instructor."</li>
                                        </ul>
                                    </li>
                                </ul>
                            </li>
                        </ol>
                        
                        <div style="margin-top: 15px; padding: 15px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0; font-style: italic;">
                            <strong>Remember:</strong> Your Learning Protocol is a living document. Update it weekly based on what works and what doesn't. This is how you truly "learn how to learn."
                        </div>
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
                        <strong>The Stewardship Question:</strong> As you study this week, pause and ask: "How is what I'm learning right now helping me become a better steward of my talents, my relationships, and my divine potential?"
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The "Teach It" Test:</strong> After studying a concept, imagine explaining it to a family member during a gospel discussion. Does your understanding hold up? This is the heart of "Teach One Another."
                    </div>
                    
                    <div class="reflection-item">
                        <strong>False Mindset Check:</strong> Am I praising myself for busy effort, or am I analyzing what's working and strategically adjusting my approach? (True Growth Mindset).
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
                        <div class="term-title">Educational Stewardship</div>
                        <p>The responsible and consecrated management of one's own learning process, viewed as a divine mandate and gift.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Growth Mindset</div>
                        <p>The belief, aligned with faith, that abilities can be developed through diligent effort, analysis, and inspired strategies.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Metacognition</div>
                        <p>Awareness of one's own learning processes; "thinking about your thinking."</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">The Learning Model</div>
                        <p>The three-step spiritual framework (Prepare, Teach One Another, Ponder & Prove) that sanctifies learning.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Learning Protocol</div>
                        <p>A personalized, proactive plan that combines mindset, model, and practical strategies for effective learning.</p>
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
                        In your own words, how does adopting a Growth Mindset involve more than just "trying harder"?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        How does the "Teach One Another" phase of the Learning Model transform your education from a personal acquisition into a divine offering?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        Looking at your draft protocol, which element feels most inspired? Which feels most challenging, and what does that teach you about your next step in learning stewardship?
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
                            <strong>Consecrate Your Study Space:</strong> Begin each study session with a moment of prayerful intent to focus and learn. This sanctifies your efforts.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                        <div>
                            <strong>Embrace the Model as a Cycle:</strong> You don't just do the steps in order. You Prepare for gatherings, Teach in them, then Ponder on what was shared to Prepare for deeper learning.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div>
                            <strong>Your Protocol is a Revelation Log:</strong> Update it weekly. Note what strategies invited clarity and which didn't. This is how you "learn how to learn."
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <div>
                            <strong>Seek Feedback, Not Just Validation:</strong> Ask instructors and peers, "What's one gap in my understanding?" This is true growth.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quote -->
            <div class="quote-box">
                "The mind is not a vessel to be filled, but a fire to be kindled." 
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Plutarch</div>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #F0E68C;">
                    "You and I are here on the earth... to learn how to learn, and to learn to love learning."
                    <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Elder David A. Bednar</div>
                </div>
            </div>

            <!-- Next Week Preview -->
            <div class="preview-box">
                <div class="preview-title">
                    <i class="fas fa-arrow-right"></i> Next Week Preview
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    Having established stewardship over your learning, Week 3 turns outward to Professional Fundamentals. We'll focus on crafting a virtuous digital presence, communicating with clarity and integrity, and building the foundational habits of a reliable professional—bridging your consecrated personal pathway to meaningful service and impact in the world.
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week3_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 3
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 2 Handout: Learning How to Learn – Educational Stewardship</strong></p>
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

            // Interactive mindset comparison
            const mindsetColumns = document.querySelectorAll('.mindset-column');
            mindsetColumns.forEach(column => {
                column.addEventListener('click', function() {
                    this.style.transform = 'scale(1.02)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 300);
                });
            });

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('Week 2 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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
    $handout = new LifeSkillsWeek2Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>