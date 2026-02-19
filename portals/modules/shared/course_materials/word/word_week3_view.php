<?php
// modules/shared/course_materials/MSWord/word_week3_view.php

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
 * Word Week 3 Handout Viewer Class with PDF Download
 */
class WordWeek3HandoutViewer
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
            $mpdf->SetTitle('Week 3: Working with Tables and Lists');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Tables, Lists, Formatting, Data Organization');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week3_Tables_Lists_' . date('Y-m-d') . '.pdf';
            
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
                Week 3: Working with Tables and Lists
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 3!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we focus on two powerful tools for organizing information: Tables and Lists. Tables help you present data in a clear, structured grid, while lists allow you to itemize information logically. You'll learn how to create, format, and customize both, making your documents more readable and professional—skills that are critical for the MO-100 exam and real-world document creation.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Create tables by specifying rows/columns and by converting text.</li>
                    <li>Modify table structure (merge/split cells, resize, sort data).</li>
                    <li>Configure table formatting (margins, spacing, repeating headers).</li>
                    <li>Create and format bulleted and numbered lists.</li>
                    <li>Customize bullet characters and number formats.</li>
                    <li>Manage multi-level lists and control numbering (restart, continue, set start values).</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Creating Tables</h3>
                <p><strong>Insert a Table:</strong></p>
                <ul>
                    <li><strong>Insert → Table → drag to select rows/columns.</strong></li>
                    <li><strong>Or choose Insert Table and specify exact dimensions.</strong></li>
                </ul>
                
                <p><strong>Convert Text to a Table:</strong></p>
                <ul>
                    <li>Select text separated by tabs, commas, or paragraphs.</li>
                    <li>Insert → Table → Convert Text to Table.</li>
                </ul>
                
                <p><strong>Convert a Table to Text:</strong></p>
                <ul>
                    <li>Select table → Table Tools (Layout) → Convert to Text.</li>
                    <li>Choose separator (tabs, commas, etc.).</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Modifying Tables</h3>
                <p><strong>Basic Adjustments:</strong></p>
                <ul>
                    <li><strong>Resize:</strong> Drag borders, or use AutoFit (Layout tab).</li>
                    <li><strong>Add/Delete Rows/Columns:</strong> Layout tab → Insert/Delete.</li>
                </ul>
                
                <p><strong>Cell Management:</strong></p>
                <ul>
                    <li><strong>Merge Cells:</strong> Combine multiple cells into one.</li>
                    <li><strong>Split Cells:</strong> Divide a cell into multiple rows/columns.</li>
                    <li><strong>Cell Margins & Spacing:</strong> Layout tab → Cell Margins.</li>
                </ul>
                
                <p><strong>Table Sorting:</strong></p>
                <ul>
                    <li>Select table → Layout tab → Sort.</li>
                    <li>Sort by column, type (text, number, date), and order.</li>
                </ul>
                
                <p><strong>Repeating Header Rows:</strong></p>
                <ul>
                    <li>Select header row → Layout tab → Repeat Header Rows.</li>
                    <li>Ensures header appears at top of each page for long tables.</li>
                </ul>
                
                <p><strong>Split a Table:</strong></p>
                <ul>
                    <li>Place cursor where split should occur → Layout tab → Split Table.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Creating and Modifying Lists</h3>
                <p><strong>Bulleted Lists:</strong></p>
                <ul>
                    <li>Home tab → Bullets.</li>
                    <li>Change bullet style: click arrow for library.</li>
                </ul>
                
                <p><strong>Numbered Lists:</strong></p>
                <ul>
                    <li>Home tab → Numbering.</li>
                    <li>Choose format (1., A., i., etc.).</li>
                </ul>
                
                <p><strong>Customizing Lists:</strong></p>
                <ul>
                    <li><strong>Define New Bullet:</strong> Use symbol, picture, or font.</li>
                    <li><strong>Define New Number Format:</strong> Choose style, font, and alignment.</li>
                </ul>
                
                <p><strong>Multi-Level Lists:</strong></p>
                <ul>
                    <li>Home tab → Multilevel List.</li>
                    <li>Increase/Decrease indent (Tab / Shift + Tab) to change levels.</li>
                </ul>
                
                <p><strong>Controlling Numbering:</strong></p>
                <ul>
                    <li>Right-click list → Restart at 1 or Continue Numbering.</li>
                    <li>Set Numbering Value: Start from a specific number.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Create a Project Plan with Tables and Lists</strong></p>
                <p>Follow these steps to build a comprehensive project plan:</p>
                <ol>
                    <li>Create a New Document titled "Project Kickoff Plan".</li>
                    <li>Insert a Table (4 columns, 5 rows) for a task tracker:</li>
                    <ul>
                        <li>Columns: Task Name, Owner, Due Date, Status.</li>
                        <li>Enter sample data.</li>
                    </ul>
                    <li>Format the Table:</li>
                    <ul>
                        <li>Merge cells in the first row for a title.</li>
                        <li>Apply a table style (Design tab).</li>
                        <li>Set the header row to repeat.</li>
                        <li>Sort tasks alphabetically by "Task Name".</li>
                    </ul>
                    <li>Below the table, create a numbered list for "Project Phases":</li>
                    <ul>
                        <li>Discovery</li>
                        <li>Planning</li>
                        <li>Execution</li>
                        <li>Review</li>
                    </ul>
                    <li>Create a nested bulleted list under "Planning":</li>
                    <ul>
                        <li>Budget</li>
                        <li>Timeline</li>
                        <li>Resources</li>
                    </ul>
                    <li>Customize the bullet for "Resources" to a checkmark symbol.</li>
                    <li>Convert the "Project Phases" numbered list to a table.</li>
                    <li>Save as YourName_Week3_ProjectPlan.docx.</li>
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
                            <td style="padding: 6px 8px;">Tab (in a table)</td>
                            <td style="padding: 6px 8px;">Move to next cell</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + Tab (in a table)</td>
                            <td style="padding: 6px 8px;">Move to previous cell</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + Shift + Up/Down</td>
                            <td style="padding: 6px 8px;">Move table row up/down</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + L</td>
                            <td style="padding: 6px 8px;">Apply bullet list</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + N</td>
                            <td style="padding: 6px 8px;">Apply normal style (remove list)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Tab (in a list)</td>
                            <td style="padding: 6px 8px;">Indent list item (increase level)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + Tab (in a list)</td>
                            <td style="padding: 6px 8px;">Outdent list item (decrease level)</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt + Shift + Right/Left</td>
                            <td style="padding: 6px 8px;">Increase/Decrease list level</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Table Style:</strong> Predefined formatting for tables (colors, borders, shading).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Merge Cells:</strong> Combining two or more cells into one.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Repeating Header Row:</strong> Table header that appears on each page automatically.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Multilevel List:</strong> A list with sub-levels (e.g., 1., 1.1, a., i.).</p>
                </div>
                <div>
                    <p><strong>Numbering Value:</strong> The starting number of a list sequence.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>How do you convert a list of names separated by commas into a table?</li>
                    <li>What is the purpose of a repeating header row, and how do you enable it?</li>
                    <li>How can you change a round bullet to a square or custom image?</li>
                    <li>What steps would you take to restart numbering at 1 in the middle of a document?</li>
                    <li>How do you sort a table by date in ascending order?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Convert text to tables and tables to text</li>
                    <li>Create tables by specifying rows and columns</li>
                    <li>Sort table data</li>
                    <li>Configure cell margins and spacing</li>
                    <li>Merge and split cells</li>
                    <li>Resize tables, rows, and columns</li>
                    <li>Split tables</li>
                    <li>Configure a repeating row header</li>
                    <li>Format paragraphs as numbered and bulleted lists</li>
                    <li>Change bullet characters and number formats</li>
                    <li>Define custom bullet characters and number formats</li>
                    <li>Increase and decrease list levels</li>
                    <li>Restart and continue list numbering</li>
                    <li>Set starting number values</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Plan Table Structure First:</strong> Sketch rows/columns before inserting.</li>
                    <li><strong>Use Table Styles for Consistency:</strong> They're quick and exam-relevant.</li>
                    <li><strong>Master List Levels:</strong> Practice creating outlines with multilevel lists.</li>
                    <li><strong>Check Alignment:</strong> Ensure lists and tables align properly in Print Preview.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 4, we'll explore Graphic Elements. You'll learn to insert and format pictures, shapes, SmartArt, and text boxes, and wrap text around objects. Bring any images or logos you'd like to use in a document!</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Word Tables Training</li>
                    <li>Create Lists in Word</li>
                    <li>Practice files and video demos available in the Course Portal.</li>
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
                Week 3 Handout: Working with Tables and Lists
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
            Week 3: Tables & Lists | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 3: Working with Tables and Lists - Impact Digital Academy</title>
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

        /* Table Demo Styles */
        .table-demo-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .table-demo {
            flex: 1;
            min-width: 300px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .table-demo-header {
            background: #185abd;
            color: white;
            padding: 10px;
            text-align: center;
            font-weight: bold;
        }

        .table-demo-content {
            padding: 15px;
        }

        .list-demo {
            flex: 1;
            min-width: 300px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
        }

        .list-demo-header {
            color: #185abd;
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
        }

        /* Interactive Elements */
        .interactive-demo {
            background: #f5f9ff;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 5px solid #185abd;
        }

        .interactive-title {
            color: #0d3d8c;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .interactive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .interactive-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .interactive-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background: #f0f7ff;
        }

        .interactive-icon {
            font-size: 2rem;
            color: #185abd;
            margin-bottom: 10px;
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

            .table-demo-container {
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
                <strong>Access Granted:</strong> Word Week 3 Handout
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
                <i class="fas fa-arrow-left"></i> Week 2
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 3 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Working with Tables and Lists</div>
            <div class="week-tag">Week 3 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-table"></i> Welcome to Week 3!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we focus on two powerful tools for organizing information: Tables and Lists. Tables help you present data in a clear, structured grid, while lists allow you to itemize information logically. You'll learn how to create, format, and customize both, making your documents more readable and professional—skills that are critical for the MO-100 exam and real-world document creation.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Tables and Lists Organization"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+VGFibGVzIGFuZCBMaXN0cyBPcmdhbml6YXRpb248L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Organize Data with Tables and Lists</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Create tables by specifying rows/columns and by converting text.</li>
                    <li>Modify table structure (merge/split cells, resize, sort data).</li>
                    <li>Configure table formatting (margins, spacing, repeating headers).</li>
                    <li>Create and format bulleted and numbered lists.</li>
                    <li>Customize bullet characters and number formats.</li>
                    <li>Manage multi-level lists and control numbering (restart, continue, set start values).</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Convert text to tables and tables to text</li>
                        <li>Create tables by specifying rows and columns</li>
                        <li>Sort table data</li>
                        <li>Configure cell margins and spacing</li>
                        <li>Merge and split cells</li>
                        <li>Resize tables, rows, and columns</li>
                        <li>Split tables</li>
                    </ul>
                    <ul>
                        <li>Configure a repeating row header</li>
                        <li>Format paragraphs as numbered and bulleted lists</li>
                        <li>Change bullet characters and number formats</li>
                        <li>Define custom bullet characters and number formats</li>
                        <li>Increase and decrease list levels</li>
                        <li>Restart and continue list numbering</li>
                        <li>Set starting number values</li>
                    </ul>
                </div>
            </div>

            <!-- Interactive Demo -->
            <div class="interactive-demo">
                <div class="interactive-title">
                    <i class="fas fa-mouse-pointer"></i> Quick Navigation: Tables & Lists Features
                </div>
                <div class="interactive-grid">
                    <div class="interactive-item" onclick="showFeatureInfo('table-insert')">
                        <div class="interactive-icon">
                            <i class="fas fa-plus-square"></i>
                        </div>
                        <h4>Insert Tables</h4>
                        <p>Multiple ways to create tables</p>
                    </div>
                    <div class="interactive-item" onclick="showFeatureInfo('table-format')">
                        <div class="interactive-icon">
                            <i class="fas fa-paint-brush"></i>
                        </div>
                        <h4>Format Tables</h4>
                        <p>Styles, borders, and shading</p>
                    </div>
                    <div class="interactive-item" onclick="showFeatureInfo('lists-create')">
                        <div class="interactive-icon">
                            <i class="fas fa-list-ul"></i>
                        </div>
                        <h4>Create Lists</h4>
                        <p>Bulleted and numbered lists</p>
                    </div>
                    <div class="interactive-item" onclick="showFeatureInfo('lists-customize')">
                        <div class="interactive-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <h4>Customize Lists</h4>
                        <p>Custom bullets and numbering</p>
                    </div>
                </div>
            </div>

            <!-- Section 1: Creating Tables -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-plus-circle"></i> 1. Creating Tables
                </div>

                <div class="table-demo-container">
                    <div class="table-demo">
                        <div class="table-demo-header">Insert a Table</div>
                        <div class="table-demo-content">
                            <table class="demo-table">
                                <tr class="header-row">
                                    <th>Method</th>
                                    <th>Steps</th>
                                </tr>
                                <tr>
                                    <td><strong>Quick Insert</strong></td>
                                    <td>Insert → Table → drag to select rows/columns</td>
                                </tr>
                                <tr>
                                    <td><strong>Custom Insert</strong></td>
                                    <td>Insert → Table → Insert Table → specify dimensions</td>
                                </tr>
                                <tr>
                                    <td><strong>Draw Table</strong></td>
                                    <td>Insert → Table → Draw Table (draw borders manually)</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="list-demo">
                        <div class="list-demo-header">Conversion Methods</div>
                        <div style="margin: 15px 0;">
                            <h4 style="color: #0d3d8c; margin-bottom: 10px;">Text to Table</h4>
                            <ul>
                                <li>Select text separated by tabs, commas, or paragraphs</li>
                                <li>Insert → Table → Convert Text to Table</li>
                                <li>Choose separator type</li>
                            </ul>
                        </div>
                        <div style="margin: 15px 0;">
                            <h4 style="color: #0d3d8c; margin-bottom: 10px;">Table to Text</h4>
                            <ul>
                                <li>Select table</li>
                                <li>Table Tools (Layout) → Convert to Text</li>
                                <li>Choose separator (tabs, commas, etc.)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Table Creation Interface"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5UYWJsZSBDcmVhdGlvbiBJbnRlcmZhY2U8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Word Table Creation Interface</div>
                </div>
            </div>

            <!-- Section 2: Modifying Tables -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-edit"></i> 2. Modifying Tables
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-arrows-alt-h"></i> Basic Adjustments</h3>
                    <ul>
                        <li><strong>Resize:</strong> Drag borders, or use AutoFit (Layout tab)</li>
                        <li><strong>Add/Delete Rows/Columns:</strong> Layout tab → Insert/Delete</li>
                        <li><strong>AutoFit Options:</strong>
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>AutoFit Contents: Adjusts to content size</li>
                                <li>AutoFit Window: Fills page width</li>
                                <li>Fixed Column Width: Manual control</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-object-group"></i> Cell Management</h3>
                    <div class="table-demo" style="margin: 15px 0;">
                        <div class="table-demo-header">Sample Table Operations</div>
                        <div class="table-demo-content">
                            <table class="demo-table">
                                <tr class="header-row">
                                    <th colspan="2">Original Cells</th>
                                    <th>Operation</th>
                                    <th>Result</th>
                                </tr>
                                <tr>
                                    <td rowspan="2" class="merged">A1<br>A2</td>
                                    <td>B1</td>
                                    <td>Merge</td>
                                    <td>A1 & A2 become single cell</td>
                                </tr>
                                <tr>
                                    <td>B2</td>
                                    <td>Split</td>
                                    <td>Cell divides into rows/columns</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <ul>
                        <li><strong>Merge Cells:</strong> Combine multiple cells into one (Layout → Merge Cells)</li>
                        <li><strong>Split Cells:</strong> Divide a cell into multiple rows/columns (Layout → Split Cells)</li>
                        <li><strong>Cell Margins & Spacing:</strong> Layout tab → Cell Margins</li>
                        <li><strong>Text Direction:</strong> Change vertical/horizontal text alignment</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sort-alpha-down"></i> Table Sorting</h3>
                    <ul>
                        <li>Select table → Layout tab → Sort</li>
                        <li>Sort by column, type (text, number, date), and order (A-Z or Z-A)</li>
                        <li>Multiple level sorting available</li>
                        <li>Header row exclusion option</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-redo"></i> Repeating Header Rows</h3>
                    <ul>
                        <li>Select header row → Layout tab → Repeat Header Rows</li>
                        <li>Essential for long tables spanning multiple pages</li>
                        <li>Ensures column labels remain visible</li>
                        <li>Automatically repeats on each new page</li>
                    </ul>

                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1542744094-3a31f272c490?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                            alt="Table with Repeating Headers"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5UYWJsZSB3aXRoIFJlcGVhdGluZyBIZWFkZXJzPC90ZXh0Pjwvc3ZnPg=='">
                        <div class="image-caption">Table with Repeating Header Rows</div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Creating and Modifying Lists -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-list-ol"></i> 3. Creating and Modifying Lists
                </div>

                <div class="table-demo-container">
                    <div class="list-demo">
                        <div class="list-demo-header">Bulleted Lists</div>
                        <div style="margin: 15px 0;">
                            <ul>
                                <li>Home tab → Bullets</li>
                                <li>Click arrow for library of bullet styles</li>
                                <li>Types of bullets:
                                    <ul style="margin-top: 5px;">
                                        <li>• Standard bullet points</li>
                                        <li>✓ Checkmarks</li>
                                        <li>→ Arrows</li>
                                        <li>■ Squares</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="list-demo">
                        <div class="list-demo-header">Numbered Lists</div>
                        <div style="margin: 15px 0;">
                            <ol>
                                <li>Home tab → Numbering</li>
                                <li>Choose format:
                                    <ul style="margin-top: 5px;">
                                        <li>1., 2., 3. (decimal)</li>
                                        <li>A., B., C. (uppercase)</li>
                                        <li>i., ii., iii. (roman)</li>
                                        <li>a., b., c. (lowercase)</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Customizing Lists</h3>
                    <ul>
                        <li><strong>Define New Bullet:</strong> Use symbol, picture, or font</li>
                        <li><strong>Define New Number Format:</strong> Choose style, font, and alignment</li>
                        <li><strong>Steps to customize:</strong>
                            <ol style="margin-top: 10px;">
                                <li>Right-click existing list</li>
                                <li>Select "Define New Bullet" or "Define New Number Format"</li>
                                <li>Choose symbol/image or number style</li>
                                <li>Set font and alignment</li>
                                <li>Click OK to apply</li>
                            </ol>
                        </li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sitemap"></i> Multi-Level Lists</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;">
                        <h4 style="color: #0d3d8c; margin-bottom: 10px;">Example Multi-Level List:</h4>
                        <ol style="list-style-type: decimal;">
                            <li>Main Topic 1
                                <ol style="list-style-type: lower-alpha;">
                                    <li>Subtopic 1.1
                                        <ul>
                                            <li>Detail point 1</li>
                                            <li>Detail point 2</li>
                                        </ul>
                                    </li>
                                    <li>Subtopic 1.2</li>
                                </ol>
                            </li>
                            <li>Main Topic 2</li>
                        </ol>
                    </div>
                    <ul>
                        <li>Home tab → Multilevel List</li>
                        <li>Increase indent (Tab) to go down a level</li>
                        <li>Decrease indent (Shift + Tab) to go up a level</li>
                        <li>Different numbering styles for each level</li>
                        <li>Perfect for outlines and hierarchical information</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-calculator"></i> Controlling Numbering</h3>
                    <ul>
                        <li><strong>Restart Numbering:</strong> Right-click list → Restart at 1</li>
                        <li><strong>Continue Numbering:</strong> Right-click list → Continue Numbering</li>
                        <li><strong>Set Numbering Value:</strong> Right-click → Set Numbering Value</li>
                        <li><strong>Start from specific number:</strong> Useful for continuing lists across sections</li>
                        <li><strong>Advance by:</strong> Skip numbers if needed</li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> 4. Essential Shortcuts for Week 3
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
                            <td><span class="shortcut-key">Tab</span> (in a table)</td>
                            <td>Move to next cell</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Tab</span> (in a table)</td>
                            <td>Move to previous cell</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + Up/Down</span></td>
                            <td>Move table row up/down</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + L</span></td>
                            <td>Apply bullet list</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + N</span></td>
                            <td>Apply normal style (remove list)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Tab</span> (in a list)</td>
                            <td>Indent list item (increase level)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Tab</span> (in a list)</td>
                            <td>Outdent list item (decrease level)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + Right/Left</span></td>
                            <td>Increase/Decrease list level</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + T</span></td>
                            <td>Hanging indent</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + T</span></td>
                            <td>Remove hanging indent</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> 5. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Create a Project Plan with Tables and Lists</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Create a New Document titled "Project Kickoff Plan".</li>
                        <li>Insert a Table (4 columns, 5 rows) for a task tracker:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Columns: Task Name, Owner, Due Date, Status.</li>
                                <li>Enter sample data.</li>
                            </ul>
                        </li>
                        <li>Format the Table:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Merge cells in the first row for a title.</li>
                                <li>Apply a table style (Design tab).</li>
                                <li>Set the header row to repeat.</li>
                                <li>Sort tasks alphabetically by "Task Name".</li>
                            </ul>
                        </li>
                        <li>Below the table, create a numbered list for "Project Phases":
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Discovery</li>
                                <li>Planning</li>
                                <li>Execution</li>
                                <li>Review</li>
                            </ul>
                        </li>
                        <li>Create a nested bulleted list under "Planning":
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Budget</li>
                                <li>Timeline</li>
                                <li>Resources</li>
                            </ul>
                        </li>
                        <li>Customize the bullet for "Resources" to a checkmark symbol.</li>
                        <li>Convert the "Project Phases" numbered list to a table.</li>
                        <li>Save as <strong><?php echo htmlspecialchars($studentName); ?>_Week3_ProjectPlan.docx</strong>.</li>
                    </ol>
                </div>

                <!--
<div class="image-container">
                    <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Project Plan Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Qcm9qZWN0IFBsYW4gRXhhbXBsZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Sample Project Plan with Tables and Lists</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadProjectTemplate()">
                    <i class="fas fa-download"></i> Download Project Template
                </a> -->
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 6. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Table Style</strong>
                    <p>Predefined formatting for tables (colors, borders, shading).</p>
                </div>

                <div class="term">
                    <strong>Merge Cells</strong>
                    <p>Combining two or more cells into one.</p>
                </div>

                <div class="term">
                    <strong>Repeating Header Row</strong>
                    <p>Table header that appears on each page automatically.</p>
                </div>

                <div class="term">
                    <strong>Multilevel List</strong>
                    <p>A list with sub-levels (e.g., 1., 1.1, a., i.).</p>
                </div>

                <div class="term">
                    <strong>Numbering Value</strong>
                    <p>The starting number of a list sequence.</p>
                </div>

                <div class="term">
                    <strong>AutoFit</strong>
                    <p>Automatically adjusts table dimensions to content or window.</p>
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
                        <li>How do you convert a list of names separated by commas into a table?</li>
                        <li>What is the purpose of a repeating header row, and how do you enable it?</li>
                        <li>How can you change a round bullet to a square or custom image?</li>
                        <li>What steps would you take to restart numbering at 1 in the middle of a document?</li>
                        <li>How do you sort a table by date in ascending order?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Homework:</strong> Complete the project plan exercise and submit your <strong><?php echo htmlspecialchars($studentName); ?>_Week3_ProjectPlan.docx</strong> file via the class portal by the end of the week. Click <a href="https://portal.impactdigitalacademy.com.ng/modules/shared/course_materials/word/week3_assignment.html" target="_blank"> here for the step-by-step guide.</a>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Plan Table Structure First:</strong> Sketch rows/columns before inserting.</li>
                    <li><strong>Use Table Styles for Consistency:</strong> They're quick and exam-relevant.</li>
                    <li><strong>Master List Levels:</strong> Practice creating outlines with multilevel lists.</li>
                    <li><strong>Check Alignment:</strong> Ensure lists and tables align properly in Print Preview.</li>
                    <li><strong>Use Keyboard Shortcuts:</strong> Save time with Tab/Shift+Tab navigation.</li>
                    <li><strong>Test on Different Devices:</strong> Verify tables display correctly on mobile.</li>
                    <li><strong>Backup Custom Formats:</strong> Save custom bullet/table styles as templates.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word" target="_blank">Word Tables Training</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-bulleted-or-numbered-list" target="_blank">Create Lists in Word</a></li>
                    <li><a href="https://support.microsoft.com/office/format-a-table" target="_blank">Format Tables Guide</a></li>
                    <li><strong>Practice files and video demos</strong> available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p>In Week 4, we'll explore Graphic Elements. You'll learn to:</p>
                <ul>
                    <li>Insert and format pictures and shapes</li>
                    <li>Create SmartArt diagrams</li>
                    <li>Add and customize text boxes</li>
                    <li>Wrap text around objects</li>
                    <li>Align and group graphic elements</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring any images or logos you'd like to use in a document!</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week3.php">Week 3 Discussion</a></li>
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
            <p>MO-100: Microsoft Word Certification Prep Program – Week 3 Handout</p>
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
        function downloadProjectTemplate() {
            alert('Project template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/templates/project_template.docx';
        }

        // Interactive feature info
        function showFeatureInfo(feature) {
            const featureInfo = {
                'table-insert': 'To insert a table: Go to Insert tab → Table → choose size. You can also draw a table or use Quick Tables.',
                'table-format': 'Format tables with: Design tab for styles, Layout tab for structure. Use borders, shading, and alignment options.',
                'lists-create': 'Create lists from Home tab: Bullets for unordered lists, Numbering for ordered lists, or Multilevel for outlines.',
                'lists-customize': 'Customize lists: Right-click → Define New Bullet/Number Format. Use symbols, pictures, or custom fonts.'
            };
            
            alert(featureInfo[feature] || 'Feature information not available.');
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
                console.log('Word Week 3 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Select the comma-separated text → Insert tab → Table → Convert Text to Table → choose 'Commas' as separator.",
                    "2. Repeating header rows ensure table headers appear on each page for long tables. Enable: Select header row → Layout tab → Repeat Header Rows.",
                    "3. Right-click the bullet list → Bullets → Define New Bullet → choose Symbol or Picture → select square or custom image.",
                    "4. Right-click the numbered list → Restart at 1, or Set Numbering Value → Start new list → Set value to 1.",
                    "5. Select the table → Layout tab → Sort → choose date column → Type: Date → Order: Ascending."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive demo enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const interactiveItems = document.querySelectorAll('.interactive-item');
            interactiveItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
        });

        // Table demo interaction
        function demonstrateTableOperation(operation) {
            const demoTable = document.querySelector('.table-demo-content table');
            if (demoTable) {
                alert(`Demonstrating ${operation}. This would show a visual demonstration of the table operation.`);
            }
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
    $viewer = new WordWeek3HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>