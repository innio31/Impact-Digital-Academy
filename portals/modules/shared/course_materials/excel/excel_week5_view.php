<?php
// modules/shared/course_materials/MSExcel/excel_week5_view.php

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
 * Excel Week 5 Handout Viewer Class with PDF Download
 */
class ExcelWeek5HandoutViewer
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
            $mpdf->SetTitle('Week 5: Formulas, Functions & Cell References');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Formulas, Functions, Cell References, IF, SUM, COUNT');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week5_Formulas_Functions_' . date('Y-m-d') . '.pdf';
            
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
                Week 5: Formulas, Functions & Cell References
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 5!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we dive into the heart of Excel's power—Formulas and Functions. You will learn how to perform calculations, count data, and make decisions using Excel's built-in functions. Mastering these skills is essential for data analysis, reporting, and passing the MO-200 exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Create and use formulas with proper syntax</li>
                    <li>Understand and apply different types of cell references</li>
                    <li>Use basic functions like SUM, AVERAGE, MIN, and MAX</li>
                    <li>Count data using COUNT, COUNTA, and COUNTBLANK</li>
                    <li>Make decisions with the IF function</li>
                    <li>Create named ranges and use them in formulas</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Understanding Excel Formulas</h3>
                <p><strong>What is a Formula?</strong></p>
                <ul>
                    <li>A formula is an expression that calculates a value</li>
                    <li>Always starts with an equals sign (=)</li>
                    <li>Can contain numbers, cell references, functions, and operators</li>
                </ul>
                
                <p><strong>Formula Syntax:</strong></p>
                <ul>
                    <li>=FunctionName(argument1, argument2, ...)</li>
                    <li>Arguments can be numbers, text, cell references, ranges, or other functions</li>
                    <li>Examples: =5+3, =A1+B1, =SUM(A1:A10)</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Inserting References in Formulas</h3>
                <p><strong>Types of Cell References:</strong></p>
                
                <p><strong>1. Relative Reference</strong></p>
                <ul>
                    <li>Default reference type (e.g., A1)</li>
                    <li>Changes when copied to another cell</li>
                    <li>Example: =B2+C2 copied down becomes =B3+C3</li>
                </ul>
                
                <p><strong>2. Absolute Reference</strong></p>
                <ul>
                    <li>Locks the column and/or row (e.g., $A$1)</li>
                    <li>Does not change when copied</li>
                    <li>Press F4 to toggle between reference types</li>
                    <li>Example: =$B$2*C2 copied down stays =$B$2*C3</li>
                </ul>
                
                <p><strong>3. Mixed Reference</strong></p>
                <ul>
                    <li>Locks either the column or the row (e.g., $A1 or A$1)</li>
                    <li>Example: =$B2*C2 copied down becomes =$B3*C3</li>
                </ul>
                
                <p><strong>Referencing Named Ranges and Named Tables:</strong></p>
                <ul>
                    <li>Named Range: Use the range name in formulas (e.g., =SUM(SalesData))</li>
                    <li>Named Table: Use structured references (e.g., =SUM(Table1[Revenue]))</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Performing Calculations with Basic Functions</h3>
                
                <p><strong>A. SUM, AVERAGE, MIN, MAX</strong></p>
                <table style="width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 30%;">Function</th>
                            <th style="padding: 8px; text-align: left; width: 35%;">Purpose</th>
                            <th style="padding: 8px; text-align: left;">Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">SUM</td>
                            <td style="padding: 6px 8px;">Adds numbers</td>
                            <td style="padding: 6px 8px;">=SUM(A1:A10)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">AVERAGE</td>
                            <td style="padding: 6px 8px;">Calculates the mean</td>
                            <td style="padding: 6px 8px;">=AVERAGE(B2:B20)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">MIN</td>
                            <td style="padding: 6px 8px;">Finds smallest value</td>
                            <td style="padding: 6px 8px;">=MIN(C1:C100)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">MAX</td>
                            <td style="padding: 6px 8px;">Finds largest value</td>
                            <td style="padding: 6px 8px;">=MAX(D5:D50)</td>
                        </tr>
                    </tbody>
                </table>
                
                <p><strong>B. Counting Functions</strong></p>
                <table style="width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #0e5c0e; color: white;">
                            <th style="padding: 8px; text-align: left; width: 30%;">Function</th>
                            <th style="padding: 8px; text-align: left; width: 35%;">Purpose</th>
                            <th style="padding: 8px; text-align: left;">Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">COUNT</td>
                            <td style="padding: 6px 8px;">Counts cells with numbers</td>
                            <td style="padding: 6px 8px;">=COUNT(E1:E30)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">COUNTA</td>
                            <td style="padding: 6px 8px;">Counts non-empty cells</td>
                            <td style="padding: 6px 8px;">=COUNTA(F1:F30)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">COUNTBLANK</td>
                            <td style="padding: 6px 8px;">Counts empty cells</td>
                            <td style="padding: 6px 8px;">=COUNTBLANK(G1:G30)</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">4. Performing Conditional Operations with IF()</h3>
                
                <p><strong>A. The IF Function</strong></p>
                <ul>
                    <li>Performs a logical test and returns one value if true, another if false</li>
                    <li>Syntax: =IF(logical_test, value_if_true, value_if_false)</li>
                </ul>
                
                <p><strong>B. Example Usage</strong></p>
                <ul>
                    <li><strong>Grading Example:</strong> =IF(B2>=60, "Pass", "Fail")</li>
                    <li><strong>Bonus Calculation:</strong> =IF(C2>10000, C2*0.1, 0)</li>
                    <li><strong>Tax Calculation:</strong> =IF(D2>50000, D2*0.3, D2*0.2)</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">5. Creating Named Ranges</h3>
                <ul>
                    <li>Select cells → Formulas tab → Define Name</li>
                    <li>Or use Name Box (left of formula bar)</li>
                    <li>Benefits: Makes formulas easier to read and maintain</li>
                    <li>Example: Name A1:A10 as "SalesData", then use =SUM(SalesData)</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Build a Student Gradebook</h3>
                <p><strong>Objective:</strong> Use formulas and functions to calculate grades, averages, and pass/fail status.</p>
                
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Create a new workbook and enter the following data:</li>
                </ol>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; border: 1px solid #ddd;">Student</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Test 1</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Test 2</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Test 3</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Final Exam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">Alex Chen</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">85</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">90</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">78</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">88</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">Maria Lopez</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">92</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">88</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">95</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">91</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">Sam Wilson</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">70</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">65</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">72</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">68</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">Jamal Patel</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">88</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">85</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">90</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">87</td>
                        </tr>
                    </tbody>
                </table>
                
                <p><strong>2. Add Calculation Columns:</strong></p>
                <ul>
                    <li><strong>Total Points:</strong> =SUM(B2:E2) (Test 1 + Test 2 + Test 3 + Final Exam)</li>
                    <li><strong>Average Score:</strong> =AVERAGE(B2:E2)</li>
                    <li><strong>Status:</strong> =IF(F2>=70, "Pass", "Fail") (Pass if Average >= 70)</li>
                    <li><strong>Highest Test 1 Score:</strong> =MAX(B2:B5)</li>
                    <li><strong>Number of Students Who Passed:</strong> =COUNTIF(G2:G5, "Pass")</li>
                </ul>
                
                <p><strong>3. Apply Formatting:</strong></p>
                <ul>
                    <li>Use Number format for scores</li>
                    <li>Bold headers</li>
                    <li>Use Conditional Formatting to highlight failing grades in red</li>
                    <li>Save as Student_Gradebook.xlsx</li>
                </ul>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Essential Shortcuts for Week 5</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">=</td>
                            <td style="padding: 6px 8px;">Start a formula</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F4</td>
                            <td style="padding: 6px 8px;">Toggle reference types (Relative → Absolute → Mixed)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + =</td>
                            <td style="padding: 6px 8px;">AutoSum (inserts SUM function)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + `</td>
                            <td style="padding: 6px 8px;">Show/Hide formulas</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F2</td>
                            <td style="padding: 6px 8px;">Edit active cell (enter formula editing mode)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + Enter</td>
                            <td style="padding: 6px 8px;">Enter an array formula (advanced)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + F3</td>
                            <td style="padding: 6px 8px;">Open Name Manager</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + F3</td>
                            <td style="padding: 6px 8px;">Insert Function dialog</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + Shift + U</td>
                            <td style="padding: 6px 8px;">Expand/Collapse formula bar</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Formula:</strong> An expression that calculates a value, always starting with =.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Function:</strong> A predefined formula in Excel (e.g., SUM, AVERAGE, IF).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Relative Reference:</strong> Cell reference that changes when copied (e.g., A1).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Absolute Reference:</strong> Cell reference that doesn't change when copied (e.g., $A$1).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Mixed Reference:</strong> Reference that locks either column or row (e.g., $A1 or A$1).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Named Range:</strong> A descriptive name for a cell or range of cells.</p>
                </div>
                <div>
                    <p><strong>Argument:</strong> The values that a function uses to perform calculations.</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Personal Budget with Formulas:</strong>
                        <ul>
                            <li>Create a monthly budget with columns: Item, Planned, Actual, Difference</li>
                            <li>Use formulas to:
                                <ul>
                                    <li>Calculate Difference (Actual – Planned)</li>
                                    <li>Sum each column</li>
                                    <li>Use IF to show "Over Budget" if Difference is negative</li>
                                </ul>
                            </li>
                            <li>Name the Planned and Actual ranges</li>
                        </ul>
                    </li>
                    <li><strong>Employee Performance Tracker:</strong>
                        <ul>
                            <li>Build a table with: Employee, Sales Q1, Sales Q2, Sales Q3, Sales Q4</li>
                            <li>Calculate:
                                <ul>
                                    <li>Total Sales per employee</li>
                                    <li>Average Sales per employee</li>
                                    <li>Highest and Lowest quarterly sales</li>
                                    <li>Use COUNT to count how many employees had sales > 10000 in Q1</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What is the difference between COUNT and COUNTA?</li>
                            <li>How do you make a cell reference absolute?</li>
                            <li>Write an IF formula that returns "Yes" if A1 is greater than 100, otherwise "No"</li>
                            <li>What does the AVERAGE function do?</li>
                            <li>How do you quickly sum a column of numbers?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Create formulas with cell references</li>
                    <li>Use relative and absolute references</li>
                    <li>Insert SUM, AVERAGE, MIN, MAX functions</li>
                    <li>Use COUNT, COUNTA, COUNTBLANK functions</li>
                    <li>Create IF functions</li>
                    <li>Name cells and ranges</li>
                    <li>Reference named ranges in formulas</li>
                    <li>Use AutoSum (Alt + =)</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Always start formulas with =</strong></li>
                    <li><strong>Use F4 to lock references</strong> when copying formulas</li>
                    <li><strong>Test IF functions</strong> with both true and false conditions</li>
                    <li><strong>Name your ranges</strong> to make formulas easier to read and manage</li>
                    <li><strong>Use AutoSum (Alt + =)</strong> for quick totals</li>
                    <li><strong>Check parentheses</strong> - make sure every opening ( has a closing )</li>
                    <li><strong>Use formula auditing tools</strong> (Formulas tab) to trace errors</li>
                    <li><strong>Practice with real data</strong> from your work or personal projects</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 6, we'll cover:</p>
                <ul>
                    <li>Text Functions: LEFT, RIGHT, MID</li>
                    <li>Case Functions: UPPER, LOWER, PROPER</li>
                    <li>Text Length: LEN</li>
                    <li>Concatenation Functions: CONCAT, TEXTJOIN</li>
                    <li>Text-to-Columns feature</li>
                    <li>Formatting and transforming text data</li>
                    <li>Extracting parts of text strings</li>
                    <li>Combining data from multiple cells</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring a dataset with text information (names, addresses, product codes) to practice text functions.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Formulas and Functions Guide</li>
                    <li>Practice files with sample datasets</li>
                    <li>Video tutorials on using IF, SUM, and COUNT functions</li>
                    <li>Interactive formula exercises in the Course Portal</li>
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
                Week 5 Handout: Formulas, Functions & Cell References
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
            Week 5: Formulas, Functions & Cell References | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 5: Formulas, Functions & Cell References - Impact Digital Academy</title>
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
            color: #107c10;
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

        .demo-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .demo-table th {
            background: #107c10;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .demo-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .demo-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .demo-table tr:hover {
            background: #e8f4e8;
        }

        /* Reference Type Cards */
        .ref-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .ref-card {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 25px 20px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .ref-card.relative {
            border-top: 5px solid #2196f3;
        }

        .ref-card.absolute {
            border-top: 5px solid #4caf50;
        }

        .ref-card.mixed {
            border-top: 5px solid #9c27b0;
        }

        .ref-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .ref-card.relative .ref-icon {
            color: #2196f3;
        }

        .ref-card.absolute .ref-icon {
            color: #4caf50;
        }

        .ref-card.mixed .ref-icon {
            color: #9c27b0;
        }

        .ref-example {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 0.9rem;
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
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: 2px solid #e0e0e0;
            transition: transform 0.3s;
        }

        .function-card:hover {
            transform: translateY(-5px);
        }

        .function-card.sum {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .function-card.average {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .function-card.min {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .function-card.max {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .function-card.count {
            border-color: #f44336;
            background: #ffebee;
        }

        .function-card.if {
            border-color: #673ab7;
            background: #ede7f6;
        }

        .function-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .function-card.sum .function-icon {
            color: #4caf50;
        }

        .function-card.average .function-icon {
            color: #2196f3;
        }

        .function-card.min .function-icon {
            color: #ff9800;
        }

        .function-card.max .function-icon {
            color: #9c27b0;
        }

        .function-card.count .function-icon {
            color: #f44336;
        }

        .function-card.if .function-icon {
            color: #673ab7;
        }

        /* Excel Grid Demo */
        .excel-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            grid-template-rows: repeat(7, 1fr);
            gap: 1px;
            background: #ddd;
            border: 2px solid #107c10;
            margin: 20px 0;
            font-family: monospace;
            font-size: 0.9rem;
        }

        .excel-cell {
            background: white;
            padding: 8px 5px;
            text-align: center;
            border: 1px solid #eee;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .excel-cell.header {
            background: #107c10;
            color: white;
            font-weight: bold;
        }

        .excel-cell.formula {
            background: #fff3e0;
            font-style: italic;
        }

        .excel-cell.result {
            background: #e8f5e9;
            font-weight: bold;
        }

        .excel-cell.if-formula {
            background: #ede7f6;
            font-size: 0.8rem;
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

        /* IF Function Visual */
        .if-visual {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 25px 0;
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
        }

        .if-condition {
            background: #2196f3;
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            min-width: 150px;
        }

        .if-arrow {
            margin: 0 20px;
            font-size: 2rem;
            color: #666;
        }

        .if-result {
            display: flex;
            gap: 20px;
        }

        .if-true, .if-false {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            min-width: 120px;
        }

        .if-true {
            background: #4caf50;
            color: white;
        }

        .if-false {
            background: #f44336;
            color: white;
        }

        /* Formula Bar Demo */
        .formula-bar-demo {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-family: monospace;
            font-size: 1.1rem;
        }

        .formula-input {
            background: white;
            border: 1px solid #107c10;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            width: 100%;
            margin-top: 10px;
        }

        /* Reference Demo Table */
        .reference-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .reference-table th {
            background: #107c10;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .reference-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .reference-table tr:hover {
            background: #f5f5f5;
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

            .shortcut-table,
            .demo-table,
            .reference-table {
                font-size: 0.9rem;
            }

            .shortcut-table th,
            .shortcut-table td,
            .demo-table th,
            .demo-table td,
            .reference-table th,
            .reference-table td {
                padding: 10px;
            }

            .ref-cards,
            .function-cards {
                flex-direction: column;
            }
            
            .excel-grid {
                grid-template-columns: repeat(5, 1fr);
                grid-template-rows: repeat(8, 1fr);
            }

            .if-visual {
                flex-direction: column;
                gap: 20px;
            }

            .if-arrow {
                transform: rotate(90deg);
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

            .excel-grid {
                break-inside: avoid;
            }

            .if-visual {
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
                <strong>Access Granted:</strong> Excel Week 5 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week4_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 4
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week6_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 6
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 5 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Formulas, Functions & Cell References</div>
            <div class="week-tag">Week 5 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-calculator"></i> Welcome to Week 5!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we dive into the heart of Excel's power—Formulas and Functions. You will learn how to perform calculations, count data, and make decisions using Excel's built-in functions. Mastering these skills is essential for data analysis, reporting, and passing the MO-200 exam.
                </p>

                <div class="image-container">
                    <img src="images/excel_formulas.png"
                        alt="Excel Formulas and Functions"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RXhjZWwgRm9ybXVsYXMgYW5kIEZ1bmN0aW9uczwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Master Excel Formulas and Functions for Powerful Data Analysis</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Create and use formulas with proper syntax</li>
                    <li>Understand and apply different types of cell references</li>
                    <li>Use basic functions like SUM, AVERAGE, MIN, and MAX</li>
                    <li>Count data using COUNT, COUNTA, and COUNTBLANK</li>
                    <li>Make decisions with the IF function</li>
                    <li>Create named ranges and use them in formulas</li>
                    <li>Use AutoSum and formula auditing tools</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Create formulas with cell references</li>
                        <li>Use relative and absolute references</li>
                        <li>Insert SUM, AVERAGE, MIN, MAX functions</li>
                        <li>Use COUNT, COUNTA, COUNTBLANK functions</li>
                    </ul>
                    <ul>
                        <li>Create IF functions</li>
                        <li>Name cells and ranges</li>
                        <li>Reference named ranges in formulas</li>
                        <li>Use AutoSum (Alt + =)</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Excel Formulas -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-equals"></i> 1. Understanding Excel Formulas
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-question-circle"></i> What is a Formula?</h3>
                    <ul>
                        <li>A <strong>formula</strong> is an expression that calculates a value</li>
                        <li>Always starts with an <strong>equals sign (=)</strong></li>
                        <li>Can contain:
                            <ul>
                                <li><strong>Numbers</strong>: =5+3, =10*2.5</li>
                                <li><strong>Cell references</strong>: =A1+B1, =C5-D3</li>
                                <li><strong>Functions</strong>: =SUM(A1:A10), =AVERAGE(B2:B20)</li>
                                <li><strong>Operators</strong>: +, -, *, /, ^ (exponent)</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-code"></i> Formula Syntax</h3>
                    <div class="formula-bar-demo">
                        <div style="margin-bottom: 10px;">Formula Syntax Structure:</div>
                        <div style="background: white; padding: 10px; border-radius: 3px; font-family: monospace;">
                            =FunctionName(argument1, argument2, ...)
                        </div>
                        <div style="margin-top: 15px;">
                            <strong>Arguments can be:</strong>
                            <ul style="margin-top: 10px;">
                                <li>Numbers: =SUM(10, 20, 30)</li>
                                <li>Cell references: =AVERAGE(A1, B1, C1)</li>
                                <li>Ranges: =SUM(A1:A10)</li>
                                <li>Other functions: =SUM(AVERAGE(B1:B5), MAX(C1:C5))</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Formula Examples Table -->
                <div class="subsection">
                    <h3><i class="fas fa-list-ol"></i> Formula Examples</h3>
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Formula</th>
                                <th>What it does</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>=5+3</code></td>
                                <td>Adds 5 and 3</td>
                                <td>8</td>
                            </tr>
                            <tr>
                                <td><code>=10*2</code></td>
                                <td>Multiplies 10 by 2</td>
                                <td>20</td>
                            </tr>
                            <tr>
                                <td><code>=A1+B1</code></td>
                                <td>Adds values in A1 and B1</td>
                                <td>Depends on cell values</td>
                            </tr>
                            <tr>
                                <td><code>=SUM(A1:A10)</code></td>
                                <td>Sums values from A1 to A10</td>
                                <td>Total of range</td>
                            </tr>
                            <tr>
                                <td><code>=(A1+B1)*C1</code></td>
                                <td>Adds A1 and B1, then multiplies by C1</td>
                                <td>Calculation result</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section 2: Cell References -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-exchange-alt"></i> 2. Inserting References in Formulas
                </div>

                <!-- Reference Type Cards -->
                <div class="ref-cards">
                    <div class="ref-card relative">
                        <div class="ref-icon">
                            <i class="fas fa-arrows-alt"></i>
                        </div>
                        <h3>Relative Reference</h3>
                        <p>Default reference type</p>
                        <p>Changes when copied</p>
                        <div class="ref-example">A1, B2, C3</div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Example: =B2+C2 copied down becomes =B3+C3
                        </p>
                    </div>

                    <div class="ref-card absolute">
                        <div class="ref-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3>Absolute Reference</h3>
                        <p>Locks column and row</p>
                        <p>Doesn't change when copied</p>
                        <div class="ref-example">$A$1, $B$5</div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Example: =$B$2*C2 copied down stays =$B$2*C3
                        </p>
                    </div>

                    <div class="ref-card mixed">
                        <div class="ref-icon">
                            <i class="fas fa-unlock"></i>
                        </div>
                        <h3>Mixed Reference</h3>
                        <p>Locks either column OR row</p>
                        <p>Partial change when copied</p>
                        <div class="ref-example">$A1, A$1</div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Example: =$B2*C$2 copied right becomes =$B2*D$2
                        </p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-keyboard"></i> Using F4 to Toggle References</h3>
                    <ul>
                        <li>Select a cell reference in formula bar</li>
                        <li>Press <strong>F4</strong> to cycle through reference types:</li>
                        <ol style="margin-left: 20px; margin-top: 10px;">
                            <li>A1 → $A$1 (Absolute)</li>
                            <li>$A$1 → A$1 (Mixed - row locked)</li>
                            <li>A$1 → $A1 (Mixed - column locked)</li>
                            <li>$A1 → A1 (Relative)</li>
                        </ol>
                        <li>Use when creating formulas that will be copied</li>
                    </ul>
                </div>

                <!-- Reference Demo Table -->
                <div class="subsection">
                    <h3><i class="fas fa-table"></i> Reference Type Examples</h3>
                    <table class="reference-table">
                        <thead>
                            <tr>
                                <th>Original Formula</th>
                                <th>Copied to Right</th>
                                <th>Copied Down</th>
                                <th>Reference Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>=A1*B1</code></td>
                                <td><code>=B1*C1</code></td>
                                <td><code>=A2*B2</code></td>
                                <td>Relative (A1, B1)</td>
                            </tr>
                            <tr>
                                <td><code>=$A$1*B1</code></td>
                                <td><code>=$A$1*C1</code></td>
                                <td><code>=$A$1*B2</code></td>
                                <td>Absolute ($A$1)</td>
                            </tr>
                            <tr>
                                <td><code>=$A1*B$1</code></td>
                                <td><code>=$A1*C$1</code></td>
                                <td><code>=$A2*B$1</code></td>
                                <td>Mixed ($A1, B$1)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-tag"></i> Named Ranges and Tables</h3>
                    <ul>
                        <li><strong>Named Range:</strong> Descriptive name for cell/range</li>
                        <li>Create: Select range → Name Box → Type name → Enter</li>
                        <li>Use in formulas: <code>=SUM(SalesData)</code> instead of <code>=SUM(A1:A100)</code></li>
                        <li><strong>Named Table:</strong> Excel Table with structured references</li>
                        <li>Use: <code>=SUM(Table1[Revenue])</code> or <code>=Table1[@Sales]</code></li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Basic Functions -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cogs"></i> 3. Performing Calculations with Basic Functions
                </div>

                <!-- Function Cards -->
                <div class="function-cards">
                    <div class="function-card sum">
                        <div class="function-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <h3>SUM</h3>
                        <p>Adds numbers</p>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                            =SUM(A1:A10)
                        </div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Totals values in range
                        </p>
                    </div>

                    <div class="function-card average">
                        <div class="function-icon">
                            <i class="fas fa-divide"></i>
                        </div>
                        <h3>AVERAGE</h3>
                        <p>Calculates mean</p>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                            =AVERAGE(B2:B20)
                        </div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Average of values
                        </p>
                    </div>

                    <div class="function-card min">
                        <div class="function-icon">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <h3>MIN</h3>
                        <p>Finds smallest value</p>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                            =MIN(C1:C100)
                        </div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Minimum value in range
                        </p>
                    </div>

                    <div class="function-card max">
                        <div class="function-icon">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <h3>MAX</h3>
                        <p>Finds largest value</p>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                            =MAX(D5:D50)
                        </div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Maximum value in range
                        </p>
                    </div>
                </div>

                <!-- Counting Functions -->
                <div class="subsection">
                    <h3><i class="fas fa-list-ol"></i> Counting Functions</h3>
                    
                    <div class="function-cards">
                        <div class="function-card count">
                            <div class="function-icon">
                                <i class="fas fa-hashtag"></i>
                            </div>
                            <h3>COUNT</h3>
                            <p>Counts cells with numbers</p>
                            <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                                =COUNT(E1:E30)
                            </div>
                            <p style="margin-top: 10px; font-size: 0.9rem;">
                                Ignores text and blanks
                            </p>
                        </div>

                        <div class="function-card count">
                            <div class="function-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <h3>COUNTA</h3>
                            <p>Counts non-empty cells</p>
                            <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                                =COUNTA(F1:F30)
                            </div>
                            <p style="margin-top: 10px; font-size: 0.9rem;">
                                Counts text and numbers
                            </p>
                        </div>

                        <div class="function-card count">
                            <div class="function-icon">
                                <i class="fas fa-square"></i>
                            </div>
                            <h3>COUNTBLANK</h3>
                            <p>Counts empty cells</p>
                            <div style="margin-top: 10px; font-family: monospace; font-size: 0.9rem;">
                                =COUNTBLANK(G1:G30)
                            </div>
                            <p style="margin-top: 10px; font-size: 0.9rem;">
                                Cells with no content
                            </p>
                        </div>
                    </div>

                    <table class="demo-table" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Function</th>
                                <th>Counts</th>
                                <th>Ignores</th>
                                <th>Example Range</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>COUNT</strong></td>
                                <td>Numbers only</td>
                                <td>Text, blanks, errors</td>
                                <td>A1:A5 = {10, "Text", 20, "", 30}</td>
                                <td>3</td>
                            </tr>
                            <tr>
                                <td><strong>COUNTA</strong></td>
                                <td>Non-empty cells</td>
                                <td>Empty cells only</td>
                                <td>A1:A5 = {10, "Text", 20, "", 30}</td>
                                <td>4</td>
                            </tr>
                            <tr>
                                <td><strong>COUNTBLANK</strong></td>
                                <td>Empty cells</td>
                                <td>All non-empty cells</td>
                                <td>A1:A5 = {10, "Text", 20, "", 30}</td>
                                <td>1</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section 4: IF Function -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-question"></i> 4. Performing Conditional Operations with IF()
                </div>

                <!-- IF Function Card -->
                <div class="function-cards">
                    <div class="function-card if" style="max-width: 400px; margin: 0 auto;">
                        <div class="function-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <h3>IF Function</h3>
                        <p>Makes decisions based on conditions</p>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 1rem;">
                            =IF(logical_test, value_if_true, value_if_false)
                        </div>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Returns one value if true, another if false
                        </p>
                    </div>
                </div>

                <!-- IF Visual -->
                <div class="if-visual">
                    <div class="if-condition">
                        <strong>Condition</strong><br>
                        B2 >= 70
                    </div>
                    <div class="if-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="if-result">
                        <div class="if-true">
                            <strong>TRUE</strong><br>
                            "Pass"
                        </div>
                        <div class="if-false">
                            <strong>FALSE</strong><br>
                            "Fail"
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-graduation-cap"></i> Example: Grading System</h3>
                    <div class="formula-bar-demo">
                        <div style="margin-bottom: 10px;">Grading Formula:</div>
                        <div style="background: white; padding: 10px; border-radius: 3px; font-family: monospace;">
                            =IF(B2>=60, "Pass", "Fail")
                        </div>
                        <div style="margin-top: 15px;">
                            <strong>Explanation:</strong>
                            <ul style="margin-top: 10px;">
                                <li><strong>logical_test:</strong> B2>=60 (Is score 60 or more?)</li>
                                <li><strong>value_if_true:</strong> "Pass" (Return if score >= 60)</li>
                                <li><strong>value_if_false:</strong> "Fail" (Return if score < 60)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-money-bill-wave"></i> Example: Bonus Calculation</h3>
                    <div class="formula-bar-demo">
                        <div style="margin-bottom: 10px;">Bonus Formula:</div>
                        <div style="background: white; padding: 10px; border-radius: 3px; font-family: monospace;">
                            =IF(C2>10000, C2*0.1, 0)
                        </div>
                        <div style="margin-top: 15px;">
                            <strong>Explanation:</strong>
                            <ul style="margin-top: 10px;">
                                <li><strong>logical_test:</strong> C2>10000 (Sales over $10,000?)</li>
                                <li><strong>value_if_true:</strong> C2*0.1 (10% bonus if sales > 10000)</li>
                                <li><strong>value_if_false:</strong> 0 (No bonus if sales ≤ 10000)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- IF Examples Table -->
                <div class="subsection">
                    <h3><i class="fas fa-table"></i> IF Function Examples</h3>
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Formula</th>
                                <th>Purpose</th>
                                <th>Example Data</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>=IF(A1>50, "High", "Low")</code></td>
                                <td>Categorize value</td>
                                <td>A1 = 75</td>
                                <td>"High"</td>
                            </tr>
                            <tr>
                                <td><code>=IF(B2="Yes", 100, 0)</code></td>
                                <td>Assign points</td>
                                <td>B2 = "Yes"</td>
                                <td>100</td>
                            </tr>
                            <tr>
                                <td><code>=IF(C3>=18, "Adult", "Minor")</code></td>
                                <td>Age classification</td>
                                <td>C3 = 21</td>
                                <td>"Adult"</td>
                            </tr>
                            <tr>
                                <td><code>=IF(D4>0, D4*0.2, "No Tax")</code></td>
                                <td>Tax calculation</td>
                                <td>D4 = 5000</td>
                                <td>1000</td>
                            </tr>
                            <tr>
                                <td><code>=IF(E5<0, "Loss", IF(E5=0, "Break-even", "Profit"))</code></td>
                                <td>Nested IF for multiple conditions</td>
                                <td>E5 = 1500</td>
                                <td>"Profit"</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 5. Hands-On Exercise: Build a Student Gradebook
                </div>
                <p><strong>Objective:</strong> Use formulas and functions to calculate grades, averages, and pass/fail status.</p>

                <!-- Gradebook Demo Grid -->
                <div class="excel-grid">
                    <!-- Headers -->
                    <div class="excel-cell header"></div>
                    <div class="excel-cell header">A</div>
                    <div class="excel-cell header">B</div>
                    <div class="excel-cell header">C</div>
                    <div class="excel-cell header">D</div>
                    <div class="excel-cell header">E</div>
                    <div class="excel-cell header">F</div>
                    <div class="excel-cell header">G</div>
                    
                    <!-- Row 1: Headers -->
                    <div class="excel-cell header">1</div>
                    <div class="excel-cell header">Student</div>
                    <div class="excel-cell header">Test 1</div>
                    <div class="excel-cell header">Test 2</div>
                    <div class="excel-cell header">Test 3</div>
                    <div class="excel-cell header">Final Exam</div>
                    <div class="excel-cell header">Total</div>
                    <div class="excel-cell header">Average</div>
                    <div class="excel-cell header">Status</div>
                    
                    <!-- Row 2: Alex Chen -->
                    <div class="excel-cell header">2</div>
                    <div class="excel-cell">Alex Chen</div>
                    <div class="excel-cell">85</div>
                    <div class="excel-cell">90</div>
                    <div class="excel-cell">78</div>
                    <div class="excel-cell">88</div>
                    <div class="excel-cell formula">=SUM(B2:E2)</div>
                    <div class="excel-cell formula">=AVERAGE(B2:E2)</div>
                    <div class="excel-cell if-formula">=IF(G2>=70,"Pass","Fail")</div>
                    
                    <!-- Row 3: Maria Lopez -->
                    <div class="excel-cell header">3</div>
                    <div class="excel-cell">Maria Lopez</div>
                    <div class="excel-cell">92</div>
                    <div class="excel-cell">88</div>
                    <div class="excel-cell">95</div>
                    <div class="excel-cell">91</div>
                    <div class="excel-cell formula">=SUM(B3:E3)</div>
                    <div class="excel-cell formula">=AVERAGE(B3:E3)</div>
                    <div class="excel-cell if-formula">=IF(G3>=70,"Pass","Fail")</div>
                    
                    <!-- Row 4: Sam Wilson -->
                    <div class="excel-cell header">4</div>
                    <div class="excel-cell">Sam Wilson</div>
                    <div class="excel-cell">70</div>
                    <div class="excel-cell">65</div>
                    <div class="excel-cell">72</div>
                    <div class="excel-cell">68</div>
                    <div class="excel-cell formula">=SUM(B4:E4)</div>
                    <div class="excel-cell formula">=AVERAGE(B4:E4)</div>
                    <div class="excel-cell if-formula">=IF(G4>=70,"Pass","Fail")</div>
                    
                    <!-- Row 5: Jamal Patel -->
                    <div class="excel-cell header">5</div>
                    <div class="excel-cell">Jamal Patel</div>
                    <div class="excel-cell">88</div>
                    <div class="excel-cell">85</div>
                    <div class="excel-cell">90</div>
                    <div class="excel-cell">87</div>
                    <div class="excel-cell formula">=SUM(B5:E5)</div>
                    <div class="excel-cell formula">=AVERAGE(B5:E5)</div>
                    <div class="excel-cell if-formula">=IF(G5>=70,"Pass","Fail")</div>
                    
                    <!-- Row 6: Summary -->
                    <div class="excel-cell header">6</div>
                    <div class="excel-cell">Summary</div>
                    <div class="excel-cell formula">=MAX(B2:B5)</div>
                    <div class="excel-cell formula">=MIN(C2:C5)</div>
                    <div class="excel-cell formula">=AVERAGE(D2:D5)</div>
                    <div class="excel-cell formula">=COUNT(E2:E5)</div>
                    <div class="excel-cell"></div>
                    <div class="excel-cell"></div>
                    <div class="excel-cell formula">=COUNTIF(H2:H5,"Pass")</div>
                </div>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open Excel and create a <strong>new workbook</strong></li>
                        <li>Enter the student data as shown above</li>
                        <li>Add the calculation formulas:
                            <ul>
                                <li><strong>Total Points:</strong> =SUM(B2:E2) in cell F2, then copy down</li>
                                <li><strong>Average Score:</strong> =AVERAGE(B2:E2) in cell G2, then copy down</li>
                                <li><strong>Status:</strong> =IF(G2>=70, "Pass", "Fail") in cell H2, then copy down</li>
                                <li><strong>Highest Test 1:</strong> =MAX(B2:B5) in cell B6</li>
                                <li><strong>Students Passed:</strong> =COUNTIF(H2:H5, "Pass") in cell H6</li>
                            </ul>
                        </li>
                        <li>Apply formatting:
                            <ul>
                                <li>Bold headers (Row 1)</li>
                                <li>Use <strong>Number format</strong> for scores</li>
                                <li>Add <strong>Conditional Formatting</strong> to highlight failing averages in red</li>
                                <li>Adjust column widths</li>
                            </ul>
                        </li>
                        <li>Save as <strong>Student_Gradebook.xlsx</strong></li>
                    </ol>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Gradebook Template
                </a>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 6. Essential Shortcuts for Week 5
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
                            <td><span class="shortcut-key">=</span></td>
                            <td>Start a formula</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F4</span></td>
                            <td>Toggle reference types (Relative → Absolute → Mixed)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + =</span></td>
                            <td>AutoSum (inserts SUM function)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + `</span></td>
                            <td>Show/Hide formulas (toggle formula view)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F2</span></td>
                            <td>Edit active cell (enter formula editing mode)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + Enter</span></td>
                            <td>Enter an array formula (advanced)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + F3</span></td>
                            <td>Open Name Manager</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + F3</span></td>
                            <td>Insert Function dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + U</span></td>
                            <td>Expand/Collapse formula bar</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F9</span></td>
                            <td>Calculate selected part of formula</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Enter</span></td>
                            <td>Enter same formula in multiple cells</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + ↓</span></td>
                            <td>Display AutoComplete list in formula</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 7. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Formula</strong>
                    <p>An expression that calculates a value, always starting with =.</p>
                </div>

                <div class="term">
                    <strong>Function</strong>
                    <p>A predefined formula in Excel (e.g., SUM, AVERAGE, IF).</p>
                </div>

                <div class="term">
                    <strong>Relative Reference</strong>
                    <p>Cell reference that changes when copied to another location (e.g., A1).</p>
                </div>

                <div class="term">
                    <strong>Absolute Reference</strong>
                    <p>Cell reference that doesn't change when copied (e.g., $A$1). Use F4 to toggle.</p>
                </div>

                <div class="term">
                    <strong>Mixed Reference</strong>
                    <p>Reference that locks either column or row (e.g., $A1 or A$1).</p>
                </div>

                <div class="term">
                    <strong>Named Range</strong>
                    <p>A descriptive name for a cell or range of cells, used in formulas.</p>
                </div>

                <div class="term">
                    <strong>Argument</strong>
                    <p>The values that a function uses to perform calculations.</p>
                </div>

                <div class="term">
                    <strong>Logical Test</strong>
                    <p>A comparison that returns TRUE or FALSE, used in IF functions.</p>
                </div>

                <div class="term">
                    <strong>AutoSum</strong>
                    <p>Feature that automatically inserts SUM, AVERAGE, COUNT, etc. (Alt + =).</p>
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
                        <li><strong>Personal Budget with Formulas:</strong>
                            <ul>
                                <li>Create a monthly budget with columns: Item, Planned, Actual, Difference</li>
                                <li>Use formulas to:
                                    <ul>
                                        <li>Calculate Difference (Actual – Planned)</li>
                                        <li>Sum each column using SUM function</li>
                                        <li>Use IF to show "Over Budget" if Difference is negative</li>
                                        <li>Calculate average actual spending</li>
                                    </ul>
                                </li>
                                <li>Name the Planned and Actual ranges</li>
                                <li>Use absolute references where appropriate</li>
                            </ul>
                        </li>
                        <li><strong>Employee Performance Tracker:</strong>
                            <ul>
                                <li>Build a table with: Employee, Sales Q1, Sales Q2, Sales Q3, Sales Q4</li>
                                <li>Calculate:
                                    <ul>
                                        <li>Total Sales per employee using SUM</li>
                                        <li>Average Sales per employee using AVERAGE</li>
                                        <li>Highest and Lowest quarterly sales using MAX and MIN</li>
                                        <li>Use COUNT to count how many employees had sales > 10000 in Q1</li>
                                        <li>Create an IF formula to label employees as "Top Performer" if total sales > 40000</li>
                                    </ul>
                                </li>
                                <li>Apply appropriate number formatting (currency)</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>What is the difference between COUNT and COUNTA?</li>
                                <li>How do you make a cell reference absolute?</li>
                                <li>Write an IF formula that returns "Yes" if A1 is greater than 100, otherwise "No"</li>
                                <li>What does the AVERAGE function do?</li>
                                <li>How do you quickly sum a column of numbers?</li>
                                <li>What happens to the formula =A1+B1 when copied from row 1 to row 2?</li>
                                <li>What happens to the formula =$A$1+B1 when copied from row 1 to row 2?</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Complete both exercises and submit your <strong>Personal_Budget.xlsx</strong> and <strong>Employee_Performance.xlsx</strong> files via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 9. Tips for Success
                </div>
                <ul>
                    <li><strong>Always start formulas with =</strong> - Excel won't recognize it as a formula otherwise.</li>
                    <li><strong>Use F4 to lock references</strong> when creating formulas that will be copied.</li>
                    <li><strong>Test IF functions</strong> with both true and false conditions to ensure they work correctly.</li>
                    <li><strong>Name your ranges</strong> to make formulas easier to read, understand, and maintain.</li>
                    <li><strong>Use AutoSum (Alt + =)</strong> for quick totals - it automatically detects the range to sum.</li>
                    <li><strong>Check parentheses carefully</strong> - every opening ( must have a closing ).</li>
                    <li><strong>Use formula auditing tools</strong> (Formulas tab → Formula Auditing) to trace precedents, dependents, and errors.</li>
                    <li><strong>Practice with real data</strong> from your work or personal projects to make learning relevant.</li>
                    <li><strong>Break complex formulas into parts</strong> to debug them more easily.</li>
                    <li><strong>Use Ctrl + `</strong> to toggle between formula view and results view.</li>
                    <li><strong>Document your formulas</strong> with comments if they're complex or will be used by others.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 10. Next Week Preview
                </div>
                <p><strong>Week 6: Text Functions & Data Manipulation</strong></p>
                <p>In Week 6, we'll build on your formula skills and learn to work with text data:</p>
                <ul>
                    <li><strong>Text Functions:</strong> LEFT, RIGHT, MID - extract parts of text strings</li>
                    <li><strong>Case Functions:</strong> UPPER, LOWER, PROPER - change text case</li>
                    <li><strong>Text Length:</strong> LEN - count characters in text</li>
                    <li><strong>Search Functions:</strong> FIND, SEARCH - locate text within strings</li>
                    <li><strong>Concatenation:</strong> CONCAT, TEXTJOIN - combine text from multiple cells</li>
                    <li><strong>Text-to-Columns</strong> feature for splitting data</li>
                    <li><strong>TRIM</strong> function to remove extra spaces</li>
                    <li><strong>REPLACE</strong> and SUBSTITUTE functions for text replacement</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring a dataset with text information (names, addresses, product codes, descriptions) to practice text functions.</p>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/excel" target="_blank">Microsoft Excel Official Support</a></li>
                    <li><a href="https://support.microsoft.com/office/overview-of-formulas-in-excel-ecfdc708-9162-49e8-b993-c311f47ca173" target="_blank">Overview of Formulas in Excel</a></li>
                    <li><a href="https://support.microsoft.com/office/if-function-69aed7c9-4e8a-4755-a9bc-aa8bbff73be2" target="_blank">IF Function Help</a></li>
                    <li><a href="https://support.microsoft.com/office/sum-function-043e1c7d-7726-4e80-8f32-07b23e057f89" target="_blank">SUM Function Help</a></li>
                    <li><a href="https://exceljet.net/keyboard-shortcuts" target="_blank">Excel Keyboard Shortcuts Reference</a></li>
                    <li><strong>Practice files with sample datasets</strong> available in the Course Portal</li>
                    <li><strong>Video tutorials</strong> on using IF, SUM, COUNT, and other functions</li>
                    <li><strong>Interactive formula exercises</strong> with instant feedback</li>
                    <li><strong>Week 5 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Formula Cheat Sheet</strong> downloadable PDF</li>
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
                    <li><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week5.php">Week 5 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft Excel Help:</strong> <a href="https://support.microsoft.com/excel" target="_blank">Official Support</a></li>
                    <li><strong>Formula Help Community:</strong> <a href="https://techcommunity.microsoft.com/t5/excel/ct-p/Excel" target="_blank">Microsoft Tech Community</a></li>
                    <li><strong>Quick Formula Reference:</strong> <a href="<?php echo BASE_URL; ?>modules/shared/resources/excel_formulas_cheatsheet.pdf">Download Cheat Sheet</a></li>
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
                <!-- <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week5_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 5 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 5 Handout</p>
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
            alert('Gradebook template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week5_gradebook.xlsx';
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
                console.log('Excel Week 5 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 5 Handout',
                //         action: 'view'
                //     })
                // });
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. COUNT counts only cells with numbers; COUNTA counts all non-empty cells.",
                    "2. Press F4 while the cell reference is selected, or add $ before column letter and/or row number.",
                    "3. =IF(A1>100, 'Yes', 'No')",
                    "4. AVERAGE calculates the mean (sum divided by count) of numbers.",
                    "5. Select the cell below the numbers and press Alt + = for AutoSum.",
                    "6. It becomes =A2+B2 (relative reference changes).",
                    "7. It becomes =$A$1+B2 (absolute part stays same, relative part changes)."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive function cards
        document.addEventListener('DOMContentLoaded', function() {
            const functionCards = document.querySelectorAll('.function-card');
            functionCards.forEach(card => {
                card.addEventListener('click', function() {
                    const functionName = this.querySelector('h3').textContent;
                    const example = this.querySelector('div[style*="font-family: monospace"]').textContent;
                    const descriptions = {
                        'SUM': 'Adds all numbers in a range of cells.',
                        'AVERAGE': 'Calculates the average (arithmetic mean) of numbers.',
                        'MIN': 'Returns the smallest number in a set of values.',
                        'MAX': 'Returns the largest number in a set of values.',
                        'COUNT': 'Counts cells that contain numbers.',
                        'COUNTA': 'Counts cells that are not empty.',
                        'COUNTBLANK': 'Counts empty cells in a range.',
                        'IF': 'Performs a logical test and returns one value if TRUE, another if FALSE.'
                    };
                    
                    alert(`${functionName} Function\n\nExample: ${example}\n\nPurpose: ${descriptions[functionName]}`);
                });
            });

            // Reference cards interaction
            const refCards = document.querySelectorAll('.ref-card');
            refCards.forEach(card => {
                card.addEventListener('click', function() {
                    const refType = this.querySelector('h3').textContent;
                    const example = this.querySelector('.ref-example').textContent;
                    const description = this.querySelector('p:nth-of-type(3)').textContent;
                    
                    alert(`${refType}\n\nExample: ${example}\n\n${description}\n\nUse: ${this.querySelector('p:last-child').textContent}`);
                });
            });

            // Excel grid interaction
            const excelCells = document.querySelectorAll('.excel-cell:not(.header)');
            excelCells.forEach(cell => {
                cell.addEventListener('click', function() {
                    if (this.classList.contains('formula') || this.classList.contains('if-formula')) {
                        const formula = this.textContent;
                        alert(`Formula: ${formula}\n\nClick OK to see this formula in action.`);
                    }
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                '=': 'Start Formula',
                '`': 'Show/Hide Formulas (Ctrl + `)',
                '4': 'Toggle References (F4)',
                'Enter': 'AutoSum (Alt + =)',
                '3': 'Name Manager (Ctrl + F3)'
            };
            
            // F4 simulation
            if (e.key === 'F4' || (e.ctrlKey && e.key === '4')) {
                e.preventDefault();
                const referenceTypes = ['A1 (Relative)', '$A$1 (Absolute)', 'A$1 (Mixed - row locked)', '$A1 (Mixed - column locked)', 'A1 (Relative)'];
                let current = 0;
                
                const cycleRef = () => {
                    const refAlert = document.createElement('div');
                    refAlert.style.cssText = `
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
                    refAlert.textContent = `Reference Type: ${referenceTypes[current]}`;
                    document.body.appendChild(refAlert);
                    
                    current = (current + 1) % referenceTypes.length;
                    
                    setTimeout(() => {
                        refAlert.remove();
                    }, 2000);
                };
                
                cycleRef();
            }
            
            // Alt + = simulation
            if (e.altKey && e.key === '=') {
                e.preventDefault();
                const sumAlert = document.createElement('div');
                sumAlert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #2196f3;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    z-index: 1000;
                    animation: fadeOut 2s forwards;
                `;
                sumAlert.textContent = 'AutoSum: Inserts =SUM() function with suggested range';
                document.body.appendChild(sumAlert);
                
                setTimeout(() => {
                    sumAlert.remove();
                }, 2000);
            }
            
            // Ctrl + ` simulation
            if (e.ctrlKey && e.key === '`') {
                e.preventDefault();
                const toggleAlert = document.createElement('div');
                toggleAlert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #9c27b0;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    z-index: 1000;
                    animation: fadeOut 2s forwards;
                `;
                toggleAlert.textContent = 'Toggle Formulas View: Shows formulas instead of results';
                document.body.appendChild(toggleAlert);
                
                setTimeout(() => {
                    toggleAlert.remove();
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
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard navigation hints
            const interactiveElements = document.querySelectorAll('a, button, .function-card, .ref-card, .excel-cell');
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

        // Formula builder simulation
        function buildFormula() {
            const functionName = prompt('Enter function name (SUM, AVERAGE, IF, etc.):', 'SUM');
            if (!functionName) return;
            
            const arguments = prompt(`Enter arguments for ${functionName} (e.g., A1:A10 or 10,20,30):`, 'A1:A10');
            if (!arguments) return;
            
            const formula = `=${functionName.toUpperCase()}(${arguments})`;
            alert(`Your formula:\n\n${formula}\n\nWould calculate: ${functionName} of ${arguments}`);
        }

        // IF function builder
        function buildIF() {
            const logicalTest = prompt('Enter logical test (e.g., A1>100, B2="Yes"):', 'A1>=60');
            if (!logicalTest) return;
            
            const valueIfTrue = prompt('Enter value if TRUE:', '"Pass"');
            if (!valueIfTrue) return;
            
            const valueIfFalse = prompt('Enter value if FALSE:', '"Fail"');
            if (!valueIfFalse) return;
            
            const formula = `=IF(${logicalTest}, ${valueIfTrue}, ${valueIfFalse})`;
            alert(`Your IF formula:\n\n${formula}\n\nTests: ${logicalTest}\nTrue: ${valueIfTrue}\nFalse: ${valueIfFalse}`);
        }

        // Reference type practice
        function practiceReferences() {
            const practice = [
                "Question 1: What reference type is A1?",
                "Answer: Relative reference",
                "\nQuestion 2: How do you make A1 absolute?",
                "Answer: Select A1 and press F4 to get $A$1",
                "\nQuestion 3: What does $A1 mean?",
                "Answer: Mixed reference - column A is absolute, row is relative",
                "\nQuestion 4: What does A$1 mean?",
                "Answer: Mixed reference - row 1 is absolute, column is relative",
                "\nQuestion 5: When should you use absolute references?",
                "Answer: When copying formulas that should always reference the same cell"
            ];
            alert("Reference Type Practice:\n\n" + practice.join("\n"));
        }

        // AutoFill demonstration for formulas
        function demonstrateFormulaFill() {
            const steps = [
                "Step 1: Enter formula in first cell (e.g., =A1*B1 in C1)",
                "Step 2: Select the cell with the formula",
                "Step 3: Hover over bottom-right corner until cursor changes to +",
                "Step 4: Click and drag down to fill formulas",
                "Step 5: Excel automatically adjusts relative references",
                "\nTry with: =SUM(A1:B1) in C1, then fill down to C10"
            ];
            alert("Formula AutoFill Demonstration:\n\n" + steps.join("\n"));
        }

        // Named range creation simulation
        function createNamedRange() {
            const rangeName = prompt('Enter name for the range (no spaces):', 'SalesData');
            if (!rangeName) return;
            
            const range = prompt('Enter cell range (e.g., A1:A100):', 'B2:B50');
            if (!range) return;
            
            alert(`Named Range Created:\n\nName: ${rangeName}\nRange: ${range}\n\nUse in formulas as: =SUM(${rangeName})`);
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
    $viewer = new ExcelWeek5HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>