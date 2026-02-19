<?php
// modules/shared/course_materials/MSWord/word_week4_view.php

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
 * Word Week 4 Handout Viewer Class with PDF Download
 */
class WordWeek4HandoutViewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor'];
    
    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
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
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
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
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->class_id, $this->user_id]);
    }
    
    /**
     * Check general student access to Word courses
     */
    private function checkGeneralStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
        return $this->executeAccessQuery($sql, [$this->user_id]);
    }
    
    /**
     * Check general instructor access to Word courses
     */
    private function checkGeneralInstructorAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id
                WHERE cb.instructor_id = ?
                AND c.title LIKE '%Microsoft Word (Office 2019)%'";
        
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
     * Check if mPDF is available
     */
    private function isMPDFAvailable(): bool
    {
        // Check multiple possible locations
        $possiblePaths = [
            __DIR__ . '/../../../../vendor/mpdf/mpdf/src/Mpdf.php',
            __DIR__ . '/../../../../vendor/autoload.php',
            '/usr/share/php/mpdf/mpdf/src/Mpdf.php',
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return true;
            }
        }
        
        return false;
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
            $mpdf->SetTitle('Week 4: Inserting and Formatting Graphic Elements');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Graphic Elements, Images, Shapes, SmartArt');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week4_Graphic_Elements_' . date('Y-m-d') . '.pdf';
            
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
            
            <div style="margin-top: 30px; padding: 15px; background: #e3f2fd; border-radius: 5px;">
                <h4>Technical Information:</h4>
                <ul>
                    <li>PHP Version: ' . PHP_VERSION . '</li>
                    <li>Server: ' . $_SERVER['SERVER_SOFTWARE'] . '</li>
                    <li>mPDF Status: ' . ($this->isMPDFAvailable() ? 'Detected' : 'Not Found') . '</li>
                </ul>
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
        $user_role = $this->user_role;
        $class_id = $this->class_id;
        $user_email = $_SESSION['user_email'] ?? '';
        $instructor_name = $_SESSION['instructor_name'] ?? 'Your Instructor';
        $instructor_email = $_SESSION['instructor_email'] ?? 'instructor@impactdigitalacademy.com';
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 12pt;">
            <h1 style="color: #0d3d8c; border-bottom: 2px solid #0d3d8c; padding-bottom: 10px; font-size: 18pt;">
                Week 4: Inserting and Formatting Graphic Elements
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 4!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we dive into the visual side of Word. You'll learn how to enhance your documents with images, shapes, SmartArt, 3D models, and text boxes. These elements help communicate ideas more effectively and make your documents engaging and professional. Mastering graphic elements is essential for creating reports, flyers, brochures, and more—and it's a key skill tested on the MO-100 exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Insert various graphic elements: pictures, shapes, SmartArt, 3D models, screenshots, and text boxes.</li>
                    <li>Format illustrations by applying artistic effects, styles, and backgrounds.</li>
                    <li>Modify graphic elements by positioning, wrapping text, and adding alternative text for accessibility.</li>
                    <li>Add and edit text within shapes, text boxes, and SmartArt graphics.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Inserting Illustrations and Text Boxes</h3>
                <p><strong>Shapes:</strong></p>
                <ul>
                    <li>Insert → Shapes → choose from lines, rectangles, arrows, callouts, etc.</li>
                    <li>Drag to draw, use Shift to maintain proportions.</li>
                </ul>
                
                <p><strong>Pictures:</strong></p>
                <ul>
                    <li>Insert → Pictures → choose from This Device, Online Pictures, or Stock Images.</li>
                </ul>
                
                <p><strong>3D Models:</strong></p>
                <ul>
                    <li>Insert → 3D Models → from online sources or local files.</li>
                    <li>Rotate and view from different angles.</li>
                </ul>
                
                <p><strong>SmartArt Graphics:</strong></p>
                <ul>
                    <li>Insert → SmartArt → choose from lists, processes, hierarchies, etc.</li>
                    <li>Conveys information visually.</li>
                </ul>
                
                <p><strong>Screenshots and Screen Clippings:</strong></p>
                <ul>
                    <li>Insert → Screenshot → capture entire window or screen clipping.</li>
                </ul>
                
                <p><strong>Text Boxes:</strong></p>
                <ul>
                    <li>Insert → Text Box → choose from built-in styles or draw your own.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Formatting Illustrations and Text Boxes</h3>
                <ul>
                    <li><strong>Artistic Effects:</strong> Select picture → Picture Format → Artistic Effects (e.g., pencil sketch, paint strokes).</li>
                    <li><strong>Picture Effects and Styles:</strong> Apply shadows, reflections, glows, bevels, and 3D rotations.</li>
                    <li><strong>Remove Backgrounds:</strong> Picture Format → Remove Background → mark areas to keep/remove.</li>
                    <li><strong>Format Shapes and Text Boxes:</strong> Shape Format tab → change fill, outline, effects.</li>
                    <li><strong>Format SmartArt:</strong> SmartArt Design and Format tabs → change layout, colors, styles.</li>
                    <li><strong>Format 3D Models:</strong> 3D Model tab → adjust scene, lighting, and rotation.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Adding Text to Graphic Elements</h3>
                <ul>
                    <li><strong>Text Boxes:</strong> Click inside and type; format text using Home tab.</li>
                    <li><strong>Shapes:</strong> Right-click shape → Add Text.</li>
                    <li><strong>SmartArt:</strong> Click "[Text]" in SmartArt graphic or use Text Pane (SmartArt Design → Text Pane).</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">4. Modifying Graphic Elements</h3>
                <p><strong>Positioning Objects:</strong></p>
                <ul>
                    <li>Drag to move, or use Layout Options to align.</li>
                    <li>Arrange → Position for precise placement.</li>
                </ul>
                
                <p><strong>Wrapping Text Around Objects:</strong></p>
                <ul>
                    <li>Select object → Layout Options → choose text wrap style:</li>
                    <li style="margin-left: 20px;">• Square, Tight, Through, Top and Bottom, Behind Text, In Front of Text.</li>
                </ul>
                
                <p><strong>Adding Alternative Text (Alt Text):</strong></p>
                <ul>
                    <li>Right-click object → Edit Alt Text.</li>
                    <li>Describe the object for screen readers (accessibility requirement).</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Design a Product Flyer</strong></p>
                <p>Follow these steps to create a visually appealing flyer:</p>
                <ol>
                    <li>Open a new document and set orientation to Landscape (Layout → Orientation).</li>
                    <li>Insert a WordArt title: Insert → WordArt → type "Amazing New Product".</li>
                    <li>Insert a picture (use a stock image or local file) and remove the background.</li>
                    <li>Insert a shape (e.g., rounded rectangle) behind the picture for a frame.</li>
                    <li>Add a text box with a short product description.</li>
                    <li>Insert a SmartArt graphic (Process type) to show 3 steps: "Buy", "Enjoy", "Share".</li>
                    <li>Insert a 3D model (if available) or another shape for visual interest.</li>
                    <li>Format all elements:
                        <ul>
                            <li>Apply a picture style to the image.</li>
                            <li>Change SmartArt colors (SmartArt Design → Change Colors).</li>
                            <li>Wrap text around the image using "Tight".</li>
                        </ul>
                    </li>
                    <li>Add Alt Text to the image and SmartArt.</li>
                    <li>Group the title and main image (select both → right-click → Group).</li>
                    <li>Save as YourName_Week4_Flyer.docx.</li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #0d3d8c; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + N then P</td>
                            <td style="padding: 6px 8px;">Insert Picture</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + N then SH</td>
                            <td style="padding: 6px 8px;">Insert Shape</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + N then M</td>
                            <td style="padding: 6px 8px;">Insert SmartArt</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + N then X</td>
                            <td style="padding: 6px 8px;">Insert Text Box</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Click</td>
                            <td style="padding: 6px 8px;">Select multiple objects</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + G</td>
                            <td style="padding: 6px 8px;">Group selected objects</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + G</td>
                            <td style="padding: 6px 8px;">Ungroup objects</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Arrow Keys</td>
                            <td style="padding: 6px 8px;">Nudge selected object</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Shift + Arrow Keys</td>
                            <td style="padding: 6px 8px;">Nudge object in larger increments</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>SmartArt:</strong> Visual representation of information (lists, processes, cycles).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Text Wrap:</strong> Controls how text flows around an object.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Alt Text:</strong> Descriptive text added to an object for accessibility.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Layout Options:</strong> Icon that appears when an object is selected, offering quick wrapping and positioning choices.</p>
                </div>
                <div>
                    <p><strong>3D Model:</strong> A three-dimensional object that can be rotated and viewed from any angle.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>How do you insert a screenshot of a specific part of your screen into Word?</li>
                    <li>What is the purpose of Alt Text, and where do you add it?</li>
                    <li>How can you make text flow closely around the edges of an irregular-shaped image?</li>
                    <li>What are two ways to add text to a shape?</li>
                    <li>How do you change the color scheme of a SmartArt graphic?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Insert shapes, pictures, 3D models</li>
                    <li>Insert SmartArt graphics</li>
                    <li>Insert screenshots and text boxes</li>
                    <li>Apply artistic effects</li>
                    <li>Apply picture effects and styles</li>
                    <li>Remove picture backgrounds</li>
                    <li>Format graphic elements</li>
                    <li>Format SmartArt graphics</li>
                    <li>Format 3D models</li>
                    <li>Add and modify text in text boxes and shapes</li>
                    <li>Add and modify SmartArt graphic content</li>
                    <li>Position objects</li>
                    <li>Wrap text around objects</li>
                    <li>Add alternative text to objects</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Use the Selection Pane:</strong> Home → Select → Selection Pane to manage overlapping objects.</li>
                    <li><strong>Maintain Consistency:</strong> Use similar styles and colors across graphic elements.</li>
                    <li><strong>Check Accessibility:</strong> Always add meaningful Alt Text to images and graphics.</li>
                    <li><strong>Practice Wrapping:</strong> Different wrap options suit different layouts—experiment!</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 5, we'll tackle References and Citations. You'll learn to insert footnotes, endnotes, citations, and create a table of contents and bibliography—essential for academic and professional reports.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Word Pictures and Graphics Help</li>
                    <li>Accessibility Checker and Alt Text Guide</li>
                    <li>Practice files and tutorial videos available in the Course Portal.</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #0d3d8c; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($instructor_email); ?></p>
                <p><strong>Course Portal:</strong> Access through your dashboard</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($user_email); ?></p>
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
        $studentEmail = $_SESSION['user_email'] ?? 'Not logged in';
        
        return '
        <div style="text-align: center; padding: 50px 0;">
            <h1 style="color: #0d3d8c; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #185abd; font-size: 18pt; margin-bottom: 30px;">
                Microsoft Word (MO-100) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #0d3d8c; 
                border-bottom: 3px solid #0d3d8c; padding: 20px 0; margin: 30px 0;">
                Week 4 Handout: Inserting and Formatting Graphic Elements
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentEmail) . '
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
                    This handout is part of the MO-100 Word Certification Prep Program. Unauthorized distribution is prohibited.
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
            Week 4: Graphic Elements | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-100 Word Certification Prep | Student: ' . htmlspecialchars($_SESSION['user_email'] ?? '') . '
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
        
        // Extract variables for the view
        $user_role = $this->user_role;
        $class_id = $this->class_id;
        $user_email = $_SESSION['user_email'] ?? '';
        $instructor_name = $_SESSION['instructor_name'] ?? 'Your Instructor';
        $instructor_email = $_SESSION['instructor_email'] ?? 'instructor@impactdigitalacademy.com';
        
        // Output the HTML page
        $this->renderHTMLPage($user_role, $class_id, $user_email, $instructor_name, $instructor_email);
    }
    
    /**
     * Render the HTML page
     */
    private function renderHTMLPage($user_role, $class_id, $user_email, $instructor_name, $instructor_email): void
    {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 4: Inserting and Formatting Graphic Elements - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #185abd 0%, #0d3d8c 100%);
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
            background: linear-gradient(135deg, #0d3d8c 0%, #185abd 100%);
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
            color: #185abd;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #185abd;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #0d3d8c;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #185abd;
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
            background-color: #0d3d8c;
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
            background-color: #e8f0ff;
        }

        .shortcut-key {
            background: #185abd;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exercise-box {
            background: #e8f0ff;
            border-left: 5px solid #185abd;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #0d3d8c;
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
            background: #185abd;
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
            background: #0d3d8c;
        }

        .learning-objectives {
            background: #f0f7ff;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #0d3d8c;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .key-terms {
            background: #f9f0ff;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .key-terms h3 {
            color: #7b1fa2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .term {
            margin-bottom: 15px;
        }

        .term strong {
            color: #0d3d8c;
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
            background: #0d3d8c;
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
            background: #e8f0ff;
        }

        .demo-table .merged {
            background: #e3f2fd;
            text-align: center;
            font-weight: bold;
        }

        .demo-table .header-row {
            background: #185abd;
            color: white;
        }

        /* Graphic Element Demos */
        .graphic-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .graphic-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .graphic-icon {
            font-size: 3rem;
            color: #185abd;
            margin-bottom: 15px;
        }

        .wrap-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .wrap-option {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
            background: white;
            transition: transform 0.2s;
        }

        .wrap-option:hover {
            transform: translateY(-3px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .wrap-option h4 {
            color: #0d3d8c;
            margin-bottom: 10px;
            font-size: 1rem;
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

            .graphic-demo {
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
                <strong>Access Granted:</strong> Word Week 4 Handout
            </div>
            <div class="access-badge">
                <?php echo ucfirst($user_role); ?> Access
            </div>
            <?php if ($user_role === 'student'): ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-user-graduate"></i> Student View
                </div>
            <?php else: ?>
                <div style="font-size: 0.9rem; opacity: 0.9;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor View
                </div>
            <?php endif; ?>
        </div>
        <?php if ($class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $user_role; ?>/classes/class_home.php?id=<?php echo $class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>modules/<?php echo $user_role; ?>/dashboard.php" class="back-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        <?php endif; ?>
        <?php if ($class_id): ?>
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week3_view.php?class_id=<?php echo $class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 3
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 4 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Inserting and Formatting Graphic Elements</div>
            <div class="week-tag">Week 4 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-palette"></i> Welcome to Week 4!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we dive into the visual side of Word. You'll learn how to enhance your documents with images, shapes, SmartArt, 3D models, and text boxes. These elements help communicate ideas more effectively and make your documents engaging and professional. Mastering graphic elements is essential for creating reports, flyers, brochures, and more—and it's a key skill tested on the MO-100 exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Graphic Elements in Word"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+R3JhcGhpYyBFbGVtZW50cyBpbiBXb3JkPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Enhancing Documents with Graphic Elements</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Insert various graphic elements: pictures, shapes, SmartArt, 3D models, screenshots, and text boxes.</li>
                    <li>Format illustrations by applying artistic effects, styles, and backgrounds.</li>
                    <li>Modify graphic elements by positioning, wrapping text, and adding alternative text for accessibility.</li>
                    <li>Add and edit text within shapes, text boxes, and SmartArt graphics.</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Insert shapes, pictures, 3D models</li>
                        <li>Insert SmartArt graphics</li>
                        <li>Insert screenshots and text boxes</li>
                        <li>Apply artistic effects</li>
                        <li>Apply picture effects and styles</li>
                        <li>Remove picture backgrounds</li>
                        <li>Format graphic elements</li>
                    </ul>
                    <ul>
                        <li>Format SmartArt graphics</li>
                        <li>Format 3D models</li>
                        <li>Add and modify text in text boxes and shapes</li>
                        <li>Add and modify SmartArt graphic content</li>
                        <li>Position objects</li>
                        <li>Wrap text around objects</li>
                        <li>Add alternative text to objects</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Inserting Graphic Elements -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-plus-circle"></i> 1. Inserting Illustrations and Text Boxes
                </div>

                <div class="graphic-demo">
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-square"></i>
                        </div>
                        <h4>Shapes</h4>
                        <p>Insert → Shapes → choose from lines, rectangles, arrows, callouts, etc.</p>
                    </div>
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-image"></i>
                        </div>
                        <h4>Pictures</h4>
                        <p>Insert → Pictures → choose from This Device, Online Pictures, or Stock Images.</p>
                    </div>
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <h4>3D Models</h4>
                        <p>Insert → 3D Models → from online sources or local files.</p>
                    </div>
                </div>

                <div class="graphic-demo">
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <h4>SmartArt</h4>
                        <p>Insert → SmartArt → choose from lists, processes, hierarchies, etc.</p>
                    </div>
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-camera"></i>
                        </div>
                        <h4>Screenshots</h4>
                        <p>Insert → Screenshot → capture entire window or screen clipping.</p>
                    </div>
                    <div class="graphic-item">
                        <div class="graphic-icon">
                            <i class="fas fa-font"></i>
                        </div>
                        <h4>Text Boxes</h4>
                        <p>Insert → Text Box → choose from built-in styles or draw your own.</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lightbulb"></i> Pro Tips</h3>
                    <ul>
                        <li><strong>Use Shift key</strong> while drawing shapes to maintain proportions.</li>
                        <li><strong>Stock Images</strong> provide royalty-free images directly in Word.</li>
                        <li><strong>Screen Clipping</strong> lets you select specific parts of your screen.</li>
                        <li><strong>Built-in Text Box styles</strong> include preformatted designs for quick use.</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Formatting Graphic Elements -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-paint-brush"></i> 2. Formatting Illustrations and Text Boxes
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-magic"></i> A. Artistic Effects and Styles</h3>
                    <ul>
                        <li><strong>Artistic Effects:</strong> Select picture → Picture Format → Artistic Effects</li>
                        <li><strong>Available effects:</strong> Pencil sketch, paint strokes, glow, etc.</li>
                        <li><strong>Picture Styles:</strong> Pre-designed combinations of borders and effects</li>
                        <li><strong>Picture Effects:</strong> Custom shadows, reflections, glows, bevels</li>
                    </ul>

                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1611224923853-80b023f02d71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                            alt="Picture Formatting Options"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5QaWN0dXJlIEZvcm1hdHRpbmcgT3B0aW9uczwvdGV4dD48L3N2Zz4n'">
                        <div class="image-caption">Before and After: Applying Picture Styles and Effects</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eraser"></i> B. Remove Backgrounds</h3>
                    <ul>
                        <li><strong>Picture Format → Remove Background</strong></li>
                        <li>Mark areas to keep (purple) and remove (magenta)</li>
                        <li>Great for product images or creating custom shapes</li>
                        <li>Use "Keep Changes" to apply, "Discard Changes" to cancel</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> C. Formatting Options</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 15px;">
                        <div style="padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background: #f9f9f9;">
                            <h4 style="color: #0d3d8c; margin-bottom: 10px;">Shapes & Text Boxes</h4>
                            <ul style="padding-left: 20px; margin: 0;">
                                <li>Shape Format tab</li>
                                <li>Change fill color</li>
                                <li>Adjust outline</li>
                                <li>Add effects</li>
                            </ul>
                        </div>
                        <div style="padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background: #f9f9f9;">
                            <h4 style="color: #0d3d8c; margin-bottom: 10px;">SmartArt</h4>
                            <ul style="padding-left: 20px; margin: 0;">
                                <li>SmartArt Design tab</li>
                                <li>Change layout</li>
                                <li>Modify colors</li>
                                <li>Apply styles</li>
                            </ul>
                        </div>
                        <div style="padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background: #f9f9f9;">
                            <h4 style="color: #0d3d8c; margin-bottom: 10px;">3D Models</h4>
                            <ul style="padding-left: 20px; margin: 0;">
                                <li>3D Model tab</li>
                                <li>Adjust scene</li>
                                <li>Set lighting</li>
                                <li>Control rotation</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Adding Text to Graphics -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-font"></i> 3. Adding Text to Graphic Elements
                </div>

                <table class="demo-table">
                    <thead>
                        <tr class="header-row">
                            <th>Element</th>
                            <th>Method</th>
                            <th>Tips</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Text Boxes</strong></td>
                            <td>Click inside and type</td>
                            <td>Format text using Home tab tools</td>
                        </tr>
                        <tr>
                            <td><strong>Shapes</strong></td>
                            <td>Right-click shape → Add Text</td>
                            <td>Text follows shape outline</td>
                        </tr>
                        <tr>
                            <td><strong>SmartArt</strong></td>
                            <td>Click "[Text]" or use Text Pane</td>
                            <td>SmartArt Design → Text Pane</td>
                        </tr>
                    </tbody>
                </table>

                <div class="subsection">
                    <h3><i class="fas fa-mouse-pointer"></i> Text Pane for SmartArt</h3>
                    <ul>
                        <li>Shows all text elements in a hierarchical view</li>
                        <li>Allows quick editing without clicking each shape</li>
                        <li>Press <span class="shortcut-key">Ctrl + Shift + F2</span> to toggle</li>
                        <li>Use Tab/Shift+Tab to change bullet levels</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Modifying Graphic Elements -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cogs"></i> 4. Modifying Graphic Elements
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-arrows-alt"></i> A. Positioning Objects</h3>
                    <ul>
                        <li><strong>Drag to move</strong> – Click and drag object</li>
                        <li><strong>Layout Options</strong> – Icon appears when object selected</li>
                        <li><strong>Arrange → Position</strong> – For precise placement</li>
                        <li><strong>Selection Pane</strong> – Manage overlapping objects (Home → Select → Selection Pane)</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-text-width"></i> B. Wrapping Text Around Objects</h3>
                    <div class="wrap-options">
                        <div class="wrap-option">
                            <h4>Square</h4>
                            <p>Text wraps in a square around object</p>
                        </div>
                        <div class="wrap-option">
                            <h4>Tight</h4>
                            <p>Text follows object's contour</p>
                        </div>
                        <div class="wrap-option">
                            <h4>Through</h4>
                            <p>Text fills gaps in object</p>
                        </div>
                        <div class="wrap-option">
                            <h4>Top & Bottom</h4>
                            <p>Text above and below only</p>
                        </div>
                        <div class="wrap-option">
                            <h4>Behind Text</h4>
                            <p>Object behind text</p>
                        </div>
                        <div class="wrap-option">
                            <h4>In Front of Text</h4>
                            <p>Object covers text</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-universal-access"></i> C. Adding Alternative Text (Alt Text)</h3>
                    <ul>
                        <li><strong>Right-click object → Edit Alt Text</strong></li>
                        <li><strong>Describe the object</strong> concisely for screen readers</li>
                        <li><strong>Required for accessibility</strong> in professional documents</li>
                        <li><strong>Mark as decorative</strong> if object is purely visual</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-eye"></i> Accessibility Tip
                        </div>
                        <p>Always add meaningful Alt Text to images, charts, and SmartArt. Screen readers rely on this to describe visual content to users with visual impairments.</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-layer-group"></i> D. Grouping Objects</h3>
                    <ul>
                        <li><strong>Select multiple objects:</strong> Click first, then Ctrl+Click others</li>
                        <li><strong>Group:</strong> Right-click → Group → Group</li>
                        <li><strong>Shortcut:</strong> <span class="shortcut-key">Ctrl + G</span></li>
                        <li><strong>Ungroup:</strong> Right-click → Group → Ungroup</li>
                        <li><strong>Shortcut:</strong> <span class="shortcut-key">Ctrl + Shift + G</span></li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bolt"></i> 5. Essential Shortcuts for Week 4
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
                            <td><span class="shortcut-key">Alt + N then P</span></td>
                            <td>Insert Picture</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + N then SH</span></td>
                            <td>Insert Shape</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + N then M</span></td>
                            <td>Insert SmartArt</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + N then X</span></td>
                            <td>Insert Text Box</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Click</span></td>
                            <td>Select multiple objects</td>
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
                            <td><span class="shortcut-key">Arrow Keys</span></td>
                            <td>Nudge selected object</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Arrow Keys</span></td>
                            <td>Nudge object in larger increments</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + F2</span></td>
                            <td>Toggle SmartArt Text Pane</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> 6. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Design a Product Flyer</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open a new document and set orientation to Landscape (Layout → Orientation).</li>
                        <li>Insert a WordArt title: Insert → WordArt → type "Amazing New Product".</li>
                        <li>Insert a picture (use a stock image or local file) and remove the background.</li>
                        <li>Insert a shape (e.g., rounded rectangle) behind the picture for a frame.</li>
                        <li>Add a text box with a short product description.</li>
                        <li>Insert a SmartArt graphic (Process type) to show 3 steps: "Buy", "Enjoy", "Share".</li>
                        <li>Insert a 3D model (if available) or another shape for visual interest.</li>
                        <li>Format all elements:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Apply a picture style to the image</li>
                                <li>Change SmartArt colors (SmartArt Design → Change Colors)</li>
                                <li>Wrap text around the image using "Tight"</li>
                            </ul>
                        </li>
                        <li>Add Alt Text to the image and SmartArt.</li>
                        <li>Group the title and main image (select both → right-click → Group).</li>
                        <li>Save as <strong>YourName_Week4_Flyer.docx</strong>.</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1545235617-9465d2a55698?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Product Flyer Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Qcm9kdWN0IEZseWVyIEV4YW1wbGU8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Sample Product Flyer with Graphic Elements</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Flyer Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>SmartArt</strong>
                    <p>Visual representation of information (lists, processes, cycles).</p>
                </div>

                <div class="term">
                    <strong>Text Wrap</strong>
                    <p>Controls how text flows around an object.</p>
                </div>

                <div class="term">
                    <strong>Alt Text</strong>
                    <p>Descriptive text added to an object for accessibility.</p>
                </div>

                <div class="term">
                    <strong>Layout Options</strong>
                    <p>Icon that appears when an object is selected, offering quick wrapping and positioning choices.</p>
                </div>

                <div class="term">
                    <strong>3D Model</strong>
                    <p>A three-dimensional object that can be rotated and viewed from any angle.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-homework"></i> 7. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Test Your Knowledge:</h4>
                    <ol>
                        <li>How do you insert a screenshot of a specific part of your screen into Word?</li>
                        <li>What is the purpose of Alt Text, and where do you add it?</li>
                        <li>How can you make text flow closely around the edges of an irregular-shaped image?</li>
                        <li>What are two ways to add text to a shape?</li>
                        <li>How do you change the color scheme of a SmartArt graphic?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Homework:</strong> Complete the flyer design exercise and submit your <strong>YourName_Week4_Flyer.docx</strong> file via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Use the Selection Pane:</strong> Home → Select → Selection Pane to manage overlapping objects.</li>
                    <li><strong>Maintain Consistency:</strong> Use similar styles and colors across graphic elements.</li>
                    <li><strong>Check Accessibility:</strong> Always add meaningful Alt Text to images and graphics.</li>
                    <li><strong>Practice Wrapping:</strong> Different wrap options suit different layouts—experiment!</li>
                    <li><strong>Group Related Elements:</strong> Group objects that should move together.</li>
                    <li><strong>Use Guides:</strong> Enable gridlines and guides for precise alignment (View → Gridlines).</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word" target="_blank">Word Pictures and Graphics Help</a></li>
                    <li><a href="https://support.microsoft.com/accessibility" target="_blank">Accessibility Checker and Alt Text Guide</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p>In Week 5, we'll tackle References and Citations. You'll learn to:</p>
                <ul>
                    <li>Insert and format footnotes and endnotes</li>
                    <li>Add citations and create bibliographies</li>
                    <li>Generate table of contents</li>
                    <li>Create indexes and tables of figures</li>
                    <li>Manage captions and cross-references</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Review any research papers or reports you might have for practice.</p>
            </div>

            <!-- Help Section -->
            <div class="help-section">
                <div class="help-title">
                    <i class="fas fa-question-circle"></i> Need Help?
                </div>
                <ul>
                    <li><strong>Instructor:</strong> <?php echo htmlspecialchars($instructor_name); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($instructor_email); ?></li>
                    <li><strong>Class Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php">Access Portal</a></li>
                    <li><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week4.php">Week 4 Discussion</a></li>
                    <li><strong>Microsoft Word Help:</strong> <a href="https://support.microsoft.com/word" target="_blank">Official Support</a></li>
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
            </div>
        </div>

        <footer>
            <p>MO-100: Microsoft Word Certification Prep Program – Week 4 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-100 Word Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($user_email); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                    <i class="fas fa-chalkboard-teacher"></i> Instructor Access - <?php echo htmlspecialchars($user_email); ?>
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
        document.getElementById('current-date').textContent = `Handout accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Print functionality
        function printHandout() {
            window.print();
        }

        // PDF alert functionality
        function showPdfAlert() {
            const pdfAlert = document.getElementById('pdfAlert');
            const pdfAlertMessage = document.getElementById('pdfAlertMessage');
            
            // Check if mPDF might be available
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
            alert('Flyer template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/templates/flyer_template.docx';
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
                console.log('Word Week 4 handout access logged for: <?php echo htmlspecialchars($user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Insert → Screenshot → Screen Clipping, then select the area you want to capture",
                    "2. Alt Text (Alternative Text) describes images and graphics for screen readers (accessibility). Add via right-click → Edit Alt Text",
                    "3. Select image → Layout Options → Tight wrap. For even tighter control, use Edit Wrap Points",
                    "4. Method 1: Right-click shape → Add Text. Method 2: Select shape and start typing (some shapes)",
                    "5. Select SmartArt → SmartArt Design tab → Change Colors → choose a color scheme"
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive graphic element demonstration
        document.addEventListener('DOMContentLoaded', function() {
            const graphicItems = document.querySelectorAll('.graphic-item');
            graphicItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    alert(`You clicked on ${title}. To insert: Go to Insert tab → find ${title} section`);
                });
            });

            // Wrap options demonstration
            const wrapOptions = document.querySelectorAll('.wrap-option');
            wrapOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const wrapType = this.querySelector('h4').textContent;
                    alert(`${wrapType} wrap selected. To apply: Select object → Layout Options → ${wrapType}`);
                });
            });
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
    $viewer = new WordWeek4HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>