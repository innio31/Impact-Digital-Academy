<?php
// modules/shared/course_materials/LifeSkills/week8_handout.php

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
 * Life Skills Week 8 Handout Class with PDF Download
 */
class LifeSkillsWeek8Handout
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
            $mpdf->SetTitle('Life Skills Week 8: Presentation & Covenant – Sealing Your Commitment');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Personal Development, Consecration, Covenant, Commitment');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Consecration, Covenant, Presentation, Commitment, Week 8');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week8_Handout_' . date('Y-m-d') . '.pdf';
            
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
                Life Skills Course - Week 8 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                Presentation & Covenant – Sealing Your Commitment
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
                        <td><strong>Week:</strong> 8 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 8: Your Consecration Ceremony
                </h4>
                <p>
                    This final week is a sacred milestone. You have journeyed from introspection to integration, building a personal framework for a consecrated life. Now, you will give voice to that framework, not as a final exam, but as a public profession of faith and a covenant of action. In the biblical tradition, public declarations solidify private conviction (Romans 10:9-10). This "Pathway Presentation & Commitment Ceremony" is your opportunity to "let your light so shine before men, that they may see your good works, and glorify your Father which is in heaven" (Matthew 5:16). By articulating your insights and your 90-day covenant before peers, you move from being a solitary learner to an accountable member of a community of saints, strengthening both yourself and others. This week is about celebration, testimony, and the holy work of commissioning one another for the ongoing journey.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate with clarity and conviction the core insights from your personal Pathway Document and your 90-Day Integration Covenant.</li>
                    <li>Provide and receive constructive, spiritually-oriented feedback within a community of learners, fulfilling the law of Christ by bearing one another’s burdens (Galatians 6:2).</li>
                    <li>Formalize a forward-looking action plan that leverages course resources for continuous improvement beyond the classroom.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": The Power of a Spoken Covenant</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Words have creative power. "Death and life are in the power of the tongue" (Proverbs 18:21). When you voice your commitment—your understanding of your divine design, your plan to steward it, and your reliance on Christ—you activate a new level of accountability and spiritual grace. This ceremony mirrors the biblical pattern of sharing testimonies to edify the church (1 Corinthians 14:26). Your presentation is not a performance for evaluation, but a sacred offering of your growth and a request for communal support as you "press toward the mark for the prize of the high calling of God in Christ Jesus" (Philippians 3:14).
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. The Foundation: Principles of Effective Reflection & Edifying Feedback</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>Reflective Depth:</strong> Move beyond "what I did" to "how I changed" and "who I am becoming." This is the difference between a summary and a testimony. Connect your practical skill development (e.g., budgeting) to the transformation of your heart and mind (e.g., moving from anxiety to faithful stewardship).
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>The Cycle of Continuous Improvement (The Discipleship Loop):</strong> Frame your future not as a static plan, but as a dynamic, Spirit-led cycle:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Plan:</strong> Your 90-Day Covenant.</li>
                        <li><strong>Act:</strong> Faithful execution.</li>
                        <li><strong>Observe & Reflect:</strong> Weekly review in your Pathway Document.</li>
                        <li><strong>Seek & Adjust:</strong> Prayerful course correction and seeking divine feedback.</li>
                    </ul>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        This loop ensures you are continually "transformed by the renewing of your mind" (Romans 12:2).
                    </p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>Giving Consecrated Feedback:</strong> Peer feedback should be a ministry, not just a critique. It should:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Affirm Strengths:</strong> "I see how your strength in [X] is guiding your plan..."</li>
                        <li><strong>Ask Prophetic Questions:</strong> "Have you considered how [principle Y] might apply if you face that obstacle?"</li>
                        <li><strong>Share Encouragement:</strong> "Your commitment to [Z] inspired me because..."</li>
                    </ul>
                    <p style="margin-left: 15px;">
                        Your goal is to "speak the truth in love" (Ephesians 4:15) to help a brother or sister fortify their covenant.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Consecrated Path: Forward Planning with Eternal Resources</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Your education does not end here; it is consecrated for a lifetime of service. You now own a toolkit and a methodology.
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 10px;">
                        <li><strong>Leveraging Course Materials for Lifelong Growth:</strong> Your Pathway Document, templates, and curated resources are now your personal discipleship library. Schedule quarterly "Deep Learning Reviews" to revisit them, assess your progress, and set new covenants.</li>
                        <li><strong>Accessing Divine Resources for Continuous Improvement:</strong> Remember the core principle from Week 6: the most critical resource is YOU in partnership with God. Your ongoing plan must include the non-negotiable practices of prayer, scripture study, and seeking the Spirit's guidance to "prove what is that good, and acceptable, and perfect, will of God" (Romans 12:2) for your next steps.</li>
                        <li><strong>Building Your Ongoing Support System:</strong> Identify who from this course community you will stay connected with for accountability. Commit to being a resource and encouragement to others, fulfilling the Savior's new commandment to "love one another; as I have loved you" (John 13:34).</li>
                    </ul>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: The Pathway Presentation & Commitment Ceremony</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Prepare Your Presentation (Solo)</p>
                    <p style="margin-left: 15px; margin-bottom: 15px;">
                        Refine the 2-minute verbal commitment statement you drafted in Week 7 into a polished, heartfelt 3-minute "Pathway Presentation." Structure it as follows:
                    </p>
                    <ul style="margin-left: 30px; margin-bottom: 15px;">
                        <li><strong>Opening (30 sec):</strong> Your name and a thematic "hook" (e.g., "My journey through this course has been a lesson in trading fear for faith.").</li>
                        <li><strong>Key Insight (60 sec):</strong> Share one profound, personal interconnection or transformation from your Pathway Document (e.g., how correcting a thinking error freed you to persevere, or how budgeting created mental space for deeper learning).</li>
                        <li><strong>Your 90-Day Covenant (60 sec):</strong> Clearly state the TWO skills you are consecrating and your primary method of application. Share your "why."</li>
                        <li><strong>Closing Testimony & Request (30 sec):</strong> Bear a brief testimony of a gospel principle that undergirds your plan (e.g., grace, stewardship, faith). Conclude with a specific request for support (e.g., "I ask for your prayers as I work to become a more consistent provider," or "I would welcome any insights on deliberate practice.").</li>
                    </ul>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Participate in the Small-Group Ceremony (Live Session)</p>
                    <ul style="margin-left: 30px; margin-bottom: 15px;">
                        <li><strong>Present:</strong> Share your 3-minute presentation with your assigned small group.</li>
                        <li><strong>Listen & Engage:</strong> Listen actively to each peer. Take brief notes.</li>
                        <li><strong>Provide Structured Feedback:</strong> Using the "Consecrated Feedback" model below, you will provide written or verbal feedback for two peers:
                            <ul style="margin-left: 20px; margin-top: 8px;">
                                <li>"One Strength I Observed in Your Covenant Was..." (Affirmation)</li>
                                <li>"One Question or Resource That Came to Mind for You Is..." (Prophetic Question/Resource)</li>
                                <li>"I Was Inspired By..." (Encouragement)</li>
                            </ul>
                        </li>
                    </ul>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 3: Finalize Your Forward Plan (Solo)</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">After the ceremony, in your Pathway Document, create a final section: "My Forward Plan: Resources & Review."</p>
                    <ul style="margin-left: 30px;">
                        <li><strong>Resource Commitment:</strong> List the two most valuable resources from the course (e.g., the Zero-Based Budget template, the Thinking Errors checklist) you commit to using regularly.</li>
                        <li><strong>Review Schedule:</strong> Block out four 90-minute appointments in your calendar over the next year for a "Quarterly Deep Learning Review."</li>
                        <li><strong>Prayer of Dedication:</strong> Write a final prayer of gratitude for your growth and a petition for grace, diligence, and guidance as you move forward.</li>
                    </ul>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>The Testimony of Works:</strong> How did the act of verbally presenting your plan and hearing others' plans affect the solidity of your own commitment? How does this reflect the principle in James 2:18: "Yea, a man may say, Thou hast faith, and I have works: shew me thy faith without thy works, and I will shew thee my faith by my works"?</li>
                    <li><strong>The Body of Christ:</strong> In what way did this small-group ceremony demonstrate the interconnected strength described in 1 Corinthians 12:26: "And whether one member suffer, all the members suffer with it; or one member be honoured, all the members rejoice with it"?</li>
                    <li><strong>The Open-Ended Journey:</strong> Why is it critical that your final Pathway Document is not a "finished product" but a "living document" with a scheduled review process? How does this approach align with the concept of enduring to the end?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Consecration Ceremony</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A formal, communal act of dedicating one’s self, skills, and plans to sacred purposes.</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>The Discipleship Loop</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The continuous cycle of Plan, Act, Observe/Reflect, and Seek/Adjust for lifelong spiritual and temporal growth.</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Consecrated Feedback</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Edifying communication aimed at strengthening another’s commitment and plan by affirming, questioning prophetically, and encouraging.</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Forward Plan</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A living strategy for applying course principles and resources beyond the formal end of instruction.</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>As you presented, did you feel you were reporting on a plan or professing a covenant? What is the difference, and why does it matter?</li>
                    <li>Which piece of feedback you received or gave felt most meaningful? How did it exemplify "speaking the truth in love"?</li>
                    <li>Looking at your scheduled Quarterly Reviews, what will be the first item on the agenda for your first review in 90 days?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Speak from the Heart:</strong> Authenticity trumps polish. Share your real journey, not a perfect one.</li>
                    <li><strong>Listen to Minister:</strong> When listening to peers, pray to discern how you can truly strengthen them.</li>
                    <li><strong>Embrace the Sentiment:</strong> This is a spiritual culmination. Allow yourself to feel the joy, sanctity, and encouragement of the moment.</li>
                    <li><strong>Your Pathway Document is Now Yours:</strong> Download, back up, and print key sections of your Pathway Document. It is your property and your plan.</li>
                </ul>
            </div>
            
            <!-- Course Wrap-Up -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Course Wrap-Up & Commissioning
                </h4>
                <p>
                    You began this course as a student. You conclude it as a consecrated steward, equipped with a profound toolkit: a renewed mind, a strategic plan for your time and resources, resilience against error and discouragement, and a covenant to persist. You are now commissioned to go forth and build—your family, your community, and the kingdom of God. The assessments are complete, but the true application has just begun. We, your instructors, now stand as your fellow laborers. We have great faith in you.
                </p>
                <div style="text-align: center; font-style: italic; margin-top: 15px; padding: 10px; border-left: 3px solid #8B7500; background: #FFF8DC;">
                    "And let us not be weary in well doing: for in due season we shall reap, if we faint not." (Galatians 6:9)
                </div>
            </div>
            
            <!-- Final Blessing -->
            <div style="margin-bottom: 25px; padding: 15px; border: 2px solid #DAA520; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Final Blessing & Resources for Ongoing Growth
                </h4>
                
                <p style="font-weight: bold; margin-top: 15px;">Official Course Conclusion:</p>
                <p>All final assessments, including your Pathway Document submission and participation in the Commitment Ceremony, are due by <strong><?php echo date('F j, Y', strtotime('+7 days')); ?></strong>.</p>
                
                <p style="font-weight: bold; margin-top: 15px;">Staying Connected & Continued Support:</p>
                <ul style="margin-left: 20px;">
                    <li><strong>Alumni Network:</strong> You will be invited to a private online community for Impact Digital Academy graduates for ongoing discussion and networking.</li>
                    <li><strong>Recommended Continuing Resources:</strong> A curated list of books, podcasts, and scriptures for deepening each life skill will be posted in the Course Portal.</li>
                    <li><strong>Spiritual Foundation:</strong> Never forget your first and most vital resource: "But my God shall supply all your need according to his riches in glory by Christ Jesus." (Philippians 4:19). Continue in Him.</li>
                </ul>
                
                <div style="text-align: center; margin-top: 20px; padding: 15px; background: #FFF8DC; border-radius: 5px;">
                    <p style="font-size: 11pt; font-weight: bold; color: #8B7500;">
                        Congratulations, and Godspeed on your consecrated pathway.
                    </p>
                </div>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 20px; font-size: 9pt;">
                <h4 style="color: #8B7500; margin-bottom: 8px; font-size: 11pt;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Virtual Office Hours:</strong> Available for 30 days post-course completion</p>
                <p><strong>Course Portal:</strong> <?php echo BASE_URL; ?>modules/student/portal.php</p>
                <p><strong>Support Email:</strong> lifeskills@impactdigitalacademy.com</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></p>
                <p><strong>Week Completed:</strong> 8 of 8</p>
                <p><strong>Course Completion Date:</strong> <?php echo $currentDate; ?></p>
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
                Week 8 Handout<br>Presentation & Covenant – Sealing Your Commitment
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
                    Week: 8 of 8 - Final Week
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
                    This Week 8 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 8: Presentation & Covenant | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 8 Handout | Student: ' . htmlspecialchars($this->user_email) . ' | Course Completion Week
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
    <title>Week 8: Presentation & Covenant – Impact Digital Academy</title>
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

        .covenant-icon {
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

        .commissioning-box {
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin: 40px 0 20px;
            text-align: center;
        }

        .commissioning-title {
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .final-blessing-box {
            background: white;
            border: 2px solid #DAA520;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
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

        .scripture-box {
            text-align: center;
            font-style: italic;
            padding: 20px;
            margin: 25px 0;
            color: #8B7500;
            font-size: 1.1rem;
            background: #FFF8DC;
            border-left: 5px solid #8B7500;
            border-radius: 0 8px 8px 0;
        }

        .completion-badge {
            display: inline-block;
            background: #8B7500;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
            margin: 10px 0;
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
                <strong>Week 8 Handout:</strong> Presentation & Covenant
            </div>
            <div class="access-badge">
                <?php echo ucfirst($this->user_role); ?> Access
            </div>
            <div class="completion-badge">
                <i class="fas fa-graduation-cap"></i> Final Week
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
            <div class="covenant-icon">
                <i class="fas fa-hands-praying"></i>
            </div>
            <div class="week-title">Week 8 Handout: Presentation & Covenant – Sealing Your Commitment</div>
            <div class="week-subtitle">Your Consecration Ceremony</div>
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
                        <strong>Week:</strong> 8 of 8 - FINAL WEEK
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 8: Your Consecration Ceremony
                </div>
                <p>This final week is a sacred milestone. You have journeyed from introspection to integration, building a personal framework for a consecrated life. Now, you will give voice to that framework, not as a final exam, but as a public profession of faith and a covenant of action. In the biblical tradition, public declarations solidify private conviction (Romans 10:9-10). This "Pathway Presentation & Commitment Ceremony" is your opportunity to "let your light so shine before men, that they may see your good works, and glorify your Father which is in heaven" (Matthew 5:16). By articulating your insights and your 90-day covenant before peers, you move from being a solitary learner to an accountable member of a community of saints, strengthening both yourself and others. This week is about celebration, testimony, and the holy work of commissioning one another for the ongoing journey.</p>
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
                        <li>Articulate with clarity and conviction the core insights from your personal Pathway Document and your 90-Day Integration Covenant.</li>
                        <li>Provide and receive constructive, spiritually-oriented feedback within a community of learners, fulfilling the law of Christ by bearing one another's burdens (Galatians 6:2).</li>
                        <li>Formalize a forward-looking action plan that leverages course resources for continuous improvement beyond the classroom.</li>
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
                        <div class="topic-title">The "Why": The Power of a Spoken Covenant</div>
                        <p>Words have creative power. "Death and life are in the power of the tongue" (Proverbs 18:21). When you voice your commitment—your understanding of your divine design, your plan to steward it, and your reliance on Christ—you activate a new level of accountability and spiritual grace.</p>
                        <p>This ceremony mirrors the biblical pattern of sharing testimonies to edify the church (1 Corinthians 14:26). Your presentation is not a performance for evaluation, but a sacred offering of your growth and a request for communal support as you "press toward the mark for the prize of the high calling of God in Christ Jesus" (Philippians 3:14).</p>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">The Foundation: Principles of Effective Reflection & Edifying Feedback</div>
                        
                        <p><strong>Reflective Depth:</strong> Move beyond "what I did" to "how I changed" and "who I am becoming." This is the difference between a summary and a testimony. Connect your practical skill development (e.g., budgeting) to the transformation of your heart and mind (e.g., moving from anxiety to faithful stewardship).</p>
                        
                        <p><strong>The Cycle of Continuous Improvement (The Discipleship Loop):</strong> Frame your future not as a static plan, but as a dynamic, Spirit-led cycle:</p>
                        <ul style="margin-left: 15px; margin-top: 8px;">
                            <li><strong>Plan:</strong> Your 90-Day Covenant.</li>
                            <li><strong>Act:</strong> Faithful execution.</li>
                            <li><strong>Observe & Reflect:</strong> Weekly review in your Pathway Document.</li>
                            <li><strong>Seek & Adjust:</strong> Prayerful course correction and seeking divine feedback.</li>
                        </ul>
                        <p>This loop ensures you are continually "transformed by the renewing of your mind" (Romans 12:2).</p>
                    </div>
                    
                    <!-- Topic 3 -->
                    <div class="topic-card">
                        <div class="topic-number">3</div>
                        <div class="topic-title">The Consecrated Path: Forward Planning with Eternal Resources</div>
                        <p>Your education does not end here; it is consecrated for a lifetime of service. You now own a toolkit and a methodology.</p>
                        
                        <p><strong>Leveraging Course Materials for Lifelong Growth:</strong> Your Pathway Document, templates, and curated resources are now your personal discipleship library. Schedule quarterly "Deep Learning Reviews" to revisit them, assess your progress, and set new covenants.</p>
                        
                        <p><strong>Accessing Divine Resources for Continuous Improvement:</strong> Remember the core principle from Week 6: the most critical resource is YOU in partnership with God. Your ongoing plan must include the non-negotiable practices of prayer, scripture study, and seeking the Spirit's guidance.</p>
                        
                        <p><strong>Building Your Ongoing Support System:</strong> Identify who from this course community you will stay connected with for accountability. Commit to being a resource and encouragement to others, fulfilling the Savior's new commandment to "love one another; as I have loved you" (John 13:34).</p>
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
                        <i class="fas fa-tasks"></i> Activity: The Pathway Presentation & Commitment Ceremony
                    </div>
                    <p>Follow these steps to apply your Week 8 knowledge:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-microphone"></i> Part 1: Prepare Your Presentation (Solo)
                        </div>
                        
                        <p>Refine the 2-minute verbal commitment statement you drafted in Week 7 into a polished, heartfelt 3-minute "Pathway Presentation." Structure it as follows:</p>
                        
                        <div style="background: #FAFAD2; padding: 15px; border-radius: 5px; margin: 15px 0;">
                            <p><strong>Opening (30 sec):</strong> Your name and a thematic "hook" (e.g., "My journey through this course has been a lesson in trading fear for faith.").</p>
                            <p><strong>Key Insight (60 sec):</strong> Share one profound, personal interconnection or transformation from your Pathway Document (e.g., how correcting a thinking error freed you to persevere, or how budgeting created mental space for deeper learning).</p>
                            <p><strong>Your 90-Day Covenant (60 sec):</strong> Clearly state the TWO skills you are consecrating and your primary method of application. Share your "why."</p>
                            <p><strong>Closing Testimony & Request (30 sec):</strong> Bear a brief testimony of a gospel principle that undergirds your plan (e.g., grace, stewardship, faith). Conclude with a specific request for support (e.g., "I ask for your prayers as I work to become a more consistent provider," or "I would welcome any insights on deliberate practice.").</p>
                        </div>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-users"></i> Part 2: Participate in the Small-Group Ceremony (Live Session)
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Present:</strong> Share your 3-minute presentation with your assigned small group.</li>
                            <li><strong>Listen & Engage:</strong> Listen actively to each peer. Take brief notes.</li>
                            <li><strong>Provide Structured Feedback:</strong> Using the "Consecrated Feedback" model below, you will provide written or verbal feedback for two peers:
                                <div style="background: #FFF8DC; padding: 15px; border-radius: 5px; margin: 10px 0 10px 20px;">
                                    <p><strong>"One Strength I Observed in Your Covenant Was..."</strong> (Affirmation)</p>
                                    <p><strong>"One Question or Resource That Came to Mind for You Is..."</strong> (Prophetic Question/Resource)</p>
                                    <p><strong>"I Was Inspired By..."</strong> (Encouragement)</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 3 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-road"></i> Part 3: Finalize Your Forward Plan (Solo)
                        </div>
                        
                        <p>After the ceremony, in your Pathway Document, create a final section: <strong>"My Forward Plan: Resources & Review."</strong></p>
                        
                        <ol class="step-list">
                            <li><strong>Resource Commitment:</strong> List the two most valuable resources from the course (e.g., the Zero-Based Budget template, the Thinking Errors checklist) you commit to using regularly.</li>
                            <li><strong>Review Schedule:</strong> Block out four 90-minute appointments in your calendar over the next year for a "Quarterly Deep Learning Review."</li>
                            <li><strong>Prayer of Dedication:</strong> Write a final prayer of gratitude for your growth and a petition for grace, diligence, and guidance as you move forward.</li>
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
                        <strong>The Testimony of Works:</strong> How did the act of verbally presenting your plan and hearing others' plans affect the solidity of your own commitment? How does this reflect the principle in James 2:18: "Yea, a man may say, Thou hast faith, and I have works: shew me thy faith without thy works, and I will shew thee my faith by my works"?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Body of Christ:</strong> In what way did this small-group ceremony demonstrate the interconnected strength described in 1 Corinthians 12:26: "And whether one member suffer, all the members suffer with it; or one member be honoured, all the members rejoice with it"?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Open-Ended Journey:</strong> Why is it critical that your final Pathway Document is not a "finished product" but a "living document" with a scheduled review process? How does this approach align with the concept of enduring to the end?
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
                        <div class="term-title">Consecration Ceremony</div>
                        <p>A formal, communal act of dedicating one's self, skills, and plans to sacred purposes.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">The Discipleship Loop</div>
                        <p>The continuous cycle of Plan, Act, Observe/Reflect, and Seek/Adjust for lifelong spiritual and temporal growth.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Consecrated Feedback</div>
                        <p>Edifying communication aimed at strengthening another's commitment and plan by affirming, questioning prophetically, and encouraging.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Forward Plan</div>
                        <p>A living strategy for applying course principles and resources beyond the formal end of instruction.</p>
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
                        As you presented, did you feel you were reporting on a plan or professing a covenant? What is the difference, and why does it matter?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        Which piece of feedback you received or gave felt most meaningful? How did it exemplify "speaking the truth in love"?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        Looking at your scheduled Quarterly Reviews, what will be the first item on the agenda for your first review in 90 days?
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
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <strong>Speak from the Heart:</strong> Authenticity trumps polish. Share your real journey, not a perfect one.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-ear-listen"></i>
                        </div>
                        <div>
                            <strong>Listen to Minister:</strong> When listening to peers, pray to discern how you can truly strengthen them.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-hands"></i>
                        </div>
                        <div>
                            <strong>Embrace the Sentiment:</strong> This is a spiritual culmination. Allow yourself to feel the joy, sanctity, and encouragement of the moment.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div>
                            <strong>Your Pathway Document is Now Yours:</strong> Download, back up, and print key sections of your Pathway Document. It is your property and your plan.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scripture -->
            <div class="scripture-box">
                "And let us not be weary in well doing: for in due season we shall reap, if we faint not." 
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B;">– Galatians 6:9</div>
            </div>

            <!-- Course Wrap-Up -->
            <div class="commissioning-box">
                <div class="commissioning-title">
                    <i class="fas fa-graduation-cap"></i> Course Wrap-Up & Commissioning
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    You began this course as a student. You conclude it as a consecrated steward, equipped with a profound toolkit: a renewed mind, a strategic plan for your time and resources, resilience against error and discouragement, and a covenant to persist. You are now commissioned to go forth and build—your family, your community, and the kingdom of God. The assessments are complete, but the true application has just begun. We, your instructors, now stand as your fellow laborers. We have great faith in you.
                </p>
            </div>

            <!-- Final Blessing -->
            <div class="final-blessing-box">
                <div class="section-title" style="border-bottom: none; margin-bottom: 15px;">
                    <i class="fas fa-blessing"></i> Final Blessing & Resources for Ongoing Growth
                </div>
                
                <div style="margin-bottom: 20px;">
                    <p><strong>Official Course Conclusion:</strong> All final assessments, including your Pathway Document submission and participation in the Commitment Ceremony, are due by <strong><?php echo date('F j, Y', strtotime('+7 days')); ?></strong>.</p>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <p><strong>Staying Connected & Continued Support:</strong></p>
                    <ul style="margin-left: 20px;">
                        <li><strong>Alumni Network:</strong> You will be invited to a private online community for Impact Digital Academy graduates for ongoing discussion and networking.</li>
                        <li><strong>Recommended Continuing Resources:</strong> A curated list of books, podcasts, and scriptures for deepening each life skill will be posted in the Course Portal.</li>
                        <li><strong>Spiritual Foundation:</strong> Never forget your first and most vital resource: "But my God shall supply all your need according to his riches in glory by Christ Jesus." (Philippians 4:19). Continue in Him.</li>
                    </ul>
                </div>
                
                <div style="text-align: center; margin-top: 25px; padding: 20px; background: #FAFAD2; border-radius: 8px;">
                    <p style="font-size: 1.2rem; font-weight: bold; color: #8B7500;">
                        <i class="fas fa-hands-praying"></i> Congratulations, and Godspeed on your consecrated pathway.
                    </p>
                </div>
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
                        <p><strong>Virtual Office Hours:</strong> Available for 30 days post-course completion</p>
                    </div>
                    <div>
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #8B7500; text-decoration: none; font-weight: bold;">Access Portal</a></p>
                        <p><strong>Alumni Support:</strong> lifeskills-alumni@impactdigitalacademy.com</p>
                        <p><strong>General Support:</strong> lifeskills@impactdigitalacademy.com</p>
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/class_home.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-trophy"></i> Complete Course
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 8 Handout: Presentation & Covenant – Sealing Your Commitment</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Life Skills Development Program</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #8B7500;">Course completion accessed on: <?php echo $currentDate; ?></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.8rem; color: #8B7500;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the Life Skills: Constructing Your Personal Pathway course. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.9rem; color: #8B7500;">
                    <i class="fas fa-user-graduate"></i> Student Completion - <?php echo htmlspecialchars($this->user_email); ?>
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
                    console.log('Week 8 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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
    $handout = new LifeSkillsWeek8Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>