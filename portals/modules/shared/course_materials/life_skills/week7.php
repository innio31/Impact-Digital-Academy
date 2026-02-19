<?php
// modules/shared/course_materials/LifeSkills/week7_handout.php

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
 * Life Skills Week 7 Handout Class with PDF Download
 */
class LifeSkillsWeek7Handout
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
            $mpdf->SetTitle('Life Skills Week 7: Integration - The Consecrated Life in Action');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Life Skills, Integration, Consecration, Deep Learning');
            
            // Set metadata
            $mpdf->SetKeywords('Life Skills, Integration, Consecration, Deep Learning, Week 7, Covenant');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week7_Integration_' . date('Y-m-d') . '.pdf';
            
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
                Life Skills Course - Week 7 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                Integration – The Consecrated Life in Action
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
                        <td><strong>Week:</strong> 7 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FAFAD2; border-left: 4px solid #DAA520; border-radius: 5px;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 7!
                </h4>
                <p>
                    Over the past six weeks, you have consecrated your mindset, your time, your thoughts, your finances, and your inner capacity for grit. You have moved from being a passive recipient of life's circumstances to an active steward and builder. This final week is not about learning a new skill, but about achieving a sacred synthesis. We integrate all these principles into a unified, personal framework for lifelong discipleship and impact. True education, as we have learned, is deep learning—a process that involves the whole soul and leads to joy, effective action, and becoming more like our Heavenly Father. This week, you will step back to see the divine pattern in your journey, understand how each skill supports and amplifies the others, and move from being a student of principles to a living testament of them.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate how the core life skills of mindset, learning, time management, cognitive integrity, financial stewardship, and grit interconnect to form a system for righteous action and resilience.</li>
                    <li>Analyze your personal growth through the lens of "deep learning" and identify your primary student-type challenges and how you have begun to overcome them.</li>
                    <li>Commit to applying two specific life skills with deliberate intention over the next 90 days, creating a system for accountability and divine partnership.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">1. The "Why": Deep Learning as Consecration</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Education in the Lord's way is fundamentally an act of conversion. It brings a drive to learn and is a "mighty struggle for perfection," designed not merely to inform you, but to transform you. Each week's topic is a spiritual technology for stewarding a different facet of your divine identity. When integrated, they create a powerful virtuous cycle: a Growth Mindset (Week 2) fuels your Perseverance (Week 6). Effective Time Management (Week 3) creates the space for Deliberate Practice (Week 6) and protects your Financial Plan (Week 5).
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">2. The Foundation: Diagnosing Your Growth – From Student Type to Disciple</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        The four student types—The Doubter, The Student with Misplaced Zeal, The Student Who Is Going It Alone, and The Basic Survivor—are not permanent labels, but snapshots of common struggles in our spiritual and academic progression. Your growth is measured by your movement through and beyond these categories.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">3. The Consecrated Path: Principles for Lifelong Integration</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Integration doesn't happen by accident. It requires intentional design and covenant keeping. Principle 1: See the Interconnected System. Principle 2: Anticipate and Plan for Constraints. Principle 3: Build a Consecrated Support System. Principle 4: Live in the Cycle of Deep Learning.
                    </p>
                    
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 5px;">4. Integration in Action: Your Pathway Document as a Living Covenant</p>
                    <p style="margin-left: 15px;">
                        Your completed Pathway Document is the tangible fruit of this deep learning. It is the blueprint of your consecrated life. This week, you will finalize it, not as an archive of past work, but as a guide for future action. The most critical section will be your new "90-Day Integration Covenant," where you select two life skills to focus on applying with surgical precision.
                    </p>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #FFF8DC; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #8B7500; margin-bottom: 10px;">Activity: Finalize Your Pathway Document & 90-Day Integration Covenant</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Deep Learning Reflection</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Review & Synthesize:</strong> Revisit your key insights from Weeks 1-6. In your Pathway Document, create a new "Week 7: Integration" section.</li>
                        <li><strong>Student Type Analysis:</strong> Reflect on the four student types. Which one did you most identify with at the beginning of the course? Write a brief paragraph describing how you have applied course principles to overcome that specific challenge.</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: The Interconnection Map</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Create a System Diagram:</strong> On a physical page or digital drawing, create a simple map. Place "My Consecrated Life" at the center. Draw lines connecting it to each of the 6 core skills.</li>
                        <li><strong>Analyze Connections:</strong> Choose two key connections and write a sentence explaining their relationship.</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 3: The 90-Day Integration Covenant</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Skill Selection:</strong> Choose TWO life skills from the course you are most committed to actively applying over the next 90 days.</li>
                        <li><strong>Covenant Plan for Each Skill:</strong> Specific Application, Success Metric, Accountability System, Anticipated Constraint & Solution.</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 4: The Verbal Commitment (2-Minute Statement)</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>Structure:</strong> Opening, Insight, Covenant, Closing Testimony.</li>
                        <li><strong>Example:</strong> "My name is [Name], and my deep learning in this course has taught me..."</li>
                    </ol>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-left: 3px solid #DAA520; font-style: italic;">
                        <strong>Example Covenant:</strong> "I covenant with the Lord to focus on applying Financial Stewardship and Time Blocking by holding a weekly 30-minute financial review with my spouse and using time blocking for my top three priorities each day."
                    </div>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Eternal Pattern:</strong> How does the cycle of "Learn, Do, Become" reflected in this course mirror the process of sanctification described in Romans 12:2?</li>
                    <li><strong>Strength in Integration:</strong> Why is an attack on one area of your life often an attack on your entire consecrated system? How does your new understanding help you "put on the whole armour of God"?</li>
                    <li><strong>The Covenant of Education:</strong> How has this course equipped you to better fulfill the commandment to "love the Lord thy God with all thy heart, and with all thy soul, and with all thy mind, and with all thy strength"?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Deep Learning</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Learning of the whole soul that increases our power to know, act righteously, and become more like God</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Integration</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The process of combining separate parts or skills into a unified, functioning whole</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Consecration</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The act of dedicating something to sacred purposes</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Conversion</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A spiritual transformation that changes one's nature, aligning desires and actions with God's will</td>
                    </tr>
                    <tr style="background-color: #FFF8DC;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Covenant Plan</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">A specific, deliberate strategy for personal growth, made as a sacred commitment between oneself and God</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>Looking at your "Interconnection Map," which two skills do you see as the most critical foundation for your current season of life? Why?</li>
                    <li>Of the four student types, which do you believe is the greatest cultural challenge in your community, and how can the principles of this course offer a solution?</li>
                    <li>How will you schedule a quarterly "Deep Learning Review" of your Pathway Document and Integration Covenant to ensure this week's work becomes a lifelong practice?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; font-size: 12pt; border-bottom: 1px solid #E6D690; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Your Document is Alive:</strong> Treat your Pathway Document as a living journal. Plan to add to it, revise it, and refer to it often.</li>
                    <li><strong>Covenant, Not Just Commit:</strong> Frame your 90-day plan as a sacred promise. Pray over it. Write it as if you are writing a covenant with God.</li>
                    <li><strong>Simplicity in Integration:</strong> Your two chosen skills do not need to be complex. Consistent, simple application of a core principle is more powerful than sporadic, complicated efforts.</li>
                    <li><strong>Celebrate the Synthesis:</strong> This week is a milestone. You have equipped yourself with a disciple's toolkit. Take time to acknowledge the transformation that has already begun.</li>
                </ul>
            </div>
            
            <!-- Course Conclusion -->
            <div style="margin-bottom: 25px; padding: 15px; background: #FFF8DC; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #8B7500; margin-top: 0; font-size: 12pt;">
                    Course Conclusion & Continuing the Journey
                </h4>
                <p>
                    You have now been armed with principles of power. The world's approach is fragmented—compartmentalizing faith, work, learning, and life. The consecrated path you have chosen integrates all things into one great whole. Your education, your career, your family, and your service are all strands in the same sacred tapestry. Go forward not as a student who has completed a course, but as a consecrated disciple, a savvy steward, and a resilient builder. Use your toolkit. Revisit your covenants. Deepen your learning. The Lord's promise is sure: "But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint." (Isaiah 40:31).
                </p>
            </div>
            
            <!-- Scriptures -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #FAFAD2; border-radius: 5px;">
                <div style="margin-bottom: 10px;">
                    "Let your light so shine before men, that they may see your good works, and glorify your Father which is in heaven."
                </div>
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Matthew 5:16</div>
                <div style="margin-top: 15px;">
                    "And I now give unto you a commandment to beware concerning yourselves, to give diligent heed to the words of eternal life. For you shall live by every word that proceedeth forth from the mouth of God."
                </div>
                <div style="margin-top: 5px; font-weight: bold; color: #8B7500;">– Deuteronomy 8:3, Matthew 4:4</div>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 20px; font-size: 9pt;">
                <h4 style="color: #8B7500; margin-bottom: 8px; font-size: 11pt;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> <?php echo BASE_URL; ?>modules/student/portal.php</p>
                <p><strong>Support Email:</strong> lifeskills@impactdigitalacademy.com</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></p>
                <p><strong>Week Completed:</strong> 7 of 8</p>
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
                Week 7 Handout<br>Integration – The Consecrated Life in Action
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
                    Week: 7 of 8
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
                    This Week 7 handout is the integration capstone of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 7: Integration – The Consecrated Life in Action | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 7 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 7: Integration – The Consecrated Life in Action – Impact Digital Academy</title>
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

        .integration-icon {
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

        .student-types-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .student-type-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #F0E68C;
            transition: all 0.3s;
        }

        .student-type-card:hover {
            border-color: #DAA520;
            box-shadow: 0 5px 10px rgba(139, 117, 0, 0.1);
        }

        .student-type-title {
            color: #8B7500;
            font-size: 1.1rem;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .principles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .principle-card {
            background: #FAFAD2;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #B8860B;
        }

        .principle-number {
            display: inline-block;
            background: #8B7500;
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            text-align: center;
            line-height: 25px;
            margin-right: 10px;
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

        .conclusion-box {
            background: linear-gradient(135deg, #8B7500 0%, #DAA520 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin: 40px 0 20px;
        }

        .conclusion-title {
            font-size: 1.5rem;
            margin-bottom: 15px;
            text-align: center;
        }

        .scripture-box {
            text-align: center;
            font-style: italic;
            padding: 25px;
            margin: 30px 0;
            color: #8B7500;
            font-size: 1.2rem;
            position: relative;
            background: #FFF8DC;
            border-radius: 8px;
        }

        .scripture-box:before {
            content: "❝";
            font-size: 3rem;
            color: #F0E68C;
            position: absolute;
            top: 0;
            left: 20px;
            opacity: 0.5;
        }

        .scripture-box:after {
            content: "❞";
            font-size: 3rem;
            color: #F0E68C;
            position: absolute;
            bottom: -10px;
            right: 20px;
            opacity: 0.5;
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
            
            .student-types-grid {
                grid-template-columns: 1fr;
            }
            
            .principles-grid {
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
                <strong>Week 7 Handout:</strong> Integration – The Consecrated Life in Action
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
            <div class="integration-icon">
                <i class="fas fa-link"></i>
            </div>
            <div class="week-title">Week 7 Handout: Integration – The Consecrated Life in Action</div>
            <div class="week-subtitle">Sacred Synthesis for Lifelong Discipleship</div>
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
                        <strong>Week:</strong> 7 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 7!
                </div>
                <p>Over the past six weeks, you have consecrated your mindset, your time, your thoughts, your finances, and your inner capacity for grit. You have moved from being a passive recipient of life's circumstances to an active steward and builder. This final week is not about learning a new skill, but about achieving a sacred synthesis. We integrate all these principles into a unified, personal framework for lifelong discipleship and impact. True education, as we have learned, is deep learning—a process that involves the whole soul and leads to joy, effective action, and becoming more like our Heavenly Father. This week, you will step back to see the divine pattern in your journey, understand how each skill supports and amplifies the others, and move from being a student of principles to a living testament of them.</p>
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
                        <li>Articulate how the core life skills of mindset, learning, time management, cognitive integrity, financial stewardship, and grit interconnect to form a system for righteous action and resilience.</li>
                        <li>Analyze your personal growth through the lens of "deep learning" and identify your primary student-type challenges and how you have begun to overcome them.</li>
                        <li>Commit to applying two specific life skills with deliberate intention over the next 90 days, creating a system for accountability and divine partnership.</li>
                    </ul>
                </div>
            </div>

            <!-- Key Topics -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Key Topics Covered
                </div>
                
                <!-- Topic 1 -->
                <div class="topic-card">
                    <div class="topic-number">1</div>
                    <div class="topic-title">The "Why": Deep Learning as Consecration</div>
                    <p>Education in the Lord's way is fundamentally an act of conversion. It brings a drive to learn and is a "mighty struggle for perfection," designed not merely to inform you, but to transform you. Each week's topic is a spiritual technology for stewarding a different facet of your divine identity.</p>
                    <p><strong>The Virtuous Cycle:</strong></p>
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <li><strong>Growth Mindset (Week 2)</strong> fuels your <strong>Perseverance (Week 6)</strong></li>
                        <li><strong>Effective Time Management (Week 3)</strong> creates the space for <strong>Deliberate Practice (Week 6)</strong> and protects your <strong>Financial Plan (Week 5)</strong></li>
                        <li><strong>Correcting Thinking Errors (Week 4)</strong> safeguards your mindset and ensures your grit is directed by truth, not fear</li>
                    </ul>
                    <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px; font-style: italic;">
                        "This integration is the essence of being 'steadfast, unmoveable, always abounding in the work of the Lord' (1 Corinthians 15:58)—the developed capacity to use your agency in partnership with Him to act and not be acted upon."
                    </div>
                </div>
                
                <!-- Topic 2 -->
                <div class="topic-card">
                    <div class="topic-number">2</div>
                    <div class="topic-title">The Foundation: Diagnosing Your Growth – From Student Type to Disciple</div>
                    <p>The four student types are not permanent labels, but snapshots of common struggles in our spiritual and academic progression. Your growth is measured by your movement through and beyond these categories.</p>
                    
                    <div class="student-types-grid">
                        <div class="student-type-card">
                            <div class="student-type-title">The Doubter</div>
                            <p>Healed by the principles of <strong>Mindset & Thinking Errors</strong>, learning to trust that "I can do all things through Christ which strengtheneth me" (Philippians 4:13).</p>
                        </div>
                        
                        <div class="student-type-card">
                            <div class="student-type-title">The Student with Misplaced Zeal</div>
                            <p>Finds purpose through <strong>Integration & Consecration</strong>, understanding that "whether therefore ye eat, or drink, or whatsoever ye do, do all to the glory of God" (1 Corinthians 10:31).</p>
                        </div>
                        
                        <div class="student-type-card">
                            <div class="student-type-title">The Student Who Is Going It Alone</div>
                            <p>Learns the power of <strong>Accountability Systems and Divine Partnership</strong>, heeding the call to "bear ye one another's burdens, and so fulfil the law of Christ" (Galatians 6:2).</p>
                        </div>
                        
                        <div class="student-type-card">
                            <div class="student-type-title">The Basic Survivor</div>
                            <p>Breaks the cycle through <strong>Stewardship & Planning</strong>, acting on the wisdom that "the thoughts of the diligent tend only to plenteousness; but of every one that is hasty only to want" (Proverbs 21:5).</p>
                        </div>
                    </div>
                </div>
                
                <!-- Topic 3 -->
                <div class="topic-card">
                    <div class="topic-number">3</div>
                    <div class="topic-title">The Consecrated Path: Principles for Lifelong Integration</div>
                    <p>Integration doesn't happen by accident. It requires intentional design and covenant keeping.</p>
                    
                    <div class="principles-grid">
                        <div class="principle-card">
                            <span class="principle-number">1</span>
                            <strong>See the Interconnected System</strong>
                            <p>Your life is an ecosystem. "That ye may be perfect and entire, wanting nothing" (James 1:4). How does improving one skill lift another?</p>
                        </div>
                        
                        <div class="principle-card">
                            <span class="principle-number">2</span>
                            <strong>Anticipate and Plan for Constraints</strong>
                            <p>The adversary will target your weakest link. The wise man "foreseeth the evil, and hideth himself" (Proverbs 22:3).</p>
                        </div>
                        
                        <div class="principle-card">
                            <span class="principle-number">3</span>
                            <strong>Build a Consecrated Support System</strong>
                            <p>You are not meant to "go it alone." Divine Partner, Personal Accountability, and Social Scaffolding.</p>
                        </div>
                        
                        <div class="principle-card">
                            <span class="principle-number">4</span>
                            <strong>Live in the Cycle of Deep Learning</strong>
                            <p>The Learning Model (Ponder & Prove) is an eternal pattern. "But be ye doers of the word, and not hearers only" (James 1:22).</p>
                        </div>
                    </div>
                </div>
                
                <!-- Topic 4 -->
                <div class="topic-card">
                    <div class="topic-number">4</div>
                    <div class="topic-title">Integration in Action: Your Pathway Document as a Living Covenant</div>
                    <p>Your completed Pathway Document is the tangible fruit of this deep learning. It is the blueprint of your consecrated life. This week, you will finalize it, not as an archive of past work, but as a guide for future action.</p>
                    <p><strong>The 90-Day Integration Covenant:</strong> The most critical section where you select two life skills to focus on applying with surgical precision. This moves you from theory to discipleship.</p>
                    <div style="margin-top: 15px; padding: 10px; background: #FAFAD2; border-radius: 5px;">
                        <strong>Your Pathway Document is:</strong>
                        <ul style="margin-top: 8px; margin-left: 20px;">
                            <li>A Personal Dashboard for your reflections, plans, and progress</li>
                            <li>A Work in Progress that evolves as you do</li>
                            <li>A Tool for Integration connecting life skills into a cohesive personal plan</li>
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
                        <i class="fas fa-handshake"></i> Activity: Finalize Your Pathway Document & 90-Day Integration Covenant
                    </div>
                    <p>Follow these steps to apply your Week 7 knowledge and complete your journey:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-brain"></i> Part 1: Deep Learning Reflection
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Review & Synthesize:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Revisit your key insights from Weeks 1-6.</li>
                                    <li>In your Pathway Document, create a new "<strong>Week 7: Integration</strong>" section.</li>
                                    <li>For each previous week, write <strong>one sentence</strong> that captures the most important principle you learned for you.</li>
                                </ul>
                            </li>
                            <li><strong>Student Type Analysis:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Reflect on the four student types. Which one did you most identify with at the beginning of the course?</li>
                                    <li>Write a brief paragraph describing how you have applied course principles to overcome that specific challenge.</li>
                                    <li>What evidence shows your growth? Be specific.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-project-diagram"></i> Part 2: The Interconnection Map
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Create a System Diagram:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>On a physical page or digital drawing, create a simple map.</li>
                                    <li>Place "<strong>My Consecrated Life</strong>" at the center.</li>
                                    <li>Draw lines connecting it to each of the 6 core skills (Mindset, Learning, Time, Thinking, Finance, Grit).</li>
                                    <li>On each connecting line, write <strong>how that skill supports the center</strong>.</li>
                                </ul>
                            </li>
                            <li><strong>Analyze Connections:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Draw lines between the skills themselves.</li>
                                    <li>Choose two key connections (e.g., "Time Management ↔ Finance" or "Thinking Errors ↔ Grit").</li>
                                    <li>For each connection, write a sentence explaining their relationship.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 3 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-file-contract"></i> Part 3: The 90-Day Integration Covenant
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Skill Selection:</strong> Choose <strong>TWO</strong> life skills from the course you are most committed to actively applying over the next 90 days.</li>
                            <li><strong>Covenant Plan for Each Skill:</strong> For each skill, define:
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>Specific Application:</strong> How will you practice this skill? (e.g., "I will hold a weekly 30-minute financial review with my spouse every Sunday.")</li>
                                    <li><strong>Success Metric:</strong> How will you know you are faithful? (e.g., "A zero-balance budget is approved before the 1st of each month.")</li>
                                    <li><strong>Accountability System:</strong> Who/what will keep you on track? (e.g., "Shared calendar invite with spouse for finance meeting.")</li>
                                    <li><strong>Anticipated Constraint & Solution:</strong> What obstacle will likely arise? What is your pre-committed response? (e.g., "If I feel too tired for the budget meeting, we will do a 10-minute check-in instead.")</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 4 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-microphone"></i> Part 4: The Verbal Commitment (2-Minute Statement)
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Prepare a 2-minute verbal summary</strong> of your covenant. This is not a presentation of your entire document, but a powerful, personal testimony of your commitment.</li>
                            <li><strong>Structure your statement:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li><strong>Opening:</strong> "My name is [Name], and my deep learning in this course has taught me..."</li>
                                    <li><strong>Insight:</strong> Share one key interconnection you discovered about yourself.</li>
                                    <li><strong>Covenant:</strong> "Therefore, over the next 90 days, I covenant with the Lord to focus on applying [Skill 1] and [Skill 2] by..."</li>
                                    <li><strong>Closing Testimony:</strong> Bear a brief testimony of the principle of consecrated effort and deep learning, rooted in scripture.</li>
                                </ul>
                            </li>
                            <li><strong>Example:</strong>
                                <div style="margin-top: 10px; padding: 15px; background: #FFF8DC; border-left: 3px solid #DAA520; border-radius: 0 5px 5px 0; font-style: italic;">
                                    "My name is Sarah, and my deep learning in this course has taught me that my financial peace directly impacts my mental clarity and spiritual capacity. Therefore, over the next 90 days, I covenant with the Lord to focus on applying Financial Stewardship and Time Blocking by maintaining a zero-based budget with my husband every Sunday and protecting three 90-minute deep work blocks each week for my most important priorities."
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Reflection Prompts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-pray"></i> Reflection Prompts (Ponder & Prove)
                </div>
                
                <div class="reflection-box">
                    <div class="reflection-item">
                        <strong>Eternal Pattern:</strong> How does the cycle of "Learn, Do, Become" reflected in this course mirror the process of sanctification described in Romans 12:2: "And be not conformed to this world: but be ye transformed by the renewing of your mind..."?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Strength in Integration:</strong> Why is an attack on one area of your life (e.g., finances) often an attack on your entire consecrated system? How does your new understanding help you "put on the whole armour of God" (Ephesians 6:11)?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>The Covenant of Education:</strong> How has this course equipped you to better fulfill the commandment to "love the Lord thy God with all thy heart, and with all thy soul, and with all thy mind, and with all thy strength" (Mark 12:30)?
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
                        <div class="term-title">Deep Learning</div>
                        <p>Learning of the whole soul (mind, heart, body, spirit) that increases our power to know, act righteously, and become more like God.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Integration</div>
                        <p>The process of combining separate parts or skills into a unified, functioning whole. In this context, it is the synthesis of spiritual and temporal self-reliance.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Consecration</div>
                        <p>The act of dedicating something to sacred purposes. Here, it is dedicating your skills, time, and efforts to building God's kingdom.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Conversion</div>
                        <p>A spiritual transformation that changes one's nature, aligning desires and actions with God's will. It brings a drive to learn and grow.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Covenant Plan</div>
                        <p>A specific, deliberate strategy for personal growth, made as a sacred commitment between oneself and God, with defined actions and accountability.</p>
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
                        Looking at your "Interconnection Map," which two skills do you see as the most critical foundation for your current season of life? Why?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        Of the four student types, which do you believe is the greatest cultural challenge in your community, and how can the principles of this course offer a solution?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        How will you schedule a quarterly "Deep Learning Review" of your Pathway Document and Integration Covenant to ensure this week's work becomes a lifelong practice, not a final assignment?
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
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <div>
                            <strong>Your Document is Alive:</strong> Treat your Pathway Document as a living journal. Plan to add to it, revise it, and refer to it often. It's your personal handbook for discipleship.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div>
                            <strong>Covenant, Not Just Commit:</strong> Frame your 90-day plan as a sacred promise. Pray over it. Write it as if you are writing a covenant with God. This changes the nature of your commitment.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-stream"></i>
                        </div>
                        <div>
                            <strong>Simplicity in Integration:</strong> Your two chosen skills do not need to be complex. Consistent, simple application of a core principle is more powerful than sporadic, complicated efforts.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div>
                            <strong>Celebrate the Synthesis:</strong> This week is a milestone. You have equipped yourself with a disciple's toolkit. Take time to acknowledge the transformation that has already begun.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course Conclusion -->
            <div class="conclusion-box">
                <div class="conclusion-title">
                    <i class="fas fa-graduation-cap"></i> Course Conclusion & Continuing the Journey
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    You have now been armed with principles of power. The world's approach is fragmented—compartmentalizing faith, work, learning, and life. The consecrated path you have chosen integrates all things into one great whole. Your education, your career, your family, and your service are all strands in the same sacred tapestry. Go forward not as a student who has completed a course, but as a consecrated disciple, a savvy steward, and a resilient builder. Use your toolkit. Revisit your covenants. Deepen your learning. The Lord's promise is sure: "But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint." (Isaiah 40:31).
                </p>
            </div>

            <!-- Scriptures -->
            <div class="scripture-box">
                <div style="margin-bottom: 10px;">
                    "Let your light so shine before men, that they may see your good works, and glorify your Father which is in heaven."
                </div>
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B; font-weight: bold;">– Matthew 5:16</div>
                <div style="margin-top: 20px;">
                    "And I now give unto you a commandment to beware concerning yourselves, to give diligent heed to the words of eternal life. For you shall live by every word that proceedeth forth from the mouth of God."
                </div>
                <div style="margin-top: 10px; font-size: 1rem; color: #B8860B; font-weight: bold;">– Deuteronomy 8:3, Matthew 4:4</div>
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week8_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 8
                    </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/pathway_document.php" class="download-btn" style="background: #8B7500;">
                    <i class="fas fa-book"></i> Access Your Pathway Document
                </a>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 7 Handout: Integration – The Consecrated Life in Action</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Life Skills Development Program</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #8B7500;">Syllabus accessed on: <?php echo $currentDate; ?></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #F0E68C; font-size: 0.8rem; color: #8B7500;">
                <i class="fas fa-exclamation-triangle"></i> This integration capstone is part of the Life Skills: Constructing Your Personal Pathway course. Unauthorized distribution is prohibited.
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

            // Covenant reminder
            const covenantBtn = document.querySelector('[href*="pathway_document.php"]');
            if (covenantBtn) {
                covenantBtn.addEventListener('click', function() {
                    if (confirm("Remember to review and update your Pathway Document with your 90-Day Integration Covenant before proceeding to Week 8. Would you like to continue to your Pathway Document?")) {
                        return true;
                    } else {
                        return false;
                    }
                });
            }

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('Week 7 integration handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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
    $handout = new LifeSkillsWeek7Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>