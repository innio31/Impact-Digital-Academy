<?php
// modules/shared/course_materials/MSPowerPoint/powerpoint_week2_view.php

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
 * PowerPoint Week 2 Handout Viewer Class with PDF Download
 */
class PowerPointWeek2HandoutViewer
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
            $mpdf->SetTitle('Week 2: Building and Structuring Complex Presentations');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-300 PowerPoint Certification Preparation');
            
            // Set metadata
            $mpdf->SetKeywords('Microsoft PowerPoint, MO-300, Import, Reuse Slides, Sections, Presentation Structure');
            
            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            // Write main content
            $mpdf->WriteHTML($htmlContent);
            
            // Output PDF
            $filename = 'PowerPoint_Week2_Building_Structuring_' . date('Y-m-d') . '.pdf';
            
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
                <button onclick="window.print()" style="padding: 10px 20px; background: #1976d2; color: white; border: none; border-radius: 5px; cursor: pointer;">
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
            <h1 style="color: #1976d2; border-bottom: 2px solid #1976d2; padding-bottom: 10px; font-size: 18pt;">
                Week 2: Building and Structuring Complex Presentations
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #1565c0; font-size: 16pt;">Welcome to Week 2!</h2>
                <p style="margin-bottom: 15px;">
                    Building on your foundational skills from Week 1, we now focus on efficient construction. A professional presentation is rarely built from scratch in isolation. This week, you'll learn how to leverage existing content, collaborate effectively, and manage large presentations with sophisticated structuring tools. By mastering the art of importing, reusing, and organizing with Sections, you'll transform from a slide creator into a presentation architect, capable of building substantial, well-organized decks with speed and precision.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Import a structured outline from Microsoft Word to create a presentation skeleton rapidly</li>
                    <li>Reuse and insert slides from other presentations while maintaining or changing their original design</li>
                    <li>Implement Sections to logically group, manage, and navigate slides within a large presentation</li>
                    <li>Apply advanced slide management techniques, including slide duplication, repositioning, and efficient thumbnail navigation</li>
                    <li>Differentiate between merging, reusing, and embedding content from external sources</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #1565c0; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #1976d2; font-size: 14pt;">1. Building from an Outline: Importing from Word</h3>
                <ul>
                    <li><strong>The Power of Outline View:</strong> PowerPoint can generate slides directly from a Word document's heading structure.</li>
                    <li><strong>How It Works:</strong>
                        <ul>
                            <li>Create your document in Word using <strong>Heading 1</strong> for slide titles</li>
                            <li>Use <strong>Heading 2</strong> (or bulleted text under Heading 1) for slide content</li>
                            <li>In PowerPoint: <strong>Home tab → New Slide dropdown → Slides from Outline...</strong></li>
                        </ul>
                    </li>
                    <li><strong>Best Practice:</strong> This method separates content creation from design work, allowing for better focus and collaboration. The imported slides will adopt the design of the current PowerPoint template.</li>
                </ul>
                
                <h3 style="color: #1976d2; margin-top: 20px; font-size: 14pt;">2. Reusing Content from Other Presentations</h3>
                <ul>
                    <li><strong>The Reuse Slides Pane (Home → New Slide → Reuse Slides...):</strong>
                        <ul>
                            <li>Opens a task pane to browse and select slides from any other PowerPoint file</li>
                            <li><strong>Critical Option: "Keep Source Formatting"</strong> (checkbox at bottom of pane)</li>
                            <li><strong>Checked:</strong> The inserted slide retains its original design/theme</li>
                            <li><strong>Unchecked:</strong> The slide adopts the design/theme of the current presentation</li>
                        </ul>
                    </li>
                    <li><strong>Methods of Insertion:</strong>
                        <ul>
                            <li>Click a slide to insert it at the current cursor position</li>
                            <li>Right-click a slide for options to insert all slides or apply the theme from the source presentation</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #1976d2; margin-top: 20px; font-size: 14pt;">3. Core Slide Management Deep Dive</h3>
                <ul>
                    <li><strong>Creating Slides:</strong> Beyond Ctrl+M, use the <strong>Layout Gallery (Home → New Slide dropdown)</strong> to choose the correct layout upfront</li>
                    <li><strong>Duplicating Slides:</strong>
                        <ul>
                            <li><strong>Method 1:</strong> Right-click slide in thumbnail pane → Duplicate Slide</li>
                            <li><strong>Method 2:</strong> Select slide(s) → Ctrl+D</li>
                            <li><strong>Why Duplicate?</strong> It's faster than copy-paste and perfectly preserves all formatting, animations, and content</li>
                        </ul>
                    </li>
                    <li><strong>Rearranging Slides:</strong> Drag-and-drop in Normal View's thumbnail pane or Slide Sorter View for broader reorganization</li>
                    <li><strong>Hiding Slides:</strong>
                        <ul>
                            <li>Right-click a slide → Hide Slide</li>
                            <li>The slide remains in the file but is skipped during a normal slide show</li>
                            <li>Useful for creating an appendix or tailoring content for different audiences</li>
                        </ul>
                    </li>
                </ul>
                
                <h3 style="color: #1976d2; margin-top: 20px; font-size: 14pt;">4. Structuring with Sections (The Professional's Tool)</h3>
                <ul>
                    <li><strong>Purpose:</strong> Sections act like folders or chapters for your slides, enabling you to collapse, expand, move, and name logical groups</li>
                    <li><strong>Creating a Section:</strong>
                        <ul>
                            <li>Right-click between slides where you want the section to start</li>
                            <li>Select <strong>Add Section</strong></li>
                            <li>A default "Untitled Section" is created</li>
                        </ul>
                    </li>
                    <li><strong>Managing Sections:</strong>
                        <ul>
                            <li><strong>Rename:</strong> Right-click the section header → Rename Section</li>
                            <li><strong>Move:</strong> Drag the entire section header to reorder all slides within that section</li>
                            <li><strong>Collapse/Expand:</strong> Click the triangle icon next to the section name</li>
                            <li><strong>Remove/Delete:</strong> Right-click section header → Remove Section (keeps slides) or Delete Section & Slides</li>
                        </ul>
                    </li>
                    <li><strong>Real-World Use Cases:</strong> Grouping by agenda item, presenter, chapter (Introduction, Findings, Conclusion), or status (Complete, In Progress)</li>
                </ul>
                
                <h3 style="color: #1976d2; margin-top: 20px; font-size: 14pt;">5. Comparing Methods</h3>
                <ul>
                    <li><strong>Import Outline (from Word):</strong> Best for starting from a text-based plan. Creates new slides based on hierarchy.</li>
                    <li><strong>Reuse Slides Pane:</strong> Best for selective borrowing from other decks with control over design adoption.</li>
                    <li><strong>Copy/Paste:</strong> Simple but can cause formatting conflicts. Use for small, simple elements within slides, not whole slides.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8eaf6; padding: 15px; border-left: 5px solid #3f51b5; margin-bottom: 25px;">
                <h3 style="color: #303f9f; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise: Assemble a Conference Pitch Presentation</h3>
                <p><strong>Follow these steps to build a complex, well-structured deck:</strong></p>
                <ol>
                    <li><strong>Create Foundation with an Outline:</strong>
                        <ul>
                            <li>Open a new, blank PowerPoint presentation</li>
                            <li>Apply the "Ion" design theme (Design tab)</li>
                            <li>Use <strong>Home → New Slide → Slides from Outline...</strong> to import the provided Conference_Outline.docx</li>
                            <li>Observe how Heading 1 became slide titles</li>
                        </ul>
                    </li>
                    <li><strong>Reuse and Integrate Existing Slides:</strong>
                        <ul>
                            <li>Navigate to your last slide</li>
                            <li>Open the <strong>Reuse Slides pane (Home → New Slide → Reuse Slides...)</strong></li>
                            <li>Browse and open the provided Past_Conference_Highlights.pptx file</li>
                            <li>With <strong>"Keep Source Formatting" UNCHECKED</strong>, insert the "Audience Demographics" slide</li>
                            <li>With <strong>"Keep Source Formatting" CHECKED</strong>, insert the "Previous Success Metrics" slide</li>
                        </ul>
                    </li>
                    <li><strong>Implement Sections for Organization:</strong>
                        <ul>
                            <li>In the slide thumbnail pane, right-click before Slide 1 and select <strong>Add Section</strong></li>
                            <li>Rename it to <strong>"00_Cover"</strong></li>
                            <li>Add a second section before Slide 2 (the first content slide)</li>
                            <li>Rename it to <strong>"01_Problem_Statement"</strong></li>
                            <li>Add sections for <strong>"02_Our_Solution"</strong>, <strong>"03_Proof_&_Metrics"</strong>, and <strong>"04_Next_Steps"</strong></li>
                            <li>Collapse the "03_Proof_&_Metrics" section</li>
                            <li>Drag the "04_Next_Steps" section above it</li>
                        </ul>
                    </li>
                    <li><strong>Apply Efficient Slide Management:</strong>
                        <ul>
                            <li>In the "02_Our_Solution" section, select the first slide and press <strong>Ctrl+D twice</strong> to duplicate it</li>
                            <li>Hide the last slide in the "03_Proof_&_Metrics" section (right-click → Hide Slide)</li>
                            <li>Switch to <strong>Slide Sorter View (View → Slide Sorter)</strong> to see your entire structured presentation</li>
                            <li>Reorder one slide by dragging</li>
                        </ul>
                    </li>
                    <li><strong>Finalize and Review:</strong>
                        <ul>
                            <li>Switch back to Normal View</li>
                            <li>Collapse and expand sections to navigate your presentation quickly</li>
                            <li>Start the slide show (F5) and confirm the hidden slide is skipped</li>
                            <li>Save your work as <strong>YourName_Conference_Pitch_WK2.pptx</strong></li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <!-- Shortcuts -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #1565c0; font-size: 14pt;">Keyboard Shortcuts Cheat Sheet (Week 2 Focus)</h3>
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 11pt;">
                    <thead>
                        <tr style="background-color: #1976d2; color: white;">
                            <th style="padding: 8px; text-align: left; width: 40%;">Shortcut</th>
                            <th style="padding: 8px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + D</td>
                            <td style="padding: 6px 8px;">Duplicate selected slide(s)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + M</td>
                            <td style="padding: 6px 8px;">Insert a new slide (with default layout)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → I</td>
                            <td style="padding: 6px 8px;">Open the New Slide Layout gallery</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → S then O</td>
                            <td style="padding: 6px 8px;">Slides from Outline...</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt → H → S then U</td>
                            <td style="padding: 6px 8px;">Reuse Slides... (open pane)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">F5</td>
                            <td style="padding: 6px 8px;">Start slideshow from beginning</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Shift + F5</td>
                            <td style="padding: 6px 8px;">Start slideshow from current slide</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Right-click between slides</td>
                            <td style="padding: 6px 8px;">Quick menu to Add Section</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #555; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Sections:</strong> An organizational tool that groups slides into named, collapsible categories within a presentation.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Reuse Slides Pane:</strong> A dedicated task pane for browsing and selectively inserting slides from other presentation files.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Keep Source Formatting:</strong> A critical option when reusing slides that determines whether the original design is preserved.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Hide Slide:</strong> A property applied to a slide that excludes it from the normal slide show sequence while keeping it in the file.</p>
                </div>
                <div>
                    <p><strong>Slide Sorter View:</strong> The optimal view for visually managing the sequence and sections of an entire presentation.</p>
                </div>
            </div>
            
            <!-- Self-Review Questions -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>You have a well-structured report in Word. What is the most efficient method to turn it into the initial slides of a PowerPoint presentation, and what Word formatting must you use?</li>
                    <li>When using the Reuse Slides pane, what is the key functional difference between leaving "Keep Source Formatting" checked versus unchecked?</li>
                    <li>Describe two specific benefits of using Sections in a presentation with over 30 slides.</li>
                    <li>What is the keyboard shortcut for duplicating a slide, and why is it often better than using Copy (Ctrl+C) and Paste (Ctrl+V)?</li>
                    <li>You need to include a detailed appendix in your presentation for Q&A but don't want it shown in the main talk. What feature do you use?</li>
                </ol>
            </div>
            
            <!-- Tips for Success -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Storyboard with Sections:</strong> Before designing a single slide, use Sections in a blank deck to map out your presentation's narrative flow (Intro, Problem, Solution, Proof, Call-to-Action).</li>
                    <li><strong>Maintain Design Control:</strong> When collaborating, decide on a "master" template first. When reusing slides, typically uncheck "Keep Source Formatting" to ensure visual consistency.</li>
                    <li><strong>Use Hiding Judiciously:</strong> Hidden slides are perfect for detailed backup data, regional-specific content, or answers to anticipated questions.</li>
                    <li><strong>Leverage Slide Sorter:</strong> Make Slide Sorter (Alt → W → I) your default view when restructuring or applying sections. It provides the essential "big picture."</li>
                    <li><strong>Organize Files:</strong> Keep source Word documents and reusable slides in clearly named folders for quick access.</li>
                    <li><strong>Test Hidden Slides:</strong> Always test that hidden slides are truly hidden during slide show but accessible when needed.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p><strong>Week 3: Visual Storytelling with Graphics & Media</strong></p>
                <p>In Week 3, we'll bring your structured presentations to life! You'll master the insertion and formatting of images, icons, and 3D models. We'll dive deep into SmartArt graphics for conceptual diagrams and explore how to effectively embed and format video and audio. Prepare to elevate your slides from structured documents to compelling visual stories!</p>
                <p style="margin-top: 10px;"><strong>Preparation:</strong> Gather images, icons, and any media files you'd like to incorporate into a presentation.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #1565c0; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Word to PowerPoint Import Guide</li>
                    <li>Collaborative Presentation Best Practices</li>
                    <li>Sections and Slide Management Tutorial Videos</li>
                    <li>Practice files and templates available in the Course Portal</li>
                    <li>Reusable Slide Library Templates</li>
                </ul>
            </div>
            
            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #1976d2; margin-bottom: 10px;">Instructor Information</h4>
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
            <h1 style="color: #1976d2; font-size: 24pt; margin-bottom: 20px;">
                Impact Digital Academy
            </h1>
            <h2 style="color: #1565c0; font-size: 18pt; margin-bottom: 30px;">
                Microsoft PowerPoint (MO-300) Exam Preparation Program
            </h2>
            <h3 style="color: #333; font-size: 20pt; border-top: 3px solid #1976d2; 
                border-bottom: 3px solid #1976d2; padding: 20px 0; margin: 30px 0;">
                Week 2 Handout: Building and Structuring Complex Presentations
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
            Week 2: Building and Structuring Complex Presentations | Impact Digital Academy | ' . date('m/d/Y') . '
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
    <title>Week 2: Building and Structuring Complex Presentations - Impact Digital Academy</title>
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
            background: linear-gradient(135deg, #1565c0 0%, #1976d2 100%);
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
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
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
            color: #1976d2;
            font-size: 1.6rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #1976d2;
        }

        .subsection {
            margin-bottom: 25px;
        }

        .subsection h3 {
            color: #1565c0;
            margin-bottom: 15px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subsection h3 i {
            color: #1976d2;
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
            background-color: #1976d2;
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
            background: #1976d2;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
        }

        .exercise-box {
            background: #e8eaf6;
            border-left: 5px solid #3f51b5;
            padding: 25px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .exercise-title {
            color: #303f9f;
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
            background: #e3f2fd;
            border-left: 5px solid #1976d2;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }

        .help-title {
            color: #1976d2;
            font-size: 1.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            display: inline-block;
            background: #1976d2;
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
            background: #1565c0;
        }

        .learning-objectives {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .learning-objectives h3 {
            color: #1976d2;
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
            color: #1976d2;
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

        .method-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .method-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }

        .method-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .method-icon {
            font-size: 3rem;
            color: #1976d2;
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

        /* Comparison Table */
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .comparison-table th {
            background-color: #1976d2;
            color: white;
            padding: 15px;
            text-align: center;
        }

        .comparison-table td {
            padding: 15px;
            border: 1px solid #e0e0e0;
            text-align: center;
        }

        .comparison-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .comparison-table .best-for {
            font-style: italic;
            color: #666;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        /* Import Methods */
        .import-methods {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .import-method {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s;
        }

        .import-method:hover {
            border-color: #1976d2;
            box-shadow: 0 5px 15px rgba(25, 118, 210, 0.1);
        }

        .import-method h4 {
            color: #1976d2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Section Management */
        .section-management {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 25px 0;
        }

        .section-level {
            width: 90%;
            padding: 15px;
            margin: 5px 0;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            position: relative;
        }

        .section-level.cover {
            background: #1976d2;
            color: white;
            border-color: #1565c0;
        }

        .section-level.problem {
            background: #e3f2fd;
            border-color: #1976d2;
            width: 85%;
        }

        .section-level.solution {
            background: #bbdefb;
            border-color: #0d47a1;
            width: 80%;
        }

        .section-level.metrics {
            background: #90caf9;
            border-color: #1976d2;
            width: 75%;
        }

        .section-level.next-steps {
            background: #64b5f6;
            border-color: #0d47a1;
            width: 70%;
        }

        .section-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .section-desc {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Reuse Slides Demo */
        .reuse-demo {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .reuse-option {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-align: center;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s;
        }

        .reuse-option:hover {
            background: #e3f2fd;
            border-color: #1976d2;
            transform: translateY(-3px);
        }

        .reuse-option.active {
            background: #bbdefb;
            border-color: #1976d2;
            border-width: 2px;
        }

        .reuse-icon {
            font-size: 2.5rem;
            color: #1976d2;
            margin-bottom: 15px;
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

            .method-demo,
            .import-methods,
            .reuse-demo {
                flex-direction: column;
            }

            .comparison-table {
                display: block;
                overflow-x: auto;
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
                <strong>Access Granted:</strong> PowerPoint Week 2 Handout
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
            <div>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week1_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                    <i class="fas fa-arrow-left"></i> Week 1
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/powerpoint_week3_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link" style="margin-left: 10px;">
                    <i class="fas fa-arrow-right"></i> Week 3
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-300 PowerPoint Certification Prep – Week 2 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Building and Structuring Complex Presentations</div>
            <div class="week-tag">Week 2 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-door-open"></i> Welcome to Week 2!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    Building on your foundational skills from Week 1, we now focus on efficient construction. A professional presentation is rarely built from scratch in isolation. This week, you'll learn how to leverage existing content, collaborate effectively, and manage large presentations with sophisticated structuring tools. By mastering the art of importing, reusing, and organizing with Sections, you'll transform from a slide creator into a presentation architect, capable of building substantial, well-organized decks with speed and precision.
                </p>

                <div class="image-container">
                    <img src="images/presentation_structure.png"
                        alt="Presentation Structure and Organization"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+UHJlc2VudGF0aW9uIFN0cnVjdHVyZSBhbmQgT3JnYW5pemF0aW9uPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Building Complex Presentations with Structure and Organization</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Import a structured outline from Microsoft Word to create a presentation skeleton rapidly</li>
                    <li>Reuse and insert slides from other presentations while maintaining or changing their original design</li>
                    <li>Implement Sections to logically group, manage, and navigate slides within a large presentation</li>
                    <li>Apply advanced slide management techniques, including slide duplication, repositioning, and efficient thumbnail navigation</li>
                    <li>Differentiate between merging, reusing, and embedding content from external sources</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-300 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Import content from Word</li>
                        <li>Reuse slides from other presentations</li>
                        <li>Insert and manage slides</li>
                        <li>Duplicate slides</li>
                    </ul>
                    <ul>
                        <li>Hide and show slides</li>
                        <li>Create and manage sections</li>
                        <li>Navigate between sections</li>
                        <li>Organize slide content</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Building from Outline -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-word"></i> 1. Building from an Outline: Importing from Word
                </div>

                <div class="method-demo">
                    <div class="method-item">
                        <div class="method-icon">
                            <i class="fas fa-heading"></i>
                        </div>
                        <h4>Heading 1</h4>
                        <p>Becomes slide titles in PowerPoint</p>
                    </div>
                    <div class="method-item">
                        <div class="method-icon">
                            <i class="fas fa-heading"></i>
                            <span style="font-size: 0.7em; vertical-align: super;">2</span>
                        </div>
                        <h4>Heading 2</h4>
                        <p>Becomes slide content or bullet points</p>
                    </div>
                    <div class="method-item">
                        <div class="method-icon">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <h4>Import Process</h4>
                        <p>Home → New Slide → Slides from Outline...</p>
                    </div>
                    <div class="method-item">
                        <div class="method-icon">
                            <i class="fas fa-magic"></i>
                        </div>
                        <h4>Automatic Conversion</h4>
                        <p>PowerPoint creates slides based on Word structure</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lightbulb"></i> Best Practices for Word Document Preparation</h3>
                    <ul>
                        <li>Use <strong>Heading 1</strong> for each slide title</li>
                        <li>Use <strong>Heading 2</strong> for major content sections within a slide</li>
                        <li>Use bullet points under headings for slide content</li>
                        <li>Keep formatting simple in Word - PowerPoint will apply its own design</li>
                        <li>Save Word document before importing</li>
                        <li>Review imported slides for proper formatting</li>
                    </ul>
                </div>

                <div class="image-container">
                    <img src="images/word_to_powerpoint.png"
                        alt="Importing from Word to PowerPoint">
                                        <div class="image-caption">Importing structured content from Microsoft Word to PowerPoint</div>
                </div>
            </div>

            <!-- Section 2: Reusing Content -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-recycle"></i> 2. Reusing Content from Other Presentations
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-window-restore"></i> The Reuse Slides Pane</h3>
                    <p><strong>Access:</strong> Home → New Slide → Reuse Slides...</p>
                    <p>The Reuse Slides pane allows you to browse and selectively insert slides from other PowerPoint files while giving you control over design adoption.</p>
                    
                    <div class="reuse-demo">
                        <div class="reuse-option" onclick="selectReuseOption(this)">
                            <div class="reuse-icon">
                                <i class="fas fa-search"></i>
                            </div>
                            <h4>Browse Files</h4>
                            <p>Browse to select any PowerPoint presentation file</p>
                        </div>
                        <div class="reuse-option" onclick="selectReuseOption(this)">
                            <div class="reuse-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <h4>Preview Slides</h4>
                            <p>See thumbnails of all slides in the selected file</p>
                        </div>
                        <div class="reuse-option" onclick="selectReuseOption(this)">
                            <div class="reuse-icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <h4>Select & Insert</h4>
                            <p>Click individual slides to insert them</p>
                        </div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-check-square"></i> The Critical Option: "Keep Source Formatting"</h3>
                    <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <div style="width: 30px; height: 30px; background: #1976d2; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-check" style="color: white;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: #1976d2;">CHECKED</h4>
                                <p style="margin: 5px 0 0 0;">The inserted slide retains its original design/theme</p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 30px; height: 30px; background: #f5f5f5; border: 2px solid #ddd; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-times" style="color: #666;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: #666;">UNCHECKED</h4>
                                <p style="margin: 5px 0 0 0;">The slide adopts the design/theme of the current presentation</p>
                            </div>
                        </div>
                    </div>
                    
                    <ul>
                        <li><strong>When to CHECK "Keep Source Formatting":</strong>
                            <ul>
                                <li>You want to preserve the original design of the slide</li>
                                <li>The slide has complex formatting you want to maintain</li>
                                <li>You're creating a presentation that combines multiple design styles</li>
                            </ul>
                        </li>
                        <li><strong>When to UNCHECK "Keep Source Formatting":</strong>
                            <ul>
                                <li>You want all slides to have consistent design</li>
                                <li>You're incorporating content into your existing template</li>
                                <li>You need to maintain brand consistency</li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Section 3: Slide Management -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-sliders-h"></i> 3. Core Slide Management Deep Dive
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-copy"></i> Duplicating Slides Efficiently</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                            <h4 style="color: #1976d2; margin-bottom: 10px;">Method 1: Right-Click</h4>
                            <p>Right-click slide → Duplicate Slide</p>
                            <div style="margin-top: 10px; padding: 5px; background: #e3f2fd; border-radius: 4px;">
                                <code>Right-click → Duplicate</code>
                            </div>
                        </div>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                            <h4 style="color: #1976d2; margin-bottom: 10px;">Method 2: Keyboard</h4>
                            <p>Select slide(s) → Ctrl + D</p>
                            <div style="margin-top: 10px; padding: 5px; background: #e3f2fd; border-radius: 4px;">
                                <code>Ctrl + D</code>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Why Duplicate Instead of Copy/Paste?
                        </div>
                        <ul>
                            <li><strong>Faster:</strong> One command vs. two (Ctrl+C then Ctrl+V)</li>
                            <li><strong>Preserves Everything:</strong> Maintains all formatting, animations, and transitions perfectly</li>
                            <li><strong>Maintains Position:</strong> Duplicate appears right after the original</li>
                            <li><strong>No Clipboard Issues:</strong> Doesn't interfere with other copied content</li>
                        </ul>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eye-slash"></i> Hiding Slides</h3>
                    <ul>
                        <li><strong>How to Hide:</strong> Right-click slide → Hide Slide</li>
                        <li><strong>Visual Indicator:</strong> Hidden slides show with a diagonal line through the slide number</li>
                        <li><strong>During Presentation:</strong> Hidden slides are skipped in normal slide show</li>
                        <li><strong>Accessing Hidden Slides:</strong>
                            <ul>
                                <li>Right-click during presentation → See All Slides</li>
                                <li>Type slide number and press Enter</li>
                                <li>Use hyperlinks to navigate to hidden slides</li>
                            </ul>
                        </li>
                    </ul>
                    
                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> Practical Uses for Hidden Slides
                        </div>
                        <ul>
                            <li><strong>Appendix Material:</strong> Detailed data, references, backup information</li>
                            <li><strong>Audience-Specific Content:</strong> Different versions for different audiences</li>
                            <li><strong>Q&A Preparation:</strong> Anticipated questions and detailed answers</li>
                            <li><strong>Time Management:</strong> Extra content if you have extra time</li>
                            <li><strong>Technical Details:</strong> In-depth information for interested participants</li>
                        </ul>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-arrows-alt"></i> Rearranging and Organizing</h3>
                    <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <h4 style="color: #1976d2; margin-bottom: 15px;">Two Methods for Reorganization:</h4>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 15px;">
                            <div style="flex: 1; min-width: 250px;">
                                <h5 style="color: #1565c0;"><i class="fas fa-th-large"></i> Normal View (Thumbnail Pane)</h5>
                                <ul>
                                    <li>Drag and drop individual slides</li>
                                    <li>See slide content while organizing</li>
                                    <li>Best for small adjustments</li>
                                </ul>
                            </div>
                            <div style="flex: 1; min-width: 250px;">
                                <h5 style="color: #1565c0;"><i class="fas fa-th"></i> Slide Sorter View</h5>
                                <ul>
                                    <li>See all slides as thumbnails</li>
                                    <li>Drag and drop multiple slides</li>
                                    <li>Best for major restructuring</li>
                                    <li><strong>Shortcut:</strong> Alt + W + I</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 4: Sections -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-folder"></i> 4. Structuring with Sections (The Professional's Tool)
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-plus-circle"></i> Creating and Managing Sections</h3>
                    
                    <div class="section-management">
                        <div class="section-level cover">
                            <div class="section-label">00_Cover</div>
                            <div class="section-desc">Title slide, agenda, presenter info</div>
                        </div>
                        <div class="section-level problem">
                            <div class="section-label">01_Problem_Statement</div>
                            <div class="section-desc">Current challenges, pain points, needs analysis</div>
                        </div>
                        <div class="section-level solution">
                            <div class="section-label">02_Our_Solution</div>
                            <div class="section-desc">Proposed approach, features, benefits</div>
                        </div>
                        <div class="section-level metrics">
                            <div class="section-label">03_Proof_&_Metrics</div>
                            <div class="section-desc">Case studies, data, success stories</div>
                        </div>
                        <div class="section-level next-steps">
                            <div class="section-label">04_Next_Steps</div>
                            <div class="section-desc">Call to action, implementation plan, Q&A</div>
                        </div>
                    </div>
                    
                    <ul>
                        <li><strong>Create Section:</strong> Right-click between slides → Add Section</li>
                        <li><strong>Rename Section:</strong> Right-click section header → Rename Section</li>
                        <li><strong>Move Section:</strong> Drag section header to new position (moves all slides in section)</li>
                        <li><strong>Collapse/Expand:</strong> Click triangle icon next to section name</li>
                        <li><strong>Remove Section:</strong> Right-click → Remove Section (keeps slides)</li>
                        <li><strong>Delete Section:</strong> Right-click → Delete Section & Slides</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-briefcase"></i> Real-World Use Cases for Sections</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
                        <div style="background: #e3f2fd; padding: 15px; border-radius: 8px;">
                            <h4 style="color: #1976d2; margin-bottom: 10px;"><i class="fas fa-list-ol"></i> Agenda-Based</h4>
                            <p>Introduction, Main Content, Case Studies, Conclusion, Q&A</p>
                        </div>
                        <div style="background: #e8f5e9; padding: 15px; border-radius: 8px;">
                            <h4 style="color: #2e7d32; margin-bottom: 10px;"><i class="fas fa-user-friends"></i> Presenter-Based</h4>
                            <p>Presenter 1, Presenter 2, Panel Discussion, Wrap-up</p>
                        </div>
                        <div style="background: #fff3e0; padding: 15px; border-radius: 8px;">
                            <h4 style="color: #e65100; margin-bottom: 10px;"><i class="fas fa-book"></i> Chapter-Based</h4>
                            <p>Chapter 1: Basics, Chapter 2: Intermediate, Chapter 3: Advanced</p>
                        </div>
                        <div style="background: #f3e5f5; padding: 15px; border-radius: 8px;">
                            <h4 style="color: #7b1fa2; margin-bottom: 10px;"><i class="fas fa-tasks"></i> Status-Based</h4>
                            <p>Completed, In Progress, Upcoming, Archived</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 5: Comparison -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-balance-scale"></i> 5. Comparing Content Integration Methods
                </div>

                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Best For</th>
                            <th>Design Control</th>
                            <th>Complexity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>Import Outline (from Word)</strong>
                                <div class="best-for">Starting from text-based content</div>
                            </td>
                            <td>Creating presentation from written content</td>
                            <td>Uses current template design</td>
                            <td>Low</td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Reuse Slides Pane</strong>
                                <div class="best-for">Selective borrowing from other decks</div>
                            </td>
                            <td>Incorporating specific slides from other presentations</td>
                            <td>Controlled by "Keep Source Formatting" option</td>
                            <td>Medium</td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Copy/Paste</strong>
                                <div class="best-for">Simple elements within slides</div>
                            </td>
                            <td>Small content pieces (text, images)</td>
                            <td>May cause formatting conflicts</td>
                            <td>Low</td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Embed Object</strong>
                                <div class="best-for">Live data from Excel or other apps</div>
                            </td>
                            <td>Maintaining live connections to source files</td>
                            <td>Limited design control</td>
                            <td>High</td>
                        </tr>
                    </tbody>
                </table>

                <div class="import-methods">
                    <div class="import-method">
                        <h4><i class="fas fa-file-word"></i> Import Outline</h4>
                        <ul>
                            <li><strong>Pros:</strong> Fast, structured, separates content from design</li>
                            <li><strong>Cons:</strong> Limited to text content, requires proper Word formatting</li>
                            <li><strong>Use when:</strong> Starting from a written document or report</li>
                        </ul>
                    </div>
                    <div class="import-method">
                        <h4><i class="fas fa-recycle"></i> Reuse Slides</h4>
                        <ul>
                            <li><strong>Pros:</strong> Selective, design control, preserves animations</li>
                            <li><strong>Cons:</strong> Requires source files, manual selection</li>
                            <li><strong>Use when:</strong> Combining content from multiple presentations</li>
                        </ul>
                    </div>
                    <div class="import-method">
                        <h4><i class="fas fa-paste"></i> Copy/Paste</h4>
                        <ul>
                            <li><strong>Pros:</strong> Simple, universal, works for any content</li>
                            <li><strong>Cons:</strong> Formatting issues, no design control</li>
                            <li><strong>Use when:</strong> Small content pieces, quick transfers</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Practice Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-hands-helping"></i> Hands-On Exercise: Assemble a Conference Pitch Presentation
                </div>
                
                <div style="margin: 20px 0;">
                    <h4 style="color: #303f9f; margin-bottom: 15px;">Follow these steps:</h4>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="color: #1976d2; margin-bottom: 10px;">Step 1: Create Foundation with an Outline</h5>
                        <ol>
                            <li>Open a new, blank PowerPoint presentation</li>
                            <li>Apply the <strong>"Ion" design theme</strong> (Design tab)</li>
                            <li>Use <strong>Home → New Slide → Slides from Outline...</strong></li>
                            <li>Import the provided <strong>Conference_Outline.docx</strong></li>
                            <li>Observe how Heading 1 becomes slide titles</li>
                        </ol>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="color: #1976d2; margin-bottom: 10px;">Step 2: Reuse and Integrate Existing Slides</h5>
                        <ol>
                            <li>Navigate to your last slide</li>
                            <li>Open the <strong>Reuse Slides pane</strong> (Home → New Slide → Reuse Slides...)</li>
                            <li>Browse and open <strong>Past_Conference_Highlights.pptx</strong></li>
                            <li>With <strong>"Keep Source Formatting" UNCHECKED</strong>, insert "Audience Demographics"</li>
                            <li>With <strong>"Keep Source Formatting" CHECKED</strong>, insert "Previous Success Metrics"</li>
                        </ol>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="color: #1976d2; margin-bottom: 10px;">Step 3: Implement Sections for Organization</h5>
                        <ol>
                            <li>Right-click before Slide 1 → <strong>Add Section</strong> → Rename to <strong>"00_Cover"</strong></li>
                            <li>Add section before Slide 2 → Rename to <strong>"01_Problem_Statement"</strong></li>
                            <li>Add sections for <strong>"02_Our_Solution"</strong>, <strong>"03_Proof_&_Metrics"</strong>, <strong>"04_Next_Steps"</strong></li>
                            <li>Collapse the "03_Proof_&_Metrics" section</li>
                            <li>Drag "04_Next_Steps" section above it</li>
                        </ol>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="color: #1976d2; margin-bottom: 10px;">Step 4: Apply Efficient Slide Management</h5>
                        <ol>
                            <li>In "02_Our_Solution" section, select first slide → Press <strong>Ctrl+D twice</strong></li>
                            <li>Hide last slide in "03_Proof_&_Metrics" section (right-click → Hide Slide)</li>
                            <li>Switch to <strong>Slide Sorter View</strong> (View → Slide Sorter)</li>
                            <li>Reorder one slide by dragging</li>
                        </ol>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px;">
                        <h5 style="color: #1976d2; margin-bottom: 10px;">Step 5: Finalize and Review</h5>
                        <ol>
                            <li>Switch back to Normal View</li>
                            <li>Collapse and expand sections to navigate quickly</li>
                            <li>Start slide show (F5) - confirm hidden slide is skipped</li>
                            <li>Save as <strong>YourName_Conference_Pitch_WK2.pptx</strong></li>
                        </ol>
                    </div>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Conference Presentation"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Db25mZXJlbmNlIFByZXNlbnRhdGlvbiBFeGVyY2lzZTwvdGV4dD48L3N2Zz4='">
                    <div class="image-caption">Build a Professional Conference Pitch Presentation</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadExerciseFiles()">
                    <i class="fas fa-download"></i> Download Exercise Files
                </a>
            </div>

            <!-- Keyboard Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-keyboard"></i> Keyboard Shortcuts Cheat Sheet (Week 2 Focus)
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
                            <td><span class="shortcut-key">Ctrl + D</span></td>
                            <td>Duplicate selected slide(s)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + M</span></td>
                            <td>Insert a new slide (with default layout)</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → I</span></td>
                            <td>Open the New Slide Layout gallery</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → S then O</span></td>
                            <td>Slides from Outline...</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt → H → S then U</span></td>
                            <td>Reuse Slides... (open pane)</td>
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
                            <td><span class="shortcut-key">Right-click between slides</span></td>
                            <td>Quick menu to Add Section</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Click slides</span></td>
                            <td>Select multiple slides</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Shift + Click</span></td>
                            <td>Select range of slides</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W + I</span></td>
                            <td>Switch to Slide Sorter View</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + W + L</span></td>
                            <td>Switch to Normal View</td>
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
                            <td><span class="shortcut-key">H</span></td>
                            <td>Go to hidden slide during presentation</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Number + Enter</span></td>
                            <td>Jump to specific slide during presentation</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Sections</strong>
                    <p>An organizational tool that groups slides into named, collapsible categories within a presentation. Sections act like folders or chapters for better organization and navigation.</p>
                </div>

                <div class="term">
                    <strong>Reuse Slides Pane</strong>
                    <p>A dedicated task pane for browsing and selectively inserting slides from other presentation files, with options to preserve or change the original design.</p>
                </div>

                <div class="term">
                    <strong>Keep Source Formatting</strong>
                    <p>A critical option in the Reuse Slides pane that determines whether inserted slides retain their original design or adopt the current presentation's theme.</p>
                </div>

                <div class="term">
                    <strong>Hide Slide</strong>
                    <p>A property applied to a slide that excludes it from the normal slide show sequence while keeping it in the file. Useful for backup content or audience-specific material.</p>
                </div>

                <div class="term">
                    <strong>Slide Sorter View</strong>
                    <p>The optimal view for visually managing the sequence, sections, and overall structure of an entire presentation. Shows all slides as thumbnails.</p>
                </div>

                <div class="term">
                    <strong>Outline View</strong>
                    <p>A view that shows presentation content as a text outline, useful for focusing on content structure without design distractions.</p>
                </div>

                <div class="term">
                    <strong>Thumbnail Pane</strong>
                    <p>The left-hand pane in Normal View that shows small previews of all slides for easy navigation and organization.</p>
                </div>

                <div class="term">
                    <strong>Import Outline</strong>
                    <p>The process of creating PowerPoint slides from a structured Word document, using heading styles to determine slide titles and content.</p>
                </div>
            </div>

            <!-- Self-Review Questions -->
            <div class="self-review-box">
                <div class="self-review-title">
                    <i class="fas fa-question-circle"></i> Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <ol>
                        <li><strong>You have a well-structured report in Word. What is the most efficient method to turn it into the initial slides of a PowerPoint presentation, and what Word formatting must you use?</strong>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="answer1">
                                <p>Use <strong>Slides from Outline</strong> (Home → New Slide → Slides from Outline...). The Word document must use <strong>Heading 1</strong> for slide titles and <strong>Heading 2</strong> or bullet points for slide content.</p>
                            </div>
                            <button onclick="toggleAnswer('answer1')" style="margin-top: 5px; padding: 5px 10px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.9rem;">Show Answer</button>
                        </li>
                        
                        <li><strong>When using the Reuse Slides pane, what is the key functional difference between leaving "Keep Source Formatting" checked versus unchecked?</strong>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="answer2">
                                <p><strong>Checked:</strong> The inserted slide retains its original design/theme.<br>
                                <strong>Unchecked:</strong> The slide adopts the design/theme of the current presentation.</p>
                            </div>
                            <button onclick="toggleAnswer('answer2')" style="margin-top: 5px; padding: 5px 10px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.9rem;">Show Answer</button>
                        </li>
                        
                        <li><strong>Describe two specific benefits of using Sections in a presentation with over 30 slides.</strong>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="answer3">
                                <p>1. <strong>Better Organization:</strong> Groups related slides into logical categories (e.g., by topic, presenter, or chapter).<br>
                                2. <strong>Easier Navigation:</strong> Allows collapsing/expanding sections and moving entire groups of slides together.<br>
                                3. <strong>Improved Collaboration:</strong> Different team members can work on different sections.<br>
                                4. <strong>Clear Structure:</strong> Provides visual hierarchy and organization for complex presentations.</p>
                            </div>
                            <button onclick="toggleAnswer('answer3')" style="margin-top: 5px; padding: 5px 10px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.9rem;">Show Answer</button>
                        </li>
                        
                        <li><strong>What is the keyboard shortcut for duplicating a slide, and why is it often better than using Copy (Ctrl+C) and Paste (Ctrl+V)?</strong>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="answer4">
                                <p><strong>Shortcut:</strong> Ctrl + D<br>
                                <strong>Why better:</strong> It's faster (one command vs. two), perfectly preserves all formatting, animations, and transitions, and the duplicate appears immediately after the original without clipboard interference.</p>
                            </div>
                            <button onclick="toggleAnswer('answer4')" style="margin-top: 5px; padding: 5px 10px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.9rem;">Show Answer</button>
                        </li>
                        
                        <li><strong>You need to include a detailed appendix in your presentation for Q&A but don't want it shown in the main talk. What feature do you use?</strong>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.5); border-radius: 5px; display: none;" id="answer5">
                                <p>Use the <strong>Hide Slide</strong> feature (right-click slide → Hide Slide). The slide remains in the presentation file but is skipped during normal slide show. You can access it during Q&A by typing the slide number and pressing Enter, or through the See All Slides option.</p>
                            </div>
                            <button onclick="toggleAnswer('answer5')" style="margin-top: 5px; padding: 5px 10px; background: #ff9800; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.9rem;">Show Answer</button>
                        </li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Practice:</strong> Create a 10-slide presentation using all the techniques covered this week. Submit via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> Tips for Success
                </div>
                <ul>
                    <li><strong>Storyboard with Sections:</strong> Before designing a single slide, use Sections in a blank deck to map out your presentation's narrative flow (Intro, Problem, Solution, Proof, Call-to-Action).</li>
                    <li><strong>Maintain Design Control:</strong> When collaborating, decide on a "master" template first. When reusing slides, typically uncheck "Keep Source Formatting" to ensure visual consistency.</li>
                    <li><strong>Use Hiding Judiciously:</strong> Hidden slides are perfect for detailed backup data, regional-specific content, or answers to anticipated questions.</li>
                    <li><strong>Leverage Slide Sorter:</strong> Make Slide Sorter (Alt → W → I) your default view when restructuring or applying sections. It provides the essential "big picture."</li>
                    <li><strong>Organize Files:</strong> Keep source Word documents and reusable slides in clearly named folders for quick access.</li>
                    <li><strong>Test Hidden Slides:</strong> Always test that hidden slides are truly hidden during slide show but accessible when needed.</li>
                    <li><strong>Name Sections Clearly:</strong> Use descriptive section names (e.g., "02_Market_Analysis" instead of just "Part 2").</li>
                    <li><strong>Use Numbering Convention:</strong> Prefix section names with numbers (00_, 01_, etc.) to maintain order.</li>
                    <li><strong>Backup Before Merging:</strong> Always save a copy before reusing slides from other presentations.</li>
                    <li><strong>Check for Updates:</strong> If you embed live data, verify links and updates before presenting.</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/office/import-a-word-outline-into-powerpoint-1745e3b1-2b5b-4b5e-8c7a-8e45a9a2adc6" target="_blank">Microsoft: Import a Word Outline into PowerPoint</a></li>
                    <li><a href="https://support.microsoft.com/office/reuse-re-purpose-slides-from-another-presentation-c676c2a8-6b2c-4c9c-a7c4-4c1c6ce5e0b5" target="_blank">Microsoft: Reuse Slides from Another Presentation</a></li>
                    <li><a href="https://support.microsoft.com/office/organize-your-slides-into-sections-456d4d8f-7a8a-4c0c-8b7d-8c8c2a7d4b5c" target="_blank">Microsoft: Organize Slides into Sections</a></li>
                    <li><a href="https://support.microsoft.com/office/hide-or-show-slides-in-powerpoint-9d0b8b3b-7c4d-4c5d-8c5a-7c4c4c4c4c4c" target="_blank">Microsoft: Hide or Show Slides</a></li>
                    <li><strong>Practice files and templates</strong> available in the Course Portal</li>
                    <li><strong>Week 2 Quiz</strong> to test your understanding (available in portal)</li>
                    <li><strong>Collaboration Guide:</strong> Best practices for team presentations</li>
                    <li><strong>Template Library:</strong> Pre-structured presentation templates with sections</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> Next Week Preview
                </div>
                <p><strong>Week 3: Visual Storytelling with Graphics & Media</strong></p>
                <p>In Week 3, we'll bring your structured presentations to life! You'll master the insertion and formatting of images, icons, and 3D models. We'll dive deep into SmartArt graphics for conceptual diagrams and explore how to effectively embed and format video and audio. Prepare to elevate your slides from structured documents to compelling visual stories!</p>
                <ul>
                    <li>Insert and format high-quality images</li>
                    <li>Work with icons, 3D models, and stock images</li>
                    <li>Create professional diagrams with SmartArt</li>
                    <li>Embed and format video and audio</li>
                    <li>Apply artistic effects and corrections</li>
                    <li>Use the Design Ideas feature</li>
                    <li>Create photo albums and galleries</li>
                    <li>Optimize media for file size and performance</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Gather images, icons, and any media files you'd like to incorporate into a presentation.</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/powerpoint-week2.php">Week 2 Discussion</a></li>
                    <li><strong>Technical Support:</strong> <a href="mailto:support@impactdigitalacademy.com">support@impactdigitalacademy.com</a></li>
                    <li><strong>Microsoft PowerPoint Help:</strong> <a href="https://support.microsoft.com/powerpoint" target="_blank">Official Support</a></li>
                    <li><strong>Collaboration Tools Guide:</strong> <a href="<?php echo BASE_URL; ?>modules/resources/collaboration_guide.php">Team Presentation Best Practices</a></li>
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
                <!--<a href="<?php echo BASE_URL; ?>modules/<?php echo $this->user_role; ?>/quiz/powerpoint_week2_quiz.php<?php echo $this->class_id ? '?class_id=' . $this->class_id : ''; ?>" class="download-btn" style="background: #4caf50; margin-left: 15px;">
                    <i class="fas fa-clipboard-check"></i> Take Week 2 Quiz
                </a>-->
            </div>
        </div>

        <footer>
            <p>MO-300: Microsoft PowerPoint Certification Prep Program – Week 2 Handout</p>
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

        // Simulate exercise files download
        function downloadExerciseFiles() {
            alert('Exercise files would download including:\n\n1. Conference_Outline.docx\n2. Past_Conference_Highlights.pptx\n3. Section_Template.potx\n\nThis is a demo.');
            // In production:
            // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSPowerPoint/exercises/week2_exercise_files.zip';
        }

        // Reuse option selection
        function selectReuseOption(element) {
            const options = document.querySelectorAll('.reuse-option');
            options.forEach(option => option.classList.remove('active'));
            element.classList.add('active');
            
            const optionName = element.querySelector('h4').textContent;
            alert(`Selected: ${optionName}\n\n${element.querySelector('p').textContent}`);
        }

        // Toggle answer visibility
        function toggleAnswer(answerId) {
            const answer = document.getElementById(answerId);
            const button = event.target;
            
            if (answer.style.display === 'block') {
                answer.style.display = 'none';
                button.textContent = 'Show Answer';
            } else {
                answer.style.display = 'block';
                button.textContent = 'Hide Answer';
            }
        }

        // Section management demonstration
        function demonstrateSections() {
            const steps = [
                "Section Management Walkthrough:",
                "1. Right-click between slides → Add Section",
                "2. Right-click section header → Rename Section",
                "3. Drag section header to reorder entire section",
                "4. Click triangle to collapse/expand section",
                "5. Right-click section header for options:",
                "   - Remove Section (keeps slides)",
                "   - Delete Section & Slides",
                "   - Collapse All / Expand All",
                "\nPro Tip: Use numbered prefixes (00_, 01_, etc.) for automatic ordering."
            ];
            alert(steps.join("\n"));
        }

        // Import outline demonstration
        function demonstrateImportOutline() {
            const steps = [
                "Import Outline from Word:",
                "1. Prepare Word document:",
                "   - Use Heading 1 for slide titles",
                "   - Use Heading 2 for slide content",
                "   - Save Word document",
                "2. In PowerPoint:",
                "   - Home tab → New Slide dropdown",
                "   - Select 'Slides from Outline...'",
                "   - Browse to Word document",
                "   - Click Insert",
                "3. Review:",
                "   - Each Heading 1 becomes a slide title",
                "   - Heading 2/text becomes slide content",
                "   - Slides adopt current PowerPoint theme"
            ];
            alert(steps.join("\n"));
        }

        // Reuse slides demonstration
        function demonstrateReuseSlides() {
            const demo = [
                "Reuse Slides Pane Demo:",
                "1. Home → New Slide → Reuse Slides...",
                "2. Click 'Browse' → 'Browse File'",
                "3. Select PowerPoint presentation",
                "4. Thumbnails appear in pane",
                "5. Options:",
                "   - Click slide to insert at cursor",
                "   - Right-click for more options",
                "   - Check/Uncheck 'Keep Source Formatting'",
                "\nKey Decision:",
                "☑ Keep Source Formatting = Original design",
                "☐ Keep Source Formatting = Current template"
            ];
            alert(demo.join("\n"));
        }

        // Image fallback handler
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.onerror = function() {
                    this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA2MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNlZWVlZWUiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==';
                };
            });

            // Interactive method items
            const methodItems = document.querySelectorAll('.method-item');
            methodItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const description = this.querySelector('p').textContent;
                    alert(`${title}\n\n${description}\n\nTry this in PowerPoint!`);
                });
            });

            // Import method interaction
            const importMethods = document.querySelectorAll('.import-method');
            importMethods.forEach(method => {
                method.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    const listItems = this.querySelectorAll('li');
                    let content = `${title}\n\n`;
                    
                    listItems.forEach(li => {
                        content += `• ${li.textContent}\n`;
                    });
                    
                    alert(content);
                });
            });

            // Section levels interaction
            const sectionLevels = document.querySelectorAll('.section-level');
            sectionLevels.forEach(level => {
                level.addEventListener('click', function() {
                    const label = this.querySelector('.section-label').textContent;
                    const desc = this.querySelector('.section-desc').textContent;
                    alert(`Section: ${label}\n\nPurpose: ${desc}\n\nRight-click before slides to add sections like this.`);
                });
            });
        });

        // Track handout access
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('PowerPoint Week 2 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Keyboard shortcut practice
        document.addEventListener('keydown', function(e) {
            const shortcuts = {
                'd': 'Duplicate Slide (Ctrl + D)',
                'm': 'New Slide (Ctrl + M)',
                'p': 'Print (Ctrl + P)',
                's': 'Save (Ctrl + S)'
            };
            
            if (e.ctrlKey && shortcuts[e.key]) {
                const shortcutAlert = document.createElement('div');
                shortcutAlert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #1976d2;
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
            const interactiveElements = document.querySelectorAll('a, button, .method-item, .reuse-option, .import-method, .section-level');
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

        // Week 2 specific demonstrations
        function demonstrateHideSlide() {
            const demo = [
                "Hide Slide Feature:",
                "How to Hide:",
                "1. Right-click slide in thumbnail pane",
                "2. Select 'Hide Slide'",
                "3. Slide shows with diagonal line through number",
                "",
                "During Presentation:",
                "• Hidden slides are skipped",
                "• To access: Type slide number + Enter",
                "• Or: Right-click → See All Slides",
                "",
                "Practical Uses:",
                "• Backup data and details",
                "• Audience-specific content",
                "• Anticipated Q&A answers",
                "• Time-sensitive material"
            ];
            alert(demo.join("\n"));
        }

        function demonstrateSlideSorter() {
            const demo = [
                "Slide Sorter View (Alt + W + I):",
                "Benefits:",
                "• See all slides as thumbnails",
                "• Drag and drop to reorder",
                "• Apply transitions to multiple slides",
                "• View and manage sections",
                "",
                "Best For:",
                "• Major restructuring",
                "• Applying consistent transitions",
                "• Getting "big picture" view",
                "• Organizing large presentations",
                "",
                "Pro Tip: Use with Sections for maximum organization!"
            ];
            alert(demo.join("\n"));
        }

        // Self-review cheat key
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                const answers = [
                    "1. Use 'Slides from Outline'. Word must use Heading 1 for titles and Heading 2/bullets for content.",
                    "2. Checked: keeps original design. Unchecked: adopts current presentation design.",
                    "3. Benefits: Better organization, easier navigation, improved collaboration, clear structure.",
                    "4. Ctrl + D. Better because it's faster, preserves all formatting perfectly, and doesn't use clipboard.",
                    "5. Use 'Hide Slide' feature. The slide stays in file but is skipped during normal presentation."
                ];
                alert("Week 2 Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
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
    $viewer = new PowerPointWeek2HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>