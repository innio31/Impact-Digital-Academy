<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week1_view.php

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
 * PowerPoint Week 1 Handout Viewer Class with PDF Download
 */
class PowerPointWeek1HandoutViewer
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
            
            // Try different mPDF class names
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
            $mpdf->SetTitle('Week 1: Introduction to PowerPoint & Presentation Management');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Introduction, Interface, Presentation, Master Slides');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week1_Introduction_' . date('Y-m-d') . '.pdf';
            
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
                Week 1: Introduction to PowerPoint & Presentation Management
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 1!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we'll demystify the core environment of Microsoft PowerPoint. You will learn to confidently navigate the interface, create and manage presentations from the ground up, and control the global settings that define how your presentations look and are distributed. By the end of this session, you will have the essential skills to build a structured presentation, customize its fundamental design, and prepare it for both digital and physical delivery.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Identify and utilize the key components of the PowerPoint 2019 interface</li>
                    <li>Create, save, and protect new presentations in various formats (.pptx, .pdf)</li>
                    <li>Efficiently navigate and manage slides using different presentation views</li>
                    <li>Modify core presentation properties, including slide size and orientation</li>
                    <li>Customize the Slide Master, Handout Master, and Notes Master</li>
                    <li>Configure print settings to produce effective handouts and notes pages</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. The PowerPoint 2019 Interface & Core Views</h3>
                <ul>
                    <li><strong>Quick Access Toolbar:</strong> Customizable bar for your most-used commands</li>
                    <li><strong>The Ribbon:</strong> Primary command center organized into tabs (Home, Insert, Design, etc.)</li>
                    <li><strong>Slide Navigation Pane (Thumbnail View):</strong> Left-hand pane to view, select, and reorder slides</li>
                    <li><strong>Slide Area:</strong> The main workspace where you design the selected slide</li>
                    <li><strong>Notes Pane:</strong> Located below the Slide Area, for adding speaker notes</li>
                    <li><strong>Essential Views:</strong>
                        <ul>
                            <li><strong>Normal View:</strong> Default editing view</li>
                            <li><strong>Slide Sorter View:</strong> Bird's-eye view for organizing slides</li>
                            <li><strong>Notes Page View:</strong> View to see how notes will look when printed</li>
                            <li><strong>Reading View & Slide Show:</strong> For presenting and previewing</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Creating, Saving, and Protecting Presentations</h3>
                <ul>
                    <li><strong>Creating a New Presentation:</strong>
                        <ul>
                            <li>Start with a <strong>blank presentation</strong> or choose from a <strong>template</strong></li>
                            <li><strong>Keyboard Shortcut:</strong> Ctrl + N for a new blank presentation</li>
                        </ul>
                    </li>
                    <li><strong>Saving and File Formats:</strong>
                        <ul>
                            <li><strong>Save As:</strong> Choose location and format</li>
                            <li>.pptx – Standard PowerPoint presentation</li>
                            <li>.pdf – Fixed-layout format for easy, universal sharing</li>
                            <li>.potx – PowerPoint template file</li>
                            <li><strong>Keyboard Shortcut:</strong> Ctrl + S to save quickly</li>
                        </ul>
                    </li>
                    <li><strong>Protecting a Presentation:</strong>
                        <ul>
                            <li>File → Info → Protect Presentation</li>
                            <li>Add a password to <strong>encrypt</strong> the file</li>
                            <li>Restrict editing or mark as final</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Presentation Properties & Setup</h3>
                <ul>
                    <li><strong>Slide Size (Design → Customize → Slide Size):</strong>
                        <ul>
                            <li><strong>Standard (4:3):</strong> For older monitors/projectors</li>
                            <li><strong>Widescreen (16:9):</strong> Default for modern displays</li>
                            <li><strong>Custom Slide Size:</strong> Set precise dimensions for posters or banners</li>
                        </ul>
                    </li>
                    <li><strong>Slide Orientation:</strong> Set slides to <strong>Portrait</strong> or <strong>Landscape</strong></li>
                    <li><strong>Accessing File Properties (File → Info):</strong> View and edit details like Title, Author, Tags</li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. Mastering the Masters (View → Master Views)</h3>
                <ul>
                    <li><strong>Slide Master (View → Slide Master):</strong>
                        <ul>
                            <li>The <strong>top slide</strong> controls the overall theme (fonts, colors, background)</li>
                            <li><strong>Child layouts</strong> below it (Title Slide, Title and Content, etc.) can be customized</li>
                            <li><strong>Why it's critical:</strong> Changes here apply to <em>all</em> slides using that layout</li>
                            <li><strong>Key Uses:</strong> Set default fonts/colors, insert a logo on every slide</li>
                        </ul>
                    </li>
                    <li><strong>Handout Master (View → Handout Master):</strong>
                        <ul>
                            <li>Controls layout of printed handouts (1, 2, 3, 4, 6, or 9 slides per page)</li>
                            <li>Add headers, footers, page numbers, and dates</li>
                        </ul>
                    </li>
                    <li><strong>Notes Master (View → Notes Master):</strong>
                        <ul>
                            <li>Controls layout of printed speaker notes pages</li>
                            <li>Format notes area, slide image box, headers, and footers</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">5. Configuring Print Settings (File → Print)</h3>
                <ul>
                    <li><strong>Print Preview:</strong> Always preview before printing</li>
                    <li><strong>Key Print Settings:</strong>
                        <ul>
                            <li><strong>Printer & Copies:</strong> Select printer and number of copies</li>
                            <li><strong>Slides:</strong> Specify a range (e.g., 1, 3, 5-8)</li>
                            <li><strong>Print Layout (Full Page Slides):</strong> The crucial dropdown menu</li>
                            <li><strong>Full Page Slides:</strong> One slide per page</li>
                            <li><strong>Notes Pages:</strong> One slide with its speaker notes per page</li>
                            <li><strong>Handouts:</strong> Choose 1, 2, 3, 4, 6, or 9 slides per page</li>
                            <li><strong>Color Settings:</strong>
                                <ul>
                                    <li><strong>Color:</strong> Full color printing</li>
                                    <li><strong>Grayscale:</strong> Converts colors to shades of gray</li>
                                    <li><strong>Pure Black and White:</strong> No gray, only black and white</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Hands-On Exercise: Build, Customize, and Prepare a Company Overview</h3>
                <p><strong>Objective:</strong> Create a professional presentation with consistent design and proper print settings.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Open PowerPoint and create a new <strong>blank presentation</strong> (Ctrl + N)</li>
                    <li>Set slide size to <strong>Widescreen (16:9)</strong></li>
                    <li>Save as <strong>YourName_CompanyOverview_WK1.pptx</strong></li>
                    <li>Open <strong>Slide Master</strong> and customize fonts and colors</li>
                    <li>Add your logo to the parent Slide Master</li>
                    <li>Create 3 slides: Title slide, Company Values, and Future Plans</li>
                    <li>Customize the <strong>Handout Master</strong> with headers and footers</li>
                    <li>Print handouts in <strong>Grayscale</strong> with 4 slides per page</li>
                    <li>Protect the presentation with a password</li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Essential Shortcuts for Week 1</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + N</td>
                            <td style="padding: 6px 8px;">New presentation</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + O</td>
                            <td style="padding: 6px 8px;">Open presentation</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + S</td>
                            <td style="padding: 6px 8px;">Save</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F12</td>
                            <td style="padding: 6px 8px;">Save As</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + P</td>
                            <td style="padding: 6px 8px;">Print</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + M</td>
                            <td style="padding: 6px 8px;">Insert new slide</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F5</td>
                            <td style="padding: 6px 8px;">Start slideshow from beginning</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + F5</td>
                            <td style="padding: 6px 8px;">Start slideshow from current slide</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + W, I</td>
                            <td style="padding: 6px 8px;">Switch to Slide Sorter View</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + W, L</td>
                            <td style="padding: 6px 8px;">Switch to Normal View</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt + W, P</td>
                            <td style="padding: 6px 8px;">Switch to Notes Page View</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Slide Master:</strong> The top slide in a hierarchy that stores global design information for the presentation.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Layout:</strong> A predefined arrangement of placeholders on a slide, controlled by the Slide Master.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Placeholder:</strong> A container on a slide layout that holds specific content (text, pictures, charts).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Handout Master:</strong> The template that defines the layout and formatting for printed handouts.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Notes Master:</strong> The template that defines the layout for printed speaker notes pages.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Grayscale Printing:</strong> A print mode that renders all colors in shades of gray.</p>
                </div>
                <div>
                    <p><strong>Slide Sorter View:</strong> A view that shows all slides as thumbnails for easy organization.</p>
                </div>
            </div>
            
            <!-- Weekly Homework -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Weekly Homework Assignment</h3>
                <ol>
                    <li><strong>Presentation Setup:</strong>
                        <ul>
                            <li>Create a new presentation with your personal brand colors</li>
                            <li>Set up Slide Master with consistent fonts and logo placement</li>
                            <li>Create custom layouts for title, content, and section slides</li>
                        </ul>
                    </li>
                    <li><strong>Print Configuration:</strong>
                        <ul>
                            <li>Design handouts for a 10-slide presentation</li>
                            <li>Configure Notes Master for speaker notes printing</li>
                            <li>Export presentation to PDF with different settings</li>
                        </ul>
                    </li>
                    <li><strong>Self-Quiz:</strong>
                        <ul>
                            <li>What is the main purpose of Slide Master?</li>
                            <li>How do you change slide size from Standard to Widescreen?</li>
                            <li>What keyboard shortcut starts a slideshow?</li>
                            <li>How do you protect a PowerPoint presentation?</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 15px;"><strong>Due Date:</strong> Submit your completed presentation via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.</p>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-300 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Navigate PowerPoint interface</li>
                    <li>Create and save presentations</li>
                    <li>Work with different views</li>
                    <li>Modify slide size and orientation</li>
                    <li>Customize Slide Master</li>
                    <li>Configure Handout Master</li>
                    <li>Set up Notes Master</li>
                    <li>Configure print settings</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Master the Masters:</strong> Spending 15 minutes understanding Slide Master saves hours of formatting.</li>
                    <li><strong>Plan Before You Build:</strong> Use Slide Sorter View to storyboard your presentation flow.</li>
                    <li><strong>Print Preview is Your Friend:</strong> Always check before printing to avoid wasted paper.</li>
                    <li><strong>Leverage Templates:</strong> Start with high-quality templates and modify the Slide Master.</li>
                    <li><strong>Use Consistent Design:</strong> Apply themes and layouts from Slide Master for professional results.</li>
                    <li><strong>Practice Shortcuts:</strong> Keyboard shortcuts dramatically increase efficiency.</li>
                    <li><strong>Save Versions:</strong> Use Save As to create different versions for different purposes.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 2, we'll cover:</p>
                <ul>
                    <li>Inserting and formatting text, shapes, and images</li>
                    <li>Working with SmartArt and icons</li>
                    <li>Basic animation techniques</li>
                    <li>Slide transitions and timing</li>
                    <li>Adding and formatting tables and charts</li>
                    <li>Using speaker notes effectively</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring content for a 5-slide presentation you'd like to enhance.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft PowerPoint Interface Guide</li>
                    <li>Slide Master Tutorial Videos</li>
                    <li>Print Settings and Handout Design Best Practices</li>
                    <li>Practice files and templates available in the Course Portal</li>
                </ul>
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
                Week 1 Handout: Introduction to PowerPoint & Presentation Management
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
            Week 1: PowerPoint Introduction & Presentation Management | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 1: Introduction to PowerPoint & Presentation Management - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #b71c1c 0%, #d32f2f 100%);
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
            background: linear-gradient(135deg, #d32f2f 0%, #b71c1c 100%);
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
            color: #d32f2f;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #d32f2f;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #b71c1c;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #d32f2f;
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
            background-color: #d32f2f;
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
            background-color: #ffebee;
        }

        .shortcut-key {
            background: #d32f2f;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exercise-box {
            background: #ffebee;
            border-left: 5px solid #d32f2f;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #b71c1c;
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
            background: #d32f2f;
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
            background: #b71c1c;
        }

        .learning-objectives {
            background: #ffebee;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #d32f2f;
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
            color: #d32f2f;
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
            color: #d32f2f;
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

        /* PowerPoint Layout Demo */
        .slide-layouts {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .slide-layout {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s;
        }

        .slide-layout:hover {
            border-color: #d32f2f;
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .layout-icon {
            font-size: 3rem;
            color: #d32f2f;
            margin-bottom: 10px;
        }

        .layout-preview {
            width: 100%;
            height: 150px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 10px 0;
            position: relative;
            overflow: hidden;
        }

        .layout-preview.title {
            background: linear-gradient(135deg, #d32f2f 20%, #f44336 100%);
        }

        .layout-preview.content {
            background: linear-gradient(135deg, #2196f3 20%, #64b5f6 100%);
        }

        .layout-preview.section {
            background: linear-gradient(135deg, #4caf50 20%, #81c784 100%);
        }

        .layout-preview.two-content {
            background: linear-gradient(135deg, #ff9800 20%, #ffb74d 100%);
        }

        /* Print Layout Cards */
        .print-layouts {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .print-layout {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
        }

        .print-layout.full {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .print-layout.notes {
            border-color: #2196f3;
            background: #e3f2fd;
        }

        .print-layout.handouts {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .print-layout.outline {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .print-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .print-layout.full .print-icon {
            color: #d32f2f;
        }

        .print-layout.notes .print-icon {
            color: #2196f3;
        }

        .print-layout.handouts .print-icon {
            color: #4caf50;
        }

        .print-layout.outline .print-icon {
            color: #9c27b0;
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

        .format-card.pptx {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .format-card.pdf {
            border-color: #f44336;
            background: #ffebee;
        }

        .format-card.potx {
            border-color: #e91e63;
            background: #fce4ec;
        }

        .format-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .format-card.pptx .format-icon {
            color: #d32f2f;
        }

        .format-card.pdf .format-icon {
            color: #f44336;
        }

        .format-card.potx .format-icon {
            color: #e91e63;
        }

        /* PowerPoint Views Demo */
        .views-demo {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .view-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-align: center;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-item:hover {
            background: #ffebee;
            border-color: #d32f2f;
            transform: translateY(-3px);
        }

        .view-item.active {
            background: #ffebee;
            border-color: #d32f2f;
            border-width: 2px;
        }

        .view-icon {
            font-size: 2rem;
            color: #d32f2f;
            margin-bottom: 10px;
        }

        /* Slide Master Hierarchy */
        .master-hierarchy {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 25px 0;
        }

        .master-level {
            width: 80%;
            padding: 20px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            position: relative;
        }

        .master-level.parent {
            background: #d32f2f;
            color: white;
            border-color: #b71c1c;
        }

        .master-level.child {
            background: #ffebee;
            border-color: #d32f2f;
            width: 70%;
        }

        .master-level.grandchild {
            background: #fce4ec;
            border-color: #e91e63;
            width: 60%;
        }

        .level-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .level-desc {
            font-size: 0.9rem;
            opacity: 0.9;
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

            .interface-demo,
            .slide-layouts,
            .print-layouts,
            .format-cards,
            .views-demo {
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
                <strong>Access Granted:</strong> PowerPoint Week 1 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week2_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 2
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 1 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Introduction to PowerPoint & Presentation Management</div>
            <div class="week-tag">Week 1 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 1!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we'll demystify the core environment of Microsoft PowerPoint. You will learn to confidently navigate the interface, create and manage presentations from the ground up, and control the global settings that define how your presentations look and are distributed. These skills are the critical bedrock for all advanced slide creation and design work to come.
                </p>

                <div class="image-container">
                    <img src="images/powerpoint_interface.png"
                        alt="Microsoft PowerPoint Interface"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+TWljcm9zb2Z0IFBvd2VyUG9pbnQgSW50ZXJmYWNlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Microsoft PowerPoint 2019 Interface Overview</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Identify and utilize the key components of the PowerPoint 2019 interface</li>
                    <li>Create, save, and protect new presentations in various formats (.pptx, .pdf)</li>
                    <li>Efficiently navigate and manage slides using different presentation views</li>
                    <li>Modify core presentation properties, including slide size and orientation</li>
                    <li>Customize the Slide Master, Handout Master, and Notes Master</li>
                    <li>Configure print settings to produce effective handouts and notes pages</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-300 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Navigate PowerPoint interface</li>
                        <li>Create and save presentations</li>
                        <li>Work with different views</li>
                        <li>Modify slide size and orientation</li>
                    </ul>
                    <ul>
                        <li>Customize Slide Master</li>
                        <li>Configure Handout Master</li>
                        <li>Set up Notes Master</li>
                        <li>Configure print settings</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: PowerPoint Interface -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-desktop"></i> 1. Understanding the PowerPoint Interface
                </div>

                <div class="interface-demo">
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <h4>Quick Access Toolbar</h4>
                        <p>Customizable bar for your most-used commands (Save, Undo, Redo)</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-bars"></i>
                        </div>
                        <h4>Ribbon</h4>
                        <p>Tabs (Home, Insert, Design, Transitions) with grouped commands</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-th-large"></i>
                        </div>
                        <h4>Slide Pane</h4>
                        <p>Thumbnail view of all slides for easy navigation and organization</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-sticky-note"></i>
                        </div>
                        <h4>Notes Pane</h4>
                        <p>Area below slide for adding speaker notes and presenter comments</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eye"></i> Essential PowerPoint Views</h3>
                    <div class="views-demo">
                        <div class="view-item" onclick="selectView(this)">
                            <div class="view-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h4>Normal View</h4>
                            <p style="font-size: 0.9rem; color: #666;">Default editing view</p>
                            <div style="font-size: 0.8rem; color: #888; margin-top: 5px;">Alt + W, L</div>
                        </div>
                        <div class="view-item" onclick="selectView(this)">
                            <div class="view-icon">
                                <i class="fas fa-th"></i>
                            </div>
                            <h4>Slide Sorter</h4>
                            <p style="font-size: 0.9rem; color: #666;">Organize slides as thumbnails</p>
                            <div style="font-size: 0.8rem; color: #888; margin-top: 5px;">Alt + W, I</div>
                        </div>
                        <div class="view-item" onclick="selectView(this)">
                            <div class="view-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <h4>Notes Page</h4>
                            <p style="font-size: 0.9rem; color: #666;">View and edit speaker notes</p>
                            <div style="font-size: 0.8rem; color: #888; margin-top: 5px;">Alt + W, P</div>
                        </div>
                        <div class="view-item" onclick="selectView(this)">
                            <div class="view-icon">
                                <i class="fas fa-play"></i>
                            </div>
                            <h4>Slide Show</h4>
                            <p style="font-size: 0.9rem; color: #666;">Present your slides full screen</p>
                            <div style="font-size: 0.8rem; color: #888; margin-top: 5px;">F5</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Presentation Operations -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file"></i> 2. Creating, Saving, and Protecting Presentations
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus"></i> Creating New Presentations</h3>
                    <ul>
                        <li>Start with a <strong>blank presentation</strong> or choose from <strong>templates</strong></li>
                        <li><strong>Keyboard Shortcut:</strong> Ctrl + N for new blank presentation</li>
                        <li>Templates available: Business, Education, Portfolio, Marketing</li>
                        <li>Recent templates appear for quick access</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-export"></i> File Formats Overview</h3>
                    <div class="format-cards">
                        <div class="format-card pptx">
                            <div class="format-icon">
                                <i class="fas fa-file-powerpoint"></i>
                            </div>
                            <h4>.pptx</h4>
                            <p>Standard PowerPoint format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Default, modern format</p>
                        </div>
                        <div class="format-card pdf">
                            <div class="format-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <h4>.pdf</h4>
                            <p>Portable Document Format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Universal sharing</p>
                        </div>
                        <div class="format-card potx">
                            <div class="format-icon">
                                <i class="fas fa-copy"></i>
                            </div>
                            <h4>.potx</h4>
                            <p>PowerPoint Template</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Custom template format</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lock"></i> Protecting Presentations</h3>
                    <ul>
                        <li>Go to: <strong>File → Info → Protect Presentation</strong></li>
                        <li><strong>Encrypt with Password:</strong> Requires password to open</li>
                        <li><strong>Mark as Final:</strong> Makes presentation read-only</li>
                        <li><strong>Restrict Access:</strong> Control who can view or edit</li>
                        <li><strong>Digital Signature:</strong> Add digital certification</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Presentation Setup -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cogs"></i> 3. Presentation Properties & Setup
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-expand-alt"></i> Slide Size and Orientation</h3>
                    <ul>
                        <li><strong>Design → Customize → Slide Size → Custom Slide Size</strong></li>
                        <li><strong>Standard (4:3):</strong> 10" x 7.5" - Legacy projectors</li>
                        <li><strong>Widescreen (16:9):</strong> 13.333" x 7.5" - Modern displays</li>
                        <li><strong>Custom:</strong> Set exact dimensions for banners, posters</li>
                        <li><strong>Orientation:</strong> Portrait or Landscape for slides/notes</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/slide_size.png"
                            alt="PowerPoint Slide Size Options"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5TbGlkZSBTaXplIFNldHRpbmdzPC90ZXh0Pjwvc3ZnPg='">
                        <div class="image-caption">Slide Size dialog with Standard and Widescreen options</div>
                    </div>
                </div>
            </div>

            <!-- Section 4: Mastering the Masters -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-chess-queen"></i> 4. Mastering the Masters
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-layer-group"></i> Understanding Master Hierarchy</h3>
                    <div class="master-hierarchy">
                        <div class="master-level parent">
                            <div class="level-label">Slide Master (Parent)</div>
                            <div class="level-desc">Controls overall theme, fonts, colors, background</div>
                        </div>
                        <div class="master-level child">
                            <div class="level-label">Layout Masters (Children)</div>
                            <div class="level-desc">Title Slide, Title and Content, Two Content, etc.</div>
                        </div>
                        <div class="master-level grandchild">
                            <div class="level-label">Individual Slides</div>
                            <div class="level-desc">Specific content based on layout</div>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Slide Master Customization</h3>
                    <ul>
                        <li>Access: <strong>View → Slide Master</strong></li>
                        <li>Modify <strong>parent master</strong> to change all slides</li>
                        <li>Customize <strong>layout masters</strong> for specific slide types</li>
                        <li>Insert <strong>placeholders</strong> for consistent content positioning</li>
                        <li>Add <strong>logos, headers, footers</strong> to appear on all slides</li>
                        <li>Set default <strong>fonts, colors, effects</strong></li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-print"></i> Handout Master & Notes Master</h3>
                    <ul>
                        <li><strong>Handout Master (View → Handout Master):</strong>
                            <ul>
                                <li>Controls printed handout layout</li>
                                <li>Choose 1, 2, 3, 4, 6, or 9 slides per page</li>
                                <li>Add headers, footers, page numbers, dates</li>
                            </ul>
                        </li>
                        <li><strong>Notes Master (View → Notes Master):</strong>
                            <ul>
                                <li>Controls printed speaker notes layout</li>
                                <li>Adjust slide image size and position</li>
                                <li>Format notes area and headers/footers</li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Print Configuration -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-print"></i> 5. Configuring Print Settings
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-print"></i> Print Layout Options</h3>
                    <div class="print-layouts">
                        <div class="print-layout full">
                            <div class="print-icon">
                                <i class="fas fa-file-powerpoint"></i>
                            </div>
                            <h4>Full Page Slides</h4>
                            <p>One slide per page</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">For high-quality prints</p>
                        </div>
                        <div class="print-layout notes">
                            <div class="print-icon">
                                <i class="fas fa-sticky-note"></i>
                            </div>
                            <h4>Notes Pages</h4>
                            <p>Slide + speaker notes</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Presenter reference</p>
                        </div>
                        <div class="print-layout handouts">
                            <div class="print-icon">
                                <i class="fas fa-copy"></i>
                            </div>
                            <h4>Handouts</h4>
                            <p>Multiple slides per page</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Audience materials</p>
                        </div>
                        <div class="print-layout outline">
                            <div class="print-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <h4>Outline</h4>
                            <p>Text content only</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Content structure</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-palette"></i> Color Settings for Printing</h3>
                    <ul>
                        <li><strong>Color:</strong> Full color printing (uses color ink/toner)</li>
                        <li><strong>Grayscale:</strong> Converts colors to shades of gray</li>
                        <li><strong>Pure Black and White:</strong> No grays, only black and white</li>
                        <li><strong>Why use Grayscale:</strong>
                            <ul>
                                <li>Saves color ink/toner</li>
                                <li>Better readability for some printers</li>
                                <li>Professional appearance for drafts</li>
                                <li>Faster printing</li>
                            </ul>
                        </li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Print Tips
                        </div>
                        <ul>
                            <li>Always use <strong>Print Preview</strong> before printing</li>
                            <li>For handouts, consider <strong>Frame Slides</strong> option</li>
                            <li>Use <strong>Scale to Fit Paper</strong> to avoid cropping</li>
                            <li>Select specific slides: <strong>Slides:</strong> 1,3,5-8</li>
                            <li>Print hidden slides if needed for reference</li>
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
                            <td>New presentation</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + O</span></td>
                            <td>Open presentation</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F12</span></td>
                            <td>Save As</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Print</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + M</span></td>
                            <td>Insert new slide</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F5</span></td>
                            <td>Start slideshow from beginning</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + F5</span></td>
                            <td>Start slideshow from current slide</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W, I</span></td>
                            <td>Switch to Slide Sorter View</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W, L</span></td>
                            <td>Switch to Normal View</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W, P</span></td>
                            <td>Switch to Notes Page View</td>
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
                            <td><span class="shortcut-key">Ctrl + F</span></td>
                            <td>Find</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Esc</span></td>
                            <td>Exit slideshow</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">B</span> or <span class="shortcut-key">.</span></td>
                            <td>Black screen during slideshow</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 7. Hands-On Exercise: Company Overview Presentation
                </div>
                <p><strong>Objective:</strong> Build a professional presentation with consistent design and proper print settings.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #b71c1c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open PowerPoint and create a <strong>new blank presentation</strong> (Ctrl + N)</li>
                        <li>Set slide size to <strong>Widescreen (16:9)</strong> (Design → Slide Size)</li>
                        <li>Save as <strong>YourName_CompanyOverview_WK1.pptx</strong></li>
                        <li>Open <strong>Slide Master</strong> (View → Slide Master)</li>
                        <li>On parent master: Set company colors and fonts</li>
                        <li>Add your logo to the bottom-right corner</li>
                        <li>Create 3 slides:
                            <ul>
                                <li><strong>Slide 1:</strong> Title Slide with company name</li>
                                <li><strong>Slide 2:</strong> Company Values (use bullet points)</li>
                                <li><strong>Slide 3:</strong> Future Plans (use content placeholder)</li>
                            </ul>
                        </li>
                        <li>Open <strong>Handout Master</strong> and add header/footer</li>
                        <li>Print handouts in <strong>Grayscale</strong> with 4 slides per page</li>
                        <li>Protect presentation with password "test123"</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="PowerPoint Presentation Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Qb3dlclBvaW50IFByZXNlbnRhdGlvbiBFeGVyY2lzZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Create Your First Professional PowerPoint Presentation</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Company Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Slide Master</strong>
                    <p>The top slide in a hierarchy of slides that stores global design information for the entire presentation. Changes here affect all slides.</p>
                </div>

                <div class="term">
                    <strong>Layout</strong>
                    <p>A predefined arrangement of placeholders (for titles, text, charts, etc.) on a slide. Controlled by the Slide Master.</p>
                </div>

                <div class="term">
                    <strong>Placeholder</strong>
                    <p>A container on a slide layout that holds specific content (text, pictures, charts, SmartArt).</p>
                </div>

                <div class="term">
                    <strong>Handout Master</strong>
                    <p>The template that defines the layout and formatting for printed handouts (1, 2, 3, 4, 6, or 9 slides per page).</p>
                </div>

                <div class="term">
                    <strong>Notes Master</strong>
                    <p>The template that defines the layout for printed speaker notes pages (slide image + notes area).</p>
                </div>

                <div class="term">
                    <strong>Grayscale Printing</strong>
                    <p>A print mode that renders all colors in shades of gray, useful for clear, ink-saving drafts and handouts.</p>
                </div>

                <div class="term">
                    <strong>Slide Sorter View</strong>
                    <p>A view that shows all slides as thumbnails for easy organization, sequencing, and applying transitions.</p>
                </div>

                <div class="term">
                    <strong>Normal View</strong>
                    <p>The default editing view in PowerPoint with slide pane, notes pane, and slide thumbnails.</p>
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
                        <li><strong>Presentation Setup:</strong>
                            <ul>
                                <li>Create a new presentation with your personal brand colors</li>
                                <li>Set up Slide Master with consistent fonts and logo placement</li>
                                <li>Create three custom layouts: Title, Content, and Section Header</li>
                                <li>Apply these layouts to create a 5-slide presentation</li>
                            </ul>
                        </li>
                        <li><strong>Print Configuration:</strong>
                            <ul>
                                <li>Design handouts for your 5-slide presentation</li>
                                <li>Configure Notes Master for speaker notes printing</li>
                                <li>Export presentation to PDF with different settings:
                                    <ul>
                                        <li>Full slides as PDF</li>
                                        <li>Handouts (3 per page) as PDF</li>
                                        <li>Notes pages as PDF</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <li><strong>Self-Quiz:</strong>
                            <ul>
                                <li>What is the main purpose of Slide Master?</li>
                                <li>How do you change slide size from Standard to Widescreen?</li>
                                <li>What keyboard shortcut starts a slideshow from the beginning?</li>
                                <li>How do you protect a PowerPoint presentation with a password?</li>
                                <li>What are the three color settings available when printing?</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Submit your <strong>PersonalBrand.pptx</strong> and the three PDF files via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Master the Masters:</strong> Spending 15 minutes understanding Slide Master will save you hours of tedious slide-by-slide formatting.</li>
                    <li><strong>Plan Before You Build:</strong> Use Slide Sorter View or Outline View to storyboard your presentation's flow before diving into design.</li>
                    <li><strong>Print Preview is Your Friend:</strong> Always check Print Preview before sending to a printer to avoid wasted paper and ink.</li>
                    <li><strong>Leverage Templates:</strong> For real-world projects, start with a high-quality template and modify its Slide Master to suit your brand.</li>
                    <li><strong>Use Consistent Design:</strong> Apply themes and layouts from Slide Master for professional, cohesive results.</li>
                    <li><strong>Practice Shortcuts:</strong> Keyboard shortcuts dramatically increase efficiency over time.</li>
                    <li><strong>Save Versions:</strong> Use Save As to create different versions for different purposes (presentation, handouts, notes).</li>
                    <li><strong>Think About Your Audience:</strong> Design handouts and notes that complement your presentation style.</li>
                    <li><strong>Backup Your Work:</strong> Save presentations to cloud storage for automatic versioning and recovery.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/powerpoint" target="_blank">Microsoft PowerPoint Official Support</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-presentation-in-powerpoint-422250f8-5721-4cea-92cc-202fa7b89617" target="_blank">Create a Presentation in PowerPoint Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/what-is-a-slide-master-b1e726c5-7f5e-4b5e-b1ed-6c8f8a6efbb8" target="_blank">Slide Master Overview and Tutorial</a></li>
                    <li><a href="https://support.microsoft.com/office/print-your-powerpoint-slides-handouts-or-notes-194d4320-aa03-478b-9300-df25f0d15dc4" target="_blank">Printing Slides, Handouts, and Notes Guide</a></li>
                    <li><a href="https://powerpoint.office.com/templates/" target="_blank">Microsoft PowerPoint Templates Gallery</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal</li>
                    <li><strong>Interactive PowerPoint Simulator</strong> for hands-on practice</li>
                    <li><strong>Week 1 Quiz</strong> to test your understanding (available in portal)</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 2: Slide Design & Content Creation</strong></p>
                <p>In Week 2, we'll dive into the heart of slide creation! You'll learn to:</p>
                <ul>
                    <li>Insert and format text, shapes, and images professionally</li>
                    <li>Work with SmartArt diagrams and icons</li>
                    <li>Apply basic animation techniques and transitions</li>
                    <li>Control slide timing and rehearsal</li>
                    <li>Add and format tables and charts for data visualization</li>
                    <li>Use speaker notes effectively for presentations</li>
                    <li>Apply themes and variants for consistent design</li>
                    <li>Work with text boxes and placeholders</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring content for a 5-slide presentation you'd like to design and enhance.</p>
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
                    <li><strong>Office Hours:</strong> Mondays & Wednesdays, 3:00 PM - 5:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week1.php">Week 1 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft PowerPoint Help:</strong> <a href="https://support.microsoft.com/powerpoint" target="_blank">Official Support</a></li>
                    <li><strong>PowerPoint Community:</strong> <a href="https://techcommunity.microsoft.com/t5/powerpoint/ct-p/PowerPoint" target="_blank">Microsoft Tech Community</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week1_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 1 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 1 Handout</p>
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

        // Simulate template download
        function downloadTemplate() {
            alert('Company presentation template would download. This is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/templates/week1_company_template.potx';
        }

        // View selection
        function selectView(element) {
            const views = document.querySelectorAll('.view-item');
            views.forEach(view => view.classList.remove('active'));
            element.classList.add('active');
            
            const viewName = element.querySelector('h4').textContent;
            alert(`Switched to ${viewName} view.\n\nShortcut: ${element.querySelector('div:last-child').textContent}`);
        }

        // Slide Master demonstration
        function demonstrateSlideMaster() {
            const steps = [
                "Slide Master Walkthrough:",
                "1. Go to View → Slide Master",
                "2. Parent master (top slide) controls ALL slides",
                "3. Child layouts (below) control specific slide types",
                "4. Changes to parent affect all child layouts",
                "5. Changes to child affect only that layout type",
                "\nTry: Add a logo to parent master → See it appear on all layouts!"
            ];
            alert(steps.join("\n"));
        }

        // Print settings demonstration
        function demonstratePrintSettings() {
            const settings = [
                "Print Settings Overview:",
                "File → Print or Ctrl + P",
                "\nKey Settings:",
                "• Printer: Select your printer",
                "• Slides: Specify range (1,3,5-8)",
                "• Print Layout: Choose from:",
                "  - Full Page Slides",
                "  - Notes Pages",
                "  - Handouts (1-9 per page)",
                "  - Outline",
                "• Color: Color, Grayscale, or Pure B&W",
                "• Other: Frame slides, Scale to fit, High quality"
            ];
            alert(settings.join("\n"));
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
                console.log('PowerPoint Week 1 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Slide Master controls the overall design and formatting of all slides in a presentation.",
                    "2. Design → Customize → Slide Size → Choose Widescreen (16:9).",
                    "3. F5 starts a slideshow from the beginning.",
                    "4. File → Info → Protect Presentation → Encrypt with Password.",
                    "5. Color, Grayscale, and Pure Black and White."
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
                    alert(`${title}\n\n${description}\n\nTry exploring this feature in PowerPoint!`);
                });
            });

            // Format cards interaction
            const formatCards = document.querySelectorAll('.format-card');
            formatCards.forEach(card => {
                card.addEventListener('click', function() {
                    const format = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:last-child').textContent;
                    const useCases = {
                        '.pptx': 'Standard PowerPoint presentations with all features',
                        '.pdf': 'Universal sharing, printing, archiving',
                        '.potx': 'Custom templates for consistent presentation creation'
                    };
                    alert(`${format} Format\n\n${description}\n\nBest for: ${useCases[format]}`);
                });
            });

            // Print layout interaction
            const printLayouts = document.querySelectorAll('.print-layout');
            printLayouts.forEach(layout => {
                layout.addEventListener('click', function() {
                    const layoutName = this.querySelector('h4').textContent;
                    const description = this.querySelector('p:last-child').textContent;
                    const whenToUse = {
                        'Full Page Slides': 'High-quality prints, formal presentations, archival',
                        'Notes Pages': 'Presenter reference, speaker notes, rehearsal',
                        'Handouts': 'Audience materials, workshop handouts, references',
                        'Outline': 'Content review, structure checking, text-only reference'
                    };
                    alert(`${layoutName}\n\n${description}\n\nWhen to use: ${whenToUse[layoutName]}`);
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'n': 'New Presentation (Ctrl + N)',
                'o': 'Open Presentation (Ctrl + O)',
                's': 'Save (Ctrl + S)',
                'p': 'Print (Ctrl + P)',
                'm': 'New Slide (Ctrl + M)'
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
            
            // F5 key simulation
            if (e.key === 'F5') {
                e.preventDefault();
                alert('F5: Start slideshow from beginning\n\nUse Shift+F5 to start from current slide.');
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
            const interactiveElements = document.querySelectorAll('a, button, .interface-item, .format-card, .print-layout, .view-item');
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

        // Slide size demonstration
        function demonstrateSlideSize() {
            const comparison = [
                "Slide Size Comparison:",
                "\nStandard (4:3):",
                "• Ratio: 4:3 (1.33:1)",
                "• Dimensions: 10\" x 7.5\"",
                "• Best for: Older projectors, some conference rooms",
                "\nWidescreen (16:9):",
                "• Ratio: 16:9 (1.78:1)",
                "• Dimensions: 13.333\" x 7.5\"",
                "• Best for: Modern displays, HD projectors, laptops",
                "\nCustom: Set exact dimensions for posters, banners, or special displays."
            ];
            alert(comparison.join("\n"));
        }

        // Protection options demonstration
        function demonstrateProtection() {
            const options = [
                "Presentation Protection Options:",
                "\n1. Encrypt with Password:",
                "• Requires password to open file",
                "• Use strong, memorable passwords",
                "\n2. Mark as Final:",
                "• Makes presentation read-only",
                "• Shows 'Marked as Final' warning",
                "\n3. Restrict Access:",
                "• Control who can view/edit",
                "• Requires rights management",
                "\n4. Digital Signature:",
                "• Adds digital certification",
                "• Verifies authenticity"
            ];
            alert(options.join("\n"));
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
    $viewer = new PowerPointWeek1HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>