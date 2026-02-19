<?php
// modules/shared/course_materials/MSExcel/excel_week1_view.php

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
 * Excel Week 1 Handout Viewer Class with PDF Download
 */
class ExcelWeek1HandoutViewer
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
            $mpdf->SetTitle('Week 1: Introduction to Excel & Workbook Basics');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Introduction, Interface, Workbook, Navigation, Data Entry');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week1_Introduction_' . date('Y-m-d') . '.pdf';
            
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
                Week 1: Introduction to Excel & Workbook Basics
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 1!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll be introduced to the Microsoft Excel interface and learn the foundational skills needed to navigate, create, and manage workbooks. By the end of this session, you'll be comfortable with Excel's layout, basic data entry, and essential navigation tools.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Identify and navigate key components of the Excel 2019 interface</li>
                    <li>Create, save, and manage workbooks in different formats</li>
                    <li>Enter and edit various types of data in worksheets</li>
                    <li>Use basic navigation tools and shortcuts efficiently</li>
                    <li>Import data from external text and CSV files</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Understanding the Excel Interface</h3>
                <p><strong>Excel Start Screen & Workbook Creation:</strong></p>
                <ul>
                    <li>When Excel opens, you can choose a blank workbook, open an existing file, or select a template</li>
                    <li>To create a new workbook: <strong>File → New → Blank Workbook</strong> or press <strong>Ctrl + N</strong></li>
                </ul>
                
                <p><strong>Key Areas of the Excel Window:</strong></p>
                <ul>
                    <li><strong>Quick Access Toolbar (QAT)</strong> – Located at top-left, customizable with your most-used commands</li>
                    <li><strong>Ribbon</strong> – Contains tabs (Home, Insert, Page Layout, Formulas, Data, Review, View)</li>
                    <li><strong>Formula Bar</strong> – Displays the content of the active cell</li>
                    <li><strong>Worksheet Area</strong> – Grid of rows and columns, each rectangle is a cell (e.g., A1, B5)</li>
                    <li><strong>Sheet Tabs</strong> – Navigate between sheets, add new sheets with + icon</li>
                    <li><strong>Status Bar</strong> – Shows information like sum, average, or count of selected cells</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Navigating Worksheets</h3>
                <p><strong>Moving Around:</strong></p>
                <ul>
                    <li>Use mouse, arrow keys, or Enter/Tab to move between cells</li>
                    <li>Go to a specific cell: Press <strong>F5</strong> or <strong>Ctrl + G</strong>, then type the cell reference</li>
                </ul>
                
                <p><strong>Selecting Cells and Ranges:</strong></p>
                <ul>
                    <li>Single cell: Click on it</li>
                    <li>Range of cells: Click and drag, or use Shift + Arrow keys</li>
                    <li>Entire row/column: Click the row number or column letter</li>
                    <li>Entire sheet: Click the triangle at top-left (between A and 1), or press <strong>Ctrl + A</strong></li>
                </ul>
                
                <p><strong>Using Named Cells and Ranges:</strong></p>
                <ul>
                    <li>A named range is a descriptive name for a cell or group of cells</li>
                    <li>To create: Select cell(s) → Type name in Name Box → Press Enter</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Basic Workbook Operations</h3>
                <p><strong>Creating and Saving Workbooks:</strong></p>
                <ul>
                    <li>Save a workbook: <strong>File → Save</strong> or <strong>Ctrl + S</strong></li>
                    <li>Save As: File → Save As to save in different location or format</li>
                    <li>Recommended formats: .xlsx (standard), .xls (older), .csv (text)</li>
                </ul>
                
                <p><strong>Opening and Closing Workbooks:</strong></p>
                <ul>
                    <li>Open: <strong>File → Open</strong> or <strong>Ctrl + O</strong></li>
                    <li>Close: File → Close or click X on window</li>
                </ul>
                
                <p><strong>Inserting and Removing Hyperlinks:</strong></p>
                <ul>
                    <li>Insert a hyperlink: Select cell → <strong>Insert → Link</strong> or <strong>Ctrl + K</strong></li>
                    <li>Link to: Web page, another location in workbook, or email address</li>
                    <li>Remove hyperlink: Right-click cell → Remove Hyperlink</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">4. Data Entry Basics</h3>
                <p><strong>Types of Data:</strong></p>
                <ul>
                    <li><strong>Text (Labels)</strong> – Left-aligned by default</li>
                    <li><strong>Numbers (Values)</strong> – Right-aligned by default</li>
                    <li><strong>Dates/Times</strong> – Stored as numbers but displayed in date format</li>
                    <li><strong>Formulas</strong> – Begin with = (e.g., =A1+B1)</li>
                </ul>
                
                <p><strong>Entering and Editing Data:</strong></p>
                <ul>
                    <li>To enter data: Click cell, type, press Enter or Tab</li>
                    <li>To edit: Double-click cell, or press <strong>F2</strong></li>
                    <li>To clear contents: Select cell(s) and press Delete</li>
                </ul>
                
                <p><strong>AutoFill and Series:</strong></p>
                <ul>
                    <li>Use fill handle (small square at cell's bottom-right corner)</li>
                    <li>Copy content or fill series (days, months, numbers)</li>
                    <li>Try: Type "Monday" in a cell, then drag the fill handle</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">5. Importing Data from External Files</h3>
                <p><strong>Importing from Text (.txt) or CSV (.csv) Files:</strong></p>
                <ul>
                    <li>Go to <strong>Data → Get External Data → From Text</strong></li>
                    <li>Browse and select your file</li>
                    <li>Follow Text Import Wizard: Choose Delimited or Fixed width</li>
                </ul>
                
                <p><strong>Why Import?</strong></p>
                <ul>
                    <li>Bring data from other systems (databases, surveys) into Excel</li>
                    <li>Maintain data structure without manual typing</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Create a Simple Contact List</h3>
                <p><strong>Objective:</strong> Build a basic contact list to practice navigation, data entry, and formatting.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Open a new workbook</li>
                    <li>In cell A1, type "Name"</li>
                    <li>In B1, type "Email"</li>
                    <li>In C1, type "Phone"</li>
                    <li>Enter 5 sample contacts (make them up)</li>
                    <li>Bold the header row (Ctrl + B)</li>
                    <li>Adjust column widths by double-clicking between column letters</li>
                    <li>Add a hyperlink in the "Email" column for one contact</li>
                    <li>Save the file as MyContactList.xlsx</li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Essential Shortcuts for Week 1</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + N</td>
                            <td style="padding: 6px 8px;">New workbook</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + O</td>
                            <td style="padding: 6px 8px;">Open workbook</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + S</td>
                            <td style="padding: 6px 8px;">Save</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + P</td>
                            <td style="padding: 6px 8px;">Print</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Z</td>
                            <td style="padding: 6px 8px;">Undo</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Y</td>
                            <td style="padding: 6px 8px;">Redo</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F5</td>
                            <td style="padding: 6px 8px;">Go to specific cell</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + G</td>
                            <td style="padding: 6px 8px;">Go to</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + A</td>
                            <td style="padding: 6px 8px;">Select all</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + K</td>
                            <td style="padding: 6px 8px;">Insert hyperlink</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">F2</td>
                            <td style="padding: 6px 8px;">Edit active cell</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Workbook:</strong> An Excel file containing one or more worksheets.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Worksheet:</strong> A single spreadsheet within a workbook.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Cell:</strong> The intersection of a row and column (e.g., A1, B5).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Cell Reference:</strong> The address of a cell (column letter + row number).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Range:</strong> A group of adjacent cells.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Formula Bar:</strong> Displays the content of the active cell.</p>
                </div>
                <div>
                    <p><strong>Name Box:</strong> Shows the address of the active cell and can be used to name ranges.</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Import Practice:</strong>
                        <ul>
                            <li>Download a sample .csv file (e.g., from Kaggle)</li>
                            <li>Import it into Excel using the Text Import Wizard</li>
                            <li>Save the imported data as an Excel workbook</li>
                        </ul>
                    </li>
                    <li><strong>Navigation Drill:</strong>
                        <ul>
                            <li>Open a blank workbook</li>
                            <li>Name cell D10 as "Total"</li>
                            <li>Use F5 to jump to "Total"</li>
                            <li>Insert a hyperlink in cell A1 that links to Microsoft's Excel help page</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What is the shortcut to create a new workbook?</li>
                            <li>Where is the Formula Bar located?</li>
                            <li>How do you edit a cell without using the mouse?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Navigate Excel interface</li>
                    <li>Create and save workbooks</li>
                    <li>Enter and edit data</li>
                    <li>Use navigation tools</li>
                    <li>Insert hyperlinks</li>
                    <li>Import external data</li>
                    <li>Use named ranges</li>
                    <li>Basic data formatting</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Practice daily:</strong> Even 15–20 minutes helps build proficiency.</li>
                    <li><strong>Use Excel's Help feature (F1)</strong> when stuck.</li>
                    <li><strong>Explore the right-click menu</strong> – it often has the option you need.</li>
                    <li><strong>Don't rush:</strong> Accuracy is more important than speed at this stage.</li>
                    <li><strong>Save frequently:</strong> Use Ctrl + S to avoid losing work.</li>
                    <li><strong>Name important ranges:</strong> Makes navigation and formula creation easier.</li>
                    <li><strong>Use templates:</strong> Start with built-in templates for common tasks.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 2, we'll cover:</p>
                <ul>
                    <li>Adjusting row height and column width</li>
                    <li>Page setup, headers, and footers</li>
                    <li>Freezing panes and printing areas</li>
                    <li>Displaying formulas</li>
                    <li>Basic cell formatting techniques</li>
                    <li>Using themes and styles</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring a dataset you'd like to format and analyze.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Interface Guide</li>
                    <li>Data Import and Navigation Techniques Tutorial</li>
                    <li>Practice files and tutorial videos available in the Course Portal</li>
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
                Week 1 Handout: Introduction to Excel & Workbook Basics
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
            Week 1: Excel Introduction & Basics | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 1: Introduction to Excel & Workbook Basics - Impact Digital Academy</title>
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

        /* Excel Interface Demo */
        .interface-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .interface-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .interface-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .interface-icon {
            font-size: 3rem;
            color: #107c10;
            margin-bottom: 15px;
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

        /* Data Type Cards */
        .type-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .type-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .type-card.text {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .type-card.number {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .type-card.date {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .type-card.formula {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .type-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .type-card.text .type-icon {
            color: #2196f3;
        }

        .type-card.number .type-icon {
            color: #4caf50;
        }

        .type-card.date .type-icon {
            color: #9c27b0;
        }

        .type-card.formula .type-icon {
            color: #ff9800;
        }

        /* File Format Cards */
        .format-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .format-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .format-card.xlsx {
            border-color: #107c10;
            background: #e8f4e8;
        }

        .format-card.xls {
            border-color: #757575;
            background: #f5f5f5;
        }

        .format-card.csv {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .format-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .format-card.xlsx .format-icon {
            color: #107c10;
        }

        .format-card.xls .format-icon {
            color: #757575;
        }

        .format-card.csv .format-icon {
            color: #ff9800;
        }

        /* Excel Grid Demo */
        .excel-grid {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            grid-template-rows: repeat(10, 1fr);
            gap: 1px;
            background: #ddd;
            border: 2px solid #107c10;
            margin: 20px 0;
            font-family: monospace;
        }

        .excel-cell {
            background: white;
            padding: 8px;
            text-align: center;
            border: 1px solid #eee;
            font-size: 0.8rem;
        }

        .excel-cell.header {
            background: #107c10;
            color: white;
            font-weight: bold;
        }

        .excel-cell.active {
            background: #e8f4e8;
            border: 2px solid #107c10;
        }

        .excel-cell.formula {
            background: #fff3e0;
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
            .demo-table {
                font-size: 0.9rem;
            }

            .shortcut-table th,
            .shortcut-table td,
            .demo-table th,
            .demo-table td {
                padding: 10px;
            }

            .interface-demo,
            .type-cards,
            .format-cards {
                flex-direction: column;
            }
            
            .excel-grid {
                grid-template-columns: repeat(5, 1fr);
                grid-template-rows: repeat(5, 1fr);
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
            
            .excel-grid {
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
                <strong>Access Granted:</strong> Excel Week 1 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week2_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 2
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 1 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Introduction to Excel & Workbook Basics</div>
            <div class="week-tag">Week 1 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 1!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll be introduced to the Microsoft Excel interface and learn the foundational skills needed to navigate, create, and manage workbooks. By the end of this session, you'll be comfortable with Excel's layout, basic data entry, and essential navigation tools.
                </p>

                <div class="image-container">
                    <img src="images/excel_interface.png"
                        alt="Microsoft Excel Interface"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+TWljcm9zb2Z0IEV4Y2VsIEludGVyZmFjZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Microsoft Excel 2019 Interface Overview</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Identify and navigate key components of the Excel 2019 interface</li>
                    <li>Create, save, and manage workbooks in different formats</li>
                    <li>Enter and edit various types of data in worksheets</li>
                    <li>Use basic navigation tools and shortcuts efficiently</li>
                    <li>Import data from external text and CSV files</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Navigate Excel interface</li>
                        <li>Create and save workbooks</li>
                        <li>Enter and edit data</li>
                        <li>Use navigation tools</li>
                    </ul>
                    <ul>
                        <li>Insert hyperlinks</li>
                        <li>Import external data</li>
                        <li>Use named ranges</li>
                        <li>Basic data formatting</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Excel Interface -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-desktop"></i> 1. Understanding the Excel Interface
                </div>

                <!-- Excel Grid Demo -->
                <div class="excel-grid">
                    <div class="excel-cell header">A</div>
                    <div class="excel-cell header">B</div>
                    <div class="excel-cell header">C</div>
                    <div class="excel-cell header">D</div>
                    <div class="excel-cell header">E</div>
                    <div class="excel-cell header">F</div>
                    <div class="excel-cell header">G</div>
                    <div class="excel-cell header">H</div>
                    <div class="excel-cell header">I</div>
                    <div class="excel-cell header">J</div>
                    
                    <div class="excel-cell header">1</div>
                    <div class="excel-cell active">Name</div>
                    <div class="excel-cell">Email</div>
                    <div class="excel-cell">Phone</div>
                    <div class="excel-cell">Department</div>
                    <div class="excel-cell">Salary</div>
                    <div class="excel-cell">Hire Date</div>
                    <div class="excel-cell">Status</div>
                    <div class="excel-cell">Score</div>
                    <div class="excel-cell">Bonus</div>
                    
                    <div class="excel-cell header">2</div>
                    <div class="excel-cell">John Smith</div>
                    <div class="excel-cell">john@email.com</div>
                    <div class="excel-cell">(555) 123-4567</div>
                    <div class="excel-cell">Sales</div>
                    <div class="excel-cell">$65,000</div>
                    <div class="excel-cell">2022-03-15</div>
                    <div class="excel-cell">Active</div>
                    <div class="excel-cell">95</div>
                    <div class="excel-cell formula">=F2*0.1</div>
                    
                    <div class="excel-cell header">3</div>
                    <div class="excel-cell">Jane Doe</div>
                    <div class="excel-cell">jane@email.com</div>
                    <div class="excel-cell">(555) 987-6543</div>
                    <div class="excel-cell">Marketing</div>
                    <div class="excel-cell">$58,000</div>
                    <div class="excel-cell">2021-11-22</div>
                    <div class="excel-cell">Active</div>
                    <div class="excel-cell">88</div>
                    <div class="excel-cell formula">=F3*0.1</div>
                    
                    <div class="excel-cell header">4</div>
                    <div class="excel-cell">Bob Johnson</div>
                    <div class="excel-cell">bob@email.com</div>
                    <div class="excel-cell">(555) 456-7890</div>
                    <div class="excel-cell">IT</div>
                    <div class="excel-cell">$72,000</div>
                    <div class="excel-cell">2020-07-30</div>
                    <div class="excel-cell">Active</div>
                    <div class="excel-cell">92</div>
                    <div class="excel-cell formula">=F4*0.1</div>
                    
                    <div class="excel-cell header">5</div>
                    <div class="excel-cell">Sara Wilson</div>
                    <div class="excel-cell">sara@email.com</div>
                    <div class="excel-cell">(555) 234-5678</div>
                    <div class="excel-cell">HR</div>
                    <div class="excel-cell">$54,000</div>
                    <div class="excel-cell">2023-01-10</div>
                    <div class="excel-cell">Active</div>
                    <div class="excel-cell">85</div>
                    <div class="excel-cell formula">=F5*0.1</div>
                </div>

                <div class="interface-demo">
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <h4>Quick Access Toolbar</h4>
                        <p>Customizable toolbar for most-used commands (Save, Undo, Redo)</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-bars"></i>
                        </div>
                        <h4>Ribbon</h4>
                        <p>Tabs (Home, Insert, Formulas, Data) with grouped commands</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <h4>Formula Bar</h4>
                        <p>Displays content of active cell (text, numbers, formulas)</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h4>Status Bar</h4>
                        <p>Shows sum, average, count of selected cells</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-excel"></i> Excel Start Screen & Workbook Creation</h3>
                    <ul>
                        <li>When Excel opens, you can choose a <strong>blank workbook</strong>, open an existing file, or select a <strong>template</strong></li>
                        <li>To create a new workbook: <strong>File → New → Blank Workbook</strong> or press <strong>Ctrl + N</strong></li>
                        <li>Popular templates: Budget, Calendar, Invoice, Schedule</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Navigation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-search"></i> 2. Navigating Worksheets
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-mouse-pointer"></i> Moving Around & Selecting</h3>
                    <ul>
                        <li>Use <strong>mouse</strong>, <strong>arrow keys</strong>, or <strong>Enter/Tab</strong> to move between cells</li>
                        <li>Go to specific cell: Press <strong>F5</strong> or <strong>Ctrl + G</strong>, then type cell reference</li>
                        <li>Select range: Click and drag, or use <strong>Shift + Arrow keys</strong></li>
                        <li>Select entire sheet: Click triangle at top-left, or press <strong>Ctrl + A</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-tag"></i> Using Named Cells and Ranges</h3>
                    <ul>
                        <li>A named range is a <strong>descriptive name</strong> for a cell or group of cells</li>
                        <li>To create: Select cell(s) → Type name in <strong>Name Box</strong> → Press <strong>Enter</strong></li>
                        <li>Example: Name cell B10 as "TotalSales" for easier reference in formulas</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/name_box.png"
                            alt="Excel Name Box"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBOYW1lIEJveDwvdGV4dD48L3N2Zz4='">
                        <div class="image-caption">Name Box for creating named ranges</div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Workbook Operations -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file"></i> 3. Basic Workbook Operations
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-save"></i> Creating and Saving Workbooks</h3>
                    <ul>
                        <li>Save: <strong>File → Save</strong> or <strong>Ctrl + S</strong></li>
                        <li>Save As: File → Save As to save in different location or format</li>
                        <li><strong>AutoSave</strong>: When saved to OneDrive/SharePoint</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-export"></i> File Formats Overview</h3>
                    <div class="format-cards">
                        <div class="format-card xlsx">
                            <div class="format-icon">
                                <i class="fas fa-file-excel"></i>
                            </div>
                            <h4>.xlsx</h4>
                            <p>Standard Excel format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Default, modern format</p>
                        </div>
                        <div class="format-card xls">
                            <div class="format-icon">
                                <i class="fas fa-file-excel"></i>
                            </div>
                            <h4>.xls</h4>
                            <p>Legacy Excel format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Compatibility mode</p>
                        </div>
                        <div class="format-card csv">
                            <div class="format-icon">
                                <i class="fas fa-file-csv"></i>
                            </div>
                            <h4>.csv</h4>
                            <p>Comma Separated Values</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Text data, no formatting</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-link"></i> Inserting and Removing Hyperlinks</h3>
                    <ul>
                        <li>Insert hyperlink: Select cell → <strong>Insert → Link</strong> or <strong>Ctrl + K</strong></li>
                        <li>Link to: Web page, location in workbook, email address, or file</li>
                        <li>Remove: Right-click cell → <strong>Remove Hyperlink</strong></li>
                        <li>Example: Link company name to website, email to mailto:</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Data Entry -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 4. Data Entry Basics
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-list"></i> Types of Data in Excel</h3>
                    <div class="type-cards">
                        <div class="type-card text">
                            <div class="type-icon">
                                <i class="fas fa-font"></i>
                            </div>
                            <h4>Text (Labels)</h4>
                            <p>Left-aligned by default</p>
                            <p>Names, descriptions, categories</p>
                        </div>
                        <div class="type-card number">
                            <div class="type-icon">
                                <i class="fas fa-hashtag"></i>
                            </div>
                            <h4>Numbers (Values)</h4>
                            <p>Right-aligned by default</p>
                            <p>Quantities, prices, scores</p>
                        </div>
                        <div class="type-card date">
                            <div class="type-icon">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <h4>Dates/Times</h4>
                            <p>Stored as numbers</p>
                            <p>Dates, times, timestamps</p>
                        </div>
                        <div class="type-card formula">
                            <div class="type-icon">
                                <i class="fas fa-equals"></i>
                            </div>
                            <h4>Formulas</h4>
                            <p>Begin with = sign</p>
                            <p>=A1+B1, =SUM(A1:A10)</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-edit"></i> Entering and Editing Data</h3>
                    <ul>
                        <li>To enter: Click cell, type, press <strong>Enter</strong> or <strong>Tab</strong></li>
                        <li>To edit: <strong>Double-click</strong> cell, or press <strong>F2</strong></li>
                        <li>To clear: Select cell(s) and press <strong>Delete</strong></li>
                        <li>To cancel: Press <strong>Esc</strong> before finishing entry</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-fill-drip"></i> AutoFill and Series</h3>
                    <ul>
                        <li>Use <strong>fill handle</strong> (small square at cell's bottom-right)</li>
                        <li>Copy content or fill series (days, months, numbers)</li>
                        <li>Examples: Type "Jan" → drag to fill Feb, Mar, Apr...</li>
                        <li>Type "1, 2" → select both → drag to continue 3, 4, 5...</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/autofill.png"
                            alt="Excel AutoFill"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBBdXRvRmlsbDwvdGV4dD48L3N2Zz4='">
                        <div class="image-caption">Using AutoFill to create series and copy formulas</div>
                    </div>
                </div>
            </div>

            <!-- Section 5: Importing Data -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-import"></i> 5. Importing Data from External Files
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-alt"></i> Importing from Text or CSV Files</h3>
                    <ul>
                        <li>Go to <strong>Data → Get External Data → From Text</strong></li>
                        <li>Browse and select your .txt or .csv file</li>
                        <li>Follow Text Import Wizard steps:</li>
                        <ol style="margin-left: 20px;">
                            <li><strong>Choose file type:</strong> Delimited or Fixed width</li>
                            <li><strong>Set delimiters:</strong> Comma, Tab, Semicolon, Space</li>
                            <li><strong>Format columns:</strong> General, Text, Date</li>
                            <li><strong>Choose import location:</strong> Existing or new worksheet</li>
                        </ol>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-question-circle"></i> Why Import External Data?</h3>
                    <ul>
                        <li>Bring data from other systems (databases, surveys, ERP)</li>
                        <li>Maintain data structure without manual typing</li>
                        <li>Automate data updates with refresh options</li>
                        <li>Work with large datasets efficiently</li>
                        <li>Combine data from multiple sources</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Import Tips
                        </div>
                        <ul>
                            <li>Preview data in Text Import Wizard before importing</li>
                            <li>Choose correct delimiter (comma for .csv, tab for .tsv)</li>
                            <li>Set text format for numbers with leading zeros (like ZIP codes)</li>
                            <li>Use "Get External Data" connection to refresh data later</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 6. Essential Shortcuts for Week 1
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
                            <td><span class="shortcut-key">Ctrl + N</span></td>
                            <td>New workbook</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + O</span></td>
                            <td>Open workbook</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Print</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Z</span></td>
                            <td>Undo</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Y</span></td>
                            <td>Redo</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F5</span></td>
                            <td>Go to specific cell</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + G</span></td>
                            <td>Go to</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + A</span></td>
                            <td>Select all</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + K</span></td>
                            <td>Insert hyperlink</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F2</span></td>
                            <td>Edit active cell</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + F</span></td>
                            <td>Find</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + H</span></td>
                            <td>Replace</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Tab</span></td>
                            <td>Move to next cell</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Tab</span></td>
                            <td>Move to previous cell</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 7. Hands-On Exercise: Create a Simple Contact List
                </div>
                <p><strong>Objective:</strong> Build a basic contact list to practice navigation, data entry, and formatting.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open Excel and create a <strong>new workbook</strong> (Ctrl + N)</li>
                        <li>In cell <strong>A1</strong>, type "Name"</li>
                        <li>In cell <strong>B1</strong>, type "Email"</li>
                        <li>In cell <strong>C1</strong>, type "Phone"</li>
                        <li>Enter 5 sample contacts (make them up)</li>
                        <li><strong>Bold the header row</strong> (Ctrl + B on row 1)</li>
                        <li>Adjust column widths by <strong>double-clicking</strong> between column letters</li>
                        <li>Add a <strong>hyperlink</strong> in the "Email" column for one contact</li>
                        <li>Use <strong>AutoFill</strong> to add consecutive ID numbers in column D</li>
                        <li>Save the file as <strong>MyContactList.xlsx</strong></li>
                    </ol>
                </div>

                <!-- <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Excel Data Entry Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBEYXRhIEVudHJ5IEV4ZXJjaXNlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Create Your First Excel Contact List</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Contact List Template
                </a> -->
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Workbook</strong>
                    <p>An Excel file containing one or more worksheets (sheets).</p>
                </div>

                <div class="term">
                    <strong>Worksheet</strong>
                    <p>A single spreadsheet within a workbook, consisting of rows and columns.</p>
                </div>

                <div class="term">
                    <strong>Cell</strong>
                    <p>The intersection of a row and column where data is entered (e.g., A1, B5).</p>
                </div>

                <div class="term">
                    <strong>Cell Reference</strong>
                    <p>The address of a cell, combining column letter and row number.</p>
                </div>

                <div class="term">
                    <strong>Range</strong>
                    <p>A group of adjacent cells, specified by top-left and bottom-right cells (e.g., A1:B10).</p>
                </div>

                <div class="term">
                    <strong>Formula Bar</strong>
                    <p>The area above the worksheet that displays the content of the active cell.</p>
                </div>

                <div class="term">
                    <strong>Name Box</strong>
                    <p>Located left of the formula bar, shows address of active cell and can name ranges.</p>
                </div>

                <div class="term">
                    <strong>Fill Handle</strong>
                    <p>The small square at bottom-right of selected cell used for AutoFill operations.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 9. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete this week's assignment: <a href="https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/excel/week1_assignment.html" target="_blank">Week 1 Practical Assignment</a></h4>
                    
                </div>

                
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Practice daily:</strong> Even 15–20 minutes helps build proficiency and confidence.</li>
                    <li><strong>Use Excel's Help (F1):</strong> When stuck, Excel's built-in help is comprehensive.</li>
                    <li><strong>Explore right-click menus:</strong> Context menus often have the exact option you need.</li>
                    <li><strong>Don't rush:</strong> Accuracy is more important than speed at this learning stage.</li>
                    <li><strong>Save frequently:</strong> Use Ctrl + S often to avoid losing work.</li>
                    <li><strong>Name important ranges:</strong> Makes navigation and formula creation much easier.</li>
                    <li><strong>Use templates:</strong> Start with built-in templates for budgets, calendars, etc.</li>
                    <li><strong>Organize data:</strong> Keep related data together and use clear headers.</li>
                    <li><strong>Learn shortcuts:</strong> They dramatically increase efficiency over time.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/excel" target="_blank">Microsoft Excel Official Support</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-workbook-in-excel-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Create a Workbook in Excel Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/import-or-export-text-txt-or-csv-files-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Import/Export Text and CSV Files</a></li>
                    <li><a href="https://exceljet.net/keyboard-shortcuts" target="_blank">Excel Keyboard Shortcuts Reference</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Interactive Excel Simulator</strong> for hands-on practice without installing Excel</li>
                    <li><strong>Week 1 Quiz</strong> to test your understanding (available in portal)</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 2: Formatting Worksheets & Basic Formulas</strong></p>
                <p>In Week 2, we'll build on your foundation and learn to:</p>
                <ul>
                    <li>Adjust row height and column width precisely</li>
                    <li>Set up page layout, headers, and footers for printing</li>
                    <li>Freeze panes to keep headers visible while scrolling</li>
                    <li>Set print areas and adjust print settings</li>
                    <li>Display formulas and trace precedents/dependents</li>
                    <li>Apply basic cell formatting (fonts, colors, borders)</li>
                    <li>Use number formats (currency, percentages, dates)</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring a dataset you'd like to format professionally (contact list, expense report, or inventory).</p>
            </div>

            <!-- Help Section 
            <div class="help-section">
                <div class="help-title">
                    <i class="fas fa-question-circle"></i> Need Help?
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></li>
                    <li><strong>Class Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a></li>
                    <li><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week1.php">Week 1 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft Excel Help:</strong> <a href="https://support.microsoft.com/excel" target="_blank">Official Support</a></li>
                    <li><strong>Excel Community:</strong> <a href="https://techcommunity.microsoft.com/t5/excel/ct-p/Excel" target="_blank">Microsoft Tech Community</a></li>
                </ul>
            </div> -->

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <a href="#" class="download-btn" onclick="printHandout()" style="margin-right: 15px;">
                    <i class="fas fa-print"></i> Print Handout
                </a>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week1_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 1 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 1 Handout</p>
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
            alert('Contact list template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week1_contact_list.xlsx';
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
                console.log('Excel Week 1 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 1 Handout',
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
                    "1. Ctrl + N creates a new workbook.",
                    "2. The Formula Bar is located above the worksheet, below the Ribbon.",
                    "3. Press F2 to edit a cell without using the mouse.",
                    "4. CSV stands for Comma Separated Values.",
                    "5. Click the column letter or press Ctrl + Space to select an entire column."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive interface item demonstration
        document.addEventListener('DOMContentLoaded', function() {
            const interfaceItems = document.querySelectorAll('.interface-item');
            interfaceItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    alert(`${title}\n\n${description}\n\nTry it in Excel now!`);
                });
            });

            // Type cards interaction
            const typeCards = document.querySelectorAll('.type-card');
            typeCards.forEach(card => {
                card.addEventListener('click', function() {
                    const type = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:last-child').textContent;
                    const examples = {
                        'Text (Labels)': 'Company names, product descriptions, categories',
                        'Numbers (Values)': 'Sales figures, quantities, prices, scores',
                        'Dates/Times': 'Order dates, birth dates, timestamps, durations',
                        'Formulas': '=A1+B1, =SUM(A1:A10), =AVERAGE(B2:B20)'
                    };
                    alert(`${type}\n\n${description}\n\nExamples: ${examples[type]}`);
                });
            });

            // Format cards interaction
            const formatCards = document.querySelectorAll('.format-card');
            formatCards.forEach(card => {
                card.addEventListener('click', function() {
                    const format = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:last-child').textContent;
                    const useCases = {
                        '.xlsx': 'Standard workbooks with formatting, formulas, and multiple sheets',
                        '.xls': 'Compatibility with older Excel versions (pre-2007)',
                        '.csv': 'Simple data exchange, database imports, plain text data'
                    };
                    alert(`${format} Format\n\n${description}\n\nBest for: ${useCases[format]}`);
                });
            });

            // Excel grid interaction
            const excelCells = document.querySelectorAll('.excel-cell:not(.header)');
            excelCells.forEach(cell => {
                cell.addEventListener('click', function() {
                    const cellRef = this.parentElement.children[0].textContent + 
                                  this.parentElement.children[Array.from(this.parentElement.children).indexOf(this)].textContent;
                    alert(`Cell Reference: ${cellRef}\n\nClick OK to select this cell.`);
                    
                    // Highlight clicked cell
                    excelCells.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'n': 'New Workbook (Ctrl + N)',
                'o': 'Open Workbook (Ctrl + O)',
                's': 'Save (Ctrl + S)',
                'p': 'Print (Ctrl + P)',
                'z': 'Undo (Ctrl + Z)',
                'y': 'Redo (Ctrl + Y)',
                'g': 'Go To (Ctrl + G)',
                'a': 'Select All (Ctrl + A)',
                'k': 'Insert Hyperlink (Ctrl + K)'
            };
            
            if (e.ctrlKey && shortcuts[e.key]) {
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
                shortcutAlert.textContent = `Excel Shortcut: ${shortcuts[e.key]}`;
                document.body.appendChild(shortcutAlert);
                
                setTimeout(() => {
                    shortcutAlert.remove();
                }, 2000);
            }
            
            // F2 key simulation
            if (e.key === 'F2') {
                e.preventDefault();
                alert('F2: Edit active cell\n\nPress F2 to edit the selected cell without using the mouse.');
            }
            
            // F5 key simulation
            if (e.key === 'F5') {
                e.preventDefault();
                const cellRef = prompt('Go To: Enter cell reference (e.g., A1, B10):', 'A1');
                if (cellRef) {
                    alert(`Navigating to cell: ${cellRef.toUpperCase()}\n\nIn Excel, this would move the selection to ${cellRef.toUpperCase()}`);
                }
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
            const interactiveElements = document.querySelectorAll('a, button, .interface-item, .type-card, .format-card, .excel-cell');
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

        // AutoFill demonstration
        function demonstrateAutoFill() {
            const steps = [
                "Step 1: Type 'Monday' in a cell",
                "Step 2: Click the small square (fill handle) at bottom-right",
                "Step 3: Drag down to fill Tuesday, Wednesday, Thursday...",
                "Step 4: Release to complete the series",
                "\nTry with numbers: Type '1' and '2' in two cells, select both, then drag."
            ];
            alert("Excel AutoFill Demonstration:\n\n" + steps.join("\n"));
        }

        // Import wizard simulation
        function simulateImportWizard() {
            const wizardSteps = [
                "Text Import Wizard - Step 1 of 3",
                "File Type: Delimited or Fixed Width",
                "Choose: Delimited (for CSV files)",
                "\nStep 2: Choose Delimiters",
                "☑ Comma   ☐ Tab   ☐ Semicolon   ☐ Space",
                "\nStep 3: Column Data Format",
                "General, Text, Date, or Do not import",
                "\nFinish: Data imported to worksheet!"
            ];
            alert("Text Import Wizard Simulation:\n\n" + wizardSteps.join("\n"));
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
    $viewer = new ExcelWeek1HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>