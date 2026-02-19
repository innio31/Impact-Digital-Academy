<?php
// modules/shared/course_materials/MSExcel/excel_week7_view.php

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
 * Excel Week 7 Handout Viewer Class with PDF Download
 */
class ExcelWeek7HandoutViewer
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
            $mpdf->SetTitle('Week 7: Creating, Modifying & Formatting Charts');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-200 Excel Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Excel, MO-200, Charts, Sparklines, Data Visualization, Dashboard');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Excel_Week7_Charts_Sparklines_' . date('Y-m-d') . '.pdf';
            
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
                Week 7: Creating, Modifying & Formatting Charts
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">Welcome to Week 7!</h2>
                <p style="margin-bottom: 15px;">
                    This week, you'll learn how to visually represent data using charts and sparklines. Charts help turn complex data into understandable visuals, making trends, comparisons, and patterns clear at a glance. You'll also learn how to make charts accessible and professional-looking for reports and presentations.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f9f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Create different types of charts from data ranges</li>
                    <li>Modify chart elements and data series</li>
                    <li>Apply professional formatting to charts</li>
                    <li>Add alternative text for accessibility</li>
                    <li>Create and format sparklines for trend visualization</li>
                    <li>Build multi-chart dashboards</li>
                </ul>
            </div>
            
            <!-- Section 1: Introduction to Charts -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">1. Introduction to Charts in Excel</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">A. What is a Chart?</h3>
                <ul>
                    <li>A graphical representation of data</li>
                    <li>Makes trends, comparisons, and patterns easier to see</li>
                    <li>Common chart types: Column, Bar, Line, Pie, Scatter, Area</li>
                    <li>Each chart type serves different purposes</li>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">B. Why Use Charts?</h3>
                <ul>
                    <li>Visualize data trends over time</li>
                    <li>Compare values across categories</li>
                    <li>Highlight key insights for presentations and reports</li>
                    <li>Make data more engaging and understandable</li>
                    <li>Identify patterns and outliers quickly</li>
                </ul>
            </div>
            
            <!-- Section 2: Creating Charts -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">2. Creating Charts</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">A. Creating a Chart from Data</h3>
                <ol>
                    <li>Select the data range (including headers)</li>
                    <li>Go to <strong>Insert → Charts</strong> group</li>
                    <li>Choose a chart type (e.g., Clustered Column, Line, Pie)</li>
                    <li>The chart will be inserted into the worksheet</li>
                    <li>Use <strong>Recommended Charts</strong> for Excel's suggestions</li>
                </ol>
                
                <h3 style="color: #107c10; font-size: 14pt;">B. Creating a Chart Sheet</h3>
                <ul>
                    <li>A chart sheet is a separate sheet that contains only a chart</li>
                    <li>To create:</li>
                    <ol>
                        <li>Create a chart on a worksheet</li>
                        <li>Right-click the chart → <strong>Move Chart</strong></li>
                        <li>Select <strong>New Sheet</strong> and name it</li>
                        <li>Click OK</li>
                    </ol>
                </ul>
            </div>
            
            <!-- Section 3: Modifying Charts -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">3. Modifying Charts</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">A. Adding Data Series to Charts</h3>
                <ul>
                    <li>A data series is a row or column of data plotted in a chart</li>
                    <li>To add a new series:</li>
                    <ol>
                        <li>Click the chart</li>
                        <li><strong>Chart Design → Select Data</strong></li>
                        <li>Click <strong>Add</strong> under Legend Entries (Series)</li>
                        <li>Select the new data range</li>
                        <li>Click OK</li>
                    </ol>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">B. Switching Between Rows and Columns in Source Data</h3>
                <ul>
                    <li>Changes whether rows or columns are treated as data series</li>
                    <li><strong>Chart Design → Switch Row/Column</strong></li>
                    <li>Useful when you want to change the orientation of your data</li>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">C. Adding and Modifying Chart Elements</h3>
                <ul>
                    <li><strong>Chart Elements button</strong> (+ next to chart) or <strong>Chart Design → Add Chart Element</strong></li>
                    <li>Common elements:</li>
                    <ul>
                        <li><strong>Titles</strong> (Chart Title, Axis Titles)</li>
                        <li><strong>Data Labels</strong> (show values on chart)</li>
                        <li><strong>Legend</strong> (explains colors/series)</li>
                        <li><strong>Gridlines</strong> (major/minor)</li>
                        <li><strong>Trendline</strong> (shows trend over time)</li>
                        <li><strong>Error Bars</strong> (shows variability)</li>
                        <li><strong>Data Table</strong> (shows data below chart)</li>
                        <li><strong>Axes</strong> (format axis scale and labels)</li>
                    </ul>
                </ul>
            </div>
            
            <!-- Section 4: Formatting Charts -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">4. Formatting Charts</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">A. Applying Chart Layouts</h3>
                <ul>
                    <li>Predefined combinations of chart elements</li>
                    <li><strong>Chart Design → Quick Layout</strong></li>
                    <li>Choose from various layouts with different element combinations</li>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">B. Applying Chart Styles</h3>
                <ul>
                    <li>Predefined color and effect schemes</li>
                    <li><strong>Chart Design → Chart Styles</strong> gallery</li>
                    <li>Styles include color variations and effects</li>
                    <li>Use <strong>Change Colors</strong> for different color palettes</li>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">C. Manual Formatting Options</h3>
                <ul>
                    <li>Click any chart element → <strong>Format</strong> tab appears</li>
                    <li>Change:</li>
                    <ul>
                        <li><strong>Fill & Line</strong> (colors, borders, gradients)</li>
                        <li><strong>Effects</strong> (shadow, glow, 3D, soft edges)</li>
                        <li><strong>Size & Properties</strong> (resize, position)</li>
                        <li><strong>Text Options</strong> (font, color, effects)</li>
                        <li><strong>Series Options</strong> (gap width, angle, explosion)</li>
                    </ul>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">D. Adding Alternative Text to Charts for Accessibility</h3>
                <ul>
                    <li>Alt Text helps screen readers describe the chart</li>
                    <li>To add:</li>
                    <ol>
                        <li>Right-click the chart → <strong>Edit Alt Text</strong></li>
                        <li>Enter a description in the Alt Text pane</li>
                        <li>Keep it concise and descriptive</li>
                    </ol>
                    <li>Example: "Column chart showing monthly sales from January to June, with highest sales in June"</li>
                    <li>Avoid generic descriptions like "chart of data"</li>
                </ul>
            </div>
            
            <!-- Section 5: Sparklines -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #0e5c0e; font-size: 16pt;">5. Sparklines: Mini-Charts in a Cell</h2>
                
                <h3 style="color: #107c10; font-size: 14pt;">A. What are Sparklines?</h3>
                <ul>
                    <li>Tiny charts that fit in a single cell</li>
                    <li>Show trends in a small space</li>
                    <li>Types: Line, Column, Win/Loss</li>
                    <li>Perfect for dashboards and summary reports</li>
                </ul>
                
                <h3 style="color: #107c10; font-size: 14pt;">B. Inserting Sparklines</h3>
                <ol>
                    <li>Select the data range for the sparkline</li>
                    <li><strong>Insert → Sparklines</strong> group</li>
                    <li>Choose type (Line, Column, or Win/Loss)</li>
                    <li>Select <strong>Location Range</strong> (where sparklines will appear)</li>
                    <li>Click OK</li>
                </ol>
                
                <h3 style="color: #107c10; font-size: 14pt;">C. Formatting Sparklines</h3>
                <ul>
                    <li>Click a sparkline → <strong>Sparkline Design</strong> tab appears</li>
                    <li>Change:</li>
                    <ul>
                        <li><strong>Style</strong> (colors, line weight)</li>
                        <li><strong>Show</strong> (high/low points, markers, first/last points)</li>
                        <li><strong>Axis</strong> settings (minimum/maximum)</li>
                        <li><strong>Type</strong> (switch between line, column, win/loss)</li>
                    </ul>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f4e8; padding: 15px; border-left: 5px solid #107c10; margin-bottom: 25px;">
                <h3 style="color: #107c10; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Create a Sales Dashboard with Charts</h3>
                <p><strong>Objective:</strong> Build a multi-chart dashboard to visualize sales performance.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Enter the following data in a new workbook:</li>
                </ol>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; border: 1px solid #ddd;">Month</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Product A</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Product B</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Product C</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">January</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">15000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">12000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">9000</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">February</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">18000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">14000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">11000</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">March</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">22000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">16000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">13000</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">April</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">19000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">17000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">14000</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">May</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">21000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">15000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">12000</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">June</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">24000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">18000</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">15000</td>
                        </tr>
                    </tbody>
                </table>
                
                <ol start="2">
                    <li><strong>Create a Clustered Column Chart:</strong></li>
                    <ul>
                        <li>Select A1:D7</li>
                        <li>Insert → Clustered Column</li>
                        <li>Add Chart Title: "Monthly Sales by Product"</li>
                        <li>Add Axis Titles: "Month" (horizontal), "Sales ($)" (vertical)</li>
                    </ul>
                    
                    <li><strong>Create a Line Chart for Trend Analysis:</strong></li>
                    <ul>
                        <li>Select A1:A7 and B1:B7 (Month and Product A)</li>
                        <li>Insert → Line Chart</li>
                        <li>Move chart to a Chart Sheet named "Product A Trend"</li>
                    </ul>
                    
                    <li><strong>Add Sparklines for At-a-Glance Trends:</strong></li>
                    <ul>
                        <li>In E2, insert a Line Sparkline for B2:D2 (first month)</li>
                        <li>Fill down to E7 for each month</li>
                        <li>Format sparklines to show High Point and Low Point</li>
                    </ul>
                    
                    <li><strong>Format and Finalize:</strong></li>
                    <ul>
                        <li>Apply Chart Style 3 to the column chart</li>
                        <li>Add Data Labels to the column chart</li>
                        <li>Add Alt Text to each chart (describe trend and purpose)</li>
                        <li>Create a Pie Chart for June sales (A7:D7) and add percentages as data labels</li>
                    </ul>
                    
                    <li><strong>Save as Sales_Dashboard.xlsx</strong></li>
                </ol>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Expense Report Visualizer:</strong>
                        <ul>
                            <li>Create a dataset of monthly expenses (Rent, Utilities, Food, Travel)</li>
                            <li>Insert a Bar Chart comparing average expenses per category</li>
                            <li>Add a Line Chart showing monthly trends for one category</li>
                            <li>Insert Column Sparklines next to each category to show monthly variance</li>
                            <li>Add Axis Titles, Legend, and Data Labels</li>
                        </ul>
                    </li>
                    <li><strong>Accessible Chart Practice:</strong>
                        <ul>
                            <li>Create two charts from any dataset</li>
                            <li>Add descriptive Alt Text to each</li>
                            <li>Apply different chart styles and layouts</li>
                            <li>Take screenshots before/after formatting</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>How do you move a chart to its own sheet?</li>
                            <li>What is the purpose of switching rows/columns in a chart?</li>
                            <li>What are the three types of sparklines?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed exercises via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Essential Shortcuts for Week 7</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #107c10; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F11</td>
                            <td style="padding: 6px 8px;">Create a chart on a new chart sheet</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F1</td>
                            <td style="padding: 6px 8px;">Create an embedded chart</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + F3</td>
                            <td style="padding: 6px 8px;">Create names from selection (for chart data)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + JC + A</td>
                            <td style="padding: 6px 8px;">Add Chart Element (after chart is selected)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + JC + S</td>
                            <td style="padding: 6px 8px;">Switch Row/Column</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + 1</td>
                            <td style="padding: 6px 8px;">Format selected chart element</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + JA + C</td>
                            <td style="padding: 6px 8px;">Insert chart (opens Insert Chart dialog)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt + JA + L</td>
                            <td style="padding: 6px 8px;">Insert sparkline</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Chart:</strong> A graphical representation of data.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Data Series:</strong> A row or column of data plotted in a chart.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Chart Elements:</strong> Components of a chart like title, legend, axes, data labels.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Chart Sheet:</strong> A separate sheet containing only a chart.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Sparkline:</strong> A tiny chart within a single cell that shows trends.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Alt Text:</strong> Alternative text description for accessibility.</p>
                </div>
                <div>
                    <p><strong>Dashboard:</strong> A collection of charts and data visualizations in one view.</p>
                </div>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-200 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Create and modify charts</li>
                    <li>Format chart elements</li>
                    <li>Add alternative text</li>
                    <li>Create sparklines</li>
                    <li>Modify sparklines</li>
                    <li>Apply chart layouts and styles</li>
                    <li>Switch between chart rows and columns</li>
                    <li>Add and remove data series</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Always include headers</strong> in your data selection for automatic labeling.</li>
                    <li><strong>Use Chart Templates</strong> if you reuse the same chart style.</li>
                    <li><strong>Keep Alt Text concise and meaningful</strong>—avoid "chart of data".</li>
                    <li><strong>Sparklines are great for dashboards</strong>—they save space and show trends.</li>
                    <li><strong>Experiment with chart types</strong> to find the best fit for your data.</li>
                    <li><strong>Use consistent colors</strong> across related charts for professional appearance.</li>
                    <li><strong>Consider your audience</strong> when choosing chart types and complexity.</li>
                    <li><strong>Save chart templates</strong> for frequently used chart styles.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p><strong>Week 8 (Final Week): Final Review and Collaboration Tools</strong></p>
                <p>In Week 8, we'll cover:</p>
                <ul>
                    <li>Final review of all Excel concepts</li>
                    <li>Inspecting workbooks for issues</li>
                    <li>Saving in alternative formats</li>
                    <li>Print settings and optimization</li>
                    <li>Final exam preparation</li>
                    <li>Capstone project review</li>
                    <li>Collaboration and sharing features</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Review all previous weeks and prepare your questions.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #0e5c0e; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Excel Charts and Graphs Tutorial</li>
                    <li>Data Visualization Best Practices Guide</li>
                    <li>Accessibility Guidelines for Charts</li>
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
                Week 7 Handout: Creating, Modifying & Formatting Charts
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
            Week 7: Charts & Sparklines | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 7: Creating, Modifying & Formatting Charts - Impact Digital Academy</title>
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

        /* Chart Type Cards */
        .chart-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .chart-card {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            transition: transform 0.3s;
        }

        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .chart-card.column {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .chart-card.bar {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .chart-card.line {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .chart-card.pie {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .chart-card.scatter {
            border-color: #f44336;
            background: #ffebee;
        }

        .chart-card.area {
            border-color: #00bcd4;
            background: #e0f7fa;
        }

        .chart-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .chart-card.column .chart-icon {
            color: #2196f3;
        }

        .chart-card.bar .chart-icon {
            color: #4caf50;
        }

        .chart-card.line .chart-icon {
            color: #9c27b0;
        }

        .chart-card.pie .chart-icon {
            color: #ff9800;
        }

        .chart-card.scatter .chart-icon {
            color: #f44336;
        }

        .chart-card.area .chart-icon {
            color: #00bcd4;
        }

        /* Chart Element Cards */
        .element-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 20px 0;
        }

        .element-card {
            flex: 1;
            min-width: 120px;
            text-align: center;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #f9f9f9;
            font-size: 0.9rem;
        }

        .element-card.title {
            background: #fff3e0;
            border-color: #ff9800;
        }

        .element-card.legend {
            background: #e8f5e9;
            border-color: #4caf50;
        }

        .element-card.labels {
            background: #e3f2fd;
            border-color: #2196f3;
        }

        .element-card.gridlines {
            background: #f5f5f5;
            border-color: #9e9e9e;
        }

        /* Sparkline Cards */
        .sparkline-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .sparkline-card {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .sparkline-card.line {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .sparkline-card.column {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .sparkline-card.winloss {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .sparkline-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .sparkline-card.line .sparkline-icon {
            color: #2196f3;
        }

        .sparkline-card.column .sparkline-icon {
            color: #4caf50;
        }

        .sparkline-card.winloss .sparkline-icon {
            color: #ff9800;
        }

        /* Dashboard Preview */
        .dashboard-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .dashboard-chart {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .dashboard-chart h4 {
            color: #107c10;
            margin-bottom: 15px;
            text-align: center;
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

        /* Chart Selection Flow */
        .flow-diagram {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .flow-step {
            text-align: center;
            flex: 1;
            min-width: 120px;
            padding: 15px;
        }

        .flow-number {
            display: inline-block;
            width: 40px;
            height: 40px;
            line-height: 40px;
            background: #107c10;
            color: white;
            border-radius: 50%;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .flow-arrow {
            flex: 0 0 30px;
            text-align: center;
            color: #107c10;
            font-size: 1.5rem;
        }

        /* Accessibility Badge */
        .accessibility-badge {
            display: inline-block;
            background: #4caf50;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-left: 10px;
            vertical-align: middle;
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

            .chart-cards,
            .sparkline-cards {
                flex-direction: column;
            }

            .flow-diagram {
                flex-direction: column;
            }

            .flow-arrow {
                transform: rotate(90deg);
                margin: 10px 0;
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
            
            .dashboard-preview {
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
                <strong>Access Granted:</strong> Excel Week 7 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week6_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 6
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/excel_week8_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 8
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-200 Excel Certification Prep – Week 7 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Creating, Modifying & Formatting Charts</div>
            <div class="week-tag">Week 7 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i> Welcome to Week 7!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, you'll learn how to visually represent data using charts and sparklines. Charts help turn complex data into understandable visuals, making trends, comparisons, and patterns clear at a glance. You'll also learn how to make charts accessible and professional-looking for reports and presentations.
                </p>

                <div class="image-container">
                    <img src="images/charts_dashboard.png"
                        alt="Excel Charts and Dashboard Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RXhjZWwgQ2hhcnRzIGFuZCBEYXNoYm9hcmQgRXhhbXBsZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Professional Excel Dashboard with Multiple Charts</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Create different types of charts from data ranges</li>
                    <li>Modify chart elements and data series</li>
                    <li>Apply professional formatting to charts</li>
                    <li>Add alternative text for accessibility</li>
                    <li>Create and format sparklines for trend visualization</li>
                    <li>Build multi-chart dashboards</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-200 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Create and modify charts</li>
                        <li>Format chart elements</li>
                        <li>Add alternative text</li>
                        <li>Create sparklines</li>
                    </ul>
                    <ul>
                        <li>Modify sparklines</li>
                        <li>Apply chart layouts and styles</li>
                        <li>Switch between chart rows and columns</li>
                        <li>Add and remove data series</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Introduction to Charts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> 1. Introduction to Charts in Excel
                </div>

                <!-- Chart Type Cards -->
                <div class="chart-cards">
                    <div class="chart-card column">
                        <div class="chart-icon">
                            <i class="fas fa-chart-column"></i>
                        </div>
                        <h4>Column Chart</h4>
                        <p>Compare values across categories</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Comparisons</p>
                    </div>
                    <div class="chart-card bar">
                        <div class="chart-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <h4>Bar Chart</h4>
                        <p>Horizontal comparisons</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Ranking</p>
                    </div>
                    <div class="chart-card line">
                        <div class="chart-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Line Chart</h4>
                        <p>Show trends over time</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Trends</p>
                    </div>
                    <div class="chart-card pie">
                        <div class="chart-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h4>Pie Chart</h4>
                        <p>Show parts of a whole</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Best for: Proportions</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-question-circle"></i> A. What is a Chart?</h3>
                    <ul>
                        <li>A <strong>graphical representation</strong> of data</li>
                        <li>Makes trends, comparisons, and patterns easier to see</li>
                        <li>Common chart types: Column, Bar, Line, Pie, Scatter, Area</li>
                        <li>Each chart type serves different purposes and tells different stories</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lightbulb"></i> B. Why Use Charts?</h3>
                    <ul>
                        <li><strong>Visualize data trends</strong> over time (sales, growth, performance)</li>
                        <li><strong>Compare values</strong> across categories (products, regions, teams)</li>
                        <li><strong>Highlight key insights</strong> for presentations and reports</li>
                        <li><strong>Make data more engaging</strong> and understandable</li>
                        <li><strong>Identify patterns and outliers</strong> quickly</li>
                        <li><strong>Support decision-making</strong> with visual evidence</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-chart-area"></i> Chart Selection Guide
                        </div>
                        <ul>
                            <li><strong>Comparison:</strong> Column or Bar charts</li>
                            <li><strong>Trend over time:</strong> Line or Area charts</li>
                            <li><strong>Part-to-whole:</strong> Pie or Doughnut charts</li>
                            <li><strong>Correlation:</strong> Scatter or Bubble charts</li>
                            <li><strong>Distribution:</strong> Histogram or Box & Whisker</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Section 2: Creating Charts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-plus-circle"></i> 2. Creating Charts
                </div>

                <div class="flow-diagram">
                    <div class="flow-step">
                        <div class="flow-number">1</div>
                        <p><strong>Select Data</strong></p>
                        <p>Include headers</p>
                    </div>
                    <div class="flow-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="flow-step">
                        <div class="flow-number">2</div>
                        <p><strong>Insert Chart</strong></p>
                        <p>Choose type</p>
                    </div>
                    <div class="flow-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="flow-step">
                        <div class="flow-number">3</div>
                        <p><strong>Customize</strong></p>
                        <p>Add titles, labels</p>
                    </div>
                    <div class="flow-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="flow-step">
                        <div class="flow-number">4</div>
                        <p><strong>Format</strong></p>
                        <p>Apply styles</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-chart-column"></i> A. Creating a Chart from Data</h3>
                    <ol>
                        <li><strong>Select the data range</strong> (including headers)</li>
                        <li>Go to <strong>Insert → Charts</strong> group</li>
                        <li>Choose a chart type (e.g., Clustered Column, Line, Pie)</li>
                        <li>The chart will be inserted into the worksheet</li>
                        <li>Use <strong>Recommended Charts</strong> for Excel's suggestions</li>
                        <li>Keyboard shortcut: <strong>Alt + F1</strong> (embedded) or <strong>F11</strong> (chart sheet)</li>
                    </ol>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-magic"></i> Pro Tip: Recommended Charts
                        </div>
                        <p>Excel's <strong>Recommended Charts</strong> feature analyzes your data and suggests the most appropriate chart types. Use it when you're unsure which chart to choose.</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-alt"></i> B. Creating a Chart Sheet</h3>
                    <ul>
                        <li>A chart sheet is a <strong>separate sheet</strong> that contains only a chart</li>
                        <li>Ideal for printing or presenting individual charts</li>
                        <li>To create:</li>
                        <ol>
                            <li>Create a chart on a worksheet</li>
                            <li>Right-click the chart → <strong>Move Chart</strong></li>
                            <li>Select <strong>New Sheet</strong> and name it</li>
                            <li>Click OK</li>
                        </ol>
                        <li>Shortcut: Press <strong>F11</strong> with data selected</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Modifying Charts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-edit"></i> 3. Modifying Charts
                </div>

                <!-- Chart Elements Cards -->
                <div class="element-cards">
                    <div class="element-card title">
                        <strong>Chart Title</strong>
                        <p>Main title of chart</p>
                    </div>
                    <div class="element-card legend">
                        <strong>Legend</strong>
                        <p>Explains colors/series</p>
                    </div>
                    <div class="element-card labels">
                        <strong>Data Labels</strong>
                        <p>Show values on chart</p>
                    </div>
                    <div class="element-card gridlines">
                        <strong>Gridlines</strong>
                        <p>Major/minor lines</p>
                    </div>
                    <div class="element-card" style="background: #f3e5f5; border-color: #9c27b0;">
                        <strong>Trendline</strong>
                        <p>Shows trend</p>
                    </div>
                    <div class="element-card" style="background: #ffebee; border-color: #f44336;">
                        <strong>Error Bars</strong>
                        <p>Shows variability</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-layer-group"></i> A. Adding Data Series to Charts</h3>
                    <ul>
                        <li>A <strong>data series</strong> is a row or column of data plotted in a chart</li>
                        <li>To add a new series:</li>
                        <ol>
                            <li>Click the chart</li>
                            <li><strong>Chart Design → Select Data</strong></li>
                            <li>Click <strong>Add</strong> under Legend Entries (Series)</li>
                            <li>Select the new data range</li>
                            <li>Click OK</li>
                        </ol>
                        <li>To remove: Select series in chart, press Delete</li>
                        <li>To edit: Double-click series, or use Format pane</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-exchange-alt"></i> B. Switching Between Rows and Columns</h3>
                    <ul>
                        <li>Changes whether rows or columns are treated as data series</li>
                        <li><strong>Chart Design → Switch Row/Column</strong></li>
                        <li>Useful when you want to change the orientation of your data</li>
                        <li>Example: Switch from "products by month" to "months by product"</li>
                        <li>Shortcut: <strong>Alt + JC + S</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-puzzle-piece"></i> C. Adding and Modifying Chart Elements</h3>
                    <ul>
                        <li><strong>Chart Elements button</strong> (+ next to chart) or <strong>Chart Design → Add Chart Element</strong></li>
                        <li>Common elements and their purposes:</li>
                        <ul>
                            <li><strong>Titles:</strong> Chart Title, Axis Titles (add context)</li>
                            <li><strong>Data Labels:</strong> Show actual values on chart</li>
                            <li><strong>Legend:</strong> Explains colors/series</li>
                            <li><strong>Gridlines:</strong> Major/minor (improve readability)</li>
                            <li><strong>Trendline:</strong> Shows trend over time (linear, exponential)</li>
                            <li><strong>Error Bars:</strong> Shows variability or uncertainty</li>
                            <li><strong>Data Table:</strong> Shows data below chart</li>
                            <li><strong>Axes:</strong> Format axis scale and labels</li>
                        </ul>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Formatting Charts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-paint-brush"></i> 4. Formatting Charts
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-th-large"></i> A. Applying Chart Layouts</h3>
                    <ul>
                        <li>Predefined combinations of chart elements</li>
                        <li><strong>Chart Design → Quick Layout</strong></li>
                        <li>Choose from various layouts with different element combinations</li>
                        <li>Includes pre-positioned titles, legends, and data labels</li>
                        <li>Good starting point before customizing</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-palette"></i> B. Applying Chart Styles</h3>
                    <ul>
                        <li>Predefined color and effect schemes</li>
                        <li><strong>Chart Design → Chart Styles</strong> gallery</li>
                        <li>Styles include color variations and effects</li>
                        <li>Use <strong>Change Colors</strong> for different color palettes</li>
                        <li>Accessible color palettes available</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/chart_styles.png"
                            alt="Excel Chart Styles Gallery"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBDaGFydCBTdHlsZXMgR2FsbGVyeTwvdGV4dD48L3N2Zz4='">
                        <div class="image-caption">Chart Styles Gallery in Excel</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> C. Manual Formatting Options</h3>
                    <ul>
                        <li>Click any chart element → <strong>Format</strong> tab appears</li>
                        <li>Change:</li>
                        <ul>
                            <li><strong>Fill & Line:</strong> Colors, borders, gradients, pictures</li>
                            <li><strong>Effects:</strong> Shadow, glow, 3D, soft edges, bevel</li>
                            <li><strong>Size & Properties:</strong> Resize, position, alignment</li>
                            <li><strong>Text Options:</strong> Font, color, effects, text box</li>
                            <li><strong>Series Options:</strong> Gap width, angle, explosion</li>
                        </ul>
                        <li>Shortcut: Select element, press <strong>Ctrl + 1</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-universal-access"></i> D. Adding Alternative Text for Accessibility <span class="accessibility-badge">A11Y</span></h3>
                    <ul>
                        <li>Alt Text helps screen readers describe the chart</li>
                        <li>Essential for accessibility compliance (ADA, WCAG)</li>
                        <li>To add:</li>
                        <ol>
                            <li>Right-click the chart → <strong>Edit Alt Text</strong></li>
                            <li>Enter a description in the Alt Text pane</li>
                            <li>Keep it concise and descriptive</li>
                            <li>Include: Chart type, data, key insights</li>
                        </ol>
                        <li><strong>Good Example:</strong> "Column chart showing monthly sales from January to June, with highest sales in June at $24,000"</li>
                        <li><strong>Avoid:</strong> "Chart of data" or "Sales chart"</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-eye"></i> Accessibility Best Practices
                        </div>
                        <ul>
                            <li>Use sufficient color contrast (4.5:1 ratio)</li>
                            <li>Avoid color-only distinctions (add patterns or labels)</li>
                            <li>Keep Alt Text under 125 characters if possible</li>
                            <li>Use descriptive chart titles and axis labels</li>
                            <li>Consider colorblind-friendly palettes</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Section 5: Sparklines -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> 5. Sparklines: Mini-Charts in a Cell
                </div>

                <!-- Sparkline Type Cards -->
                <div class="sparkline-cards">
                    <div class="sparkline-card line">
                        <div class="sparkline-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Line Sparkline</h4>
                        <p>Shows trend over time</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Continuous data</p>
                    </div>
                    <div class="sparkline-card column">
                        <div class="sparkline-icon">
                            <i class="fas fa-chart-column"></i>
                        </div>
                        <h4>Column Sparkline</h4>
                        <p>Compares values</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Discrete data</p>
                    </div>
                    <div class="sparkline-card winloss">
                        <div class="sparkline-icon">
                            <i class="fas fa-balance-scale"></i>
                        </div>
                        <h4>Win/Loss Sparkline</h4>
                        <p>Shows positive/negative</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Binary outcomes</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-info-circle"></i> A. What are Sparklines?</h3>
                    <ul>
                        <li><strong>Tiny charts</strong> that fit in a single cell</li>
                        <li>Show trends in a small space</li>
                        <li>Types: Line, Column, Win/Loss</li>
                        <li>Perfect for dashboards and summary reports</li>
                        <li>Created by Edward Tufte, data visualization expert</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus"></i> B. Inserting Sparklines</h3>
                    <ol>
                        <li>Select the data range for the sparkline</li>
                        <li><strong>Insert → Sparklines</strong> group</li>
                        <li>Choose type (Line, Column, or Win/Loss)</li>
                        <li>Select <strong>Location Range</strong> (where sparklines will appear)</li>
                        <li>Click OK</li>
                        <li>Shortcut: <strong>Alt + JA + L</strong></li>
                    </ol>

                    <div class="image-container">
                        <img src="images/sparklines.png"
                            alt="Excel Sparklines Example"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5FeGNlbCBTcGFya2xpbmVzIEV4YW1wbGU8L3RleHQ+PC9zdmc+'>
                        <div class="image-caption">Sparklines showing trends in a compact format</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> C. Formatting Sparklines</h3>
                    <ul>
                        <li>Click a sparkline → <strong>Sparkline Design</strong> tab appears</li>
                        <li>Change:</li>
                        <ul>
                            <li><strong>Style:</strong> Colors, line weight, marker colors</li>
                            <li><strong>Show:</strong> High/low points, markers, first/last points, negative points</li>
                            <li><strong>Axis:</strong> Minimum/maximum, date axis, custom view</li>
                            <li><strong>Type:</strong> Switch between line, column, win/loss</li>
                            <li><strong>Group:</strong> Group sparklines for consistent formatting</li>
                        </ul>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-chart-line"></i> Sparkline Best Practices
                        </div>
                        <ul>
                            <li>Use sparklines for quick trend analysis</li>
                            <li>Place them next to the data they represent</li>
                            <li>Highlight important points (high, low, first, last)</li>
                            <li>Use consistent formatting across related sparklines</li>
                            <li>Consider sparklines for dashboard headers</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 6. Hands-On Exercise: Create a Sales Dashboard with Charts
                </div>
                <p><strong>Objective:</strong> Build a multi-chart dashboard to visualize sales performance.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Sales Data Table:</h4>
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Product A</th>
                                <th>Product B</th>
                                <th>Product C</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>January</td>
                                <td>$15,000</td>
                                <td>$12,000</td>
                                <td>$9,000</td>
                                <td style="color: #107c10;">▲</td>
                            </tr>
                            <tr>
                                <td>February</td>
                                <td>$18,000</td>
                                <td>$14,000</td>
                                <td>$11,000</td>
                                <td style="color: #107c10;">▲</td>
                            </tr>
                            <tr>
                                <td>March</td>
                                <td>$22,000</td>
                                <td>$16,000</td>
                                <td>$13,000</td>
                                <td style="color: #107c10;">▲</td>
                            </tr>
                            <tr>
                                <td>April</td>
                                <td>$19,000</td>
                                <td>$17,000</td>
                                <td>$14,000</td>
                                <td style="color: #f44336;">▼</td>
                            </tr>
                            <tr>
                                <td>May</td>
                                <td>$21,000</td>
                                <td>$15,000</td>
                                <td>$12,000</td>
                                <td style="color: #107c10;">▲</td>
                            </tr>
                            <tr>
                                <td>June</td>
                                <td>$24,000</td>
                                <td>$18,000</td>
                                <td>$15,000</td>
                                <td style="color: #107c10;">▲</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0e5c0e; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Create a Clustered Column Chart:</strong></li>
                        <ul>
                            <li>Select A1:D7</li>
                            <li>Insert → Clustered Column</li>
                            <li>Add Chart Title: "Monthly Sales by Product"</li>
                            <li>Add Axis Titles: "Month" (horizontal), "Sales ($)" (vertical)</li>
                        </ul>
                        
                        <li><strong>Create a Line Chart for Trend Analysis:</strong></li>
                        <ul>
                            <li>Select A1:A7 and B1:B7 (Month and Product A)</li>
                            <li>Insert → Line Chart</li>
                            <li>Move chart to a Chart Sheet named "Product A Trend"</li>
                            <li>Add Trendline (linear)</li>
                        </ul>
                        
                        <li><strong>Add Sparklines for At-a-Glance Trends:</strong></li>
                        <ul>
                            <li>In E2, insert a Line Sparkline for B2:D2 (first month)</li>
                            <li>Fill down to E7 for each month</li>
                            <li>Format sparklines to show High Point and Low Point</li>
                        </ul>
                        
                        <li><strong>Format and Finalize:</strong></li>
                        <ul>
                            <li>Apply Chart Style 3 to the column chart</li>
                            <li>Add Data Labels to the column chart</li>
                            <li>Add Alt Text to each chart (describe trend and purpose)</li>
                            <li>Create a Pie Chart for June sales (A7:D7) and add percentages as data labels</li>
                            <li>Group related charts together</li>
                        </ul>
                        
                        <li><strong>Save as Sales_Dashboard.xlsx</strong></li>
                    </ol>
                </div>

                <div class="dashboard-preview">
                    <div class="dashboard-chart">
                        <h4>Column Chart Preview</h4>
                        <div style="height: 200px; background: linear-gradient(to top, #e8f4e8 60%, #107c10 40%); border-radius: 5px; margin: 10px 0;"></div>
                        <p style="text-align: center; font-size: 0.9rem; color: #666;">Monthly Sales Comparison</p>
                    </div>
                    <div class="dashboard-chart">
                        <h4>Line Chart Preview</h4>
                        <div style="height: 200px; background: #f3e5f5; border-radius: 5px; margin: 10px 0; position: relative;">
                            <div style="position: absolute; bottom: 0; left: 10%; width: 80%; height: 60%; background: linear-gradient(to right, #9c27b0, transparent); border-radius: 5px;"></div>
                        </div>
                        <p style="text-align: center; font-size: 0.9rem; color: #666;">Product A Trend Analysis</p>
                    </div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Dashboard Template
                </a>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 7. Essential Shortcuts for Week 7
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
                            <td><span class="shortcut-key">F11</span></td>
                            <td>Create a chart on a new chart sheet</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F1</span></td>
                            <td>Create an embedded chart</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + F3</span></td>
                            <td>Create names from selection (for chart data)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + JC + A</span></td>
                            <td>Add Chart Element (after chart is selected)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + JC + S</span></td>
                            <td>Switch Row/Column</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + 1</span></td>
                            <td>Format selected chart element</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + JA + C</span></td>
                            <td>Insert chart (opens Insert Chart dialog)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + JA + L</span></td>
                            <td>Insert sparkline</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + JA + E</span></td>
                            <td>Edit sparkline data</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Delete</span></td>
                            <td>Delete selected chart element</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Chart</strong>
                    <p>A graphical representation of data used to visualize patterns, trends, and comparisons.</p>
                </div>

                <div class="term">
                    <strong>Data Series</strong>
                    <p>A row or column of data plotted in a chart. Multiple series can be compared on one chart.</p>
                </div>

                <div class="term">
                    <strong>Chart Elements</strong>
                    <p>Components of a chart like title, legend, axes, data labels, gridlines, and trendlines.</p>
                </div>

                <div class="term">
                    <strong>Chart Sheet</strong>
                    <p>A separate sheet containing only a chart, ideal for printing or presentations.</p>
                </div>

                <div class="term">
                    <strong>Sparkline</strong>
                    <p>A tiny chart within a single cell that shows trends without taking up much space.</p>
                </div>

                <div class="term">
                    <strong>Alt Text (Alternative Text)</strong>
                    <p>Text description of a chart for accessibility, read by screen readers.</p>
                </div>

                <div class="term">
                    <strong>Dashboard</strong>
                    <p>A collection of charts, tables, and visualizations in one view for quick analysis.</p>
                </div>

                <div class="term">
                    <strong>Trendline</strong>
                    <p>A line on a chart showing the general direction or trend of data over time.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 9. Weekly Homework Assignment
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Complete these exercises:</h4>
                    <ol>
                        <li><strong>Expense Report Visualizer:</strong>
                            <ul>
                                <li>Create a dataset of monthly expenses (Rent, Utilities, Food, Travel) for 6 months</li>
                                <li>Insert a Bar Chart comparing average expenses per category</li>
                                <li>Add a Line Chart showing monthly trends for one category</li>
                                <li>Insert Column Sparklines next to each category to show monthly variance</li>
                                <li>Add Axis Titles, Legend, and Data Labels</li>
                                <li>Apply professional chart formatting</li>
                            </ul>
                        </li>
                        <li><strong>Accessible Chart Practice:</strong>
                            <ul>
                                <li>Create two charts from any dataset</li>
                                <li>Add descriptive Alt Text to each (follow accessibility guidelines)</li>
                                <li>Apply different chart styles and layouts</li>
                                <li>Take screenshots before/after formatting</li>
                                <li>Test with a screen reader if available</li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>How do you move a chart to its own sheet?</li>
                                <li>What is the purpose of switching rows/columns in a chart?</li>
                                <li>What are the three types of sparklines?</li>
                                <li>Why is Alt Text important for charts?</li>
                                <li>What shortcut creates a chart on a new chart sheet?</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Submit your completed <strong>Sales_Dashboard.xlsx</strong> and <strong>Expense_Visualizer.xlsx</strong> files via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Always include headers</strong> in your data selection for automatic labeling.</li>
                    <li><strong>Use Chart Templates</strong> if you reuse the same chart style (Save as Template).</li>
                    <li><strong>Keep Alt Text concise and meaningful</strong>—avoid "chart of data".</li>
                    <li><strong>Sparklines are great for dashboards</strong>—they save space and show trends.</li>
                    <li><strong>Experiment with chart types</strong> to find the best fit for your data.</li>
                    <li><strong>Use consistent colors</strong> across related charts for professional appearance.</li>
                    <li><strong>Consider your audience</strong> when choosing chart types and complexity.</li>
                    <li><strong>Save chart templates</strong> for frequently used chart styles.</li>
                    <li><strong>Use data labels sparingly</strong>—only when they add value.</li>
                    <li><strong>Test accessibility</strong> by using screen readers or accessibility checkers.</li>
                    <li><strong>Keep it simple</strong>—sometimes less is more in data visualization.</li>
                    <li><strong>Use the right chart type</strong> for your message (comparison, trend, distribution, etc.).</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 8 (Final Week): Final Review and Collaboration Tools</strong></p>
                <p>In Week 8, we'll cover:</p>
                <ul>
                    <li><strong>Final review</strong> of all Excel concepts covered</li>
                    <li><strong>Inspecting workbooks</strong> for issues and metadata</li>
                    <li><strong>Saving in alternative formats</strong> (PDF, HTML, CSV)</li>
                    <li><strong>Print settings and optimization</strong> for professional output</li>
                    <li><strong>Final exam preparation</strong> and practice tests</li>
                    <li><strong>Capstone project review</strong> and submission guidelines</li>
                    <li><strong>Collaboration and sharing</strong> features (comments, track changes)</li>
                    <li><strong>Workbook protection</strong> and security settings</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Review all previous weeks and prepare your questions. Bring your completed capstone project for review.</p>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/create-a-chart-from-start-to-finish-0baf399e-dd61-4e18-8a73-b3fd5d5680c2" target="_blank">Microsoft: Create a Chart from Start to Finish</a></li>
                    <li><a href="https://support.microsoft.com/office/create-sparklines-in-excel-147ef1a4-7b34-4f4a-9dcd-5eab8f8c0c5c" target="_blank">Microsoft: Create Sparklines in Excel</a></li>
                    <li><a href="https://www.w3.org/WAI/tutorials/images/complex/" target="_blank">W3C: Complex Images Accessibility Guide</a></li>
                    <li><a href="https://exceljet.net/charting" target="_blank">ExcelJet: Charting and Data Visualization</a></li>
                    <li><a href="https://depictdatastudio.com/chart-chooser-cheat-sheet/" target="_blank">Chart Chooser Cheat Sheet</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Interactive chart simulator</strong> for hands-on practice</li>
                    <li><strong>Week 7 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Accessibility checker tool</strong> for evaluating chart accessibility</li>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/excel-week7.php">Week 7 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft Excel Help:</strong> <a href="https://support.microsoft.com/excel" target="_blank">Official Support</a></li>
                    <li><strong>Chart Design Community:</strong> <a href="https://community.powerbi.com/t5/Data-Stories-Gallery/bd-p/DataStoriesGallery" target="_blank">Power BI Community</a></li>
                    <li><strong>Accessibility Resources:</strong> <a href="https://www.w3.org/WAI/design-develop/" target="_blank">W3C Accessibility Guidelines</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/excel_week7_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 7 Quiz
                </a> -->
                <!-- <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/chart_dashboard.xltx" class="download-btn" style="background: #2196f3; margin-left: 15px;">
                    <i class="fas fa-download"></i> Download Chart Templates
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-200: Microsoft Excel Certification Prep Program – Week 7 Handout</p>
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
            alert('Dashboard template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSExcel/templates/week7_dashboard.xlsx';
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
                console.log('Excel Week 7 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Excel Week 7 Handout',
                //         action: 'view'
                //     })
                // });
            }
        });

        // Interactive chart type selection
        document.addEventListener('DOMContentLoaded', function() {
            const chartCards = document.querySelectorAll('.chart-card');
            chartCards.forEach(card => {
                card.addEventListener('click', function() {
                    const chartType = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    const useCases = {
                        'Column Chart': 'Comparing values across categories, showing changes over time',
                        'Bar Chart': 'Comparing values when categories have long names, ranking items',
                        'Line Chart': 'Showing trends over time, continuous data, multiple series',
                        'Pie Chart': 'Showing parts of a whole, proportions, percentages (limit to 6 slices)',
                        'Scatter Chart': 'Showing relationships between variables, correlation analysis',
                        'Area Chart': 'Showing cumulative totals over time, emphasizing volume'
                    };
                    alert(`${chartType}\n\n${description}\n\nBest for: ${useCases[chartType] || 'General data visualization'}`);
                });
            });

            // Sparkline cards interaction
            const sparklineCards = document.querySelectorAll('.sparkline-card');
            sparklineCards.forEach(card => {
                card.addEventListener('click', function() {
                    const sparklineType = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    const examples = {
                        'Line Sparkline': 'Stock prices, temperature trends, website traffic over time',
                        'Column Sparkline': 'Monthly sales, survey results, comparison of discrete values',
                        'Win/Loss Sparkline': 'Sports scores, profit/loss, positive/negative indicators'
                    };
                    alert(`${sparklineType}\n\n${description}\n\nExamples: ${examples[sparklineType] || 'Trend visualization'}`);
                });
            });

            // Chart elements interaction
            const elementCards = document.querySelectorAll('.element-card');
            elementCards.forEach(card => {
                card.addEventListener('click', function() {
                    const element = this.querySelector('strong').textContent;
                    const purpose = this.querySelector('p').textContent;
                    const details = {
                        'Chart Title': 'Describes what the chart shows. Should be clear and concise.',
                        'Legend': 'Explains what each color/series represents. Position for readability.',
                        'Data Labels': 'Show exact values on chart. Use sparingly to avoid clutter.',
                        'Gridlines': 'Help readers align with axis values. Use light colors.',
                        'Trendline': 'Shows general direction of data. Can be linear, exponential, etc.',
                        'Error Bars': 'Show variability or uncertainty in data. Common in scientific charts.'
                    };
                    alert(`${element}\n\nPurpose: ${purpose}\n\nDetails: ${details[element] || 'Chart component'}`);
                });
            });
        });

        // Keyboard shortcut practice for chart creation
        document.addEventListener('keydown', function(e) {
            const chartShortcuts = {
                'F11': 'Create chart on new chart sheet',
                'F1': 'Create embedded chart (Alt + F1)',
                '1': 'Format selection (Ctrl + 1)'
            };
            
            if ((e.key === 'F11' || (e.altKey && e.key === 'F1') || (e.ctrlKey && e.key === '1')) && !e.repeat) {
                e.preventDefault();
                
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
                    font-weight: bold;
                `;
                
                let message = '';
                if (e.key === 'F11') message = 'F11: Create chart on new chart sheet';
                else if (e.altKey && e.key === 'F1') message = 'Alt + F1: Create embedded chart';
                else if (e.ctrlKey && e.key === '1') message = 'Ctrl + 1: Format selected chart element';
                
                shortcutAlert.textContent = `Excel Chart Shortcut: ${message}`;
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
            
            /* Sparkline animation */
            @keyframes sparklinePulse {
                0% { opacity: 0.3; }
                50% { opacity: 1; }
                100% { opacity: 0.3; }
            }
            
            .sparkline-card:hover .sparkline-icon {
                animation: sparklinePulse 1.5s infinite;
            }
        `;
        document.head.appendChild(style);

        // Accessibility demonstration
        function demonstrateAltText() {
            const goodExample = "Column chart showing monthly sales from January to June, with highest sales in June at $24,000. Product A shows consistent growth throughout the period.";
            const badExample = "Chart of sales data";
            
            alert("Alt Text Examples:\n\n" +
                  "✅ GOOD (Descriptive):\n" + goodExample + "\n\n" +
                  "❌ BAD (Too vague):\n" + badExample + "\n\n" +
                  "Guidelines:\n" +
                  "1. Describe the chart type\n" +
                  "2. Mention the data shown\n" +
                  "3. Highlight key insights\n" +
                  "4. Keep it concise (under 125 chars if possible)\n" +
                  "5. Avoid 'chart of' or 'image of'");
        }

        // Chart creation simulation
        function simulateChartCreation() {
            const steps = [
                "Step 1: Select your data range (A1:D7)",
                "Step 2: Go to Insert → Charts → Column Chart",
                "Step 3: Choose Clustered Column",
                "Step 4: Chart appears on worksheet",
                "Step 5: Click Chart Title to edit",
                "Step 6: Click + button to add elements",
                "Step 7: Use Chart Design tab for styles",
                "Step 8: Right-click chart for Alt Text"
            ];
            alert("Creating a Chart in Excel:\n\n" + steps.join("\n"));
        }

        // Sparkline creation simulation
        function simulateSparklineCreation() {
            const wizardSteps = [
                "Insert Sparkline Dialog",
                "Data Range: Select B2:D2 (first row of data)",
                "Location Range: Select E2 (where sparkline appears)",
                "Choose Type: ○ Line ● Column ○ Win/Loss",
                "Click OK",
                "Format: Use Sparkline Design tab",
                "Show: ☑ High Point ☑ Low Point ☐ Markers",
                "Style: Choose from gallery"
            ];
            alert("Inserting Sparklines:\n\n" + wizardSteps.join("\n"));
        }

        // Dashboard layout helper
        function showDashboardLayout() {
            alert("Dashboard Layout Tips:\n\n" +
                  "1. Place most important chart top-left\n" +
                  "2. Group related charts together\n" +
                  "3. Use consistent colors across charts\n" +
                  "4. Include sparklines for quick trends\n" +
                  "5. Add titles and clear labels\n" +
                  "6. Consider grid layout (2x2, 3x3)\n" +
                  "7. Leave white space for readability\n" +
                  "8. Use cell borders or shading to separate sections");
        }

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Right-click chart → Move Chart → New Sheet, or press F11.",
                    "2. Changes whether rows or columns are treated as data series (orientation).",
                    "3. Line, Column, and Win/Loss sparklines.",
                    "4. Alt Text makes charts accessible to screen readers for visually impaired users.",
                    "5. F11 creates a chart on a new chart sheet."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Accessibility checker simulation
        function checkAccessibility() {
            const checklist = [
                "✓ Chart has descriptive title",
                "✓ Axis labels are clear",
                "✓ Legend is present (if multiple series)",
                "✓ Color contrast is sufficient",
                "✓ Alt Text is descriptive",
                "✓ Data labels used when helpful",
                "✓ Chart type appropriate for data",
                "✓ No 3D effects (for clarity)"
            ];
            alert("Accessibility Checklist:\n\n" + checklist.join("\n"));
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
    $viewer = new ExcelWeek7HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>