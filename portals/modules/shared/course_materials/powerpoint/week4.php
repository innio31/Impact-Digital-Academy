<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week4_view.php

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
 * PowerPoint Week 4 Handout Viewer Class with PDF Download
 */
class PowerPointWeek4HandoutViewer
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
            $mpdf->SetTitle('Week 4: Mastering Visual Storytelling with Graphics & Media');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Visual Storytelling, Graphics, Media, Images, SmartArt, Accessibility');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week4_Visual_Storytelling_' . date('Y-m-d') . '.pdf';
            
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
                Week 4: Mastering Visual Storytelling with Graphics & Media
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Welcome to Week 4!</h2>
                <p style="margin-bottom: 15px;">
                    Last week, you built an interactive and structurally sophisticated presentation. Now, it's time to make it visually captivating and professionally polished. This week, we move from structure to visual storytelling. You will learn to expertly insert, format, and manipulate images, icons, shapes, and SmartArt to convey ideas powerfully. We'll also integrate dynamic media—video and audio—and ensure our visuals are accessible to everyone. By the end, you'll be able to transform text-heavy slides into memorable, engaging visual experiences that command attention.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #ffebee; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Insert, crop, mask, and apply professional artistic effects to images</li>
                    <li>Create custom diagrams and workflows using SmartArt and manipulate them at the component level</li>
                    <li>Insert and format icons, 3D models, and shapes to build custom graphics and infographics</li>
                    <li>Embed and configure video and audio playback options for seamless presentation integration</li>
                    <li>Apply and edit Alt Text to all visual elements for accessibility compliance</li>
                    <li>Align, group, and layer objects with precision to achieve pixel-perfect slide layouts</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #b71c1c; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #d32f2f; font-size: 14pt;">1. Working with Images Like a Pro</h3>
                <ul>
                    <li><strong>Inserting Images:</strong>
                        <ul>
                            <li>From This Device, Online Pictures (Bing, OneDrive), or Stock Images</li>
                            <li>Drag-and-drop directly onto a slide</li>
                        </ul>
                    </li>
                    <li><strong>Essential Image Corrections & Formatting (Picture Format Tab):</strong>
                        <ul>
                            <li><strong>Crop:</strong> Standard crop, crop to shape (e.g., circle, rounded rectangle), or crop to aspect ratio</li>
                            <li><strong>Remove Background:</strong> Automatic tool to eliminate distracting backgrounds</li>
                            <li><strong>Corrections:</strong> Adjust Sharpness, Brightness, and Contrast</li>
                            <li><strong>Color:</strong> Recolor images to match your theme, set transparent color</li>
                            <li><strong>Artistic Effects:</strong> Apply stylized effects like blur, paint strokes, or glass</li>
                        </ul>
                    </li>
                    <li><strong>Picture Styles:</strong> Apply pre-set borders, shadows, frames, and 3D effects with one click</li>
                    <li><strong>Picture Layout:</strong> Instantly convert an image into a SmartArt graphic with captions</li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">2. Creating Conceptual Graphics with SmartArt</h3>
                <ul>
                    <li><strong>Inserting SmartArt (Insert Tab > SmartArt):</strong>
                        <ul>
                            <li>Choose from categories: List, Process, Cycle, Hierarchy, Relationship, Matrix, Pyramid</li>
                        </ul>
                    </li>
                    <li><strong>Editing SmartArt:</strong>
                        <ul>
                            <li><strong>Text Pane:</strong> The easiest way to add and edit content (appears when SmartArt is selected)</li>
                            <li><strong>Adding/Removing Shapes:</strong> Use the Text Pane or SmartArt Design Tab > Add Shape</li>
                        </ul>
                    </li>
                    <li><strong>Advanced Customization:</strong>
                        <ul>
                            <li><strong>Change Colors (SmartArt Design Tab):</strong> Apply theme-based color schemes</li>
                            <li><strong>SmartArt Styles:</strong> Apply 3D and polished style effects</li>
                            <li><strong>Convert to Shapes:</strong> Right-click the SmartArt and select Convert to Shapes. This breaks the graphic into individual, editable shapes, allowing for limitless customization (e.g., changing one shape's color independently)</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">3. Building with Icons, 3D Models & Shapes</h3>
                <ul>
                    <li><strong>Icons (Insert Tab > Icons):</strong>
                        <ul>
                            <li>Huge library of scalable, customizable vector graphics from the Microsoft 365 library</li>
                            <li>Format like shapes: Change fill, outline, and apply effects</li>
                        </ul>
                    </li>
                    <li><strong>3D Models (Insert Tab > 3D Models):</strong>
                        <ul>
                            <li>Insert from Stock 3D Library or This Device</li>
                            <li>Use the 3D Model View tool to rotate, tilt, and pan the model</li>
                            <li><strong>Animation Tip:</strong> Apply the Turntable animation to make it spin automatically</li>
                        </ul>
                    </li>
                    <li><strong>Shapes (Insert Tab > Shapes):</strong>
                        <ul>
                            <li><strong>Drawing & Merging:</strong> Use the Merge Shapes tool (found under Shape Format > Insert Shapes) to Union, Combine, Fragment, Intersect, or Subtract shapes to create unique graphics</li>
                            <li><strong>Formatting:</strong> Master the Shape Fill (gradients, pictures, textures), Shape Outline, and Shape Effects (shadow, glow, bevel)</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">4. Integrating Video & Audio</h3>
                <ul>
                    <li><strong>Inserting Media:</strong>
                        <ul>
                            <li><strong>Video:</strong> From This Device, Stock Videos, or Online Videos (YouTube via embed code)</li>
                            <li><strong>Audio:</strong> From This Device, Record Audio, or Online Audio</li>
                        </ul>
                    </li>
                    <li><strong>Playback Settings (Video/Audio Format & Playback Tabs):</strong>
                        <ul>
                            <li><strong>Trimming:</strong> Clip the start and end of media files</li>
                            <li><strong>Fade In/Out:</strong> Set smooth audio transitions</li>
                            <li><strong>Start Options:</strong> On Click, Automatically, or Play Across Slides</li>
                            <li><strong>Playback Options:</strong> Loop until Stopped, Rewind after Playing, Hide During Show (for audio)</li>
                            <li><strong>Poster Frame:</strong> Set a custom thumbnail image for a video</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">5. Essential Graphic Design Principles</h3>
                <ul>
                    <li><strong>Selecting & Arranging Objects:</strong>
                        <ul>
                            <li><strong>Selection Pane (Home Tab > Editing > Select > Selection Pane):</strong> Manage objects on a crowded slide, show/hide, and rename them</li>
                            <li><strong>Align & Distribute (Shape Format Tab > Align):</strong> Align objects to each other or to the slide. Distribute them evenly</li>
                            <li><strong>Group (Ctrl+G) / Ungroup (Ctrl+Shift+G):</strong> Bind multiple objects to move and format as one</li>
                            <li><strong>Layers (Bring Forward / Send Backward):</strong> Control which objects appear on top of others</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #d32f2f; margin-top: 20px; font-size: 14pt;">6. Accessibility: Alt Text for All Visuals</h3>
                <ul>
                    <li><strong>Adding & Editing Alt Text:</strong>
                        <ul>
                            <li>Right-click any object (image, shape, SmartArt, chart) > Edit Alt Text</li>
                            <li>The Alt Text pane will open. Describe the object and its purpose concisely</li>
                            <li>For decorative images: Check the "Mark as decorative" checkbox</li>
                        </ul>
                    </li>
                    <li><strong>Why it's Critical:</strong> Ensures screen readers can describe visuals to users with visual impairments, a key requirement for accessibility standards</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #fce4ec; padding: 15px; border-left: 5px solid #d32f2f; margin-bottom: 25px;">
                <h3 style="color: #d32f2f; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise: Create a Visual "Company Culture" Infographic Slide</h3>
                <p><strong>Objective:</strong> Apply your visual storytelling skills to create a single, content-rich, and beautifully designed slide.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li><strong>Set Up & Insert a Background:</strong>
                        <ul>
                            <li>Open a new presentation. Apply the "Ion" theme</li>
                            <li>On Slide 1, right-click the slide background > Format Background</li>
                            <li>Choose Gradient fill. Select a subtle two-color gradient that matches the theme</li>
                        </ul>
                    </li>
                    <li><strong>Build a Custom SmartArt Process:</strong>
                        <ul>
                            <li>Go to Insert > SmartArt. Choose Process > Basic Bending Process</li>
                            <li>In the Text Pane, enter: 1) Recruit, 2) Onboard, 3) Train, 4) Succeed</li>
                            <li>With the SmartArt selected, go to SmartArt Design > Change Colors and apply a colorful accent</li>
                            <li>Go to SmartArt Design > Convert > Convert to Shapes. Now, ungroup (Ctrl+Shift+G) the shapes once. You can now format each arrow and circle independently</li>
                        </ul>
                    </li>
                    <li><strong>Enhance with Icons and Formatting:</strong>
                        <ul>
                            <li>Select the "Recruit" circle. Go to Shape Format > Shape Fill > Picture. Choose "From Online Pictures" and search for a "person" icon from the icons library. Do this for each circle with relevant icons (e.g., "team" for Onboard, "book" for Train, "trophy" for Succeed)</li>
                            <li>Select all four circles, then use Shape Format > Align > Align Middle and Distribute Horizontally</li>
                        </ul>
                    </li>
                    <li><strong>Insert and Format Supporting Graphics:</strong>
                        <ul>
                            <li>Go to Insert > 3D Models > Stock 3D Models. Add a simple model like a "city" or "office building." Resize it and place it in the top right corner. Use the 3D control to adjust its angle</li>
                            <li>Insert a rounded rectangle shape below your process. Add text inside: "Our Core Values: Integrity, Innovation, Teamwork."</li>
                            <li>Insert three decorative circles (Insert > Shapes > Oval, hold Shift to draw a circle). Place them near the core values text. Use the Merge Shapes > Fragment tool with the circles and text box selected to create a custom text effect (this is advanced—optional)</li>
                        </ul>
                    </li>
                    <li><strong>Add a Media Element:</strong>
                        <ul>
                            <li>Go to Insert > Media > Audio > Record Audio. Record a 5-second welcome message saying, "Welcome to our team."</li>
                            <li>A speaker icon will appear. Drag it to the bottom corner. In the Audio Playback tab, set it to Start: On Click and check Hide During Show</li>
                        </ul>
                    </li>
                    <li><strong>Apply Final Polish and Accessibility:</strong>
                        <ul>
                            <li>Use the Selection Pane to rename your objects logically (e.g., "Process_Circle_1", "3D_Building")</li>
                            <li>Select all objects except the background. Group them (Ctrl+G). Use Align > Align Center and Align Middle to center the entire graphic on the slide</li>
                            <li>Right-click the 3D Model and select Edit Alt Text. Write: "A 3D model of a modern office building, symbolizing our workplace."</li>
                            <li>Right-click one of the decorative circles and in the Alt Text pane, check "Mark as decorative."</li>
                        </ul>
                    </li>
                    <li><strong>Save and Review:</strong>
                        <ul>
                            <li>Enter Slide Show mode (F5) and click to play your recorded audio</li>
                            <li>Save your work as YourName_Visual_Infographic_WK4.pptx</li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #b71c1c; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet (Week 4 Focus)</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #d32f2f; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > P</td>
                            <td style="padding: 6px 8px;">Insert Picture</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > S</td>
                            <td style="padding: 6px 8px;">Insert SmartArt</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > I</td>
                            <td style="padding: 6px 8px;">Insert Icons/3D Models (opens stock libraries)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > V</td>
                            <td style="padding: 6px 8px;">Insert Video</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt > N > O</td>
                            <td style="padding: 6px 8px;">Insert Audio</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + G</td>
                            <td style="padding: 6px 8px;">Group selected objects</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + G</td>
                            <td style="padding: 6px 8px;">Ungroup selected objects</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt, J, D, A</td>
                            <td style="padding: 6px 8px;">Open Selection Pane</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt, J, D, T</td>
                            <td style="padding: 6px 8px;">Open the Animation Pane</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>SmartArt:</strong> A tool for creating professional-quality diagrams and information graphics.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Crop to Shape:</strong> The process of masking an image to fit within a specific shape (e.g., circle, star).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Merge Shapes:</strong> A feature that allows you to combine, fragment, or subtract multiple shapes to create new, custom shapes.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Selection Pane:</strong> A panel that lists all objects on a slide, allowing you to manage visibility, ordering, and selection.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Alt Text (Alternative Text):</strong> Descriptive text added to a visual element so screen reader software can describe it to users who are blind or have low vision.</p>
                </div>
                <div>
                    <p><strong>Poster Frame:</strong> A static image displayed on a slide to represent a video before it is played.</p>
                </div>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>You have inserted a Process SmartArt, but need to add an extra step between two existing shapes. What is the most efficient way to do this?</li>
                    <li>You want to make a company logo, which is a square JPEG, appear as a circle on your slide. What two-step formatting feature do you use?</li>
                    <li>You've inserted a background music track. You want it to play softly across the first three slides and then stop, without showing an icon. What three Playback settings must you configure?</li>
                    <li>A slide contains 20 overlapping shapes. What tool should you use to select a single shape buried at the bottom of the stack?</li>
                    <li>When writing Alt Text for a complex chart, what is the most important principle to follow?</li>
                </ol>
                <div style="margin-top: 15px; padding: 10px; background: #ffecb3; border-radius: 5px; display: none;" id="answers">
                    <strong>Answers:</strong>
                    <ol>
                        <li>Select a shape adjacent to where you want the new shape, then go to SmartArt Design > Add Shape > Add Shape After/Before, or use the Text Pane and press Enter after the relevant bullet point.</li>
                        <li>First, use Picture Format > Crop > Crop to Shape and select a circle. Then, use Picture Format > Crop > Aspect Ratio > 1:1 to ensure it's a perfect circle.</li>
                        <li>1) Set Start to "Play Across Slides", 2) Set the number of slides in "Play Across Slides" to 3, 3) Check "Hide During Show". Also consider setting a Fade Out.</li>
                        <li>The Selection Pane (Alt, J, D, A). It lists all objects, allowing you to select the one you need by name or by clicking its eye icon to isolate it.</li>
                        <li>Describe the chart's purpose and key takeaway, not just its appearance. For example: "Bar chart showing Q3 sales increasing 15% across all regions, with the Western region leading at $2.5M."</li>
                    </ol>
                </div>
                <button onclick="document.getElementById('answers').style.display='block'" style="margin-top: 10px; padding: 8px 15px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer;">Show Answers</button>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Less is More:</strong> One powerful, well-formatted image is better than five cluttered ones. Use white space strategically.</li>
                    <li><strong>Consistency Creates Professionalism:</strong> Apply the same style of borders, shadows, and color filters to all images on a slide or in a deck for a cohesive look.</li>
                    <li><strong>Use Stock Media Wisely:</strong> The stock image/video/icon libraries are excellent resources. Always recolor icons to match your theme palette for a custom feel.</li>
                    <li><strong>Accessibility First:</strong> Make adding Alt Text the final, non-negotiable step in your workflow for every single visual object.</li>
                    <li><strong>Master the Selection Pane:</strong> In complex slides, naming objects in the Selection Pane (e.g., "Chart_2023", "Logo_Header") will save you immense time and frustration.</li>
                    <li><strong>Learn Merge Shapes:</strong> This is PowerPoint's secret weapon for custom graphic design. Practice Union, Combine, and Fragment.</li>
                    <li><strong>Use SmartArt as a Starting Point:</strong> Don't be limited by default SmartArt layouts. Convert to shapes and customize endlessly.</li>
                    <li><strong>Test Media Playback:</strong> Always test video and audio in Slide Show mode before presenting.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p><strong>Week 5: Animating with Purpose & Mastering Transitions</strong></p>
                <p>In Week 5, we'll bring your stunning visuals to life. You'll learn to:</p>
                <ul>
                    <li>Apply and customize entrance, emphasis, and exit animations using the Animation Pane</li>
                    <li>Create sophisticated motion paths for precise object movement</li>
                    <li>Use the Animation Painter to quickly copy animation styles</li>
                    <li>Set timing and triggers for complex animation sequences</li>
                    <li>Explore Morph, the most powerful transition, to create cinematic scene changes</li>
                    <li>Combine animations and transitions for professional storytelling</li>
                    <li>Use animation to guide audience attention and emphasize key points</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Bring the infographic slide you created this week to animate next week!</p>
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
                Week 4 Handout: Mastering Visual Storytelling with Graphics & Media
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
            Week 4: Visual Storytelling with Graphics & Media | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 4: Mastering Visual Storytelling with Graphics & Media - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ====== Base Styles ====== */
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

        /* ====== Access Control Header ====== */
        .access-header {
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
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

        /* ====== Main Container ====== */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* ====== Header ====== */
        .header {
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
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

        /* ====== Content Area ====== */
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
            color: #2196F3;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #2196F3;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #1976D2;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #2196F3;
        }

        /* ====== Lists ====== */
        ul, ol {
            padding-left: 25px;
            margin-bottom: 20px;
        }

        li {
            margin-bottom: 8px;
            position: relative;
        }

        /* ====== Image Container ====== */
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

        /* ====== Tables ====== */
        .shortcut-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #f9f9f9;
            border-radius: 8px;
            overflow: hidden;
        }

        .shortcut-table th {
            background-color: #2196F3;
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
            background-color: #e3f2fd;
        }

        .shortcut-key {
            background: #2196F3;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        /* ====== Box Styles ====== */
        .exercise-box {
            background: #e3f2fd;
            border-left: 5px solid #2196F3;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #1976D2;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .self-review-box {
            background: #fff3e0;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .self-review-title {
            color: #e65100;
            font-size: 1.4rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tip-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .tip-title {
            color: #2e7d32;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .next-week {
            background: #f3e5f5;
            border-left: 5px solid #9c27b0;
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

        .learning-objectives {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #2196F3;
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

        /* ====== Buttons ====== */
        .download-btn {
            display: inline-block;
            background: #2196F3;
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
            background: #1976D2;
        }

        /* ====== Demo Grids ====== */
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .tool-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .tool-card:hover {
            border-color: #2196F3;
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .tool-icon {
            font-size: 3rem;
            color: #2196F3;
            margin-bottom: 15px;
        }

        .tool-card.image-tool .tool-icon { color: #FF9800; }
        .tool-card.smartart-tool .tool-icon { color: #4CAF50; }
        .tool-card.icon-tool .tool-icon { color: #9C27B0; }
        .tool-card.media-tool .tool-icon { color: #F44336; }

        /* ====== Formatting Demo ====== */
        .formatting-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .format-sample {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .format-sample img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        /* ====== SmartArt Categories ====== */
        .smartart-categories {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .category-card {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            background: white;
            transition: all 0.3s;
        }

        .category-card:hover {
            border-color: #4CAF50;
            transform: translateY(-3px);
        }

        .category-icon {
            font-size: 2rem;
            color: #4CAF50;
            margin-bottom: 10px;
        }

        /* ====== Accessibility Demo ====== */
        .accessibility-demo {
            background: #fff8e1;
            border: 2px dashed #ff9800;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .alt-text-example {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }

        .good-alt { border-left: 4px solid #4CAF50; }
        .bad-alt { border-left: 4px solid #F44336; }

        /* ====== Footer ====== */
        footer {
            text-align: center;
            padding: 20px;
            background-color: #f2f2f2;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #ddd;
        }

        /* ====== Alerts ====== */
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

        /* ====== Interactive Elements ====== */
        .interactive-demo {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .demo-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .demo-btn {
            padding: 8px 16px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .demo-btn:hover {
            background: #1976D2;
        }

        .demo-visual {
            width: 100%;
            height: 200px;
            background: white;
            border: 2px solid #2196F3;
            border-radius: 5px;
            margin: 15px 0;
            position: relative;
            overflow: hidden;
        }

        .demo-object {
            position: absolute;
            width: 60px;
            height: 60px;
            background: #2196F3;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        /* ====== Responsive Design ====== */
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

            .tools-grid,
            .formatting-demo,
            .smartart-categories {
                grid-template-columns: 1fr;
            }
        }

        /* ====== Print Styles ====== */
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

        /* ====== Additional Styles ====== */
        .term {
            margin-bottom: 15px;
        }

        .term strong {
            color: #2196F3;
            display: block;
            margin-bottom: 5px;
        }

        .exam-focus {
            background: #fff3e0;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
            border-left: 5px solid #ff9800;
        }

        .exam-focus h3 {
            color: #e65100;
            margin-bottom: 15px;
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
                <strong>Access Granted:</strong> PowerPoint Week 4 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week5_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 5
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 4 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Mastering Visual Storytelling with Graphics & Media</div>
            <div class="week-tag">Week 4 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-palette"></i> Welcome to Week 4!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    Last week, you built an interactive and structurally sophisticated presentation. Now, it's time to make it visually captivating and professionally polished. This week, we move from structure to visual storytelling. You will learn to expertly insert, format, and manipulate images, icons, shapes, and SmartArt to convey ideas powerfully. We'll also integrate dynamic media—video and audio—and ensure our visuals are accessible to everyone. By the end, you'll be able to transform text-heavy slides into memorable, engaging visual experiences that command attention.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1611224923853-80b023f02d71?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80"
                        alt="Visual storytelling with graphics and media"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+VmlzdWFsIFN0b3J5dGVsbGluZyB3aXRoIEdyYXBoaWNzICYgTWVkaWE8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Transform text-heavy slides into engaging visual experiences</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Insert, crop, mask, and apply professional artistic effects to images</li>
                    <li>Create custom diagrams and workflows using SmartArt and manipulate them at the component level</li>
                    <li>Insert and format icons, 3D models, and shapes to build custom graphics and infographics</li>
                    <li>Embed and configure video and audio playback options for seamless presentation integration</li>
                    <li>Apply and edit Alt Text to all visual elements for accessibility compliance</li>
                    <li>Align, group, and layer objects with precision to achieve pixel-perfect slide layouts</li>
                </ul>
            </div>

            <!-- PowerPoint Tools Grid -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-tools"></i> Essential PowerPoint Visual Tools
                </div>

                <div class="tools-grid">
                    <div class="tool-card image-tool" onclick="showToolInfo('image')">
                        <div class="tool-icon">
                            <i class="fas fa-image"></i>
                        </div>
                        <h4>Images</h4>
                        <p>Professional photo editing and formatting</p>
                    </div>
                    <div class="tool-card smartart-tool" onclick="showToolInfo('smartart')">
                        <div class="tool-icon">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <h4>SmartArt</h4>
                        <p>Create professional diagrams and workflows</p>
                    </div>
                    <div class="tool-card icon-tool" onclick="showToolInfo('icon')">
                        <div class="tool-icon">
                            <i class="fas fa-icons"></i>
                        </div>
                        <h4>Icons & 3D Models</h4>
                        <p>Scalable vector graphics and 3D objects</p>
                    </div>
                    <div class="tool-card media-tool" onclick="showToolInfo('media')">
                        <div class="tool-icon">
                            <i class="fas fa-film"></i>
                        </div>
                        <h4>Video & Audio</h4>
                        <p>Embed and configure multimedia content</p>
                    </div>
                </div>
            </div>

            <!-- Section 1: Working with Images -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-image"></i> 1. Working with Images Like a Pro
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-upload"></i> Inserting Images</h3>
                    <ul>
                        <li><strong>From This Device:</strong> Insert images from your computer</li>
                        <li><strong>Online Pictures:</strong> Search Bing or access OneDrive</li>
                        <li><strong>Stock Images:</strong> Professional royalty-free images</li>
                        <li><strong>Drag-and-drop:</strong> Simply drag images onto your slide</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-crop-alt"></i> Image Formatting Tools</h3>
                    
                    <div class="formatting-demo">
                        <div class="format-sample">
                            <div style="width: 100%; height: 150px; background: linear-gradient(45deg, #FF9800, #FF5722); border-radius: 5px; margin-bottom: 15px;"></div>
                            <h4>Crop to Shape</h4>
                            <p style="font-size: 0.9rem;">Mask images as circles, stars, or custom shapes</p>
                        </div>
                        <div class="format-sample">
                            <div style="width: 100%; height: 150px; background: linear-gradient(45deg, #4CAF50, #8BC34A); border-radius: 5px; margin-bottom: 15px; position: relative;">
                                <div style="position: absolute; top: 20px; left: 20px; right: 20px; bottom: 20px; background: white; border-radius: 3px;"></div>
                            </div>
                            <h4>Remove Background</h4>
                            <p style="font-size: 0.9rem;">Automatic background removal tool</p>
                        </div>
                        <div class="format-sample">
                            <div style="width: 100%; height: 150px; background: linear-gradient(45deg, #2196F3, #03A9F4); border-radius: 5px; margin-bottom: 15px; filter: blur(2px);"></div>
                            <h4>Artistic Effects</h4>
                            <p style="font-size: 0.9rem;">Apply blur, paint strokes, and other effects</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Picture Format Tab Features</h3>
                    <ul>
                        <li><strong>Corrections:</strong> Adjust sharpness, brightness, and contrast</li>
                        <li><strong>Color:</strong> Recolor images to match your theme palette</li>
                        <li><strong>Picture Styles:</strong> One-click borders, shadows, and 3D effects</li>
                        <li><strong>Picture Layout:</strong> Convert images to SmartArt with captions</li>
                        <li><strong>Transparency:</strong> Set transparent colors or adjust opacity</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: SmartArt Graphics -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-project-diagram"></i> 2. Creating Conceptual Graphics with SmartArt
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-th-large"></i> SmartArt Categories</h3>
                    <div class="smartart-categories">
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <h4>List</h4>
                            <p style="font-size: 0.8rem;">Bulleted lists and processes</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-sync"></i>
                            </div>
                            <h4>Process</h4>
                            <p style="font-size: 0.8rem;">Sequential workflows</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-recycle"></i>
                            </div>
                            <h4>Cycle</h4>
                            <p style="font-size: 0.8rem;">Continuous processes</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-sitemap"></i>
                            </div>
                            <h4>Hierarchy</h4>
                            <p style="font-size: 0.8rem;">Org charts and structures</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-project-diagram"></i>
                            </div>
                            <h4>Relationship</h4>
                            <p style="font-size: 0.8rem;">Connections and networks</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-th"></i>
                            </div>
                            <h4>Matrix</h4>
                            <p style="font-size: 0.8rem;">Four-quadrant diagrams</p>
                        </div>
                        <div class="category-card">
                            <div class="category-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <h4>Pyramid</h4>
                            <p style="font-size: 0.8rem;">Proportional relationships</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-edit"></i> Advanced SmartArt Customization</h3>
                    <ul>
                        <li><strong>Text Pane:</strong> The easiest way to edit SmartArt content</li>
                        <li><strong>Adding Shapes:</strong> SmartArt Design > Add Shape > Add Shape After/Before</li>
                        <li><strong>Change Colors:</strong> Apply theme-based color schemes</li>
                        <li><strong>SmartArt Styles:</strong> 3D and polished effects with one click</li>
                        <li><strong>Convert to Shapes:</strong> Right-click > Convert to Shapes for unlimited customization</li>
                        <li><strong>Promote/Demote:</strong> Change hierarchy levels in the Text Pane</li>
                    </ul>
                </div>

                <div class="interactive-demo">
                    <h4 style="color: #1976D2; margin-bottom: 15px;"><i class="fas fa-play-circle"></i> SmartArt Editing Demo</h4>
                    <div class="demo-controls">
                        <button class="demo-btn" onclick="addSmartArtShape()">Add Shape</button>
                        <button class="demo-btn" onclick="changeSmartArtColor()">Change Color</button>
                        <button class="demo-btn" onclick="convertToShapes()">Convert to Shapes</button>
                        <button class="demo-btn" onclick="resetSmartArt()">Reset</button>
                    </div>
                    <div class="demo-visual" id="smartartDemo">
                        <div class="demo-object" style="top: 70px; left: 100px;">Step 1</div>
                        <div class="demo-object" style="top: 70px; left: 300px;">Step 2</div>
                        <div class="demo-object" style="top: 70px; left: 500px;">Step 3</div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Icons, 3D Models & Shapes -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-shapes"></i> 3. Building with Icons, 3D Models & Shapes
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-icons"></i> Icons Library</h3>
                    <ul>
                        <li><strong>Access:</strong> Insert Tab > Icons</li>
                        <li><strong>Thousands of icons:</strong> Searchable by keyword</li>
                        <li><strong>Vector format:</strong> Infinitely scalable without quality loss</li>
                        <li><strong>Format like shapes:</strong> Change fill, outline, and effects</li>
                        <li><strong>Recolor to theme:</strong> Match your presentation color palette</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cube"></i> 3D Models</h3>
                    <ul>
                        <li><strong>Stock 3D Library:</strong> Hundreds of free 3D models</li>
                        <li><strong>3D Model View tool:</strong> Rotate, tilt, and pan models</li>
                        <li><strong>Turntable animation:</strong> Make models spin automatically</li>
                        <li><strong>Insert from device:</strong> Use your own 3D models</li>
                        <li><strong>Format options:</strong> Materials, lighting, and rotation</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-draw-polygon"></i> Shapes & Merge Shapes</h3>
                    <ul>
                        <li><strong>Basic shapes:</strong> Rectangles, circles, arrows, callouts</li>
                        <li><strong>Merge Shapes tool:</strong> Found under Shape Format > Insert Shapes</li>
                        <li><strong>Union:</strong> Combine multiple shapes into one</li>
                        <li><strong>Combine:</strong> Create cutouts where shapes overlap</li>
                        <li><strong>Fragment:</strong> Break overlapping shapes into pieces</li>
                        <li><strong>Intersect:</strong> Keep only overlapping areas</li>
                        <li><strong>Subtract:</strong> Remove overlapping areas</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Pro Tip: Merge Shapes
                        </div>
                        <p>Merge Shapes is PowerPoint's secret design weapon. Use it to create custom logos, infographics, and unique graphics that can't be achieved with standard shapes.</p>
                    </div>
                </div>
            </div>

            <!-- Section 4: Video & Audio -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-film"></i> 4. Integrating Video & Audio
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-video"></i> Video Integration</h3>
                    <ul>
                        <li><strong>Sources:</strong> This Device, Stock Videos, Online Videos (YouTube)</li>
                        <li><strong>Playback Tab settings:</strong>
                            <ul>
                                <li><strong>Start:</strong> On Click, Automatically, When Clicked On</li>
                                <li><strong>Volume:</strong> Adjust playback volume</li>
                                <li><strong>Loop until Stopped:</strong> Continuous playback</li>
                                <li><strong>Rewind after Playing:</strong> Return to first frame</li>
                                <li><strong>Hide While Not Playing:</strong> Hide video icon</li>
                            </ul>
                        </li>
                        <li><strong>Format Tab settings:</strong>
                            <ul>
                                <li><strong>Poster Frame:</strong> Set custom thumbnail</li>
                                <li><strong>Video Shape:</strong> Mask video as a shape</li>
                                <li><strong>Video Border:</strong> Add borders and effects</li>
                                <li><strong>Video Effects:</strong> Shadows, reflections, 3D</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-volume-up"></i> Audio Integration</h3>
                    <ul>
                        <li><strong>Sources:</strong> This Device, Record Audio, Online Audio</li>
                        <li><strong>Playback Options:</strong>
                            <ul>
                                <li><strong>Start:</strong> On Click, Automatically, Play Across Slides</li>
                                <li><strong>Volume:</strong> Adjust audio levels</li>
                                <li><strong>Hide During Show:</strong> Hide speaker icon</li>
                                <li><strong>Loop until Stopped:</strong> Continuous audio</li>
                                <li><strong>Rewind after Playing:</strong> Return to beginning</li>
                            </ul>
                        </li>
                        <li><strong>Audio Tools:</strong>
                            <ul>
                                <li><strong>Trim Audio:</strong> Remove unwanted parts</li>
                                <li><strong>Fade In/Out:</strong> Smooth audio transitions</li>
                                <li><strong>Bookmarks:</strong> Mark important timestamps</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1558618666-fcd25c85cd64?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80"
                        alt="Multimedia integration in presentations"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+TXVsdGltZWRpYSBJbnRlZ3JhdGlvbiBpbiBQcmVzZW50YXRpb25zPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Professional multimedia integration enhances presentation engagement</div>
                </div>
            </div>

            <!-- Section 5: Design Principles -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-ruler-combined"></i> 5. Essential Graphic Design Principles
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-mouse-pointer"></i> Object Selection & Arrangement</h3>
                    <ul>
                        <li><strong>Selection Pane (Alt, J, D, A):</strong>
                            <ul>
                                <li>Lists all objects on the slide</li>
                                <li>Show/hide objects with eye icons</li>
                                <li>Rename objects for easy identification</li>
                                <li>Reorder objects with drag-and-drop</li>
                            </ul>
                        </li>
                        <li><strong>Align & Distribute (Shape Format > Align):</strong>
                            <ul>
                                <li>Align Left, Center, Right, Top, Middle, Bottom</li>
                                <li>Distribute Horizontally/Vertically</li>
                                <li>Align to Slide or Selected Objects</li>
                            </ul>
                        </li>
                        <li><strong>Grouping (Ctrl+G / Ctrl+Shift+G):</strong>
                            <ul>
                                <li>Group objects to move/format as one</li>
                                <li>Ungroup to edit individual objects</li>
                                <li>Regroup after ungrouping</li>
                            </ul>
                        </li>
                        <li><strong>Layering:</strong>
                            <ul>
                                <li>Bring Forward / Send Backward</li>
                                <li>Bring to Front / Send to Back</li>
                                <li>Use Selection Pane for complex layers</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="interactive-demo">
                    <h4 style="color: #1976D2; margin-bottom: 15px;"><i class="fas fa-object-group"></i> Object Arrangement Demo</h4>
                    <div class="demo-controls">
                        <button class="demo-btn" onclick="alignObjects('left')">Align Left</button>
                        <button class="demo-btn" onclick="alignObjects('center')">Align Center</button>
                        <button class="demo-btn" onclick="alignObjects('distribute')">Distribute</button>
                        <button class="demo-btn" onclick="groupObjects()">Group</button>
                        <button class="demo-btn" onclick="resetObjects()">Reset</button>
                    </div>
                    <div class="demo-visual" id="arrangementDemo">
                        <div class="demo-object" style="top: 50px; left: 100px; background: #FF9800;">1</div>
                        <div class="demo-object" style="top: 100px; left: 300px; background: #4CAF50;">2</div>
                        <div class="demo-object" style="top: 150px; left: 500px; background: #2196F3;">3</div>
                    </div>
                </div>
            </div>

            <!-- Section 6: Accessibility -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-universal-access"></i> 6. Accessibility: Alt Text for All Visuals
                </div>

                <div class="accessibility-demo">
                    <h3 style="color: #FF9800; margin-bottom: 20px;"><i class="fas fa-info-circle"></i> Why Accessibility Matters</h3>
                    <p style="margin-bottom: 20px;">Alt Text ensures that screen readers can describe visual content to users with visual impairments. This is not just good practice—it's often a legal requirement for accessibility compliance.</p>

                    <div class="subsection">
                        <h3><i class="fas fa-keyboard"></i> How to Add Alt Text</h3>
                        <ul>
                            <li><strong>Method 1:</strong> Right-click object > Edit Alt Text</li>
                            <li><strong>Method 2:</strong> Select object > Format Tab > Alt Text</li>
                            <li><strong>Method 3:</strong> Picture Format > Alt Text</li>
                            <li><strong>For multiple objects:</strong> Select all > add Alt Text once</li>
                        </ul>
                    </div>

                    <div class="alt-text-example good-alt">
                        <h4><i class="fas fa-check-circle" style="color: #4CAF50;"></i> Good Alt Text Example</h4>
                        <p><strong>For a sales chart:</strong> "Bar chart showing 25% increase in Q3 sales across all regions, with Western region leading at $2.5M."</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;"><em>Describes purpose and key takeaway, not just appearance.</em></p>
                    </div>

                    <div class="alt-text-example bad-alt">
                        <h4><i class="fas fa-times-circle" style="color: #F44336;"></i> Poor Alt Text Example</h4>
                        <p><strong>For the same chart:</strong> "Chart with blue and red bars"</p>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 5px;"><em>Only describes appearance, not meaning or purpose.</em></p>
                    </div>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Accessibility Best Practices
                        </div>
                        <ul>
                            <li><strong>Be concise:</strong> 1-2 sentences maximum</li>
                            <li><strong>Describe purpose:</strong> What does the visual communicate?</li>
                            <li><strong>Include key data:</strong> Mention important numbers or trends</li>
                            <li><strong>Mark as decorative:</strong> For purely decorative images</li>
                            <li><strong>Use for all visuals:</strong> Images, charts, SmartArt, shapes, icons</li>
                            <li><strong>Check spelling:</strong> Screen readers will read errors</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> Step-by-Step Practice Exercise: Company Culture Infographic
                </div>
                <p><strong>Objective:</strong> Apply your visual storytelling skills to create a single, content-rich, and beautifully designed slide.</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #1976D2; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Set Up & Insert a Background:</strong>
                            <ul>
                                <li>Open a new presentation. Apply the "Ion" theme</li>
                                <li>On Slide 1, right-click the slide background > Format Background</li>
                                <li>Choose Gradient fill. Select a subtle two-color gradient</li>
                            </ul>
                        </li>
                        <li><strong>Build a Custom SmartArt Process:</strong>
                            <ul>
                                <li>Go to Insert > SmartArt. Choose Process > Basic Bending Process</li>
                                <li>In the Text Pane, enter: 1) Recruit, 2) Onboard, 3) Train, 4) Succeed</li>
                                <li>With the SmartArt selected, go to SmartArt Design > Change Colors and apply a colorful accent</li>
                                <li>Go to SmartArt Design > Convert > Convert to Shapes. Now, ungroup (Ctrl+Shift+G) the shapes once</li>
                            </ul>
                        </li>
                        <li><strong>Enhance with Icons and Formatting:</strong>
                            <ul>
                                <li>Select the "Recruit" circle. Go to Shape Format > Shape Fill > Picture</li>
                                <li>Choose "From Online Pictures" and search for a "person" icon</li>
                                <li>Do this for each circle with relevant icons</li>
                                <li>Select all four circles, then use Shape Format > Align > Align Middle and Distribute Horizontally</li>
                            </ul>
                        </li>
                        <li><strong>Insert and Format Supporting Graphics:</strong>
                            <ul>
                                <li>Go to Insert > 3D Models > Stock 3D Models</li>
                                <li>Add a simple model like a "city" or "office building"</li>
                                <li>Insert a rounded rectangle shape below your process</li>
                                <li>Add text: "Our Core Values: Integrity, Innovation, Teamwork"</li>
                            </ul>
                        </li>
                        <li><strong>Add a Media Element:</strong>
                            <ul>
                                <li>Go to Insert > Media > Audio > Record Audio</li>
                                <li>Record a 5-second welcome message: "Welcome to our team"</li>
                                <li>Set audio to Start: On Click and check Hide During Show</li>
                            </ul>
                        </li>
                        <li><strong>Apply Final Polish and Accessibility:</strong>
                            <ul>
                                <li>Use the Selection Pane to rename objects logically</li>
                                <li>Group all objects (Ctrl+G) and center on slide</li>
                                <li>Add Alt Text to the 3D Model and mark decorative circles</li>
                            </ul>
                        </li>
                        <li><strong>Save and Review:</strong>
                            <ul>
                                <li>Enter Slide Show mode (F5) and test audio</li>
                                <li>Save as: YourName_Visual_Infographic_WK4.pptx</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Submission:</strong> Upload your completed infographic to the course portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Keyboard Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> Keyboard Shortcuts Cheat Sheet (Week 4 Focus)
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
                            <td><span class="shortcut-key">Alt > N > P</span></td>
                            <td>Insert Picture</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > S</span></td>
                            <td>Insert SmartArt</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > I</span></td>
                            <td>Insert Icons/3D Models</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > V</span></td>
                            <td>Insert Video</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt > N > O</span></td>
                            <td>Insert Audio</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + G</span></td>
                            <td>Group selected objects</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + G</span></td>
                            <td>Ungroup selected objects</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, D, A</span></td>
                            <td>Open Selection Pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt, J, D, T</span></td>
                            <td>Open Animation Pane</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + [</span></td>
                            <td>Send object backward</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + ]</span></td>
                            <td>Bring object forward</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + [</span></td>
                            <td>Send object to back</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + ]</span></td>
                            <td>Bring object to front</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + H, G, A</span></td>
                            <td>Align objects</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + D</span></td>
                            <td>Duplicate selected object</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>SmartArt</strong>
                    <p>A tool for creating professional-quality diagrams and information graphics with pre-designed layouts and formatting options.</p>
                </div>

                <div class="term">
                    <strong>Crop to Shape</strong>
                    <p>The process of masking an image to fit within a specific shape (e.g., circle, star, arrow) rather than just cropping to a rectangle.</p>
                </div>

                <div class="term">
                    <strong>Merge Shapes</strong>
                    <p>A feature that allows you to combine, fragment, or subtract multiple shapes to create new, custom shapes that aren't available in the standard shapes library.</p>
                </div>

                <div class="term">
                    <strong>Selection Pane</strong>
                    <p>A panel that lists all objects on a slide, allowing you to manage visibility, ordering, and selection—especially useful for complex slides with many overlapping objects.</p>
                </div>

                <div class="term">
                    <strong>Alt Text (Alternative Text)</strong>
                    <p>Descriptive text added to a visual element so screen reader software can describe it to users who are blind or have low vision. Essential for accessibility compliance.</p>
                </div>

                <div class="term">
                    <strong>Poster Frame</strong>
                    <p>A static image displayed on a slide to represent a video before it is played. Can be a frame from the video or a custom image.</p>
                </div>

                <div class="term">
                    <strong>3D Model View</strong>
                    <p>A set of controls that allow you to rotate, tilt, and pan 3D models inserted into PowerPoint presentations.</p>
                </div>

                <div class="term">
                    <strong>Text Pane</strong>
                    <p>A specialized text editing panel that appears when working with SmartArt, making it easy to add and organize content in diagram structures.</p>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="self-review-box">
                <div class="self-review-title">
                    <i class="fas fa-question-circle"></i> Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <ol>
                        <li>You have inserted a Process SmartArt, but need to add an extra step between two existing shapes. What is the most efficient way to do this?</li>
                        <li>You want to make a company logo, which is a square JPEG, appear as a circle on your slide. What two-step formatting feature do you use?</li>
                        <li>You've inserted a background music track. You want it to play softly across the first three slides and then stop, without showing an icon. What three Playback settings must you configure?</li>
                        <li>A slide contains 20 overlapping shapes. What tool should you use to select a single shape buried at the bottom of the stack?</li>
                        <li>When writing Alt Text for a complex chart, what is the most important principle to follow?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="week4Answers">
                    <h4 style="color: #e65100; margin-bottom: 10px;"><i class="fas fa-lightbulb"></i> Answers:</h4>
                    <ol>
                        <li><strong>Answer:</strong> Select a shape adjacent to where you want the new shape, then go to SmartArt Design > Add Shape > Add Shape After/Before, or use the Text Pane and press Enter after the relevant bullet point.</li>
                        <li><strong>Answer:</strong> First, use Picture Format > Crop > Crop to Shape and select a circle. Then, use Picture Format > Crop > Aspect Ratio > 1:1 to ensure it's a perfect circle.</li>
                        <li><strong>Answer:</strong> 1) Set Start to "Play Across Slides", 2) Set the number of slides in "Play Across Slides" to 3, 3) Check "Hide During Show". Also consider setting a Fade Out for smooth ending.</li>
                        <li><strong>Answer:</strong> The Selection Pane (Alt, J, D, A). It lists all objects by name, allowing you to select the one you need directly or by clicking its eye icon to isolate it.</li>
                        <li><strong>Answer:</strong> Describe the chart's purpose and key takeaway, not just its appearance. For example: "Bar chart showing Q3 sales increasing 15% across all regions, with the Western region leading at $2.5M."</li>
                    </ol>
                </div>

                <button onclick="document.getElementById('week4Answers').style.display='block'" class="download-btn" style="background: #ff9800;">
                    <i class="fas fa-eye"></i> Show Answers
                </button>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> Tips for Success
                </div>
                <ul>
                    <li><strong>Less is More:</strong> One powerful, well-formatted image is better than five cluttered ones. Use white space strategically to guide attention.</li>
                    <li><strong>Consistency Creates Professionalism:</strong> Apply the same style of borders, shadows, and color filters to all images on a slide or in a deck for a cohesive look.</li>
                    <li><strong>Use Stock Media Wisely:</strong> The stock image/video/icon libraries are excellent resources. Always recolor icons to match your theme palette for a custom feel.</li>
                    <li><strong>Accessibility First:</strong> Make adding Alt Text the final, non-negotiable step in your workflow for every single visual object.</li>
                    <li><strong>Master the Selection Pane:</strong> In complex slides, naming objects in the Selection Pane (e.g., "Chart_2023", "Logo_Header") will save you immense time and frustration.</li>
                    <li><strong>Learn Merge Shapes:</strong> This is PowerPoint's secret weapon for custom graphic design. Practice Union, Combine, and Fragment to create unique graphics.</li>
                    <li><strong>Use SmartArt as a Starting Point:</strong> Don't be limited by default SmartArt layouts. Convert to shapes and customize endlessly for unique diagrams.</li>
                    <li><strong>Test Media Playback:</strong> Always test video and audio in Slide Show mode before presenting to ensure proper timing and volume.</li>
                    <li><strong>Consider File Size:</strong> Compress images (Picture Format > Compress Pictures) to keep presentation file sizes manageable.</li>
                    <li><strong>Use Gridlines and Guides:</strong> Enable View > Gridlines and Guides for precise object alignment.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> Next Week Preview
                </div>
                <p><strong>Week 5: Animating with Purpose & Mastering Transitions</strong></p>
                <p>In Week 5, we'll bring your stunning visuals to life! You'll learn to:</p>
                <ul>
                    <li>Apply and customize entrance, emphasis, and exit animations using the Animation Pane</li>
                    <li>Create sophisticated motion paths for precise object movement</li>
                    <li>Use the Animation Painter to quickly copy animation styles between objects</li>
                    <li>Set timing and triggers for complex animation sequences</li>
                    <li>Explore Morph, the most powerful transition, to create cinematic scene changes</li>
                    <li>Combine animations and transitions for professional storytelling</li>
                    <li>Use animation to guide audience attention and emphasize key points</li>
                    <li>Create interactive presentations with click-triggered animations</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring the infographic slide you created this week—we'll animate it next week!</p>
                <p><strong>MO-300 Exam Focus:</strong> Animation effects, motion paths, transition types, Morph transition, animation timing.</p>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/insert-a-picture-in-powerpoint-5f7368d4-2b94-4d2d-8d2d-6c3c4c8c5c5b" target="_blank">Microsoft: Insert and Format Pictures in PowerPoint</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-smartart-graphic-9b0f0b5b-7b0f-4b5b-9b0f-0b5b7b0f4b5b" target="_blank">Microsoft: Create SmartArt Graphics</a></li>
                    <li><a href="https://support.microsoft.com/office/insert-icons-in-powerpoint-9b0f0b5b-7b0f-4b5b-9b0f-0b5b7b0f4b5b" target="_blank">Microsoft: Insert and Format Icons</a></li>
                    <li><a href="https://support.microsoft.com/office/insert-a-video-from-your-computer-9b0f0b5b-7b0f-4b5b-9b0f-0b5b7b0f4b5b" target="_blank">Microsoft: Insert and Play Video</a></li>
                    <li><a href="https://support.microsoft.com/office/make-your-powerpoint-presentations-accessible-6f7772b2-2f33-4bd2-8ca7-dae3b2b3ef25" target="_blank">Microsoft: PowerPoint Accessibility Guidelines</a></li>
                    <li><strong>Practice Files:</strong> Download sample images and media files from the course portal</li>
                    <li><strong>Video Tutorials:</strong> Step-by-step video guides for each visual tool</li>
                    <li><strong>Icon Libraries:</strong> Additional icon sets and 3D model resources</li>
                    <li><strong>Week 4 Quiz:</strong> Test your understanding (available in portal)</li>
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
                    <li><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM (Virtual)</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week4.php">Week 4 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Accessibility Resources:</strong> <a href="https://www.w3.org/WAI/standards-guidelines/" target="_blank">W3C Accessibility Guidelines</a></li>
                    <li><strong>Design Inspiration:</strong> <a href="https://dribbble.com/" target="_blank">Dribbble Design Community</a></li>
                </ul>
            </div>

            <!-- Download Section -->
            <div style="text-align: center; margin: 40px 0;">
                <button onclick="printHandout()" class="download-btn" style="margin-right: 15px;">
                    <i class="fas fa-print"></i> Print Handout
                </button>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week4_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 4 Quiz
                </a> -->
                <button onclick="downloadExerciseFiles()" class="download-btn" style="background: #9c27b0; margin-left: 15px;">
                    <i class="fas fa-download"></i> Exercise Files
                </button>
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 4 Handout</p>
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
        // ====== Initialization Functions ======
        document.addEventListener('DOMContentLoaded', function() {
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

            // Image fallback handler
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });

            // Accessibility enhancements
            const interactiveElements = document.querySelectorAll('.tool-card, .category-card, .format-sample, .demo-btn, button, a');
            interactiveElements.forEach(el => {
                el.setAttribute('tabindex', '0');
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });

            // Track handout access
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    console.log('PowerPoint Week 4 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                    // In production, send AJAX request to log access
                }
            });
        });

        // ====== Tool Information Functions ======
        function showToolInfo(toolType) {
            const toolInfo = {
                'image': {
                    title: 'Image Tools',
                    content: 'Professional photo editing tools including crop, background removal, color correction, and artistic effects. Use the Picture Format tab for advanced editing.'
                },
                'smartart': {
                    title: 'SmartArt Graphics',
                    content: 'Create professional diagrams and workflows. Convert to shapes for unlimited customization. Use the Text Pane for easy content editing.'
                },
                'icon': {
                    title: 'Icons & 3D Models',
                    content: 'Thousands of scalable vector icons and 3D models. Format like shapes and recolor to match your theme. Use 3D controls to rotate models.'
                },
                'media': {
                    title: 'Video & Audio',
                    content: 'Embed and configure multimedia. Set playback options, trim content, and add fades. Use Poster Frames for video thumbnails.'
                }
            };

            const info = toolInfo[toolType];
            if (info) {
                alert(`${info.title}\n\n${info.content}\n\nTry this feature in your PowerPoint practice!`);
            }
        }

        // ====== SmartArt Demo Functions ======
        let smartArtShapes = 3;

        function addSmartArtShape() {
            if (smartArtShapes < 6) {
                smartArtShapes++;
                const demo = document.getElementById('smartartDemo');
                const newShape = document.createElement('div');
                newShape.className = 'demo-object';
                newShape.textContent = `Step ${smartArtShapes}`;
                newShape.style.top = '70px';
                newShape.style.left = (100 + (smartArtShapes - 1) * 200) + 'px';
                demo.appendChild(newShape);
                
                // Adjust existing shapes if needed
                if (smartArtShapes > 4) {
                    const shapes = demo.querySelectorAll('.demo-object');
                    shapes.forEach((shape, index) => {
                        shape.style.left = (100 + index * (600 / (smartArtShapes - 1))) + 'px';
                    });
                }
                
                showAlert(`Added Step ${smartArtShapes} to SmartArt process`);
            } else {
                showAlert('Maximum of 6 shapes for this demo');
            }
        }

        function changeSmartArtColor() {
            const colors = ['#2196F3', '#4CAF50', '#FF9800', '#9C27B0', '#F44336'];
            const demo = document.getElementById('smartartDemo');
            const shapes = demo.querySelectorAll('.demo-object');
            
            shapes.forEach(shape => {
                const randomColor = colors[Math.floor(Math.random() * colors.length)];
                shape.style.background = randomColor;
            });
            
            showAlert('Changed SmartArt colors to theme palette');
        }

        function convertToShapes() {
            const demo = document.getElementById('smartartDemo');
            const shapes = demo.querySelectorAll('.demo-object');
            
            shapes.forEach(shape => {
                shape.style.borderRadius = '0';
                shape.style.transform = 'rotate(5deg)';
                shape.style.boxShadow = '3px 3px 5px rgba(0,0,0,0.3)';
            });
            
            showAlert('Converted SmartArt to individual shapes for customization');
        }

        function resetSmartArt() {
            const demo = document.getElementById('smartartDemo');
            demo.innerHTML = '';
            
            for (let i = 1; i <= 3; i++) {
                const shape = document.createElement('div');
                shape.className = 'demo-object';
                shape.textContent = `Step ${i}`;
                shape.style.top = '70px';
                shape.style.left = (100 + (i - 1) * 200) + 'px';
                shape.style.background = '#2196F3';
                shape.style.borderRadius = '5px';
                shape.style.transform = 'none';
                shape.style.boxShadow = 'none';
                demo.appendChild(shape);
            }
            
            smartArtShapes = 3;
            showAlert('Reset SmartArt to default state');
        }

        // ====== Object Arrangement Demo Functions ======
        function alignObjects(alignment) {
            const demo = document.getElementById('arrangementDemo');
            const shapes = demo.querySelectorAll('.demo-object');
            
            switch(alignment) {
                case 'left':
                    shapes.forEach(shape => {
                        shape.style.left = '100px';
                    });
                    showAlert('Aligned all objects to left');
                    break;
                    
                case 'center':
                    shapes.forEach(shape => {
                        shape.style.left = '270px';
                    });
                    showAlert('Aligned all objects to center');
                    break;
                    
                case 'distribute':
                    shapes.forEach((shape, index) => {
                        shape.style.left = (100 + index * 200) + 'px';
                    });
                    showAlert('Distributed objects horizontally');
                    break;
            }
        }

        function groupObjects() {
            const demo = document.getElementById('arrangementDemo');
            const shapes = demo.querySelectorAll('.demo-object');
            
            // Visual grouping effect
            shapes.forEach(shape => {
                shape.style.border = '2px dashed #333';
                shape.style.transform = 'scale(0.9)';
            });
            
            // Move them closer together to show grouping
            shapes[0].style.left = '200px';
            shapes[1].style.left = '270px';
            shapes[2].style.left = '340px';
            
            showAlert('Grouped objects (Ctrl+G). They now move and format as one unit.');
        }

        function resetObjects() {
            const demo = document.getElementById('arrangementDemo');
            const shapes = demo.querySelectorAll('.demo-object');
            
            // Reset positions and styles
            shapes[0].style.left = '100px';
            shapes[0].style.top = '50px';
            shapes[0].style.background = '#FF9800';
            shapes[0].style.border = 'none';
            shapes[0].style.transform = 'none';
            
            shapes[1].style.left = '300px';
            shapes[1].style.top = '100px';
            shapes[1].style.background = '#4CAF50';
            shapes[1].style.border = 'none';
            shapes[1].style.transform = 'none';
            
            shapes[2].style.left = '500px';
            shapes[2].style.top = '150px';
            shapes[2].style.background = '#2196F3';
            shapes[2].style.border = 'none';
            shapes[2].style.transform = 'none';
            
            showAlert('Reset object arrangement demo');
        }

        // ====== Utility Functions ======
        function showAlert(message) {
            const alertBox = document.createElement('div');
            alertBox.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #2196F3;
                color: white;
                padding: 12px 24px;
                border-radius: 5px;
                z-index: 1000;
                animation: fadeOut 2s forwards;
                box-shadow: 0 3px 10px rgba(0,0,0,0.2);
                font-weight: 500;
            `;
            alertBox.textContent = message;
            document.body.appendChild(alertBox);
            
            setTimeout(() => {
                alertBox.remove();
            }, 2000);
        }

        function printHandout() {
            window.print();
        }

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

        function downloadExerciseFiles() {
            const files = [
                'Company_Culture_Infographic_Template.potx',
                'Sample_Images.zip',
                'Icon_Set.zip',
                'Week4_Practice_Instructions.pdf'
            ];
            
            let message = 'Week 4 Exercise Files:\n\n';
            files.forEach(file => {
                message += `• ${file}\n`;
            });
            message += '\nThese files would download in a production environment.';
            
            alert(message);
            
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/exercises/week4_files.zip';
        }

        // ====== Keyboard Shortcut Practice ======
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'g': 'Group Objects (Ctrl+G)',
                'shift+g': 'Ungroup Objects (Ctrl+Shift+G)',
                '[': 'Send Backward (Ctrl+[)',
                ']': 'Bring Forward (Ctrl+])'
            };
            
            let shortcutKey = '';
            if (e.ctrlKey) {
                if (e.key === 'g') shortcutKey = e.shiftKey ? 'shift+g' : 'g';
                if (e.key === '[') shortcutKey = '[';
                if (e.key === ']') shortcutKey = ']';
            }
            
            if (shortcuts[shortcutKey]) {
                const shortcutAlert = document.createElement('div');
                shortcutAlert.style.cssText = `
                    position: fixed;
                    top: 60px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #4CAF50;
                    color: white;
                    padding: 8px 16px;
                    border-radius: 5px;
                    z-index: 1000;
                    animation: fadeOut 2s forwards;
                `;
                shortcutAlert.textContent = `PowerPoint Shortcut: ${shortcuts[shortcutKey]}`;
                document.body.appendChild(shortcutAlert);
                
                setTimeout(() => {
                    shortcutAlert.remove();
                }, 2000);
            }
            
            // Alt key sequence simulation
            if (e.altKey && e.key === 'n') {
                setTimeout(() => {
                    const toolChoices = [
                        'P: Insert Picture',
                        'S: Insert SmartArt',
                        'I: Insert Icons',
                        'V: Insert Video',
                        'O: Insert Audio'
                    ];
                    
                    alert('Alt+N opens the Insert tab. Then press:\n\n' + toolChoices.join('\n'));
                }, 100);
            }
        });

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                0% { opacity: 1; }
                70% { opacity: 1; }
                100% { opacity: 0; }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .pulse {
                animation: pulse 0.5s ease-in-out;
            }
        `;
        document.head.appendChild(style);

        // ====== Self-Review Answer Key ======
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                const answers = [
                    "1. Select adjacent shape > SmartArt Design > Add Shape > Add Shape After/Before",
                    "2. Picture Format > Crop > Crop to Shape (circle) then > Aspect Ratio > 1:1",
                    "3. Start: 'Play Across Slides', Slides: 3, Hide During Show: checked",
                    "4. Selection Pane (Alt, J, D, A) - select by name or eye icon",
                    "5. Describe purpose/key takeaway, not just appearance"
                ];
                
                const answerBox = document.createElement('div');
                answerBox.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    padding: 30px;
                    border-radius: 10px;
                    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
                    z-index: 2000;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                `;
                
                answerBox.innerHTML = `
                    <h3 style="color: #2196F3; margin-bottom: 20px;">Week 4 Self-Review Answers</h3>
                    <ol style="margin-bottom: 25px;">
                        ${answers.map((answer, index) => `<li style="margin-bottom: 15px;">${answer}</li>`).join('')}
                    </ol>
                    <button onclick="this.parentElement.remove()" style="
                        background: #2196F3;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 5px;
                        cursor: pointer;
                        float: right;
                    ">
                        Close
                    </button>
                    <div style="clear: both;"></div>
                `;
                
                document.body.appendChild(answerBox);
            }
        });

        // ====== Image Editing Simulation ======
        function simulateImageEdit(action) {
            const actions = {
                'crop': 'Cropped image to focus on subject',
                'remove-bg': 'Removed background using automatic tool',
                'recolor': 'Recolored image to match theme palette',
                'effect': 'Applied artistic effect for visual style'
            };
            
            if (actions[action]) {
                showAlert(actions[action]);
                
                // Visual feedback
                const imageContainers = document.querySelectorAll('.image-container');
                if (imageContainers.length > 0) {
                    imageContainers[0].classList.add('pulse');
                    setTimeout(() => {
                        imageContainers[0].classList.remove('pulse');
                    }, 500);
                }
            }
        }

        // ====== Accessibility Checker Simulation ======
        function runAccessibilityCheck() {
            const issues = [
                '✓ All images have Alt Text',
                '✓ Charts include descriptive Alt Text',
                '✓ Decorative images marked appropriately',
                '✓ Color contrast meets WCAG standards',
                '✗ One SmartArt missing Alt Text',
                '✓ Video includes captions'
            ];
            
            const checkBox = document.createElement('div');
            checkBox.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 5px 30px rgba(0,0,0,0.3);
                z-index: 2000;
                max-width: 500px;
                width: 90%;
            `;
            
            checkBox.innerHTML = `
                <h3 style="color: #2196F3; margin-bottom: 20px;">
                    <i class="fas fa-universal-access"></i> Accessibility Check
                </h3>
                <div style="margin-bottom: 25px;">
                    ${issues.map(issue => `
                        <div style="
                            padding: 8px 12px;
                            margin-bottom: 8px;
                            border-radius: 4px;
                            background: ${issue.startsWith('✓') ? '#E8F5E9' : '#FFEBEE'};
                            color: ${issue.startsWith('✓') ? '#2E7D32' : '#C62828'};
                            font-family: monospace;
                        ">
                            ${issue}
                        </div>
                    `).join('')}
                </div>
                <p style="color: #666; font-size: 0.9rem; margin-bottom: 20px;">
                    Always run Accessibility Checker before finalizing presentations.
                </p>
                <button onclick="this.parentElement.remove()" style="
                    background: #2196F3;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 5px;
                    cursor: pointer;
                    float: right;
                ">
                    Close Checker
                </button>
                <div style="clear: both;"></div>
            `;
            
            document.body.appendChild(checkBox);
        }

        // Make functions available globally
        window.simulateImageEdit = simulateImageEdit;
        window.runAccessibilityCheck = runAccessibilityCheck;
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
    $viewer = new PowerPointWeek4HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>