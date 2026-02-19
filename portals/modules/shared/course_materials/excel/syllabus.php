<?php
// modules/shared/course_materials/MSExcel/excel_syllabus_view.php

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
 * MO-200 Excel Syllabus Viewer Class with PDF Download
 */
class ExcelSyllabusViewer
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
                AND (c.title LIKE '%Microsoft Excel (Office 2019)%' OR c.title LIKE '%MO-200%')";
        
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
                AND (c.title LIKE '%Microsoft Excel (Office 2019)%' OR c.title LIKE '%MO-200%')";
        
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
            $mpdf->SetTitle('MO-200 Microsoft Excel Certification Program Syllabus');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Microsoft Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Syllabus, Certification, Course Outline, Exam Preparation');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'MO-200_Excel_Certification_Syllabus_' . date('Y-m-d') . '.pdf';
            
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
                <button onclick="window.print()" style="padding: 10px 20px; background: #0a4c3e; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #0a4c3e; border-bottom: 3px solid #0a4c3e; padding-bottom: 15px; font-size: 20pt; text-align: center;">
                MO-200: Excel Mastery for Microsoft Excel (Office 2019) Certification
            </h1>
            
            <h2 style="color: #107c41; font-size: 16pt; margin-top: 25px; margin-bottom: 20px;">
                Complete Course Syllabus
            </h2>
            
            <!-- Program Overview -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f0f9f5; border-radius: 8px;">
                <h3 style="color: #0a4c3e; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #c3e8d8; padding-bottom: 10px;">
                    <i class="fas fa-bullseye"></i> Program Goal
                </h3>
                <p style="font-size: 12pt; line-height: 1.8;">
                    Prepare learners to master Microsoft Excel (Office 2019) and confidently pass the MO-200 certification exam through practical, hands-on training focused on real-world business applications.
                </p>
            </div>
            
            <!-- Program Structure -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px;">
                <h3 style="color: #0a4c3e; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-calendar-alt"></i> Program Structure
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #e8f5ef;">
                        <td style="padding: 10px; border: 1px solid #ddd; width: 30%; font-weight: bold;">Duration</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">8 Weeks (16 hours total)</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Format</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Weekly modules with hands-on exercises, real-world projects, and assessments</td>
                    </tr>
                    <tr style="background-color: #e8f5ef;">
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Target Audience</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Beginners to Intermediate Excel users</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Certification</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">MO-200: Microsoft Excel (Office 2019)</td>
                    </tr>
                </table>
            </div>
            
            <!-- Weekly Breakdown Header -->
            <div style="text-align: center; margin: 30px 0; padding: 15px; background: linear-gradient(135deg, #0a4c3e 0%, #107c41 100%); color: white; border-radius: 8px;">
                <h3 style="margin: 0; font-size: 16pt;">
                    <i class="fas fa-list-ol"></i> Weekly Breakdown
                </h3>
            </div>
            
            <!-- Week 1 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0a4c3e; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 1: Introduction to Excel & Workbook Basics
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Understand Excel interface and navigation</li>
                            <li>Create, save, and manage workbooks</li>
                            <li>Perform basic data entry and selection</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Excel interface, ribbons, and Quick Access Toolbar</li>
                            <li>Creating, saving, and opening workbooks</li>
                            <li>Navigating worksheets (cells, ranges, hyperlinks)</li>
                            <li>Basic data entry and selection techniques</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a simple budget sheet; Practice navigation shortcuts</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Import data from a .txt or .csv file</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 2 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #107c41; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 2: Worksheet Formatting & Printing
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Format worksheets for professional presentation</li>
                            <li>Configure print settings and page layout</li>
                            <li>Use advanced navigation features</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Adjusting row height, column width, and page setup</li>
                            <li>Headers, footers, and print areas</li>
                            <li>Freezing panes and splitting windows</li>
                            <li>Displaying formulas</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Format a sales report; Set up a printable invoice template</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Customize a workbook with headers and print settings</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 3 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0a4c3e; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 3: Data Entry, Ranges & Cell Formatting
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Master data entry and formatting techniques</li>
                            <li>Use special paste options and AutoFill</li>
                            <li>Apply professional cell formatting</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Special paste options (values, formats, formulas)</li>
                            <li>AutoFill and flash fill</li>
                            <li>Inserting/deleting rows, columns, cells</li>
                            <li>Merging cells, text wrapping, alignment</li>
                            <li>Using Format Painter and cell styles</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a formatted employee roster; Practice using named ranges</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Apply conditional formatting to a dataset</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 4 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #107c41; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 4: Tables & Data Organization
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Create and format Excel tables</li>
                            <li>Organize and analyze data efficiently</li>
                            <li>Filter and sort data for insights</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Creating and formatting Excel tables</li>
                            <li>Adding/removing table rows/columns</li>
                            <li>Table styles and total rows</li>
                            <li>Filtering and sorting data</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Convert a data range into a table; Filter and sort survey data</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Create a filtered product inventory table</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 5 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0a4c3e; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 5: Formulas & Basic Functions
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Understand and use cell references</li>
                            <li>Apply basic Excel functions</li>
                            <li>Create simple IF statements</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Relative, absolute, and mixed references</li>
                            <li>SUM, AVERAGE, MIN, MAX functions</li>
                            <li>COUNT, COUNTA, COUNTBLANK functions</li>
                            <li>Basic IF statements</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Build a grading sheet with calculations; Use IF() to assign pass/fail status</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Create a project expense tracker with formulas</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 6 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #107c41; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 6: Text Functions & Data Transformation
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Manipulate and transform text data</li>
                            <li>Combine text from multiple cells</li>
                            <li>Clean and prepare data for analysis</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>LEFT, RIGHT, MID functions</li>
                            <li>UPPER, LOWER, LEN functions</li>
                            <li>CONCAT, TEXTJOIN functions</li>
                            <li>Combining functions for advanced transformations</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Clean and format customer data; Merge first and last names using CONCAT</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Prepare a mailing list using text functions</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 7 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0a4c3e; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 7: Charts & Visual Summaries
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Create effective charts and graphs</li>
                            <li>Use Sparklines for data visualization</li>
                            <li>Apply conditional formatting for visual analysis</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Creating charts (column, line, pie)</li>
                            <li>Adding data series and chart elements</li>
                            <li>Switching rows/columns in charts</li>
                            <li>Sparklines and conditional formatting visuals</li>
                            <li>Chart styles and layouts</li>
                            <li>Accessibility: adding alt text to charts</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Visualize sales trends with charts; Add sparklines to a financial report</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Create a dashboard with multiple charts</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 8 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #107c41; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 8: Review, Collaboration & Exam Prep
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Prepare workbooks for sharing and printing</li>
                            <li>Review all course concepts</li>
                            <li>Prepare for MO-200 certification exam</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #107c41;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Inspecting workbooks for issues</li>
                            <li>Saving in alternative formats (PDF, .xlsx vs .csv)</li>
                            <li>Printing settings and collaboration tools</li>
                            <li>Full-length practice exam review</li>
                            <li>Q&A and test-taking strategies</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #107c41;">
                        <strong style="color: #0a4c3e;">Hands-On Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Final project: complete a mock business report using all skills; Review MO-200 sample questions</p>
                        <strong style="color: #0a4c3e; display: block; margin-top: 10px;">Homework:</strong>
                        <p style="margin: 5px 0 0 0;">Take a timed practice exam (if available)</p>
                    </div>
                </div>
            </div>
            
            <!-- Recommended Materials -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f0f9f5; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #0a4c3e; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #c3e8d8; padding-bottom: 10px;">
                    <i class="fas fa-book"></i> Recommended Materials
                </h3>
                <ul style="margin: 15px 0 0 20px;">
                    <li>Microsoft Excel 2019 installed on learner machines</li>
                    <li>Sample datasets for practice (provided weekly)</li>
                    <li>MO-200 practice tests and exam objectives handout</li>
                    <li>Quick reference guides for formulas and shortcuts</li>
                    <li>Access to recorded sessions and supplementary videos</li>
                </ul>
            </div>
            
            <!-- Assessment -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #0a4c3e; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                    <i class="fas fa-clipboard-check"></i> Assessment Breakdown
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #e8f5ef;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Weekly quizzes</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">10%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Hands-on assignments</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">30%</td>
                    </tr>
                    <tr style="background-color: #e8f5ef;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Mid-term project (Week 4)</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">20%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Final capstone project (Week 8)</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">40%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background-color: #e8f5e9;">Total</td>
                        <td style="padding: 12px; border: 1px solid #ddd; background-color: #e8f5e9; font-weight: bold;">100%</td>
                    </tr>
                </table>
            </div>
            
            <!-- Prerequisites -->
            <div style="margin-bottom: 25px; padding: 20px; background: #fff9e6; border-left: 5px solid #ff9800; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-exclamation-circle"></i> Prerequisites
                </h3>
                <ul style="margin: 15px 0 0 20px;">
                    <li>Basic computer literacy (file management, keyboard skills)</li>
                    <li>Access to Microsoft Excel 2019 or Office 365</li>
                    <li>Stable internet connection for online sessions</li>
                    <li>Commitment to 2-3 hours per week (session + practice)</li>
                    <li>Willingness to practice and apply skills to real-world scenarios</li>
                </ul>
            </div>
            
            <!-- Learning Outcomes -->
            <div style="margin-bottom: 25px; padding: 20px; background: #e8f5e9; border-left: 5px solid #4caf50; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-graduation-cap"></i> Learning Outcomes
                </h3>
                <p>Upon successful completion, students will be able to:</p>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Navigate Excel interface efficiently and use essential features</li>
                    <li>Create, format, and print professional worksheets</li>
                    <li>Organize and analyze data using tables and filters</li>
                    <li>Apply basic formulas and functions for calculations</li>
                    <li>Transform and clean data using text functions</li>
                    <li>Create effective charts and visual summaries</li>
                    <li>Prepare for and pass the MO-200 Excel certification exam</li>
                </ul>
            </div>
            
            <!-- Instructor Notes -->
            <div style="margin-bottom: 25px; padding: 20px; background: #e3f2fd; border-left: 5px solid #1976d2; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #1565c0; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Notes
                </h3>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Emphasize real-world applications (budgets, reports, dashboards)</li>
                    <li>Encourage use of Excel Help and online resources for self-learning</li>
                    <li>Provide extra support for formula logic and function nesting</li>
                    <li>Simulate exam conditions in final review session</li>
                    <li>Focus on practical problem-solving rather than memorization</li>
                    <li>Provide constructive feedback on assignments and projects</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 20px; margin-top: 30px; font-size: 10pt;">
                <h4 style="color: #0a4c3e; margin-bottom: 10px; font-size: 12pt;">Program Information</h4>
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
            <h1 style="color: #0a4c3e; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #107c41; font-size: 18pt; margin-bottom: 30px;">
                Excel Mastery for MO-200 Certification
            </h2>
            <h3 style="color: #333; font-size: 22pt; border-top: 3px solid #0a4c3e; 
                border-bottom: 3px solid #0a4c3e; padding: 25px 0; margin: 40px 0;">
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
                    Certification: MO-200 Microsoft Excel (Office 2019)
                </p>
            </div>
            <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 10pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 9pt;">
                    This syllabus outlines the complete MO-200 Excel Certification Prep Program. Unauthorized distribution is prohibited.
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
            MO-200 Microsoft Excel Certification Syllabus | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-200 Excel Certification Program | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>MO-200 Excel Certification Syllabus - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #107c41 0%, #0a4c3e 100%);
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
            background: linear-gradient(135deg, #0a4c3e 0%, #107c41 100%);
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
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect fill="none" width="100" height="100"/><path fill="rgba(255,255,255,0.05)" d="M50,0 L100,50 L50,100 L0,50 Z"/></svg>') repeat;
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
            color: #107c41;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #107c41;
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
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .week-header {
            background: #0a4c3e;
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .week-header.alt {
            background: #107c41;
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
            color: #0a4c3e;
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
            color: #107c41;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .activity-box {
            background: #f0f9f5;
            padding: 20px;
            border-left: 4px solid #107c41;
            border-radius: 0 5px 5px 0;
        }

        .activity-title {
            color: #0a4c3e;
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
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }

        .material-card:hover {
            background: #e8f5ef;
            border-color: #107c41;
        }

        .material-icon {
            font-size: 3rem;
            color: #107c41;
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
            background: #0a4c3e;
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
            background: #f8f9fa;
        }

        .assessment-table tr:last-child td {
            background: #e8f5e9;
            font-weight: bold;
        }

        .outcome-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .outcome-title {
            color: #2e7d32;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prerequisites-box {
            background: #fff9e6;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .prerequisites-title {
            color: #e65100;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .instructor-notes-box {
            background: #e3f2fd;
            border-left: 5px solid #1976d2;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .instructor-notes-title {
            color: #1565c0;
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
            background: #f0f9f5;
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
            background: #107c41;
        }

        .timeline-icon {
            font-size: 2.5rem;
            color: #107c41;
            margin-bottom: 10px;
        }

        .timeline-text {
            font-weight: 600;
            color: #0a4c3e;
        }

        .download-btn {
            display: inline-block;
            background: #107c41;
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
            background: #0a4c3e;
        }

        .download-section {
            text-align: center;
            margin: 40px 0;
            padding: 30px;
            background: #f0f9f5;
            border-radius: 8px;
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

        footer {
            text-align: center;
            padding: 30px;
            background-color: #f2f2f2;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #ddd;
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
                <strong>Access Granted:</strong> MO-200 Complete Syllabus
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
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">Excel Mastery for MO-200 Certification</div>
            <div style="font-size: 1.8rem; margin: 20px 0; font-weight: 300;">Microsoft Excel (Office 2019) Complete Syllabus</div>
            <div class="cert-badge">
                <i class="fas fa-certificate"></i> Microsoft Office Specialist Certification
            </div>
        </div>

        <div class="content">
            <!-- Program Overview -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Program Goal
                </div>
                <p style="font-size: 1.2rem; line-height: 1.8; margin-bottom: 20px; color: #555;">
                    Prepare learners to master Microsoft Excel (Office 2019) and confidently pass the MO-200 certification exam through practical, hands-on training focused on real-world business applications.
                </p>

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

                <!-- Program Structure -->
                <div style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-top: 30px;">
                    <h3 style="color: #0a4c3e; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-cogs"></i> Program Structure
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #107c41;">
                            <strong style="color: #0a4c3e; display: block; margin-bottom: 10px;">Format</strong>
                            <p>Weekly modules with hands-on exercises, real-world projects, and assessments</p>
                        </div>
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #107c41;">
                            <strong style="color: #0a4c3e; display: block; margin-bottom: 10px;">Target Audience</strong>
                            <p>Beginners to Intermediate Excel users</p>
                        </div>
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #107c41;">
                            <strong style="color: #0a4c3e; display: block; margin-bottom: 10px;">Total Hours</strong>
                            <p>16 hours (2 hours per week)</p>
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
                        <div class="week-title">Introduction to Excel & Workbook Basics</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Understand Excel interface and navigation</li>
                                <li>Create, save, and manage workbooks</li>
                                <li>Perform basic data entry and selection</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Excel interface, ribbons, and Quick Access Toolbar</li>
                                <li>Creating, saving, and opening workbooks</li>
                                <li>Navigating worksheets (cells, ranges, hyperlinks)</li>
                                <li>Basic data entry and selection techniques</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Create a simple budget sheet; Practice navigation shortcuts</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Import data from a .txt or .csv file</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 2 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">2</div>
                        <div class="week-title">Worksheet Formatting & Printing</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Format worksheets for professional presentation</li>
                                <li>Configure print settings and page layout</li>
                                <li>Use advanced navigation features</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Adjusting row height, column width, and page setup</li>
                                <li>Headers, footers, and print areas</li>
                                <li>Freezing panes and splitting windows</li>
                                <li>Displaying formulas</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Format a sales report; Set up a printable invoice template</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Customize a workbook with headers and print settings</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 3 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">3</div>
                        <div class="week-title">Data Entry, Ranges & Cell Formatting</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Master data entry and formatting techniques</li>
                                <li>Use special paste options and AutoFill</li>
                                <li>Apply professional cell formatting</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Special paste options (values, formats, formulas)</li>
                                <li>AutoFill and flash fill</li>
                                <li>Inserting/deleting rows, columns, cells</li>
                                <li>Merging cells, text wrapping, alignment</li>
                                <li>Using Format Painter and cell styles</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Create a formatted employee roster; Practice using named ranges</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Apply conditional formatting to a dataset</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 4 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">4</div>
                        <div class="week-title">Tables & Data Organization</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Create and format Excel tables</li>
                                <li>Organize and analyze data efficiently</li>
                                <li>Filter and sort data for insights</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Creating and formatting Excel tables</li>
                                <li>Adding/removing table rows/columns</li>
                                <li>Table styles and total rows</li>
                                <li>Filtering and sorting data</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Convert a data range into a table; Filter and sort survey data</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Create a filtered product inventory table</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 5 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">5</div>
                        <div class="week-title">Formulas & Basic Functions</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Understand and use cell references</li>
                                <li>Apply basic Excel functions</li>
                                <li>Create simple IF statements</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Relative, absolute, and mixed references</li>
                                <li>SUM, AVERAGE, MIN, MAX functions</li>
                                <li>COUNT, COUNTA, COUNTBLANK functions</li>
                                <li>Basic IF statements</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Build a grading sheet with calculations; Use IF() to assign pass/fail status</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Create a project expense tracker with formulas</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 6 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">6</div>
                        <div class="week-title">Text Functions & Data Transformation</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Manipulate and transform text data</li>
                                <li>Combine text from multiple cells</li>
                                <li>Clean and prepare data for analysis</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>LEFT, RIGHT, MID functions</li>
                                <li>UPPER, LOWER, LEN functions</li>
                                <li>CONCAT, TEXTJOIN functions</li>
                                <li>Combining functions for advanced transformations</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Clean and format customer data; Merge first and last names using CONCAT</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Prepare a mailing list using text functions</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 7 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">7</div>
                        <div class="week-title">Charts & Visual Summaries</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Create effective charts and graphs</li>
                                <li>Use Sparklines for data visualization</li>
                                <li>Apply conditional formatting for visual analysis</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Creating charts (column, line, pie)</li>
                                <li>Adding data series and chart elements</li>
                                <li>Switching rows/columns in charts</li>
                                <li>Sparklines and conditional formatting visuals</li>
                                <li>Chart styles and layouts</li>
                                <li>Accessibility: adding alt text to charts</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Visualize sales trends with charts; Add sparklines to a financial report</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Create a dashboard with multiple charts</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Week 8 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">8</div>
                        <div class="week-title">Review, Collaboration & Exam Prep</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Prepare workbooks for sharing and printing</li>
                                <li>Review all course concepts</li>
                                <li>Prepare for MO-200 certification exam</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Inspecting workbooks for issues</li>
                                <li>Saving in alternative formats (PDF, .xlsx vs .csv)</li>
                                <li>Printing settings and collaboration tools</li>
                                <li>Full-length practice exam review</li>
                                <li>Q&A and test-taking strategies</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Hands-On Activity
                            </div>
                            <p>Final project: complete a mock business report using all skills; Review MO-200 sample questions</p>
                            <div style="margin-top: 10px;">
                                <div class="activity-title" style="font-size: 1rem; margin-bottom: 5px;">
                                    <i class="fas fa-homework"></i> Homework
                                </div>
                                <p>Take a timed practice exam (if available)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommended Materials -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Recommended Materials
                </div>
                
                <div class="materials-grid">
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <h3>Excel Software</h3>
                        <p>Microsoft Excel 2019 or Office 365 installed on learner machines</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-database"></i>
                        </div>
                        <h3>Practice Datasets</h3>
                        <p>Sample datasets for hands-on practice (provided weekly)</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>Exam Resources</h3>
                        <p>MO-200 practice tests and exam objectives handout</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-quick"></i>
                        </div>
                        <h3>Quick Guides</h3>
                        <p>Reference guides for formulas, functions, and keyboard shortcuts</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <h3>Recorded Sessions</h3>
                        <p>Access to all live session recordings for review</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <h3>Support Resources</h3>
                        <p>Excel Help documentation and online learning resources</p>
                    </div>
                </div>
            </div>

            <!-- Assessment -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-clipboard-check"></i> Assessment & Grading
                </div>
                
                <p style="margin-bottom: 20px; font-size: 1.1rem;">
                    Student progress is evaluated through a balanced combination of assessments designed to measure both knowledge and practical application:
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
                            <td><strong>Weekly Quizzes</strong></td>
                            <td>Short knowledge checks covering key concepts from each week</td>
                            <td>10%</td>
                        </tr>
                        <tr>
                            <td><strong>Hands-on Assignments</strong></td>
                            <td>Practical exercises applying skills learned each week to real-world scenarios</td>
                            <td>30%</td>
                        </tr>
                        <tr>
                            <td><strong>Mid-term Project (Week 4)</strong></td>
                            <td>Comprehensive project demonstrating mastery of tables, formatting, and data organization</td>
                            <td>20%</td>
                        </tr>
                        <tr>
                            <td><strong>Final Capstone Project (Week 8)</strong></td>
                            <td>Complete business report incorporating all Excel skills learned throughout the program</td>
                            <td>40%</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Total</strong></td>
                            <td><strong>100%</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 25px; padding: 20px; background: #f0f9f5; border-radius: 8px;">
                    <h4 style="color: #0a4c3e; margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle"></i> Grading Scale
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">
                        <div style="text-align: center; padding: 15px; background: #e8f5e9; border-radius: 5px;">
                            <strong style="color: #2e7d32;">90-100%</strong><br>Excellent
                        </div>
                        <div style="text-align: center; padding: 15px; background: #e3f2fd; border-radius: 5px;">
                            <strong style="color: #1976d2;">80-89%</strong><br>Good
                        </div>
                        <div style="text-align: center; padding: 15px; background: #fff8e1; border-radius: 5px;">
                            <strong style="color: #ff8f00;">70-79%</strong><br>Satisfactory
                        </div>
                        <div style="text-align: center; padding: 15px; background: #ffebee; border-radius: 5px;">
                            <strong style="color: #d32f2f;">Below 70%</strong><br>Needs Improvement
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prerequisites -->
            <div class="prerequisites-box">
                <div class="prerequisites-title">
                    <i class="fas fa-exclamation-circle"></i> Prerequisites
                </div>
                <p>To successfully complete this program, students should have:</p>
                <ul>
                    <li><strong>Basic computer literacy:</strong> Familiarity with file management, keyboard, and mouse skills</li>
                    <li><strong>Software access:</strong> Microsoft Excel 2019 or Office 365 installed on their computer</li>
                    <li><strong>Internet connection:</strong> Stable internet for attending sessions and accessing materials</li>
                    <li><strong>Time commitment:</strong> Ability to dedicate 2-3 hours per week (session + practice)</li>
                    <li><strong>Learning attitude:</strong> Willingness to practice and apply skills to real-world scenarios</li>
                </ul>
            </div>

            <!-- Learning Outcomes -->
            <div class="outcome-box">
                <div class="outcome-title">
                    <i class="fas fa-graduation-cap"></i> Learning Outcomes
                </div>
                <p>Upon successful completion of this program, students will be able to:</p>
                <ul>
                    <li>Navigate the Excel interface efficiently and use essential features</li>
                    <li>Create, format, and print professional worksheets and workbooks</li>
                    <li>Organize and analyze data using tables, filters, and sorting</li>
                    <li>Apply basic formulas and functions for calculations and data analysis</li>
                    <li>Transform and clean data using text functions for better reporting</li>
                    <li>Create effective charts, graphs, and visual summaries of data</li>
                    <li>Prepare workbooks for sharing, collaboration, and printing</li>
                    <li>Pass the MO-200 Microsoft Excel certification exam with confidence</li>
                </ul>
            </div>

            <!-- Instructor Notes -->
            <div class="instructor-notes-box">
                <div class="instructor-notes-title">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Notes & Teaching Approach
                </div>
                <ul>
                    <li><strong>Real-World Focus:</strong> Emphasize practical applications (budgets, reports, dashboards, business analysis)</li>
                    <li><strong>Self-Learning Skills:</strong> Encourage use of Excel Help and online resources for independent problem-solving</li>
                    <li><strong>Formula Support:</strong> Provide extra guidance on formula logic, function nesting, and error troubleshooting</li>
                    <li><strong>Exam Simulation:</strong> Simulate MO-200 exam conditions during the final review session</li>
                    <li><strong>Practical Problem-Solving:</strong> Focus on practical application rather than rote memorization</li>
                    <li><strong>Constructive Feedback:</strong> Provide detailed, actionable feedback on assignments and projects</li>
                    <li><strong>Differentiated Support:</strong> Offer additional assistance for students struggling with specific concepts</li>
                </ul>
            </div>

            <!-- Additional Information -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Additional Information
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 20px;">
                    <div style="padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                        <h4 style="color: #0a4c3e; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-certificate"></i> Certification Details
                        </h4>
                        <p>The MO-200: Microsoft Excel (Office 2019) certification validates skills in creating and managing worksheets, applying formulas and functions, creating charts, and analyzing data.</p>
                        <p style="margin-top: 10px;"><strong>Exam Format:</strong> Performance-based, 45-50 questions</p>
                        <p><strong>Exam Duration:</strong> 50 minutes</p>
                        <p><strong>Passing Score:</strong> 700 out of 1000</p>
                    </div>
                    
                    <div style="padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                        <h4 style="color: #0a4c3e; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-clock"></i> Time Commitment & Expectations
                        </h4>
                        <p>Students should plan for approximately 2-3 hours per week:</p>
                        <ul style="margin-top: 10px;">
                            <li>2 hours: Live session instruction and guided practice</li>
                            <li>30-60 minutes: Hands-on exercises and assignments</li>
                            <li>Optional: Additional practice with provided datasets</li>
                        </ul>
                        <p style="margin-top: 15px;"><strong>Success Tip:</strong> Regular practice between sessions is key to mastery.</p>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div class="download-section">
                <h3 style="color: #0a4c3e; margin-bottom: 20px;">
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
            <div style="background: #ffebee; padding: 25px; border-radius: 8px; margin-top: 40px;">
                <h3 style="color: #d32f2f; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-question-circle"></i> Need Help or Have Questions?
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Instructor Support</h4>
                        <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                        <p><strong>Office Hours:</strong> Wednesdays & Fridays, 3:00 PM - 5:00 PM</p>
                        <p><strong>Response Time:</strong> 24-48 hours for email inquiries</p>
                    </div>
                    <div>
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Technical & Academic Support</h4>
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #0a4c3e; text-decoration: none; font-weight: bold;">Access Portal</a></p>
                        <p><strong>Discussion Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-syllabus.php" style="color: #0a4c3e; text-decoration: none; font-weight: bold;">Excel Syllabus Questions</a></p>
                        <p><strong>Technical Issues:</strong> support@impactdigitalacademy.com</p>
                        <p><strong>Academic Questions:</strong> academics@impactdigitalacademy.com</p>
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <p><strong>MO-200: Excel Mastery for Microsoft Excel Certification - Complete Syllabus</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This syllabus outlines the complete MO-200 Excel Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.9rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.9rem;">
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
                console.log('MO-200 syllabus access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
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

        // Keyboard shortcuts for instructors
        <?php if ($this->user_role === 'instructor'): ?>
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                alert('Instructor Quick Access:\n\n1. Press Ctrl+1-8 to jump to specific week\n2. Press Ctrl+P to print\n3. Press Ctrl+D to download PDF\n4. Press Ctrl+S to view student progress');
            }
        });
        <?php endif; ?>
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
    $viewer = new ExcelSyllabusViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>