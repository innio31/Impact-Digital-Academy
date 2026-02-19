<?php
// modules/shared/course_materials/MSWord/word_syllabus_view.php

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
 * MO-100 Word Syllabus Viewer Class with PDF Download
 */
class WordSyllabusViewer
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
        $access_count = $this->user_role === 'student'
            ? $this->checkGeneralStudentAccess()
            : $this->checkGeneralInstructorAccess();
        
        if ($access_count === 0) {
            $this->redirectToDashboard();
        }
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
                'default_font_size' => 11,
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
                // Try with older class name
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            // Set document information
            $mpdf->SetTitle('MO-100 Microsoft Word Certification Program Syllabus');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Microsoft Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Syllabus, Certification, Course Outline, Exam Preparation');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'MO-100_Word_Certification_Syllabus_' . date('Y-m-d') . '.pdf';
            
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
                    <i class="fas fa-print"></i> Print Syllabus
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
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 11pt;">
            <h1 style="color: #0d3d8c; border-bottom: 3px solid #0d3d8c; padding-bottom: 15px; font-size: 20pt; text-align: center;">
                MO-100: Microsoft Word (Office 2019) Certification Program
            </h1>
            
            <h2 style="color: #185abd; font-size: 16pt; margin-top: 25px; margin-bottom: 20px;">
                Complete Course Syllabus
            </h2>
            
            <!-- Program Overview -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f0f7ff; border-radius: 8px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #cce0ff; padding-bottom: 10px;">
                    <i class="fas fa-bullseye"></i> Program Goal
                </h3>
                <p style="font-size: 12pt; line-height: 1.8;">
                    Prepare learners with little to no prior experience in Microsoft Word to confidently take and pass the MO-100: Microsoft Word (Office 2019) certification exam.
                </p>
            </div>
            
            <!-- Program Structure -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-calendar-alt"></i> Program Structure
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #e8f0ff;">
                        <td style="padding: 10px; border: 1px solid #ddd; width: 30%; font-weight: bold;">Duration</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">8 Weeks (40 hours total)</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Format</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Weekly modules with live sessions, hands-on exercises, and assessments</td>
                    </tr>
                    <tr style="background-color: #e8f0ff;">
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Target Audience</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Beginners to Intermediate Word users</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">Certification</td>
                        <td style="padding: 10px; border: 1px solid #ddd;">MO-100: Microsoft Word (Office 2019)</td>
                    </tr>
                </table>
            </div>
            
            <!-- Weekly Breakdown Header -->
            <div style="text-align: center; margin: 30px 0; padding: 15px; background: linear-gradient(135deg, #0d3d8c 0%, #185abd 100%); color: white; border-radius: 8px;">
                <h3 style="margin: 0; font-size: 16pt;">
                    <i class="fas fa-list-ol"></i> Weekly Breakdown
                </h3>
            </div>
            
            <!-- Week 1 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0d3d8c; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 1: Introduction to Word & Document Management Basics
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Understand the Word interface</li>
                            <li>Create, save, and share documents</li>
                            <li>Navigate within documents</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Overview of Word 2019 interface</li>
                            <li>Creating and saving documents (different formats)</li>
                            <li>Using navigation tools: Search, Go To, bookmarks</li>
                            <li>Show/hide formatting symbols</li>
                            <li>Basic print settings and sharing documents</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a simple document, save as PDF, share via email simulation</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 2 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #185abd; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 2: Formatting Text, Paragraphs, and Sections
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Format text and paragraphs professionally</li>
                            <li>Use styles and Format Painter</li>
                            <li>Work with sections and columns</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Applying text effects and styles</li>
                            <li>Line spacing, indentation, and alignment</li>
                            <li>Using Format Painter</li>
                            <li>Creating sections and column layouts</li>
                            <li>Inserting page/section breaks</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Format a newsletter-style document with columns and section breaks</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 3 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0d3d8c; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 3: Working with Tables and Lists
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Create and modify tables</li>
                            <li>Create and customize lists</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Converting text to tables and vice versa</li>
                            <li>Sorting data, merging/splitting cells</li>
                            <li>Repeating header rows</li>
                            <li>Bulleted and numbered lists, customizing formats</li>
                            <li>Multi-level lists and restarting numbering</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a project plan with tables and nested lists</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 4 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #185abd; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 4: Graphics and Visual Elements
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Insert and format images, shapes, and SmartArt</li>
                            <li>Add text boxes and 3D models</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Inserting pictures, shapes, SmartArt, screenshots</li>
                            <li>Removing backgrounds, applying artistic effects</li>
                            <li>Wrapping text around objects</li>
                            <li>Adding alt text for accessibility</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Design a flyer with images, shapes, and text boxes</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 5 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0d3d8c; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 5: References and Citations
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Insert footnotes, endnotes, and citations</li>
                            <li>Create a table of contents and bibliography</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Adding and modifying footnotes/endnotes</li>
                            <li>Creating citation sources</li>
                            <li>Inserting and customizing a table of contents</li>
                            <li>Generating a bibliography</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Create a short research document with citations and TOC</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 6 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #185abd; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 6: Document Review and Collaboration
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Use comments and track changes</li>
                            <li>Review and resolve edits</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Adding, replying to, and resolving comments</li>
                            <li>Tracking changes, accepting/rejecting edits</li>
                            <li>Locking/unlocking change tracking</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Peer review exercise using comments and track changes</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 7 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #0d3d8c; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 7: Document Inspection and Final Preparation
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Check documents for issues</li>
                            <li>Prepare for the exam structure and question types</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Removing hidden data and personal info</li>
                            <li>Checking accessibility and compatibility</li>
                            <li>Exam tips and practice questions</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Inspect and clean a sample document, then run accessibility check</p>
                    </div>
                </div>
            </div>
            
            <!-- Week 8 -->
            <div style="page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: #185abd; color: white; padding: 15px;">
                    <h4 style="margin: 0; font-size: 13pt;">
                        <i class="fas fa-calendar-week"></i> Week 8: Mock Exam and Review Session
                    </h4>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Objectives:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Simulate exam conditions</li>
                            <li>Review weak areas</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #185abd;">Topics Covered:</strong>
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Full-length practice exam (MO-100 style)</li>
                            <li>Q&A and final review</li>
                            <li>Exam registration guidance</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #185abd;">
                        <strong style="color: #0d3d8c;">Activity:</strong>
                        <p style="margin: 8px 0 0 0;">Timed mock exam followed by group review</p>
                    </div>
                </div>
            </div>
            
            <!-- Course Materials -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f0f7ff; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #cce0ff; padding-bottom: 10px;">
                    <i class="fas fa-book"></i> Course Materials Provided
                </h3>
                <ul style="margin: 15px 0 0 20px;">
                    <li>Weekly handouts and practice files</li>
                    <li>Access to recorded sessions</li>
                    <li>Practice quizzes and mock exams</li>
                    <li>Exam voucher discount information</li>
                </ul>
            </div>
            
            <!-- Assessment -->
            <div style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                    <i class="fas fa-clipboard-check"></i> Assessment
                </h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt;">
                    <tr style="background-color: #e8f0ff;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Weekly quizzes</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">10%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Practical assignments</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">40%</td>
                    </tr>
                    <tr style="background-color: #e8f0ff;">
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Final mock exam</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">50%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background-color: #e8f5e9;">Total</td>
                        <td style="padding: 12px; border: 1px solid #ddd; background-color: #e8f5e9; font-weight: bold;">100%</td>
                    </tr>
                </table>
            </div>
            
            <!-- Prerequisites -->
            <div style="margin-bottom: 25px; padding: 20px; background: #fff9e6; border-left: 5px solid #ff9800; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-exclamation-circle"></i> Prerequisites
                </h3>
                <ul style="margin: 15px 0 0 20px;">
                    <li>Basic computer literacy</li>
                    <li>Access to Microsoft Word 2019 or Office 365</li>
                    <li>Internet connection for online sessions</li>
                    <li>Commitment to 5-7 hours per week</li>
                </ul>
            </div>
            
            <!-- Learning Outcomes -->
            <div style="margin-bottom: 25px; padding: 20px; background: #e8f5e9; border-left: 5px solid #4caf50; border-radius: 8px; page-break-inside: avoid;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">
                    <i class="fas fa-graduation-cap"></i> Learning Outcomes
                </h3>
                <p>Upon successful completion, students will be able to:</p>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Create professional Word documents efficiently</li>
                    <li>Apply advanced formatting and layout techniques</li>
                    <li>Manage references and citations appropriately</li>
                    <li>Collaborate effectively using review tools</li>
                    <li>Pass the MO-100 Microsoft Word certification exam</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 20px; margin-top: 30px; font-size: 10pt;">
                <h4 style="color: #0d3d8c; margin-bottom: 10px; font-size: 12pt;">Program Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($this->user_email); ?></p>
                <p><strong>Program Duration:</strong> 8 Weeks (<?php echo date('F j, Y'); ?> - <?php echo date('F j, Y', strtotime('+8 weeks')); ?>)</p>
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
                Microsoft Word (MO-100) Certification Program
            </h2>
            <h3 style="color: #333; font-size: 22pt; border-top: 3px solid #0d3d8c; 
                border-bottom: 3px solid #0d3d8c; padding: 25px 0; margin: 40px 0;">
                Complete Course Syllabus
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Email: ' . htmlspecialchars($this->user_email) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Program Start Date: ' . date('F j, Y') . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Access Level: ' . ucfirst($this->user_role) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Certification: MO-100 Microsoft Word (Office 2019)
                </p>
            </div>
            <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="color: #888; font-size: 10pt;">
                    © ' . date('Y') . ' Impact Digital Academy. Confidential educational material.
                </p>
                <p style="color: #888; font-size: 9pt;">
                    This syllabus outlines the complete MO-100 Word Certification Prep Program. Unauthorized distribution is prohibited.
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
            MO-100 Microsoft Word Certification Syllabus | Impact Digital Academy | ' . date('m/d/Y') . '
        </div>';
    }
    
    /**
     * Get PDF footer
     */
    private function getPDFFooter(): string
    {
        return '
        <div style="text-align: center; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; color: #666;">
            Page {PAGENO} of {nbpg} | MO-100 Word Certification Program | Student: ' . htmlspecialchars($this->user_email) . '
        </div>';
    }
    
    /**
     * Display the syllabus HTML page
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
        $startDate = date('F j, Y');
        $endDate = date('F j, Y', strtotime('+8 weeks'));
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MO-100 Microsoft Word Certification Syllabus - Impact Digital Academy</title>
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
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect fill="none" width="100" height="100"/><path fill="rgba(255,255,255,0.05)" d="M50,0 L100,50 L50,100 L0,50 Z"/></svg>') repeat;
            opacity: 0.3;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .header .subtitle {
            font-size: 1.6rem;
            opacity: 0.9;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header .cert-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 25px;
            border-radius: 30px;
            margin-top: 20px;
            font-weight: 600;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
            border: 2px solid rgba(255, 255, 255, 0.3);
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

        .week-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 25px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .week-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .week-header {
            background: #0d3d8c;
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .week-header.alt {
            background: #185abd;
        }

        .week-number {
            background: rgba(255, 255, 255, 0.2);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .week-title {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .week-content {
            padding: 25px;
        }

        .objectives-box {
            margin-bottom: 20px;
        }

        .objectives-title {
            color: #0d3d8c;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topics-box {
            margin-bottom: 20px;
        }

        .topics-title {
            color: #185abd;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .activity-box {
            background: #f0f7ff;
            padding: 20px;
            border-left: 4px solid #185abd;
            border-radius: 0 5px 5px 0;
        }

        .activity-title {
            color: #0d3d8c;
            font-size: 1.1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        ul, ol {
            padding-left: 25px;
            margin-bottom: 15px;
        }

        li {
            margin-bottom: 8px;
        }

        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .material-card {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }

        .material-card:hover {
            background: #e8f0ff;
            border-color: #185abd;
        }

        .material-icon {
            font-size: 3rem;
            color: #185abd;
            margin-bottom: 15px;
        }

        .assessment-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .assessment-table th {
            background: #0d3d8c;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .assessment-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .assessment-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .assessment-table tr:last-child td {
            background: #e8f5e9;
            font-weight: bold;
        }

        .outcome-box {
            background: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .outcome-title {
            color: #2e7d32;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prerequisites-box {
            background: #fff9e6;
            border-left: 5px solid #ff9800;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .prerequisites-title {
            color: #e65100;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .program-timeline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f0f7ff;
            padding: 25px;
            border-radius: 8px;
            margin: 30px 0;
            position: relative;
        }

        .timeline-item {
            text-align: center;
            flex: 1;
            position: relative;
        }

        .timeline-item:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 25px;
            right: -10%;
            width: 20%;
            height: 2px;
            background: #185abd;
        }

        .timeline-icon {
            font-size: 2.5rem;
            color: #185abd;
            margin-bottom: 10px;
        }

        .timeline-text {
            font-weight: 600;
            color: #0d3d8c;
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
            border: none;
            font-size: 1rem;
        }

        .download-btn:hover {
            background: #0d3d8c;
        }

        .download-section {
            text-align: center;
            margin: 40px 0;
            padding: 30px;
            background: #f0f7ff;
            border-radius: 8px;
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

        footer {
            text-align: center;
            padding: 30px;
            background-color: #f2f2f2;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #ddd;
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

            .program-timeline {
                flex-direction: column;
                gap: 30px;
            }

            .timeline-item:not(:last-child)::after {
                display: none;
            }

            .materials-grid {
                grid-template-columns: 1fr;
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

            .week-card {
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
                <strong>Access Granted:</strong> MO-100 Complete Syllabus
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
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Microsoft Word (Office 2019)</div>
            <div style="font-size: 1.8rem; margin: 20px 0; font-weight: 300;">Complete Certification Program Syllabus</div>
            <div class="cert-badge">
                <i class="fas fa-certificate"></i> Microsoft Office Specialist Certification
            </div>
        </div>

        <div class="content">
            <!-- Program Overview -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Program Goal
                </div>
                <p style="font-size: 1.2rem; line-height: 1.8; margin-bottom: 20px; color: #555;">
                    Prepare learners with little to no prior experience in Microsoft Word to confidently take and pass the MO-100: Microsoft Word (Office 2019) certification exam.
                </p>

                <!-- Program Timeline -->
                <div class="program-timeline">
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="timeline-text">
                            Start Date<br>
                            <span style="font-size: 1.2rem; font-weight: bold;"><?php echo $startDate; ?></span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="timeline-text">
                            Duration<br>
                            <span style="font-size: 1.2rem; font-weight: bold;">8 Weeks</span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="timeline-text">
                            End Date<br>
                            <span style="font-size: 1.2rem; font-weight: bold;"><?php echo $endDate; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Program Structure -->
                <div style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-top: 30px;">
                    <h3 style="color: #0d3d8c; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-cogs"></i> Program Structure
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #185abd;">
                            <strong style="color: #0d3d8c; display: block; margin-bottom: 10px;">Format</strong>
                            <p>Weekly modules with live sessions, hands-on exercises, and assessments</p>
                        </div>
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #185abd;">
                            <strong style="color: #0d3d8c; display: block; margin-bottom: 10px;">Target Audience</strong>
                            <p>Beginners to Intermediate Word users</p>
                        </div>
                        <div style="padding: 20px; background: white; border-radius: 5px; border-left: 4px solid #185abd;">
                            <strong style="color: #0d3d8c; display: block; margin-bottom: 10px;">Total Hours</strong>
                            <p>40 hours (5 hours per week)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Breakdown -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-list-ol"></i> Weekly Breakdown
                </div>

                <!-- Week 1 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">1</div>
                        <div class="week-title">Introduction to Word & Document Management Basics</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Understand the Word interface</li>
                                <li>Create, save, and share documents</li>
                                <li>Navigate within documents</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Overview of Word 2019 interface</li>
                                <li>Creating and saving documents (different formats)</li>
                                <li>Using navigation tools: Search, Go To, bookmarks</li>
                                <li>Show/hide formatting symbols</li>
                                <li>Basic print settings and sharing documents</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Create a simple document, save as PDF, share via email simulation</p>
                        </div>
                    </div>
                </div>

                <!-- Week 2 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">2</div>
                        <div class="week-title">Formatting Text, Paragraphs, and Sections</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Format text and paragraphs professionally</li>
                                <li>Use styles and Format Painter</li>
                                <li>Work with sections and columns</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Applying text effects and styles</li>
                                <li>Line spacing, indentation, and alignment</li>
                                <li>Using Format Painter</li>
                                <li>Creating sections and column layouts</li>
                                <li>Inserting page/section breaks</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Format a newsletter-style document with columns and section breaks</p>
                        </div>
                    </div>
                </div>

                <!-- Week 3 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">3</div>
                        <div class="week-title">Working with Tables and Lists</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Create and modify tables</li>
                                <li>Create and customize lists</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Converting text to tables and vice versa</li>
                                <li>Sorting data, merging/splitting cells</li>
                                <li>Repeating header rows</li>
                                <li>Bulleted and numbered lists, customizing formats</li>
                                <li>Multi-level lists and restarting numbering</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Create a project plan with tables and nested lists</p>
                        </div>
                    </div>
                </div>

                <!-- Week 4 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">4</div>
                        <div class="week-title">Graphics and Visual Elements</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Insert and format images, shapes, and SmartArt</li>
                                <li>Add text boxes and 3D models</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Inserting pictures, shapes, SmartArt, screenshots</li>
                                <li>Removing backgrounds, applying artistic effects</li>
                                <li>Wrapping text around objects</li>
                                <li>Adding alt text for accessibility</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Design a flyer with images, shapes, and text boxes</p>
                        </div>
                    </div>
                </div>

                <!-- Week 5 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">5</div>
                        <div class="week-title">References and Citations</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Insert footnotes, endnotes, and citations</li>
                                <li>Create a table of contents and bibliography</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Adding and modifying footnotes/endnotes</li>
                                <li>Creating citation sources</li>
                                <li>Inserting and customizing a table of contents</li>
                                <li>Generating a bibliography</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Create a short research document with citations and TOC</p>
                        </div>
                    </div>
                </div>

                <!-- Week 6 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">6</div>
                        <div class="week-title">Document Review and Collaboration</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Use comments and track changes</li>
                                <li>Review and resolve edits</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Adding, replying to, and resolving comments</li>
                                <li>Tracking changes, accepting/rejecting edits</li>
                                <li>Locking/unlocking change tracking</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Peer review exercise using comments and track changes</p>
                        </div>
                    </div>
                </div>

                <!-- Week 7 -->
                <div class="week-card">
                    <div class="week-header">
                        <div class="week-number">7</div>
                        <div class="week-title">Document Inspection and Final Preparation</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Check documents for issues</li>
                                <li>Prepare for the exam structure and question types</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Removing hidden data and personal info</li>
                                <li>Checking accessibility and compatibility</li>
                                <li>Exam tips and practice questions</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Inspect and clean a sample document, then run accessibility check</p>
                        </div>
                    </div>
                </div>

                <!-- Week 8 -->
                <div class="week-card">
                    <div class="week-header alt">
                        <div class="week-number">8</div>
                        <div class="week-title">Mock Exam and Review Session</div>
                    </div>
                    <div class="week-content">
                        <div class="objectives-box">
                            <div class="objectives-title">
                                <i class="fas fa-bullseye"></i> Objectives
                            </div>
                            <ul>
                                <li>Simulate exam conditions</li>
                                <li>Review weak areas</li>
                            </ul>
                        </div>
                        
                        <div class="topics-box">
                            <div class="topics-title">
                                <i class="fas fa-book"></i> Topics Covered
                            </div>
                            <ul>
                                <li>Full-length practice exam (MO-100 style)</li>
                                <li>Q&A and final review</li>
                                <li>Exam registration guidance</li>
                            </ul>
                        </div>
                        
                        <div class="activity-box">
                            <div class="activity-title">
                                <i class="fas fa-laptop-code"></i> Activity
                            </div>
                            <p>Timed mock exam followed by group review</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course Materials -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Course Materials Provided
                </div>
                
                <div class="materials-grid">
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>Weekly Handouts</h3>
                        <p>Detailed guides for each week's topics with step-by-step instructions</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-file-code"></i>
                        </div>
                        <h3>Practice Files</h3>
                        <p>Downloadable Word documents for hands-on exercises and activities</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <h3>Recorded Sessions</h3>
                        <p>Access to all live session recordings for review and reference</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <h3>Practice Quizzes</h3>
                        <p>Weekly assessments to test your understanding and track progress</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h3>Mock Exams</h3>
                        <p>Full-length practice tests simulating the actual MO-100 exam</p>
                    </div>
                    
                    <div class="material-card">
                        <div class="material-icon">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <h3>Exam Voucher</h3>
                        <p>Discount information for MO-100 certification exam registration</p>
                    </div>
                </div>
            </div>

            <!-- Assessment -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-clipboard-check"></i> Assessment & Grading
                </div>
                
                <p style="margin-bottom: 20px; font-size: 1.1rem;">
                    Student progress is evaluated through a combination of assessments designed to measure both knowledge and practical skills:
                </p>
                
                <table class="assessment-table">
                    <thead>
                        <tr>
                            <th>Assessment Type</th>
                            <th>Description</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Weekly Quizzes</strong></td>
                            <td>Short knowledge checks at the end of each week covering key concepts</td>
                            <td>10%</td>
                        </tr>
                        <tr>
                            <td><strong>Practical Assignments</strong></td>
                            <td>Hands-on exercises applying skills learned each week</td>
                            <td>40%</td>
                        </tr>
                        <tr>
                            <td><strong>Final Mock Exam</strong></td>
                            <td>Comprehensive simulation of the actual MO-100 certification exam</td>
                            <td>50%</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Total</strong></td>
                            <td><strong>100%</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 25px; padding: 20px; background: #f0f7ff; border-radius: 8px;">
                    <h4 style="color: #0d3d8c; margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle"></i> Grading Scale
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">
                        <div style="text-align: center; padding: 15px; background: #e8f5e9; border-radius: 5px;">
                            <strong style="color: #2e7d32;">90-100%</strong><br>Excellent
                        </div>
                        <div style="text-align: center; padding: 15px; background: #e3f2fd; border-radius: 5px;">
                            <strong style="color: #1976d2;">80-89%</strong><br>Good
                        </div>
                        <div style="text-align: center; padding: 15px; background: #fff8e1; border-radius: 5px;">
                            <strong style="color: #ff8f00;">70-79%</strong><br>Satisfactory
                        </div>
                        <div style="text-align: center; padding: 15px; background: #ffebee; border-radius: 5px;">
                            <strong style="color: #d32f2f;">Below 70%</strong><br>Needs Improvement
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prerequisites -->
            <div class="prerequisites-box">
                <div class="prerequisites-title">
                    <i class="fas fa-exclamation-circle"></i> Prerequisites
                </div>
                <p>To successfully complete this program, students should have:</p>
                <ul>
                    <li><strong>Basic computer literacy:</strong> Familiarity with using a computer, mouse, and keyboard</li>
                    <li><strong>Software access:</strong> Microsoft Word 2019 or Office 365 installed on their computer</li>
                    <li><strong>Internet connection:</strong> Stable internet for attending live sessions and accessing materials</li>
                    <li><strong>Time commitment:</strong> Ability to dedicate 5-7 hours per week to coursework</li>
                    <li><strong>Learning attitude:</strong> Willingness to practice and apply new skills</li>
                </ul>
            </div>

            <!-- Learning Outcomes -->
            <div class="outcome-box">
                <div class="outcome-title">
                    <i class="fas fa-graduation-cap"></i> Learning Outcomes
                </div>
                <p>Upon successful completion of this program, students will be able to:</p>
                <ul>
                    <li>Navigate and utilize the Microsoft Word interface efficiently</li>
                    <li>Create, format, and manage professional documents</li>
                    <li>Design documents with tables, graphics, and visual elements</li>
                    <li>Implement references, citations, and bibliographies appropriately</li>
                    <li>Collaborate effectively using comments and track changes features</li>
                    <li>Prepare documents for distribution while maintaining privacy and accessibility</li>
                    <li>Pass the MO-100 Microsoft Word certification exam with confidence</li>
                </ul>
            </div>

            <!-- Additional Information -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Additional Information
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 20px;">
                    <div style="padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                        <h4 style="color: #0d3d8c; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-certificate"></i> Certification Details
                        </h4>
                        <p>The MO-100: Microsoft Word (Office 2019) certification validates skills in creating and managing documents, formatting content, and collaborating with others.</p>
                        <p style="margin-top: 10px;"><strong>Exam Format:</strong> 50-60 questions, 50 minutes</p>
                        <p><strong>Passing Score:</strong> 700 out of 1000</p>
                    </div>
                    
                    <div style="padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                        <h4 style="color: #0d3d8c; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-clock"></i> Time Commitment
                        </h4>
                        <p>Students should plan for approximately 5-7 hours per week:</p>
                        <ul style="margin-top: 10px;">
                            <li>2 hours: Live session or video review</li>
                            <li>2-3 hours: Hands-on practice exercises</li>
                            <li>1-2 hours: Reading and review</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div class="download-section">
                <h3 style="color: #0d3d8c; margin-bottom: 20px;">
                    <i class="fas fa-download"></i> Download Syllabus
                </h3>
                <p style="margin-bottom: 25px; max-width: 600px; margin-left: auto; margin-right: auto;">
                    Download a printable version of this syllabus for your reference. The PDF includes all program details, weekly breakdown, and assessment information.
                </p>
                
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <button onclick="printSyllabus()" class="download-btn">
                        <i class="fas fa-print"></i> Print Syllabus
                    </button>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'] . '&download=pdf'; ?>" class="download-btn" onclick="showPdfAlert()">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </a>
                </div>
            </div>

            <!-- Help Section -->
            <div style="background: #ffebee; padding: 25px; border-radius: 8px; margin-top: 40px;">
                <h3 style="color: #d32f2f; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-question-circle"></i> Need Help or Have Questions?
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Instructor Support</h4>
                        <p><strong>Instructor:</strong> <?php echo htmlspecialchars($this->instructor_name); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($this->instructor_email); ?></p>
                        <p><strong>Office Hours:</strong> Tuesdays & Thursdays, 2:00 PM - 4:00 PM</p>
                    </div>
                    <div>
                        <h4 style="color: #d32f2f; margin-bottom: 10px;">Technical Support</h4>
                        <p><strong>Course Portal:</strong> <a href="<?php echo BASE_URL; ?>modules/student/portal.php" style="color: #0d3d8c; text-decoration: none; font-weight: bold;">Access Portal</a></p>
                        <p><strong>Discussion Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-syllabus.php" style="color: #0d3d8c; text-decoration: none; font-weight: bold;">Syllabus Questions</a></p>
                        <p><strong>Technical Issues:</strong> support@impactdigitalacademy.com</p>
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <p><strong>MO-100: Microsoft Word Certification Prep Program - Complete Syllabus</strong></p>
            <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
            <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                <i class="fas fa-exclamation-triangle"></i> This syllabus outlines the complete MO-100 Word Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
            </div>
            <?php if ($this->user_role === 'student'): ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.9rem;">
                    <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($this->user_email); ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.9rem;">
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
        document.getElementById('current-date').textContent = `Syllabus accessed on: ${currentDate.toLocaleDateString('en-US', options)}`;

        // Print functionality
        function printSyllabus() {
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

        // Week card interaction
        document.addEventListener('DOMContentLoaded', function() {
            const weekCards = document.querySelectorAll('.week-card');
            weekCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (!e.target.closest('a')) {
                        const content = this.querySelector('.week-content');
                        if (content.style.maxHeight && content.style.maxHeight !== '0px') {
                            content.style.maxHeight = '0';
                            content.style.opacity = '0';
                        } else {
                            content.style.maxHeight = content.scrollHeight + 'px';
                            content.style.opacity = '1';
                        }
                    }
                });
            });
        });

        // Track syllabus access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('MO-100 syllabus access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Quick navigation to weeks
        function scrollToWeek(weekNumber) {
            const weekElement = document.querySelector(`[data-week="${weekNumber}"]`);
            if (weekElement) {
                weekElement.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Keyboard shortcuts for instructors
        <?php if ($this->user_role === 'instructor'): ?>
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                alert('Instructor Quick Access:\n\n1. Press Ctrl+1-8 to jump to specific week\n2. Press Ctrl+P to print\n3. Press Ctrl+D to download PDF');
            }
        });
        <?php endif; ?>
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

// Initialize and display the syllabus
try {
    $viewer = new WordSyllabusViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>