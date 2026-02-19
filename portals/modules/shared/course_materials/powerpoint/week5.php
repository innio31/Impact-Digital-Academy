<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week5_view.php

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
 * PowerPoint Week 5 Handout Viewer Class with PDF Download
 */
class PowerPointWeek5HandoutViewer
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
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
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
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to PowerPoint courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to PowerPoint courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft PowerPoint (Office 2019)%'";
        
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
        if (!isset($_GET['download']) || $_GET['download'] !== 'pdf') {
            return;
        }
        
        $autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        } else {
            $mpdfPath = __DIR__ . '/../../../../vendor/mpdf/mpdf/src/Mpdf.php';
            if (file_exists($mpdfPath)) {
                require_once $mpdfPath;
            } else {
                $this->showPDFError();
                return;
            }
        }
        
        $htmlContent = $this->getHTMLContentForPDF();
        
        try {
            if (version_compare(PHP_VERSION, '7.1.0') < 0) {
                throw new Exception('PHP 7.1.0 or higher is required for mPDF 8+. Your PHP version: ' . PHP_VERSION);
            }
            
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
            
            try {
                $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            } catch (Exception $e) {
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            $mpdf->SetTitle('Week 5: Data Visualization & Interactive Media Mastery');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Data Visualization, Charts, Tables, 3D Models, Media');
            
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            $mpdf->WriteHTML($htmlContent);
            
            $filename = 'PowerPoint_Week5_Data_Visualization_' . date('Y-m-d') . '.pdf';
            
            if (ob_get_length()) {
                ob_end_clean();
            }
            
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
                
                <p><strong>Alternative:</strong> Download and install mPDF manually:</p>
                <ol>
                    <li>Download from: <a href="https://github.com/mpdf/mpdf/releases" target="_blank">https://github.com/mpdf/mpdf/releases</a></li>
                    <li>Extract to: <code>' . htmlspecialchars(__DIR__ . '/../../../../vendor/mpdf/mpdf/') . '</code></li>
                </ol>
                
                <p><strong>Temporary Solution:</strong> Use the print function instead:</p>
                <button onclick="window.print()" style="padding: 10px 20px; background: #d32f2f; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #d32f2f; border-bottom: 2px solid #d32f2f; padding-bottom: 10px; font-size: 18pt;">
                Week 5: Data Visualization & Interactive Media Mastery
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 5!</h2>
                <p style="margin-bottom: 15px;">
                    Following your mastery of visual storytelling with images, shapes, and basic SmartArt, we now elevate your presentation to the level of data-driven professionalism. This week focuses on transforming raw data and complex ideas into compelling, understandable visualizations. You will learn to create and customize sophisticated tables, dynamic charts, advanced SmartArt hierarchies, and interactive 3D models. Furthermore, you'll integrate and control various media types—audio, video, and screen recordings—to create rich, multi-sensory presentation experiences. By the end of this week, you will be equipped to handle the most demanding corporate content with confidence and flair.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Design, format, and sort professional tables that clearly present structured data</li>
                    <li>Create, link, and customize charts from Excel to visualize trends and comparisons</li>
                    <li>Build and manipulate complex hierarchical and matrix diagrams using advanced SmartArt techniques</li>
                    <li>Insert, animate, and seamlessly integrate 3D models into your narrative</li>
                    <li>Embed, trim, and configure playback options for audio, video, and screen recordings</li>
                    <li>Combine multiple object types on a single slide to create a comprehensive, interactive dashboard</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. Professional Table Design & Management</h3>
                <ul>
                    <li><strong>Inserting Tables:</strong>
                        <ul>
                            <li>Insert Tab > Table: Choose grid size or use Insert Table to specify columns/rows</li>
                            <li>Drawing a Table: For irregular layouts</li>
                            <li>Excel Spreadsheet: Insert Tab > Table > Excel Spreadsheet for full Excel functionality within PowerPoint</li>
                        </ul>
                    </li>
                    <li><strong>Formatting for Clarity (Table Design & Layout Tabs):</strong>
                        <ul>
                            <li>Table Styles: Apply built-in styles with banded rows, header row emphasis</li>
                            <li>Custom Shading & Borders: Use Borders and Shading for precise control</li>
                            <li>Alignment & Cell Margins: Control text position within cells (Layout Tab)</li>
                        </ul>
                    </li>
                    <li><strong>Table Manipulation:</strong>
                        <ul>
                            <li>Adding/Deleting Rows & Columns: Use the Layout Tab or right-click</li>
                            <li>Merging & Splitting Cells: Create custom header layouts</li>
                            <li>Sorting Data: Select a column and use Layout Tab > Sort to organize alphabetically or numerically</li>
                            <li>Distributing Rows/Columns: Ensure even sizing for a clean look</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Dynamic Chart Creation & Customization</h3>
                <ul>
                    <li><strong>Inserting Charts (Insert Tab > Chart):</strong>
                        <ul>
                            <li>Choose from Column, Line, Pie, Bar, Area, Combo, and more</li>
                            <li>An embedded Excel worksheet will open for data entry—this is the chart data sheet</li>
                        </ul>
                    </li>
                    <li><strong>Linking to Excel Data:</strong>
                        <ul>
                            <li>Paste Special > Paste Link: Copy data from an existing Excel file and use Home Tab > Paste > Paste Special > Paste Link to create a dynamic link</li>
                            <li>The chart in PowerPoint updates when the source Excel file changes</li>
                        </ul>
                    </li>
                    <li><strong>Advanced Chart Formatting (Chart Design & Format Tabs):</strong>
                        <ul>
                            <li>Change Chart Type: Switch from a Column to a Line chart, etc.</li>
                            <li>Edit Data: Re-open the chart data sheet</li>
                            <li>Chart Elements: Add, remove, or format Axis Titles, Data Labels, Legend, Trendline, Error Bars</li>
                            <li>Chart Styles & Colors: Quickly apply aesthetic presets</li>
                            <li>Formatting Specific Elements: Right-click any chart element for deep formatting options like gradient fills, shadow, or glow</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Advanced SmartArt for Complex Concepts</h3>
                <ul>
                    <li><strong>Beyond Basic Diagrams:</strong>
                        <ul>
                            <li>Hierarchy: Organization Charts with multiple assistants and layouts</li>
                            <li>Matrix: For showing relationships within quadrants</li>
                            <li>Picture: For creating photo layouts with captions</li>
                        </ul>
                    </li>
                    <li><strong>Advanced Techniques:</strong>
                        <ul>
                            <li>Promote/Demote Shapes: In the Text Pane, use Tab (demote) and Shift+Tab (promote) to create sub-levels in org charts</li>
                            <li>Layout Options: Right-click a shape in a Hierarchy SmartArt to change its branch layout (Standard, Both, Left Hanging, Right Hanging)</li>
                            <li>Resetting Graphic: Use SmartArt Design > Reset Graphic to revert formatting while keeping text</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. Working with 3D Models</h3>
                <ul>
                    <li><strong>Inserting 3D Models (Insert Tab > 3D Models):</strong>
                        <ul>
                            <li>Stock 3D Library: Vast library of anatomical, geometric, educational, and decorative models</li>
                            <li>From a File: Insert your own .glb, .fbx, .obj, .3mf, or .stl files</li>
                        </ul>
                    </li>
                    <li><strong>Controlling the 3D Scene (3D Model Tab):</strong>
                        <ul>
                            <li>Model Views: Save specific angles of your model for quick recall</li>
                            <li>Pan & Zoom Tool: Examine details</li>
                            <li>Reset: Return the model to its default position and size</li>
                        </ul>
                    </li>
                    <li><strong>Animating 3D Models:</strong>
                        <ul>
                            <li>Apply the Turntable animation (Animations Tab) for automatic rotation</li>
                            <li>Use the Arrive or Jump & Turn emphasis effects for dramatic entry</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">5. Integrating Advanced Media: Audio, Video & Screen Recordings</h3>
                <ul>
                    <li><strong>Audio Deep Dive:</strong>
                        <ul>
                            <li>Record Audio: Insert Tab > Media > Audio > Record Audio for narration or sound bites</li>
                            <li>Trim Audio & Fade: Use Playback Tab > Trim Audio and set fade duration</li>
                            <li>Play Across Slides: Set audio to span multiple slides seamlessly</li>
                        </ul>
                    </li>
                    <li><strong>Video Mastery:</strong>
                        <ul>
                            <li>Poster Frame: Set a custom thumbnail image from a file or a frame within the video</li>
                            <li>Playback Bookmarks: Add bookmarks in a video timeline (Playback Tab) to trigger animations or jump to key moments</li>
                            <li>Video Format Tab: Apply corrections, colors, and video styles (frames, shadows, reflections) just like pictures</li>
                        </ul>
                    </li>
                    <li><strong>Screen Recording (Insert Tab > Media > Screen Recording):</strong>
                        <ul>
                            <li>Select Area: Drag to select the portion of your screen to record</li>
                            <li>Record: Capture audio from your microphone and/or system audio</li>
                            <li>Editing: The recorded clip is embedded as a video, which can then be trimmed and formatted</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise: Quarterly Business Review Dashboard</h3>
                <p><strong>Activity:</strong> Create an Interactive "Quarterly Business Review" Dashboard Slide</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li><strong>Set Up & Insert a Table:</strong>
                        <ul>
                            <li>Create a new slide with Title Only layout. Title it "Q4 Performance Dashboard"</li>
                            <li>Insert a 3x5 table with header: Product, Q3 Sales, Q4 Sales, Growth %, Notes</li>
                            <li>Fill in 4 product rows with sample data</li>
                            <li>Sort table by "Q4 Sales" descending and apply Table Style with banded rows</li>
                        </ul>
                    </li>
                    <li><strong>Create a Linked Column Chart:</strong>
                        <ul>
                            <li>Below table, insert Clustered Column Chart</li>
                            <li>Enter data from table into chart data sheet</li>
                            <li>Add Data Labels and Chart Title "Quarterly Sales Comparison"</li>
                            <li>Format Q4 bars with gradient fill</li>
                        </ul>
                    </li>
                    <li><strong>Build an Advanced SmartArt Hierarchy:</strong>
                        <ul>
                            <li>Insert Hierarchy SmartArt (Organization Chart)</li>
                            <li>Create structure: CEO → VP Marketing, VP Sales → Sales Manager</li>
                            <li>Add "Sales Analyst" under VP Sales</li>
                            <li>Apply 3D SmartArt Style and change colors</li>
                        </ul>
                    </li>
                    <li><strong>Insert and Animate a 3D Model:</strong>
                        <ul>
                            <li>Insert 3D Model from Stock Library (graph or geometric shape)</li>
                            <li>Resize and position in top corner</li>
                            <li>Apply Turntable animation with "Number of Spins: 1"</li>
                        </ul>
                    </li>
                    <li><strong>Embed and Configure a Screen Recording:</strong>
                        <ul>
                            <li>Insert Screen Recording of small desktop area</li>
                            <li>Record for 3 seconds and trim to 2 seconds</li>
                            <li>Set custom Poster Frame and check "Play Full Screen"</li>
                        </ul>
                    </li>
                    <li><strong>Final Polish & Accessibility:</strong>
                        <ul>
                            <li>Align table, chart, and SmartArt neatly</li>
                            <li>Group table, chart, and SmartArt (Ctrl+G)</li>
                            <li>Add Alt Text to chart and 3D model</li>
                            <li>Test in Slide Show mode</li>
                            <li>Save as YourName_Data_Dashboard_WK5.pptx</li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet (Week 5 Focus)</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > C</td>
                            <td style="padding: 6px 8px;">Insert Chart</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > T</td>
                            <td style="padding: 6px 8px;">Insert Table</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > M</td>
                            <td style="padding: 6px 8px;">Insert 3D Model</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > U</td>
                            <td style="padding: 6px 8px;">Screen Recording</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + C / Ctrl + Alt + V</td>
                            <td style="padding: 6px 8px;">Copy / Paste Special</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F10</td>
                            <td style="padding: 6px 8px;">Show Selection Pane</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt, J, C, E</td>
                            <td style="padding: 6px 8px;">Edit Chart Data</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt, J, T, L</td>
                            <td style="padding: 6px 8px;">Table Layout Tab</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + D</td>
                            <td style="padding: 6px 8px;">Duplicate selected object</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Chart Data Sheet:</strong> The mini-Excel worksheet that opens when creating/editing a chart, holding the chart's underlying data.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Paste Link:</strong> A paste method that creates a dynamic connection between pasted content (like a chart) and its source file (like an Excel workbook).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Hierarchy SmartArt:</strong> A diagram type specifically designed to show reporting relationships, such as an organization chart.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Poster Frame:</strong> A custom, static image that represents a video or screen recording on a slide before it is played.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Screen Recording:</strong> A feature that captures video of actions on your computer screen, which can be embedded directly into a slide.</p>
                </div>
                <div>
                    <p><strong>Turntable Animation:</strong> A 3D animation effect that makes a 3D model rotate around its vertical axis.</p>
                </div>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>You have a complex Excel financial model. You need a chart in PowerPoint that updates automatically when the Excel file is revised. What specific paste method must you use?</li>
                    <li>Your organization chart requires a "dotted-line" reporting relationship. After converting SmartArt to shapes, what formatting tool would you use to change a connector line to dashes?</li>
                    <li>You want a 3D model of a product to spin once slowly as you introduce it on a slide. Which animation and which effect option should you apply?</li>
                    <li>You've inserted a 2-minute video but only need to show a 30-second clip. What two Playback Tab features allow you to accomplish this?</li>
                    <li>You need to present regional sales data, showing both total revenue per region (bars) and profit margin percentage (line) on the same chart. What chart type should you insert and customize?</li>
                </ol>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Let Charts Tell the Story:</strong> Choose the chart type that matches your message: trends over time (Line), comparisons (Column/Bar), parts of a whole (Pie), or relationships (Scatter).</li>
                    <li><strong>Simplify for Impact:</strong> Remove default clutter from charts. Eliminate unnecessary gridlines, use direct data labels instead of a legend where possible, and ensure all text is legible.</li>
                    <li><strong>Maintain Data Integrity:</strong> Never stretch or distort a chart disproportionately, as it misrepresents the data. Always keep axes properly scaled.</li>
                    <li><strong>Pre-Record for Perfection:</strong> Use screen recordings and video trims to ensure live demos or complex explanations are flawless and consistent during your actual presentation.</li>
                    <li><strong>Test Media Playback:</strong> Always test videos, audio, and screen recordings in Slide Show mode on the actual presentation computer to avoid codec or link issues.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 6: Collaboration, Review, and Final Polish, we shift from creation to refinement and sharing. You will master:</p>
                <ul>
                    <li>Collaboration tools like Comments and Co-authoring</li>
                    <li>Review tab for proofing and language tools</li>
                    <li>Comparing and merging presentation versions</li>
                    <li>Creating custom slide shows</li>
                    <li>Presenter View and rehearsal tools</li>
                    <li>Securing and preparing final outputs</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Get ready to finalize and present your masterpiece with confidence!</strong></p>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #d32f2f; margin-bottom: 10px;">Instructor Information</h4>
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
            <h1 style="color: #d32f2f; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #b71c1c; font-size: 18pt; margin-bottom: 30px;">
                Microsoft PowerPoint (MO-300) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #d32f2f; 
                border-bottom: 3px solid #d32f2f; padding: 20px 0; margin: 30px 0;">
                Week 5 Handout: Data Visualization & Interactive Media Mastery
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
                    This handout is part of the MO-300 PowerPoint Certification Prep Program. Unauthorized distribution is prohibited.
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
            Week 5: Data Visualization & Interactive Media Mastery | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-300 PowerPoint Certification Prep | Student: ' . htmlspecialchars($this->user_email) . '
        </div>';
    }
    
    /**
     * Display the handout HTML page
     */
    public function display(): void
    {
        if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
            $this->generatePDF();
            exit();
        }
        
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
    <title>Week 5: Data Visualization & Interactive Media Mastery - Impact Digital Academy</title>
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

        .access-header {
            background: linear-gradient(135deg, #4a148c 0%, #7b1fa2 100%);
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
            background: linear-gradient(135deg, #7b1fa2 0%, #4a148c 100%);
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
            color: #7b1fa2;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #7b1fa2;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #6a1b9a;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #7b1fa2;
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
            background-color: #7b1fa2;
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
            background-color: #f3e5f5;
        }

        .shortcut-key {
            background: #7b1fa2;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exercise-box {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #6a1b9a;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .homework-box {
            background: #fff3e0;
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
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .next-week-title {
            color: #2e7d32;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .help-section {
            background: #f3e5f5;
            border-left: 5px solid #7b1fa2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .help-title {
            color: #7b1fa2;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #7b1fa2;
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
            background: #6a1b9a;
        }

        .learning-objectives {
            background: #f3e5f5;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #7b1fa2;
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
            padding: 10px;
            border-left: 3px solid #7b1fa2;
            background: white;
        }

        .term strong {
            color: #7b1fa2;
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

        .visualization-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .viz-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .viz-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .viz-icon {
            font-size: 3rem;
            color: #7b1fa2;
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

        /* Chart Types Demo */
        .chart-types {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .chart-type {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .chart-type:hover {
            border-color: #7b1fa2;
            background: #f3e5f5;
        }

        .chart-type.active {
            border-color: #7b1fa2;
            background: #f3e5f5;
            border-width: 3px;
        }

        .chart-icon {
            font-size: 2.5rem;
            color: #7b1fa2;
            margin-bottom: 10px;
        }

        /* Table Formatting Demo */
        .table-demo {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .table-demo th {
            background: #7b1fa2;
            color: white;
            padding: 12px;
            text-align: left;
        }

        .table-demo td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        .table-demo tr:nth-child(even) {
            background: #f9f9f9;
        }

        .table-demo tr:hover {
            background: #f3e5f5;
        }

        /* Media Types */
        .media-types {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .media-type {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }

        .media-type.audio {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .media-type.video {
            border-color: #f44336;
            background: #ffebee;
        }

        .media-type.screen {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .media-type.model {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .media-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .media-type.audio .media-icon {
            color: #2196f3;
        }

        .media-type.video .media-icon {
            color: #f44336;
        }

        .media-type.screen .media-icon {
            color: #4caf50;
        }

        .media-type.model .media-icon {
            color: #ff9800;
        }

        /* SmartArt Hierarchy */
        .hierarchy-demo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 25px 0;
        }

        .hierarchy-level {
            width: 80%;
            padding: 20px;
            margin: 10px 0;
            border: 2px solid #7b1fa2;
            border-radius: 8px;
            text-align: center;
            position: relative;
            background: white;
        }

        .hierarchy-level.ceo {
            background: #7b1fa2;
            color: white;
        }

        .hierarchy-level.vp {
            background: #e1bee7;
            width: 70%;
        }

        .hierarchy-level.manager {
            background: #f3e5f5;
            width: 60%;
        }

        .hierarchy-level.analyst {
            background: #f9f0fa;
            width: 50%;
        }

        .level-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .level-desc {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Dashboard Preview */
        .dashboard-preview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 25px 0;
        }

        .dashboard-item {
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            min-height: 150px;
        }

        .dashboard-item.table {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .dashboard-item.chart {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .dashboard-item.smartart {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .dashboard-item.model {
            border-color: #9c27b0;
            background: #f3e5f5;
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

            .shortcut-table {
                font-size: 0.9rem;
            }

            .shortcut-table th,
            .shortcut-table td {
                padding: 10px;
            }

            .dashboard-preview {
                grid-template-columns: 1fr;
            }

            .visualization-demo,
            .chart-types,
            .media-types {
                flex-direction: column;
            }
        }

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
                <strong>Access Granted:</strong> PowerPoint Week 5 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week4_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 4
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week6_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 6
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 5 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Data Visualization & Interactive Media Mastery</div>
            <div class="week-tag">Week 5 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> Welcome to Week 5!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    Following your mastery of visual storytelling with images, shapes, and basic SmartArt, we now elevate your presentation to the level of data-driven professionalism. This week focuses on transforming raw data and complex ideas into compelling, understandable visualizations. You will learn to create and customize sophisticated tables, dynamic charts, advanced SmartArt hierarchies, and interactive 3D models. Furthermore, you'll integrate and control various media types—audio, video, and screen recordings—to create rich, multi-sensory presentation experiences.
                </p>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    <strong>By the end of this week, you will be equipped to handle the most demanding corporate content with confidence and flair.</strong>
                </p>

                <div class="image-container">
                    <div style="font-size: 2rem; color: #7b1fa2;">
                        <i class="fas fa-chart-bar"></i>
                        <i class="fas fa-table"></i>
                        <i class="fas fa-sitemap"></i>
                        <i class="fas fa-cube"></i>
                        <i class="fas fa-video"></i>
                    </div>
                    <div class="image-caption">Week 5: Tables, Charts, SmartArt, 3D Models, and Media Integration</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Design, format, and sort professional tables that clearly present structured data</li>
                    <li>Create, link, and customize charts from Excel to visualize trends and comparisons</li>
                    <li>Build and manipulate complex hierarchical and matrix diagrams using advanced SmartArt techniques</li>
                    <li>Insert, animate, and seamlessly integrate 3D models into your narrative</li>
                    <li>Embed, trim, and configure playback options for audio, video, and screen recordings</li>
                    <li>Combine multiple object types on a single slide to create a comprehensive, interactive dashboard</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-300 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Insert and format tables</li>
                        <li>Create and modify charts</li>
                        <li>Link charts to Excel data</li>
                        <li>Modify chart elements</li>
                        <li>Create SmartArt graphics</li>
                    </ul>
                    <ul>
                        <li>Modify SmartArt graphics</li>
                        <li>Insert 3D models</li>
                        <li>Insert and format media</li>
                        <li>Configure media playback</li>
                        <li>Insert screen recordings</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Table Design -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-table"></i> 1. Professional Table Design & Management
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Inserting Tables</h3>
                    <ul>
                        <li><strong>Insert Tab > Table:</strong> Choose grid size or use Insert Table dialog</li>
                        <li><strong>Drawing a Table:</strong> Create custom, irregular layouts with the pencil tool</li>
                        <li><strong>Excel Spreadsheet:</strong> Insert Tab > Table > Excel Spreadsheet for full Excel functionality within PowerPoint</li>
                        <li><strong>Keyboard Shortcut:</strong> Alt > N > T to insert a table</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-paint-brush"></i> Formatting for Clarity</h3>
                    <table class="table-demo">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Q3 Sales</th>
                                <th>Q4 Sales</th>
                                <th>Growth %</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Product A</td>
                                <td>$125,000</td>
                                <td>$150,000</td>
                                <td>20%</td>
                                <td>Strong holiday sales</td>
                            </tr>
                            <tr>
                                <td>Product B</td>
                                <td>$98,000</td>
                                <td>$115,000</td>
                                <td>17%</td>
                                <td>New marketing campaign</td>
                            </tr>
                            <tr>
                                <td>Product C</td>
                                <td>$75,000</td>
                                <td>$85,000</td>
                                <td>13%</td>
                                <td>Steady growth</td>
                            </tr>
                            <tr>
                                <td>Product D</td>
                                <td>$210,000</td>
                                <td>$225,000</td>
                                <td>7%</td>
                                <td>Market saturation</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p style="margin-top: 15px;"><strong>Table Design & Layout Tabs:</strong></p>
                    <ul>
                        <li><strong>Table Styles:</strong> Apply built-in styles with banded rows, header row emphasis</li>
                        <li><strong>Custom Shading & Borders:</strong> Use Borders and Shading for precise control</li>
                        <li><strong>Alignment & Cell Margins:</strong> Control text position within cells (Layout Tab)</li>
                        <li><strong>Sorting Data:</strong> Select column → Layout Tab > Sort (A-Z, Z-A, numerically)</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> Table Manipulation</h3>
                    <ul>
                        <li><strong>Adding/Deleting Rows & Columns:</strong> Use Layout Tab or right-click context menu</li>
                        <li><strong>Merging & Splitting Cells:</strong> Create custom header layouts and complex structures</li>
                        <li><strong>Distributing Rows/Columns:</strong> Ensure even sizing for a clean, professional look</li>
                        <li><strong>Table Size & Alignment:</strong> Precisely control dimensions and position on slide</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Chart Creation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i> 2. Dynamic Chart Creation & Customization
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Inserting Charts</h3>
                    <ul>
                        <li><strong>Insert Tab > Chart:</strong> Choose from Column, Line, Pie, Bar, Area, Combo, and more</li>
                        <li><strong>Chart Data Sheet:</strong> Embedded Excel worksheet opens for data entry</li>
                        <li><strong>Keyboard Shortcut:</strong> Alt > N > C to insert a chart</li>
                        <li><strong>Recommended Charts:</strong> PowerPoint suggests chart types based on your data</li>
                    </ul>

                    <div class="chart-types">
                        <div class="chart-type" onclick="selectChartType(this)">
                            <div class="chart-icon">
                                <i class="fas fa-chart-column"></i>
                            </div>
                            <h4>Column Chart</h4>
                            <p style="font-size: 0.9rem; color: #666;">Compare values across categories</p>
                        </div>
                        <div class="chart-type" onclick="selectChartType(this)">
                            <div class="chart-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4>Line Chart</h4>
                            <p style="font-size: 0.9rem; color: #666;">Show trends over time</p>
                        </div>
                        <div class="chart-type" onclick="selectChartType(this)">
                            <div class="chart-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <h4>Pie Chart</h4>
                            <p style="font-size: 0.9rem; color: #666;">Show parts of a whole</p>
                        </div>
                        <div class="chart-type" onclick="selectChartType(this)">
                            <div class="chart-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h4>Bar Chart</h4>
                            <p style="font-size: 0.9rem; color: #666;">Compare values horizontally</p>
                        </div>
                        <div class="chart-type" onclick="selectChartType(this)">
                            <div class="chart-icon">
                                <i class="fas fa-project-diagram"></i>
                            </div>
                            <h4>Combo Chart</h4>
                            <p style="font-size: 0.9rem; color: #666;">Combine chart types</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-link"></i> Linking to Excel Data</h3>
                    <ul>
                        <li><strong>Paste Special > Paste Link:</strong> Copy data from Excel → Home Tab > Paste > Paste Special > Paste Link</li>
                        <li><strong>Dynamic Updates:</strong> Chart in PowerPoint updates automatically when source Excel file changes</li>
                        <li><strong>Break Link:</strong> If needed, break the connection to make chart static</li>
                        <li><strong>Edit Data:</strong> Right-click chart → Edit Data to modify linked data</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Advanced Chart Formatting</h3>
                    <div class="visualization-demo">
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <h4>Change Chart Type</h4>
                            <p>Switch between chart types (Column to Line, etc.)</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h4>Edit Data</h4>
                            <p>Re-open the chart data sheet</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-tags"></i>
                            </div>
                            <h4>Chart Elements</h4>
                            <p>Add/format titles, labels, legend, trendlines</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-palette"></i>
                            </div>
                            <h4>Chart Styles</h4>
                            <p>Apply aesthetic presets and color schemes</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Advanced SmartArt -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-sitemap"></i> 3. Advanced SmartArt for Complex Concepts
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-project-diagram"></i> Beyond Basic Diagrams</h3>
                    <ul>
                        <li><strong>Hierarchy:</strong> Organization Charts with multiple assistants and layouts</li>
                        <li><strong>Matrix:</strong> Show relationships within quadrants (2x2, 3x3 grids)</li>
                        <li><strong>Picture:</strong> Create photo layouts with captions and artistic effects</li>
                        <li><strong>Process:</strong> Multi-step workflows with branching and decision points</li>
                        <li><strong>Cycle:</strong> Continuous processes and recurring workflows</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-layer-group"></i> SmartArt Hierarchy Demonstration</h3>
                    <div class="hierarchy-demo">
                        <div class="hierarchy-level ceo">
                            <div class="level-label">CEO</div>
                            <div class="level-desc">Chief Executive Officer</div>
                        </div>
                        <div class="hierarchy-level vp">
                            <div class="level-label">VP Marketing & VP Sales</div>
                            <div class="level-desc">Vice Presidents (Direct Reports)</div>
                        </div>
                        <div class="hierarchy-level manager">
                            <div class="level-label">Sales Manager</div>
                            <div class="level-desc">Department Manager (Reports to VP Sales)</div>
                        </div>
                        <div class="hierarchy-level analyst">
                            <div class="level-label">Sales Analyst</div>
                            <div class="level-desc">Team Member (Reports to VP Sales)</div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-magic"></i> Advanced Techniques</h3>
                    <ul>
                        <li><strong>Promote/Demote Shapes:</strong> In Text Pane, use Tab (demote) and Shift+Tab (promote)</li>
                        <li><strong>Layout Options:</strong> Right-click shape → Change Layout for branch-specific formatting</li>
                        <li><strong>Resetting Graphic:</strong> SmartArt Design > Reset Graphic reverts formatting, keeps text</li>
                        <li><strong>Convert to Shapes:</strong> SmartArt Design > Convert to Shapes for ultimate control</li>
                        <li><strong>Add Shapes:</strong> Add shape before, after, above, below, or as assistant</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: 3D Models -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cube"></i> 4. Working with 3D Models
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Inserting 3D Models</h3>
                    <ul>
                        <li><strong>Stock 3D Library:</strong> Vast collection of anatomical, geometric, educational models</li>
                        <li><strong>From a File:</strong> Insert custom .glb, .fbx, .obj, .3mf, or .stl files</li>
                        <li><strong>Remix 3D:</strong> Access community-created 3D models (online)</li>
                        <li><strong>Keyboard Shortcut:</strong> Alt > N > M to insert 3D model</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-gamepad"></i> Controlling the 3D Scene</h3>
                    <div class="visualization-demo">
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-camera"></i>
                            </div>
                            <h4>Model Views</h4>
                            <p>Save specific angles for quick recall</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-search"></i>
                            </div>
                            <h4>Pan & Zoom</h4>
                            <p>Examine details of complex models</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-undo"></i>
                            </div>
                            <h4>Reset</h4>
                            <p>Return to default position and size</p>
                        </div>
                        <div class="viz-item">
                            <div class="viz-icon">
                                <i class="fas fa-sun"></i>
                            </div>
                            <h4>Scene Lighting</h4>
                            <p>Adjust lighting for dramatic effects</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-film"></i> Animating 3D Models</h3>
                    <ul>
                        <li><strong>Turntable Animation:</strong> Automatic rotation around vertical axis</li>
                        <li><strong>Arrive & Jump & Turn:</strong> Dramatic entry effects with 3D rotation</li>
                        <li><strong>Effect Options:</strong> Control direction, intensity, and duration</li>
                        <li><strong>Animation Pane:</strong> Fine-tune timing and sequencing</li>
                        <li><strong>Combine Animations:</strong> Layer multiple effects for complex movements</li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Advanced Media -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-film"></i> 5. Integrating Advanced Media
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-headphones"></i> Audio Deep Dive</h3>
                    <div class="media-types">
                        <div class="media-type audio">
                            <div class="media-icon">
                                <i class="fas fa-microphone"></i>
                            </div>
                            <h4>Record Audio</h4>
                            <p>Insert Tab > Media > Audio > Record Audio</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">For narration or sound bites</p>
                        </div>
                        <div class="media-type audio">
                            <div class="media-icon">
                                <i class="fas fa-cut"></i>
                            </div>
                            <h4>Trim Audio</h4>
                            <p>Playback Tab > Trim Audio</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Remove unwanted sections</p>
                        </div>
                        <div class="media-type audio">
                            <div class="media-icon">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                            <h4>Fade In/Out</h4>
                            <p>Set fade duration in Playback Tab</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Smooth audio transitions</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-video"></i> Video Mastery</h3>
                    <div class="media-types">
                        <div class="media-type video">
                            <div class="media-icon">
                                <i class="fas fa-image"></i>
                            </div>
                            <h4>Poster Frame</h4>
                            <p>Set custom thumbnail image</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">From file or video frame</p>
                        </div>
                        <div class="media-type video">
                            <div class="media-icon">
                                <i class="fas fa-bookmark"></i>
                            </div>
                            <h4>Playback Bookmarks</h4>
                            <p>Add markers in timeline</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Trigger animations or jumps</p>
                        </div>
                        <div class="media-type video">
                            <div class="media-icon">
                                <i class="fas fa-paint-brush"></i>
                            </div>
                            <h4>Video Format</h4>
                            <p>Apply corrections and styles</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Frames, shadows, reflections</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-desktop"></i> Screen Recording</h3>
                    <div class="media-types">
                        <div class="media-type screen">
                            <div class="media-icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <h4>Select Area</h4>
                            <p>Drag to select screen portion</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Record specific window or area</p>
                        </div>
                        <div class="media-type screen">
                            <div class="media-icon">
                                <i class="fas fa-record-vinyl"></i>
                            </div>
                            <h4>Record</h4>
                            <p>Capture microphone/system audio</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Full or partial screen capture</p>
                        </div>
                        <div class="media-type screen">
                            <div class="media-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h4>Editing</h4>
                            <p>Trim and format recorded clip</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Embedded as video for editing</p>
                        </div>
                    </div>
                    <p style="margin-top: 15px;"><strong>Keyboard Shortcut:</strong> Alt > N > U to start screen recording</p>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 6. Keyboard Shortcuts Cheat Sheet
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
                            <td><span class="shortcut-key">Alt > N > C</span></td>
                            <td>Insert Chart</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > T</span></td>
                            <td>Insert Table</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > M</span></td>
                            <td>Insert 3D Model</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > U</span></td>
                            <td>Screen Recording</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + C / Ctrl + Alt + V</span></td>
                            <td>Copy / Paste Special</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + F10</span></td>
                            <td>Show Selection Pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, C, E</span></td>
                            <td>Edit Chart Data (chart selected)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, T, L</span></td>
                            <td>Table Layout Tab (in table)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + D</span></td>
                            <td>Duplicate selected object</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + G</span></td>
                            <td>Group selected objects</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + G</span></td>
                            <td>Ungroup objects</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F4</span></td>
                            <td>Repeat last action</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, S, A</span></td>
                            <td>Open SmartArt Text Pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, D, C</span></td>
                            <td>Open Chart Design Tab</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 7. Hands-On Exercise: Quarterly Business Review Dashboard
                </div>
                <p><strong>Activity:</strong> Create an Interactive "Quarterly Business Review" Dashboard Slide</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #6a1b9a; margin-bottom: 15px;">Dashboard Preview:</h4>
                    <div class="dashboard-preview">
                        <div class="dashboard-item table">
                            <h4><i class="fas fa-table"></i> Sales Table</h4>
                            <p>Product performance data with sorting</p>
                        </div>
                        <div class="dashboard-item chart">
                            <h4><i class="fas fa-chart-bar"></i> Column Chart</h4>
                            <p>Quarterly sales comparison visualization</p>
                        </div>
                        <div class="dashboard-item smartart">
                            <h4><i class="fas fa-sitemap"></i> Org Chart</h4>
                            <p>Company hierarchy with advanced layout</p>
                        </div>
                        <div class="dashboard-item model">
                            <h4><i class="fas fa-cube"></i> 3D Model</h4>
                            <p>Animated 3D graph showing growth</p>
                        </div>
                    </div>
                </div>

                <div style="margin: 20px 0;">
                    <h4 style="color: #6a1b9a; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Set Up & Insert a Table:</strong>
                            <ul>
                                <li>New slide with Title Only layout → Title: "Q4 Performance Dashboard"</li>
                                <li>Insert 3x5 table with headers: Product, Q3 Sales, Q4 Sales, Growth %, Notes</li>
                                <li>Fill with sample data and sort by "Q4 Sales" descending</li>
                                <li>Apply Table Style with banded rows, bold header</li>
                            </ul>
                        </li>
                        <li><strong>Create a Linked Column Chart:</strong>
                            <ul>
                                <li>Below table, insert Clustered Column Chart</li>
                                <li>Enter table data into chart data sheet</li>
                                <li>Add Data Labels and Title "Quarterly Sales Comparison"</li>
                                <li>Format Q4 bars with gradient fill</li>
                            </ul>
                        </li>
                        <li><strong>Build Advanced SmartArt Hierarchy:</strong>
                            <ul>
                                <li>Insert Hierarchy SmartArt (Organization Chart)</li>
                                <li>Create structure: CEO → VP Marketing, VP Sales → Sales Manager</li>
                                <li>Add "Sales Analyst" under VP Sales</li>
                                <li>Apply 3D SmartArt Style and custom colors</li>
                            </ul>
                        </li>
                        <li><strong>Insert and Animate 3D Model:</strong>
                            <ul>
                                <li>Insert 3D Model from Stock Library (graph shape)</li>
                                <li>Resize and position in corner</li>
                                <li>Apply Turntable animation → Effect Options: "Number of Spins: 1"</li>
                            </ul>
                        </li>
                        <li><strong>Embed Screen Recording:</strong>
                            <ul>
                                <li>Insert > Screen Recording → Select small desktop area</li>
                                <li>Record 3 seconds → Trim to 2 seconds</li>
                                <li>Set custom Poster Frame, check "Play Full Screen"</li>
                            </ul>
                        </li>
                        <li><strong>Final Polish & Accessibility:</strong>
                            <ul>
                                <li>Align table, chart, and SmartArt neatly</li>
                                <li>Group table, chart, and SmartArt (Ctrl+G)</li>
                                <li>Add Alt Text to chart and 3D model</li>
                                <li>Test in Slide Show mode (Shift+F5)</li>
                                <li>Save as YourName_Data_Dashboard_WK5.pptx</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <button class="download-btn" onclick="downloadDashboardTemplate()">
                    <i class="fas fa-download"></i> Download Dashboard Template
                </button>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Chart Data Sheet</strong>
                    <p>The mini-Excel worksheet that opens when creating or editing a chart, holding the chart's underlying numerical data.</p>
                </div>

                <div class="term">
                    <strong>Paste Link</strong>
                    <p>A paste method that creates a dynamic connection between pasted content (like a chart) and its source file (like an Excel workbook), enabling automatic updates.</p>
                </div>

                <div class="term">
                    <strong>Hierarchy SmartArt</strong>
                    <p>A diagram type specifically designed to show reporting relationships and organizational structures, such as an organization chart.</p>
                </div>

                <div class="term">
                    <strong>Poster Frame</strong>
                    <p>A custom, static image that represents a video or screen recording on a slide before it is played, acting as a preview thumbnail.</p>
                </div>

                <div class="term">
                    <strong>Screen Recording</strong>
                    <p>A feature that captures video of actions on your computer screen, which can be embedded directly into a slide for demonstrations.</p>
                </div>

                <div class="term">
                    <strong>Turntable Animation</strong>
                    <p>A 3D animation effect that makes a 3D model rotate around its vertical axis, often used for product demonstrations.</p>
                </div>

                <div class="term">
                    <strong>Banded Rows</strong>
                    <p>A table formatting option that applies alternating background colors to rows, improving readability of large data sets.</p>
                </div>

                <div class="term">
                    <strong>Combo Chart</strong>
                    <p>A chart that combines two or more chart types (e.g., column and line) to visualize different data series in a single view.</p>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 9. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Test your understanding:</h4>
                    <ol>
                        <li>You have a complex Excel financial model. You need a chart in PowerPoint that updates automatically when the Excel file is revised. What specific paste method must you use?</li>
                        <li>Your organization chart requires a "dotted-line" reporting relationship. After converting SmartArt to shapes, what formatting tool would you use to change a connector line to dashes?</li>
                        <li>You want a 3D model of a product to spin once slowly as you introduce it on a slide. Which animation and which effect option should you apply?</li>
                        <li>You've inserted a 2-minute video but only need to show a 30-second clip. What two Playback Tab features allow you to accomplish this?</li>
                        <li>You need to present regional sales data, showing both total revenue per region (bars) and profit margin percentage (line) on the same chart. What chart type should you insert and customize?</li>
                    </ol>
                </div>

                <button class="download-btn" onclick="showAnswers()" style="background: #4caf50;">
                    <i class="fas fa-lightbulb"></i> Show Answers
                </button>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Let Charts Tell the Story:</strong> Choose the chart type that matches your message: trends over time (Line), comparisons (Column/Bar), parts of a whole (Pie), or relationships (Scatter).</li>
                    <li><strong>Simplify for Impact:</strong> Remove default clutter from charts. Eliminate unnecessary gridlines, use direct data labels instead of a legend where possible, and ensure all text is legible.</li>
                    <li><strong>Maintain Data Integrity:</strong> Never stretch or distort a chart disproportionately, as it misrepresents the data. Always keep axes properly scaled.</li>
                    <li><strong>Pre-Record for Perfection:</strong> Use screen recordings and video trims to ensure live demos or complex explanations are flawless and consistent during your actual presentation.</li>
                    <li><strong>Test Media Playback:</strong> Always test videos, audio, and screen recordings in Slide Show mode on the actual presentation computer to avoid codec or link issues.</li>
                    <li><strong>Use Alt Text for Accessibility:</strong> Add descriptive alternative text to all charts, tables, and media for screen reader users.</li>
                    <li><strong>Group Related Objects:</strong> Use Ctrl+G to group tables, charts, and diagrams that belong together for easier manipulation.</li>
                    <li><strong>Master the Selection Pane:</strong> Use Alt+F10 to manage objects on complex slides, especially when creating interactive dashboards.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/create-a-chart-in-powerpoint-ecbbc2e6-7f5e-4c6b-8557-7c8d4ce6bae3" target="_blank">Microsoft: Create a Chart in PowerPoint</a></li>
                    <li><a href="https://support.microsoft.com/office/insert-a-3d-model-in-powerpoint-ec5feb79-b0af-47d6-b6a7-52b1c4b5fc6f" target="_blank">Microsoft: Insert a 3D Model in PowerPoint</a></li>
                    <li><a href="https://support.microsoft.com/office/insert-a-screen-recording-3c5b33c3-91d8-4a35-8a25-97e924c8f6d4" target="_blank">Microsoft: Insert a Screen Recording</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-smartart-graphic-from-scratch-7118a6c2-6e91-41e8-8d15-3c47a29d6a6f" target="_blank">Microsoft: Create SmartArt Graphics</a></li>
                    <li><a href="https://powerpoint.office.com/templates/charts/" target="_blank">Chart Templates Gallery</a></li>
                    <li><strong>Practice files and sample dashboards</strong> available in the Course Portal</li>
                    <li><strong>Interactive Chart Simulator</strong> for hands-on practice</li>
                    <li><strong>Week 5 Quiz</strong> to test your data visualization skills (available in portal)</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 6: Collaboration, Review, and Final Polish</strong></p>
                <p>We shift from creation to refinement and sharing. You will master:</p>
                <ul>
                    <li>Collaboration tools: Comments and Co-authoring</li>
                    <li>Review tab: Proofing and language tools</li>
                    <li>Comparing and merging presentation versions</li>
                    <li>Creating custom slide shows</li>
                    <li>Presenter View and rehearsal tools</li>
                    <li>Securing and preparing final outputs</li>
                    <li>Exporting to different formats</li>
                    <li>Presentation delivery best practices</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Get ready to finalize and present your masterpiece with confidence!</strong></p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week5.php">Week 5 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft PowerPoint Help:</strong> <a href="https://support.microsoft.com/powerpoint" target="_blank">Official Support</a></li>
                    <li><strong>Data Visualization Community:</strong> <a href="https://techcommunity.microsoft.com/t5/powerpoint/ct-p/PowerPoint" target="_blank">Microsoft Tech Community</a></li>
                </ul>
            </div>

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <button class="download-btn" onclick="printHandout()" style="margin-right: 15px;">
                    <i class="fas fa-print"></i> Print Handout
                </button>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week5_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 5 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 5 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-300 PowerPoint Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
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

        // Template download
        function downloadDashboardTemplate() {
            alert('Quarterly Business Review Dashboard template would download. This is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/templates/week5_dashboard_template.potx';
        }

        // Chart type selection
        function selectChartType(element) {
            const charts = document.querySelectorAll('.chart-type');
            charts.forEach(chart => chart.classList.remove('active'));
            element.classList.add('active');
            
            const chartName = element.querySelector('h4').textContent;
            const chartDesc = element.querySelector('p').textContent;
            
            const useCases = {
                'Column Chart': 'Best for comparing values across different categories.',
                'Line Chart': 'Ideal for showing trends over time.',
                'Pie Chart': 'Perfect for displaying parts of a whole.',
                'Bar Chart': 'Great for comparing values horizontally.',
                'Combo Chart': 'Excellent for showing different data series with different chart types.'
            };
            
            alert(`${chartName}\n\n${chartDesc}\n\n${useCases[chartName]}`);
        }

        // Show self-review answers
        function showAnswers() {
            const answers = [
                "1. Use 'Paste Special > Paste Link' when pasting from Excel to create a dynamic link.",
                "2. After converting to shapes, select the connector line, go to Format Shape > Line Style, and change Dash Type to your preferred dotted/dashed style.",
                "3. Apply the 'Turntable' animation, then in Effect Options set 'Number of Spins' to 1 and adjust timing for slow rotation.",
                "4. Use 'Trim Video' to set start and end points, and 'Fade Duration' for smooth transitions.",
                "5. Insert a 'Combo Chart' (Clustered Column - Line on Secondary Axis), then customize it to show revenue as columns and profit margin as a line."
            ];
            
            alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
        }

        // Interactive demonstrations
        function demonstrateTableSorting() {
            const steps = [
                "Table Sorting Demonstration:",
                "1. Click anywhere inside your table",
                "2. Go to Table Layout Tab",
                "3. Click 'Sort' button",
                "4. In Sort dialog:",
                "   • Select column to sort by",
                "   • Choose Ascending (A-Z, 0-9) or Descending",
                "   • Add secondary sort levels if needed",
                "5. Click OK to apply",
                "\nTip: Header row is automatically excluded from sorting."
            ];
            alert(steps.join("\n"));
        }

        function demonstrateChartLinking() {
            const steps = [
                "Chart Linking to Excel:",
                "1. In Excel: Select and copy your data range",
                "2. In PowerPoint: Go to Home Tab",
                "3. Click Paste dropdown → Paste Special",
                "4. Select 'Paste Link'",
                "5. Choose appropriate format (usually Excel Chart Object)",
                "6. Click OK",
                "\nNow when you update Excel, refresh the chart in PowerPoint:",
                "Right-click chart → Update Link or Edit Data → Refresh Data"
            ];
            alert(steps.join("\n"));
        }

        function demonstrate3DAnimation() {
            const steps = [
                "3D Model Animation:",
                "1. Select your 3D model",
                "2. Go to Animations Tab",
                "3. Choose 'Turntable' from emphasis effects",
                "4. Click Effect Options:",
                "   • Direction: Clockwise/Counterclockwise",
                "   • Amount: Custom degrees or spins",
                "5. In Animation Pane:",
                "   • Set start: On Click, With Previous, After Previous",
                "   • Adjust duration for slow/fast rotation",
                "6. Preview with Slide Show (Shift+F5)"
            ];
            alert(steps.join("\n"));
        }

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5EYXRhIFZpc3VhbGl6YXRpb24gRGVtbzwvdGV4dD48L3N2Zz4=';
                };
            });

            // Interactive elements
            const vizItems = document.querySelectorAll('.viz-item');
            vizItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    alert(`${title}\n\n${description}\n\nTry this feature in PowerPoint!`);
                });
            });

            const mediaTypes = document.querySelectorAll('.media-type');
            mediaTypes.forEach(type => {
                type.addEventListener('click', function() {
                    const typeName = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    const details = this.querySelector('p:last-child').textContent;
                    alert(`${typeName}\n\n${description}\n\n${details}`);
                });
            });
        });

        // Track handout access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('PowerPoint Week 5 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
            }
        });

        // Keyboard shortcuts practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'n': 'Insert Chart (Alt > N > C)',
                't': 'Insert Table (Alt > N > T)',
                'm': 'Insert 3D Model (Alt > N > M)',
                'u': 'Screen Recording (Alt > N > U)',
                'd': 'Duplicate Object (Ctrl + D)',
                'g': 'Group Objects (Ctrl + G)'
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
                shortcutAlert.textContent = `PowerPoint Shortcut: ${shortcuts[e.key]}`;
                document.body.appendChild(shortcutAlert);
                
                setTimeout(() => {
                    shortcutAlert.remove();
                }, 2000);
            }
            
            // Alt key combinations (simplified)
            if (e.altKey && e.key === 'n') {
                setTimeout(() => {
                    alert('Alt+N opens the Insert tab menu. Then press:\n• C for Chart\n• T for Table\n• M for 3D Model\n• U for Screen Recording');
                }, 100);
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
            
            @keyframes spin {
                from { transform: rotateY(0deg); }
                to { transform: rotateY(360deg); }
            }
            
            .spinning {
                animation: spin 3s linear infinite;
                display: inline-block;
            }
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveElements = document.querySelectorAll('button, a, .viz-item, .media-type, .chart-type, .dashboard-item');
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

        // Dashboard preview interaction
        function previewDashboardItem(type) {
            const items = {
                'table': 'Professional Table Design\n• Sortable data\n• Banded rows for readability\n• Custom formatting options',
                'chart': 'Interactive Chart\n• Linked to Excel data\n• Customizable elements\n• Professional styling',
                'smartart': 'Advanced SmartArt\n• Organizational hierarchy\n• Custom layouts\n• 3D effects',
                'model': '3D Model Integration\n• Stock or custom models\n• Turntable animation\n• Interactive controls'
            };
            
            alert(items[type]);
        }

        // Initialize dashboard click handlers
        document.addEventListener('DOMContentLoaded', function() {
            const dashboardItems = document.querySelectorAll('.dashboard-item');
            dashboardItems.forEach(item => {
                const type = Array.from(item.classList).find(cls => 
                    cls === 'table' || cls === 'chart' || cls === 'smartart' || cls === 'model'
                );
                if (type) {
                    item.addEventListener('click', () => previewDashboardItem(type));
                }
            });
        });

        // SmartArt promotion/demotion demonstration
        function demonstrateSmartArtHierarchy() {
            const steps = [
                "SmartArt Hierarchy Controls:",
                "1. Select your SmartArt graphic",
                "2. Open Text Pane (click arrow on left or Alt, J, S, A)",
                "3. To demote a shape (make it a child):",
                "   • Place cursor in text",
                "   • Press Tab key",
                "4. To promote a shape (move up a level):",
                "   • Place cursor in text",
                "   • Press Shift+Tab",
                "5. Add shapes:",
                "   • Select existing shape",
                "   • SmartArt Design > Add Shape",
                "   • Choose: After, Before, Above, Below, or Assistant"
            ];
            alert(steps.join("\n"));
        }

        // Screen recording tips
        function demonstrateScreenRecording() {
            const tips = [
                "Screen Recording Tips:",
                "1. Plan your recording:",
                "   • Close unnecessary applications",
                "   • Prepare your script/demonstration",
                "   • Test microphone audio",
                "2. Recording options:",
                "   • Record Pointer: Shows mouse movements",
                "   • Audio: System sound and/or microphone",
                "3. During recording:",
                "   • Pause/Stop with floating toolbar",
                "   • Keep movements smooth",
                "   • Speak clearly if narrating",
                "4. After recording:",
                "   • Trim to remove mistakes",
                "   • Set poster frame for thumbnail",
                "   • Adjust playback options"
            ];
            alert(tips.join("\n"));
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
    $viewer = new PowerPointWeek5HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>