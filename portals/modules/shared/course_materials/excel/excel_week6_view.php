<?php
// modules/shared/course_materials/MSExcel/excel_week6_view.php

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
 * Excel Week 6 Handout Viewer Class with PDF Download
 */
class ExcelWeek6HandoutViewer
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
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
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
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to Excel courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to Excel courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft Excel (Office 2019)%'";
        
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
                'tempDir' => sys_get_temp_dir() // Set temp directory
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
            $mpdf->SetTitle('Week 6: Text Functions & Data Transformation');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Text Functions, Data Cleaning, CONCAT, TEXTJOIN, LEFT, RIGHT, MID');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week6_TextFunctions_' . date('Y-m-d') . '.pdf';
            
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
                <button onclick="window.print()" style="padding: 10px 20px; background: #0d3d8c; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #107c10; border-bottom: 2px solid #107c10; padding-bottom: 10px; font-size: 18pt;">
                Week 6: Text Functions & Data Transformation
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 6!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll learn how to manipulate and transform text data using Excel's powerful text functions. These tools are essential for cleaning, formatting, and restructuring data—especially when working with imported files, names, addresses, IDs, and other text-based information.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Use LEFT, RIGHT, and MID functions to extract text</li>
                    <li>Transform text case with UPPER, LOWER, and PROPER</li>
                    <li>Combine text from multiple cells using CONCAT and TEXTJOIN</li>
                    <li>Clean and format messy data efficiently</li>
                    <li>Measure text length with LEN function</li>
                    <li>Apply text functions to real-world data scenarios</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Introduction to Text Functions</h3>
                <p><strong>Why Use Text Functions?</strong></p>
                <ul>
                    <li>Clean inconsistent data (e.g., extra spaces, mixed case)</li>
                    <li>Extract parts of strings (e.g., first name from full name)</li>
                    <li>Combine data from multiple columns</li>
                    <li>Prepare data for reports, mailing lists, or analysis</li>
                    <li>Standardize formats for databases and systems</li>
                </ul>
                
                <p><strong>Common Text Function Syntax:</strong></p>
                <div style="background: #f5f5f5; padding: 10px; border-left: 4px solid #107c10; margin: 10px 0;">
                    <code>=FUNCTION(text, [start_num], [num_chars])</code>
                </div>
                <ul>
                    <li><strong>text:</strong> The cell or string you're working with</li>
                    <li><strong>start_num:</strong> Position to start extraction (1 = first character)</li>
                    <li><strong>num_chars:</strong> Number of characters to extract or manipulate</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Extracting Text with LEFT, RIGHT, and MID</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 20%;">Function</th>
                            <th style="padding: 8px; text-align: left; width: 30%;">Purpose</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Syntax</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">LEFT</td>
                            <td style="padding: 6px 8px;">Extracts characters from the start of text</td>
                            <td style="padding: 6px 8px;"><code>=LEFT(text, num_chars)</code></td>
                            <td style="padding: 6px 8px;"><code>=LEFT("Excel", 2)</code> → "Ex"</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">RIGHT</td>
                            <td style="padding: 6px 8px;">Extracts characters from the end of text</td>
                            <td style="padding: 6px 8px;"><code>=RIGHT(text, num_chars)</code></td>
                            <td style="padding: 6px 8px;"><code>=RIGHT("Excel", 3)</code> → "cel"</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">MID</td>
                            <td style="padding: 6px 8px;">Extracts characters from the middle of text</td>
                            <td style="padding: 6px 8px;"><code>=MID(text, start_num, num_chars)</code></td>
                            <td style="padding: 6px 8px;"><code>=MID("Microsoft", 6, 4)</code> → "soft"</td>
                        </tr>
                    </tbody>
                </table>
                
                <p><strong>Practical Examples:</strong></p>
                <ul>
                    <li>Extract Area Code from Phone Number: <code>=LEFT(A2, 3)</code> if phone = "(123)456-7890"</li>
                    <li>Extract Last 4 Digits of ID: <code>=RIGHT(B2, 4)</code></li>
                    <li>Extract Month from Date String: <code>=MID(C2, 4, 2)</code> if date = "01/15/2024"</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Changing Case with UPPER, LOWER and Measuring Length with LEN</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 20%;">Function</th>
                            <th style="padding: 8px; text-align: left; width: 30%;">Purpose</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Syntax</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">UPPER</td>
                            <td style="padding: 6px 8px;">Converts text to ALL CAPS</td>
                            <td style="padding: 6px 8px;"><code>=UPPER(text)</code></td>
                            <td style="padding: 6px 8px;"><code>=UPPER("hello")</code> → "HELLO"</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">LOWER</td>
                            <td style="padding: 6px 8px;">Converts text to all lowercase</td>
                            <td style="padding: 6px 8px;"><code>=LOWER(text)</code></td>
                            <td style="padding: 6px 8px;"><code>=LOWER("HELLO")</code> → "hello"</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">LEN</td>
                            <td style="padding: 6px 8px;">Returns the number of characters in text</td>
                            <td style="padding: 6px 8px;"><code>=LEN(text)</code></td>
                            <td style="padding: 6px 8px;"><code>=LEN("Excel")</code> → 5</td>
                        </tr>
                    </tbody>
                </table>
                
                <p><strong>Practical Examples:</strong></p>
                <ul>
                    <li>Standardize Names: <code>=PROPER(A2)</code> (not in MO-200 but useful)</li>
                    <li>UPPER for consistency in codes: <code>=UPPER(A2)</code> for SKU codes</li>
                    <li>Check Data Length: <code>=LEN(B2)</code> to validate input length (e.g., ZIP codes, IDs)</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">4. Combining Text with CONCAT and TEXTJOIN</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 20%;">Function</th>
                            <th style="padding: 8px; text-align: left; width: 30%;">Purpose</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Syntax</th>
                            <th style="padding: 8px; text-align: left; width: 25%;">Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">CONCAT</td>
                            <td style="padding: 6px 8px;">Combines text from multiple cells/ranges</td>
                            <td style="padding: 6px 8px;"><code>=CONCAT(text1, [text2], ...)</code></td>
                            <td style="padding: 6px 8px;"><code>=CONCAT(A2, " ", B2)</code></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px; font-weight: bold;">TEXTJOIN</td>
                            <td style="padding: 6px 8px;">Combines text with a delimiter, can ignore empty cells</td>
                            <td style="padding: 6px 8px;"><code>=TEXTJOIN(delimiter, ignore_empty, text1, [text2], ...)</code></td>
                            <td style="padding: 6px 8px;"><code>=TEXTJOIN(", ", TRUE, A2, B2, C2)</code></td>
                        </tr>
                    </tbody>
                </table>
                
                <p><strong>Key Differences:</strong></p>
                <ul>
                    <li><strong>CONCAT:</strong> Simple concatenation, no delimiter option</li>
                    <li><strong>TEXTJOIN:</strong> More powerful—adds separators (comma, space, dash) and skips blanks</li>
                </ul>
                
                <p><strong>Practical Examples:</strong></p>
                <ul>
                    <li>Full Name from First and Last: <code>=CONCAT(B2, " ", C2)</code> or <code>=TEXTJOIN(" ", TRUE, B2, C2)</code></li>
                    <li>Create Email Address: <code>=LOWER(CONCAT(A2, ".", B2, "@company.com"))</code></li>
                    <li>Combine Address Parts: <code>=TEXTJOIN(", ", TRUE, D2, E2, F2, G2)</code></li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">5. Hands-On Exercise: Clean and Format Customer Data</h3>
                <p><strong>Objective:</strong> Use text functions to clean, extract, and combine customer information.</p>
                
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Create a new workbook and enter the following messy data:</li>
                </ol>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; border: 1px solid #ddd;">CustomerID</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">FullName</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Email</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">C001</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">JOHN DOE</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">JDOE@EMAIL.COM</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">5551234567</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">C002</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">maria garcia</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">m.garcia@email.com</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">(555)987-6543</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">C003</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">ALEx SMITH</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">Alex.Smith@email.com</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">555-555-5555</td>
                        </tr>
                    </tbody>
                </table>
                
                <ol start="2">
                    <li>Add New Columns and Apply Text Functions:</li>
                    <ul>
                        <li><strong>FirstName</strong> (extract first name from FullName):
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=LEFT(B2, FIND(" ", B2) - 1)</code>
                            </div>
                        </li>
                        <li><strong>LastName</strong> (extract last name from FullName):
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=RIGHT(B2, LEN(B2) - FIND(" ", B2))</code>
                            </div>
                        </li>
                        <li><strong>Clean Phone</strong> (remove non-numeric characters):
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=TEXTJOIN("", TRUE, MID(D2, {1,2,3,4,5,6,7,8,9,10,11,12,13,14}, 1)*1)</code>
                            </div>
                        </li>
                        <li><strong>Standardized Email</strong> (all lowercase):
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=LOWER(C2)</code>
                            </div>
                        </li>
                        <li><strong>Customer Code</strong> (Combine ID and LastName in uppercase):
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=UPPER(CONCAT(A2, "-", F2))</code>
                            </div>
                        </li>
                    </ul>
                    <li>Format and Finalize:</li>
                    <ul>
                        <li>Apply Proper Case to FirstName and LastName (manually or with PROPER)</li>
                        <li>Use Conditional Formatting to highlight any emails not containing "@"</li>
                        <li>Create a combined mailing label in a new column:
                            <div style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                                <code>=TEXTJOIN(", ", TRUE, E2, F2, G2)</code>
                            </div>
                        </li>
                    </ul>
                    <li>Save as <strong>Customer_Data_Cleaned.xlsx</strong></li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">6. Essential Shortcuts for Week 6</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F2</td>
                            <td style="padding: 6px 8px;">Edit cell (useful for debugging formulas)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + →</td>
                            <td style="padding: 6px 8px;">Select text within formula bar</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + Enter</td>
                            <td style="padding: 6px 8px;">Insert line break in cell (for TEXTJOIN with addresses)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + ~</td>
                            <td style="padding: 6px 8px;">Show/Hide formulas (toggle view)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + U</td>
                            <td style="padding: 6px 8px;">Expand/Collapse formula bar</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">F4</td>
                            <td style="padding: 6px 8px;">Toggle absolute/relative references in formulas</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">7. Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Product SKU Formatter:</strong>
                        <ul>
                            <li>Create a list of messy SKU codes (e.g., "SKU-1234", "sku_5678", "SKU 91011")</li>
                            <li>Use UPPER, SUBSTITUTE, and TEXTJOIN to standardize them to "SKU-XXXX"</li>
                            <li>Extract the numeric part using MID and RIGHT</li>
                            <li>Add a new column that combines the cleaned SKU with a category code</li>
                        </ul>
                    </li>
                    <li><strong>Address Book Builder:</strong>
                        <ul>
                            <li>You are given: First, Last, Street, City, State, ZIP in separate columns</li>
                            <li>Create a full address block using TEXTJOIN with line breaks (use CHAR(10) for line break)</li>
                            <li>Use LEFT to extract state abbreviation from a longer state name</li>
                            <li>Format the ZIP code to always show 5 digits (use TEXT function: <code>=TEXT(G2, "00000")</code>)</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What function would you use to combine first and last names with a comma?</li>
                            <li>How do you extract the domain from an email address (e.g., "@gmail.com")?</li>
                            <li>Write a formula to count how many characters are in cell A1 before the "@" symbol</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">8. Tips for Success</h3>
                <ul>
                    <li><strong>Use TRIM()</strong> to remove extra spaces before applying text functions</li>
                    <li><strong>Combine functions</strong> like FIND() with LEFT/RIGHT/MID for dynamic extraction</li>
                    <li><strong>Test on sample data</strong> before applying to entire datasets</li>
                    <li><strong>Remember case sensitivity</strong>—UPPER and LOWER ensure consistency</li>
                    <li><strong>Use named ranges</strong> to make formulas more readable</li>
                    <li><strong>Document your formulas</strong> with comments for complex transformations</li>
                    <li><strong>Always keep original data</strong> in separate columns when transforming</li>
                </ul>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Extract text using LEFT, RIGHT, MID</li>
                    <li>Change text case with UPPER, LOWER</li>
                    <li>Combine text with CONCAT, TEXTJOIN</li>
                    <li>Measure text length with LEN</li>
                    <li>Clean data with TRIM, CLEAN</li>
                    <li>Use FIND function with text functions</li>
                    <li>Apply text formatting functions</li>
                    <li>Transform data for reporting</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">9. Next Week Preview</h3>
                <p>In Week 7, we'll cover:</p>
                <ul>
                    <li>Creating and Modifying Charts</li>
                    <li>Adding Data Series and Chart Elements</li>
                    <li>Chart Styles, Layouts, and Accessibility</li>
                    <li>Sparklines and Visual Data Summaries</li>
                    <li>Formatting Charts for Professional Presentation</li>
                    <li>Creating Combination Charts</li>
                    <li>Adding Trendlines and Data Labels</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring a dataset you'd like to visualize with charts.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Text Functions Guide</li>
                    <li>Data Cleaning and Transformation Tutorial</li>
                    <li>Practice files and tutorial videos available in the Course Portal</li>
                    <li>Text Functions Cheat Sheet (download from portal)</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #107c10; margin-bottom: 10px;">Instructor Information</h4>
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
            <h1 style="color: #107c10; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #0e5c0e; font-size: 18pt; margin-bottom: 30px;">
                Microsoft Excel (MO-200) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #107c10; 
                border-bottom: 3px solid #107c10; padding: 20px 0; margin: 30px 0;">
                Week 6 Handout: Text Functions & Data Transformation
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
                    This handout is part of the MO-200 Excel Certification Prep Program. Unauthorized distribution is prohibited.
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
            Week 6: Text Functions & Data Transformation | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-200 Excel Certification Prep | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 6: Text Functions & Data Transformation - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #0e5c0e 0%, #107c10 100%);
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
            background: linear-gradient(135deg, #107c10 0%, #0e5c0e 100%);
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
            color: #107c10;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #107c10;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #0e5c0e;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #107c10;
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

        .function-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #f9f9f9;
            border-radius: 8px;
            overflow: hidden;
        }

        .function-table th {
            background-color: #107c10;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .function-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
        }

        .function-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .function-table tr:hover {
            background-color: #e8f4e8;
        }

        .code {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 12px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 10px 0;
            overflow-x: auto;
            white-space: nowrap;
        }

        .code-inline {
            background: #f5f5f5;
            color: #d63384;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            border: 1px solid #ddd;
        }

        .exercise-box {
            background: #e8f4e8;
            border-left: 5px solid #107c10;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #0e5c0e;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .homework-box {
            background: #fff9e6;
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
            background: #ffebee;
            border-left: 5px solid #d32f2f;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .help-title {
            color: #d32f2f;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #107c10;
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
            background: #0e5c0e;
        }

        .learning-objectives {
            background: #f0f9f0;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #107c10;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .data-table th {
            background: #107c10;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .data-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .data-table tr:hover {
            background: #e8f4e8;
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
            background-color: #107c10;
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
            background-color: #e8f4e8;
        }

        .shortcut-key {
            background: #107c10;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        /* Function Cards */
        .function-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .function-card {
            flex: 1;
            min-width: 200px;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .function-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .function-icon {
            font-size: 2.5rem;
            color: #107c10;
            margin-bottom: 15px;
            text-align: center;
        }

        .function-name {
            color: #107c10;
            font-size: 1.2rem;
            margin-bottom: 10px;
            text-align: center;
        }

        /* Formula Examples */
        .formula-example {
            background: #f8f9fa;
            border-left: 4px solid #107c10;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
        }

        .formula-result {
            background: #e8f4e8;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-weight: bold;
            color: #0e5c0e;
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

        footer {
            text-align: center;
            padding: 20px;
            background-color: #f2f2f2;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #ddd;
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

            .function-table,
            .data-table,
            .shortcut-table {
                font-size: 0.9rem;
                display: block;
                overflow-x: auto;
            }

            .function-cards {
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
                <strong>Access Granted:</strong> Excel Week 6 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week5_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 5
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week7_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 7
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 6 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Text Functions & Data Transformation</div>
            <div class="week-tag">Week 6 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 6!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll learn how to manipulate and transform text data using Excel's powerful text functions. These tools are essential for cleaning, formatting, and restructuring data—especially when working with imported files, names, addresses, IDs, and other text-based information.
                </p>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Use LEFT, RIGHT, and MID functions to extract text</li>
                    <li>Transform text case with UPPER, LOWER, and PROPER</li>
                    <li>Combine text from multiple cells using CONCAT and TEXTJOIN</li>
                    <li>Clean and format messy data efficiently</li>
                    <li>Measure text length with LEN function</li>
                    <li>Apply text functions to real-world data scenarios</li>
                    <li>Create dynamic formulas for data transformation</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Extract text using LEFT, RIGHT, MID</li>
                        <li>Change text case with UPPER, LOWER</li>
                        <li>Combine text with CONCAT, TEXTJOIN</li>
                        <li>Measure text length with LEN</li>
                    </ul>
                    <ul>
                        <li>Clean data with TRIM, CLEAN</li>
                        <li>Use FIND function with text functions</li>
                        <li>Apply text formatting functions</li>
                        <li>Transform data for reporting</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Introduction -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> 1. Introduction to Text Functions
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-question-circle"></i> Why Use Text Functions?</h3>
                    <ul>
                        <li><strong>Clean inconsistent data</strong> (e.g., extra spaces, mixed case)</li>
                        <li><strong>Extract parts of strings</strong> (e.g., first name from full name)</li>
                        <li><strong>Combine data from multiple columns</strong></li>
                        <li><strong>Prepare data for reports</strong>, mailing lists, or analysis</li>
                        <li><strong>Standardize formats</strong> for databases and systems</li>
                        <li><strong>Fix imported data</strong> from external sources</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-code"></i> Common Text Function Syntax</h3>
                    <div class="code">
                        =FUNCTION(text, [start_num], [num_chars])
                    </div>
                    <ul>
                        <li><code class="code-inline">text</code>: The cell or string you're working with</li>
                        <li><code class="code-inline">start_num</code>: Position to start extraction (1 = first character)</li>
                        <li><code class="code-inline">num_chars</code>: Number of characters to extract or manipulate</li>
                    </ul>
                    
                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Pro Tip
                        </div>
                        <p>Always use <code class="code-inline">TRIM()</code> before applying text functions to remove extra spaces that could affect your results.</p>
                    </div>
                </div>
            </div>

            <!-- Section 2: Extracting Text -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cut"></i> 2. Extracting Text with LEFT, RIGHT, and MID
                </div>

                <div class="function-cards">
                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-arrow-left"></i>
                        </div>
                        <div class="function-name">LEFT</div>
                        <p><strong>Purpose:</strong> Extracts characters from the start of text</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =LEFT(text, num_chars)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =LEFT("Excel", 2)
                            </div>
                            <div class="formula-result">
                                Result: "Ex"
                            </div>
                        </div>
                    </div>

                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="function-name">RIGHT</div>
                        <p><strong>Purpose:</strong> Extracts characters from the end of text</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =RIGHT(text, num_chars)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =RIGHT("Excel", 3)
                            </div>
                            <div class="formula-result">
                                Result: "cel"
                            </div>
                        </div>
                    </div>

                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-arrows-alt-h"></i>
                        </div>
                        <div class="function-name">MID</div>
                        <p><strong>Purpose:</strong> Extracts characters from the middle of text</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =MID(text, start_num, num_chars)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =MID("Microsoft", 6, 4)
                            </div>
                            <div class="formula-result">
                                Result: "soft"
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-hands-helping"></i> Practical Examples</h3>
                    <ul>
                        <li><strong>Extract Area Code from Phone Number:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =LEFT(A2, 3)  <span style="color: #888;">// if phone = "(123)456-7890"</span>
                            </div>
                        </li>
                        <li><strong>Extract Last 4 Digits of ID:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =RIGHT(B2, 4)
                            </div>
                        </li>
                        <li><strong>Extract Month from Date String:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =MID(C2, 4, 2)  <span style="color: #888;">// if date = "01/15/2024"</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Changing Case -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-font"></i> 3. Changing Case with UPPER, LOWER and Measuring Length with LEN
                </div>

                <div class="function-cards">
                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-text-height"></i>
                        </div>
                        <div class="function-name">UPPER</div>
                        <p><strong>Purpose:</strong> Converts text to ALL CAPS</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =UPPER(text)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =UPPER("hello")
                            </div>
                            <div class="formula-result">
                                Result: "HELLO"
                            </div>
                        </div>
                    </div>

                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-text-width"></i>
                        </div>
                        <div class="function-name">LOWER</div>
                        <p><strong>Purpose:</strong> Converts text to all lowercase</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =LOWER(text)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =LOWER("HELLO")
                            </div>
                            <div class="formula-result">
                                Result: "hello"
                            </div>
                        </div>
                    </div>

                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-ruler"></i>
                        </div>
                        <div class="function-name">LEN</div>
                        <p><strong>Purpose:</strong> Returns number of characters in text</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =LEN(text)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =LEN("Excel")
                            </div>
                            <div class="formula-result">
                                Result: 5
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-hands-helping"></i> Practical Examples</h3>
                    <ul>
                        <li><strong>Standardize Names:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =PROPER(A2)  <span style="color: #888;">// not in MO-200 but useful</span>
                            </div>
                        </li>
                        <li><strong>UPPER for consistency in codes:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =UPPER(A2)  <span style="color: #888;">// for SKU codes like SKU123</span>
                            </div>
                        </li>
                        <li><strong>Check Data Length:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =LEN(B2)  <span style="color: #888;">// validate input length for ZIP codes, IDs</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Combining Text -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-link"></i> 4. Combining Text with CONCAT and TEXTJOIN
                </div>

                <div class="function-cards">
                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="function-name">CONCAT</div>
                        <p><strong>Purpose:</strong> Combines text from multiple cells/ranges</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =CONCAT(text1, [text2], ...)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =CONCAT(A2, " ", B2)
                            </div>
                            <div class="formula-result">
                                Result: Combines A2 and B2 with space
                            </div>
                        </div>
                    </div>

                    <div class="function-card">
                        <div class="function-icon">
                            <i class="fas fa-object-group"></i>
                        </div>
                        <div class="function-name">TEXTJOIN</div>
                        <p><strong>Purpose:</strong> Combines text with delimiter, ignores empty cells</p>
                        <div class="code" style="font-size: 0.8rem;">
                            =TEXTJOIN(delimiter, ignore_empty, text1, [text2], ...)
                        </div>
                        <div class="formula-example">
                            <strong>Example:</strong>
                            <div class="code" style="font-size: 0.8rem;">
                                =TEXTJOIN(", ", TRUE, A2, B2, C2)
                            </div>
                            <div class="formula-result">
                                Result: "Value1, Value2, Value3"
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exchange-alt"></i> Key Differences</h3>
                    <ul>
                        <li><strong>CONCAT:</strong> Simple concatenation, no delimiter option</li>
                        <li><strong>TEXTJOIN:</strong> More powerful—adds separators (comma, space, dash) and skips blanks</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-hands-helping"></i> Practical Examples</h3>
                    <ul>
                        <li><strong>Full Name from First and Last:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =CONCAT(B2, " ", C2)
                            </div>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 15px 20px;">
                                <span style="color: #888;">// or better:</span> =TEXTJOIN(" ", TRUE, B2, C2)
                            </div>
                        </li>
                        <li><strong>Create Email Address:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =LOWER(CONCAT(A2, ".", B2, "@company.com"))
                            </div>
                        </li>
                        <li><strong>Combine Address Parts:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =TEXTJOIN(", ", TRUE, D2, E2, F2, G2)
                            </div>
                        </li>
                        <li><strong>Address with Line Breaks:</strong>
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =TEXTJOIN(CHAR(10), TRUE, D2, E2, F2, G2)
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 5. Hands-On Exercise: Clean and Format Customer Data
                </div>
                <p><strong>Objective:</strong> Use text functions to clean, extract, and combine customer information.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Original Messy Data:</h4>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>CustomerID</th>
                                <th>FullName</th>
                                <th>Email</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>C001</td>
                                <td>JOHN DOE</td>
                                <td>JDOE@EMAIL.COM</td>
                                <td>5551234567</td>
                            </tr>
                            <tr>
                                <td>C002</td>
                                <td>maria garcia</td>
                                <td>m.garcia@email.com</td>
                                <td>(555)987-6543</td>
                            </tr>
                            <tr>
                                <td>C003</td>
                                <td>ALEx SMITH</td>
                                <td>Alex.Smith@email.com</td>
                                <td>555-555-5555</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Create a new workbook and enter the data above</li>
                        <li>Add these new columns with formulas:</li>
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <li><strong>FirstName:</strong>
                                <div class="code" style="font-size: 0.8rem; margin: 5px 0;">
                                    =LEFT(B2, FIND(" ", B2) - 1)
                                </div>
                            </li>
                            <li><strong>LastName:</strong>
                                <div class="code" style="font-size: 0.8rem; margin: 5px 0;">
                                    =RIGHT(B2, LEN(B2) - FIND(" ", B2))
                                </div>
                            </li>
                            <li><strong>Clean Phone:</strong>
                                <div class="code" style="font-size: 0.8rem; margin: 5px 0;">
                                    =TEXTJOIN("", TRUE, MID(D2, {1,2,3,4,5,6,7,8,9,10,11,12,13,14}, 1)*1)
                                </div>
                            </li>
                            <li><strong>Standardized Email:</strong>
                                <div class="code" style="font-size: 0.8rem; margin: 5px 0;">
                                    =LOWER(C2)
                                </div>
                            </li>
                            <li><strong>Customer Code:</strong>
                                <div class="code" style="font-size: 0.8rem; margin: 5px 0;">
                                    =UPPER(CONCAT(A2, "-", F2))
                                </div>
                            </li>
                        </ul>
                        <li>Apply Proper Case to FirstName and LastName (manually or with PROPER)</li>
                        <li>Use Conditional Formatting to highlight any emails not containing "@"</li>
                        <li>Create a mailing label column:
                            <div class="code" style="font-size: 0.8rem; margin: 5px 0 5px 20px;">
                                =TEXTJOIN(", ", TRUE, E2, F2, G2)
                            </div>
                        </li>
                        <li>Save as <strong>Customer_Data_Cleaned.xlsx</strong></li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Excel Data Transformation Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBEYXRhIFRyYW5zZm9ybWF0aW9uPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Transform Messy Data into Clean, Usable Information</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Customer Data Template
                </a>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 6. Essential Shortcuts for Week 6
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
                            <td><span class="shortcut-key">F2</span></td>
                            <td>Edit cell (useful for debugging formulas)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + →</span></td>
                            <td>Select text within formula bar</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Enter</span></td>
                            <td>Insert line break in cell (for TEXTJOIN with addresses)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + ~</span></td>
                            <td>Show/Hide formulas (toggle view)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + U</span></td>
                            <td>Expand/Collapse formula bar</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F4</span></td>
                            <td>Toggle absolute/relative references in formulas</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + `</span></td>
                            <td>Toggle formula view (same as Ctrl + ~)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Enter</span></td>
                            <td>Enter same formula in multiple selected cells</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 7. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete these exercises:</h4>
                    <ol>
                        <li><strong>Product SKU Formatter:</strong>
                            <ul>
                                <li>Create a list of messy SKU codes (e.g., "SKU-1234", "sku_5678", "SKU 91011")</li>
                                <li>Use UPPER, SUBSTITUTE, and TEXTJOIN to standardize them to "SKU-XXXX"</li>
                                <li>Extract the numeric part using MID and RIGHT</li>
                                <li>Add a new column that combines the cleaned SKU with a category code</li>
                            </ul>
                        </li>
                        <li><strong>Address Book Builder:</strong>
                            <ul>
                                <li>You are given: First, Last, Street, City, State, ZIP in separate columns</li>
                                <li>Create a full address block using TEXTJOIN with line breaks (use CHAR(10) for line break)</li>
                                <li>Use LEFT to extract state abbreviation from a longer state name</li>
                                <li>Format the ZIP code to always show 5 digits (use TEXT function: <code class="code-inline">=TEXT(G2, "00000")</code>)</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>What function would you use to combine first and last names with a comma?</li>
                                <li>How do you extract the domain from an email address (e.g., "@gmail.com")?</li>
                                <li>Write a formula to count how many characters are in cell A1 before the "@" symbol</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Complete the practice exercises and submit your <strong>SKU_Formatter.xlsx</strong> and <strong>Address_Book.xlsx</strong> files via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Use TRIM()</strong> to remove extra spaces before applying text functions</li>
                    <li><strong>Combine functions</strong> like FIND() with LEFT/RIGHT/MID for dynamic extraction</li>
                    <li><strong>Test on sample data</strong> before applying to entire datasets</li>
                    <li><strong>Remember case sensitivity</strong>—UPPER and LOWER ensure consistency</li>
                    <li><strong>Use named ranges</strong> to make formulas more readable</li>
                    <li><strong>Document your formulas</strong> with comments for complex transformations</li>
                    <li><strong>Always keep original data</strong> in separate columns when transforming</li>
                    <li><strong>Use CLEAN()</strong> to remove non-printable characters from imported data</li>
                    <li><strong>Practice with real data</strong> to understand practical applications</li>
                    <li><strong>Learn the shortcuts</strong> to work more efficiently with formulas</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/text-functions-reference-7659c4b4-8012-4801-b78f-8d3a4459c8a5" target="_blank">Microsoft Excel Text Functions Reference</a></li>
                    <li><a href="https://support.microsoft.com/office/combine-text-from-two-or-more-cells-into-one-cell-81ba0946-ce78-42ed-b3c3-21340eb164a6" target="_blank">Combine Text from Multiple Cells Guide</a></li>
                    <li><a href="https://exceljet.net/excel-text-functions" target="_blank">Excel Text Functions Examples and Tutorials</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Text Functions Cheat Sheet</strong> (download from portal)</li>
                    <li><strong>Week 6 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Interactive Formula Builder</strong> for practicing text functions</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p><strong>Week 7: Creating and Formatting Charts</strong></p>
                <p>In Week 7, we'll build on your data transformation skills and learn to:</p>
                <ul>
                    <li>Create and modify various chart types (bar, line, pie, column)</li>
                    <li>Add data series and customize chart elements</li>
                    <li>Apply chart styles, layouts, and formatting options</li>
                    <li>Ensure chart accessibility for all users</li>
                    <li>Create sparklines for in-cell data visualization</li>
                    <li>Add trendlines and data labels to charts</li>
                    <li>Create combination charts with multiple data types</li>
                    <li>Format charts for professional presentations</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring a cleaned dataset you'd like to visualize with charts.</p>
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
                    <li><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week6.php">Week 6 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft Excel Help:</strong> <a href="https://support.microsoft.com/excel" target="_blank">Official Support</a></li>
                    <li><strong>Excel Community:</strong> <a href="https://techcommunity.microsoft.com/t5/excel/ct-p/Excel" target="_blank">Microsoft Tech Community</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week6_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 6 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 6 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-200 Excel Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
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
            
            // Auto-hide after 5 seconds
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Simulate template download
        function downloadTemplate() {
            alert('Customer data template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week6_customer_data.xlsx';
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
                console.log('Excel Week 6 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 6 Handout',
                //         action: 'view'
                //     })
                // });
            }
        });

        // Interactive function cards
        document.addEventListener('DOMContentLoaded', function() {
            const functionCards = document.querySelectorAll('.function-card');
            functionCards.forEach(card => {
                card.addEventListener('click', function() {
                    const functionName = this.querySelector('.function-name').textContent;
                    const purpose = this.querySelector('p').textContent;
                    const syntax = this.querySelector('.code').textContent;
                    const example = this.querySelector('.formula-example .code').textContent;
                    const result = this.querySelector('.formula-result').textContent;
                    
                    alert(`${functionName}\n\nPurpose: ${purpose}\n\nSyntax: ${syntax}\n\nExample: ${example}\n\n${result}`);
                });
            });

            // Interactive formula examples
            const codeElements = document.querySelectorAll('.code:not(.code-inline)');
            codeElements.forEach(code => {
                code.addEventListener('click', function() {
                    if (this.textContent.length < 100) { // Only copy short code snippets
                        navigator.clipboard.writeText(this.textContent)
                            .then(() => {
                                const originalText = this.textContent;
                                this.textContent = 'Copied!';
                                setTimeout(() => {
                                    this.textContent = originalText;
                                }, 1000);
                            })
                            .catch(err => {
                                console.error('Failed to copy: ', err);
                            });
                    }
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'F2': 'Edit cell - Great for debugging formulas',
                'Alt+Enter': 'Insert line break in cell - Useful for TEXTJOIN with addresses',
                'Ctrl+~': 'Toggle formula view - See all formulas at once',
                'F4': 'Toggle absolute/relative references - Lock cell references'
            };
            
            if (shortcuts[e.key] || (e.altKey && e.key === 'Enter') || (e.ctrlKey && e.key === '~')) {
                const shortcutAlert = document.createElement('div');
                shortcutAlert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #4caf50;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    z-index: 1000;
                    animation: fadeOut 2s forwards;
                `;
                
                let shortcutName = '';
                if (e.key === 'F2') shortcutName = 'F2';
                else if (e.altKey && e.key === 'Enter') shortcutName = 'Alt + Enter';
                else if (e.ctrlKey && e.key === '~') shortcutName = 'Ctrl + ~';
                else if (e.key === 'F4') shortcutName = 'F4';
                
                shortcutAlert.textContent = `Excel Shortcut: ${shortcutName} - ${shortcuts[shortcutName]}`;
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
            
            .code {
                cursor: pointer;
                transition: background-color 0.3s;
            }
            
            .code:hover {
                background-color: #3d3d3d;
            }
            
            .function-card {
                cursor: pointer;
            }
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard navigation hints
            const interactiveElements = document.querySelectorAll('a, button, .function-card, .code');
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

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Use TEXTJOIN with a comma delimiter: =TEXTJOIN(\", \", TRUE, last_name, first_name)",
                    "2. Use RIGHT and FIND: =RIGHT(A1, LEN(A1) - FIND(\"@\", A1) + 1)",
                    "3. Use FIND and LEN: =FIND(\"@\", A1) - 1"
                ];
                alert("Self-Quiz Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive formula builder simulation
        function demonstrateFormulaBuilder() {
            const steps = [
                "Text Formula Builder Demo:",
                "1. Select the cell where you want the result",
                "2. Type = to start a formula",
                "3. Choose your text function (e.g., LEFT, CONCAT)",
                "4. Select the cell reference for the text",
                "5. Add additional arguments as needed",
                "6. Press Enter to complete the formula",
                "\nTry building: =CONCAT(A2, \" \", B2)"
            ];
            alert(steps.join("\n"));
        }

        // Data transformation simulation
        function simulateDataTransformation() {
            const transformations = [
                "Data Transformation Example:",
                "Original: 'JOHN DOE'",
                "Step 1: Extract first name: =LEFT(A1, FIND(\" \", A1)-1)",
                "Result: 'JOHN'",
                "Step 2: Convert to proper case: =PROPER(B1)",
                "Result: 'John'",
                "Step 3: Combine with last name: =CONCAT(C1, \" \", D1)",
                "Final Result: 'John Doe'"
            ];
            alert(transformations.join("\n"));
        }

        // Function reference quick guide
        function showFunctionReference() {
            const functions = [
                "Text Functions Quick Reference:",
                "LEFT(text, num_chars) - Extract from start",
                "RIGHT(text, num_chars) - Extract from end",
                "MID(text, start_num, num_chars) - Extract middle",
                "UPPER(text) - Convert to uppercase",
                "LOWER(text) - Convert to lowercase",
                "LEN(text) - Count characters",
                "CONCAT(text1, text2, ...) - Combine text",
                "TEXTJOIN(delimiter, ignore_empty, text1, ...) - Combine with delimiter",
                "TRIM(text) - Remove extra spaces",
                "FIND(find_text, within_text, [start_num]) - Find position"
            ];
            alert(functions.join("\n"));
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
    $viewer = new ExcelWeek6HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>