<?php
// modules/shared/course_materials/MSExcel/excel_week2_view.php

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
 * Excel Week 2 Handout Viewer Class with PDF Download
 */
class ExcelWeek2HandoutViewer
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
            $mpdf->SetTitle('Week 2: Worksheet Formatting, Printing & View Management');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Formatting, Printing, Page Setup, Headers, Footers, Freeze Panes');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week2_Formatting_Printing_' . date('Y-m-d') . '.pdf';
            
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
                Week 2: Worksheet Formatting, Printing & View Management
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 2!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll learn how to professionally format worksheets, customize print settings, and manage workbook views. These skills are essential for creating presentable reports, invoices, and data sheets that are both functional and easy to read—whether on-screen or on paper.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Modify page setup, margins, orientation, and scaling</li>
                    <li>Adjust row height and column width precisely</li>
                    <li>Create custom headers and footers with dynamic elements</li>
                    <li>Manage workbook views (Normal, Page Layout, Page Break Preview)</li>
                    <li>Freeze panes and split windows for better navigation</li>
                    <li>Set print areas and configure print settings</li>
                    <li>Display formulas and modify workbook properties</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Modifying Page Setup & Layout</h3>
                <p><strong>Accessing Page Setup:</strong></p>
                <ul>
                    <li>Go to the <strong>Page Layout</strong> tab</li>
                    <li>Click the <strong>Page Setup Dialog Box Launcher</strong> (small arrow in bottom-right corner of Page Setup group)</li>
                    <li>Or use: <strong>File → Print → Page Setup</strong> (at the bottom)</li>
                </ul>
                
                <p><strong>Key Page Setup Options:</strong></p>
                <ul>
                    <li><strong>Page Tab:</strong>
                        <ul>
                            <li>Orientation: Portrait (vertical) or Landscape (horizontal)</li>
                            <li>Scaling: Adjust to fit content (e.g., "Fit to 1 page wide by 1 tall")</li>
                            <li>Paper Size: Letter, A4, Legal, etc.</li>
                        </ul>
                    </li>
                    <li><strong>Margins Tab:</strong>
                        <ul>
                            <li>Set top, bottom, left, and right margins</li>
                            <li>Center on page: Horizontally / Vertically</li>
                        </ul>
                    </li>
                    <li><strong>Header/Footer Tab:</strong>
                        <ul>
                            <li>Add predefined headers/footers (page numbers, file name, date)</li>
                            <li>Customize with Custom Header or Custom Footer buttons</li>
                        </ul>
                    </li>
                    <li><strong>Sheet Tab:</strong>
                        <ul>
                            <li>Print Area: Select range to print only that area</li>
                            <li>Print Titles: Repeat specific rows/columns on every page</li>
                            <li>Gridlines & Headings: Choose to print or hide</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Adjusting Row Height & Column Width</h3>
                <p><strong>Manual Adjustment:</strong></p>
                <ul>
                    <li><strong>Row Height:</strong> Drag the line between row numbers</li>
                    <li><strong>Column Width:</strong> Drag the line between column letters</li>
                </ul>
                
                <p><strong>AutoFit:</strong></p>
                <ul>
                    <li>Double-click the line between row numbers or column letters to AutoFit content</li>
                    <li>Or select rows/columns → <strong>Home → Format → AutoFit Row Height / AutoFit Column Width</strong></li>
                </ul>
                
                <p><strong>Precise Sizing:</strong></p>
                <ul>
                    <li>Select rows/columns → <strong>Home → Format → Row Height / Column Width</strong> → Enter exact value</li>
                    <li>Recommended column widths: 8-15 characters for text, 10-12 for numbers</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Customizing Headers & Footers</h3>
                <p><strong>Inserting Built-in Elements:</strong></p>
                <ul>
                    <li>Go to <strong>Insert → Header & Footer</strong></li>
                    <li>Use the <strong>Design tab (Header & Footer Tools)</strong> to add:</li>
                    <ul>
                        <li>Page numbers</li>
                        <li>Current date/time</li>
                        <li>File path/name</li>
                        <li>Sheet name</li>
                    </ul>
                </ul>
                
                <p><strong>Custom Text & Formatting:</strong></p>
                <ul>
                    <li>Click in header/footer section and type directly</li>
                    <li>Use formatting buttons (Font, Page Number, etc.)</li>
                    <li>Use <strong>&[Page]</strong> for page numbers, <strong>&[Date]</strong> for current date</li>
                    <li>Other codes: <strong>&[File]</strong> (file name), <strong>&[Tab]</strong> (sheet name)</li>
                </ul>
                
                <p><strong>Different First Page or Odd/Even Pages:</strong></p>
                <ul>
                    <li>Check options in the Design tab under <strong>Options</strong></li>
                    <li>Useful for reports with cover pages or dual-sided printing</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">4. Managing Workbook Views</h3>
                <p><strong>Available Views (View Tab):</strong></p>
                <ul>
                    <li><strong>Normal:</strong> Default view for data entry and editing</li>
                    <li><strong>Page Layout:</strong> Shows pages as they will print, with margins and headers/footers</li>
                    <li><strong>Page Break Preview:</strong> Adjust where pages break when printing (blue lines)</li>
                    <li><strong>Custom Views:</strong> Save specific view settings (like zoom, hidden rows/columns)</li>
                </ul>
                
                <p><strong>Freezing Panes:</strong></p>
                <ul>
                    <li>Keep rows/columns visible while scrolling through large datasets</li>
                    <li>To freeze:
                        <ol>
                            <li>Select the cell below and to the right of what you want to freeze</li>
                            <li><strong>View → Freeze Panes → Freeze Panes</strong></li>
                        </ol>
                    </li>
                    <li>To unfreeze: <strong>View → Freeze Panes → Unfreeze Panes</strong></li>
                </ul>
                
                <p><strong>Splitting Windows:</strong></p>
                <ul>
                    <li>Divide the worksheet into separate scrollable panes</li>
                    <li><strong>View → Split</strong></li>
                    <li>Drag the split bars to adjust pane sizes</li>
                    <li>Useful for comparing distant parts of a worksheet</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">5. Setting a Print Area & Configuring Print Settings</h3>
                <p><strong>Defining a Print Area:</strong></p>
                <ul>
                    <li>Select the range to print</li>
                    <li><strong>Page Layout → Print Area → Set Print Area</strong></li>
                    <li>To clear: <strong>Page Layout → Print Area → Clear Print Area</strong></li>
                    <li>Multiple print areas: Hold Ctrl to select non-adjacent ranges</li>
                </ul>
                
                <p><strong>Print Preview & Settings:</strong></p>
                <ul>
                    <li><strong>File → Print</strong> (or <strong>Ctrl + P</strong>)</li>
                    <li>Adjust settings:
                        <ul>
                            <li>Number of copies</li>
                            <li>Printer selection</li>
                            <li>Pages to print</li>
                            <li>Collation</li>
                            <li>Orientation, scaling, margins</li>
                        </ul>
                    </li>
                </ul>
                
                <p><strong>Printing Gridlines & Headings:</strong></p>
                <ul>
                    <li>In Page Layout tab, check <strong>Print</strong> under Gridlines and Headings</li>
                    <li>Or in <strong>Page Setup → Sheet</strong> tab</li>
                    <li>Gridlines help read data but may look cluttered</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">6. Displaying Formulas</h3>
                <ul>
                    <li>To show formulas: <strong>Ctrl + `</strong> (grave accent key, next to number 1)</li>
                    <li>Or: <strong>Formulas → Show Formulas</strong></li>
                    <li>To hide formulas: Repeat the same shortcut or button</li>
                    <li>Useful for auditing and debugging complex spreadsheets</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">7. Modifying Basic Workbook Properties</h3>
                <ul>
                    <li><strong>File → Info → Properties → Show All Properties</strong></li>
                    <li>Edit metadata:
                        <ul>
                            <li>Title</li>
                            <li>Author</li>
                            <li>Keywords</li>
                            <li>Category</li>
                            <li>Comments</li>
                        </ul>
                    </li>
                    <li>Helps in organizing and searching for files</li>
                    <li>Properties appear in Windows File Explorer and search results</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Format a Monthly Sales Report</h3>
                <p><strong>Objective:</strong> Apply Week 2 skills to create a professional, printable report.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Open a new workbook</li>
                    <li>Enter the following data:</li>
                </ol>
                
                <table style="width: 100%; border-collapse: collapse; margin: 10px 0; border: 1px solid #ddd;">
                    <thead>
                        <tr style="background-color: #f0f0f0;">
                            <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Month</th>
                            <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Sales</th>
                            <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Expenses</th>
                            <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 8px;">January</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">15000</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">9000</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">6000</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 8px;">February</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">18000</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">9500</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">8500</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 8px;">March</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">22000</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">11000</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">11000</td>
                        </tr>
                    </tbody>
                </table>
                
                <ol start="3">
                    <li><strong>Formatting:</strong>
                        <ul>
                            <li>Bold the header row</li>
                            <li>AutoFit all columns</li>
                            <li>Center align the headers</li>
                            <li>Apply Currency format to Sales, Expenses, Profit columns</li>
                        </ul>
                    </li>
                    <li><strong>Page Setup:</strong>
                        <ul>
                            <li>Set orientation to Landscape</li>
                            <li>Set margins to Narrow</li>
                            <li>Add a Custom Header: "Sales Report 2024" (centered)</li>
                            <li>Add a Footer: Page number (right-aligned)</li>
                        </ul>
                    </li>
                    <li><strong>View & Print Setup:</strong>
                        <ul>
                            <li>Freeze the top row</li>
                            <li>Set print area to A1:D5</li>
                            <li>Preview in Page Layout View</li>
                            <li>Show formulas (Ctrl + `) to see calculations</li>
                        </ul>
                    </li>
                    <li>Save as <strong>Sales_Report_Formatted.xlsx</strong></li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Essential Shortcuts for Week 2</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + P</td>
                            <td style="padding: 6px 8px;">Print dialog</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + `</td>
                            <td style="padding: 6px 8px;">Show/hide formulas</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → P → S → P</td>
                            <td style="padding: 6px 8px;">Page Setup dialog (keyboard sequence)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → W → F → F</td>
                            <td style="padding: 6px 8px;">Freeze Panes (keyboard sequence)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → W → S</td>
                            <td style="padding: 6px 8px;">Split window (keyboard sequence)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → W → L</td>
                            <td style="padding: 6px 8px;">Switch to Page Layout view</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → W → N</td>
                            <td style="padding: 6px 8px;">Switch to Normal view</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → W → I</td>
                            <td style="padding: 6px 8px;">Switch to Page Break Preview</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → P → R → A</td>
                            <td style="padding: 6px 8px;">Set Print Area (keyboard sequence)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt → H → O → H</td>
                            <td style="padding: 6px 8px;">Row Height dialog (keyboard sequence)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Print Area:</strong> The specific range of cells designated for printing.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Print Titles:</strong> Rows or columns repeated on every printed page.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Freeze Panes:</strong> Keeps selected rows/columns visible while scrolling.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Page Break:</strong> The point where one printed page ends and another begins.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Header/Footer:</strong> Text that appears at the top/bottom of every printed page.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Orientation:</strong> Page layout direction (Portrait = vertical, Landscape = horizontal).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Scaling:</strong> Adjusting print size to fit content on specified number of pages.</p>
                </div>
                <div>
                    <p><strong>Workbook Properties:</strong> Metadata about the file (author, title, keywords).</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Print-Ready Invoice:</strong>
                        <ul>
                            <li>Create a simple invoice with:
                                <ul>
                                    <li>Company name/logo (text)</li>
                                    <li>Customer info section</li>
                                    <li>Item, Quantity, Price, Total columns</li>
                                    <li>Formula for Total (Qty × Price)</li>
                                    <li>Grand total at bottom with SUM formula</li>
                                </ul>
                            </li>
                            <li>Format professionally with borders, alignment, number formatting</li>
                            <li>Set print area to include only invoice content</li>
                            <li>Add header with company name and footer with page number/date</li>
                            <li>Adjust margins for balanced layout</li>
                            <li>Save as <strong>Invoice_Template.xlsx</strong></li>
                        </ul>
                    </li>
                    <li><strong>View Management Practice:</strong>
                        <ul>
                            <li>Open any dataset with many rows/columns</li>
                            <li>Freeze the first column</li>
                            <li>Split the window horizontally</li>
                            <li>Switch between Normal, Page Layout, and Page Break Preview views</li>
                            <li>Take a screenshot showing frozen panes and split window</li>
                            <li>Save different custom views for the same data</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>How do you AutoFit a column?</li>
                            <li>What is the shortcut to show formulas?</li>
                            <li>How do you set a different header for the first page?</li>
                            <li>What does the Page Break Preview show?</li>
                            <li>How do you repeat row 1 on every printed page?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Modify page setup and print settings</li>
                    <li>Adjust row height and column width</li>
                    <li>Create and modify headers and footers</li>
                    <li>Freeze and unfreeze panes</li>
                    <li>Split worksheet windows</li>
                    <li>Set and clear print areas</li>
                    <li>Manage workbook views</li>
                    <li>Display formulas</li>
                    <li>Modify document properties</li>
                    <li>Print worksheets and workbooks</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Always preview before printing:</strong> Use Print Preview to save paper and ensure layout is correct.</li>
                    <li><strong>Use Print Titles for long reports:</strong> Keep headers visible on each printed page.</li>
                    <li><strong>Experiment with scaling:</strong> Fit content neatly on one page when possible.</li>
                    <li><strong>Save custom views:</strong> If you frequently switch between different layouts.</li>
                    <li><strong>Freeze panes wisely:</strong> Freeze only what you need to see while scrolling.</li>
                    <li><strong>Use narrow margins:</strong> When printing data-heavy sheets to maximize space.</li>
                    <li><strong>Add page numbers:</strong> Essential for multi-page documents.</li>
                    <li><strong>Test print on one page first:</strong> Before printing large batches.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 3, we'll cover:</p>
                <ul>
                    <li>Advanced data entry techniques (special paste, Flash Fill)</li>
                    <li>Cell formatting (alignment, text wrapping, merging)</li>
                    <li>Using Format Painter for consistent styling</li>
                    <li>Applying number formats (currency, percentages, dates)</li>
                    <li>Using cell styles and themes</li>
                    <li>Working with named ranges</li>
                    <li>Basic conditional formatting</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Review your formatted sales report and invoice for Week 3 enhancements.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Page Setup and Printing Guide</li>
                    <li>Header and Footer Customization Tutorial</li>
                    <li>Workbook Views and Navigation Techniques</li>
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
                Week 2 Handout: Worksheet Formatting, Printing & View Management
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
            Week 2: Worksheet Formatting & Printing | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 2: Worksheet Formatting, Printing & View Management - Impact Digital Academy</title>
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

        /* Page Layout Demo */
        .page-layout-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .layout-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .layout-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .layout-icon {
            font-size: 3rem;
            color: #107c10;
            margin-bottom: 15px;
        }

        /* View Cards */
        .view-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .view-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .view-card.normal {
            border-color: #107c10;
            background: #e8f4e8;
        }

        .view-card.page-layout {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .view-card.page-break {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .view-card.custom {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .view-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .view-card.normal .view-icon {
            color: #107c10;
        }

        .view-card.page-layout .view-icon {
            color: #2196f3;
        }

        .view-card.page-break .view-icon {
            color: #9c27b0;
        }

        .view-card.custom .view-icon {
            color: #ff9800;
        }

        /* Orientation Cards */
        .orientation-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .orientation-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .orientation-card.portrait {
            border-color: #107c10;
            background: #e8f4e8;
        }

        .orientation-card.landscape {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .orientation-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .orientation-card.portrait .orientation-icon {
            color: #107c10;
        }

        .orientation-card.landscape .orientation-icon {
            color: #2196f3;
        }

        /* Header Footer Demo */
        .header-footer-demo {
            border: 2px solid #107c10;
            border-radius: 8px;
            margin: 25px 0;
            overflow: hidden;
        }

        .header-section {
            background: #e8f4e8;
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #107c10;
        }

        .content-section {
            padding: 30px;
            min-height: 300px;
            background: white;
        }

        .footer-section {
            background: #f0f0f0;
            padding: 15px;
            text-align: center;
            border-top: 1px solid #ddd;
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

        /* Freeze Panes Demo */
        .freeze-demo {
            display: grid;
            grid-template-columns: 80px 1fr;
            grid-template-rows: 40px 1fr;
            gap: 1px;
            border: 2px solid #107c10;
            margin: 20px 0;
            max-width: 600px;
        }

        .frozen-corner {
            background: #107c10;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .frozen-header {
            background: #0e5c0e;
            color: white;
            display: flex;
            align-items: center;
            padding-left: 10px;
            font-weight: bold;
        }

        .frozen-sidebar {
            background: #0e5c0e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .scrollable-area {
            background: #f9f9f9;
            height: 200px;
            overflow: auto;
            padding: 10px;
        }

        /* Print Area Demo */
        .print-area-demo {
            border: 2px dashed #107c10;
            padding: 20px;
            margin: 20px 0;
            background: #f9f9f9;
        }

        .print-area-indicator {
            position: absolute;
            top: -10px;
            left: 10px;
            background: #107c10;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
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

            .page-layout-demo,
            .view-cards,
            .orientation-cards {
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
                <strong>Access Granted:</strong> Excel Week 2 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week1_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 1
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week3_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 3
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 2 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Worksheet Formatting, Printing & View Management</div>
            <div class="week-tag">Week 2 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 2!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll learn how to professionally format worksheets, customize print settings, and manage workbook views. These skills are essential for creating presentable reports, invoices, and data sheets that are both functional and easy to read—whether on-screen or on paper.
                </p>

                <div class="image-container">
                    <img src="images/excel_formatting.png"
                        alt="Excel Worksheet Formatting & Printing"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RXhjZWwgRm9ybWF0dGluZyAmIFByaW50aW5nPC90ZXh0Pjwvc3ZnPg='">
                    <div class="image-caption">Professional Worksheet Formatting and Printing in Excel</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Modify page setup, margins, orientation, and scaling</li>
                    <li>Adjust row height and column width precisely</li>
                    <li>Create custom headers and footers with dynamic elements</li>
                    <li>Manage workbook views (Normal, Page Layout, Page Break Preview)</li>
                    <li>Freeze panes and split windows for better navigation</li>
                    <li>Set print areas and configure print settings</li>
                    <li>Display formulas and modify workbook properties</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Modify page setup and print settings</li>
                        <li>Adjust row height and column width</li>
                        <li>Create and modify headers and footers</li>
                        <li>Freeze and unfreeze panes</li>
                        <li>Split worksheet windows</li>
                    </ul>
                    <ul>
                        <li>Set and clear print areas</li>
                        <li>Manage workbook views</li>
                        <li>Display formulas</li>
                        <li>Modify document properties</li>
                        <li>Print worksheets and workbooks</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Page Setup -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-print"></i> 1. Modifying Page Setup & Layout
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cog"></i> Accessing Page Setup</h3>
                    <ul>
                        <li>Go to the <strong>Page Layout</strong> tab</li>
                        <li>Click the <strong>Page Setup Dialog Box Launcher</strong> (small arrow in bottom-right corner)</li>
                        <li>Or use: <strong>File → Print → Page Setup</strong> (at the bottom)</li>
                        <li>Keyboard shortcut sequence: <strong>Alt → P → S → P</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Key Page Setup Options</h3>
                    
                    <div class="page-layout-demo">
                        <div class="layout-item">
                            <div class="layout-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <h4>Page Tab</h4>
                            <ul style="text-align: left; padding-left: 15px;">
                                <li><strong>Orientation:</strong> Portrait/Landscape</li>
                                <li><strong>Scaling:</strong> Fit to page</li>
                                <li><strong>Paper Size:</strong> Letter, A4, Legal</li>
                            </ul>
                        </div>
                        <div class="layout-item">
                            <div class="layout-icon">
                                <i class="fas fa-border-all"></i>
                            </div>
                            <h4>Margins Tab</h4>
                            <ul style="text-align: left; padding-left: 15px;">
                                <li>Top, Bottom, Left, Right</li>
                                <li>Center on page</li>
                                <li>Header/Footer margins</li>
                            </ul>
                        </div>
                        <div class="layout-item">
                            <div class="layout-icon">
                                <i class="fas fa-heading"></i>
                            </div>
                            <h4>Header/Footer Tab</h4>
                            <ul style="text-align: left; padding-left: 15px;">
                                <li>Built-in headers/footers</li>
                                <li>Custom Header/Footer</li>
                                <li>Page numbers, dates</li>
                            </ul>
                        </div>
                        <div class="layout-item">
                            <div class="layout-icon">
                                <i class="fas fa-table"></i>
                            </div>
                            <h4>Sheet Tab</h4>
                            <ul style="text-align: left; padding-left: 15px;">
                                <li>Print Area</li>
                                <li>Print Titles</li>
                                <li>Gridlines & Headings</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="orientation-cards">
                    <div class="orientation-card portrait">
                        <div class="orientation-icon">
                            <i class="fas fa-portrait"></i>
                        </div>
                        <h4>Portrait</h4>
                        <p>Vertical orientation</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Lists, reports, documents</p>
                    </div>
                    <div class="orientation-card landscape">
                        <div class="orientation-icon">
                            <i class="fas fa-landscape"></i>
                        </div>
                        <h4>Landscape</h4>
                        <p>Horizontal orientation</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Wide tables, charts, spreadsheets</p>
                    </div>
                </div>
            </div>

            <!-- Section 2: Row & Column Adjustment -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-arrows-alt-v"></i> 2. Adjusting Row Height & Column Width
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-mouse-pointer"></i> Manual Adjustment</h3>
                    <ul>
                        <li><strong>Row Height:</strong> Drag the line between row numbers</li>
                        <li><strong>Column Width:</strong> Drag the line between column letters</li>
                        <li>Cursor changes to <strong>double-headed arrow</strong> when positioned correctly</li>
                        <li>Current size displays as you drag</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-expand-alt"></i> AutoFit</h3>
                    <ul>
                        <li><strong>Double-click</strong> the line between row numbers or column letters</li>
                        <li>Or select rows/columns → <strong>Home → Format → AutoFit Row Height / AutoFit Column Width</strong></li>
                        <li>AutoFit adjusts to fit the <strong>longest entry</strong> in the column/row</li>
                        <li>Keyboard: <strong>Alt → H → O → I</strong> (AutoFit Column), <strong>Alt → H → O → A</strong> (AutoFit Row)</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-ruler"></i> Precise Sizing</h3>
                    <ul>
                        <li>Select rows/columns → <strong>Home → Format → Row Height / Column Width</strong></li>
                        <li>Enter exact value (row height in points, column width in characters)</li>
                        <li><strong>Standard column widths:</strong> 8-15 characters for text, 10-12 for numbers</li>
                        <li><strong>Standard row heights:</strong> 15-20 points for most data</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i> Size Tips
                    </div>
                    <ul>
                        <li>Use <strong>Shift + Space</strong> to select entire row</li>
                        <li>Use <strong>Ctrl + Space</strong> to select entire column</li>
                        <li>Select multiple rows/columns first to size them all at once</li>
                        <li>Right-click row/column header for quick access to sizing options</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Headers & Footers -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-heading"></i> 3. Customizing Headers & Footers
                </div>

                <!-- Header/Footer Demo -->
                <div class="header-footer-demo">
                    <div class="header-section">
                        <h4>Header Section</h4>
                        <p><strong>&[Tab]</strong> - Sheet Name | <strong>&[File]</strong> - File Name | <strong>&[Date]</strong> - Current Date</p>
                    </div>
                    <div class="content-section">
                        <h4>Worksheet Content Area</h4>
                        <p>This is where your data appears. Headers and footers only show in Page Layout view and when printing.</p>
                        <p>To edit headers/footers: <strong>Insert → Header & Footer</strong> or switch to <strong>Page Layout</strong> view.</p>
                    </div>
                    <div class="footer-section">
                        <h4>Footer Section</h4>
                        <p>Page <strong>&[Page]</strong> of <strong>&[Pages]</strong> | Printed on <strong>&[Date]</strong> | Confidential</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Inserting Built-in Elements</h3>
                    <ul>
                        <li>Go to <strong>Insert → Header & Footer</strong></li>
                        <li>Design tab appears (Header & Footer Tools)</li>
                        <li>Click in header/footer area, then use Design tab buttons:</li>
                        <ul>
                            <li><strong>Page Number</strong> - &[Page]</li>
                            <li><strong>Number of Pages</strong> - &[Pages]</li>
                            <li><strong>Current Date</strong> - &[Date]</li>
                            <li><strong>Current Time</strong> - &[Time]</li>
                            <li><strong>File Path</strong> - &[Path]&[File]</li>
                            <li><strong>File Name</strong> - &[File]</li>
                            <li><strong>Sheet Name</strong> - &[Tab]</li>
                        </ul>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-edit"></i> Custom Text & Formatting</h3>
                    <ul>
                        <li>Click in header/footer section and type directly</li>
                        <li>Use formatting buttons in Design tab: <strong>Font, Font Size, Bold, Italic</strong></li>
                        <li>Three sections: Left, Center, Right (click to activate each)</li>
                        <li>Combine text and codes: <strong>"Report printed on &[Date]"</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-alt"></i> Different First Page or Odd/Even Pages</h3>
                    <ul>
                        <li>In Design tab, check <strong>Different First Page</strong> option</li>
                        <li>First page can have unique header/footer (like cover page)</li>
                        <li>Check <strong>Different Odd & Even Pages</strong> for book-style formatting</li>
                        <li>Useful for formal reports, manuals, and booklets</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Workbook Views -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-desktop"></i> 4. Managing Workbook Views
                </div>

                <div class="view-cards">
                    <div class="view-card normal">
                        <div class="view-icon">
                            <i class="fas fa-th"></i>
                        </div>
                        <h4>Normal View</h4>
                        <p>Default editing view</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Alt + W + N</p>
                    </div>
                    <div class="view-card page-layout">
                        <div class="view-icon">
                            <i class="fas fa-print"></i>
                        </div>
                        <h4>Page Layout View</h4>
                        <p>Shows pages as they print</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Alt + W + L</p>
                    </div>
                    <div class="view-card page-break">
                        <div class="view-icon">
                            <i class="fas fa-cut"></i>
                        </div>
                        <h4>Page Break Preview</h4>
                        <p>Adjust page breaks</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Alt + W + I</p>
                    </div>
                    <div class="view-card custom">
                        <div class="view-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <h4>Custom Views</h4>
                        <p>Save view settings</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">View → Custom Views</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-snowflake"></i> Freezing Panes</h3>
                    
                    <div class="freeze-demo">
                        <div class="frozen-corner">A1</div>
                        <div class="frozen-header">Header Row (Frozen)</div>
                        <div class="frozen-sidebar">ID Column (Frozen)</div>
                        <div class="scrollable-area">
                            <p>This area scrolls while headers remain visible.</p>
                            <p>To freeze panes:</p>
                            <ol>
                                <li>Select cell below and right of what to freeze</li>
                                <li>View → Freeze Panes → Freeze Panes</li>
                            </ol>
                            <p>Scroll down/right to see frozen headers stay in place.</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-window-restore"></i> Splitting Windows</h3>
                    <ul>
                        <li><strong>View → Split</strong> (or drag split boxes from scroll bars)</li>
                        <li>Creates <strong>four separate panes</strong> that scroll independently</li>
                        <li>Drag split bars to adjust pane sizes</li>
                        <li>Double-click split bar to remove that split</li>
                        <li>Useful for comparing different worksheet sections</li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Print Settings -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-print"></i> 5. Setting Print Area & Configuring Print Settings
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-border-none"></i> Defining a Print Area</h3>
                    <div class="print-area-demo" style="position: relative;">
                        <div class="print-area-indicator">Print Area</div>
                        <table class="demo-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Sales</th>
                                    <th>Expenses</th>
                                    <th>Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>January</td>
                                    <td>$15,000</td>
                                    <td>$9,000</td>
                                    <td>$6,000</td>
                                </tr>
                                <tr>
                                    <td>February</td>
                                    <td>$18,000</td>
                                    <td>$9,500</td>
                                    <td>$8,500</td>
                                </tr>
                                <tr>
                                    <td>March</td>
                                    <td>$22,000</td>
                                    <td>$11,000</td>
                                    <td>$11,000</td>
                                </tr>
                            </tbody>
                        </table>
                        <p style="color: #666; font-size: 0.9rem; margin-top: 10px;">
                            Only cells within the print area (this box) will print.
                        </p>
                    </div>
                    
                    <ul>
                        <li>Select range → <strong>Page Layout → Print Area → Set Print Area</strong></li>
                        <li>To clear: <strong>Page Layout → Print Area → Clear Print Area</strong></li>
                        <li>Multiple print areas: Hold <strong>Ctrl</strong> to select non-adjacent ranges</li>
                        <li>Each print area prints on separate page</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-search"></i> Print Preview & Settings</h3>
                    <ul>
                        <li><strong>File → Print</strong> or <strong>Ctrl + P</strong></li>
                        <li>Preview shows exactly how page will print</li>
                        <li>Adjust settings in right pane:</li>
                        <ul>
                            <li>Copies</li>
                            <li>Printer selection</li>
                            <li>Pages (e.g., 1-3)</li>
                            <li>Collated/Uncollated</li>
                            <li>Orientation, scaling, margins</li>
                            <li>Page Setup (advanced options)</li>
                        </ul>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-th"></i> Printing Gridlines & Headings</h3>
                    <ul>
                        <li>In <strong>Page Layout</strong> tab, check <strong>Print</strong> under Gridlines</li>
                        <li>Check <strong>Print</strong> under Headings for row/column labels</li>
                        <li>Or in <strong>Page Setup → Sheet</strong> tab</li>
                        <li>Gridlines help read data but may look cluttered</li>
                        <li>Consider using cell borders instead for cleaner look</li>
                    </ul>
                </div>
            </div>

            <!-- Section 6: Displaying Formulas -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-calculator"></i> 6. Displaying Formulas
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eye"></i> Show/Hide Formulas</h3>
                    <ul>
                        <li>Shortcut: <strong>Ctrl + `</strong> (grave accent key, next to number 1)</li>
                        <li>Or: <strong>Formulas → Show Formulas</strong></li>
                        <li>Toggles between formula view and result view</li>
                        <li>Column widths auto-adjust to show full formulas</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Formula View Tips
                        </div>
                        <ul>
                            <li>Use formula view to audit and debug spreadsheets</li>
                            <li>Print with formulas showing for documentation</li>
                            <li>Columns may need manual adjustment after returning to normal view</li>
                            <li>Trace precedents/dependents while formulas are visible</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Section 7: Workbook Properties -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> 7. Modifying Basic Workbook Properties
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-edit"></i> Editing Document Properties</h3>
                    <ul>
                        <li><strong>File → Info → Properties → Show All Properties</strong></li>
                        <li>Edit metadata fields:</li>
                        <ul>
                            <li><strong>Title:</strong> Descriptive name of workbook</li>
                            <li><strong>Author:</strong> Your name (auto-filled from Excel options)</li>
                            <li><strong>Keywords:</strong> Search terms (separate with commas)</li>
                            <li><strong>Category:</strong> Grouping category</li>
                            <li><strong>Comments:</strong> Notes about the file</li>
                        </ul>
                        <li>Properties appear in Windows File Explorer, search results</li>
                        <li>Helps organize and find files in large collections</li>
                    </ul>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 8. Hands-On Exercise: Format a Monthly Sales Report
                </div>
                <p><strong>Objective:</strong> Apply Week 2 skills to create a professional, printable report.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open Excel and create a <strong>new workbook</strong></li>
                        <li>Enter the sales data from the table below:</li>
                    </ol>
                    
                    <table class="demo-table" style="margin: 15px 0;">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Sales</th>
                                <th>Expenses</th>
                                <th>Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>January</td>
                                <td>15000</td>
                                <td>9000</td>
                                <td>6000</td>
                            </tr>
                            <tr>
                                <td>February</td>
                                <td>18000</td>
                                <td>9500</td>
                                <td>8500</td>
                            </tr>
                            <tr>
                                <td>March</td>
                                <td>22000</td>
                                <td>11000</td>
                                <td>11000</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <ol start="3" style="padding-left: 30px;">
                        <li><strong>Formatting:</strong>
                            <ul>
                                <li>Bold the header row (Ctrl + B)</li>
                                <li>AutoFit all columns (double-click column borders)</li>
                                <li>Center align the headers (Home → Center)</li>
                                <li>Apply <strong>Currency format</strong> to Sales, Expenses, Profit columns</li>
                            </ul>
                        </li>
                        <li><strong>Page Setup:</strong>
                            <ul>
                                <li>Set orientation to <strong>Landscape</strong></li>
                                <li>Set margins to <strong>Narrow</strong></li>
                                <li>Add a Custom Header: <strong>"Sales Report 2024"</strong> (centered)</li>
                                <li>Add a Footer: <strong>Page number</strong> (right-aligned)</li>
                            </ul>
                        </li>
                        <li><strong>View & Print Setup:</strong>
                            <ul>
                                <li>Freeze the top row (View → Freeze Panes → Freeze Top Row)</li>
                                <li>Set print area to <strong>A1:D5</strong></li>
                                <li>Preview in <strong>Page Layout View</strong></li>
                                <li>Show formulas (Ctrl + `) to see calculations</li>
                            </ul>
                        </li>
                        <li>Save as <strong>Sales_Report_Formatted.xlsx</strong></li>
                    </ol>
                </div>

                <!--
                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Sales Report Template
                </a> -->
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 9. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Print Area</strong>
                    <p>The specific range of cells designated for printing. Only this area will print.</p>
                </div>

                <div class="term">
                    <strong>Print Titles</strong>
                    <p>Rows or columns repeated on every printed page (e.g., header rows).</p>
                </div>

                <div class="term">
                    <strong>Freeze Panes</strong>
                    <p>Keeps selected rows/columns visible while scrolling through large datasets.</p>
                </div>

                <div class="term">
                    <strong>Page Break</strong>
                    <p>The point where one printed page ends and another begins. Adjust in Page Break Preview.</p>
                </div>

                <div class="term">
                    <strong>Header/Footer</strong>
                    <p>Text that appears at the top/bottom of every printed page (page numbers, dates, titles).</p>
                </div>

                <div class="term">
                    <strong>Orientation</strong>
                    <p>Page layout direction: Portrait (vertical) or Landscape (horizontal).</p>
                </div>

                <div class="term">
                    <strong>Scaling</strong>
                    <p>Adjusting print size to fit content on specified number of pages (Fit to Page).</p>
                </div>

                <div class="term">
                    <strong>Workbook Properties</strong>
                    <p>Metadata about the file (author, title, keywords, comments) for organization and search.</p>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 10. Essential Shortcuts for Week 2
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
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Print dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + `</span></td>
                            <td>Show/hide formulas</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → P → S → P</span></td>
                            <td>Page Setup dialog (keyboard sequence)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → W → F → F</span></td>
                            <td>Freeze Panes</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → W → S</span></td>
                            <td>Split window</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → W → L</span></td>
                            <td>Switch to Page Layout view</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → W → N</span></td>
                            <td>Switch to Normal view</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → W → I</span></td>
                            <td>Switch to Page Break Preview</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → P → R → A</span></td>
                            <td>Set Print Area</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → O → H</span></td>
                            <td>Row Height dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → O → W</span></td>
                            <td>Column Width dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → O → I</span></td>
                            <td>AutoFit Column Width</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → O → A</span></td>
                            <td>AutoFit Row Height</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Space</span></td>
                            <td>Select entire row</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Space</span></td>
                            <td>Select entire column</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 11. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;"> 
                        <a href="https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/excel/week2_assignment.html"target="_blank">Access your assignment here</a> :</h4>
                    <!--
                    <ol>
                        <li><strong>Print-Ready Invoice:</strong>
                            <ul>
                                <li>Create a simple invoice with:
                                    <ul>
                                        <li>Company name/logo (use text)</li>
                                        <li>Customer info section (name, address)</li>
                                        <li>Item, Quantity, Price, Total columns</li>
                                        <li>Formula for Total (Qty × Price)</li>
                                        <li>Grand total at bottom with SUM formula</li>
                                    </ul>
                                </li>
                                <li>Format professionally with borders, alignment, number formatting</li>
                                <li>Set print area to include only invoice content</li>
                                <li>Add header with company name and footer with page number/date</li>
                                <li>Adjust margins for balanced layout</li>
                                <li>Save as <strong>Invoice_Template.xlsx</strong></li>
                            </ul>
                        </li>
                        <li><strong>View Management Practice:</strong>
                            <ul>
                                <li>Open any dataset with many rows/columns</li>
                                <li>Freeze the first column</li>
                                <li>Split the window horizontally</li>
                                <li>Switch between Normal, Page Layout, and Page Break Preview views</li>
                                <li>Take a screenshot showing frozen panes and split window</li>
                                <li>Save different custom views for the same data</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>How do you AutoFit a column?</li>
                                <li>What is the shortcut to show formulas?</li>
                                <li>How do you set a different header for the first page?</li>
                                <li>What does the Page Break Preview show?</li>
                                <li>How do you repeat row 1 on every printed page?</li>
                            </ul>
                        </li>
                    </ol> -->
                </div>

                <!--
                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Submit your <strong>Sales_Report_Formatted.xlsx</strong>, <strong>Invoice_Template.xlsx</strong>, and screenshot via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div> -->
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 12. Tips for Success
                </div>
                <ul>
                    <li><strong>Always preview before printing:</strong> Use Print Preview (Ctrl + P) to save paper and ensure layout is correct.</li>
                    <li><strong>Use Print Titles for long reports:</strong> Keep headers visible on each printed page for readability.</li>
                    <li><strong>Experiment with scaling:</strong> Fit content neatly on one page when possible to avoid awkward page breaks.</li>
                    <li><strong>Save custom views:</strong> If you frequently switch between different layouts or zoom levels.</li>
                    <li><strong>Freeze panes wisely:</strong> Freeze only what you need to see while scrolling through large datasets.</li>
                    <li><strong>Use narrow margins:</strong> When printing data-heavy sheets to maximize space usage.</li>
                    <li><strong>Add page numbers:</strong> Essential for multi-page documents to keep pages organized.</li>
                    <li><strong>Test print on one page first:</strong> Before printing large batches, print one copy to check formatting.</li>
                    <li><strong>Use landscape for wide tables:</strong> Switch to landscape orientation when tables have many columns.</li>
                    <li><strong>Update document properties:</strong> Makes files easier to find and organize later.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/page-setup-in-excel-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Microsoft Excel Page Setup Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-header-or-footer-in-excel-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Create Headers and Footers in Excel</a></li>
                    <li><a href="https://support.microsoft.com/office/freeze-panes-to-lock-rows-and-columns-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Freeze Panes to Lock Rows and Columns</a></li>
                    <li><a href="https://exceljet.net/keyboard-shortcuts/excel-printing-shortcuts" target="_blank">Excel Printing Shortcuts Reference</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Week 2 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Invoice and Report Templates</strong> for practice and real-world use</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 13. Next Week Preview
                </div>
                <p><strong>Week 3: Cell Formatting, Styles & Advanced Data Entry</strong></p>
                <p>In Week 3, we'll build on your formatting skills and learn to:</p>
                <ul>
                    <li>Advanced data entry techniques (Flash Fill, special paste options)</li>
                    <li>Cell formatting (alignment, text wrapping, merging cells)</li>
                    <li>Using Format Painter for consistent styling across worksheets</li>
                    <li>Applying number formats (currency, percentages, dates, fractions)</li>
                    <li>Using cell styles and themes for professional appearance</li>
                    <li>Working with named ranges for easier formula creation</li>
                    <li>Basic conditional formatting to highlight important data</li>
                    <li>Data validation for controlled data entry</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring your formatted sales report and invoice for Week 3 enhancements and styling.</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week2.php">Week 2 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft Excel Help:</strong> <a href="https://support.microsoft.com/excel" target="_blank">Official Support</a></li>
                    <li><strong>Excel Printing Guide:</strong> <a href="https://support.microsoft.com/office/print-a-worksheet-or-workbook-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Printing Worksheets</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week2_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 2 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 2 Handout</p>
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
            alert('Sales report template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week2_sales_report.xlsx';
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
                console.log('Excel Week 2 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 2 Handout',
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
                    "1. Double-click the line between column letters, or use Home → Format → AutoFit Column Width.",
                    "2. Ctrl + ` (grave accent key next to number 1) shows/hides formulas.",
                    "3. In Header/Footer Design tab, check 'Different First Page' option.",
                    "4. Page Break Preview shows where pages will break when printed, with blue lines indicating breaks.",
                    "5. Page Setup → Sheet tab → Print Titles → Rows to repeat at top: select row 1."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive demonstrations
        document.addEventListener('DOMContentLoaded', function() {
            // View cards interaction
            const viewCards = document.querySelectorAll('.view-card');
            viewCards.forEach(card => {
                card.addEventListener('click', function() {
                    const viewName = this.querySelector('h4').textContent;
                    const shortcut = this.querySelector('p:last-child').textContent;
                    const descriptions = {
                        'Normal View': 'Default view for data entry and editing. Shows gridlines but not page boundaries.',
                        'Page Layout View': 'Shows pages as they will print, with margins, headers, and footers visible.',
                        'Page Break Preview': 'Shows page breaks as blue lines. Drag to adjust where pages break.',
                        'Custom Views': 'Save specific view settings (zoom, hidden rows/columns, etc.) for quick recall.'
                    };
                    alert(`${viewName}\n\n${descriptions[viewName]}\n\nShortcut: ${shortcut}`);
                });
            });

            // Orientation cards interaction
            const orientationCards = document.querySelectorAll('.orientation-card');
            orientationCards.forEach(card => {
                card.addEventListener('click', function() {
                    const orientation = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:nth-child(3)').textContent;
                    const bestFor = this.querySelector('p:last-child').textContent;
                    alert(`${orientation} Orientation\n\n${description}\n\n${bestFor}\n\nChange in: Page Layout → Orientation`);
                });
            });

            // Freeze panes demonstration
            const freezeDemo = document.querySelector('.freeze-demo .scrollable-area');
            if (freezeDemo) {
                freezeDemo.addEventListener('click', function() {
                    alert("Freeze Panes Demonstration\n\n1. Select cell B2 (below row 1, right of column A)\n2. View → Freeze Panes → Freeze Panes\n3. Row 1 and Column A are now frozen\n4. Scroll to see them remain visible\n\nTo unfreeze: View → Freeze Panes → Unfreeze Panes");
                });
            }

            // Print area demonstration
            const printArea = document.querySelector('.print-area-demo');
            if (printArea) {
                printArea.addEventListener('click', function() {
                    alert("Print Area Demonstration\n\n1. Select range A1:D5\n2. Page Layout → Print Area → Set Print Area\n3. Only selected cells will print\n4. Dashed line shows print area boundary\n\nTo clear: Page Layout → Print Area → Clear Print Area");
                });
            }
        });

        // Keyboard shortcut practice for Week 2
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'p': 'Print Dialog (Ctrl + P)',
                '`': 'Show/Hide Formulas (Ctrl + `)'
            };
            
            // Check for Ctrl + key combinations
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
            
            // Simulate Ctrl + ` for showing formulas
            if (e.ctrlKey && e.key === '`') {
                e.preventDefault();
                alert('Ctrl + `: Toggle Formula View\n\nShows formulas instead of results. Useful for auditing and debugging.');
            }
            
            // Simulate Ctrl + P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                alert('Ctrl + P: Print Dialog\n\nOpens print preview and settings. Always preview before printing!');
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
            
            @keyframes highlight {
                0% { background-color: #ffffcc; }
                100% { background-color: transparent; }
            }
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard navigation hints
            const interactiveElements = document.querySelectorAll('a, button, .view-card, .orientation-card, .layout-item');
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

        // Page setup simulation
        function simulatePageSetup() {
            const steps = [
                "Page Setup Dialog Simulation",
                "1. Page Tab:",
                "   Orientation: ○ Portrait ● Landscape",
                "   Scaling: ○ Adjust to: 100% ● Fit to: 1 page(s) wide by 1 tall",
                "",
                "2. Margins Tab:",
                "   Top: 0.75\"   Bottom: 0.75\"",
                "   Left: 0.7\"   Right: 0.7\"",
                "   ✓ Center on page horizontally",
                "",
                "3. Header/Footer Tab:",
                "   Custom Header...",
                "   Left: &[Tab]   Center: Sales Report   Right: &[Date]",
                "",
                "4. Sheet Tab:",
                "   Print area: $A$1:$D$5",
                "   Rows to repeat at top: $1:$1",
                "   ✓ Gridlines"
            ];
            alert(steps.join("\n"));
        }

        // Header/Footer code demonstration
        function showHeaderFooterCodes() {
            const codes = [
                "Header/Footer Codes:",
                "&[Page] - Current page number",
                "&[Pages] - Total number of pages",
                "&[Date] - Current date",
                "&[Time] - Current time",
                "&[File] - File name",
                "&[Path]&[File] - Full file path",
                "&[Tab] - Sheet name",
                "",
                "Example:",
                "Left: &[Tab]",
                "Center: Sales Report",
                "Right: Page &[Page] of &[Pages]"
            ];
            alert(codes.join("\n"));
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
    $viewer = new ExcelWeek2HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>