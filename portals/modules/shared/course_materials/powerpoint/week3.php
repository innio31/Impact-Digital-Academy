<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week3_view.php

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
 * PowerPoint Week 3 Handout Viewer Class with PDF Download
 */
class PowerPointWeek3HandoutViewer
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
            $mpdf->SetTitle('Week 3: Advanced Formatting & Interactive Linking');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Advanced Formatting, Hyperlinks, Zoom Links, Interactive Presentations');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week3_Advanced_Formatting_' . date('Y-m-d') . '.pdf';
            
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
                Week 3: Advanced Formatting & Interactive Linking
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 3!</h2>
                <p style="margin-bottom: 15px;">
                    Your presentation now has a strong, well-organized structure from Week 2. This week, we transform that structure into a polished, professional, and interactive experience. We move beyond basic text to master typography and list hierarchy, then unlock PowerPoint's powerful interactive features. By mastering text formatting, columns, and—most importantly—creating seamless navigational links, you'll learn how to build non-linear, audience-driven presentations that are both elegant and engaging. This is where your slides become truly dynamic.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Apply advanced text formatting and create multi-column text layouts for sophisticated content presentation.</li>
                    <li>Create and customize multi-level bulleted and numbered lists to clearly communicate hierarchy and processes.</li>
                    <li>Insert and format standard hyperlinks to connect to web pages, files, or email addresses.</li>
                    <li>Implement advanced interactive navigation using Zoom Links (Summary, Section, and Slide Zoom) to create dynamic, non-linear presentation flows.</li>
                    <li>Distinguish between the use cases for hyperlinks versus Zoom links for optimal user experience.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. Advanced Text Formatting & Columns</h3>
                <ul>
                    <li><strong>Beyond the Basics (Home Tab):</strong>
                        <ul>
                            <li><strong>Character Spacing (Kerning):</strong> Adjust spacing between letters for better readability in titles (Font dialog > Advanced).</li>
                            <li><strong>Text Effects & Typography:</strong> Apply shadows, reflections, and convert text to WordArt for stylized headlines.</li>
                        </ul>
                    </li>
                    <li><strong>Creating Columns within a Text Box:</strong>
                        <ul>
                            <li>Select your text box, then go to <strong>Home tab > Paragraph group > Columns</strong>.</li>
                            <li>Choose <strong>Two or Three columns</strong>, or select <strong>More Columns</strong> for custom width and spacing.</li>
                            <li><strong>Perfect For:</strong> Lists, side-by-side comparisons, or magazine-style layouts on a single slide.</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Professional Lists: Bulleted & Numbered</h3>
                <ul>
                    <li><strong>Creating Multi-Level Lists:</strong>
                        <ul>
                            <li>Type your list, then use <strong>Tab</strong> to demote an item (create a sub-point) and <strong>Shift + Tab</strong> to promote it.</li>
                            <li>Define the formatting for each level separately via <strong>Home > Paragraph > Bullets > Bullets and Numbering</strong>.</li>
                        </ul>
                    </li>
                    <li><strong>Customizing List Appearance:</strong>
                        <ul>
                            <li><strong>Change Bullet Symbol:</strong> Use a custom image, icon, or symbol.</li>
                            <li><strong>Change Numbering Format:</strong> Use 1), A., i., etc., and set the starting number.</li>
                            <li><strong>Adjust Indentation & Alignment:</strong> Use the ruler (View > Show > Ruler) for precise control over list positioning.</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Creating Connections: Hyperlinks</h3>
                <ul>
                    <li><strong>Inserting a Hyperlink (Ctrl + K):</strong>
                        <ul>
                            <li><strong>Link to:</strong> Existing File or Web Page, Place in This Document, Create New Document, E-mail Address.</li>
                            <li><strong>Text to Display:</strong> The clickable text on your slide.</li>
                            <li><strong>ScreenTip:</strong> Custom text that appears when hovering (improves accessibility).</li>
                        </ul>
                    </li>
                    <li><strong>Best Practices:</strong>
                        <ul>
                            <li>Use descriptive text (e.g., "Download the Full Report" instead of "Click Here").</li>
                            <li>For in-presentation links, use "Place in This Document" to jump to a specific slide.</li>
                            <li>Test ALL links before presenting.</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. Interactive Navigation: Zoom Links (The Game-Changer)</h3>
                <p>Zoom Links create interactive, digestible, and visually appealing menus that let you jump between slides or sections fluidly during a presentation.</p>
                <ul>
                    <li><strong>Insert Tab > Zoom:</strong>
                        <ul>
                            <li><strong>Summary Zoom:</strong> Creates a landing page with thumbnails of selected slides. Click any thumbnail to zoom to that slide, then return automatically.
                                <ul>
                                    <li><strong>How:</strong> Insert > Zoom > Summary Zoom. Select the "section header" or key slides you want to feature.</li>
                                    <li><strong>Use Case:</strong> A dynamic, clickable agenda or table of contents.</li>
                                </ul>
                            </li>
                            <li><strong>Section Zoom:</strong> Inserts a live thumbnail link to a Section you created (Week 2 skill!). Click to zoom into that entire section, navigate through it, then return to the origin slide.
                                <ul>
                                    <li><strong>How:</strong> Insert > Zoom > Section Zoom. Choose a Section from your presentation.</li>
                                    <li><strong>Use Case:</strong> Deep dives. "Would you like details on Finance, Marketing, or R&D?" Click the Marketing thumbnail to jump to that entire section.</li>
                                </ul>
                            </li>
                            <li><strong>Slide Zoom:</strong> Inserts a live thumbnail link to a specific slide. Click to zoom directly to that slide, then return.
                                <ul>
                                    <li><strong>How:</strong> Insert > Zoom > Slide Zoom. Choose individual slides.</li>
                                    <li><strong>Use Case:</strong> An appendix or reference slide. "As mentioned, here is our detailed timeline..." (click to zoom to it).</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li><strong>Formatting Zoom Links:</strong> Select the Zoom object and use the Zoom Format tab to change the frame style, add visual effects, or set a default return path.</li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">5. When to Use What: Hyperlink vs. Zoom</h3>
                <ul>
                    <li><strong>Use a Hyperlink (Ctrl+K) for:</strong>
                        <ul>
                            <li>Linking to external resources (websites, documents, emails).</li>
                            <li>Simple "jump to slide" navigation in a straightforward, linear deck.</li>
                            <li>Text-based navigation within a content slide.</li>
                        </ul>
                    </li>
                    <li><strong>Use a Zoom Link for:</strong>
                        <ul>
                            <li>Creating a visual, menu-style interface within your presentation.</li>
                            <li>Building non-linear, choose-your-own-path presentations.</li>
                            <li>Providing a clear, visual overview of your structure (Summary Zoom).</li>
                            <li>Managing complex presentations with distinct modules or sections.</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <!-- Step-by-Step Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <h4 style="color: #b71c1c;">Activity: Build an Interactive Product Launch Deck</h4>
                <p><strong>Apply your formatting and linking skills to create a dynamic, user-guided presentation.</strong></p>
                
                <ol>
                    <li><strong>Set Up & Format Text:</strong>
                        <ul>
                            <li>Open a new presentation. Apply the "Slice" theme.</li>
                            <li>On Slide 1, add a title and create a subheading text box with your company name.</li>
                            <li>Format the subheading: change the Font Color and apply a subtle Text Shadow (Text Effects).</li>
                            <li>On Slide 2, add a title "Product Overview" and a text box with a 3-bullet list describing key features.</li>
                            <li>Convert this text box into Two Columns (Home > Paragraph > Columns).</li>
                        </ul>
                    </li>
                    <li><strong>Create a Multi-Level List:</strong>
                        <ul>
                            <li>On Slide 3, title it "Technical Specifications."</li>
                            <li>Create a Numbered List for main categories (e.g., 1. Performance, 2. Design).</li>
                            <li>Press Tab under "1. Performance" to create sub-bullets for details (CPU, RAM, etc.).</li>
                            <li>Customize the sub-bullets to a different symbol via Bullets > Bullets and Numbering.</li>
                        </ul>
                    </li>
                    <li><strong>Insert Standard Hyperlinks:</strong>
                        <ul>
                            <li>On Slide 4, title it "Learn More & Contact."</li>
                            <li>Add text: "Email our team for a custom quote."</li>
                            <li>Select the text, press Ctrl+K, choose E-mail Address, and enter a dummy email. Add a ScreenTip: "Opens your default email client."</li>
                        </ul>
                    </li>
                    <li><strong>Build Interactive Zoom Links:</strong>
                        <ul>
                            <li>First, create structure: Add three new slides (5, 6, 7) with titles: "Deep Dive: Performance," "Deep Dive: Design," "Deep Dive: Support." Group these into a new Section called Deep_Dives (Right-click before Slide 5 > Add Section).</li>
                            <li><strong>Create a Summary Zoom (Your Interactive Menu):</strong>
                                <ul>
                                    <li>Go to Slide 2. Insert > Zoom > Summary Zoom.</li>
                                    <li>In the dialog, select the checkboxes for Slide 1 (Cover), Slide 3 (Specs), Slide 4 (Contact), and the Deep_Dives Section. Click Insert.</li>
                                    <li>A new "Summary Zoom" slide is created with thumbnails. Move it to be Slide 2. Format the thumbnails using the Zoom Format tab (try a "Soft Edge Rectangle").</li>
                                </ul>
                            </li>
                            <li><strong>Add a Section Zoom:</strong>
                                <ul>
                                    <li>On Slide 2, also insert a Section Zoom for the Deep_Dives section. Position it neatly. This gives you two ways to navigate to the same content.</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li><strong>Test & Present:</strong>
                        <ul>
                            <li>Enter Slide Show mode (F5).</li>
                            <li>On the Summary Zoom slide (Slide 2), click on the Deep_Dives Section thumbnail. It should zoom into that section.</li>
                            <li>Navigate through the deep dive slides, then press Esc to return to the Summary Zoom.</li>
                            <li>Test your email hyperlink on Slide 4 (it will prompt to open an email client; you can cancel).</li>
                        </ul>
                    </li>
                    <li><strong>Save your work as YourName_Interactive_Product_Launch_WK3.pptx.</strong></li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet (Week 3 Focus)</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 30%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + K</td>
                            <td style="padding: 6px 8px;">Insert a hyperlink</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Tab / Shift + Tab</td>
                            <td style="padding: 6px 8px;">Demote / Promote list item (in a text box)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > Z > S</td>
                            <td style="padding: 6px 8px;">Insert Summary Zoom</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > Z > C</td>
                            <td style="padding: 6px 8px;">Insert Section Zoom</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > Z > L</td>
                            <td style="padding: 6px 8px;">Insert Slide Zoom</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > H > 0</td>
                            <td style="padding: 6px 8px;">Add numbered list</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > H > U</td>
                            <td style="padding: 6px 8px;">Add bulleted list</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > J, D, A</td>
                            <td style="padding: 6px 8px;">Open the Zoom Format tab (when a Zoom is selected)</td>
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
                            <td style="padding: 6px 8px;">Ctrl + M</td>
                            <td style="padding: 6px 8px;">Insert new slide</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Summary Zoom:</strong> An interactive table of contents slide that zooms to selected slides or sections.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Section Zoom:</strong> A live link that zooms into and presents an entire, pre-defined section of slides.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Slide Zoom:</strong> A live link that zooms directly to a single, specific slide.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Multi-Level List:</strong> A list with a hierarchical structure, using indentation and different bullet/number styles for each level.</p>
                </div>
                <div>
                    <p><strong>ScreenTip:</strong> The informational text that appears when a user hovers the pointer over a hyperlink.</p>
                </div>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>You want to create a magazine-style layout with text flowing side-by-side on a slide. What feature do you use, and where is it found?</li>
                    <li>What is the keyboard action to create a sub-bullet (a lower-level item) in a list while typing?</li>
                    <li>Describe the primary visual and functional difference between using a Summary Zoom and simply linking text to other slides with standard hyperlinks.</li>
                    <li>When inserting a Section Zoom, what prerequisite must you have already completed in your presentation (a skill from Week 2)?</li>
                    <li>You want to create a clickable menu that shows thumbnails of only your three key case study slides. Which type of Zoom link is most appropriate?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Design for Interaction:</strong> When using Zooms, think like a web designer. Your Zoom slide is a homepage. Keep it clean, visually balanced, and intuitive.</li>
                    <li><strong>Consistency is Key:</strong> Format all your Zoom thumbnails similarly (same border, effect, size) for a professional look.</li>
                    <li><strong>Practice the Flow:</strong> Rehearse navigating with Zoom links until it's second nature. Know how to enter and exit zooms smoothly during a live talk.</li>
                    <li><strong>Hyperlinks for External, Zooms for Internal:</strong> This simple rule will guide you to the right tool. Use Zoom features to keep your audience inside your presentation experience.</li>
                    <li><strong>Accessibility Note:</strong> While Zooms are visually engaging, ensure you also verbally describe navigation options for attendees using screen readers.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p><strong>Week 4: Mastering Visual Storytelling with Graphics & Media</strong></p>
                <p>In Week 4, we'll make your interactive decks visually stunning. You'll master working with images, icons, and 3D models, learn to create and customize SmartArt graphics for conceptual diagrams, and finally, embed and control video and audio seamlessly. Prepare to engage all senses in your presentations!</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft PowerPoint Zoom Feature Guide</li>
                    <li>Advanced Text Formatting Tutorial Videos</li>
                    <li>Interactive Presentation Design Best Practices</li>
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
                Week 3 Handout: Advanced Formatting & Interactive Linking
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
            Week 3: Advanced Formatting & Interactive Linking | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 3: Advanced Formatting & Interactive Linking - Impact Digital Academy</title>
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

        /* Text Formatting Demo */
        .formatting-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .formatting-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .formatting-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .formatting-icon {
            font-size: 3rem;
            color: #d32f2f;
            margin-bottom: 15px;
        }

        /* Zoom Types */
        .zoom-types {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .zoom-card {
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            transition: all 0.3s;
        }

        .zoom-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .zoom-card.summary {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .zoom-card.section {
            border-color: #1976d2;
            background: #e3f2fd;
        }

        .zoom-card.slide {
            border-color: #4caf50;
            background: #e8f5e9;
        }

        .zoom-icon {
            font-size: 2.5rem;
            color: #d32f2f;
            margin-bottom: 15px;
        }

        .zoom-card.summary .zoom-icon {
            color: #d32f2f;
        }

        .zoom-card.section .zoom-icon {
            color: #1976d2;
        }

        .zoom-card.slide .zoom-icon {
            color: #4caf50;
        }

        /* Text Column Demo */
        .column-demo {
            display: flex;
            gap: 20px;
            margin: 25px 0;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
        }

        .column {
            flex: 1;
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        /* List Demo */
        .list-demo {
            margin: 25px 0;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
        }

        .list-level {
            padding-left: 20px;
            margin-bottom: 10px;
        }

        .level-1 {
            font-weight: bold;
            color: #d32f2f;
        }

        .level-2 {
            padding-left: 40px;
            color: #666;
        }

        .level-3 {
            padding-left: 60px;
            color: #999;
            font-style: italic;
        }

        /* Link Types */
        .link-types {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .link-card {
            flex: 1;
            min-width: 200px;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
        }

        .link-card.hyperlink {
            border-color: #ff9800;
            background: #fff3e0;
        }

        .link-card.zoom {
            border-color: #9c27b0;
            background: #f3e5f5;
        }

        .link-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .link-card.hyperlink .link-icon {
            color: #ff9800;
        }

        .link-card.zoom .link-icon {
            color: #9c27b0;
        }

        /* Comparison Table */
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .comparison-table th {
            background-color: #d32f2f;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .comparison-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .comparison-table tr:nth-child(even) {
            background-color: #f9f9f9;
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

            .shortcut-table {
                font-size: 0.9rem;
            }

            .shortcut-table th,
            .shortcut-table td {
                padding: 10px;
            }

            .formatting-demo,
            .zoom-types,
            .link-types {
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
                <strong>Access Granted:</strong> PowerPoint Week 3 Handout
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
                <i class="fas fa-arrow-left"></i> Week 2
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week4_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 4
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 3 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Advanced Formatting & Interactive Linking</div>
            <div class="week-tag">Week 3 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 3!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    Your presentation now has a strong, well-organized structure from Week 2. This week, we transform that structure into a polished, professional, and interactive experience. We move beyond basic text to master typography and list hierarchy, then unlock PowerPoint's powerful interactive features. By mastering text formatting, columns, and—most importantly—creating seamless navigational links, you'll learn how to build non-linear, audience-driven presentations that are both elegant and engaging. This is where your slides become truly dynamic.
                </p>

                <div class="image-container">
                    <img src="images/interactive_powerpoint.png"
                        alt="Interactive PowerPoint Presentation">
                    <div class="image-caption">Interactive PowerPoint Presentation with Zoom Links</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Apply advanced text formatting and create multi-column text layouts for sophisticated content presentation.</li>
                    <li>Create and customize multi-level bulleted and numbered lists to clearly communicate hierarchy and processes.</li>
                    <li>Insert and format standard hyperlinks to connect to web pages, files, or email addresses.</li>
                    <li>Implement advanced interactive navigation using Zoom Links (Summary, Section, and Slide Zoom) to create dynamic, non-linear presentation flows.</li>
                    <li>Distinguish between the use cases for hyperlinks versus Zoom links for optimal user experience.</li>
                </ul>
            </div>

            <!-- Section 1: Advanced Text Formatting -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-text-height"></i> 1. Advanced Text Formatting & Columns
                </div>

                <div class="formatting-demo">
                    <div class="formatting-item">
                        <div class="formatting-icon">
                            <i class="fas fa-columns"></i>
                        </div>
                        <h4>Text Columns</h4>
                        <p>Create magazine-style layouts with multi-column text boxes for better content organization.</p>
                        <div style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                            <strong>Path:</strong> Home > Paragraph > Columns
                        </div>
                    </div>
                    <div class="formatting-item">
                        <div class="formatting-icon">
                            <i class="fas fa-font"></i>
                        </div>
                        <h4>Character Spacing</h4>
                        <p>Adjust kerning for professional typography in titles and headings.</p>
                        <div style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                            <strong>Path:</strong> Font Dialog > Advanced
                        </div>
                    </div>
                    <div class="formatting-item">
                        <div class="formatting-icon">
                            <i class="fas fa-bezier-curve"></i>
                        </div>
                        <h4>Text Effects</h4>
                        <p>Apply shadows, reflections, glow, and WordArt for stylized text.</p>
                        <div style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                            <strong>Path:</strong> Home > Font > Text Effects
                        </div>
                    </div>
                </div>

                <div class="column-demo">
                    <div class="column">
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Left Column</h4>
                        <p>This is an example of two-column text layout. Perfect for comparing features, listing benefits, or creating magazine-style designs.</p>
                        <ul>
                            <li>Column 1, Item 1</li>
                            <li>Column 1, Item 2</li>
                            <li>Column 1, Item 3</li>
                        </ul>
                    </div>
                    <div class="column">
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Right Column</h4>
                        <p>The second column provides balance and improves readability. Use this layout for side-by-side comparisons or when you have multiple related points.</p>
                        <ul>
                            <li>Column 2, Item 1</li>
                            <li>Column 2, Item 2</li>
                            <li>Column 2, Item 3</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Section 2: Professional Lists -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-list-ol"></i> 2. Professional Lists: Bulleted & Numbered
                </div>

                <div class="list-demo">
                    <h4 style="color: #d32f2f; margin-bottom: 15px;">Multi-Level List Example</h4>
                    <div class="list-level level-1">1. Main Category (Level 1 - Numbered)</div>
                    <div class="list-level level-2">• Sub-item A (Level 2 - Bulleted)</div>
                    <div class="list-level level-2">• Sub-item B (Level 2 - Bulleted)</div>
                    <div class="list-level level-3">◦ Detail 1 (Level 3 - Different bullet)</div>
                    <div class="list-level level-3">◦ Detail 2 (Level 3 - Different bullet)</div>
                    <div class="list-level level-1">2. Second Category (Level 1 - Numbered)</div>
                    <div class="list-level level-2">• Sub-item C (Level 2 - Bulleted)</div>
                </div>

                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i> List Shortcuts
                    </div>
                    <ul>
                        <li><strong>Tab:</strong> Demote list item (create sub-bullet)</li>
                        <li><strong>Shift + Tab:</strong> Promote list item</li>
                        <li><strong>Ctrl + Shift + L:</strong> Apply default bullet list</li>
                        <li><strong>Alt + H + U:</strong> Open bullet library</li>
                        <li><strong>Alt + H + N:</strong> Open numbering library</li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Hyperlinks -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-link"></i> 3. Creating Connections: Hyperlinks
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-external-link-alt"></i> Inserting Hyperlinks (Ctrl + K)</h3>
                    <ul>
                        <li><strong>Link to Existing File or Web Page:</strong> Connect to external resources</li>
                        <li><strong>Place in This Document:</strong> Jump to specific slides</li>
                        <li><strong>Create New Document:</strong> Generate and link to new file</li>
                        <li><strong>E-mail Address:</strong> Create mailto links</li>
                        <li><strong>ScreenTip:</strong> Add hover text for accessibility</li>
                    </ul>
                </div>

                <div class="image-container">
                    <img src="images/hyperlink_dialog.png"
                        alt="PowerPoint Hyperlink Dialog"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Qb3dlclBvaW50IEh5cGVybGluayBEaWFsb2c8L3RleHQ+PC9zdmc+']">
                    <div class="image-caption">The Insert Hyperlink dialog with all four link type options</div>
                </div>
            </div>

            <!-- Section 4: Zoom Links -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-search-plus"></i> 4. Interactive Navigation: Zoom Links
                </div>

                <div class="zoom-types">
                    <div class="zoom-card summary">
                        <div class="zoom-icon">
                            <i class="fas fa-th-large"></i>
                        </div>
                        <h3>Summary Zoom</h3>
                        <p><strong>Creates:</strong> Interactive table of contents slide</p>
                        <p><strong>Best for:</strong> Dynamic agendas, visual overviews</p>
                        <p><strong>Shortcut:</strong> Alt > N > Z > S</p>
                        <div style="margin-top: 15px; padding: 10px; background: rgba(255,255,255,0.5); border-radius: 5px;">
                            <strong>How:</strong> Insert > Zoom > Summary Zoom
                        </div>
                    </div>
                    <div class="zoom-card section">
                        <div class="zoom-icon">
                            <i class="fas fa-object-group"></i>
                        </div>
                        <h3>Section Zoom</h3>
                        <p><strong>Creates:</strong> Link to entire presentation section</p>
                        <p><strong>Best for:</strong> Deep dives, modular content</p>
                        <p><strong>Shortcut:</strong> Alt > N > Z > C</p>
                        <div style="margin-top: 15px; padding: 10px; background: rgba(255,255,255,0.5); border-radius: 5px;">
                            <strong>Requires:</strong> Pre-defined sections (Week 2 skill)
                        </div>
                    </div>
                    <div class="zoom-card slide">
                        <div class="zoom-icon">
                            <i class="fas fa-file-powerpoint"></i>
                        </div>
                        <h3>Slide Zoom</h3>
                        <p><strong>Creates:</strong> Direct link to specific slide</p>
                        <p><strong>Best for:</strong> Appendix slides, quick references</p>
                        <p><strong>Shortcut:</strong> Alt > N > Z > L</p>
                        <div style="margin-top: 15px; padding: 10px; background: rgba(255,255,255,0.5); border-radius: 5px;">
                            <strong>How:</strong> Insert > Zoom > Slide Zoom
                        </div>
                    </div>
                </div>

                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-magic"></i> Formatting Zoom Links
                    </div>
                    <p>Select any Zoom object and use the <strong>Zoom Format tab</strong> to:</p>
                    <ul>
                        <li>Change frame style and color</li>
                        <li>Add shadow and reflection effects</li>
                        <li>Set zoom transition duration</li>
                        <li>Configure return to zoom</li>
                        <li>Apply image correction to thumbnails</li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Hyperlink vs Zoom -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-balance-scale"></i> 5. When to Use What: Hyperlink vs. Zoom
                </div>

                <div class="link-types">
                    <div class="link-card hyperlink">
                        <div class="link-icon">
                            <i class="fas fa-link"></i>
                        </div>
                        <h3>Use Hyperlinks (Ctrl+K)</h3>
                        <ul style="text-align: left; margin-top: 15px;">
                            <li>Linking to external websites</li>
                            <li>Connecting to documents or files</li>
                            <li>Email address links</li>
                            <li>Simple text-based navigation</li>
                            <li>Linear presentation flows</li>
                        </ul>
                    </div>
                    <div class="link-card zoom">
                        <div class="link-icon">
                            <i class="fas fa-search-plus"></i>
                        </div>
                        <h3>Use Zoom Links</h3>
                        <ul style="text-align: left; margin-top: 15px;">
                            <li>Visual menu-style interfaces</li>
                            <li>Non-linear presentations</li>
                            <li>Section-based navigation</li>
                            <li>Interactive table of contents</li>
                            <li>Choose-your-own-path decks</li>
                        </ul>
                    </div>
                </div>

                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Hyperlink</th>
                            <th>Zoom Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Visual Appeal</strong></td>
                            <td>Text-based</td>
                            <td>Thumbnail-based</td>
                        </tr>
                        <tr>
                            <td><strong>Navigation Style</strong></td>
                            <td>Linear jumps</td>
                            <td>Animated zooms</td>
                        </tr>
                        <tr>
                            <td><strong>Best For</strong></td>
                            <td>External resources</td>
                            <td>Internal navigation</td>
                        </tr>
                        <tr>
                            <td><strong>User Experience</strong></td>
                            <td>Functional</td>
                            <td>Engaging</td>
                        </tr>
                        <tr>
                            <td><strong>Accessibility</strong></td>
                            <td>Screen reader friendly</td>
                            <td>Visual emphasis needed</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 6. Keyboard Shortcuts Cheat Sheet
                </div>

                <table class="shortcut-table">
                    <thead>
                        <tr>
                            <th width="30%">Shortcut</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + K</span></td>
                            <td>Insert a hyperlink</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Tab</span></td>
                            <td>Demote list item (create sub-bullet)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Tab</span></td>
                            <td>Promote list item</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > Z > S</span></td>
                            <td>Insert Summary Zoom</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > Z > C</span></td>
                            <td>Insert Section Zoom</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > Z > L</span></td>
                            <td>Insert Slide Zoom</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > H > 0</span></td>
                            <td>Add numbered list</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > H > U</span></td>
                            <td>Add bulleted list</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > J, D, A</span></td>
                            <td>Open Zoom Format tab</td>
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
                            <td><span class="shortcut-key">Ctrl + M</span></td>
                            <td>Insert new slide</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save presentation</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Esc</span></td>
                            <td>Exit slideshow or zoom</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 7. Step-by-Step Practice Exercise
                </div>
                <h4 style="color: #b71c1c; margin-bottom: 15px;">Activity: Build an Interactive Product Launch Deck</h4>
                <p><strong>Apply your formatting and linking skills to create a dynamic, user-guided presentation.</strong></p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #b71c1c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Set Up & Format Text:</strong>
                            <ul>
                                <li>Open a new presentation. Apply the "Slice" theme.</li>
                                <li>On Slide 1, add a title and create a subheading text box with your company name.</li>
                                <li>Format the subheading: change the Font Color and apply a subtle Text Shadow (Text Effects).</li>
                                <li>On Slide 2, add a title "Product Overview" and a text box with a 3-bullet list describing key features.</li>
                                <li>Convert this text box into <strong>Two Columns</strong> (Home > Paragraph > Columns).</li>
                            </ul>
                        </li>
                        <li><strong>Create a Multi-Level List:</strong>
                            <ul>
                                <li>On Slide 3, title it "Technical Specifications."</li>
                                <li>Create a <strong>Numbered List</strong> for main categories (e.g., 1. Performance, 2. Design).</li>
                                <li>Press <strong>Tab</strong> under "1. Performance" to create sub-bullets for details (CPU, RAM, etc.).</li>
                                <li>Customize the sub-bullets to a different symbol via <strong>Bullets > Bullets and Numbering</strong>.</li>
                            </ul>
                        </li>
                        <li><strong>Insert Standard Hyperlinks:</strong>
                            <ul>
                                <li>On Slide 4, title it "Learn More & Contact."</li>
                                <li>Add text: "Email our team for a custom quote."</li>
                                <li>Select the text, press <strong>Ctrl+K</strong>, choose <strong>E-mail Address</strong>, and enter a dummy email.</li>
                                <li>Add a ScreenTip: "Opens your default email client."</li>
                            </ul>
                        </li>
                        <li><strong>Build Interactive Zoom Links:</strong>
                            <ul>
                                <li>First, create structure: Add three new slides (5, 6, 7) with titles: "Deep Dive: Performance," "Deep Dive: Design," "Deep Dive: Support."</li>
                                <li>Group these into a new Section called <strong>Deep_Dives</strong> (Right-click before Slide 5 > Add Section).</li>
                                <li><strong>Create a Summary Zoom (Your Interactive Menu):</strong>
                                    <ul>
                                        <li>Go to Slide 2. <strong>Insert > Zoom > Summary Zoom</strong>.</li>
                                        <li>Select Slide 1 (Cover), Slide 3 (Specs), Slide 4 (Contact), and the Deep_Dives Section.</li>
                                        <li>A new "Summary Zoom" slide is created with thumbnails. Move it to be Slide 2.</li>
                                        <li>Format the thumbnails using the Zoom Format tab (try a "Soft Edge Rectangle").</li>
                                    </ul>
                                </li>
                                <li><strong>Add a Section Zoom:</strong>
                                    <ul>
                                        <li>On Slide 2, also insert a <strong>Section Zoom</strong> for the Deep_Dives section.</li>
                                        <li>Position it neatly. This gives you two ways to navigate to the same content.</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <li><strong>Test & Present:</strong>
                            <ul>
                                <li>Enter Slide Show mode (F5).</li>
                                <li>On the Summary Zoom slide (Slide 2), click on the Deep_Dives Section thumbnail.</li>
                                <li>Navigate through the deep dive slides, then press <strong>Esc</strong> to return to the Summary Zoom.</li>
                                <li>Test your email hyperlink on Slide 4.</li>
                            </ul>
                        </li>
                        <li><strong>Save your work as YourName_Interactive_Product_Launch_WK3.pptx.</strong></li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551434678-e076c223a692?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Interactive Presentation Design"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbnRlcmFjdGl2ZSBQcmVzZW50YXRpb24gRGVzaWduPC90ZXh0Pjwvc3ZnPg==']">
                    <div class="image-caption">Design engaging, interactive presentations with Zoom links</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Product Launch Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Summary Zoom</strong>
                    <p>An interactive table of contents slide that zooms to selected slides or sections, creating a visual menu for navigation.</p>
                </div>

                <div class="term">
                    <strong>Section Zoom</strong>
                    <p>A live link that zooms into and presents an entire, pre-defined section of slides, perfect for modular content.</p>
                </div>

                <div class="term">
                    <strong>Slide Zoom</strong>
                    <p>A live link that zooms directly to a single, specific slide, useful for quick references and appendices.</p>
                </div>

                <div class="term">
                    <strong>Multi-Level List</strong>
                    <p>A list with a hierarchical structure, using indentation and different bullet/number styles for each level to show relationships.</p>
                </div>

                <div class="term">
                    <strong>ScreenTip</strong>
                    <p>The informational text that appears when a user hovers the pointer over a hyperlink, improving accessibility and user experience.</p>
                </div>

                <div class="term">
                    <strong>Character Spacing (Kerning)</strong>
                    <p>The adjustment of space between characters in text, used for better readability and professional typography.</p>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-question-circle"></i> 9. Self-Review Questions
                </div>
                
                <ol style="margin-bottom: 20px;">
                    <li>You want to create a magazine-style layout with text flowing side-by-side on a slide. What feature do you use, and where is it found?</li>
                    <li>What is the keyboard action to create a sub-bullet (a lower-level item) in a list while typing?</li>
                    <li>Describe the primary visual and functional difference between using a Summary Zoom and simply linking text to other slides with standard hyperlinks.</li>
                    <li>When inserting a Section Zoom, what prerequisite must you have already completed in your presentation (a skill from Week 2)?</li>
                    <li>You want to create a clickable menu that shows thumbnails of only your three key case study slides. Which type of Zoom link is most appropriate?</li>
                </ol>

                <div class="tip-box" style="margin-top: 20px;">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i> Answers (Try first before checking!)
                    </div>
                    <div id="answers" style="display: none;">
                        <ol>
                            <li><strong>Columns feature</strong>, found in Home tab > Paragraph group > Columns.</li>
                            <li><strong>Tab key</strong> while typing in a list item.</li>
                            <li>Summary Zoom creates a <strong>visual thumbnail menu</strong> with smooth zoom animations, while hyperlinks are <strong>text-based jumps</strong> without visual previews.</li>
                            <li>You must have already <strong>created sections</strong> in your presentation (using Section feature from Week 2).</li>
                            <li><strong>Summary Zoom</strong> is most appropriate for creating a thumbnail menu of selected slides.</li>
                        </ol>
                    </div>
                    <button onclick="toggleAnswers()" class="download-btn" style="background: #4caf50; margin: 10px 0;">
                        <i class="fas fa-eye"></i> Show/Hide Answers
                    </button>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Design for Interaction:</strong> When using Zooms, think like a web designer. Your Zoom slide is a homepage. Keep it clean, visually balanced, and intuitive.</li>
                    <li><strong>Consistency is Key:</strong> Format all your Zoom thumbnails similarly (same border, effect, size) for a professional look.</li>
                    <li><strong>Practice the Flow:</strong> Rehearse navigating with Zoom links until it's second nature. Know how to enter and exit zooms smoothly during a live talk.</li>
                    <li><strong>Hyperlinks for External, Zooms for Internal:</strong> This simple rule will guide you to the right tool. Use Zoom features to keep your audience inside your presentation experience.</li>
                    <li><strong>Accessibility Note:</strong> While Zooms are visually engaging, ensure you also verbally describe navigation options for attendees using screen readers.</li>
                    <li><strong>Test All Links:</strong> Always test every hyperlink and Zoom before presenting. Broken links undermine credibility.</li>
                    <li><strong>Use Descriptive Text:</strong> For hyperlinks, use meaningful text like "Download Report" instead of generic "Click Here."</li>
                    <li><strong>Master the Shortcuts:</strong> Keyboard shortcuts for Zoom insertion (Alt sequences) will dramatically speed up your workflow.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 4: Mastering Visual Storytelling with Graphics & Media</strong></p>
                <p>In Week 4, we'll make your interactive decks visually stunning. You'll master:</p>
                <ul>
                    <li>Working with images, icons, and 3D models</li>
                    <li>Creating and customizing SmartArt graphics for conceptual diagrams</li>
                    <li>Embedding and controlling video and audio seamlessly</li>
                    <li>Applying advanced picture formatting and effects</li>
                    <li>Using icons and scalable vector graphics</li>
                    <li>Creating compelling infographics within PowerPoint</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Gather high-quality images and a short video clip you'd like to incorporate into a presentation.</p>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/create-a-summary-zoom-slide-in-powerpoint-75b8635f-f80f-4c2f-8e3b-7b2bf8c4a5cb" target="_blank">Microsoft Guide: Create a Summary Zoom Slide</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-multi-level-list-5d4a6b7e-35b1-4cc8-bb1e-8c6d65c9aa23" target="_blank">Create a Multi-Level List in Office</a></li>
                    <li><a href="https://support.microsoft.com/office/add-hyperlinks-to-slides-239c6c94-d52f-480c-99ae-8b0acf7df6d9" target="_blank">Add Hyperlinks to Slides</a></li>
                    <li><a href="https://www.youtube.com/watch?v=w9cqehSdYjI" target="_blank">PowerPoint Zoom Tutorial Video</a></li>
                    <li><strong>Practice files and interactive templates</strong> available in the Course Portal</li>
                    <li><strong>Week 3 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Interactive PowerPoint Simulator</strong> for hands-on Zoom practice</li>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week3.php">Week 3 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>PowerPoint Interactive Features Guide:</strong> <a href="https://support.microsoft.com/powerpoint" target="_blank">Official Support</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week3_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 3 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 3 Handout</p>
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

        // Toggle answers visibility
        function toggleAnswers() {
            const answersDiv = document.getElementById('answers');
            if (answersDiv.style.display === 'none') {
                answersDiv.style.display = 'block';
            } else {
                answersDiv.style.display = 'none';
            }
        }

        // Simulate template download
        function downloadTemplate() {
            alert('Product launch template would download. This is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/templates/week3_product_launch.potx';
        }

        // Interactive demonstrations
        function demonstrateZoom(type) {
            const demonstrations = {
                'summary': {
                    title: 'Summary Zoom Demonstration',
                    steps: [
                        '1. Go to Insert > Zoom > Summary Zoom',
                        '2. Select the slides or sections you want to include',
                        '3. PowerPoint creates a new slide with thumbnails',
                        '4. Click any thumbnail during presentation to zoom',
                        '5. Press Esc to return to the Summary Zoom slide'
                    ]
                },
                'section': {
                    title: 'Section Zoom Demonstration',
                    steps: [
                        '1. First, create Sections in your presentation',
                        '2. Go to Insert > Zoom > Section Zoom',
                        '3. Select the Section you want to link to',
                        '4. During presentation, click to zoom into the entire section',
                        '5. Navigate through the section slides',
                        '6. Press Esc to return to origin slide'
                    ]
                },
                'slide': {
                    title: 'Slide Zoom Demonstration',
                    steps: [
                        '1. Go to Insert > Zoom > Slide Zoom',
                        '2. Select individual slides to create thumbnails for',
                        '3. During presentation, click thumbnail to zoom directly to that slide',
                        '4. Press Esc to return to the zoom origin'
                    ]
                }
            };
            
            const demo = demonstrations[type];
            if (demo) {
                alert(demo.title + '\n\n' + demo.steps.join('\n'));
            }
        }

        // Hyperlink demonstration
        function demonstrateHyperlink() {
            const steps = [
                'Inserting a Hyperlink:',
                '1. Select the text or object you want to make clickable',
                '2. Press Ctrl + K or go to Insert > Hyperlink',
                '3. Choose link type:',
                '   • Existing File or Web Page',
                '   • Place in This Document',
                '   • Create New Document',
                '   • E-mail Address',
                '4. Configure the link details',
                '5. Add a ScreenTip (optional but recommended)',
                '6. Click OK to insert the hyperlink'
            ];
            alert(steps.join('\n'));
        }

        // Text columns demonstration
        function demonstrateColumns() {
            const steps = [
                'Creating Text Columns:',
                '1. Select the text box you want to format',
                '2. Go to Home tab > Paragraph group',
                '3. Click the Columns button (looks like newspaper columns)',
                '4. Choose:',
                '   • One (default)',
                '   • Two',
                '   • Three',
                '   • More Columns... (for custom settings)',
                '5. For custom columns, set:',
                '   • Number of columns',
                '   • Spacing between columns',
                '   • Line between columns (checkbox)'
            ];
            alert(steps.join('\n'));
        }

        // Multi-level list demonstration
        function demonstrateMultiLevelList() {
            const steps = [
                'Creating Multi-Level Lists:',
                '1. Start typing your list',
                '2. To create a sub-item (Level 2):',
                '   • Press Tab at the beginning of the line',
                '   • Or use Increase Indent button (Home > Paragraph)',
                '3. To promote an item (move up a level):',
                '   • Press Shift + Tab',
                '   • Or use Decrease Indent button',
                '4. Customize bullet/number styles:',
                '   • Home > Paragraph > Bullets dropdown',
                '   • Home > Paragraph > Numbering dropdown',
                '5. For advanced formatting:',
                '   • Click arrow next to Bullets/Numbering',
                '   • Choose "Bullets and Numbering"'
            ];
            alert(steps.join('\n'));
        }

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj9ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });

            // Add click handlers to Zoom cards
            const zoomCards = document.querySelectorAll('.zoom-card');
            zoomCards.forEach(card => {
                card.addEventListener('click', function() {
                    const type = Array.from(this.classList).find(cls => 
                        ['summary', 'section', 'slide'].includes(cls)
                    );
                    if (type) {
                        demonstrateZoom(type);
                    }
                });
            });

            // Add click handlers to formatting items
            const formattingItems = document.querySelectorAll('.formatting-item');
            formattingItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    const path = this.querySelector('div:last-child').textContent;
                    
                    alert(`${title}\n\n${description}\n\n${path}\n\nTry this feature in PowerPoint!`);
                });
            });

            // Add click handlers to link cards
            const linkCards = document.querySelectorAll('.link-card');
            linkCards.forEach(card => {
                card.addEventListener('click', function() {
                    const type = Array.from(this.classList).find(cls => 
                        ['hyperlink', 'zoom'].includes(cls)
                    );
                    
                    if (type === 'hyperlink') {
                        demonstrateHyperlink();
                    } else if (type === 'zoom') {
                        alert('Zoom Links vs Hyperlinks:\n\nZoom links provide visual, animated navigation within your presentation.\nHyperlinks are best for external resources and simple text-based jumps.');
                    }
                });
            });
        });

        // Track handout access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('PowerPoint Week 3 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Interactive practice
        function practiceShortcut(shortcut, action) {
            const alertDiv = document.createElement('div');
            alertDiv.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #4caf50;
                color: white;
                padding: 15px 25px;
                border-radius: 8px;
                z-index: 1000;
                animation: slideDown 0.3s, fadeOut 2s 1.7s forwards;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                font-weight: bold;
                text-align: center;
                min-width: 300px;
            `;
            alertDiv.innerHTML = `
                <div style="font-size: 1.2rem; margin-bottom: 5px;">${shortcut}</div>
                <div>${action}</div>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 2000);
        }

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            // Ctrl + K for hyperlink
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                practiceShortcut('Ctrl + K', 'Insert Hyperlink');
            }
            
            // Tab for list demotion
            if (e.key === 'Tab' && !e.ctrlKey && !e.altKey) {
                if (document.activeElement.tagName !== 'INPUT' && 
                    document.activeElement.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    practiceShortcut('Tab', 'Demote List Item');
                }
            }
            
            // Shift + Tab for list promotion
            if (e.key === 'Tab' && e.shiftKey) {
                if (document.activeElement.tagName !== 'INPUT' && 
                    document.activeElement.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    practiceShortcut('Shift + Tab', 'Promote List Item');
                }
            }
            
            // F5 for slideshow
            if (e.key === 'F5') {
                e.preventDefault();
                practiceShortcut('F5', 'Start Slideshow');
            }
        });

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideDown {
                from {
                    top: -100px;
                    opacity: 0;
                }
                to {
                    top: 20px;
                    opacity: 1;
                }
            }
            
            @keyframes fadeOut {
                from {
                    opacity: 1;
                }
                to {
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveElements = document.querySelectorAll(
                'a, button, .zoom-card, .formatting-item, .link-card'
            );
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

        // Week 3 specific features demonstration
        function demonstrateWeek3Features() {
            const features = [
                "Week 3 Key Features Review:",
                "\n1. Advanced Text Formatting:",
                "   • Text Columns for magazine layouts",
                "   • Character Spacing (kerning)",
                "   • Text Effects and WordArt",
                "\n2. Professional Lists:",
                "   • Multi-level bulleted/numbered lists",
                "   • Custom bullet symbols",
                "   • List indentation control",
                "\n3. Hyperlinks:",
                "   • External and internal links",
                "   • Email links",
                "   • ScreenTips for accessibility",
                "\n4. Zoom Links:",
                "   • Summary Zoom (interactive TOC)",
                "   • Section Zoom (modular navigation)",
                "   • Slide Zoom (quick references)",
                "\n5. Best Practices:",
                "   • Hyperlinks for external resources",
                "   • Zooms for internal navigation",
                "   • Test all links before presenting"
            ];
            alert(features.join('\n'));
        }

        // Quick reference guide
        function showQuickReference() {
            const reference = [
                "Week 3 Quick Reference:",
                "\n📝 Text Columns: Home > Paragraph > Columns",
                "🔗 Hyperlinks: Ctrl + K",
                "📋 List Demotion: Tab",
                "📈 List Promotion: Shift + Tab",
                "\n🔍 Summary Zoom: Insert > Zoom > Summary Zoom",
                "🔍 Section Zoom: Insert > Zoom > Section Zoom",
                "🔍 Slide Zoom: Insert > Zoom > Slide Zoom",
                "\n🎯 Shortcuts:",
                "• Alt > N > Z > S = Summary Zoom",
                "• Alt > N > Z > C = Section Zoom",
                "• Alt > N > Z > L = Slide Zoom",
                "• Alt > H > U = Bullets",
                "• Alt > H > 0 = Numbering"
            ];
            alert(reference.join('\n'));
        }

        // Add buttons for demonstrations
        document.addEventListener('DOMContentLoaded', function() {
            // Add demonstration buttons if not already present
            const contentDiv = document.querySelector('.content');
            
            const demoButtons = document.createElement('div');
            demoButtons.style.cssText = `
                display: flex;
                gap: 10px;
                margin: 20px 0;
                flex-wrap: wrap;
                justify-content: center;
            `;
            
            demoButtons.innerHTML = `
                <button onclick="demonstrateColumns()" style="
                    padding: 8px 15px;
                    background: #2196f3;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 0.9rem;
                ">
                    <i class="fas fa-columns"></i> Text Columns Demo
                </button>
                <button onclick="demonstrateMultiLevelList()" style="
                    padding: 8px 15px;
                    background: #ff9800;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 0.9rem;
                ">
                    <i class="fas fa-list-ol"></i> Multi-Level List Demo
                </button>
                <button onclick="demonstrateHyperlink()" style="
                    padding: 8px 15px;
                    background: #4caf50;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 0.9rem;
                ">
                    <i class="fas fa-link"></i> Hyperlink Demo
                </button>
                <button onclick="showQuickReference()" style="
                    padding: 8px 15px;
                    background: #9c27b0;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 0.9rem;
                ">
                    <i class="fas fa-star"></i> Quick Reference
                </button>
            `;
            
            // Insert after the first section
            const firstSection = document.querySelector('.section');
            if (firstSection) {
                firstSection.parentNode.insertBefore(demoButtons, firstSection.nextSibling);
            }
        });

        // Practice mode for shortcuts
        let practiceMode = false;
        
        function togglePracticeMode() {
            practiceMode = !practiceMode;
            const modeButton = document.getElementById('practiceModeBtn');
            
            if (practiceMode) {
                if (modeButton) {
                    modeButton.innerHTML = '<i class="fas fa-stop"></i> Stop Practice Mode';
                    modeButton.style.background = '#d32f2f';
                }
                alert('Practice Mode Active!\n\nTry these shortcuts:\n• Ctrl + K (Hyperlink)\n• Tab (Demote list)\n• Shift + Tab (Promote list)\n• F5 (Start slideshow)');
            } else {
                if (modeButton) {
                    modeButton.innerHTML = '<i class="fas fa-play"></i> Start Practice Mode';
                    modeButton.style.background = '#4caf50';
                }
            }
        }

        // Add practice mode button
        document.addEventListener('DOMContentLoaded', function() {
            const downloadSection = document.querySelector('.download-btn').parentNode;
            const practiceButton = document.createElement('button');
            practiceButton.id = 'practiceModeBtn';
            practiceButton.className = 'download-btn';
            practiceButton.style.background = '#4caf50';
            practiceButton.style.marginLeft = '15px';
            practiceButton.innerHTML = '<i class="fas fa-play"></i> Start Practice Mode';
            practiceButton.onclick = togglePracticeMode;
            
            downloadSection.appendChild(practiceButton);
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
    $viewer = new PowerPointWeek3HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>