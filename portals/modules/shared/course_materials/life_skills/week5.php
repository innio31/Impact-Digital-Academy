<?php
// modules/shared/course_materials/LifeSkills/week5_handout.php

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
 * Life Skills Week 5 Handout Class with PDF Download
 */
class LifeSkillsWeek5Handout
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
            
            // Initialize mPDF with theme colors
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
            $mpdf->SetTitle('Life Skills Week 5: Financial Stewardship Principles');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('Financial Stewardship, Budgeting, Debt Management, Self-Reliance');
            
            // Set metadata
            $mpdf->SetKeywords('Financial Stewardship, Budget, Debt, Self-Reliance, Emergency Fund, Tithing, Week 5');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Life_Skills_Week5_Handout_' . date('Y-m-d') . '.pdf';
            
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
                <button onclick="window.print()" style="padding: 10px 20px; background: #006400; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #006400; border-bottom: 3px solid #228B22; padding-bottom: 15px; font-size: 18pt; text-align: center;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #228B22; font-size: 14pt; text-align: center; margin-top: 10px;">
                Life Skills Course - Week 5 Handout
            </h2>
            <h3 style="color: #333; font-size: 16pt; text-align: center; margin-top: 15px; margin-bottom: 20px;">
                The Mind's Wealth – Principles of Financial Stewardship
            </h3>
            
            <!-- Student Info -->
            <div style="margin-bottom: 20px; padding: 15px; background: #F0FFF0; border-radius: 5px; font-size: 9pt;">
                <table style="width: 100%;">
                    <tr>
                        <td><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></td>
                        <td><strong>Email:</strong> <?php echo htmlspecialchars($this->user_email); ?></td>
                        <td><strong>Date:</strong> <?php echo $currentDate; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></td>
                        <td><strong>Week:</strong> 5 of 8</td>
                        <td><strong>Course:</strong> Life Skills Pathway</td>
                    </tr>
                </table>
            </div>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px; padding: 15px; background: #F8FFF8; border-left: 4px solid #228B22; border-radius: 5px;">
                <h4 style="color: #006400; margin-top: 0; font-size: 12pt;">
                    Welcome to Week 5!
                </h4>
                <p>
                    Last week, you worked to consecrate your mind—to transform your thoughts from a battlefield of distortion into a temple of truth. This week, we apply that consecrated mindset to one of the most tangible tests of our discipleship: our finances. Money is not merely a temporal tool; it is a primary means through which we exercise our agency, demonstrate our faith, and build God's kingdom. Poor financial stewardship is often the fruit of unchecked thinking errors—scarcity mentality, entitlement, or short-term justification. Conversely, provident living is the natural result of a mind aligned with eternal principles. This lesson moves beyond basic budgeting to a doctrinal framework for finances. You will learn to see money as a sacred trust, manage it as an act of worship, and thereby liberate yourself from the bondage of debt and anxiety to find true peace and purpose. By the end of this session, you will not just plan a budget; you will craft a financial covenant—a practical plan that reflects your consecrated heart.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="margin-bottom: 25px;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Learning Objectives
                </h4>
                <p>By the end of this week, you will be able to:</p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li>Articulate the doctrinal principle of financial stewardship as a test of faith and priorities, contrasting the "self-reliant" and "common" approaches to money management.</li>
                    <li>Analyze your current financial position by creating a simple budget and a debt inventory, identifying areas of alignment or conflict with stewardship principles.</li>
                    <li>Apply a three-step plan (Protect, Eliminate, Grow) to move toward financial peace, integrating specific strategies like building an emergency fund and the debt roll-over method.</li>
                    <li>Develop a personal financial stewardship plan that prioritizes tithing, future needs, and wise spending, and commit to a system of accountable follow-through.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Key Topics Covered
                </h4>
                
                <div style="margin-top: 15px;">
                    <p style="font-weight: bold; color: #006400; margin-bottom: 5px;">1. The "Why": Finances as Sacred Stewardship</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        Your financial resources are a stewardship from God. How you manage them is a profound expression of your heart and faith (see Matthew 6:21). The "common approach" to finances—paying for current wants and needs first, and saving or donating only what is left—flows from a scarcity mindset. The Lord's "self-reliant approach" invites us to a higher law: 1. Pay the Lord First, 2. Pay for the Future, 3. Pay for Current Needs & Wants. This order is an act of faith that literally consecrates our increase. Financial stewardship is not about wealth; it is about freedom—freeing resources to serve, to build, and to secure the well-being of our families.
                    </p>
                    
                    <p style="font-weight: bold; color: #006400; margin-bottom: 5px;">2. The Foundation: Know Your Reality (Budget & Debt Inventory)</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        You cannot steward wisely what you do not understand. The first step in financial consecration is ruthless honesty. A budget is not a restriction, but a tool for intentional freedom. Create a budget by identifying net income, classifying expenses as fixed or variable, and using a framework like the 50/30/20 Rule as a starting guide. A debt inventory (creditor, balance, interest rate) removes the fog of anxiety and reveals the true cost of debt, empowering you to attack it strategically.
                    </p>
                    
                    <p style="font-weight: bold; color: #006400; margin-bottom: 5px;">3. The Consecrated Path: Protect, Eliminate, Grow</p>
                    <p style="margin-left: 15px; margin-bottom: 10px;">
                        <strong>PHASE 1: PROTECT</strong> - Build an Emergency Fund (3-6 months' expenses) and acquire appropriate insurance.<br>
                        <strong>PHASE 2: ELIMINATE</strong> - Pay extra on debt, choose Snowball or Avalanche method, use Roll-Over Method for momentum.<br>
                        <strong>PHASE 3: GROW</strong> - Pay yourself first, invest in education/skills, set long-term goals, start investing now.
                    </p>
                    
                    <p style="font-weight: bold; color: #006400; margin-bottom: 5px;">4. Stewardship in Action: Making a Budget Stick</p>
                    <p style="margin-left: 15px;">
                        A plan without execution is a thinking error. Set realistic, inspired goals using the Self-Reliance priority checklist. Choose a tracking system (digital tool or physical method). Hold yourself accountable through weekly reviews and family counsel. Most importantly, counsel with the Lord—take your budget, fears, and goals to God in prayer.
                    </p>
                </div>
            </div>
            
            <!-- Practice Exercise -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Step-by-Step Practice Exercise
                </h4>
                
                <div style="margin-top: 15px; background: #F0FFF0; padding: 15px; border-radius: 5px;">
                    <p style="font-weight: bold; color: #006400; margin-bottom: 10px;">Activity: Draft Your Financial Stewardship Covenant</p>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 1: Foundation – Assessment</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>Income & Tithing:</strong> In your Pathway Document, under a new "Week 5" section, list your average monthly net income. Calculate and list your monthly tithing contribution first.</li>
                        <li><strong>Expense Audit:</strong> Track or estimate your last month's spending. Categorize each expense as Need, Want, or Savings/Debt Payment.</li>
                        <li><strong>Debt Inventory:</strong> If you have any debt, create a simple inventory table (Creditor, Balance, Interest Rate, Min. Payment).</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 2: Consecrated Planning – The Three Phases</p>
                    <ol style="margin-left: 20px; margin-bottom: 15px;">
                        <li><strong>PROTECT:</strong> Based on your expense audit, what is your one-month emergency fund goal? Write down one actionable step to start building it.</li>
                        <li><strong>ELIMINATE:</strong> Look at your debt inventory. Calculate interest paid with minimum payments vs. extra payments. Choose Snowball or Avalanche method.</li>
                        <li><strong>GROW:</strong> What is one specific, non-debt savings goal? Write it down. What is one small, weekly habit that could move you toward it?</li>
                    </ol>
                    
                    <p style="font-weight: bold; margin-bottom: 5px;">Part 3: The Covenant – Integration</p>
                    <ol style="margin-left: 20px;">
                        <li><strong>Create a Simple Projected Budget:</strong> Using the Self-Reliant Order, draft a basic monthly budget for a hypothetical post-internship scenario.</li>
                        <li><strong>Reframe a Financial Stressor:</strong> Identify a financial thinking error and write a gospel-based reframe.</li>
                        <li><strong>Prayer of Stewardship:</strong> Write a short prayer offering your financial plan and worries to the Lord.</li>
                    </ol>
                </div>
            </div>
            
            <!-- Reflection Prompts -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Reflection Prompts (Ponder & Prove)
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>The Litmus Test of Faith:</strong> How does paying tithing first fundamentally change your relationship with money and your trust in God's promises? What fear does it require you to overcome?</li>
                    <li><strong>Debt & Bondage:</strong> In what ways does consumer debt limit your agency to serve, to give, and to respond to spiritual promptings? How does a plan to eliminate it feel like a spiritual pursuit?</li>
                    <li><strong>Budget as Revelation:</strong> How can the practical, even mundane, act of tracking expenses and planning a budget become a source of spiritual insight and peace?</li>
                </ul>
            </div>
            
            <!-- Key Terms -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Key Terms to Remember
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt;">
                    <tr style="background-color: #F0FFF0;">
                        <td style="padding: 8px; border: 1px solid #ddd; width: 30%;"><strong>Financial Stewardship</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">The sacred responsibility to manage resources in accordance with God's will</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Self-Reliant Approach</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Gospel-based financial order: 1. Tithing, 2. Savings/Debt, 3. Needs/Wants</td>
                    </tr>
                    <tr style="background-color: #F0FFF0;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Debt Inventory</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Clear list of all debts for strategic repayment planning</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Roll-Over Method</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">Applying payments from paid-off debts to next debts for momentum</td>
                    </tr>
                    <tr style="background-color: #F0FFF0;">
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>Emergency Fund</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">3-6 months' expenses saved for financial protection</td>
                    </tr>
                </table>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Self-Review Questions
                </h4>
                <ol style="margin: 10px 0 10px 20px;">
                    <li>How does the "self-reliant" financial order directly challenge the world's common approach? Which step feels most like an act of faith for you?</li>
                    <li>Why is simply knowing your detailed financial situation a critical first step toward repentance and change in this area?</li>
                    <li>How can applying the "Stop, Think, Act, Reflect" process from Week 4 help you in moments of financial decision-making?</li>
                    <li>What practical system will you use to track your budget and hold yourself accountable?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="margin-bottom: 25px; page-break-inside: avoid;">
                <h4 style="color: #006400; font-size: 12pt; border-bottom: 1px solid #98FB98; padding-bottom: 8px;">
                    Tips for Success
                </h4>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><strong>Start Where You Are:</strong> If in debt, start with a small emergency fund, then attack debt aggressively. Don't be discouraged.</li>
                    <li><strong>Automate Consecration:</strong> Set up automatic transfers for tithing and savings immediately after receiving income.</li>
                    <li><strong>Involve Your Circle:</strong> Counsel with family or accountability partners about financial goals for unity and strength.</li>
                    <li><strong>Practice Grace & Persistence:</strong> Financial turnarounds take time. If you overspend, adjust, reframe, and recommit.</li>
                </ul>
            </div>
            
            <!-- Quote -->
            <div style="text-align: center; font-style: italic; margin: 25px 0; padding: 15px; background: #F0FFF0; border-radius: 5px;">
                "But seek ye first the kingdom of God, and his righteousness; and all these things shall be added unto you." 
                <div style="margin-top: 5px; font-weight: bold; color: #006400;">– Matthew 6:33</div>
            </div>
            
            <!-- Next Week Preview -->
            <div style="margin-bottom: 25px; padding: 15px; background: #F8FFF8; border-radius: 5px; page-break-inside: avoid;">
                <h4 style="color: #006400; margin-top: 0; font-size: 12pt;">
                    Next Week Preview
                </h4>
                <p>
                    Having consecrated your mind, time, and means (Weeks 3-5), you are now prepared to look outward. Week 6 begins our "Impact" module by focusing on Digital Communication. We will explore how to use technology—social media, email, and digital presentations—as a tool for authentic connection, professional outreach, and sharing light, ensuring your online presence reflects your consecrated identity.
                </p>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 20px; font-size: 9pt;">
                <h4 style="color: #006400; margin-bottom: 8px; font-size: 11pt;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Course Portal:</strong> <?php echo BASE_URL; ?>modules/student/portal.php</p>
                <p><strong>Support Email:</strong> lifeskills@impactdigitalacademy.com</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($studentName); ?></p>
                <p><strong>Week Completed:</strong> 5 of 8</p>
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
            <h1 style="color: #006400; font-size: 22pt; margin-bottom: 15px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #228B22; font-size: 16pt; margin-bottom: 20px;">
                Life Skills: Constructing Your Personal Pathway
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #006400; 
                border-bottom: 3px solid #006400; padding: 20px 0; margin: 30px 0;">
                Week 5 Handout<br>The Mind\'s Wealth – Principles of Financial Stewardship
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
                    Week: 5 of 8
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
                    This Week 5 handout is part of the Life Skills Personal Pathway Program. Unauthorized distribution is prohibited.
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
            Life Skills Week 5: Financial Stewardship Principles | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 7pt; color: #666;">
            Page {PAGENO} of {nbpg} | Week 5 Handout | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 5: Financial Stewardship Principles – Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #006400 0%, #228B22 100%);
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
            background: linear-gradient(135deg, #006400 0%, #228B22 100%);
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

        .wealth-icon {
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
            color: #006400;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #90EE90;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #228B22;
        }

        .welcome-box {
            background: #F0FFF0;
            padding: 25px;
            border-radius: 8px;
            border-left: 5px solid #228B22;
            margin-bottom: 30px;
        }

        .welcome-title {
            color: #006400;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, #228B22, transparent);
            margin: 30px 0;
        }

        .objectives-box {
            background: #F8FFF8;
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
            color: #006400;
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
            background: #F0FFF0;
            padding: 25px;
            border-radius: 8px;
            border-top: 4px solid #228B22;
            transition: all 0.3s;
        }

        .topic-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 100, 0, 0.1);
        }

        .topic-number {
            display: inline-block;
            background: #006400;
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
            color: #006400;
            font-size: 1.2rem;
            margin-bottom: 15px;
        }

        .exercise-box {
            background: #F8FFF8;
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
            border-left: 5px solid #006400;
        }

        .exercise-title {
            color: #006400;
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
            border: 1px solid #90EE90;
        }

        .part-title {
            color: #006400;
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
            background: #228B22;
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
            background: #F0FFF0;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 5px solid #006400;
        }

        .reflection-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px dashed #98FB98;
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
            border: 1px solid #90EE90;
            transition: all 0.3s;
        }

        .term-card:hover {
            border-color: #228B22;
            box-shadow: 0 5px 10px rgba(0, 100, 0, 0.1);
        }

        .term-title {
            color: #006400;
            font-size: 1.1rem;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .questions-box {
            background: #F8FFF8;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
        }

        .question-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #90EE90;
        }

        .question-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .question-number {
            display: inline-block;
            background: #006400;
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
            background: #F0FFF0;
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
            color: #006400;
            font-size: 1.2rem;
            margin-top: 3px;
        }

        .preview-box {
            background: linear-gradient(135deg, #006400 0%, #228B22 100%);
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
            border-top: 3px solid #006400;
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
            color: #006400;
            font-size: 1.2rem;
            position: relative;
        }

        .quote-box:before {
            content: "❝";
            font-size: 3rem;
            color: #90EE90;
            position: absolute;
            top: 0;
            left: 20px;
            opacity: 0.5;
        }

        .quote-box:after {
            content: "❞";
            font-size: 3rem;
            color: #90EE90;
            position: absolute;
            bottom: -10px;
            right: 20px;
            opacity: 0.5;
        }

        footer {
            text-align: center;
            padding: 30px;
            background-color: #F0FFF0;
            color: #006400;
            font-size: 0.9rem;
            border-top: 1px solid #90EE90;
        }

        .download-btn {
            display: inline-block;
            background: #228B22;
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
            background: #006400;
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

        .budget-example {
            background: #F8FFF8;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #228B22;
        }

        .budget-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .budget-table th, .budget-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .budget-table th {
            background: #006400;
            color: white;
        }

        .budget-table tr:nth-child(even) {
            background: #F0FFF0;
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
            
            .budget-table {
                font-size: 0.9rem;
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
                <strong>Week 5 Handout:</strong> Financial Stewardship Principles
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
            <div class="wealth-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="week-title">Week 5 Handout: The Mind's Wealth – Principles of Financial Stewardship</div>
            <div class="week-subtitle">Consecrating Your Means for Eternal Prosperity</div>
        </div>

        <div class="content">
            <!-- Student Info -->
            <div style="background: #F0FFF0; padding: 15px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid #228B22;">
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
                        <strong>Week:</strong> 5 of 8
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-box">
                <div class="welcome-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 5!
                </div>
                <p>Last week, you worked to consecrate your mind—to transform your thoughts from a battlefield of distortion into a temple of truth. This week, we apply that consecrated mindset to one of the most tangible tests of our discipleship: our finances. Money is not merely a temporal tool; it is a primary means through which we exercise our agency, demonstrate our faith, and build God's kingdom. Poor financial stewardship is often the fruit of unchecked thinking errors—scarcity mentality, entitlement, or short-term justification. Conversely, provident living is the natural result of a mind aligned with eternal principles. This lesson moves beyond basic budgeting to a doctrinal framework for finances. You will learn to see money as a sacred trust, manage it as an act of worship, and thereby liberate yourself from the bondage of debt and anxiety to find true peace and purpose. By the end of this session, you will not just plan a budget; you will craft a financial covenant—a practical plan that reflects your consecrated heart.</p>
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
                        <li>Articulate the doctrinal principle of financial stewardship as a test of faith and priorities, contrasting the "self-reliant" and "common" approaches to money management.</li>
                        <li>Analyze your current financial position by creating a simple budget and a debt inventory, identifying areas of alignment or conflict with stewardship principles.</li>
                        <li>Apply a three-step plan (Protect, Eliminate, Grow) to move toward financial peace, integrating specific strategies like building an emergency fund and the debt roll-over method.</li>
                        <li>Develop a personal financial stewardship plan that prioritizes tithing, future needs, and wise spending, and commit to a system of accountable follow-through.</li>
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
                        <div class="topic-title">The "Why": Finances as Sacred Stewardship</div>
                        <p>Your financial resources are a stewardship from God. How you manage them is a profound expression of your heart and faith (see Matthew 6:21). The "common approach" to finances—paying for current wants and needs first, and saving or donating only what is left—flows from a scarcity mindset and places us in spiritual and temporal vulnerability.</p>
                        <p style="margin-top: 10px;"><strong>The Lord's "self-reliant approach" invites us to a higher law:</strong></p>
                        <ol style="margin: 10px 0 10px 20px;">
                            <li><strong>Pay the Lord First</strong> (Tithing & Offerings)</li>
                            <li><strong>Pay for the Future</strong> (Savings, Emergency Fund, Debt Reduction)</li>
                            <li><strong>Pay for Current Needs & Wants</strong></li>
                        </ol>
                        <div style="margin-top: 15px; padding: 10px; background: #F8FFF8; border-radius: 5px;">
                            <strong>Promise:</strong> This order is an act of faith that literally consecrates our increase and, as promised, allows the remaining nine-tenths to accomplish more. Financial stewardship is not about wealth; it is about freedom—freeing resources to serve, to build, and to secure the well-being of our families.
                        </div>
                    </div>
                    
                    <!-- Topic 2 -->
                    <div class="topic-card">
                        <div class="topic-number">2</div>
                        <div class="topic-title">The Foundation: Know Your Reality (Budget & Debt Inventory)</div>
                        <p>You cannot steward wisely what you do not understand. The first step in financial consecration is ruthless honesty.</p>
                        
                        <p><strong>Budget: Your Plan for Agency</strong></p>
                        <p>A budget is your financial plan. It is not a restriction, but a tool for intentional freedom. To create one:</p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Identify <strong>Net Income</strong> (what you actually take home)</li>
                            <li>Classify Expenses as <strong>Fixed</strong> (rent, loan payments) or <strong>Variable</strong> (food, entertainment)</li>
                            <li>Use a framework like the <strong>50/30/20 Rule</strong> (50% Needs, 30% Wants, 20% Savings/Debt) as a starting guide</li>
                        </ul>
                        
                        <p><strong>Debt Inventory: Your Map to Liberation</strong></p>
                        <p>Debt is often spiritual bondage made tangible. Create a clear inventory:</p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Creditor, Balance, Minimum Payment, Interest Rate</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background: #F8FFF8; border-radius: 5px;">
                            <strong>Benefit:</strong> This inventory removes the fog of anxiety and reveals the true cost of debt, empowering you to attack it strategically.
                        </div>
                    </div>
                    
                    <!-- Topic 3 -->
                    <div class="topic-card">
                        <div class="topic-number">3</div>
                        <div class="topic-title">The Consecrated Path: Protect, Eliminate, Grow</div>
                        <p>Following the Self-Reliance map leads to peace. This is a three-phase journey of stewardship.</p>
                        
                        <p><strong>PHASE 1: PROTECT Yourself & Family from Hardship</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Build an Emergency Fund:</strong> Start with a goal of one month's living expenses, then build to 3-6 months. This is your "storehouse" against life's "famine"</li>
                            <li><strong>Acquire Appropriate Insurance:</strong> Prayerfully consider Health, Life/Disability, and Property insurance as layers of protection</li>
                        </ul>
                        
                        <p><strong>PHASE 2: ELIMINATE Debt</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Action 1:</strong> Pay Extra - Any amount beyond minimum attacks principal</li>
                            <li><strong>Action 2:</strong> Choose a Strategy - Debt Snowball or Debt Avalanche</li>
                            <li><strong>Action 3:</strong> Use the Roll-Over Method for momentum</li>
                        </ul>
                        
                        <p><strong>PHASE 3: GROW & Invest for the Future</strong></p>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li><strong>Principle 1:</strong> Pay Yourself - Treat savings as non-negotiable</li>
                            <li><strong>Principle 2:</strong> Invest in Your Greatest Asset—You (education & skills)</li>
                            <li><strong>Principle 3:</strong> Look Ahead - Set long-term goals and start now</li>
                        </ul>
                    </div>
                    
                    <!-- Topic 4 -->
                    <div class="topic-card">
                        <div class="topic-number">4</div>
                        <div class="topic-title">Stewardship in Action: Making a Budget Stick</div>
                        <p>A plan without execution is a thinking error (Powerlessness/Justification). To implement your financial covenant:</p>
                        
                        <ul style="margin-top: 10px;">
                            <li><strong>Set Realistic, Inspired Goals:</strong> Use the Self-Reliance priority checklist to identify your "next right step."</li>
                            <li><strong>Choose a Tracking System:</strong> Use a digital tool (spreadsheet, bank app) or a physical method (like the Envelope System). The best system is the one you will use consistently.</li>
                            <li><strong>Hold Yourself Accountable:</strong> Review your budget weekly, counsel with family, and adjust. Honest reflection turns mistakes into wisdom.</li>
                            <li><strong>Counsel with the Lord:</strong> Take your budget, your fears, and your goals to God in prayer. Seek His guidance on spending, earning, and giving. He is your senior partner in this stewardship (see D&C 84:88).</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Budget Example -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-table"></i> Budget Example: Self-Reliant Approach
                </div>
                
                <div class="budget-example">
                    <p><strong>Monthly Net Income: $2,500</strong> (Hypothetical post-internship scenario)</p>
                    
                    <table class="budget-table">
                        <thead>
                            <tr>
                                <th>Priority</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td><strong>Tithing & Offerings</strong></td>
                                <td>$250</td>
                                <td>10%</td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><strong>Emergency Fund/Savings</strong></td>
                                <td>$375</td>
                                <td>15%</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong>Rent/Housing</strong></td>
                                <td>$750</td>
                                <td>30%</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong>Food & Groceries</strong></td>
                                <td>$300</td>
                                <td>12%</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong>Utilities & Phone</strong></td>
                                <td>$150</td>
                                <td>6%</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong>Transportation</strong></td>
                                <td>$100</td>
                                <td>4%</td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong>Personal/Entertainment</strong></td>
                                <td>$250</td>
                                <td>10%</td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong>Clothing & Personal Care</strong></td>
                                <td>$125</td>
                                <td>5%</td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong>Education/Professional Development</strong></td>
                                <td>$100</td>
                                <td>4%</td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong>Charitable Giving (Beyond Tithing)</strong></td>
                                <td>$100</td>
                                <td>4%</td>
                            </tr>
                            <tr style="font-weight: bold; background: #F0FFF0;">
                                <td colspan="2"><strong>TOTAL</strong></td>
                                <td><strong>$2,500</strong></td>
                                <td><strong>100%</strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p style="margin-top: 15px; font-style: italic; color: #006400;">
                        <i class="fas fa-lightbulb"></i> <strong>Note:</strong> This is a sample budget. Your percentages will vary based on your circumstances, but the priority order (Tithing → Savings → Needs → Wants) remains constant.
                    </p>
                </div>
            </div>

            <!-- Practice Exercise -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-laptop-code"></i> Step-by-Step Practice Exercise
                </div>
                
                <div class="exercise-box">
                    <div class="exercise-title">
                        <i class="fas fa-file-contract"></i> Activity: Draft Your Financial Stewardship Covenant
                    </div>
                    <p>Follow these steps to apply your Week 5 knowledge and create your financial covenant:</p>
                    
                    <!-- Part 1 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-clipboard-check"></i> Part 1: Foundation – Assessment
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Income & Tithing:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>In your Pathway Document, under a new "Week 5" section, list your average monthly net income.</li>
                                    <li>Calculate and list your monthly tithing contribution first.</li>
                                </ul>
                            </li>
                            <li><strong>Expense Audit:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Track or estimate your last month's spending. Be honest and thorough.</li>
                                    <li>Categorize each expense as <strong>Need</strong>, <strong>Want</strong>, or <strong>Savings/Debt Payment</strong>.</li>
                                </ul>
                            </li>
                            <li><strong>Debt Inventory:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>If you have any debt, create a simple inventory table with these columns: <strong>Creditor | Balance | Interest Rate | Min. Payment</strong>.</li>
                                    <li>If no debt, write "No current debt" and note any potential future debt you want to avoid.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 2 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-chart-line"></i> Part 2: Consecrated Planning – The Three Phases
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>PROTECT:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Based on your expense audit, what is your one-month emergency fund goal?</li>
                                    <li>Write down one actionable step to start building it (e.g., "Save $X from next paycheck," "Reduce eating out by $Y this month").</li>
                                </ul>
                            </li>
                            <li><strong>ELIMINATE:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Look at your debt inventory. Using a debt calculator (available online), calculate how much interest you will pay by making only minimum payments.</li>
                                    <li>Calculate the savings if you paid an extra $50/month toward your highest-interest debt.</li>
                                    <li>What is your targeted debt repayment strategy? <strong>Snowball</strong> (lowest balance first) or <strong>Avalanche</strong> (highest interest first)?</li>
                                </ul>
                            </li>
                            <li><strong>GROW:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>What is one specific, non-debt savings goal you have (e.g., education fund, future mission, down payment, starting a business)? Write it down.</li>
                                    <li>What is one small, weekly habit that could move you toward it (e.g., "Save $10/week," "Learn one new financial skill per month")?</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- Part 3 -->
                    <div class="part-box">
                        <div class="part-title">
                            <i class="fas fa-hands-praying"></i> Part 3: The Covenant – Integration
                        </div>
                        
                        <ol class="step-list">
                            <li><strong>Create a Simple Projected Budget:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Using the <strong>Self-Reliant Order</strong>, draft a basic monthly budget for a hypothetical post-internship scenario (e.g., $2,500 net income).</li>
                                    <li>Allocate funds in this order: a) Tithing, b) Emergency Fund/Savings, c) Needs (Rent, Food, etc.), d) Wants.</li>
                                    <li>Use the budget example above as a guide, but personalize it for your expected situation.</li>
                                </ul>
                            </li>
                            <li><strong>Reframe a Financial Stressor:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Identify a financial thinking error you may have (e.g., "I'll never get out of debt" - Powerlessness, "I deserve this treat" - Justification).</li>
                                    <li>Write a gospel-based reframe (e.g., "With discipline and God's help, I can overcome this one step at a time. My liberation is a matter of faith and consistent action.").</li>
                                </ul>
                            </li>
                            <li><strong>Prayer of Stewardship:</strong>
                                <ul style="margin-top: 8px; margin-left: 20px;">
                                    <li>Write a short prayer offering your financial plan and worries to the Lord.</li>
                                    <li>Ask for wisdom to see money as a tool for good and for strength to follow your covenant.</li>
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
                        <strong>The Litmus Test of Faith:</strong> How does paying tithing first fundamentally change your relationship with money and your trust in God's promises? What fear does it require you to overcome? Consider keeping a small journal for one month noting any spiritual or practical blessings that come from putting God first in your finances.
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Debt & Bondage:</strong> In what ways does consumer debt limit your agency to serve, to give, and to respond to spiritual promptings? How does having a concrete plan to eliminate debt feel like a spiritual pursuit rather than just a financial one? How might financial freedom increase your capacity to help others?
                    </div>
                    
                    <div class="reflection-item">
                        <strong>Budget as Revelation:</strong> How can the practical, even mundane, act of tracking expenses and planning a budget become a source of spiritual insight and peace? Have you ever received guidance or answers to prayer while working on your finances? How might financial order bring mental and spiritual clarity?
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
                        <div class="term-title">Financial Stewardship</div>
                        <p>The sacred responsibility to manage the resources God has entrusted to us in accordance with His will, prioritizing His kingdom, our future needs, and wise present use.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Self-Reliant Approach</div>
                        <p>The gospel-based financial order: 1. Pay the Lord (Tithing), 2. Pay the Future (Savings/Debt), 3. Pay the Present (Needs/Wants).</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Debt Inventory</div>
                        <p>A clear list of all debts, detailing creditor, balance, interest rate, and minimum payment, used to formulate a strategic repayment plan.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Roll-Over Method</div>
                        <p>A debt repayment strategy where the monthly payment from a paid-off debt is applied to the next debt on the list, creating increasing momentum toward freedom.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Emergency Fund</div>
                        <p>A dedicated savings buffer (goal of 3-6 months' expenses) to provide peace and protection against unforeseen financial crises.</p>
                    </div>
                    
                    <div class="term-card">
                        <div class="term-title">Debt Snowball vs. Avalanche</div>
                        <p><strong>Snowball:</strong> Pay lowest balance first for psychological wins. <strong>Avalanche:</strong> Pay highest interest rate first for mathematical efficiency.</p>
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
                        How does the "self-reliant" financial order directly challenge the world's common approach? Which step in that order feels most like an act of faith for you right now?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">2</span>
                        Why is simply knowing your detailed financial situation (via budget and debt inventory) a critical first step toward repentance and change in this area?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">3</span>
                        How can applying the "Stop, Think, Act, Reflect" process from Week 4 help you in moments of financial decision-making or impulse spending?
                    </div>
                    
                    <div class="question-item">
                        <span class="question-number">4</span>
                        What practical system will you use to track your budget and hold yourself accountable? How will you involve others (family, trusted friends, God) in this stewardship?
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
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <strong>Start Where You Are:</strong> If you are in debt, your first "savings" goal is your emergency fund starter ($500-$1000), then aggressively attack debt. Don't be discouraged by where you start; celebrate every step forward.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div>
                            <strong>Automate Consecration:</strong> Set up automatic transfers for tithing and savings immediately after you receive income. This removes temptation and makes stewardship a default, not a decision.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <strong>Involve Your Circle:</strong> If applicable, counsel with your family or an accountability partner about your financial goals. Unity brings strength, creativity, and mutual support when temptations arise.
                        </div>
                    </div>
                    
                    <div class="tip-item">
                        <div class="tip-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <strong>Practice Grace & Persistence:</strong> Financial turnarounds take time. If you overspend one week, repent (adjust next week's plan), reframe, and recommit. This is a marathon of discipleship, not a sprint.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quote -->
            <div class="quote-box">
                "But seek ye first the kingdom of God, and his righteousness; and all these things shall be added unto you." 
                <div style="margin-top: 10px; font-size: 1rem; color: #228B22;">– Matthew 6:33</div>
            </div>

            <!-- Next Week Preview -->
            <div class="preview-box">
                <div class="preview-title">
                    <i class="fas fa-arrow-right"></i> Next Week Preview
                </div>
                <p style="font-size: 1.1rem; line-height: 1.6;">
                    Having consecrated your mind, time, and means (Weeks 3-5), you are now prepared to look outward. Week 6 begins our "Impact" module by focusing on Digital Communication. We will explore how to use technology—social media, email, and digital presentations—as a tool for authentic connection, professional outreach, and sharing light, ensuring your online presence reflects your consecrated identity.
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
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #006400; text-decoration: none; font-weight: bold;">Access Portal</a></p>
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
                    <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/classes/week6_materials.php?id=<?php echo $this->class_id; ?>" class="download-btn">
                        <i class="fas fa-arrow-right"></i> Go to Week 6
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <p><strong>Life Skills Course - Week 5 Handout: The Mind's Wealth – Principles of Financial Stewardship</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Life Skills Development Program</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #006400;">Syllabus accessed on: <?php echo $currentDate; ?></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #90EE90; font-size: 0.8rem; color: #006400;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the Life Skills: Constructing Your Personal Pathway course. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #90EE90; font-size: 0.9rem; color: #006400;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #90EE90; font-size: 0.9rem; color: #006400;">
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

            // Debt calculator simulation
            const calculateDebt = () => {
                // This would be connected to actual form inputs in a real implementation
                console.log('Debt calculation functionality would be implemented here');
            };

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('Week 5 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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
    $handout = new LifeSkillsWeek5Handout();
    $handout->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>