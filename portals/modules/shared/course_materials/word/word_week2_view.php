<?php
// modules/shared/course_materials/MSWord/word_week2_view.php

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
 * Word Week 2 Handout Viewer Class with PDF Download
 */
class WordWeek2HandoutViewer
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
            $mpdf->SetTitle('Week 2: Formatting Text, Paragraphs, and Sections');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Formatting, Styles, Sections, Columns');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week2_Formatting_' . date('Y-m-d') . '.pdf';
            
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
            <h1 style="color: #0d3d8c; border-bottom: 2px solid #0d3d8c; padding-bottom: 10px; font-size: 18pt;">
                Week 2: Formatting Text, Paragraphs, and Sections
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 2!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we move from basic document management to making your documents look professional. You'll learn how to format text and paragraphs effectively, use styles to maintain consistency, and control your document's layout with sections and columns. These skills are essential for creating polished, readable, and well-structured documents—and they're heavily tested on the MO-100 exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Apply text effects and advanced formatting using the Font and Paragraph dialog boxes.</li>
                    <li>Use Format Painter to quickly copy formatting.</li>
                    <li>Apply and modify built-in styles for consistent document design.</li>
                    <li>Create and manage document sections with different formatting.</li>
                    <li>Insert and modify page, section, and column breaks.</li>
                    <li>Format text into multiple columns.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Formatting Text</h3>
                
                <p><strong>Font Formatting:</strong></p>
                <ul>
                    <li>Change font type, size, color, and style (bold, italic, underline).</li>
                    <li>Apply text effects (Outline, Shadow, Reflection, Glow).</li>
                    <li>Use Advanced Font Settings (Character spacing, scale, position).</li>
                </ul>
                
                <p><strong>Clear Formatting:</strong></p>
                <ul>
                    <li>Select text and click Clear All Formatting (Home tab) or press Ctrl + Spacebar.</li>
                </ul>
                
                <p><strong>Format Painter:</strong></p>
                <ul>
                    <li>Click once to apply formatting once, double-click to apply multiple times.</li>
                    <li>Press Esc to deactivate.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Formatting Paragraphs</h3>
                
                <ul>
                    <li><strong>Alignment:</strong> Left, Center, Right, Justified.</li>
                    <li><strong>Indentation:</strong> Increase/decrease indent, first-line indent, hanging indent.</li>
                    <li><strong>Line Spacing:</strong> Single, 1.5, Double, or custom spacing.</li>
                    <li><strong>Paragraph Spacing:</strong> Space before and after paragraphs.</li>
                    <li><strong>Borders and Shading:</strong> Add lines and background color to paragraphs.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Using Styles</h3>
                
                <p><strong>What are Styles?</strong></p>
                <ul>
                    <li>Predefined formatting combinations (font, size, color, spacing).</li>
                </ul>
                
                <p><strong>Apply a Style:</strong></p>
                <ul>
                    <li>Select text → Home tab → Styles gallery.</li>
                </ul>
                
                <p><strong>Modify a Style:</strong></p>
                <ul>
                    <li>Right-click style → Modify → Adjust formatting.</li>
                </ul>
                
                <p><strong>Style Sets:</strong></p>
                <ul>
                    <li>Design tab → choose a Style Set to change the look of all styles at once.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">4. Working with Sections</h3>
                
                <p><strong>What is a Section?</strong></p>
                <ul>
                    <li>A portion of a document with its own page formatting.</li>
                </ul>
                
                <p><strong>Insert a Section Break:</strong></p>
                <ul>
                    <li>Layout tab → Breaks → choose:
                        <ul>
                            <li>Next Page: Starts new section on next page.</li>
                            <li>Continuous: New section on same page.</li>
                            <li>Even/Odd Page: Starts on next even/odd page.</li>
                        </ul>
                    </li>
                </ul>
                
                <p><strong>Change Page Setup for a Section:</strong></p>
                <ul>
                    <li>Different margins, orientation, or headers/footers per section.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">5. Creating Multiple Columns</h3>
                
                <p><strong>Apply Columns:</strong></p>
                <ul>
                    <li>Layout tab → Columns → choose 1, 2, 3, or More Columns.</li>
                </ul>
                
                <p><strong>Customize Columns:</strong></p>
                <ul>
                    <li>More Columns → set width, spacing, and line between.</li>
                </ul>
                
                <p><strong>Column Breaks:</strong></p>
                <ul>
                    <li>Layout → Breaks → Column Break to force text to next column.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Create a Newsletter with Sections and Columns</strong></p>
                <p>Follow these steps to build a professional-looking newsletter:</p>
                <ol>
                    <li>Open a new document and type a title: "Community News".</li>
                    <li>Format the title:
                        <ul>
                            <li>Apply Title style.</li>
                            <li>Add a Shadow text effect.</li>
                            <li>Center align.</li>
                        </ul>
                    </li>
                    <li>Type an introduction paragraph.</li>
                    <li>Apply a two-column layout: Layout → Columns → Two.</li>
                    <li>Add a section break: After the intro, go to Layout → Breaks → Continuous.</li>
                    <li>In the new section, change column formatting: Layout → Columns → More Columns → Three columns with line between.</li>
                    <li>Add sample text in each column.</li>
                    <li>Insert a column break in the middle of the second column.</li>
                    <li>Use Format Painter to copy the intro formatting to another paragraph.</li>
                    <li>Save as YourName_Week2_Newsletter.docx.</li>
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
                            <td style="padding: 6px 8px;">Ctrl + B</td>
                            <td style="padding: 6px 8px;">Bold</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + I</td>
                            <td style="padding: 6px 8px;">Italic</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + U</td>
                            <td style="padding: 6px 8px;">Underline</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + E</td>
                            <td style="padding: 6px 8px;">Center align</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + L</td>
                            <td style="padding: 6px 8px;">Left align</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + R</td>
                            <td style="padding: 6px 8px;">Right align</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + J</td>
                            <td style="padding: 6px 8px;">Justify</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + &gt;</td>
                            <td style="padding: 6px 8px;">Increase font size</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + &lt;</td>
                            <td style="padding: 6px 8px;">Decrease font size</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + D</td>
                            <td style="padding: 6px 8px;">Open Font dialog box</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + C</td>
                            <td style="padding: 6px 8px;">Copy formatting</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + V</td>
                            <td style="padding: 6px 8px;">Paste formatting</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + Spacebar</td>
                            <td style="padding: 6px 8px;">Clear formatting</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Format Painter:</strong> Tool to copy formatting from one selection to another.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Style:</strong> A named set of formatting characteristics.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Style Set:</strong> A collection of styles that work together.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Section Break:</strong> Divides a document into parts with different layout settings.</p>
                </div>
                <div>
                    <p><strong>Column Break:</strong> Forces text to start in the next column.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>How do you apply the same formatting to multiple parts of a document quickly?</li>
                    <li>What is the difference between a section break and a page break?</li>
                    <li>How can you change the line spacing of a paragraph to 1.5 lines?</li>
                    <li>What are two ways to apply a style to text?</li>
                    <li>How do you create a three-column layout with a line between columns?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Apply text effects</li>
                    <li>Use Format Painter</li>
                    <li>Set line and paragraph spacing and indentation</li>
                    <li>Apply built-in styles to text</li>
                    <li>Clear formatting</li>
                    <li>Format text in multiple columns</li>
                    <li>Insert page, section, and column breaks</li>
                    <li>Change page setup options for a section</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Use Styles Consistently:</strong> They save time and ensure uniformity.</li>
                    <li><strong>Plan Your Sections:</strong> Think about layout before inserting breaks.</li>
                    <li><strong>Practice with Real Documents:</strong> Try reformatting an existing document.</li>
                    <li><strong>Check Print Preview:</strong> Always preview how breaks and columns will print.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 3, we'll dive into Tables and Lists. You'll learn how to create, format, and modify tables, as well as build professional bulleted and numbered lists. Bring any documents you'd like to organize in table format!</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Word Styles Tutorial</li>
                    <li>Working with Sections in Word</li>
                    <li>Practice files and tutorial videos available in the Course Portal.</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #0d3d8c; margin-bottom: 10px;">Instructor Information</h4>
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
            <h1 style="color: #0d3d8c; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #185abd; font-size: 18pt; margin-bottom: 30px;">
                Microsoft Word (MO-100) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #0d3d8c; 
                border-bottom: 3px solid #0d3d8c; padding: 20px 0; margin: 30px 0;">
                Week 2 Handout: Formatting Text, Paragraphs, and Sections
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
            Week 2: Formatting & Sections | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-100 Word Certification Prep | Student: ' . htmlspecialchars($this->user_email) . '
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
    <title>Week 2: Formatting Text, Paragraphs, and Sections - Impact Digital Academy</title>
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

        /* Formatting Tools Demo */
        .formatting-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 25px 0;
        }

        .formatting-tool {
            flex: 1;
            min-width: 150px;
            text-align: center;
            padding: 20px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .formatting-tool:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .formatting-icon {
            font-size: 2.5rem;
            color: #185abd;
            margin-bottom: 10px;
        }

        .style-samples {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .style-sample {
            padding: 10px 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }

        .style-sample.heading1 {
            font-size: 1.4rem;
            font-weight: bold;
            color: #0d3d8c;
        }

        .style-sample.heading2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #185abd;
        }

        .style-sample.normal {
            font-size: 1rem;
            color: #333;
        }

        .column-demo {
            column-count: 3;
            column-gap: 20px;
            column-rule: 1px solid #ddd;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
            margin: 20px 0;
        }

        .column-demo p {
            margin-bottom: 15px;
        }

        .text-effects {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .text-effect {
            padding: 10px 20px;
            border-radius: 4px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .text-effect.shadow {
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .text-effect.outline {
            color: white;
            -webkit-text-stroke: 1px #0d3d8c;
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

            .formatting-demo {
                flex-direction: column;
            }

            .column-demo {
                column-count: 1;
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

            .column-demo {
                column-count: 1 !important;
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
                <strong>Access Granted:</strong> Word Week 2 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week1_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 1
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 2 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Formatting Text, Paragraphs, and Sections</div>
            <div class="week-tag">Week 2 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-paint-brush"></i> Welcome to Week 2!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we move from basic document management to making your documents look professional. You'll learn how to format text and paragraphs effectively, use styles to maintain consistency, and control your document's layout with sections and columns. These skills are essential for creating polished, readable, and well-structured documents—and they're heavily tested on the MO-100 exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1581291518633-83b4ebd1d83e?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Professional Document Formatting"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+Rm9ybWF0dGluZyBUZXh0LCBQYXJhZ3JhcGhzLCBhbmQgU2VjdGlvbnM8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Professional Document Formatting and Layout</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Apply text effects and advanced formatting using the Font and Paragraph dialog boxes.</li>
                    <li>Use Format Painter to quickly copy formatting.</li>
                    <li>Apply and modify built-in styles for consistent document design.</li>
                    <li>Create and manage document sections with different formatting.</li>
                    <li>Insert and modify page, section, and column breaks.</li>
                    <li>Format text into multiple columns.</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Apply text effects</li>
                        <li>Use Format Painter</li>
                        <li>Set line and paragraph spacing and indentation</li>
                        <li>Apply built-in styles to text</li>
                    </ul>
                    <ul>
                        <li>Clear formatting</li>
                        <li>Format text in multiple columns</li>
                        <li>Insert page, section, and column breaks</li>
                        <li>Change page setup options for a section</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Formatting Text -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-font"></i> 1. Formatting Text
                </div>

                <div class="formatting-demo">
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-bold"></i>
                        </div>
                        <h4>Font Formatting</h4>
                        <p>Change type, size, color, and style</p>
                        <p style="margin-top: 10px;"><strong>Shortcut:</strong> Ctrl + D</p>
                    </div>
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-palette"></i>
                        </div>
                        <h4>Text Effects</h4>
                        <p>Outline, Shadow, Reflection, Glow</p>
                        <p style="margin-top: 10px;"><strong>Location:</strong> Font Dialog Box</p>
                    </div>
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-broom"></i>
                        </div>
                        <h4>Clear Formatting</h4>
                        <p>Remove all formatting</p>
                        <p style="margin-top: 10px;"><strong>Shortcut:</strong> Ctrl + Spacebar</p>
                    </div>
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-paint-roller"></i>
                        </div>
                        <h4>Format Painter</h4>
                        <p>Copy formatting between text</p>
                        <p style="margin-top: 10px;"><strong>Shortcut:</strong> Ctrl + Shift + C/V</p>
                    </div>
                </div>

                <!-- Text Effects Demo -->
                <div class="subsection">
                    <h3><i class="fas fa-magic"></i> Text Effects Demo</h3>
                    <div class="text-effects">
                        <div class="text-effect" style="font-size: 1.2rem; font-weight: bold; color: #0d3d8c;">
                            Standard Text
                        </div>
                        <div class="text-effect shadow" style="font-size: 1.2rem; font-weight: bold; color: #0d3d8c;">
                            Shadow Effect
                        </div>
                        <div class="text-effect" style="font-size: 1.2rem; font-weight: bold; color: transparent; -webkit-text-stroke: 1px #0d3d8c;">
                            Outline Effect
                        </div>
                        <div class="text-effect" style="font-size: 1.2rem; font-weight: bold; color: #185abd; text-shadow: 0 0 10px rgba(24, 90, 189, 0.5);">
                            Glow Effect
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Advanced Font Settings</h3>
                    <ul>
                        <li><strong>Character Spacing:</strong> Expand or condense text spacing</li>
                        <li><strong>Scale:</strong> Stretch text horizontally (50-200%)</li>
                        <li><strong>Position:</strong> Raise or lower text baseline</li>
                        <li><strong>Kerning:</strong> Adjust spacing between specific character pairs</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Formatting Paragraphs -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-align-left"></i> 2. Formatting Paragraphs
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Paragraph Settings</h3>
                    <div class="demo-table">
                        <thead>
                            <tr>
                                <th>Setting</th>
                                <th>Description</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Alignment</strong></td>
                                <td>Left, Center, Right, Justified</td>
                                <td>Home tab → Paragraph group</td>
                            </tr>
                            <tr>
                                <td><strong>Indentation</strong></td>
                                <td>First-line, Hanging, Left, Right</td>
                                <td>Paragraph dialog box → Indents</td>
                            </tr>
                            <tr>
                                <td><strong>Line Spacing</strong></td>
                                <td>Single, 1.5, Double, Custom</td>
                                <td>Home tab → Line spacing button</td>
                            </tr>
                            <tr>
                                <td><strong>Paragraph Spacing</strong></td>
                                <td>Space before/after paragraphs</td>
                                <td>Paragraph dialog box → Spacing</td>
                            </tr>
                            <tr>
                                <td><strong>Borders & Shading</strong></td>
                                <td>Lines and background color</td>
                                <td>Home tab → Borders button</td>
                            </tr>
                        </tbody>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-ruler-combined"></i> Indentation Examples</h3>
                    <div style="margin: 20px 0; padding-left: 40px; border-left: 3px solid #185abd;">
                        <p><strong>First-line indent:</strong> First line is indented, subsequent lines start at margin.</p>
                    </div>
                    <div style="margin: 20px 0; padding-left: 40px; text-indent: -20px;">
                        <p><strong>Hanging indent:</strong> First line starts at margin, subsequent lines are indented.</p>
                    </div>
                </div>
            </div>

            <!-- Section 3: Using Styles -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-layer-group"></i> 3. Using Styles
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-th-large"></i> Style Gallery</h3>
                    
                    <div class="style-samples">
                        <div class="style-sample heading1">Heading 1</div>
                        <div class="style-sample heading2">Heading 2</div>
                        <div class="style-sample normal">Normal Text</div>
                        <div class="style-sample" style="font-weight: bold;">Strong</div>
                        <div class="style-sample" style="font-style: italic;">Emphasis</div>
                        <div class="style-sample" style="color: #2e7d32;">Quote</div>
                    </div>

                    <ul style="margin-top: 20px;">
                        <li><strong>Apply Style:</strong> Select text → Home tab → Styles gallery</li>
                        <li><strong>Modify Style:</strong> Right-click style → Modify → Adjust formatting</li>
                        <li><strong>Style Sets:</strong> Design tab → choose a Style Set to change all styles at once</li>
                        <li><strong>Clear Style:</strong> Select text → Clear Formatting (Ctrl + Spacebar)</li>
                    </ul>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Word Styles Interface"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5TdHlsZXMgSW50ZXJmYWNlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Word Styles Gallery and Management</div>
                </div>
            </div>

            <!-- Section 4: Working with Sections -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-columns"></i> 4. Working with Sections
                </div>

                <div class="formatting-demo">
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4>Next Page</h4>
                        <p>Starts new section on next page</p>
                        <p style="margin-top: 10px;"><strong>Use:</strong> Different chapter layouts</p>
                    </div>
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <h4>Continuous</h4>
                        <p>New section on same page</p>
                        <p style="margin-top: 10px;"><strong>Use:</strong> Column changes</p>
                    </div>
                    <div class="formatting-tool">
                        <div class="formatting-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <h4>Even/Odd Page</h4>
                        <p>Starts on next even/odd page</p>
                        <p style="margin-top: 10px;"><strong>Use:</strong> Book formatting</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> Section Management</h3>
                    <ul>
                        <li><strong>Insert Section Break:</strong> Layout tab → Breaks → Choose type</li>
                        <li><strong>Different Headers/Footers:</strong> Double-click header → Design tab → Link to Previous (uncheck)</li>
                        <li><strong>Different Page Setup:</strong> Layout tab → Page Setup → Apply to: This section</li>
                        <li><strong>Delete Section Break:</strong> Show formatting marks (¶) → Select break → Delete</li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Multiple Columns -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-newspaper"></i> 5. Creating Multiple Columns
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-columns"></i> Column Layout Demo</h3>
                    
                    <div class="column-demo">
                        <p><strong>Two-column Layout:</strong> Creates a newspaper-style layout that's easy to read. Text flows from the bottom of the first column to the top of the next column.</p>
                        
                        <p><strong>Column Breaks:</strong> Insert a column break (Layout → Breaks → Column Break) to force text to start in the next column. Useful for controlling where content appears.</p>
                        
                        <p><strong>Line Between Columns:</strong> In the More Columns dialog, check "Line between" to add a vertical separator between columns for better readability.</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Custom Column Settings</h3>
                    <ul>
                        <li><strong>Preset Columns:</strong> 1, 2, 3 columns from Layout tab</li>
                        <li><strong>Custom Columns:</strong> More Columns → Set exact width and spacing</li>
                        <li><strong>Equal Column Width:</strong> Check to make all columns same width</li>
                        <li><strong>Apply To:</strong> Whole document, this section, or selected text</li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bolt"></i> 6. Essential Shortcuts for Week 2
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
                            <td><span class="shortcut-key">Ctrl + B</span></td>
                            <td>Bold</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + I</span></td>
                            <td>Italic</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + U</span></td>
                            <td>Underline</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + E</span></td>
                            <td>Center align</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + L</span></td>
                            <td>Left align</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + R</span></td>
                            <td>Right align</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + J</span></td>
                            <td>Justify</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + &gt;</span></td>
                            <td>Increase font size</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + &lt;</span></td>
                            <td>Decrease font size</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + D</span></td>
                            <td>Open Font dialog box</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + C</span></td>
                            <td>Copy formatting</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + V</span></td>
                            <td>Paste formatting</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Spacebar</span></td>
                            <td>Clear formatting</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Ctrl + 1</span></td>
                            <td>Apply Heading 1 style</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Ctrl + 2</span></td>
                            <td>Apply Heading 2 style</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + N</span></td>
                            <td>Apply Normal style</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> 7. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Create a Newsletter with Sections and Columns</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open a new document and type a title: "Community News".</li>
                        <li>Format the title:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Apply <strong>Title</strong> style</li>
                                <li>Add a <strong>Shadow</strong> text effect</li>
                                <li><strong>Center align</strong></li>
                            </ul>
                        </li>
                        <li>Type an introduction paragraph (3-4 sentences).</li>
                        <li>Apply a two-column layout: <strong>Layout → Columns → Two</strong></li>
                        <li>Add a section break: After the intro, go to <strong>Layout → Breaks → Continuous</strong></li>
                        <li>In the new section, change column formatting: <strong>Layout → Columns → More Columns → Three columns</strong> with line between</li>
                        <li>Add sample text in each column (you can use Lorem Ipsum or real text)</li>
                        <li>Insert a column break in the middle of the second column: <strong>Layout → Breaks → Column Break</strong></li>
                        <li>Use Format Painter to copy the intro formatting to another paragraph</li>
                        <li>Save as <strong><?php echo htmlspecialchars($studentName); ?>_Week2_Newsletter.docx</strong></li>
                    </ol>
                </div>

                <!--
                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Newsletter Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5OZXdzbGV0dGVyIEV4YW1wbGU8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Sample Newsletter Layout with Columns</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Newsletter Template
                </a> -->
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Format Painter</strong>
                    <p>Tool to copy formatting from one selection to another.</p>
                </div>

                <div class="term">
                    <strong>Style</strong>
                    <p>A named set of formatting characteristics that can be applied to text.</p>
                </div>

                <div class="term">
                    <strong>Style Set</strong>
                    <p>A collection of styles that work together to create a consistent document design.</p>
                </div>

                <div class="term">
                    <strong>Section Break</strong>
                    <p>Divides a document into parts with different layout settings (margins, orientation, headers/footers).</p>
                </div>

                <div class="term">
                    <strong>Column Break</strong>
                    <p>Forces text to start in the next column, useful for controlling content flow in multi-column layouts.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-homework"></i> 9. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Test Your Knowledge:</h4>
                    <ol>
                        <li>How do you apply the same formatting to multiple parts of a document quickly?</li>
                        <li>What is the difference between a section break and a page break?</li>
                        <li>How can you change the line spacing of a paragraph to 1.5 lines?</li>
                        <li>What are two ways to apply a style to text?</li>
                        <li>How do you create a three-column layout with a line between columns?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <p> <strong>Homework:</strong> Complete your <a href="https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/word/week2_assignment.html" target="_blank">assignment</a>> and submit via the assignment page. </p>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Use Styles Consistently:</strong> They save time and ensure uniformity across your document.</li>
                    <li><strong>Plan Your Sections:</strong> Think about layout before inserting breaks - sketch it out if needed.</li>
                    <li><strong>Practice with Real Documents:</strong> Try reformatting an existing document to apply your new skills.</li>
                    <li><strong>Check Print Preview:</strong> Always preview how breaks and columns will print before finalizing.</li>
                    <li><strong>Use Format Painter Wisely:</strong> Double-click to apply formatting multiple times, press Esc when done.</li>
                    <li><strong>Master Keyboard Shortcuts:</strong> They significantly speed up your formatting workflow.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word" target="_blank">Word Styles Tutorial</a></li>
                    <li><a href="https://support.microsoft.com/office/working-with-sections" target="_blank">Working with Sections in Word</a></li>
                    <li><a href="https://support.microsoft.com/office/create-columns" target="_blank">Creating Multiple Columns Guide</a></li>
                    <li><strong>Practice files and video walkthroughs</strong> available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p>In Week 3, we'll dive into Tables and Lists. You'll learn how to:</p>
                <ul>
                    <li>Create and format professional tables</li>
                    <li>Sort table data alphabetically or numerically</li>
                    <li>Create bulleted and numbered lists</li>
                    <li>Customize list styles and formats</li>
                    <li>Convert text to tables and vice versa</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring any documents you'd like to organize in table format!</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week2.php">Week 2 Discussion</a></li>
                    <li><strong>Microsoft Word Help:</strong> <a href="https://support.microsoft.com/word" target="_blank">Official Support</a></li>
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
            </div>
        </div>

        <footer>
            <p>MO-100: Microsoft Word Certification Prep Program – Week 2 Handout</p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-100 Word Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
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
            alert('Newsletter template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/templates/newsletter_template.docx';
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
                console.log('Word Week 2 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Use Format Painter: Select formatted text → Home tab → Format Painter (or Ctrl+Shift+C) → select target text.",
                    "2. Page break only moves content to next page, while section break creates a new section with different formatting (margins, orientation, headers/footers).",
                    "3. Select paragraph → Home tab → Line spacing button → choose 1.5, or Paragraph dialog box → Line spacing → 1.5 lines.",
                    "4. Home tab → Styles gallery click, or use keyboard shortcuts: Alt+Ctrl+1 for Heading 1, Alt+Ctrl+2 for Heading 2, etc.",
                    "5. Layout tab → Columns → More Columns → choose 'Three' → check 'Line between' → OK."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive formatting tool demonstration
        document.addEventListener('DOMContentLoaded', function() {
            const formattingTools = document.querySelectorAll('.formatting-tool');
            formattingTools.forEach(tool => {
                tool.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const shortcut = this.querySelector('p:nth-child(4)').textContent;
                    alert(`${title}\n\n${shortcut}\n\nTo use: ${this.querySelector('p:nth-child(3)').textContent}`);
                });
            });
        });

        // Demo: Toggle text effects
        const textEffects = document.querySelectorAll('.text-effect');
        textEffects.forEach(effect => {
            effect.addEventListener('click', function() {
                const effectType = this.classList.contains('shadow') ? 'Shadow' : 
                                  this.style.webkitTextStroke ? 'Outline' : 
                                  this.style.textShadow ? 'Glow' : 'Standard';
                alert(`Text Effect: ${effectType}\n\nTo apply: Home tab → Font group → Text Effects and Typography → Choose effect`);
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
    $viewer = new WordWeek2HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>