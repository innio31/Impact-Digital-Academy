<?php
// modules/shared/course_materials/MSExcel/excel_week3_view.php

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
 * Excel Week 3 Handout Viewer Class with PDF Download
 */
class ExcelWeek3HandoutViewer
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
            $mpdf->SetTitle('Week 3: Data Entry, Cell Formatting & Named Ranges');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Data Entry, Cell Formatting, Named Ranges, Conditional Formatting, Format Painter');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week3_Data_Formatting_' . date('Y-m-d') . '.pdf';
            
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
                Week 3: Data Entry, Cell Formatting & Named Ranges
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 3!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll dive deeper into data manipulation, cell formatting, and named ranges. These skills are essential for organizing data efficiently, improving readability, and preparing for more advanced operations like formulas and data analysis.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Use Special Paste options for precise data manipulation</li>
                    <li>Apply advanced cell formatting and alignment techniques</li>
                    <li>Create and use named ranges for easier formula references</li>
                    <li>Apply conditional formatting to visualize data patterns</li>
                    <li>Use Format Painter efficiently across multiple cells</li>
                    <li>Insert and manage Sparklines for data trends</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Manipulating Data in Worksheets</h3>
                
                <p><strong>A. Using Special Paste Options</strong></p>
                <p><strong>Why use Special Paste?</strong> To paste only specific elements (values, formats, formulas) without altering other content.</p>
                <p><strong>How to use:</strong></p>
                <ol>
                    <li>Copy the source cell(s) (Ctrl + C)</li>
                    <li>Right-click the destination cell → Paste Special</li>
                    <li>Choose from:
                        <ul>
                            <li><strong>Values:</strong> Paste only the numbers/text (no formulas)</li>
                            <li><strong>Formulas:</strong> Paste formulas only</li>
                            <li><strong>Formats:</strong> Copy only the formatting</li>
                            <li><strong>Transpose:</strong> Switch rows to columns or columns to rows</li>
                        </ul>
                    </li>
                </ol>
                
                <p><strong>B. Filling Cells with AutoFill</strong></p>
                <ul>
                    <li><strong>AutoFill Handle:</strong> The small square at the bottom-right of a selected cell</li>
                    <li><strong>What it can do:</strong>
                        <ul>
                            <li>Copy values/formulas down or across</li>
                            <li>Fill series (days, months, numbers, dates)</li>
                            <li>Fill patterns (e.g., 1, 2, 3… or Mon, Tue, Wed…)</li>
                        </ul>
                    </li>
                    <li><strong>Try:</strong> Type "Q1" in A1, then drag the AutoFill handle down to fill Q2, Q3, Q4</li>
                </ul>
                
                <p><strong>C. Inserting and Deleting Multiple Rows/Columns</strong></p>
                <ul>
                    <li><strong>To insert:</strong>
                        <ol>
                            <li>Select the number of rows/columns you want to insert</li>
                            <li>Right-click → Insert</li>
                        </ol>
                    </li>
                    <li><strong>To delete:</strong>
                        <ol>
                            <li>Select the rows/columns to delete</li>
                            <li>Right-click → Delete</li>
                        </ol>
                    </li>
                    <li><strong>Shortcut for Insert:</strong> Ctrl + Shift + + (plus sign)</li>
                    <li><strong>Shortcut for Delete:</strong> Ctrl + - (minus sign)</li>
                </ul>
                
                <p><strong>D. Inserting and Deleting Cells</strong></p>
                <ul>
                    <li><strong>Shift Cells:</strong> When deleting or inserting individual cells, you can choose to shift cells up, down, left, or right</li>
                    <li>Right-click → Insert… or Delete… → Choose direction</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Formatting Cells and Ranges</h3>
                
                <p><strong>A. Merging and Unmerging Cells</strong></p>
                <ul>
                    <li><strong>Merge & Center:</strong> Combine selected cells into one and center content
                        <ul>
                            <li>Home → Merge & Center (or dropdown for more options)</li>
                        </ul>
                    </li>
                    <li><strong>Unmerge:</strong> Select merged cell → Home → Merge & Center → Unmerge Cells</li>
                    <li><strong>⚠️ Caution:</strong> Merging can affect sorting, filtering, and formulas</li>
                </ul>
                
                <p><strong>B. Modifying Cell Alignment, Orientation, and Indentation</strong></p>
                <ul>
                    <li><strong>Alignment Group (Home tab):</strong>
                        <ul>
                            <li><strong>Horizontal:</strong> Left, Center, Right</li>
                            <li><strong>Vertical:</strong> Top, Middle, Bottom</li>
                            <li><strong>Orientation:</strong> Rotate text diagonally or vertically</li>
                            <li><strong>Indent:</strong> Increase/decrease text indentation</li>
                        </ul>
                    </li>
                </ul>
                
                <p><strong>C. Using Format Painter</strong></p>
                <ul>
                    <li>Copy formatting from one cell to another</li>
                    <li><strong>Steps:</strong>
                        <ol>
                            <li>Select the cell with the desired formatting</li>
                            <li>Click Format Painter (Home tab)</li>
                            <li>Click the target cell(s) to apply formatting</li>
                        </ol>
                    </li>
                    <li><strong>Double-click Format Painter</strong> to apply to multiple non-adjacent cells</li>
                </ul>
                
                <p><strong>D. Wrapping Text Within Cells</strong></p>
                <ul>
                    <li>Home → Wrap Text</li>
                    <li>Automatically adjusts row height so all text is visible</li>
                    <li>Useful for long descriptions or addresses</li>
                </ul>
                
                <p><strong>E. Applying Number Formats</strong></p>
                <ul>
                    <li><strong>Common Formats:</strong>
                        <ul>
                            <li><strong>General:</strong> Default</li>
                            <li><strong>Number:</strong> With decimals, thousands separator</li>
                            <li><strong>Currency:</strong> Adds currency symbol</li>
                            <li><strong>Accounting:</strong> Aligns currency symbols and decimals</li>
                            <li><strong>Date/Time:</strong> Various date and time formats</li>
                            <li><strong>Percentage:</strong> Multiplies by 100 and adds %</li>
                        </ul>
                    </li>
                    <li>Apply via Home → Number Format dropdown or Format Cells (Ctrl + 1)</li>
                </ul>
                
                <p><strong>F. Using the Format Cells Dialog Box</strong></p>
                <ul>
                    <li><strong>Open:</strong> Ctrl + 1 or right-click → Format Cells</li>
                    <li><strong>Tabs:</strong>
                        <ul>
                            <li><strong>Number:</strong> Number formatting</li>
                            <li><strong>Alignment:</strong> Text alignment and orientation</li>
                            <li><strong>Font:</strong> Font style, size, color</li>
                            <li><strong>Border:</strong> Add cell borders</li>
                            <li><strong>Fill:</strong> Cell background color/pattern</li>
                            <li><strong>Protection:</strong> Lock/unlock cells (requires sheet protection)</li>
                        </ul>
                    </li>
                </ul>
                
                <p><strong>G. Applying Cell Styles</strong></p>
                <ul>
                    <li>Predefined combinations of formats (fonts, colors, borders)</li>
                    <li>Home → Cell Styles</li>
                    <li>Use for consistent professional formatting (e.g., Title, Heading 1, Good, Bad, Neutral)</li>
                </ul>
                
                <p><strong>H. Clearing Cell Formatting</strong></p>
                <ul>
                    <li>Select cells → Home → Clear → Clear Formats</li>
                    <li>Removes formatting but keeps data</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Defining and Referencing Named Ranges</h3>
                
                <p><strong>A. Defining a Named Range</strong></p>
                <ul>
                    <li>A name that refers to a cell or range (e.g., "SalesData" instead of "A1:D10")</li>
                    <li><strong>To create:</strong>
                        <ol>
                            <li>Select the range</li>
                            <li>Type the name in the Name Box (left of formula bar) → Press Enter</li>
                        </ol>
                    </li>
                    <li>Or use: Formulas → Define Name</li>
                    <li><strong>Rules:</strong> Names can't contain spaces, must start with a letter or underscore</li>
                </ul>
                
                <p><strong>B. Naming a Table</strong></p>
                <ul>
                    <li>When you create an Excel table, it gets a default name (e.g., Table1)</li>
                    <li><strong>To rename:</strong>
                        <ol>
                            <li>Click inside the table</li>
                            <li>Table Design → Table Name (enter new name)</li>
                        </ol>
                    </li>
                    <li>Use table names in formulas (e.g., =SUM(Table1[Sales]))</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">4. Summarizing Data Visually (Introduction)</h3>
                
                <p><strong>A. Inserting Sparklines</strong></p>
                <ul>
                    <li>Mini-charts inside a single cell</li>
                    <li><strong>To insert:</strong>
                        <ol>
                            <li>Select the data range</li>
                            <li>Insert → Sparklines (choose Line, Column, Win/Loss)</li>
                            <li>Choose location range</li>
                        </ol>
                    </li>
                    <li>Use to show trends in a small space</li>
                </ul>
                
                <p><strong>B. Applying Built-in Conditional Formatting</strong></p>
                <ul>
                    <li>Automatically format cells based on their values</li>
                    <li>Home → Conditional Formatting</li>
                    <li><strong>Examples:</strong>
                        <ul>
                            <li><strong>Highlight Cells Rules:</strong> Greater than, less than, between</li>
                            <li><strong>Top/Bottom Rules:</strong> Top 10%, bottom 5 items</li>
                            <li><strong>Data Bars / Color Scales / Icon Sets</strong></li>
                        </ul>
                    </li>
                    <li><strong>To remove:</strong> Conditional Formatting → Clear Rules</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">5. Hands-On Exercise: Format a Product Inventory Sheet</h3>
                <p><strong>Objective:</strong> Apply Week 3 skills to create a well-formatted, easy-to-read inventory list.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li><strong>Create a new workbook</strong> and enter the following data:
                        <table style="width: 100%; border-collapse: collapse; margin: 10px 0; border: 1px solid #ddd;">
                            <thead>
                                <tr style="background-color: #f2f2f2;">
                                    <th style="border: 1px solid #ddd; padding: 8px;">Product ID</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Product Name</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Category</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Price</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;">P001</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Wireless Mouse</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Electronics</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">29.99</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">45</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;">P002</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Mechanical Keyboard</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Electronics</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">89.99</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">22</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;">P003</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Laptop Stand</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Accessories</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">34.50</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">100</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;">P004</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">USB-C Cable</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Accessories</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">15.99</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">200</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;">P005</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Monitor 24"</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">Electronics</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">199.99</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">15</td>
                                </tr>
                            </tbody>
                        </table>
                    </li>
                    <li><strong>Format the Header Row:</strong>
                        <ul>
                            <li>Merge & Center A1:E1, type "Product Inventory Q1"</li>
                            <li>Apply Cell Style: Title</li>
                            <li>Bold and center align A2:E2 headers</li>
                            <li>Apply Cell Style: Heading 1</li>
                        </ul>
                    </li>
                    <li><strong>Format Data:</strong>
                        <ul>
                            <li>Apply Currency format to Price column</li>
                            <li>Center align Product ID and Stock columns</li>
                            <li>Wrap text in Product Name column if needed</li>
                            <li>Apply Data Bars (Conditional Formatting) to Stock column</li>
                        </ul>
                    </li>
                    <li><strong>Create a Named Range:</strong>
                        <ul>
                            <li>Select Price data (D3:D7)</li>
                            <li>Name it "PriceList" in the Name Box</li>
                        </ul>
                    </li>
                    <li><strong>Use Format Painter:</strong>
                        <ul>
                            <li>Format one cell with fill color and bold</li>
                            <li>Use Format Painter to apply to low stock items (e.g., Stock < 30)</li>
                        </ul>
                    </li>
                    <li><strong>Save</strong> as Inventory_Formatted.xlsx</li>
                </ol>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">6. Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Personal Budget Formatter:</strong>
                        <ul>
                            <li>Create a simple monthly budget with Income and Expenses</li>
                            <li>Use:
                                <ul>
                                    <li>Special Paste to copy values only</li>
                                    <li>Currency formatting</li>
                                    <li>Cell Styles for totals</li>
                                    <li>Conditional formatting to highlight overspending</li>
                                </ul>
                            </li>
                            <li>Name the total income and expense ranges</li>
                        </ul>
                    </li>
                    <li><strong>Sparkline Practice:</strong>
                        <ul>
                            <li>Create a small dataset of monthly sales (6 months)</li>
                            <li>Insert Sparklines next to the data to show trend</li>
                            <li>Apply Data Bars to another column</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What is the shortcut for Format Cells dialog?</li>
                            <li>How do you apply Format Painter to multiple cells?</li>
                            <li>What are the rules for naming a range?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">7. Essential Shortcuts for Week 3</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + 1</td>
                            <td style="padding: 6px 8px;">Format Cells dialog</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + +</td>
                            <td style="padding: 6px 8px;">Insert rows/columns/cells</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + -</td>
                            <td style="padding: 6px 8px;">Delete rows/columns/cells</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → F → P</td>
                            <td style="padding: 6px 8px;">Format Painter</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → W</td>
                            <td style="padding: 6px 8px;">Wrap Text toggle</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → M → C</td>
                            <td style="padding: 6px 8px;">Merge & Center</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">F3</td>
                            <td style="padding: 6px 8px;">Paste Name (in formulas)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Special Paste:</strong> Options to paste specific elements like values, formulas, or formatting only.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Named Range:</strong> A descriptive name assigned to a cell or range for easier reference.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Conditional Formatting:</strong> Automatically formats cells based on their values or conditions.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Format Painter:</strong> Tool to copy formatting from one cell to another.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Sparklines:</strong> Miniature charts that fit within a single cell to show data trends.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Cell Styles:</strong> Predefined combinations of formatting options for consistent look.</p>
                </div>
                <div>
                    <p><strong>Transpose:</strong> Changing data orientation from rows to columns or vice versa.</p>
                </div>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Use Paste Special options</li>
                    <li>Insert/delete cells, rows, columns</li>
                    <li>Merge and split cells</li>
                    <li>Modify cell alignment and orientation</li>
                    <li>Apply number formats</li>
                    <li>Use Format Painter</li>
                    <li>Apply cell styles</li>
                    <li>Wrap text within cells</li>
                    <li>Define and use named ranges</li>
                    <li>Insert and format Sparklines</li>
                    <li>Apply conditional formatting</li>
                    <li>Clear cell formatting</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">8. Tips for Success</h3>
                <ul>
                    <li><strong>Use Named Ranges</strong> to make formulas easier to read and manage.</li>
                    <li><strong>Avoid over-merging cells</strong>—it can complicate data manipulation later.</li>
                    <li><strong>Experiment with Conditional Formatting</strong> to quickly visualize data patterns.</li>
                    <li><strong>Save formatting styles as Cell Styles</strong> for consistency across workbooks.</li>
                    <li><strong>Double-click Format Painter</strong> for applying formatting to multiple non-adjacent cells.</li>
                    <li><strong>Use F4 key</strong> to repeat your last action (including formatting).</li>
                    <li><strong>Create a formatting template</strong> for frequently used document types.</li>
                    <li><strong>Name ranges logically</strong> using prefixes like "data_" or "calc_" for organization.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">9. Next Week Preview</h3>
                <p>In Week 4, we'll cover:</p>
                <ul>
                    <li>Creating and formatting Excel Tables</li>
                    <li>Adding/removing table rows and columns</li>
                    <li>Table style options and total rows</li>
                    <li>Filtering and sorting table data</li>
                    <li>Converting tables to ranges</li>
                    <li>Using structured references in formulas</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Prepare a dataset with at least 20 rows and 5 columns to practice table creation.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Excel Format Cells Dialog Deep Dive</li>
                    <li>Named Ranges Best Practices Guide</li>
                    <li>Conditional Formatting Examples Gallery</li>
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
                Week 3 Handout: Data Entry, Cell Formatting & Named Ranges
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
            Week 3: Data Entry, Cell Formatting & Named Ranges | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 3: Data Entry, Cell Formatting & Named Ranges - Impact Digital Academy</title>
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

        /* Formatting Cards */
        .format-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .format-card {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .format-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .format-icon {
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

        /* Data Manipulation Demo */
        .data-demo {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 25px 0;
        }

        .data-box {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .data-box.before {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .data-box.after {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .data-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        /* Paste Special Demo */
        .paste-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 25px 0;
        }

        .paste-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .paste-card.values {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .paste-card.formulas {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .paste-card.formats {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .paste-card.transpose {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .paste-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .paste-card.values .paste-icon {
            color: #2196f3;
        }

        .paste-card.formulas .paste-icon {
            color: #4caf50;
        }

        .paste-card.formats .paste-icon {
            color: #9c27b0;
        }

        .paste-card.transpose .paste-icon {
            color: #ff9800;
        }

        /* Conditional Formatting Demo */
        .cf-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 25px 0;
        }

        .cf-item {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            font-weight: bold;
        }

        .cf-item.high {
            background: linear-gradient(to right, #ffebee, #ffcdd2);
            color: #c62828;
            border: 2px solid #ff5252;
        }

        .cf-item.medium {
            background: linear-gradient(to right, #fff3e0, #ffe0b2);
            color: #ef6c00;
            border: 2px solid #ff9800;
        }

        .cf-item.low {
            background: linear-gradient(to right, #e8f5e9, #c8e6c9);
            color: #2e7d32;
            border: 2px solid #4caf50;
        }

        /* Sparkline Demo */
        .sparkline-demo {
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            height: 60px;
            margin: 25px 0;
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .sparkline-bar {
            width: 30px;
            background: #107c10;
            border-radius: 3px 3px 0 0;
        }

        .sparkline-bar:nth-child(2) {
            height: 80%;
            background: #4caf50;
        }

        .sparkline-bar:nth-child(3) {
            height: 60%;
            background: #8bc34a;
        }

        .sparkline-bar:nth-child(4) {
            height: 90%;
            background: #107c10;
        }

        .sparkline-bar:nth-child(5) {
            height: 40%;
            background: #cddc39;
        }

        .sparkline-bar:nth-child(6) {
            height: 70%;
            background: #4caf50;
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

            .data-demo {
                grid-template-columns: 1fr;
            }

            .format-cards,
            .paste-cards,
            .cf-demo {
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
                <strong>Access Granted:</strong> Excel Week 3 Handout
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
                <i class="fas fa-arrow-left"></i> Week 2
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week4_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 4
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 3 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Data Entry, Cell Formatting & Named Ranges</div>
            <div class="week-tag">Week 3 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 3!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll dive deeper into data manipulation, cell formatting, and named ranges. These skills are essential for organizing data efficiently, improving readability, and preparing for more advanced operations like formulas and data analysis.
                </p>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Use Special Paste options for precise data manipulation</li>
                    <li>Apply advanced cell formatting and alignment techniques</li>
                    <li>Create and use named ranges for easier formula references</li>
                    <li>Apply conditional formatting to visualize data patterns</li>
                    <li>Use Format Painter efficiently across multiple cells</li>
                    <li>Insert and manage Sparklines for data trends</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Use Paste Special options</li>
                        <li>Insert/delete cells, rows, columns</li>
                        <li>Merge and split cells</li>
                        <li>Modify cell alignment and orientation</li>
                        <li>Apply number formats</li>
                        <li>Use Format Painter</li>
                    </ul>
                    <ul>
                        <li>Apply cell styles</li>
                        <li>Wrap text within cells</li>
                        <li>Define and use named ranges</li>
                        <li>Insert and format Sparklines</li>
                        <li>Apply conditional formatting</li>
                        <li>Clear cell formatting</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Data Manipulation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cogs"></i> 1. Manipulating Data in Worksheets
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-paste"></i> A. Using Special Paste Options</h3>
                    <p><strong>Why use Special Paste?</strong> To paste only specific elements (values, formats, formulas) without altering other content.</p>
                    
                    <div class="paste-cards">
                        <div class="paste-card values">
                            <div class="paste-icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <h4>Values</h4>
                            <p>Paste only numbers/text (no formulas)</p>
                        </div>
                        <div class="paste-card formulas">
                            <div class="paste-icon">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <h4>Formulas</h4>
                            <p>Paste formulas only</p>
                        </div>
                        <div class="paste-card formats">
                            <div class="paste-icon">
                                <i class="fas fa-paint-brush"></i>
                            </div>
                            <h4>Formats</h4>
                            <p>Copy only the formatting</p>
                        </div>
                        <div class="paste-card transpose">
                            <div class="paste-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <h4>Transpose</h4>
                            <p>Switch rows ↔ columns</p>
                        </div>
                    </div>

                    <div class="data-demo">
                        <div class="data-box before">
                            <div class="data-title">Before Paste Special:</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px; border: 1px solid #ccc; background: #ffebee;">A1: =B1*1.1</td>
                                    <td style="padding: 5px; border: 1px solid #ccc; background: #ffebee;">$100.00</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border: 1px solid #ccc;">C1: (Empty)</td>
                                    <td style="padding: 5px; border: 1px solid #ccc;">(Empty)</td>
                                </tr>
                            </table>
                        </div>
                        <div class="data-box after">
                            <div class="data-title">After Paste Special (Values):</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px; border: 1px solid #ccc; background: #e8f5e9;">C1: $110.00</td>
                                    <td style="padding: 5px; border: 1px solid #ccc; background: #e8f5e9;">(Static Value)</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border: 1px solid #ccc;">No formula</td>
                                    <td style="padding: 5px; border: 1px solid #ccc;">No link to B1</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <p><strong>How to use:</strong></p>
                    <ol>
                        <li>Copy the source cell(s) (<span class="shortcut-key">Ctrl + C</span>)</li>
                        <li>Right-click the destination cell → <strong>Paste Special</strong></li>
                        <li>Choose from: Values, Formulas, Formats, or Transpose</li>
                    </ol>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-fill-drip"></i> B. Filling Cells with AutoFill</h3>
                    <ul>
                        <li><strong>AutoFill Handle:</strong> The small square at the bottom-right of a selected cell</li>
                        <li><strong>What it can do:</strong>
                            <ul>
                                <li>Copy values/formulas down or across</li>
                                <li>Fill series (days, months, numbers, dates)</li>
                                <li>Fill patterns (e.g., 1, 2, 3… or Mon, Tue, Wed…)</li>
                            </ul>
                        </li>
                        <li><strong>Try:</strong> Type "Q1" in A1, then drag the AutoFill handle down to fill Q2, Q3, Q4</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/autofill_series.png"
                            alt="Excel AutoFill Series"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBBdXRvRmlsbCBTZXJpZXM8L3RleHQ+PC9zdmc+='">
                        <div class="image-caption">Using AutoFill to create series and patterns</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> C. Inserting and Deleting Multiple Rows/Columns</h3>
                    <ul>
                        <li><strong>To insert:</strong>
                            <ol>
                                <li>Select the number of rows/columns you want to insert</li>
                                <li>Right-click → <strong>Insert</strong></li>
                            </ol>
                        </li>
                        <li><strong>To delete:</strong>
                            <ol>
                                <li>Select the rows/columns to delete</li>
                                <li>Right-click → <strong>Delete</strong></li>
                            </ol>
                        </li>
                        <li><strong>Shortcut for Insert:</strong> <span class="shortcut-key">Ctrl + Shift + +</span> (plus sign)</li>
                        <li><strong>Shortcut for Delete:</strong> <span class="shortcut-key">Ctrl + -</span> (minus sign)</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-arrows-alt-h"></i> D. Inserting and Deleting Cells</h3>
                    <ul>
                        <li><strong>Shift Cells:</strong> When deleting or inserting individual cells, you can choose to shift cells up, down, left, or right</li>
                        <li>Right-click → <strong>Insert…</strong> or <strong>Delete…</strong> → Choose direction</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Cell Formatting -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-paint-brush"></i> 2. Formatting Cells and Ranges
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-object-group"></i> A. Merging and Unmerging Cells</h3>
                    <ul>
                        <li><strong>Merge & Center:</strong> Combine selected cells into one and center content
                            <ul>
                                <li>Home → <strong>Merge & Center</strong> (or dropdown for more options)</li>
                            </ul>
                        </li>
                        <li><strong>Unmerge:</strong> Select merged cell → Home → Merge & Center → <strong>Unmerge Cells</strong></li>
                        <li><strong>⚠️ Caution:</strong> Merging can affect sorting, filtering, and formulas</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/merge_cells.png"
                            alt="Excel Merge Cells"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBNZXJnZSBDZWxsczwvdGV4dD48L3N2Zz4='">
                        <div class="image-caption">Merging cells for titles and headers</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-align-center"></i> B. Modifying Cell Alignment, Orientation, and Indentation</h3>
                    <ul>
                        <li><strong>Alignment Group (Home tab):</strong>
                            <ul>
                                <li><strong>Horizontal:</strong> Left, Center, Right</li>
                                <li><strong>Vertical:</strong> Top, Middle, Bottom</li>
                                <li><strong>Orientation:</strong> Rotate text diagonally or vertically</li>
                                <li><strong>Indent:</strong> Increase/decrease text indentation</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-brush"></i> C. Using Format Painter</h3>
                    <ul>
                        <li>Copy formatting from one cell to another</li>
                        <li><strong>Steps:</strong>
                            <ol>
                                <li>Select the cell with the desired formatting</li>
                                <li>Click <strong>Format Painter</strong> (Home tab)</li>
                                <li>Click the target cell(s) to apply formatting</li>
                            </ol>
                        </li>
                        <li><strong>Double-click Format Painter</strong> to apply to multiple non-adjacent cells</li>
                        <li><strong>Shortcut:</strong> Alt → H → F → P</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-text-height"></i> D. Wrapping Text Within Cells</h3>
                    <ul>
                        <li>Home → <strong>Wrap Text</strong></li>
                        <li>Automatically adjusts row height so all text is visible</li>
                        <li>Useful for long descriptions or addresses</li>
                        <li><strong>Shortcut:</strong> Alt → H → W</li>
                    </ul>

                    <div class="data-demo">
                        <div class="data-box before">
                            <div class="data-title">Without Wrap Text:</div>
                            <div style="padding: 10px; background: white; border: 1px solid #ccc; overflow: hidden; white-space: nowrap;">
                                This is a very long product description that extends beyond the cell width
                            </div>
                        </div>
                        <div class="data-box after">
                            <div class="data-title">With Wrap Text:</div>
                            <div style="padding: 10px; background: white; border: 1px solid #ccc; height: 60px;">
                                This is a very long product description that wraps within the cell boundaries
                            </div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-dollar-sign"></i> E. Applying Number Formats</h3>
                    <ul>
                        <li><strong>Common Formats:</strong>
                            <ul>
                                <li><strong>General:</strong> Default</li>
                                <li><strong>Number:</strong> With decimals, thousands separator</li>
                                <li><strong>Currency:</strong> Adds currency symbol</li>
                                <li><strong>Accounting:</strong> Aligns currency symbols and decimals</li>
                                <li><strong>Date/Time:</strong> Various date and time formats</li>
                                <li><strong>Percentage:</strong> Multiplies by 100 and adds %</li>
                            </ul>
                        </li>
                        <li>Apply via Home → Number Format dropdown or Format Cells (<span class="shortcut-key">Ctrl + 1</span>)</li>
                    </ul>

                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Value</th>
                                <th>General</th>
                                <th>Currency</th>
                                <th>Percentage</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1234.5</td>
                                <td>1234.5</td>
                                <td>$1,234.50</td>
                                <td>123450%</td>
                                <td>N/A</td>
                            </tr>
                            <tr>
                                <td>0.75</td>
                                <td>0.75</td>
                                <td>$0.75</td>
                                <td>75%</td>
                                <td>N/A</td>
                            </tr>
                            <tr>
                                <td>44562</td>
                                <td>44562</td>
                                <td>$44,562.00</td>
                                <td>4456200%</td>
                                <td>2/1/2022</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-table"></i> F. Using the Format Cells Dialog Box</h3>
                    <ul>
                        <li><strong>Open:</strong> <span class="shortcut-key">Ctrl + 1</span> or right-click → Format Cells</li>
                        <li><strong>Tabs:</strong>
                            <ul>
                                <li><strong>Number:</strong> Number formatting</li>
                                <li><strong>Alignment:</strong> Text alignment and orientation</li>
                                <li><strong>Font:</strong> Font style, size, color</li>
                                <li><strong>Border:</strong> Add cell borders</li>
                                <li><strong>Fill:</strong> Cell background color/pattern</li>
                                <li><strong>Protection:</strong> Lock/unlock cells (requires sheet protection)</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-palette"></i> G. Applying Cell Styles</h3>
                    <ul>
                        <li>Predefined combinations of formats (fonts, colors, borders)</li>
                        <li>Home → <strong>Cell Styles</strong></li>
                        <li>Use for consistent professional formatting (e.g., Title, Heading 1, Good, Bad, Neutral)</li>
                    </ul>

                    <div class="cf-demo">
                        <div class="cf-item high">Bad / Negative</div>
                        <div class="cf-item medium">Neutral / Warning</div>
                        <div class="cf-item low">Good / Positive</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eraser"></i> H. Clearing Cell Formatting</h3>
                    <ul>
                        <li>Select cells → Home → <strong>Clear</strong> → Clear Formats</li>
                        <li>Removes formatting but keeps data</li>
                        <li><strong>Alternative:</strong> Select cells → Right-click → Clear Contents (removes data only)</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Named Ranges -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-tag"></i> 3. Defining and Referencing Named Ranges
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-map-pin"></i> A. Defining a Named Range</h3>
                    <ul>
                        <li>A name that refers to a cell or range (e.g., "SalesData" instead of "A1:D10")</li>
                        <li><strong>To create:</strong>
                            <ol>
                                <li>Select the range</li>
                                <li>Type the name in the <strong>Name Box</strong> (left of formula bar) → Press Enter</li>
                            </ol>
                        </li>
                        <li>Or use: Formulas → <strong>Define Name</strong></li>
                        <li><strong>Rules:</strong> Names can't contain spaces, must start with a letter or underscore</li>
                        <li><strong>Examples:</strong> Sales_Total, Q1_Revenue, Employee_List (use underscore instead of spaces)</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/name_box.png"
                            alt="Excel Name Box for Named Ranges"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBOYW1lIEJveCBmb3IgTmFtZWQgUmFuZ2VzPC90ZXh0Pjwvc3ZnPg='">
                        <div class="image-caption">Using Name Box to create named ranges</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-table"></i> B. Naming a Table</h3>
                    <ul>
                        <li>When you create an Excel table, it gets a default name (e.g., Table1)</li>
                        <li><strong>To rename:</strong>
                            <ol>
                                <li>Click inside the table</li>
                                <li>Table Design → <strong>Table Name</strong> (enter new name)</li>
                            </ol>
                        </li>
                        <li>Use table names in formulas (e.g., =SUM(Table1[Sales]))</li>
                        <li><strong>Benefits:</strong> Formulas automatically adjust when table expands</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Visual Summarization -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i> 4. Summarizing Data Visually (Introduction)
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-chart-line"></i> A. Inserting Sparklines</h3>
                    <ul>
                        <li>Mini-charts inside a single cell</li>
                        <li><strong>To insert:</strong>
                            <ol>
                                <li>Select the data range</li>
                                <li>Insert → <strong>Sparklines</strong> (choose Line, Column, Win/Loss)</li>
                                <li>Choose location range</li>
                            </ol>
                        </li>
                        <li>Use to show trends in a small space</li>
                        <li>Can be formatted with different colors and styles</li>
                    </ul>

                    <div class="sparkline-demo">
                        <div class="sparkline-bar" style="height: 50%;"></div>
                        <div class="sparkline-bar"></div>
                        <div class="sparkline-bar"></div>
                        <div class="sparkline-bar"></div>
                        <div class="sparkline-bar"></div>
                        <div class="sparkline-bar"></div>
                    </div>
                    <div style="text-align: center; margin-top: 10px; color: #666; font-size: 0.9rem;">
                        Sparkline showing monthly trend (Column type)
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-fill-drip"></i> B. Applying Built-in Conditional Formatting</h3>
                    <ul>
                        <li>Automatically format cells based on their values</li>
                        <li>Home → <strong>Conditional Formatting</strong></li>
                        <li><strong>Examples:</strong>
                            <ul>
                                <li><strong>Highlight Cells Rules:</strong> Greater than, less than, between</li>
                                <li><strong>Top/Bottom Rules:</strong> Top 10%, bottom 5 items</li>
                                <li><strong>Data Bars / Color Scales / Icon Sets</strong></li>
                            </ul>
                        </li>
                        <li><strong>To remove:</strong> Conditional Formatting → Clear Rules</li>
                    </ul>

                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Sales</th>
                                <th>Growth %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Product A</td>
                                <td style="background: linear-gradient(to right, #4caf50 75%, #e0e0e0 25%); color: #000;">$12,500</td>
                                <td style="background: #ffebee; color: #c62828;">-5%</td>
                                <td style="background: #ffebee; color: #c62828;">▼ Below Target</td>
                            </tr>
                            <tr>
                                <td>Product B</td>
                                <td style="background: linear-gradient(to right, #4caf50 92%, #e0e0e0 8%); color: #000;">$23,000</td>
                                <td style="background: #e8f5e9; color: #2e7d32;">+15%</td>
                                <td style="background: #e8f5e9; color: #2e7d32;">▲ Above Target</td>
                            </tr>
                            <tr>
                                <td>Product C</td>
                                <td style="background: linear-gradient(to right, #4caf50 60%, #e0e0e0 40%); color: #000;">$9,800</td>
                                <td style="background: #fff3e0; color: #ef6c00;">+2%</td>
                                <td style="background: #fff3e0; color: #ef6c00;">● On Target</td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="text-align: center; margin-top: 10px; color: #666; font-size: 0.9rem;">
                        Conditional formatting with Data Bars, color scales, and icon sets
                    </div>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 5. Hands-On Exercise: Format a Product Inventory Sheet
                </div>
                <p><strong>Objective:</strong> Apply Week 3 skills to create a well-formatted, easy-to-read inventory list.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Create a new workbook</strong> and enter the following data:
                            <table class="demo-table" style="margin: 15px 0;">
                                <thead>
                                    <tr>
                                        <th>Product ID</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>P001</td>
                                        <td>Wireless Mouse</td>
                                        <td>Electronics</td>
                                        <td>29.99</td>
                                        <td>45</td>
                                    </tr>
                                    <tr>
                                        <td>P002</td>
                                        <td>Mechanical Keyboard</td>
                                        <td>Electronics</td>
                                        <td>89.99</td>
                                        <td>22</td>
                                    </tr>
                                    <tr>
                                        <td>P003</td>
                                        <td>Laptop Stand</td>
                                        <td>Accessories</td>
                                        <td>34.50</td>
                                        <td>100</td>
                                    </tr>
                                    <tr>
                                        <td>P004</td>
                                        <td>USB-C Cable</td>
                                        <td>Accessories</td>
                                        <td>15.99</td>
                                        <td>200</td>
                                    </tr>
                                    <tr>
                                        <td>P005</td>
                                        <td>Monitor 24"</td>
                                        <td>Electronics</td>
                                        <td>199.99</td>
                                        <td>15</td>
                                    </tr>
                                </tbody>
                            </table>
                        </li>
                        <li><strong>Format the Header Row:</strong>
                            <ul>
                                <li>Merge & Center A1:E1, type "Product Inventory Q1"</li>
                                <li>Apply Cell Style: <strong>Title</strong></li>
                                <li>Bold and center align A2:E2 headers</li>
                                <li>Apply Cell Style: <strong>Heading 1</strong></li>
                            </ul>
                        </li>
                        <li><strong>Format Data:</strong>
                            <ul>
                                <li>Apply <strong>Currency</strong> format to Price column</li>
                                <li>Center align Product ID and Stock columns</li>
                                <li><strong>Wrap text</strong> in Product Name column if needed</li>
                                <li>Apply <strong>Data Bars</strong> (Conditional Formatting) to Stock column</li>
                            </ul>
                        </li>
                        <li><strong>Create a Named Range:</strong>
                            <ul>
                                <li>Select Price data (D3:D7)</li>
                                <li>Name it <strong>"PriceList"</strong> in the Name Box</li>
                            </ul>
                        </li>
                        <li><strong>Use Format Painter:</strong>
                            <ul>
                                <li>Format one cell with fill color and bold</li>
                                <li>Use Format Painter to apply to low stock items (e.g., Stock < 30)</li>
                            </ul>
                        </li>
                        <li><strong>Save</strong> as <strong>Inventory_Formatted.xlsx</strong></li>
                    </ol>
                </div>

                <!--
<div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Excel Formatting Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBGb3JtYXR0aW5nIEV4ZXJjaXNlPC90ZXh0Pjwvc3ZnPg='">
                    <div class="image-caption">Create a Professional Inventory Sheet</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Inventory Template
                </a> -->
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 6. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete these exercises:</h4>
                    <ol>
                        <li><strong>Personal Budget Formatter:</strong>
                            <ul>
                                <li>Create a simple monthly budget with Income and Expenses</li>
                                <li>Use:
                                    <ul>
                                        <li><strong>Special Paste</strong> to copy values only</li>
                                        <li><strong>Currency formatting</strong> for monetary values</li>
                                        <li><strong>Cell Styles</strong> for totals (Total, Good, Bad)</li>
                                        <li><strong>Conditional formatting</strong> to highlight overspending</li>
                                    </ul>
                                </li>
                                <li>Name the total income and expense ranges</li>
                            </ul>
                        </li>
                        <li><strong>Sparkline Practice:</strong>
                            <ul>
                                <li>Create a small dataset of monthly sales (6 months)</li>
                                <li>Insert <strong>Sparklines</strong> next to the data to show trend</li>
                                <li>Apply <strong>Data Bars</strong> to another column</li>
                                <li>Use <strong>Color Scales</strong> for a third column</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>What is the shortcut for Format Cells dialog?</li>
                                <li>How do you apply Format Painter to multiple cells?</li>
                                <li>What are the rules for naming a range?</li>
                                <li>How do you remove conditional formatting?</li>
                                <li>What happens when you merge cells with data?</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Complete the practice exercises and submit your <strong>Personal_Budget.xlsx</strong> and <strong>Sales_Sparklines.xlsx</strong> files via the class portal by the end of the week. Click <a href="https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/excel/week3_assignment.html" target="_blank"> here to access the assignment guide.</a>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 7. Essential Shortcuts for Week 3
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
                            <td><span class="shortcut-key">Ctrl + 1</span></td>
                            <td>Format Cells dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + +</span></td>
                            <td>Insert rows/columns/cells</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + -</span></td>
                            <td>Delete rows/columns/cells</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → F → P</span></td>
                            <td>Format Painter</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → W</span></td>
                            <td>Wrap Text toggle</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → M → C</span></td>
                            <td>Merge & Center</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F3</span></td>
                            <td>Paste Name (in formulas)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F4</span></td>
                            <td>Repeat last action (including formatting)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + C</span></td>
                            <td>Copy</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Alt + V</span></td>
                            <td>Paste Special dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + $</span></td>
                            <td>Apply Currency format</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + %</span></td>
                            <td>Apply Percentage format</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + #</span></td>
                            <td>Apply Date format</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + @</span></td>
                            <td>Apply Time format</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + H, H</span></td>
                            <td>Fill Color menu</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Special Paste</strong>
                    <p>Options to paste specific elements like values, formulas, or formatting only, without other content.</p>
                </div>

                <div class="term">
                    <strong>Named Range</strong>
                    <p>A descriptive name assigned to a cell or range for easier reference in formulas and navigation.</p>
                </div>

                <div class="term">
                    <strong>Conditional Formatting</strong>
                    <p>Automatically formats cells based on their values or specified conditions.</p>
                </div>

                <div class="term">
                    <strong>Format Painter</strong>
                    <p>Tool to copy formatting from one cell to another with a single click.</p>
                </div>

                <div class="term">
                    <strong>Sparklines</strong>
                    <p>Miniature charts that fit within a single cell to visually represent data trends.</p>
                </div>

                <div class="term">
                    <strong>Cell Styles</strong>
                    <p>Predefined combinations of formatting options for consistent professional appearance.</p>
                </div>

                <div class="term">
                    <strong>Transpose</strong>
                    <p>Changing data orientation from rows to columns or vice versa during paste operations.</p>
                </div>

                <div class="term">
                    <strong>Wrap Text</strong>
                    <p>Feature that automatically adjusts row height to display all text within a cell.</p>
                </div>

                <div class="term">
                    <strong>Merge Cells</strong>
                    <p>Combining multiple adjacent cells into one larger cell, often used for titles.</p>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Use Named Ranges</strong> to make formulas easier to read and manage.</li>
                    <li><strong>Avoid over-merging cells</strong>—it can complicate data manipulation, sorting, and filtering later.</li>
                    <li><strong>Experiment with Conditional Formatting</strong> to quickly visualize data patterns and outliers.</li>
                    <li><strong>Save formatting styles as Cell Styles</strong> for consistency across workbooks and worksheets.</li>
                    <li><strong>Double-click Format Painter</strong> for applying formatting to multiple non-adjacent cells.</li>
                    <li><strong>Use F4 key</strong> to repeat your last action (including formatting changes).</li>
                    <li><strong>Create a formatting template</strong> for frequently used document types (invoices, reports).</li>
                    <li><strong>Name ranges logically</strong> using prefixes like "data_" or "calc_" for better organization.</li>
                    <li><strong>Use Paste Special → Transpose</strong> to quickly rearrange data layouts.</li>
                    <li><strong>Test conditional formatting</strong> with different data scenarios to ensure it works as expected.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/use-paste-special-to-copy-and-paste-specific-cell-contents-4f5b4c8b-6d3a-4c5a-8b5a-5b5a5b5a5b5a" target="_blank">Microsoft: Paste Special Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/define-and-use-names-in-formulas-4d0f13ac-53b7-422e-afd2-abd7ff379c64" target="_blank">Named Ranges in Formulas</a></li>
                    <li><a href="https://support.microsoft.com/office/apply-conditional-formatting-in-excel-4f5b4c8b-6d3a-4c5a-8b5a-5b5a5b5a5b5a" target="_blank">Conditional Formatting Examples</a></li>
                    <li><a href="https://support.microsoft.com/office/create-sparklines-in-excel-4f5b4c8b-6d3a-4c5a-8b5a-5b5a5b5a5b5a" target="_blank">Sparklines Tutorial</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Week 3 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Formatting Cheat Sheet</strong> with all keyboard shortcuts</li>
                    <li><strong>Sample formatted workbooks</strong> for reference and inspiration</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p><strong>Week 4: Excel Tables, Sorting, and Filtering</strong></p>
                <p>In Week 4, we'll build on your formatting skills and learn to:</p>
                <ul>
                    <li>Create and format Excel Tables for structured data</li>
                    <li>Add and remove table rows and columns dynamically</li>
                    <li>Apply table style options and create total rows</li>
                    <li>Filter and sort table data for analysis</li>
                    <li>Convert tables back to ranges when needed</li>
                    <li>Use structured references in formulas (TableName[ColumnName])</li>
                    <li>Remove duplicates from datasets</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Prepare a dataset with at least 20 rows and 5 columns to practice table creation and manipulation.</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week3.php">Week 3 Discussion</a></li>
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
                <!-- <a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week3_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 3 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 3 Handout</p>
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
            alert('Inventory template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week3_inventory_template.xlsx';
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
                console.log('Excel Week 3 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 3 Handout',
                //         action: 'view'
                //     })
                // });
            }
        });

        // Interactive demonstrations
        document.addEventListener('DOMContentLoaded', function() {
            // Paste Special cards interaction
            const pasteCards = document.querySelectorAll('.paste-card');
            pasteCards.forEach(card => {
                card.addEventListener('click', function() {
                    const type = this.querySelector('h4').textContent;
                    const descriptions = {
                        'Values': 'Pastes only the cell values, no formulas or formatting.',
                        'Formulas': 'Pastes only formulas, keeps cell references relative.',
                        'Formats': 'Copies only formatting (colors, borders, fonts).',
                        'Transpose': 'Switches rows to columns or columns to rows.'
                    };
                    alert(`Paste Special: ${type}\n\n${descriptions[type]}\n\nTry it: Copy cells, right-click destination, choose Paste Special.`);
                });
            });

            // Conditional formatting demo interaction
            const cfItems = document.querySelectorAll('.cf-item');
            cfItems.forEach(item => {
                item.addEventListener('click', function() {
                    const type = this.textContent.split(' ')[0];
                    const rules = {
                        'Bad': 'Highlight cells less than threshold',
                        'Neutral': 'Highlight cells between values',
                        'Good': 'Highlight cells greater than threshold'
                    };
                    alert(`Conditional Formatting: ${type}\n\nRule: ${rules[type]}\n\nHome → Conditional Formatting → Highlight Cells Rules`);
                });
            });

            // Format Painter simulation
            const formatPainterDemo = document.querySelector('.subsection:nth-child(3)');
            if (formatPainterDemo) {
                formatPainterDemo.addEventListener('click', function(e) {
                    if (e.target.classList.contains('fa-brush')) {
                        alert('Format Painter:\n\n1. Select cell with desired format\n2. Click Format Painter icon\n3. Click target cell(s) to apply\n\nDouble-click to apply to multiple cells.');
                    }
                });
            }

            // Name Box demonstration
            const nameBoxImg = document.querySelector('img[alt*="Name Box"]');
            if (nameBoxImg) {
                nameBoxImg.addEventListener('click', function() {
                    alert('Name Box for Named Ranges:\n\n1. Select a cell or range\n2. Click in the Name Box (left of formula bar)\n3. Type a name (no spaces)\n4. Press Enter\n\nNow use =SUM(YourRangeName) in formulas!');
                });
            }

            // Sparkline interaction
            const sparklineBars = document.querySelectorAll('.sparkline-bar');
            sparklineBars.forEach((bar, index) => {
                bar.addEventListener('click', function() {
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
                    const values = [50, 80, 60, 90, 40, 70];
                    alert(`Sparkline Data Point\n\nMonth: ${months[index]}\nValue: ${values[index]}\n\nInsert: Select data → Insert → Sparklines`);
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                '1': 'Format Cells (Ctrl + 1)',
                '+': 'Insert (Ctrl + Shift + +)',
                '-': 'Delete (Ctrl + -)',
                'p': 'Format Painter (Alt, H, F, P)',
                'w': 'Wrap Text (Alt, H, W)'
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
            
            // F3 key simulation for named ranges
            if (e.key === 'F3') {
                e.preventDefault();
                const rangeName = prompt('Paste Name: Enter named range to insert:', 'PriceList');
                if (rangeName) {
                    alert(`Inserting named range: ${rangeName}\n\nIn Excel, this would insert the named range reference in your formula.`);
                }
            }
            
            // F4 key simulation for repeat action
            if (e.key === 'F4') {
                e.preventDefault();
                alert('F4: Repeat Last Action\n\nUse F4 to quickly repeat your last formatting or action. Try formatting a cell, then select another cell and press F4.');
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
            
            /* Interactive hover effects */
            .paste-card:hover {
                transform: translateY(-5px) scale(1.05);
                transition: all 0.3s ease;
            }
            
            .cf-item:hover {
                filter: brightness(1.1);
                transform: scale(1.05);
                transition: all 0.3s ease;
            }
            
            .sparkline-bar:hover {
                filter: brightness(1.2);
                transform: scaleY(1.1);
                transition: all 0.3s ease;
            }
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard navigation hints
            const interactiveElements = document.querySelectorAll('a, button, .paste-card, .cf-item, .sparkline-bar');
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

        // Paste Special simulation
        function simulatePasteSpecial() {
            const options = [
                "Paste Special - Choose Option:",
                "1. Values (V)",
                "2. Formulas (F)",
                "3. Formats (T)",
                "4. Transpose (E)",
                "5. All using source theme (S)",
                "6. Values and number formats (A)",
                "7. Values and source formatting (U)"
            ];
            
            const choice = prompt(options.join('\n'));
            if (choice) {
                const results = {
                    '1': 'Pasted values only',
                    '2': 'Pasted formulas only',
                    '3': 'Pasted formatting only',
                    '4': 'Transposed data (rows↔columns)'
                };
                
                if (results[choice]) {
                    alert(`Result: ${results[choice]}\n\nTry this in Excel with your own data!`);
                }
            }
        }

        // Conditional formatting wizard simulation
        function simulateConditionalFormatting() {
            const steps = [
                "Conditional Formatting Wizard",
                "Step 1: Select rule type:",
                "• Highlight Cells Rules",
                "• Top/Bottom Rules",
                "• Data Bars",
                "• Color Scales",
                "• Icon Sets",
                "\nStep 2: Set parameters",
                "e.g., Greater than: 100",
                "\nStep 3: Choose format",
                "Fill color, font color, etc.",
                "\nStep 4: Apply and preview"
            ];
            alert(steps.join('\n'));
        }

        // Named range creation simulation
        function createNamedRange() {
            const rangeName = prompt('Create Named Range:\n\nEnter name for selected range:', 'SalesData');
            if (rangeName) {
                if (rangeName.includes(' ')) {
                    alert('Error: Names cannot contain spaces. Use underscores instead.');
                    return;
                }
                if (!/^[a-zA-Z_]/.test(rangeName)) {
                    alert('Error: Names must start with a letter or underscore.');
                    return;
                }
                alert(`Named Range Created: ${rangeName}\n\nNow use =SUM(${rangeName}) in your formulas!`);
            }
        }

        // Self-review answers
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Ctrl + 1 opens the Format Cells dialog.",
                    "2. Double-click Format Painter to apply to multiple cells.",
                    "3. Named range rules: No spaces, start with letter/underscore, max 255 chars.",
                    "4. Remove conditional formatting: Home → Conditional Formatting → Clear Rules.",
                    "5. Merging cells with data keeps only the top-left cell's content."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // AutoFill demonstration
        function demonstrateAutoFill() {
            const patterns = [
                "AutoFill Patterns:",
                "1. Basic series: 1, 2, 3...",
                "2. Days: Mon, Tue, Wed...",
                "3. Months: Jan, Feb, Mar...",
                "4. Quarters: Q1, Q2, Q3...",
                "5. Custom: Type two values to establish pattern",
                "\nTry: Type 'January' in A1, drag fill handle down."
            ];
            alert(patterns.join('\n'));
        }

        // Sparkline insertion simulation
        function insertSparkline() {
            const types = [
                "Insert Sparkline:",
                "1. Select data range (6 cells for 6 months)",
                "2. Insert → Sparklines",
                "3. Choose type:",
                "   • Line: Shows trend",
                "   • Column: Compares values",
                "   • Win/Loss: Shows positive/negative",
                "4. Choose location cell",
                "5. Format as needed"
            ];
            alert(types.join('\n'));
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
    $viewer = new ExcelWeek3HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>