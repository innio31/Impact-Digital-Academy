<?php
// modules/shared/course_materials/LifeSkills/week4_handout.php

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
 * Life Skills Week 4 Handout Class with PDF Download
 */
class LifeSkillsWeek4Handout
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
            $mpdf->SetTitle('Life Skills Week 4: The Mind\'s Architecture');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Cognitive Distortions, Thinking Errors, Mental Health');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Thinking Errors, Cognitive Distortions, Mental Architecture, Week 4');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week4_Handout_' . date('Y-m-d') . '.pdf';
            
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
                Life Skills Course - Week 4 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                The Mind's Architecture – Identifying & Overcoming Thinking Errors
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
                        <td><strong>Week:</strong> 4 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 4!
                </h4>
                <p>
                    Last week, you learned to sanctify your time—to turn your daily schedule into a testimony of your values. This week, we turn inward to consecrate the very lens through which you perceive reality: your thoughts. Just as poor stewardship of time leads to wasted days, unchecked thinking errors lead to a distorted, stressful, and limited life. This lesson moves beyond simply recognizing negative thoughts to understanding them as spiritual vulnerabilities—choices that distance us from truth, peace, and our divine potential. You will learn to diagnose the root causes of distorted thinking, apply spiritually-grounded reframing techniques, and build mental habits that align your inner world with eternal truth. By the end of this session, you will not just manage your thoughts; you will begin to master them, transforming your mind into a sacred space where revelation and righteous action can flourish.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate the doctrine of moral agency in thought and recognize at least five common cognitive distortions (thinking errors) and their impact on emotions, decisions, and spiritual progress.</li>
                    <li>Analyze the connection between primary emotions, stressful conditions, and the choice to engage in thinking errors.</li>
                    <li>Apply a four-step reframing process (Stop, Think, Act, Reflect) to overcome a personal thinking error, integrating prayer and gospel principles.</li>
                    <li>Develop a plan to cultivate a "mighty change" of mind, replacing thinking errors with virtues grounded in a Christlike identity.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": Thoughts as the Foundation of Stewardship</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Your thoughts are the command center for your stewardship over mind, time, and actions. Thinking errors are more than psychological concepts; they are manifestations of the "natural man"—ways we choose to respond to stress that conflict with our divine nature. When we commit thinking errors, we essentially pretend reality is different than it is. This self-deceit is a rejection of light and truth, creating barriers to learning, peace, and the Holy Ghost. Overcoming them is an essential act of consecrating our minds.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. Identifying Thinking Errors: Ten Common Distortions</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Thinking errors are failures in judgment caused by stress, leading to a distorted view of the world and actions that often increase our suffering. They are choices—expressions of our agency—even when they feel automatic.
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Victimization:</strong> Choosing to believe you are being intentionally harmed when no such intent exists.</li>
                        <li><strong>Pride:</strong> Basing your worth on comparisons with others.</li>
                        <li><strong>Entitlement:</strong> Believing you deserve special treatment or exemptions due to your status.</li>
                        <li><strong>Powerlessness:</strong> Telling yourself "I can't" and refusing to act or try.</li>
                        <li><strong>Giving Up:</strong> Allowing a mistake or setback to define your potential and justify quitting.</li>
                        <li><strong>Justification:</strong> Rationalizing sin or poor choices by minimizing its seriousness or blaming circumstances.</li>
                        <li><strong>Scarcity Mentality:</strong> Believing there is never enough—love, opportunity, blessings—leading to fear and selfishness.</li>
                        <li><strong>People Pleasing:</strong> Deriving your value from the approval of others, fearing their disapproval.</li>
                        <li><strong>Minimize/Catastrophize:</strong> Distorting the size of a problem, either shrinking it to avoid responsibility or blowing it up to justify despair.</li>
                        <li><strong>Deceit:</strong> The root of all errors. Lying to yourself about reality, your motives, or the consequences of your choices.</li>
                    </ul>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Anatomy of an Error: Primary Emotions & Conditions</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Thinking errors are chosen responses to underlying stress and primary emotions. Understanding this chain is key to repentance and change.
                    </p>
                    <p style="margin-left: 30px; margin-bottom: 10px;">
                        <strong>The Chain:</strong> Stressful Circumstance → Primary Emotion (Fear, Shame, Hurt, Disappointment) → Agency Point → Choice: Healthy Response or Thinking Error → Consequence.
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>B.L.A.H.S.T. Conditions:</strong> You are most vulnerable to thinking errors when your spirit and body are depleted:
                        Bored, Lonely, Angry, Hungry, Stressed, Tired.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">4. The Consecrated Reframe: A Four-Step Process for Overcoming Errors</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Overcoming thinking errors is the practical work of "putting off the natural man." This four-step process aligns spiritual and cognitive tools:
                    </p>
                    <ol style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>STOP:</strong> Recognize & Halt the thought pattern</li>
                        <li><strong>THINK:</strong> Diagnose the Source and identify the specific error</li>
                        <li><strong>ACT:</strong> Choose a Godly Response - challenge the error and perform a physical reset</li>
                        <li><strong>REFLECT:</strong> Learn & Integrate the lesson for future growth</li>
                    </ol>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">5. Seeking Help: Strength in Counsel & Covenant</p>
                    <p style="margin-left: 15px;">
                        Choosing to seek help—from a trusted friend, spouse, Church leader, or professional counselor—is not a surrender of agency but a righteous use of it. We are all dependent on the Savior, and He often works through others to provide healing, perspective, and support.
                    </p>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: Diagnose and Reframe a Thinking Error</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Foundation – Identify & Analyze</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Recall:</strong> In your Pathway Document, under a new "Week 4" section, recall a recent stressful situation where you felt a strong negative emotion (frustration, discouragement, anger, anxiety).</li>
                        <li><strong>Identify the Error:</strong> Review the list of 10 thinking errors. Which one did you most likely commit in that situation? Write it down.</li>
                        <li><strong>Analyze the Chain:</strong> 
                            <ul style="margin-top: 5px; margin-left: 20px;">
                                <li>Circumstance: What happened?</li>
                                <li>Primary Emotion: What did you initially feel?</li>
                                <li>Your Chosen Thought/Error: What did you tell yourself?</li>
                                <li>Consequence: How did this thought affect your feelings and subsequent actions?</li>
                            </ul>
                        </li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Consecrated Reframe – Apply the Process</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>STOP:</strong> What would a tangible "stop" signal look like for you in the future?</li>
                        <li><strong>THINK:</strong> Were any B.L.A.H.S.T. conditions present? How did they influence you?</li>
                        <li><strong>ACT – Craft a Godly Response:</strong> For your identified error, write a "reframe" statement based on gospel truth.</li>
                        <li><strong>REFLECT:</strong> Write a short prayer asking for help to implement this reframe the next time a similar stress arises.</li>
                    </ol>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-left: 3px solid #DAA520; font-style: italic;">
                        <strong>Example (Catastrophizing → Faith):</strong> "This feels overwhelming, but God is aware of me. I can take one small step. I will trust His help and do what I can today."
                    </div>
                    <div style="margin-top: 10px; padding: 10px; background: #FAFAD2; border-left: 3px solid #DAA520; font-style: italic;">
                        <strong>Example (People Pleasing → Divine Worth):</strong> "My worth is constant and infinite in the eyes of God. I will act with kindness and integrity, not for approval, but because it is right."
                    </div>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>The Stewardship of Thought:</strong> How does understanding thinking errors as a choice and a stewardship change your sense of responsibility for your mental state?</li>
                    <li><strong>Condition Check:</strong> Over the next 48 hours, monitor yourself for B.L.A.H.S.T. conditions. How often do they occur? What is one simple way you can better care for your physical and spiritual state to reduce vulnerability?</li>
                    <li><strong>The Savior's Role:</strong> In what way is overcoming a deep-seated thinking error impossible without the enabling power of Jesus Christ's Atonement? How can you more fully invite Him into this process?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Thinking Error</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A chosen, patterned failure in judgment—a distorted view of reality—often in response to stress</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Primary Emotion</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">An initial, instinctive emotional response (e.g., fear, hurt, disappointment) to an event</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>B.L.A.H.S.T. Conditions</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Vulnerable states (Bored, Lonely, Angry, Hungry, Stressed, Tired) that increase error likelihood</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Reframing</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Identifying a thinking error, halting it, and replacing it with a thought based on truth and faith</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Consecrated Mind</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A mind dedicated to truth, free from distortions, aligned with divine principles</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>Which of the ten thinking errors resonates most with your typical reactions? Why do you think that pattern has developed?</li>
                    <li>How can effectively managing the B.L.A.H.S.T. conditions be seen as an act of spiritual preparation and self-stewardship?</li>
                    <li>How does the four-step "Stop, Think, Act, Reflect" process align with the principles of repentance and seeking divine grace?</li>
                    <li>What is the relationship between thinking errors and our ability to receive personal revelation?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Start Small:</strong> Choose one thinking error to focus on for the semester. Consistent work on one is more powerful than a scattered approach.</li>
                    <li><strong>Pair with Prayer:</strong> Make identifying and overcoming thinking errors a matter of sincere prayer.</li>
                    <li><strong>Use Your Support System:</strong> Share your goal to work on a specific thinking error with a trusted friend or family member.</li>
                    <li><strong>Grace in the Process:</strong> Changing lifelong thought patterns is the journey of a lifetime. Each moment of awareness is a victory.</li>
                </ul>
            </div>
            
            <!-- Quote -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FFF8DC; border-radius: 5px;">
                "Let virtue garnish thy thoughts unceasingly; then shall thy confidence wax strong in the presence of God..." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Doctrine & Covenants 121:45</div>
            </div>
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FAFAD2; border-radius: 5px;">
                "For as he thinketh in his heart, so is he." 
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Proverbs 23:7</div>
            </div>
            
            <!-- Next Week Preview -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Next Week Preview
                </h4>
                <p>
                    Having worked to consecrate your mind (Week 3) and your thoughts (Week 4), Week 5 will focus on the cornerstone of all temporal stewardship: Financial Principles. We will explore provident living, avoiding debt, budgeting with eternal purpose, and viewing money as a tool for building God's kingdom—completing the triad of personal consecration (Mind, Time, Means) to prepare you for outward impact.
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
                <p><strong>Week Completed:</strong> 4 of 8</p>
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
                Week 4 Handout<br>The Mind\'s Architecture – Identifying & Overcoming Thinking Errors
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
                    Week: 4 of 8
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
                    This Week 4 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 4: The Mind\'s Architecture | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 4 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 4: The Mind's Architecture – Impact Digital Academy</title>
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

        .mind-icon {
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

        .errors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .error-card {
            background: #FFF8DC;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #B8860B;
            transition: all 0.3s;
        }

        .error-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(139, 117, 0, 0.1);
        }

        .error-title {
            color: #8B7500;
            font-weight: bold;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-title i {
            color: #DAA520;
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
            
            .errors-grid {
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
                <strong>Week 4 Handout:</strong> The Mind's Architecture
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
            <div class="mind-icon">
                <i class="fas fa-brain"></i>
            </div>
            <div class="week-title">Week 4 Handout: The Mind's Architecture</div>
            <div class="week-subtitle">Identifying & Overcoming Thinking Errors</div>
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
                        <strong>Week:</strong> 4 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 4!
                </div>
                <p>Last week, you learned to sanctify your time—to turn your daily schedule into a testimony of your values. This week, we turn inward to consecrate the very lens through which you perceive reality: your thoughts. Just as poor stewardship of time leads to wasted days, unchecked thinking errors lead to a distorted, stressful, and limited life. This lesson moves beyond simply recognizing negative thoughts to understanding them as spiritual vulnerabilities—choices that distance us from truth, peace, and our divine potential. You will learn to diagnose the root causes of distorted thinking, apply spiritually-grounded reframing techniques, and build mental habits that align your inner world with eternal truth. By the end of this session, you will not just manage your thoughts; you will begin to master them, transforming your mind into a sacred space where revelation and righteous action can flourish.</p>
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
                        <li>Articulate the doctrine of moral agency in thought and recognize at least five common cognitive distortions (thinking errors) and their impact on emotions, decisions, and spiritual progress.</li>
                        <li>Analyze the connection between primary emotions, stressful conditions, and the choice to engage in thinking errors.</li>
                        <li>Apply a four-step reframing process (Stop, Think, Act, Reflect) to overcome a personal thinking error, integrating prayer and gospel principles.</li>
                        <li>Develop a plan to cultivate a "mighty change" of mind, replacing thinking errors with virtues grounded in a Christlike identity.</li>
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
                        <div class="topic-title">The "Why": Thoughts as the Foundation of Stewardship</div>
                        <p>Your thoughts are the command center for your stewardship over mind, time, and actions. Thinking errors are more than psychological concepts; they are manifestations of the "natural man"—ways we choose to respond to stress that conflict with our divine nature.</p>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px; font-style: italic;">
                            <strong>Key Insight:</strong> When we commit thinking errors, we essentially pretend reality is different than it is. This self-deceit is a rejection of light and truth, creating barriers to learning, peace, and spiritual guidance.
                        </div>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">Identifying Thinking Errors: Ten Common Distortions</div>
                        <p>Thinking errors are failures in judgment caused by stress, leading to a distorted view of the world and actions that often increase our suffering. They are choices—expressions of our agency—even when they feel automatic.</p>
                        <div style="margin-top: 15px;">
                            <strong>Common Errors Include:</strong>
                            <ul style="margin-top: 8px; margin-left: 20px;">
                                <li>Victimization & Pride</li>
                                <li>Entitlement & Powerlessness</li>
                                <li>Justification & Scarcity Mentality</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Topic 3 -->
                    <div class="topic-card">
                        <div class="topic-number">3</div>
                        <div class="topic-title">The Anatomy of an Error: Primary Emotions & Conditions</div>
                        <p>Thinking errors are chosen responses to underlying stress and primary emotions. Understanding this chain is key to repentance and change.</p>
                        <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                            <strong>The Chain:</strong><br>
                            Stressful Circumstance → Primary Emotion → Agency Point → Choice: Healthy Response or Thinking Error → Consequence
                        </div>
                        <div style="margin-top: 10px;">
                            <strong>B.L.A.H.S.T. Conditions:</strong><br>
                            Bored, Lonely, Angry, Hungry, Stressed, Tired
                        </div>
                    </div>
                    
                    <!-- Topic 4 -->
                    <div class="topic-card">
                        <div class="topic-number">4</div>
                        <div class="topic-title">The Consecrated Reframe: A Four-Step Process</div>
                        <p>Overcoming thinking errors is the practical work of "putting off the natural man." This four-step process aligns spiritual and cognitive tools:</p>
                        <ol style="margin-top: 10px; margin-left: 20px;">
                            <li><strong>STOP:</strong> Recognize & Halt</li>
                            <li><strong>THINK:</strong> Diagnose the Source</li>
                            <li><strong>ACT:</strong> Choose a Godly Response</li>
                            <li><strong>REFLECT:</strong> Learn & Integrate</li>
                        </ol>
                        <div style="margin-top: 15px; padding: 10px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0; font-size: 0.9rem;">
                            This process turns a single event into lasting discipleship growth.
                        </div>
                    </div>
                </div>

                <!-- Thinking Errors Grid -->
                <div style="margin-top: 30px;">
                    <h4 style="color: #8B7500; font-size: 1.2rem; margin-bottom: 15px; border-bottom: 1px solid #F0E68C; padding-bottom: 8px;">
                        <i class="fas fa-exclamation-triangle"></i> Ten Common Thinking Errors
                    </h4>
                    
                    <div class="errors-grid">
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-user-slash"></i> Victimization
                            </div>
                            <p>Choosing to believe you are being intentionally harmed when no such intent exists.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-crown"></i> Pride
                            </div>
                            <p>Basing your worth on comparisons with others.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-gem"></i> Entitlement
                            </div>
                            <p>Believing you deserve special treatment or exemptions due to your status.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-ban"></i> Powerlessness
                            </div>
                            <p>Telling yourself "I can't" and refusing to act or try.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-flag"></i> Giving Up
                            </div>
                            <p>Allowing a mistake or setback to define your potential and justify quitting.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-balance-scale"></i> Justification
                            </div>
                            <p>Rationalizing sin or poor choices by minimizing its seriousness or blaming circumstances.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-hourglass-half"></i> Scarcity Mentality
                            </div>
                            <p>Believing there is never enough—love, opportunity, blessings—leading to fear and selfishness.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-users"></i> People Pleasing
                            </div>
                            <p>Deriving your value from the approval of others, fearing their disapproval.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-expand-arrows-alt"></i> Minimize/Catastrophize
                            </div>
                            <p>Distorting the size of a problem, either shrinking it or blowing it up unjustifiably.</p>
                        </div>
                        
                        <div class="error-card">
                            <div class="error-title">
                                <i class="fas fa-mask"></i> Deceit
                            </div>
                            <p>The root of all errors. Lying to yourself about reality, your motives, or consequences.</p>
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
                        <i class="fas fa-tasks"></i> Activity: Diagnose and Reframe a Thinking Error
                    </div>
                    <p>Follow these steps to apply your Week 4 knowledge to a real situation:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-search"></i> Part 1: Foundation – Identify & Analyze
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Recall:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>In your Pathway Document, under a new "Week 4" section, recall a recent stressful situation where you felt a strong negative emotion (frustration, discouragement, anger, anxiety).</li>
                                    <li>Describe the circumstance briefly but specifically.</li>
                                </ul>
                            </li>
                            <li><strong>Identify the Error:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Review the list of 10 thinking errors. Which one did you most likely commit in that situation?</li>
                                    <li>Write it down clearly: "I was engaging in [Error Name]."</li>
                                </ul>
                            </li>
                            <li><strong>Analyze the Chain:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>Circumstance:</strong> What happened?</li>
                                    <li><strong>Primary Emotion:</strong> What did you initially feel (Fear, Hurt, Disappointment, Shame)?</li>
                                    <li><strong>Your Chosen Thought/Error:</strong> What did you tell yourself? (Link this directly to the named error).</li>
                                    <li><strong>Consequence:</strong> How did this thought affect your feelings and subsequent actions?</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-sync-alt"></i> Part 2: Consecrated Reframe – Apply the Process
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>STOP:</strong> What would a tangible "stop" signal look like for you in the future? (e.g., A specific phrase like "Stop. This is a thinking error," a silent prayer, a physical action like taking three deep breaths).</li>
                            <li><strong>THINK:</strong> 
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Were any B.L.A.H.S.T. conditions present? (Bored, Lonely, Angry, Hungry, Stressed, Tired)</li>
                                    <li>How did they influence your vulnerability to the thinking error?</li>
                                </ul>
                            </li>
                            <li><strong>ACT – Craft a Godly Response:</strong> For your identified error, write a "reframe" statement based on gospel truth. This is your new mental script.</li>
                            <li><strong>REFLECT:</strong> 
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Write a short prayer asking for help to implement this reframe the next time a similar stress arises.</li>
                                    <li>What specific attribute of Christ (e.g., patience, humility, faith, courage) do you need to develop to overcome this error long-term?</li>
                                </ul>
                            </li>
                        </ol>
                        
                        <div style="margin-top: 20px;">
                            <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Examples of Godly Reframes:</p>
                            
                            <div style="margin-bottom: 15px; padding: 15px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0;">
                                <strong>Catastrophizing → Faith:</strong> "This feels overwhelming, but God is aware of me. I can take one small step. I will trust His help and do what I can today."
                            </div>
                            
                            <div style="padding: 15px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0;">
                                <strong>People Pleasing → Divine Worth:</strong> "My worth is constant and infinite in the eyes of God. I will act with kindness and integrity, not for approval, but because it is right."
                            </div>
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
                        <strong>The Stewardship of Thought:</strong> How does understanding thinking errors as a choice and a stewardship change your sense of responsibility for your mental state? Does this perspective empower you or feel burdensome? Why?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Condition Check:</strong> Over the next 48 hours, monitor yourself for B.L.A.H.S.T. conditions. How often do they occur? What is one simple way you can better care for your physical and spiritual state to reduce vulnerability to thinking errors?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Savior's Role:</strong> In what way is overcoming a deep-seated thinking error impossible without the enabling power of Jesus Christ's Atonement? How can you more fully invite Him into this process of mental and spiritual renovation?
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
                        <div class="term-title">Thinking Error</div>
                        <p>A chosen, patterned failure in judgment—a distorted view of reality—often in response to stress, which leads to negative emotions and unproductive behaviors.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Primary Emotion</div>
                        <p>An initial, instinctive emotional response (e.g., fear, hurt, disappointment) to an event, which then presents a point of agency.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">B.L.A.H.S.T. Conditions</div>
                        <p>A set of vulnerable physical/emotional states (Bored, Lonely, Angry, Hungry, Stressed, Tired) that increase the likelihood of committing thinking errors.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Reframing</div>
                        <p>The consecrated process of identifying a thinking error, halting it, and consciously replacing it with a thought based on truth, faith, and gospel principles.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Consecrated Mind</div>
                        <p>A mind dedicated to truth, free from distortions, aligned with divine principles, and open to revelation.</p>
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
                        Which of the ten thinking errors resonates most with your typical reactions under stress? Why do you think that particular pattern has developed in your life?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        How can effectively managing the B.L.A.H.S.T. conditions (getting enough rest, eating well, managing stress) be seen as an act of spiritual preparation and self-stewardship, not just physical maintenance?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        How does the four-step "Stop, Think, Act, Reflect" process align with the principles of repentance and seeking divine grace? Where is Christ in each step?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">4</span>
                        What is the relationship between thinking errors and our ability to receive personal revelation? How might clearing our minds of distortions improve our capacity to hear the Spirit?
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
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <strong>Start Small:</strong> For your Application Activity, choose ONE thinking error to focus on for the semester. Consistent, prayerful work on one error is more powerful than a scattered approach to several.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-pray"></i>
                        </div>
                        <div>
                            <strong>Pair with Prayer:</strong> Make identifying and overcoming thinking errors a matter of sincere prayer. "Heavenly Father, help me to see my thoughts as they really are. Show me where I am deceiving myself."
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-hands-helping"></i>
                        </div>
                        <div>
                            <strong>Use Your Support System:</strong> Share your goal to work on a specific thinking error with a trusted friend, spouse, or family member. Ask them to lovingly point it out when they see it manifest in your words or attitude.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <strong>Grace in the Process:</strong> Changing lifelong thought patterns is the journey of a lifetime—part of the ongoing conversion process. Do not succumb to "Giving Up" when you fail. Each moment of awareness is a victory. Repent, reframe, and try again with renewed dependence on Christ.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quotes -->
            <div class="quote-box">
                "Let virtue garnish thy thoughts unceasingly; then shall thy confidence wax strong in the presence of God..." 
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Doctrine & Covenants 121:45</div>
            </div>
            
            <div class="quote-box">
                "For as he thinketh in his heart, so is he." 
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Proverbs 23:7</div>
            </div>

            <!-- Next Week Preview -->
            <div class="preview-box">
                <div class="preview-title">
                    <i class="fas fa-arrow-right"></i> Next Week Preview
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    Having worked to consecrate your mind (Week 3) and your thoughts (Week 4), Week 5 will focus on the cornerstone of all temporal stewardship: Financial Principles. We will explore provident living, avoiding debt, budgeting with eternal purpose, and viewing money as a tool for building God's kingdom—completing the triad of personal consecration (Mind, Time, Means) to prepare you for outward impact.
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week5_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 5
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 4 Handout: The Mind's Architecture</strong></p>
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

            // Add click animation to error cards
            const errorCards = document.querySelectorAll('.error-card');
            errorCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'translateY(-3px)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(0)';
                    }, 300);
                });
            });

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('Week 4 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                    // In production, send AJAX request to log access
                }
            });

            // Add B.L.A.H.S.T. condition checker
            const blahstConditions = ['Bored', 'Lonely', 'Angry', 'Hungry', 'Stressed', 'Tired'];
            const checkConditionsBtn = document.createElement('button');
            checkConditionsBtn.innerHTML = '<i class="fas fa-heartbeat"></i> Check B.L.A.H.S.T. Conditions';
            checkConditionsBtn.className = 'download-btn';
            checkConditionsBtn.style.marginTop = '10px';
            checkConditionsBtn.onclick = function() {
                let message = 'B.L.A.H.S.T. Conditions Check:\n\n';
                let count = 0;
                
                blahstConditions.forEach(condition => {
                    const hasCondition = confirm(`Are you currently feeling ${condition.toLowerCase()}?`);
                    if (hasCondition) {
                        message += `✓ ${condition}\n`;
                        count++;
                    } else {
                        message += `○ ${condition}\n`;
                    }
                });
                
                message += `\nYou have ${count} active condition${count !== 1 ? 's' : ''}. `;
                if (count > 2) {
                    message += 'Consider taking a break, praying, or addressing physical needs before making important decisions.';
                } else if (count > 0) {
                    message += 'Be mindful of your increased vulnerability to thinking errors.';
                } else {
                    message += 'Great! You\'re in a good state for clear thinking.';
                }
                
                alert(message);
            };
            
            // Add button after the reflection prompts
            const reflectionSection = document.querySelector('.reflection-box');
            if (reflectionSection) {
                reflectionSection.parentNode.insertBefore(checkConditionsBtn, reflectionSection.nextSibling);
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
    $handout = new LifeSkillsWeek4Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>