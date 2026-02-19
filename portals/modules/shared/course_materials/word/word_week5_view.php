<?php
// modules/shared/course_materials/MSWord/word_week5_view.php

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
 * Word Week 5 Handout Viewer Class with PDF Download
 */
class WordWeek5HandoutViewer
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
            $mpdf->SetTitle('Week 5: Creating and Managing References');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, References, Citations, Table of Contents, Bibliography');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'Word_Week5_References_' . date('Y-m-d') . '.pdf';
            
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
                Week 5: Creating and Managing References
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 5!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we focus on a critical skill for academic, technical, and professional documents: References. You'll learn how to add footnotes, endnotes, citations, and bibliographies to your work. Additionally, you'll master creating and customizing a table of contents. These features not only enhance the credibility and organization of your documents but are also essential for the MO-100 exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Insert and modify footnotes and endnotes.</li>
                    <li>Create and manage citation sources.</li>
                    <li>Insert citations and generate a bibliography.</li>
                    <li>Insert and customize a table of contents (TOC).</li>
                    <li>Understand the difference between reference elements and manage them effectively.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Footnotes and Endnotes</h3>
                <p><strong>What's the Difference?</strong></p>
                <ul>
                    <li><strong>Footnotes:</strong> Appear at the bottom of the page.</li>
                    <li><strong>Endnotes:</strong> Appear at the end of the document or section.</li>
                </ul>
                
                <p><strong>Inserting Notes:</strong></p>
                <ul>
                    <li>References tab → Insert Footnote (Ctrl + Alt + F) or Insert Endnote (Ctrl + Alt + D).</li>
                </ul>
                
                <p><strong>Modifying Notes:</strong></p>
                <ul>
                    <li>Right-click note number → Note Options to change format, numbering, or placement.</li>
                    <li>Convert footnotes to endnotes (and vice versa) via the Footnote & Endnote dialog box.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Citations and Bibliography</h3>
                <p><strong>Creating a Source:</strong></p>
                <ul>
                    <li>References tab → Manage Sources → New.</li>
                    <li>Enter details: Author, Title, Year, Publisher, etc.</li>
                    <li>Choose Source Type (Book, Journal Article, Website, etc.).</li>
                </ul>
                
                <p><strong>Inserting a Citation:</strong></p>
                <ul>
                    <li>Place cursor where citation should appear.</li>
                    <li>References tab → Insert Citation → choose source.</li>
                </ul>
                
                <p><strong>Editing a Source:</strong></p>
                <ul>
                    <li>Manage Sources → select source → Edit.</li>
                </ul>
                
                <p><strong>Generating a Bibliography:</strong></p>
                <ul>
                    <li>References tab → Bibliography → choose a built-in style or insert a blank one.</li>
                    <li>Updates automatically when sources are added or changed.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Table of Contents (TOC)</h3>
                <p><strong>Inserting a TOC:</strong></p>
                <ul>
                    <li>References tab → Table of Contents → choose Automatic or Custom.</li>
                    <li>Automatic TOC pulls from Heading styles (Heading 1, Heading 2, etc.).</li>
                </ul>
                
                <p><strong>Customizing a TOC:</strong></p>
                <ul>
                    <li>References → Table of Contents → Custom Table of Contents.</li>
                    <li>Adjust levels, formatting, leader lines, and page number alignment.</li>
                </ul>
                
                <p><strong>Updating a TOC:</strong></p>
                <ul>
                    <li>Click the TOC → Update Table (or References → Update Table).</li>
                    <li>Choose to update page numbers only or entire table.</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">4. Managing Reference Elements</h3>
                <ul>
                    <li><strong>Use Heading Styles:</strong> TOC depends on consistent use of Heading 1, 2, 3.</li>
                    <li><strong>Placeholders:</strong> You can insert citations before creating sources using placeholder text.</li>
                    <li><strong>Citation Styles:</strong> Choose from APA, MLA, Chicago, etc. via References → Style.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Create a Research Summary with Full References</strong></p>
                <p>Follow these steps to build a professionally referenced document:</p>
                <ol>
                    <li>Open a new document titled "The Impact of Renewable Energy".</li>
                    <li>Apply Heading 1 to the title.</li>
                    <li>Add three headings (Heading 2):</li>
                    <ul>
                        <li>Introduction</li>
                        <li>Current Technologies</li>
                        <li>Future Outlook</li>
                    </ul>
                    <li>Insert a footnote in the Introduction explaining a key term.</li>
                    <li>Create two citation sources:</li>
                    <ul>
                        <li>A book: Author = "Green, T.", Title = "Solar Futures", Year = "2022".</li>
                        <li>A website: Author = "Energy Institute", Title = "Wind Power Report", Year = "2023".</li>
                    </ul>
                    <li>Insert citations in the "Current Technologies" section for both sources.</li>
                    <li>Generate a Bibliography at the end using the APA style.</li>
                    <li>Insert a Table of Contents at the beginning of the document.</li>
                    <li>Update the TOC after making changes.</li>
                    <li>Save as YourName_Week5_Research.docx.</li>
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
                            <td style="padding: 6px 8px;">Ctrl + Alt + F</td>
                            <td style="padding: 6px 8px;">Insert Footnote</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Alt + D</td>
                            <td style="padding: 6px 8px;">Insert Endnote</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + Shift + I</td>
                            <td style="padding: 6px 8px;">Mark citation (for Index)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F9</td>
                            <td style="padding: 6px 8px;">Update selected field (e.g., TOC)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Click on TOC entry</td>
                            <td style="padding: 6px 8px;">Jump to that section</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Alt + Shift + X</td>
                            <td style="padding: 6px 8px;">Mark index entry</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Citation:</strong> A reference to a source within the text.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Bibliography:</strong> A list of all sources cited in the document, placed at the end.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Footnote/Endnote:</strong> Additional information or reference placed at page end (footnote) or document end (endnote).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Source Manager:</strong> Tool to add, edit, and manage citation sources.</p>
                </div>
                <div>
                    <p><strong>Table of Contents (TOC):</strong> A navigational list of document headings with page numbers.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>What is the main difference between a footnote and an endnote?</li>
                    <li>How do you change the citation style from MLA to APA?</li>
                    <li>What must you apply to document headings for an automatic TOC to work?</li>
                    <li>How do you update a Table of Contents after adding new headings?</li>
                    <li>Where do you edit the details of an existing citation source?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Insert footnotes and endnotes</li>
                    <li>Modify footnote and endnote properties</li>
                    <li>Create and modify bibliography citation sources</li>
                    <li>Insert citations for bibliographies</li>
                    <li>Insert tables of contents</li>
                    <li>Customize tables of contents</li>
                    <li>Insert bibliographies</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Use Styles Consistently:</strong> TOC relies on Heading styles—apply them correctly.</li>
                    <li><strong>Manage Sources Early:</strong> Enter all sources in the Source Manager before inserting citations.</li>
                    <li><strong>Update Fields:</strong> Remember to update TOC and bibliography after edits (F9 is your friend).</li>
                    <li><strong>Check Formatting:</strong> Different citation styles have different rules—verify with your institution.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 6, we'll explore Document Collaboration. You'll learn to use comments, track changes, and manage edits from multiple reviewers—key skills for team projects and professional editing workflows.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Word References and Citations Help</li>
                    <li>Insert a Table of Contents Guide</li>
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
                Week 5 Handout: Creating and Managing References
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
            Week 5: References & Citations | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 5: Creating and Managing References - Impact Digital Academy</title>
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

        /* Reference Element Demos */
        .reference-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .reference-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .reference-icon {
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

            .reference-demo {
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
                <strong>Access Granted:</strong> Word Week 5 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week4_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 4
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 5 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Creating and Managing References</div>
            <div class="week-tag">Week 5 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Welcome to Week 5!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we focus on a critical skill for academic, technical, and professional documents: References. You'll learn how to add footnotes, endnotes, citations, and bibliographies to your work. Additionally, you'll master creating and customizing a table of contents. These features not only enhance the credibility and organization of your documents but are also essential for the MO-100 exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1497636577773-f1231844b336?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Academic References and Citations"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+UmVmZXJlbmNlcyBhbmQgQ2l0YXRpb25zPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Professional Document References and Citations</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Insert and modify footnotes and endnotes.</li>
                    <li>Create and manage citation sources.</li>
                    <li>Insert citations and generate a bibliography.</li>
                    <li>Insert and customize a table of contents (TOC).</li>
                    <li>Understand the difference between reference elements and manage them effectively.</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Insert footnotes and endnotes</li>
                        <li>Modify footnote and endnote properties</li>
                        <li>Create and modify bibliography citation sources</li>
                        <li>Insert citations for bibliographies</li>
                    </ul>
                    <ul>
                        <li>Insert tables of contents</li>
                        <li>Customize tables of contents</li>
                        <li>Insert bibliographies</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Footnotes and Endnotes -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-sticky-note"></i> 1. Footnotes and Endnotes
                </div>

                <div class="reference-demo">
                    <div class="reference-item">
                        <div class="reference-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4>Footnotes</h4>
                        <p>Appear at the bottom of the page</p>
                        <p style="margin-top: 10px;"><strong>Shortcut:</strong> Ctrl + Alt + F</p>
                    </div>
                    <div class="reference-item">
                        <div class="reference-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <h4>Endnotes</h4>
                        <p>Appear at the end of document</p>
                        <p style="margin-top: 10px;"><strong>Shortcut:</strong> Ctrl + Alt + D</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> How to Insert Notes</h3>
                    <ol>
                        <li>Place cursor where you want the note reference</li>
                        <li>Go to <strong>References tab</strong></li>
                        <li>Click <strong>Insert Footnote</strong> or <strong>Insert Endnote</strong></li>
                        <li>Type your note text at the bottom of page or end of document</li>
                    </ol>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sliders-h"></i> Modifying Notes</h3>
                    <ul>
                        <li><strong>Right-click note number → Note Options</strong> to change format</li>
                        <li>Convert between footnotes and endnotes via dialog box</li>
                        <li>Change numbering style (1, 2, 3 or i, ii, iii)</li>
                        <li>Set restart numbering options</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Citations and Bibliography -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-quote-right"></i> 2. Citations and Bibliography
                </div>

                <div class="reference-demo">
                    <div class="reference-item">
                        <div class="reference-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <h4>Create Source</h4>
                        <p>References → Manage Sources → New</p>
                    </div>
                    <div class="reference-item">
                        <div class="reference-icon">
                            <i class="fas fa-pencil-alt"></i>
                        </div>
                        <h4>Insert Citation</h4>
                        <p>References → Insert Citation → Choose source</p>
                    </div>
                    <div class="reference-item">
                        <div class="reference-icon">
                            <i class="fas fa-list"></i>
                        </div>
                        <h4>Bibliography</h4>
                        <p>References → Bibliography → Choose style</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-database"></i> Source Manager</h3>
                    <ul>
                        <li>Store all your citation sources in one place</li>
                        <li>Add sources for books, articles, websites, etc.</li>
                        <li>Edit existing sources anytime</li>
                        <li>Sources can be used across multiple documents</li>
                    </ul>

                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1450101499163-c8848c66ca85?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                            alt="Source Manager Interface"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Tb3VyY2UgTWFuYWdlciBJbnRlcmZhY2U8L3RleHQ+PC9zdmc+'">
                        <div class="image-caption">Managing Citation Sources in Word</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-bookmark"></i> Citation Styles</h3>
                    <table class="demo-table">
                        <thead>
                            <tr class="header-row">
                                <th>Style</th>
                                <th>Common Use</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>APA</strong></td>
                                <td>Psychology, Education, Social Sciences</td>
                                <td>Smith, J. (2023). Title. Publisher.</td>
                            </tr>
                            <tr>
                                <td><strong>MLA</strong></td>
                                <td>Humanities, Literature</td>
                                <td>Smith, John. Title. Publisher, 2023.</td>
                            </tr>
                            <tr>
                                <td><strong>Chicago</strong></td>
                                <td>History, Business, Fine Arts</td>
                                <td>Smith, John. 2023. Title. Publisher.</td>
                            </tr>
                            <tr>
                                <td><strong>Harvard</strong></td>
                                <td>Various disciplines</td>
                                <td>Smith, J. (2023) Title. Publisher.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section 3: Table of Contents -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-list-ol"></i> 3. Table of Contents (TOC)
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-magic"></i> Automatic TOC</h3>
                    <ul>
                        <li>Based on <strong>Heading styles</strong> (Heading 1, Heading 2, Heading 3)</li>
                        <li>References tab → Table of Contents → Choose Automatic style</li>
                        <li>Automatically updates page numbers and headings</li>
                        <li>Click entries to navigate through document</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-cogs"></i> Custom TOC</h3>
                    <ul>
                        <li>References → Table of Contents → Custom Table of Contents</li>
                        <li>Choose which heading levels to include</li>
                        <li>Customize formatting and leader lines</li>
                        <li>Show/hide page numbers</li>
                        <li>Set right-aligned page numbers</li>
                    </ul>

                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1531973576160-7125cd663d86?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                            alt="Table of Contents Example"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5UYWJsZSBvZiBDb250ZW50cyBFeGFtcGxlPC90ZXh0Pjwvc3ZnPg=='">
                        <div class="image-caption">Professional Table of Contents</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-sync-alt"></i> Updating TOC</h3>
                    <ul>
                        <li>Click anywhere in the TOC</li>
                        <li>Press <strong>F9</strong> or click <strong>Update Table</strong></li>
                        <li>Choose: Update page numbers only OR Update entire table</li>
                        <li>Always update after adding/changing headings</li>
                    </ul>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bolt"></i> 4. Essential Shortcuts for Week 5
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
                            <td><span class="shortcut-key">Ctrl + Alt + F</span></td>
                            <td>Insert Footnote</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Alt + D</span></td>
                            <td>Insert Endnote</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + I</span></td>
                            <td>Mark citation (for Index)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">F9</span></td>
                            <td>Update selected field (e.g., TOC)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Click on TOC entry</span></td>
                            <td>Jump to that section</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + X</span></td>
                            <td>Mark index entry</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + O</span></td>
                            <td>Mark table of contents entry</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + Shift + I</span></td>
                            <td>Mark citation</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> 5. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Create a Research Summary with Full References</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open a new document titled "The Impact of Renewable Energy".</li>
                        <li>Apply Heading 1 to the title.</li>
                        <li>Add three headings (Heading 2):
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Introduction</li>
                                <li>Current Technologies</li>
                                <li>Future Outlook</li>
                            </ul>
                        </li>
                        <li>Insert a footnote in the Introduction explaining a key term.</li>
                        <li>Create two citation sources:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>A book: Author = "Green, T.", Title = "Solar Futures", Year = "2022".</li>
                                <li>A website: Author = "Energy Institute", Title = "Wind Power Report", Year = "2023".</li>
                            </ul>
                        </li>
                        <li>Insert citations in the "Current Technologies" section for both sources.</li>
                        <li>Generate a Bibliography at the end using the APA style.</li>
                        <li>Insert a Table of Contents at the beginning of the document.</li>
                        <li>Update the TOC after making changes.</li>
                        <li>Save as <strong><?php echo htmlspecialchars($studentName); ?>_Week5_Research.docx</strong>.</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1542744095-fcf48d80b0fd?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Research Document Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5SZXNlYXJjaCBEb2N1bWVudCBFeGFtcGxlPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Sample Research Document with References</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download Research Template
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> 6. Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Citation</strong>
                    <p>A reference to a source within the text.</p>
                </div>

                <div class="term">
                    <strong>Bibliography</strong>
                    <p>A list of all sources cited in the document, placed at the end.</p>
                </div>

                <div class="term">
                    <strong>Footnote/Endnote</strong>
                    <p>Additional information or reference placed at page end (footnote) or document end (endnote).</p>
                </div>

                <div class="term">
                    <strong>Source Manager</strong>
                    <p>Tool to add, edit, and manage citation sources.</p>
                </div>

                <div class="term">
                    <strong>Table of Contents (TOC)</strong>
                    <p>A navigational list of document headings with page numbers.</p>
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
                        <li>What is the main difference between a footnote and an endnote?</li>
                        <li>How do you change the citation style from MLA to APA?</li>
                        <li>What must you apply to document headings for an automatic TOC to work?</li>
                        <li>How do you update a Table of Contents after adding new headings?</li>
                        <li>Where do you edit the details of an existing citation source?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Homework:</strong> Complete the research document exercise and submit your <strong><?php echo htmlspecialchars($studentName); ?>_Week5_Research.docx</strong> file via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 8. Tips for Success
                </div>
                <ul>
                    <li><strong>Use Styles Consistently:</strong> TOC relies on Heading styles—apply them correctly.</li>
                    <li><strong>Manage Sources Early:</strong> Enter all sources in the Source Manager before inserting citations.</li>
                    <li><strong>Update Fields:</strong> Remember to update TOC and bibliography after edits (F9 is your friend).</li>
                    <li><strong>Check Formatting:</strong> Different citation styles have different rules—verify with your institution.</li>
                    <li><strong>Use Placeholders:</strong> Insert citations as placeholders and fill details later.</li>
                    <li><strong>Backup Your Sources:</strong> Export your source list for use in other documents.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word" target="_blank">Word References and Citations Help</a></li>
                    <li><a href="https://support.microsoft.com/office/create-a-table-of-contents" target="_blank">Insert a Table of Contents Guide</a></li>
                    <li><strong>Practice files and tutorial videos</strong> available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 9. Next Week Preview
                </div>
                <p>In Week 6, we'll explore Document Collaboration. You'll learn to:</p>
                <ul>
                    <li>Insert and manage comments</li>
                    <li>Track changes and review edits</li>
                    <li>Accept or reject changes</li>
                    <li>Compare different document versions</li>
                    <li>Protect documents with passwords</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Think about a document you might collaborate on with others (report, proposal, etc.).</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week5.php">Week 5 Discussion</a></li>
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
            <p>MO-100: Microsoft Word Certification Prep Program – Week 5 Handout</p>
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
            alert('Research template would download. This is a demo.');
            // In production, this would link to a template file
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/templates/research_template.docx';
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
                console.log('Word Week 5 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Footnotes appear at the bottom of the page, while endnotes appear at the end of the document or section.",
                    "2. Go to References tab → Style dropdown → Choose APA from the list of citation styles.",
                    "3. You must apply Heading styles (Heading 1, Heading 2, etc.) to document headings for an automatic TOC to work properly.",
                    "4. Click on the TOC and press F9, or right-click and select Update Field, then choose 'Update entire table'.",
                    "5. Go to References tab → Manage Sources → select the source you want to edit → click Edit button."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive reference element demonstration
        document.addEventListener('DOMContentLoaded', function() {
            const referenceItems = document.querySelectorAll('.reference-item');
            referenceItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    alert(`You clicked on ${title}. To use: Go to References tab → find ${title} options`);
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
    $viewer = new WordWeek5HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>