<?php
// modules/shared/course_materials/MSWord/word_week1_view.php

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
 * Word Week 1 Handout Viewer Class with PDF Download
 */
class WordWeek1HandoutViewer
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
            $mpdf->SetTitle('Week 1: Introduction to Word & Document Management Basics');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Introduction, Interface, Document Management, Navigation');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week1_Introduction_' . date('Y-m-d') . '.pdf';
            
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
                Week 1: Introduction to Word & Document Management Basics
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 1!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we'll start from the very beginning. You'll learn how to navigate the Microsoft Word interface, create and save documents, and use essential tools to manage and share your work. By the end of this session, you'll be comfortable opening, editing, saving, and sharing a Word document—skills that form the foundation for everything else you'll learn in this course.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Identify and use key components of the Word 2019 interface.</li>
                    <li>Create, save, and open documents in various formats.</li>
                    <li>Navigate efficiently within a document using search, bookmarks, and the Navigation Pane.</li>
                    <li>Show or hide formatting symbols to better understand document structure.</li>
                    <li>Adjust basic print settings and share documents electronically.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. The Word 2019 Interface</h3>
                <ul>
                    <li><strong>Quick Access Toolbar</strong> – Customizable toolbar for your most-used commands.</li>
                    <li><strong>Ribbon</strong> – Contains tabs (Home, Insert, Design, etc.) with grouped commands.</li>
                    <li><strong>Document Area</strong> – Where you type and edit your content.</li>
                    <li><strong>Status Bar</strong> – Shows page count, word count, zoom, and view options.</li>
                    <li><strong>Backstage View</strong> – Accessed via File tab; contains Save, Open, Print, Share, and Export options.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Creating and Saving Documents</h3>
                <p><strong>Creating a New Document:</strong></p>
                <ul>
                    <li>Start with a blank document or a template.</li>
                    <li>Use Ctrl + N to create a new blank document quickly.</li>
                </ul>
                
                <p><strong>Saving Your Work:</strong></p>
                <ul>
                    <li>Save As: Choose format (e.g., .docx, .pdf, .rtf).</li>
                    <li>AutoSave/OneDrive: Save to the cloud for automatic versioning.</li>
                    <li>Keyboard Shortcut: Ctrl + S to save quickly.</li>
                </ul>
                
                <p><strong>File Formats Overview:</strong></p>
                <ul>
                    <li><strong>.docx</strong> – Standard Word document.</li>
                    <li><strong>.pdf</strong> – Portable Document Format (preserves formatting).</li>
                    <li><strong>.rtf</strong> – Rich Text Format (compatible with many word processors).</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Navigating Within Documents</h3>
                <ul>
                    <li><strong>Using Find (Ctrl + F):</strong> Search for specific text.</li>
                    <li><strong>Using Go To (Ctrl + G):</strong> Jump to a page, section, line, or bookmark.</li>
                    <li><strong>Navigation Pane (Ctrl + F or View → Navigation Pane):</strong></li>
                    <ul>
                        <li>Browse by headings, pages, or search results.</li>
                        <li>Drag headings to reorganize document structure.</li>
                    </ul>
                    <li><strong>Bookmarks:</strong></li>
                    <ul>
                        <li>Insert → Links → Bookmark to mark a location.</li>
                        <li>Useful for long documents.</li>
                    </ul>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">4. Viewing Formatting Symbols</h3>
                <ul>
                    <li><strong>Show/Hide ¶ (Ctrl + Shift + *):</strong> Reveals spaces, tabs, paragraph marks, and breaks.</li>
                    <li><strong>Why It's Useful:</strong> Helps you see hidden formatting and troubleshoot layout issues.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">5. Print Settings and Sharing</h3>
                <p><strong>Print Preview:</strong> File → Print to see how the document will look.</p>
                <p><strong>Print Options:</strong></p>
                <ul>
                    <li>Choose printer, page range, copies, and orientation.</li>
                    <li>Adjust margins and scaling if needed.</li>
                </ul>
                <p><strong>Sharing Documents:</strong></p>
                <ul>
                    <li>Share via email, OneDrive, or SharePoint.</li>
                    <li>Attach as a file or send a link with viewing/editing permissions.</li>
                    <li>Export as PDF for easy sharing.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Create, Format, Save, and Share a Simple Document</strong></p>
                <p>Follow these steps to apply what you've learned:</p>
                <ol>
                    <li>Open Word and create a new blank document.</li>
                    <li>Type a short paragraph introducing yourself (name, background, course goals).</li>
                    <li>Use Find (Ctrl + F) to locate a word in your paragraph.</li>
                    <li>Insert a Bookmark at the beginning of your paragraph.</li>
                    <li>Turn on Show/Hide ¶ to view formatting symbols.</li>
                    <li>Save your document as:
                        <ul>
                            <li>YourName_Week1.docx</li>
                            <li>Then Save As → PDF format</li>
                        </ul>
                    </li>
                    <li>Go to File → Print and adjust settings (e.g., print 1 copy, portrait orientation).</li>
                    <li>Share your PDF via email (simulated or real).</li>
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
                            <td style="padding: 6px 8px;">Ctrl + N</td>
                            <td style="padding: 6px 8px;">New document</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + O</td>
                            <td style="padding: 6px 8px;">Open document</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + S</td>
                            <td style="padding: 6px 8px;">Save</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + S</td>
                            <td style="padding: 6px 8px;">Save As</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + P</td>
                            <td style="padding: 6px 8px;">Print</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + F</td>
                            <td style="padding: 6px 8px;">Find</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + H</td>
                            <td style="padding: 6px 8px;">Replace</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + G</td>
                            <td style="padding: 6px 8px;">Go To</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + Shift + *</td>
                            <td style="padding: 6px 8px;">Show/Hide formatting symbols</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Backstage View:</strong> The menu accessed via the File tab.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Ribbon:</strong> The toolbar at the top with tabs and command groups.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Navigation Pane:</strong> A sidebar that helps you browse through headings, pages, or search results.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Bookmark:</strong> A named location in a document that you can jump to.</p>
                </div>
                <div>
                    <p><strong>PDF:</strong> A fixed-layout file format that preserves fonts and layout.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>What are two ways to create a new document in Word?</li>
                    <li>How do you save a document as a PDF?</li>
                    <li>What is the purpose of the Navigation Pane?</li>
                    <li>Why would you want to show formatting symbols?</li>
                    <li>How can you quickly jump to page 5 in a long document?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Navigate the Word interface</li>
                    <li>Create and save documents</li>
                    <li>Open documents in different views</li>
                    <li>Use the Navigation Pane</li>
                    <li>Insert bookmarks</li>
                    <li>Show/hide formatting symbols</li>
                    <li>Adjust print settings</li>
                    <li>Share documents electronically</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Practice Daily:</strong> Even 15 minutes a day helps build muscle memory.</li>
                    <li><strong>Use Shortcuts:</strong> Keyboard shortcuts save time and are often tested on the exam.</li>
                    <li><strong>Explore the Interface:</strong> Don't be afraid to click around—Word is designed to be discoverable.</li>
                    <li><strong>Ask Questions:</strong> Use our class forum or live Q&A sessions if you're stuck.</li>
                    <li><strong>Save Often:</strong> Use Ctrl + S frequently to avoid losing work.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 2, we'll dive into formatting text and paragraphs, using styles, working with sections, and creating multi-column layouts. Bring your questions and be ready to make your documents look professional!</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Word Interface Guide</li>
                    <li>Navigation and Search Techniques Tutorial</li>
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
                Week 1 Handout: Introduction to Word & Document Management Basics
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
            Week 1: Word Introduction & Basics | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 1: Introduction to Word & Document Management - Impact Digital Academy</title>
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

        /* Word Interface Demo */
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
            color: #185abd;
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

        .format-card.docx {
            border-color: #185abd;
            background: #f0f7ff;
        }

        .format-card.pdf {
            border-color: #d32f2f;
            background: #ffebee;
        }

        .format-card.rtf {
            border-color: #388e3c;
            background: #e8f5e9;
        }

        .format-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .format-card.docx .format-icon {
            color: #185abd;
        }

        .format-card.pdf .format-icon {
            color: #d32f2f;
        }

        .format-card.rtf .format-icon {
            color: #388e3c;
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

            .interface-demo,
            .format-cards {
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
                <strong>Access Granted:</strong> Word Week 1 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week2_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-right"></i> Week 2
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 1 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Introduction to Word & Document Management Basics</div>
            <div class="week-tag">Week 1 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 1!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we'll start from the very beginning. You'll learn how to navigate the Microsoft Word interface, create and save documents, and use essential tools to manage and share your work. By the end of this session, you'll be comfortable opening, editing, saving, and sharing a Word document—skills that form the foundation for everything else you'll learn in this course.
                </p>

                <div class="image-container">
                    <img src="images/word_interface.png"
                        alt="Microsoft Word Interface"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+TWljcm9zb2Z0IFdvcmQgSW50ZXJmYWNlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Microsoft Word 2019 Interface Overview</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Identify and use key components of the Word 2019 interface.</li>
                    <li>Create, save, and open documents in various formats.</li>
                    <li>Navigate efficiently within a document using search, bookmarks, and the Navigation Pane.</li>
                    <li>Show or hide formatting symbols to better understand document structure.</li>
                    <li>Adjust basic print settings and share documents electronically.</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Navigate the Word interface</li>
                        <li>Create and save documents</li>
                        <li>Open documents in different views</li>
                        <li>Use the Navigation Pane</li>
                    </ul>
                    <ul>
                        <li>Insert bookmarks</li>
                        <li>Show/hide formatting symbols</li>
                        <li>Adjust print settings</li>
                        <li>Share documents electronically</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Word Interface -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-desktop"></i> 1. The Word 2019 Interface
                </div>

                <div class="interface-demo">
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <h4>Quick Access Toolbar</h4>
                        <p>Customizable toolbar for your most-used commands</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-bars"></i>
                        </div>
                        <h4>Ribbon</h4>
                        <p>Tabs (Home, Insert, Design) with grouped commands</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4>Document Area</h4>
                        <p>Where you type and edit your content</p>
                    </div>
                    <div class="interface-item">
                        <div class="interface-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h4>Status Bar</h4>
                        <p>Shows page count, word count, zoom, and view options</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-folder-open"></i> Backstage View</h3>
                    <ul>
                        <li>Accessed via <strong>File tab</strong></li>
                        <li>Contains Save, Open, Print, Share, and Export options</li>
                        <li>Manage document properties and permissions</li>
                        <li>Access recent documents and templates</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/file_tab.png"
                            alt="Backstage View"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5CYWNrc3RhZ2UgVmlldyBJbnRlcmZhY2U8L3RleHQ+PC9zdmc+'">
                        <div class="image-caption">File Tab Backstage View</div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Creating and Saving Documents -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file"></i> 2. Creating and Saving Documents
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Creating a New Document</h3>
                    <ul>
                        <li>Start with a <strong>blank document</strong> or a <strong>template</strong></li>
                        <li>Use <strong>Ctrl + N</strong> to create a new blank document quickly</li>
                        <li>Access templates from File → New</li>
                        <li>Choose from resume, letter, report, and other templates</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-save"></i> Saving Your Work</h3>
                    <ul>
                        <li><strong>Save As:</strong> Choose format (e.g., .docx, .pdf, .rtf)</li>
                        <li><strong>AutoSave/OneDrive:</strong> Save to the cloud for automatic versioning</li>
                        <li><strong>Keyboard Shortcut:</strong> Ctrl + S to save quickly</li>
                        <li><strong>Version History:</strong> Access previous versions in OneDrive</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-file-export"></i> File Formats Overview</h3>
                    <div class="format-cards">
                        <div class="format-card docx">
                            <div class="format-icon">
                                <i class="fas fa-file-word"></i>
                            </div>
                            <h4>.docx</h4>
                            <p>Standard Word document format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Default format</p>
                        </div>
                        <div class="format-card pdf">
                            <div class="format-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <h4>.pdf</h4>
                            <p>Portable Document Format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Preserves formatting</p>
                        </div>
                        <div class="format-card rtf">
                            <div class="format-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <h4>.rtf</h4>
                            <p>Rich Text Format</p>
                            <p style="font-size: 0.9rem; color: #666; margin-top: 5px;">Wide compatibility</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Navigation -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-search"></i> 3. Navigating Within Documents
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-binoculars"></i> Find and Go To</h3>
                    <ul>
                        <li><strong>Find (Ctrl + F):</strong> Search for specific text in document</li>
                        <li><strong>Replace (Ctrl + H):</strong> Find and replace text</li>
                        <li><strong>Go To (Ctrl + G):</strong> Jump to page, section, line, or bookmark</li>
                        <li>Use wildcards (*, ?) for advanced searches</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-columns"></i> Navigation Pane</h3>
                    <ul>
                        <li>Open with <strong>Ctrl + F</strong> or <strong>View → Navigation Pane</strong></li>
                        <li>Browse by headings, pages, or search results</li>
                        <li>Drag headings to reorganize document structure</li>
                        <li>Filter search results with options dropdown</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/navigation_pane.png"
                            alt="Navigation Pane">
                                            <div class="image-caption">Navigation Pane for Easy Document Browsing</div>
                </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-bookmark"></i> Bookmarks</h3>
                    <ul>
                        <li><strong>Insert → Links → Bookmark</strong> to mark a location</li>
                        <li>Useful for long documents and technical papers</li>
                        <li>Jump between bookmarks with Go To (Ctrl + G)</li>
                        <li>Organize bookmarks with descriptive names</li>
                    </ul>
                </div>
            </div>

            <!-- Section 4: Formatting Symbols -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-eye"></i> 4. Viewing Formatting Symbols
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-paragraph"></i> Show/Hide Formatting Symbols</h3>
                    <ul>
                        <li><strong>Shortcut:</strong> Ctrl + Shift + *</li>
                        <li>Reveals spaces, tabs, paragraph marks, and breaks</li>
                        <li>Toggle button on Home tab (¶ icon)</li>
                        <li>Essential for troubleshooting layout issues</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/hide.png"
                            alt="Formatting Symbols"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Gb3JtYXR0aW5nIFN5bWJvbHMgRGlzcGxheTwvdGV4dD48L3N2Zz4='">
                        <div class="image-caption">Formatting Symbols Reveal Hidden Document Structure</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-question-circle"></i> Why It's Useful</h3>
                    <ul>
                        <li>Identify extra spaces or tabs</li>
                        <li>See paragraph and line breaks</li>
                        <li>Understand document structure</li>
                        <li>Fix formatting inconsistencies</li>
                        <li>Debug layout problems</li>
                    </ul>
                </div>
            </div>

            <!-- Section 5: Print and Share -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-print"></i> 5. Print Settings and Sharing
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-print"></i> Print Preview and Options</h3>
                    <ul>
                        <li><strong>File → Print</strong> to see how document will look</li>
                        <li>Choose printer, page range, copies, and orientation</li>
                        <li>Adjust margins and scaling if needed</li>
                        <li>Print specific pages or sections</li>
                        <li>Print to PDF for digital distribution</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-share-alt"></i> Sharing Documents</h3>
                    <ul>
                        <li>Share via email, OneDrive, or SharePoint</li>
                        <li>Attach as a file or send a link with viewing/editing permissions</li>
                        <li>Export as PDF for easy sharing</li>
                        <li>Control access permissions for collaborators</li>
                        <li>Track document version history</li>
                    </ul>

                    <div class="image-container">
                        <img src="images/share.png"
                            alt="Document Sharing"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Eb2N1bWVudCBTaGFyaW5nIE9wdGlvbnM8L3RleHQ+PC9zdmc+'">
                        <div class="image-caption">Collaborate and Share Documents Securely</div>
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
                            <td>New document</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + O</span></td>
                            <td>Open document</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + S</span></td>
                            <td>Save</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + S</span></td>
                            <td>Save As</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + P</span></td>
                            <td>Print</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + F</span></td>
                            <td>Find</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + H</span></td>
                            <td>Replace</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + G</span></td>
                            <td>Go To</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + *</span></td>
                            <td>Show/Hide formatting symbols</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F12</span></td>
                            <td>Save As dialog</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + F1</span></td>
                            <td>Show/hide Ribbon</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> 7. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Create, Format, Save, and Share a Simple Document</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li><strong>Open Word</strong> and create a new blank document.</li>
                        <li>Type a short paragraph introducing yourself (name, background, course goals).</li>
                        <li>Use <strong>Find (Ctrl + F)</strong> to locate a word in your paragraph.</li>
                        <li>Insert a <strong>Bookmark</strong> at the beginning of your paragraph.</li>
                        <li>Turn on <strong>Show/Hide ¶</strong> to view formatting symbols.</li>
                        <li>Save your document as:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong><?php echo htmlspecialchars($studentName); ?>_Week1.docx</strong></li>
                                <li>Then <strong>Save As → PDF</strong> format</li>
                            </ul>
                        </li>
                        <li>Go to <strong>File → Print</strong> and adjust settings (e.g., print 1 copy, portrait orientation).</li>
                        <li>Share your PDF via email (simulated or real).</li>
                    </ol>
                </div>

                <!--
                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1517077304055-6e89abbf09b0?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Document Creation Exercise"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5QcmFjdGljZSBEb2N1bWVudCBDcmVhdGlvbjwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Create Your First Professional Document</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Practice Template
                </a> -->
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 8. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Backstage View</strong>
                    <p>The menu accessed via the File tab containing Save, Open, Print, Share, and Export options.</p>
                </div>

                <div class="term">
                    <strong>Ribbon</strong>
                    <p>The toolbar at the top with tabs and command groups that organize Word's features.</p>
                </div>

                <div class="term">
                    <strong>Navigation Pane</strong>
                    <p>A sidebar that helps you browse through headings, pages, or search results in a document.</p>
                </div>

                <div class="term">
                    <strong>Bookmark</strong>
                    <p>A named location in a document that you can mark and jump to for quick navigation.</p>
                </div>

                <div class="term">
                    <strong>PDF</strong>
                    <p>A fixed-layout file format that preserves fonts and layout, ideal for sharing and printing.</p>
                </div>

                <div class="term">
                    <strong>Quick Access Toolbar</strong>
                    <p>A customizable toolbar for your most-used commands, located above the Ribbon.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-question-circle"></i> 9. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Test Your Knowledge:</h4>
                    <ol>
                        <li>What are two ways to create a new document in Word?</li>
                        <li>How do you save a document as a PDF?</li>
                        <li>What is the purpose of the Navigation Pane?</li>
                        <li>Why would you want to show formatting symbols?</li>
                        <li>How can you quickly jump to page 5 in a long document?</li>
                        <li>What shortcut opens the Save As dialog?</li>
                        <li>How do you insert a bookmark in a document?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Homework Assignment:</strong> Access your <a href = "https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/word/week1_assignment.html" target="_blank">assignment here.</a> Complete the assignment and submit files via the class portal by due date.>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 10. Tips for Success
                </div>
                <ul>
                    <li><strong>Practice Daily:</strong> Even 15 minutes a day helps build muscle memory.</li>
                    <li><strong>Use Shortcuts:</strong> Keyboard shortcuts save time and are often tested on the exam.</li>
                    <li><strong>Explore the Interface:</strong> Don't be afraid to click around—Word is designed to be discoverable.</li>
                    <li><strong>Ask Questions:</strong> Use our class forum or live Q&A sessions if you're stuck.</li>
                    <li><strong>Save Often:</strong> Use Ctrl + S frequently to avoid losing work.</li>
                    <li><strong>Use Templates:</strong> Start with templates for common document types.</li>
                    <li><strong>Organize Files:</strong> Create folders for different projects and use descriptive filenames.</li>
                    <li><strong>Backup Your Work:</strong> Save important documents to OneDrive or external storage.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word" target="_blank">Microsoft Word Official Support</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-document-in-word-3e7d2f0e-9b5a-4c2d-a1a8-5b7c0c0b0b0b" target="_blank">Create a Document in Word Guide</a></li>
                    <li><a href="https://support.microsoft.com/office/save-a-document-in-word-3b2c9c1a-0b9c-4b2c-9c1a-0b9c4b2c9c1a" target="_blank">Save and Share Documents Tutorial</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal.</li>
                    <li><strong>Interactive Word Simulator</strong> for hands-on practice without installing Word.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 11. Next Week Preview
                </div>
                <p><strong>Week 2: Formatting Text and Paragraphs</strong></p>
                <p>In Week 2, we'll dive deeper into Word's formatting capabilities. You'll learn to:</p>
                <ul>
                    <li>Apply and modify text formatting (font, size, color)</li>
                    <li>Format paragraphs (alignment, indentation, spacing)</li>
                    <li>Use styles for consistent formatting</li>
                    <li>Work with sections and multi-column layouts</li>
                    <li>Create bulleted and numbered lists</li>
                    <li>Apply borders and shading</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring a document you'd like to format professionally (resume, report, or letter).</p>
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
                    <li><strong>Office Hours:</strong> Mondays & Wednesdays, 10:00 AM - 12:00 PM</li>
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week1.php">Week 1 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/word_week1_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 1 Quiz
                </a> -->
            </div>
        </div>

        <footer>
            <p>MO-100: Microsoft Word Certification Prep Program – Week 1 Handout</p>
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
            alert('Practice template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/templates/week1_practice.docx';
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
                console.log('Word Week 1 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
                // fetch('<?php echo BASE_URL; ?>modules/shared/log_access.php', {
                //     method: 'POST',
                //     body: JSON.stringify({
                //         user_id: <?php echo $this->user_id; ?>,
                //         resource: 'Word Week 1 Handout',
                //         action: 'view'
                //     })
                // });
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Two ways to create a new document: Click File → New → Blank Document, OR use Ctrl + N shortcut.",
                    "2. To save as PDF: File → Save As → choose PDF from file type dropdown → click Save.",
                    "3. Navigation Pane allows browsing by headings, pages, or search results. Open with Ctrl + F.",
                    "4. Show formatting symbols to see hidden formatting (spaces, tabs, breaks) and troubleshoot layout issues.",
                    "5. Quickly jump to page 5: Press Ctrl + G → type 5 → press Enter.",
                    "6. F12 opens the Save As dialog box.",
                    "7. Insert a bookmark: Place cursor → Insert tab → Links group → Bookmark → enter name → click Add."
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
                    alert(`${title}\n\n${description}\n\nTry it in Word now!`);
                });
            });

            // Format cards interaction
            const formatCards = document.querySelectorAll('.format-card');
            formatCards.forEach(card => {
                card.addEventListener('click', function() {
                    const format = this.querySelector('h4').textContent;
                    const useCase = this.querySelector('p:last-child').textContent;
                    alert(`${format} Format\n\nBest for: ${useCase}\n\nUse when: ${format === '.docx' ? 'editing and collaboration' : format === '.pdf' ? 'sharing final version' : 'compatibility with other word processors'}`);
                });
            });
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'n': 'New Document (Ctrl + N)',
                'o': 'Open Document (Ctrl + O)',
                's': 'Save (Ctrl + S)',
                'p': 'Print (Ctrl + P)',
                'f': 'Find (Ctrl + F)',
                'g': 'Go To (Ctrl + G)'
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
                shortcutAlert.textContent = `Shortcut Activated: ${shortcuts[e.key]}`;
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
        `;
        document.head.appendChild(style);

        // Accessibility enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard navigation hints
            const interactiveElements = document.querySelectorAll('a, button, .interface-item, .format-card');
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
    $viewer = new WordWeek1HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>