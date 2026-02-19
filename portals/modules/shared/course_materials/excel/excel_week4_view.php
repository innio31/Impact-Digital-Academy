<?php
// modules/shared/course_materials/MSExcel/excel_week4_view.php

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
 * Excel Week 4 Handout Viewer Class with PDF Download
 */
class ExcelWeek4HandoutViewer
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
                'tempDir' => sys_get_temp_dir()
            ];
            
            // Try different mPDF class names based on version
            try {
                $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            } catch (Exception $e) {
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            // Set document information
            $mpdf->SetTitle('Week 4: Working with Excel Tables');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Tables, Filtering, Sorting, Structured References');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week4_Tables_' . date('Y-m-d') . '.pdf';
            
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
            error_log('PDF Generation Error: ' . $e->getMessage());
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
                Week 4: Working with Excel Tables
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 4!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll learn how to work with Excel Tables—a powerful feature that makes managing, analyzing, and formatting data easier and more dynamic. Tables provide built-in sorting, filtering, and structured references that are essential for organizing data effectively.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Create and format Excel tables from data ranges</li>
                    <li>Modify tables by adding/removing rows and columns</li>
                    <li>Apply and customize table styles and options</li>
                    <li>Filter and sort table data efficiently</li>
                    <li>Use slicers for interactive filtering</li>
                    <li>Insert and configure total rows with different functions</li>
                    <li>Convert tables back to ranges when needed</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">1. Creating and Formatting Tables</h3>
                
                <p><strong>A. What is an Excel Table?</strong></p>
                <ul>
                    <li>A structured range of data with headers, filter buttons, and banded rows</li>
                    <li>Tables support automatic expansion, calculated columns, and structured references</li>
                    <li>Tables make data management dynamic and formulas update automatically</li>
                </ul>
                
                <p><strong>B. Creating an Excel Table from a Cell Range:</strong></p>
                <ol>
                    <li>Select any cell within your data range</li>
                    <li>Go to <strong>Insert → Table</strong> (or press <strong>Ctrl + T</strong>)</li>
                    <li>Ensure "My table has headers" is checked</li>
                    <li>Click <strong>OK</strong></li>
                </ol>
                
                <p><strong>C. Applying Table Styles:</strong></p>
                <ul>
                    <li>Click inside the table → Table Design tab appears</li>
                    <li>Choose from Table Styles gallery (Light, Medium, Dark)</li>
                    <li>Check/uncheck style options:</li>
                    <ul>
                        <li><strong>Header Row:</strong> Show/hide headers</li>
                        <li><strong>Total Row:</strong> Add a total row at the bottom</li>
                        <li><strong>Banded Rows/Columns:</strong> Alternate shading</li>
                        <li><strong>First/Last Column:</strong> Emphasize with formatting</li>
                    </ul>
                </ul>
                
                <p><strong>D. Converting a Table Back to a Range:</strong></p>
                <ul>
                    <li>Click inside the table → Table Design → Convert to Range</li>
                    <li>Table functionality is removed, but formatting may remain</li>
                    <li>Confirmation dialog: "Do you want to convert the table to a normal range?"</li>
                </ul>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">2. Modifying Tables</h3>
                
                <p><strong>A. Adding or Removing Table Rows and Columns:</strong></p>
                <ul>
                    <li><strong>Add a row:</strong> Type directly below the last row → Table auto-expands</li>
                    <li><strong>Add a column:</strong> Type directly to the right of the last column</li>
                    <li><strong>Insert rows/columns:</strong> Right-click → Insert → Choose option</li>
                    <li><strong>Delete rows/columns:</strong> Select → Right-click → Delete → Table Rows/Columns</li>
                </ul>
                
                <p><strong>B. Configuring Table Style Options:</strong></p>
                <ul>
                    <li>Table Design tab → Table Style Options group:</li>
                    <ul>
                        <li><strong>Header Row:</strong> Toggle on/off</li>
                        <li><strong>Total Row:</strong> Add automatic totals (Sum, Average, etc.)</li>
                        <li><strong>Banded Rows/Columns:</strong> Alternating colors</li>
                        <li><strong>First Column/Last Column:</strong> Bold or special formatting</li>
                        <li><strong>Filter Button:</strong> Show/hide filter dropdowns</li>
                    </ul>
                </ul>
                
                <p><strong>C. Inserting and Configuring Total Rows:</strong></p>
                <ol>
                    <li>Table Design → Total Row (check the box)</li>
                    <li>A total row appears at the bottom of the table</li>
                    <li>Click a cell in the total row → Dropdown arrow appears</li>
                    <li>Choose function: Sum, Average, Count, Max, Min, etc.</li>
                </ol>
                
                <h3 style="color: #107c10; margin-top: 20px; font-size: 14pt;">3. Filtering and Sorting Table Data</h3>
                
                <p><strong>A. Filtering Records:</strong></p>
                <ul>
                    <li>Filter dropdowns appear in header cells</li>
                    <li>Click the arrow → Choose:</li>
                    <ul>
                        <li><strong>Text/Number Filters:</strong> Equals, contains, greater than, etc.</li>
                        <li><strong>Search box:</strong> Type to find specific items</li>
                        <li><strong>Select/deselect items</strong> from list</li>
                    </ul>
                    <li><strong>Clear filter:</strong> Click filter arrow → Clear Filter From [Column Name]</li>
                </ul>
                
                <p><strong>B. Sorting Data by Multiple Columns:</strong></p>
                <ol>
                    <li>Click Sort & Filter in the Data tab or use the dropdown in a header</li>
                    <li>Choose Custom Sort</li>
                    <li>Add levels:</li>
                    <ul>
                        <li><strong>Sort by:</strong> Choose first column and order (A-Z, Z-A)</li>
                        <li><strong>Then by:</strong> Add additional sort criteria</li>
                    </ul>
                    <li>Can sort by values, cell color, font color, or icon</li>
                </ol>
                
                <p><strong>C. Using Slicers for Interactive Filtering (Bonus):</strong></p>
                <ul>
                    <li><strong>Insert → Slicer</strong> (Table Design tab)</li>
                    <li>Choose column(s) to filter by</li>
                    <li>Click buttons in slicer to filter table interactively</li>
                    <li>Slicers provide visual, user-friendly filtering for dashboards</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Create and Manage a Sales Data Table</h3>
                <p><strong>Objective:</strong> Build a dynamic sales table with filtering, sorting, and totals.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li><strong>Enter the following data in a new workbook:</strong>
                        <table style="border-collapse: collapse; margin: 10px 0; font-size: 10pt;">
                            <tr style="background: #107c10; color: white;">
                                <th style="border: 1px solid #ddd; padding: 8px;">Date</th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Region</th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Product</th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Units Sold</th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Price per Unit</th>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;">2024-01-10</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">North</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">Laptop</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">15</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">899.99</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;">2024-01-12</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">South</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">Mouse</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">50</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">29.99</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;">2024-01-15</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">East</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">Keyboard</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">30</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">89.99</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;">2024-01-18</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">West</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">Monitor</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">10</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">249.99</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;">2024-01-20</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">North</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">Headphones</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">40</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">79.99</td>
                            </tr>
                        </table>
                    </li>
                    <li><strong>Create the Table:</strong>
                        <ul>
                            <li>Select the range (including headers)</li>
                            <li>Press Ctrl + T → Click OK</li>
                        </ul>
                    </li>
                    <li><strong>Apply Table Formatting:</strong>
                        <ul>
                            <li>Choose Table Style Medium 4</li>
                            <li>Enable Banded Rows and Header Row</li>
                            <li>Disable Filter Button for the Date column</li>
                        </ul>
                    </li>
                    <li><strong>Add a Total Row:</strong>
                        <ul>
                            <li>Table Design → Total Row</li>
                            <li>In the Units Sold total cell, select Sum</li>
                            <li>In the Price per Unit total cell, select Average</li>
                        </ul>
                    </li>
                    <li><strong>Filter and Sort:</strong>
                        <ul>
                            <li>Filter the Region column to show only North</li>
                            <li>Sort Units Sold from Highest to Lowest</li>
                            <li>Use Custom Sort to sort first by Region, then by Date (Oldest to Newest)</li>
                        </ul>
                    </li>
                    <li><strong>Convert to Range (Optional):</strong>
                        <ul>
                            <li>Practice converting the table back to a range</li>
                            <li>Then re-create it using Ctrl + T</li>
                        </ul>
                    </li>
                    <li><strong>Save as Sales_Table_Managed.xlsx</strong></li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Essential Shortcuts for Week 4</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + T</td>
                            <td style="padding: 6px 8px;">Create a table</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + L</td>
                            <td style="padding: 6px 8px;">Toggle filter buttons on/off</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → T</td>
                            <td style="padding: 6px 8px;">Format as Table (from Home tab)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → J → T</td>
                            <td style="padding: 6px 8px;">Go to Table Design tab</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + *</td>
                            <td style="padding: 6px 8px;">Select entire table</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt → D → F → F</td>
                            <td style="padding: 6px 8px;">Clear all filters</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Excel Table:</strong> A structured range with headers, automatic expansion, and special formatting.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Structured References:</strong> Table-based formulas that use column names instead of cell addresses.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Banded Rows:</strong> Alternating row colors for better readability.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Total Row:</strong> A special row at the bottom of a table for summary calculations.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Slicer:</strong> A visual filter control for tables and pivot tables.</p>
                </div>
                <div>
                    <p><strong>Table Style Options:</strong> Settings that control table appearance elements like headers and totals.</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Employee Directory Table:</strong>
                        <ul>
                            <li>Create a table with columns: EmployeeID, FirstName, LastName, Department, HireDate, Salary</li>
                            <li>Enter at least 8 records</li>
                            <li>Apply a table style, add a total row to average the salary</li>
                            <li>Filter to show only a specific department</li>
                            <li>Sort by HireDate (oldest first)</li>
                        </ul>
                    </li>
                    <li><strong>Table Modification Practice:</strong>
                        <ul>
                            <li>Add a new column: Years of Service (calculated from HireDate)</li>
                            <li>Insert two new employee records</li>
                            <li>Remove the filter buttons from all columns except Department</li>
                            <li>Add a slicer for Department and take a screenshot</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What is the shortcut to create a table?</li>
                            <li>How do you add a total row to a table?</li>
                            <li>Can you sort by cell color in a table?</li>
                            <li>What is a slicer and when would you use it?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Create and format tables</li>
                    <li>Apply table styles</li>
                    <li>Modify table options</li>
                    <li>Insert total rows</li>
                    <li>Filter table data</li>
                    <li>Sort by multiple columns</li>
                    <li>Use slicers</li>
                    <li>Convert tables to ranges</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Use tables for any structured data</strong>—they make formulas dynamic and ranges auto-expand.</li>
                    <li><strong>Turn off filter buttons</strong> for columns that don't need filtering to clean up the view.</li>
                    <li><strong>Total rows are flexible</strong>—you can choose different functions per column (Sum, Count, Average, etc.).</li>
                    <li><strong>Slicers provide a visual, user-friendly way to filter</strong>—great for dashboards and reports.</li>
                    <li><strong>Table names</strong> can be changed in Table Design → Table Name for easier reference in formulas.</li>
                    <li><strong>Use structured references</strong> in formulas for readability (e.g., =SUM(Table1[Sales]) instead of =SUM(A2:A100)).</li>
                    <li><strong>Tables maintain formatting</strong> when adding new rows or columns automatically.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 5, we'll cover:</p>
                <ul>
                    <li>Formulas and Functions fundamentals</li>
                    <li>Relative, Absolute, and Mixed References</li>
                    <li>Basic Functions: SUM, AVERAGE, MIN, MAX, COUNT, COUNTA, COUNTBLANK</li>
                    <li>The IF function for conditional logic</li>
                    <li>Formula auditing and error checking</li>
                    <li>Named ranges in formulas</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring a dataset you'd like to analyze with formulas and functions.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Tables Guide</li>
                    <li>Structured References Tutorial</li>
                    <li>Slicers and Timelines Overview</li>
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
                Week 4 Handout: Working with Excel Tables
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
            Week 4: Working with Excel Tables | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 4: Working with Excel Tables - Impact Digital Academy</title>
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

        /* Table Style Demo */
        .style-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .style-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .style-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .style-icon {
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

        /* Table Feature Cards */
        .feature-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .feature-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .feature-card.structure {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .feature-card.autofill {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .feature-card.styles {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .feature-card.filter {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .feature-card.structure .feature-icon {
            color: #2196f3;
        }

        .feature-card.autofill .feature-icon {
            color: #4caf50;
        }

        .feature-card.styles .feature-icon {
            color: #9c27b0;
        }

        .feature-card.filter .feature-icon {
            color: #ff9800;
        }

        /* Table Example */
        .table-example {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 2px solid #107c10;
        }

        .table-example th {
            background: #107c10;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border-right: 1px solid #0e5c0e;
        }

        .table-example td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
            border-right: 1px solid #eee;
        }

        .table-example tr:nth-child(even) {
            background: #f0f9f0;
        }

        .table-example tr.total-row {
            background: #e8f4e8;
            font-weight: bold;
            border-top: 2px solid #107c10;
        }

        .table-example .filter-icon::after {
            content: " ▼";
            font-size: 0.8em;
            opacity: 0.7;
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

            .style-demo,
            .feature-cards {
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
                <strong>Access Granted:</strong> Excel Week 4 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week3_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 3
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week5_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 5
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 4 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Working with Excel Tables</div>
            <div class="week-tag">Week 4 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 4!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll learn how to work with Excel Tables—a powerful feature that makes managing, analyzing, and formatting data easier and more dynamic. Tables provide built-in sorting, filtering, and structured references that are essential for organizing data effectively.
                </p>

                <div class="image-container">
                    <img src="images/excel_tables.png"
                        alt="Microsoft Excel Tables"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RXhjZWwgVGFibGVzIE92ZXJ2aWV3PC90ZXh0Pjwvc3ZnPg='">
                    <div class="image-caption">Excel Tables: Powerful Data Management Feature</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Create and format Excel tables from data ranges</li>
                    <li>Modify tables by adding/removing rows and columns</li>
                    <li>Apply and customize table styles and options</li>
                    <li>Filter and sort table data efficiently</li>
                    <li>Use slicers for interactive filtering</li>
                    <li>Insert and configure total rows with different functions</li>
                    <li>Convert tables back to ranges when needed</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Create and format tables</li>
                        <li>Apply table styles</li>
                        <li>Modify table options</li>
                        <li>Insert total rows</li>
                    </ul>
                    <ul>
                        <li>Filter table data</li>
                        <li>Sort by multiple columns</li>
                        <li>Use slicers</li>
                        <li>Convert tables to ranges</li>
                    </ul>
                </div>
            </div>

            <!-- Table Benefits -->
            <div class="feature-cards">
                <div class="feature-card structure">
                    <div class="feature-icon">
                        <i class="fas fa-th-list"></i>
                    </div>
                    <h4>Structured Data</h4>
                    <p>Organized headers, automatic expansion, and consistent formatting</p>
                </div>
                <div class="feature-card autofill">
                    <div class="feature-icon">
                        <i class="fas fa-magic"></i>
                    </div>
                    <h4>Auto Formulas</h4>
                    <p>Formulas automatically copy down columns in calculated columns</p>
                </div>
                <div class="feature-card styles">
                    <div class="feature-icon">
                        <i class="fas fa-palette"></i>
                    </div>
                    <h4>Built-in Styles</h4>
                    <p>Professional formatting with one click using table styles</p>
                </div>
                <div class="feature-card filter">
                    <div class="feature-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <h4>Easy Filtering</h4>
                    <p>Filter buttons automatically added to headers for quick data analysis</p>
                </div>
            </div>

            <!-- Section 1: Creating Tables -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-plus-circle"></i> 1. Creating and Formatting Tables
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-table"></i> A. What is an Excel Table?</h3>
                    <ul>
                        <li>A <strong>structured range</strong> of data with headers, filter buttons, and banded rows</li>
                        <li>Tables support <strong>automatic expansion</strong> - add data and the table grows automatically</li>
                        <li><strong>Calculated columns</strong> - formulas copy down automatically</li>
                        <li><strong>Structured references</strong> - use column names instead of cell addresses in formulas</li>
                        <li>Tables make data management dynamic and formulas update automatically</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-mouse-pointer"></i> B. Creating an Excel Table from a Cell Range</h3>
                    <ol style="padding-left: 30px;">
                        <li>Select any cell within your data range</li>
                        <li>Go to <strong>Insert → Table</strong> (or press <strong class="shortcut-key">Ctrl + T</strong>)</li>
                        <li>Ensure "My table has headers" is checked</li>
                        <li>Click <strong>OK</strong></li>
                    </ol>
                    
                    <div class="image-container">
                        <img src="images/create_table.png"
                            alt="Create Table Dialog"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5DcmVhdGUgVGFibGUgRGlhbG9nPC90ZXh0Pjwvc3ZnPg='">
                        <div class="image-caption">Create Table dialog with header option</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-paint-brush"></i> C. Applying Table Styles</h3>
                    <ul>
                        <li>Click inside the table → <strong>Table Design tab</strong> appears</li>
                        <li>Choose from <strong>Table Styles gallery</strong> (Light, Medium, Dark)</li>
                        <li>Check/uncheck style options in Table Style Options group:</li>
                        <ul>
                            <li><strong>Header Row:</strong> Show/hide headers</li>
                            <li><strong>Total Row:</strong> Add a total row at the bottom</li>
                            <li><strong>Banded Rows/Columns:</strong> Alternate shading for readability</li>
                            <li><strong>First/Last Column:</strong> Emphasize with special formatting</li>
                            <li><strong>Filter Button:</strong> Show/hide filter dropdowns</li>
                        </ul>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exchange-alt"></i> D. Converting a Table Back to a Range</h3>
                    <ul>
                        <li>Click inside the table → Table Design → <strong>Convert to Range</strong></li>
                        <li>Table functionality is removed, but formatting may remain</li>
                        <li>Confirmation dialog: "Do you want to convert the table to a normal range?"</li>
                        <li>Useful when you need plain data without table features</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Modifying Tables -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-edit"></i> 2. Modifying Tables
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-square"></i> A. Adding or Removing Table Rows and Columns</h3>
                    <ul>
                        <li><strong>Add a row:</strong> Type directly below the last row → Table auto-expands</li>
                        <li><strong>Add a column:</strong> Type directly to the right of the last column</li>
                        <li><strong>Insert rows/columns:</strong> Right-click → Insert → Choose option</li>
                        <li><strong>Delete rows/columns:</strong> Select → Right-click → Delete → Table Rows/Columns</li>
                        <li><strong>Resize table:</strong> Drag the resize handle at bottom-right corner</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> B. Configuring Table Style Options</h3>
                    <ul>
                        <li>Table Design tab → Table Style Options group:</li>
                        <ul>
                            <li><strong>Header Row:</strong> Toggle on/off (useful for printing)</li>
                            <li><strong>Total Row:</strong> Add automatic totals (Sum, Average, etc.)</li>
                            <li><strong>Banded Rows/Columns:</strong> Alternating colors for better readability</li>
                            <li><strong>First Column/Last Column:</strong> Bold or special formatting for emphasis</li>
                            <li><strong>Filter Button:</strong> Show/hide filter dropdowns in headers</li>
                        </ul>
                        <li>Changes apply instantly to the table appearance</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-calculator"></i> C. Inserting and Configuring Total Rows</h3>
                    <ol style="padding-left: 30px;">
                        <li>Table Design → <strong>Total Row</strong> (check the box)</li>
                        <li>A total row appears at the bottom of the table</li>
                        <li>Click a cell in the total row → Dropdown arrow appears</li>
                        <li>Choose function: <strong>Sum, Average, Count, Max, Min, StdDev, Var, More Functions...</strong></li>
                        <li>Different columns can have different functions</li>
                    </ol>
                    
                    <div class="table-example">
                        <tr>
                            <th class="filter-icon">Product</th>
                            <th class="filter-icon">Region</th>
                            <th class="filter-icon">Q1 Sales</th>
                            <th class="filter-icon">Q2 Sales</th>
                            <th class="filter-icon">Total</th>
                        </tr>
                        <tr>
                            <td>Laptop</td>
                            <td>North</td>
                            <td>$15,000</td>
                            <td>$18,500</td>
                            <td>$33,500</td>
                        </tr>
                        <tr>
                            <td>Monitor</td>
                            <td>South</td>
                            <td>$8,200</td>
                            <td>$9,800</td>
                            <td>$18,000</td>
                        </tr>
                        <tr>
                            <td>Keyboard</td>
                            <td>East</td>
                            <td>$3,500</td>
                            <td>$4,200</td>
                            <td>$7,700</td>
                        </tr>
                        <tr>
                            <td>Mouse</td>
                            <td>West</td>
                            <td>$2,800</td>
                            <td>$3,100</td>
                            <td>$5,900</td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td></td>
                            <td><strong>$29,500</strong></td>
                            <td><strong>$35,600</strong></td>
                            <td><strong>$65,100</strong></td>
                        </tr>
                    </div>
                </div>
            </div>

            <!-- Section 3: Filtering and Sorting -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-filter"></i> 3. Filtering and Sorting Table Data
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-search"></i> A. Filtering Records</h3>
                    <ul>
                        <li>Filter dropdowns appear automatically in header cells</li>
                        <li>Click the arrow → Choose filtering options:</li>
                        <ul>
                            <li><strong>Text/Number Filters:</strong> Equals, contains, greater than, between, etc.</li>
                            <li><strong>Search box:</strong> Type to find specific items quickly</li>
                            <li><strong>Select/deselect items</strong> from the list (checkboxes)</li>
                            <li><strong>Color Filters:</strong> Filter by cell or font color</li>
                        </ul>
                        <li><strong>Clear filter:</strong> Click filter arrow → Clear Filter From [Column Name]</li>
                        <li><strong>Clear all filters:</strong> Data tab → Clear (or Alt → D → F → F)</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sort"></i> B. Sorting Data by Multiple Columns</h3>
                    <ol style="padding-left: 30px;">
                        <li>Click Sort & Filter in the Data tab or use the dropdown in a header</li>
                        <li>Choose <strong>Custom Sort</strong></li>
                        <li>Add levels for multiple criteria:</li>
                        <ul>
                            <li><strong>Sort by:</strong> Choose first column and order (A-Z, Z-A, Custom List)</li>
                            <li><strong>Then by:</strong> Add additional sort criteria for tie-breaking</li>
                        </ul>
                        <li>Can sort by: Values, Cell Color, Font Color, or Cell Icon</li>
                        <li>Example: Sort by Region (A-Z), then by Sales (Largest to Smallest)</li>
                    </ol>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> C. Using Slicers for Interactive Filtering (Bonus)</h3>
                    <ul>
                        <li><strong>Insert → Slicer</strong> (Table Design tab or Insert tab)</li>
                        <li>Choose column(s) to create slicers for</li>
                        <li>Click buttons in slicer to filter table interactively</li>
                        <li>Slicers provide <strong>visual, user-friendly filtering</strong> for dashboards and reports</li>
                        <li>Multiple slicers can be connected to the same table</li>
                        <li>Format slicers using Slicer Tools → Options tab</li>
                    </ul>
                    
                    <div class="image-container">
                        <img src="images/excel_slicers.png"
                            alt="Excel Slicers"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBTbGljZXJzPC90ZXh0Pjwvc3ZnPg='">
                        <div class="image-caption">Slicers provide visual filtering controls</div>
                    </div>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 4. Essential Shortcuts for Week 4
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
                            <td><span class="shortcut-key">Ctrl + T</span></td>
                            <td>Create a table from selected data</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + L</span></td>
                            <td>Toggle filter buttons on/off</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → T</span></td>
                            <td>Format as Table (from Home tab)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → J → T</span></td>
                            <td>Go to Table Design tab</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + *</span></td>
                            <td>Select entire table</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → D → F → F</span></td>
                            <td>Clear all filters</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Tab</span></td>
                            <td>Move to next cell in table (creates new row at end)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Space</span></td>
                            <td>Select entire table column</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Space</span></td>
                            <td>Select entire table row</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + -</span></td>
                            <td>Delete table rows/columns</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + +</span></td>
                            <td>Insert table rows/columns</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 5. Hands-On Exercise: Create and Manage a Sales Data Table
                </div>
                <p><strong>Objective:</strong> Build a dynamic sales table with filtering, sorting, and totals.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Enter the following data in a new workbook:</strong>
                            <table class="demo-table" style="margin: 15px 0;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Region</th>
                                        <th>Product</th>
                                        <th>Units Sold</th>
                                        <th>Price per Unit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>2024-01-10</td>
                                        <td>North</td>
                                        <td>Laptop</td>
                                        <td>15</td>
                                        <td>899.99</td>
                                    </tr>
                                    <tr>
                                        <td>2024-01-12</td>
                                        <td>South</td>
                                        <td>Mouse</td>
                                        <td>50</td>
                                        <td>29.99</td>
                                    </tr>
                                    <tr>
                                        <td>2024-01-15</td>
                                        <td>East</td>
                                        <td>Keyboard</td>
                                        <td>30</td>
                                        <td>89.99</td>
                                    </tr>
                                    <tr>
                                        <td>2024-01-18</td>
                                        <td>West</td>
                                        <td>Monitor</td>
                                        <td>10</td>
                                        <td>249.99</td>
                                    </tr>
                                    <tr>
                                        <td>2024-01-20</td>
                                        <td>North</td>
                                        <td>Headphones</td>
                                        <td>40</td>
                                        <td>79.99</td>
                                    </tr>
                                </tbody>
                            </table>
                        </li>
                        <li><strong>Create the Table:</strong>
                            <ul>
                                <li>Select the range (including headers)</li>
                                <li>Press <strong>Ctrl + T</strong> → Click OK</li>
                            </ul>
                        </li>
                        <li><strong>Apply Table Formatting:</strong>
                            <ul>
                                <li>Choose <strong>Table Style Medium 4</strong></li>
                                <li>Enable <strong>Banded Rows</strong> and <strong>Header Row</strong></li>
                                <li>Disable <strong>Filter Button</strong> for the Date column</li>
                            </ul>
                        </li>
                        <li><strong>Add a Total Row:</strong>
                            <ul>
                                <li>Table Design → <strong>Total Row</strong></li>
                                <li>In the Units Sold total cell, select <strong>Sum</strong></li>
                                <li>In the Price per Unit total cell, select <strong>Average</strong></li>
                            </ul>
                        </li>
                        <li><strong>Filter and Sort:</strong>
                            <ul>
                                <li>Filter the Region column to show only <strong>North</strong></li>
                                <li>Sort Units Sold from <strong>Highest to Lowest</strong></li>
                                <li>Use <strong>Custom Sort</strong> to sort first by Region, then by Date (Oldest to Newest)</li>
                            </ul>
                        </li>
                        <li><strong>Convert to Range (Optional):</strong>
                            <ul>
                                <li>Practice converting the table back to a range</li>
                                <li>Then re-create it using Ctrl + T</li>
                            </ul>
                        </li>
                        <li><strong>Save as Sales_Table_Managed.xlsx</strong></li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Excel Table Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBUYWJsZSBFeGVyY2lzZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Create Your First Professional Excel Table</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Sales Table Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 6. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Excel Table</strong>
                    <p>A structured range with headers, automatic expansion, and special formatting that makes data management easier.</p>
                </div>

                <div class="term">
                    <strong>Structured References</strong>
                    <p>Table-based formulas that use column names instead of cell addresses (e.g., =SUM(Table1[Sales])).</p>
                </div>

                <div class="term">
                    <strong>Banded Rows</strong>
                    <p>Alternating row colors in a table for better readability and visual appeal.</p>
                </div>

                <div class="term">
                    <strong>Total Row</strong>
                    <p>A special row at the bottom of a table that can show summary calculations like Sum, Average, Count, etc.</p>
                </div>

                <div class="term">
                    <strong>Slicer</strong>
                    <p>A visual filter control for tables and pivot tables that provides buttons for easy filtering.</p>
                </div>

                <div class="term">
                    <strong>Table Style Options</strong>
                    <p>Settings that control table appearance elements like headers, totals, banding, and filter buttons.</p>
                </div>

                <div class="term">
                    <strong>Calculated Column</strong>
                    <p>A column in a table where formulas automatically fill down when you enter them in one cell.</p>
                </div>

                <div class="term">
                    <strong>Resize Handle</strong>
                    <p>The small icon at the bottom-right corner of a table used to expand or contract the table range.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 7. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete these exercises:</h4>
                    <ol>
                        <li><strong>Employee Directory Table:</strong>
                            <ul>
                                <li>Create a table with columns: EmployeeID, FirstName, LastName, Department, HireDate, Salary</li>
                                <li>Enter at least 8 records with realistic data</li>
                                <li>Apply a table style, add a total row to average the salary</li>
                                <li>Filter to show only a specific department (e.g., "Marketing")</li>
                                <li>Sort by HireDate (oldest first)</li>
                            </ul>
                        </li>
                        <li><strong>Table Modification Practice:</strong>
                            <ul>
                                <li>Add a new column: <strong>Years of Service</strong> (calculated from HireDate using formula)</li>
                                <li>Insert two new employee records at different positions in the table</li>
                                <li>Remove the filter buttons from all columns except Department</li>
                                <li>Add a slicer for Department and take a screenshot of your table with slicer</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>What is the shortcut to create a table?</li>
                                <li>How do you add a total row to a table?</li>
                                <li>Can you sort by cell color in a table?</li>
                                <li>What is a slicer and when would you use it?</li>
                                <li>How do structured references differ from regular cell references?</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Complete the practice exercises and submit your <strong>Employee_Directory.xlsx</strong> and <strong>screenshot.png</strong> files via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Use tables for any structured data</strong>—they make formulas dynamic and ranges auto-expand.</li>
                    <li><strong>Turn off filter buttons</strong> for columns that don't need filtering to clean up the view.</li>
                    <li><strong>Total rows are flexible</strong>—you can choose different functions per column (Sum, Count, Average, etc.).</li>
                    <li><strong>Slicers provide a visual, user-friendly way to filter</strong>—great for dashboards and reports.</li>
                    <li><strong>Table names</strong> can be changed in Table Design → Table Name for easier reference in formulas.</li>
                    <li><strong>Use structured references</strong> in formulas for readability (e.g., =SUM(Table1[Sales]) instead of =SUM(A2:A100)).</li>
                    <li><strong>Tables maintain formatting</strong> when adding new rows or columns automatically.</li>
                    <li><strong>Convert to range</strong> when you need to perform operations that don't work well with tables.</li>
                    <li><strong>Use banded rows</strong> for large tables to make them easier to read across rows.</li>
                    <li><strong>Table headers stay visible</strong> when scrolling if you freeze the top row.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/overview-of-excel-tables-7ab0bb7d-3a9e-4b56-a3c9-6c94334e492c" target="_blank">Microsoft Excel Tables Overview</a></li>
                    <li><a href="https://support.microsoft.com/office/using-structured-references-with-excel-tables-f5ed2452-2337-4f71-bed3-c8ae6d2b276e" target="_blank">Structured References Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/use-slicers-to-filter-data-249f966b-a9d5-4b0f-b31a-12651785d29d" target="_blank">Slicers and Timelines Overview</a></li>
                    <li><a href="https://exceljet.net/excel-tables" target="_blank">Excel Tables Comprehensive Guide</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Interactive Table Simulator</strong> for hands-on practice</li>
                    <li><strong>Week 4 Quiz</strong> to test your understanding (available in portal)</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p><strong>Week 5: Formulas and Functions Fundamentals</strong></p>
                <p>In Week 5, we'll build on your table knowledge and learn to:</p>
                <ul>
                    <li>Formulas and Functions fundamentals and syntax</li>
                    <li>Relative, Absolute, and Mixed References ($A$1, A$1, $A1)</li>
                    <li>Basic Functions: SUM, AVERAGE, MIN, MAX, COUNT, COUNTA, COUNTBLANK</li>
                    <li>The IF function for conditional logic and decision-making</li>
                    <li>Formula auditing and error checking tools</li>
                    <li>Named ranges and their use in formulas</li>
                    <li>Common formula errors and how to fix them</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring a dataset you'd like to analyze with formulas and functions (sales data, expenses, grades, etc.).</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week4.php">Week 4 Discussion</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week4_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 4 Quiz
                </a>-->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 4 Handout</p>
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
            
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Simulate template download
        function downloadTemplate() {
            alert('Sales table template would download. This is a demo.');
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
                console.log('Excel Week 4 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
            }
        });

        // Interactive feature cards
        document.addEventListener('DOMContentLoaded', function() {
            const featureCards = document.querySelectorAll('.feature-card');
            featureCards.forEach(card => {
                card.addEventListener('click', function() {
                    const feature = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    const details = {
                        'Structured Data': 'Tables keep data organized with consistent headers and automatic formatting.',
                        'Auto Formulas': 'When you enter a formula in one cell of a column, it automatically fills down.',
                        'Built-in Styles': 'Professional table styles with one click, customizable with banding options.',
                        'Easy Filtering': 'Filter dropdowns in headers make data analysis quick and intuitive.'
                    };
                    alert(`${feature}\n\n${description}\n\n${details[feature]}`);
                });
            });

            // Table example interaction
            const tableRows = document.querySelectorAll('.table-example tr:not(.total-row)');
            tableRows.forEach(row => {
                row.addEventListener('click', function() {
                    const cells = this.querySelectorAll('td');
                    if (cells.length > 0) {
                        const product = cells[0]?.textContent || '';
                        const region = cells[1]?.textContent || '';
                        alert(`Selected: ${product} - ${region}\n\nClick a filter icon (▼) in the header to filter this data.`);
                    }
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                't': 'Create Table (Ctrl + T)',
                'l': 'Toggle Filters (Ctrl + Shift + L)'
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
            
            // Ctrl+Shift+* simulation
            if (e.ctrlKey && e.shiftKey && e.key === '*') {
                e.preventDefault();
                alert('Ctrl + Shift + *: Select entire table\n\nUse this shortcut to quickly select all data in your current table.');
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
            const interactiveElements = document.querySelectorAll('a, button, .feature-card, .style-item');
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

        // Table creation simulation
        function simulateTableCreation() {
            const steps = [
                "Step 1: Select your data range",
                "Step 2: Press Ctrl + T",
                "Step 3: Confirm 'My table has headers' is checked",
                "Step 4: Click OK",
                "\nYour table is now created with:",
                "• Filter buttons in headers",
                "• Table Design tab activated",
                "• Professional formatting applied",
                "• Auto-expand capability enabled"
            ];
            alert("Table Creation Simulation:\n\n" + steps.join("\n"));
        }

        // Slicer demonstration
        function demonstrateSlicer() {
            const slicerInfo = [
                "Excel Slicers: Visual Filter Controls",
                "\nBenefits:",
                "• User-friendly button interface",
                "• Shows current filter state clearly",
                "• Can connect to multiple tables",
                "• Great for dashboards and reports",
                "\nTo Create:",
                "1. Click inside your table",
                "2. Go to Table Design tab",
                "3. Click Insert Slicer",
                "4. Choose columns to create slicers for",
                "5. Click slicer buttons to filter"
            ];
            alert(slicerInfo.join("\n"));
        }

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Ctrl + T creates a table from selected data.",
                    "2. Table Design → Total Row (check the box) adds a total row.",
                    "3. Yes, you can sort by cell color, font color, or icon in tables.",
                    "4. A slicer is a visual filter control with buttons for easy filtering. Use it for dashboards and user-friendly reports.",
                    "5. Structured references use column names (Table1[Sales]) while regular references use cell addresses (A2:A100)."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
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
    $viewer = new ExcelWeek4HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>