<?php
// modules/shared/course_materials/word/word_week7_view.php

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
 * Word Week 7 Handout Viewer Class with PDF Download
 */
class WordWeek7HandoutViewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor'];

    // User details
    private $user_email;
    private $user_first_name;
    private $user_last_name;
    private $instructor_name;
    private $instructor_email;

    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
        $this->fetchUserDetails();
        $this->fetchInstructorDetails();
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
        $this->user_email = $_SESSION['user_email'] ?? '';
        $this->user_first_name = $_SESSION['first_name'] ?? '';
        $this->user_last_name = $_SESSION['last_name'] ?? '';
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
     * Fetch user details from database
     */
    private function fetchUserDetails(): void
    {
        if (!$this->conn) {
            return;
        }

        $sql = "SELECT u.email, u.first_name, u.last_name, u.phone 
        FROM users u 
        WHERE u.id = ?";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $this->user_email = $row['email'];
                $this->user_first_name = $row['first_name'];
                $this->user_last_name = $row['last_name'];

                // Update session with fresh data
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['first_name'] = $row['first_name'];
                $_SESSION['last_name'] = $row['last_name'];
            }
            $stmt->close();
        }
    }

    /**
     * Fetch instructor details for the class
     */
    private function fetchInstructorDetails(): void
    {
        if (!$this->conn || $this->class_id === null) {
            // Set default values if no class_id or connection
            $this->instructor_name = 'Your Instructor';
            $this->instructor_email = 'instructor@impactdigitalacademy.com';
            return;
        }

        // Try to get instructor from class_batches first
        $sql = "SELECT cb.instructor_id, u.first_name, u.last_name, u.email
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

                // Update session with instructor details
                $_SESSION['instructor_name'] = $this->instructor_name;
                $_SESSION['instructor_email'] = $this->instructor_email;
            } else {
                // Fallback to default values
                $this->instructor_name = 'Your Instructor';
                $this->instructor_email = 'instructor@impactdigitalacademy.com';
            }
            $stmt->close();
        }
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
            $mpdf->SetTitle('Week 7: Document Inspection and Final Exam Preparation');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');

            // Set metadata
            $mpdf->SetKeywords('Microsoft Word, MO-100, Document Inspection, Accessibility, Compatibility, Exam Preparation');

            // Add a cover page
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();

            // Set header and footer
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());

            // Write main content
            $mpdf->WriteHTML($htmlContent);

            // Output PDF
            $filename = 'Word_Week7_Document_Inspection_' . date('Y-m-d') . '.pdf';

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
        $user_email = $this->user_email;
        $instructor_name = $this->instructor_name;
        $instructor_email = $this->instructor_email;
        $user_full_name = $this->user_first_name . ' ' . $this->user_last_name;
?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 12pt;">
            <h1 style="color: #0d3d8c; border-bottom: 2px solid #0d3d8c; padding-bottom: 10px; font-size: 18pt;">
                Week 7: Document Inspection and Final Exam Preparation
            </h1>

            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 7!</h2>
                <p style="margin-bottom: 15px;">
                    This week is dedicated to two critical areas: ensuring your documents are clean, accessible, and compatible, and preparing strategically for the MO-100 exam. You'll learn how to inspect and protect documents, remove hidden data, check for accessibility and compatibility issues, and refine your test-taking skills. This session is essential for anyone who handles sensitive documents and for those who want to enter the exam with confidence.
                </p>
            </div>

            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Use the Document Inspector to find and remove hidden properties and personal information.</li>
                    <li>Run and interpret the Accessibility Checker to locate and fix accessibility issues.</li>
                    <li>Check and resolve document compatibility issues with older versions of Word.</li>
                    <li>Apply exam-taking strategies and review key topics in preparation for the MO-100 exam.</li>
                </ul>
            </div>

            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>

                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Inspecting Documents for Hidden Data and Personal Information</h3>
                <p><strong>Why Inspect?</strong></p>
                <p>Documents can contain hidden metadata, comments, tracked changes, and personal info that should not be shared.</p>

                <p><strong>Using the Document Inspector:</strong></p>
                <ul>
                    <li>File → Info → Check for Issues → Inspect Document.</li>
                    <li>Select what to check: Comments, Document Properties, Headers/Footers, etc.</li>
                    <li>Remove All for selected items (some removals are permanent).</li>
                </ul>

                <p><strong>Common Hidden Data:</strong></p>
                <ul>
                    <li>Author name, company, file paths, hidden text, inactive content.</li>
                </ul>

                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Checking and Correcting Accessibility Issues</h3>
                <p><strong>What is Accessibility?</strong></p>
                <p>Ensuring documents are usable by people with disabilities (e.g., screen reader compatibility).</p>

                <p><strong>Running the Accessibility Checker:</strong></p>
                <ul>
                    <li>Review tab → Check Accessibility.</li>
                    <li>Issues appear in the Accessibility Pane with Errors, Warnings, and Tips.</li>
                </ul>

                <p><strong>Common Accessibility Issues & Fixes:</strong></p>
                <ul>
                    <li><strong>Missing Alt Text:</strong> Add descriptive alt text to images, charts, shapes.</li>
                    <li><strong>Table Headers Missing:</strong> Define header rows in tables (Table Design → Header Row).</li>
                    <li><strong>Poor Color Contrast:</strong> Ensure text and background have sufficient contrast.</li>
                    <li><strong>Non-sequential Headings:</strong> Use Heading styles in order (H1, H2, H3).</li>
                </ul>

                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">3. Checking and Correcting Compatibility Issues</h3>
                <p><strong>Why Check Compatibility?</strong></p>
                <p>Features in newer Word versions may not work in older ones.</p>

                <p><strong>Using the Compatibility Checker:</strong></p>
                <ul>
                    <li>File → Info → Check for Issues → Check Compatibility.</li>
                    <li>Review list of features not supported in earlier versions.</li>
                    <li>Resolve by: Simplifying formatting, avoiding newer features, or saving in an older format.</li>
                </ul>

                <p><strong>Saving in Older Formats:</strong></p>
                <ul>
                    <li>File → Save As → Word 97-2003 Document (*.doc).</li>
                </ul>

                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">4. Final Exam Preparation Strategies</h3>
                <p><strong>Understand the Exam Format:</strong></p>
                <ul>
                    <li>Multiple-choice, drag-and-drop, performance-based tasks.</li>
                    <li>Approximately 45–60 questions, 50 minutes.</li>
                </ul>

                <p><strong>Key Areas to Review:</strong></p>
                <ul>
                    <li>Document management (save, share, inspect).</li>
                    <li>Formatting (text, paragraphs, sections).</li>
                    <li>Tables and lists.</li>
                    <li>Graphic elements.</li>
                    <li>References.</li>
                    <li>Collaboration (comments, track changes).</li>
                </ul>

                <p><strong>Test-Taking Tips:</strong></p>
                <ul>
                    <li><strong>Manage Time:</strong> Don't spend too long on one question.</li>
                    <li><strong>Read Carefully:</strong> Pay attention to wording like "not," "except," "best."</li>
                    <li><strong>Use Practice Exams:</strong> Simulate real exam conditions.</li>
                    <li><strong>Review Performance-Based Tasks:</strong> These require hands-on Word skills.</li>
                </ul>
            </div>

            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Inspect, Clean, and Prepare a Sample Document</strong></p>
                <p>Follow these steps to practice document inspection and cleanup:</p>
                <ol>
                    <li>Open Week7_Sample_Report.docx from the portal.</li>
                    <li>Run the Document Inspector:
                        <ul>
                            <li>File → Info → Inspect Document → Inspect.</li>
                            <li>Remove all: Document Properties and Personal Information.</li>
                        </ul>
                    </li>
                    <li>Run the Accessibility Checker:
                        <ul>
                            <li>Review tab → Check Accessibility.</li>
                            <li>Fix all errors and warnings:
                                <ul>
                                    <li>Add Alt Text to the image.</li>
                                    <li>Ensure table has a header row.</li>
                                    <li>Check color contrast in the chart.</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li>Run the Compatibility Checker:
                        <ul>
                            <li>File → Info → Check Compatibility.</li>
                            <li>Note any issues and decide if you need to save in an older format.</li>
                        </ul>
                    </li>
                    <li>Save the cleaned version as <strong><?php echo htmlspecialchars($this->user_first_name); ?>_Week7_Cleaned.docx</strong>.</li>
                    <li>Take the Week 7 Practice Quiz (available in the portal) to test your knowledge.</li>
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
                            <td style="padding: 6px 8px;">F12</td>
                            <td style="padding: 6px 8px;">Save As</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, I, D</td>
                            <td style="padding: 6px 8px;">Open Document Inspector (via File → Info)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + R, A</td>
                            <td style="padding: 6px 8px;">Open Accessibility Checker</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Alt + F, I, C</td>
                            <td style="padding: 6px 8px;">Open Compatibility Checker</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + F1</td>
                            <td style="padding: 6px 8px;">Collapse/Expand the Ribbon (more space for tasks)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Metadata:</strong> Hidden data about the document (author, dates, revisions).</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Accessibility:</strong> Designing documents to be usable by individuals with disabilities.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Compatibility:</strong> Ensuring a document works correctly across different versions of Word.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Document Inspector:</strong> Tool that finds and removes hidden content and personal info.</p>
                </div>
                <div>
                    <p><strong>Alt Text:</strong> Descriptive text added to visuals for screen readers.</p>
                </div>
            </div>

            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>What types of hidden information can the Document Inspector find?</li>
                    <li>Why is it important to add Alt Text to images?</li>
                    <li>How can you check if a document will work correctly in Word 2010?</li>
                    <li>What are two common accessibility issues in Word documents?</li>
                    <li>Name two strategies for managing your time during the MO-100 exam.</li>
                </ol>
            </div>

            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul>
                    <li>Locate and remove hidden properties and personal information</li>
                    <li>Locate and correct accessibility issues</li>
                    <li>Locate and correct compatibility issues</li>
                </ul>
            </div>

            <!-- Exam Preparation Checklist -->
            <div style="background: #fff3e0; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Exam Preparation Checklist</h3>
                <ul>
                    <li>Review all handouts from Weeks 1–6.</li>
                    <li>Complete at least one full-length practice exam.</li>
                    <li>Practice performance-based tasks (available in the portal).</li>
                    <li>Test document inspection, accessibility, and compatibility features.</li>
                    <li>Familiarize yourself with the exam interface (MOS exam demo).</li>
                    <li>Schedule your exam if you haven't already.</li>
                </ul>
            </div>

            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Clean Before Sharing:</strong> Always inspect documents before sending them out.</li>
                    <li><strong>Accessibility First:</strong> Build accessible documents from the start—it's easier than fixing later.</li>
                    <li><strong>Practice Under Pressure:</strong> Time yourself while doing practice exams.</li>
                    <li><strong>Stay Calm:</strong> On exam day, take a deep breath and read each question carefully.</li>
                </ul>
            </div>

            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 8, we'll host a Mock Exam and Final Review Session. You'll take a simulated MO-100 exam, review answers, and ask final questions before your certification test.</p>
            </div>

            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Microsoft Document Inspector Guide</li>
                    <li>Word Accessibility Checklist</li>
                    <li>MOS Exam Preparation Page</li>
                    <li>Practice exams and review materials available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Instructor Info -->
            <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 25px; font-size: 11pt;">
                <h4 style="color: #0d3d8c; margin-bottom: 10px;">Instructor Information</h4>
                <p><strong>Instructor:</strong> <?php echo htmlspecialchars($instructor_name); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($instructor_email); ?></p>
                <p><strong>Course Portal:</strong> Access through your dashboard</p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($user_full_name); ?> (<?php echo htmlspecialchars($user_email); ?>)</p>
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
        $studentName = $this->user_first_name . ' ' . $this->user_last_name;
        $studentEmail = $this->user_email;

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
                Week 7 Handout: Document Inspection and Final Exam Preparation
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($studentName) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Email: ' . htmlspecialchars($studentEmail) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Date: ' . date('F j, Y') . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Access Level: ' . ucfirst($this->user_role) . '
                </p>
                <p style="font-size: 14pt; color: #666;">
                    Instructor: ' . htmlspecialchars($this->instructor_name) . '
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
            Week 7: Document Inspection & Exam Prep | Impact Digital Academy | ' . date('m/d/Y') . '
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
        $user_role = $this->user_role;
        $class_id = $this->class_id;
        $user_email = $this->user_email;
        $user_full_name = $this->user_first_name . ' ' . $this->user_last_name;
        $instructor_name = $this->instructor_name;
        $instructor_email = $this->instructor_email;
    ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Week 7: Document Inspection and Final Exam Preparation - Impact Digital Academy</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <link rel="icon" href="../../public/images/favicon.ico">
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

                ul,
                ol {
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

                .checklist-box {
                    background: #fff9e6;
                    border-left: 5px solid #ff9800;
                    padding: 25px;
                    margin: 30px 0;
                    border-radius: 0 8px 8px 0;
                }

                .checklist-title {
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
                    border: none;
                    font-size: 1rem;
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
                    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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

                /* Tool Demos */
                .tool-demo {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 20px;
                    margin: 25px 0;
                }

                .tool-item {
                    flex: 1;
                    min-width: 200px;
                    text-align: center;
                    padding: 20px;
                    border: 1px solid #e0e0e0;
                    border-radius: 8px;
                    background: #f9f9f9;
                    transition: transform 0.2s;
                }

                .tool-item:hover {
                    transform: translateY(-3px);
                    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
                }

                .tool-icon {
                    font-size: 3rem;
                    color: #185abd;
                    margin-bottom: 15px;
                }

                .exam-tips {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 15px;
                    margin: 20px 0;
                }

                .exam-tip {
                    padding: 15px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                    background: white;
                }

                .exam-tip h4 {
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
                    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
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

                    .tool-demo {
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
                        <strong>Access Granted:</strong> Word Week 7 Handout
                    </div>
                    <div class="access-badge">
                        <?php echo ucfirst($user_role); ?> Access
                    </div>
                    <?php if ($user_role === 'student'): ?>
                        <div style="font-size: 0.9rem; opacity: 0.9;">
                            <i class="fas fa-user-graduate"></i> Student: <?php echo htmlspecialchars($user_full_name); ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size: 0.9rem; opacity: 0.9;">
                            <i class="fas fa-chalkboard-teacher"></i> Instructor: <?php echo htmlspecialchars($user_full_name); ?>
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
                    <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week6_view.php?class_id=<?php echo $class_id; ?>" class="back-link">
                        <i class="fas fa-arrow-left"></i> Week 6
                    </a>
                <?php endif; ?>
            </div>

            <div class="container">
                <div class="header">
                    <h1>Impact Digital Academy</h1>
                    <div class="subtitle">MO-100 Word Certification Prep – Week 7 Handout</div>
                    <div style="font-size: 1.6rem; margin: 15px 0;">Document Inspection and Final Exam Preparation</div>
                    <div class="week-tag">Week 7 of 8</div>
                </div>

                <div class="content">
                    <!-- Welcome Section -->
                    <div class="section">
                        <div class="section-title">
                            <i class="fas fa-search"></i> Welcome to Week 7!
                        </div>
                        <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                            This week is dedicated to two critical areas: ensuring your documents are clean, accessible, and compatible, and preparing strategically for the MO-100 exam. You'll learn how to inspect and protect documents, remove hidden data, check for accessibility and compatibility issues, and refine your test-taking skills. This session is essential for anyone who handles sensitive documents and for those who want to enter the exam with confidence.
                        </p>

                        <div class="image-container">
                            <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                                alt="Document Inspection Tools"
                                onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RW5zdXJpbmcgRG9jdW1lbnQgUXVhbGl0eSBhbmQgUHJlcGFyaW5nIGZvciBFeGFtPC90ZXh0Pjwvc3ZnPg=='">
                            <div class="image-caption">Ensuring Document Quality and Preparing for Certification</div>
                        </div>
                    </div>

                    <!-- Learning Objectives -->
                    <div class="learning-objectives">
                        <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                        <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                        <ul>
                            <li>Use the Document Inspector to find and remove hidden properties and personal information.</li>
                            <li>Run and interpret the Accessibility Checker to locate and fix accessibility issues.</li>
                            <li>Check and resolve document compatibility issues with older versions of Word.</li>
                            <li>Apply exam-taking strategies and review key topics in preparation for the MO-100 exam.</li>
                        </ul>
                    </div>

                    <!-- Exam Focus Areas -->
                    <div class="exam-focus">
                        <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                        <ul>
                            <li>Locate and remove hidden properties and personal information</li>
                            <li>Locate and correct accessibility issues</li>
                            <li>Locate and correct compatibility issues</li>
                        </ul>
                    </div>

                    <!-- Section 1: Document Inspection Tools -->
                    <div class="section">
                        <div class="section-title">
                            <i class="fas fa-shield-alt"></i> 1. Inspecting Documents for Hidden Data
                        </div>

                        <div class="tool-demo">
                            <div class="tool-item">
                                <div class="tool-icon">
                                    <i class="fas fa-search"></i>
                                </div>
                                <h4>Document Inspector</h4>
                                <p>File → Info → Check for Issues → Inspect Document</p>
                                <p style="font-size: 0.9rem; color: #666;">Finds hidden metadata and personal info</p>
                            </div>
                            <div class="tool-item">
                                <div class="tool-icon">
                                    <i class="fas fa-eye"></i>
                                </div>
                                <h4>Accessibility Checker</h4>
                                <p>Review tab → Check Accessibility</p>
                                <p style="font-size: 0.9rem; color: #666;">Ensures documents are accessible to all users</p>
                            </div>
                            <div class="tool-item">
                                <div class="tool-icon">
                                    <i class="fas fa-exchange-alt"></i>
                                </div>
                                <h4>Compatibility Checker</h4>
                                <p>File → Info → Check for Issues → Check Compatibility</p>
                                <p style="font-size: 0.9rem; color: #666;">Checks for issues with older Word versions</p>
                            </div>
                        </div>

                        <div class="subsection">
                            <h3><i class="fas fa-info-circle"></i> What the Document Inspector Finds</h3>
                            <ul>
                                <li><strong>Comments & Revisions:</strong> Tracked changes and comments</li>
                                <li><strong>Document Properties:</strong> Author, company, creation dates</li>
                                <li><strong>Headers & Footers:</strong> Hidden text in headers/footers</li>
                                <li><strong>Hidden Text:</strong> Formatted as hidden</li>
                                <li><strong>Invisible Content:</strong> White text on white background</li>
                                <li><strong>Custom XML Data:</strong> Embedded XML data</li>
                            </ul>
                        </div>

                        <div class="tip-box">
                            <div class="tip-title">
                                <i class="fas fa-exclamation-triangle"></i> Important Warning
                            </div>
                            <p>Some removals by the Document Inspector are permanent. Always save a copy of your original document before using Remove All.</p>
                        </div>
                    </div>

                    <!-- Section 2: Accessibility & Compatibility -->
                    <div class="section">
                        <div class="section-title">
                            <i class="fas fa-universal-access"></i> 2. Accessibility & Compatibility
                        </div>

                        <div class="subsection">
                            <h3><i class="fas fa-wheelchair"></i> A. Common Accessibility Issues & Fixes</h3>

                            <table class="demo-table">
                                <thead>
                                    <tr class="header-row">
                                        <th>Issue</th>
                                        <th>How to Find</th>
                                        <th>How to Fix</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Missing Alt Text</strong></td>
                                        <td>Accessibility Checker → Errors</td>
                                        <td>Right-click image → Edit Alt Text → Add description</td>
                                    </tr>
                                    <tr>
                                        <td><strong>No Table Headers</strong></td>
                                        <td>Accessibility Checker → Errors</td>
                                        <td>Select table → Table Design → Header Row checkbox</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Poor Color Contrast</strong></td>
                                        <td>Accessibility Checker → Warnings</td>
                                        <td>Change text/background colors for better contrast</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Non-Sequential Headings</strong></td>
                                        <td>Accessibility Checker → Tips</td>
                                        <td>Use Heading 1, then Heading 2, etc. in order</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="subsection">
                            <h3><i class="fas fa-sync-alt"></i> B. Compatibility Issues</h3>
                            <ul>
                                <li><strong>New Formatting Features:</strong> Advanced text effects, newer chart types</li>
                                <li><strong>New Graphic Features:</strong> 3D models, SVG icons</li>
                                <li><strong>Layout Features:</strong> Modern page layout options</li>
                                <li><strong>Collaboration Features:</strong> Real-time co-authoring</li>
                            </ul>

                            <div class="tip-box">
                                <div class="tip-title">
                                    <i class="fas fa-lightbulb"></i> Quick Fix
                                </div>
                                <p>If you need to share with Word 2003 users, save as <strong>Word 97-2003 Document (*.doc)</strong>. Word will convert incompatible features.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Exam Preparation -->
                    <div class="section">
                        <div class="section-title">
                            <i class="fas fa-graduation-cap"></i> 3. Final Exam Preparation Strategies
                        </div>

                        <div class="exam-tips">
                            <div class="exam-tip">
                                <h4><i class="fas fa-clock"></i> Time Management</h4>
                                <p>50 minutes for 45-60 questions = ~1 minute per question. Skip difficult questions and return later.</p>
                            </div>
                            <div class="exam-tip">
                                <h4><i class="fas fa-book-reader"></i> Question Analysis</h4>
                                <p>Watch for keywords: "not," "except," "best," "most appropriate." Read every word carefully.</p>
                            </div>
                            <div class="exam-tip">
                                <h4><i class="fas fa-mouse-pointer"></i> Performance Tasks</h4>
                                <p>These are hands-on Word tasks. Practice common operations: formatting, tables, graphics, references.</p>
                            </div>
                            <div class="exam-tip">
                                <h4><i class="fas fa-check-double"></i> Review Strategy</h4>
                                <p>Flag questions you're unsure about. Use remaining time to review flagged questions.</p>
                            </div>
                        </div>

                        <div class="subsection">
                            <h3><i class="fas fa-list-check"></i> Topics to Review</h3>
                            <div style="column-count: 2; column-gap: 40px;">
                                <ul>
                                    <li>Document creation & management</li>
                                    <li>Text formatting & styles</li>
                                    <li>Paragraph & page formatting</li>
                                    <li>Tables & lists</li>
                                    <li>Images & graphic elements</li>
                                </ul>
                                <ul>
                                    <li>References & citations</li>
                                    <li>Mail merge</li>
                                    <li>Comments & track changes</li>
                                    <li>Document inspection</li>
                                    <li>Accessibility features</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Essential Shortcuts -->
                    <div class="section">
                        <div class="section-title">
                            <i class="fas fa-bolt"></i> 4. Essential Shortcuts for Week 7
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
                                    <td><span class="shortcut-key">F12</span></td>
                                    <td>Save As (quickly save in different format)</td>
                                </tr>
                                <tr>
                                    <td><span class="shortcut-key">Alt + F, I, D</span></td>
                                    <td>Open Document Inspector (via File → Info)</td>
                                </tr>
                                <tr>
                                    <td><span class="shortcut-key">Alt + R, A</span></td>
                                    <td>Open Accessibility Checker</td>
                                </tr>
                                <tr>
                                    <td><span class="shortcut-key">Alt + F, I, C</span></td>
                                    <td>Open Compatibility Checker</td>
                                </tr>
                                <tr>
                                    <td><span class="shortcut-key">Ctrl + F1</span></td>
                                    <td>Collapse/Expand the Ribbon (more space for tasks)</td>
                                </tr>
                                <tr>
                                    <td><span class="shortcut-key">Ctrl + S</span></td>
                                    <td>Quick Save (always save before inspections)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Hands-On Exercise -->
                    <div class="exercise-box">
                        <div class="exercise-title">
                            <i class="fas fa-laptop-code"></i> 5. Step-by-Step Practice Exercise
                        </div>
                        <p><strong>Activity:</strong> Inspect, Clean, and Prepare a Sample Document</p>

                        <div style="margin: 20px 0;">
                            <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                            <ol style="padding-left: 30px;">
                                <li>Open Week7_Sample_Report.docx from the portal.</li>
                                <li>Run the Document Inspector:
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li>File → Info → Inspect Document → Inspect.</li>
                                        <li>Remove all: Document Properties and Personal Information.</li>
                                    </ul>
                                </li>
                                <li>Run the Accessibility Checker:
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li>Review tab → Check Accessibility.</li>
                                        <li>Fix all errors and warnings:
                                            <ul style="margin-top: 5px; padding-left: 20px;">
                                                <li>Add Alt Text to the image.</li>
                                                <li>Ensure table has a header row.</li>
                                                <li>Check color contrast in the chart.</li>
                                            </ul>
                                        </li>
                                    </ul>
                                </li>
                                <li>Run the Compatibility Checker:
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li>File → Info → Check Compatibility.</li>
                                        <li>Note any issues and decide if you need to save in an older format.</li>
                                    </ul>
                                </li>
                                <li>Save the cleaned version as <strong><?php echo htmlspecialchars($this->user_first_name); ?>_Week7_Cleaned.docx</strong>.</li>
                                <li>Take the Week 7 Practice Quiz (available in the portal) to test your knowledge.</li>
                            </ol>
                        </div>

                        <a href="#" class="download-btn" onclick="downloadSample()">
                            <i class="fas fa-download"></i> Download Sample Document
                        </a>
                    </div>

                    <!-- Key Terms -->
                    <div class="key-terms">
                        <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>

                        <div class="term">
                            <strong>Metadata</strong>
                            <p>Hidden data about the document (author, dates, revisions).</p>
                        </div>

                        <div class="term">
                            <strong>Accessibility</strong>
                            <p>Designing documents to be usable by individuals with disabilities.</p>
                        </div>

                        <div class="term">
                            <strong>Compatibility</strong>
                            <p>Ensuring a document works correctly across different versions of Word.</p>
                        </div>

                        <div class="term">
                            <strong>Document Inspector</strong>
                            <p>Tool that finds and removes hidden content and personal info.</p>
                        </div>

                        <div class="term">
                            <strong>Alt Text</strong>
                            <p>Descriptive text added to visuals for screen readers.</p>
                        </div>
                    </div>

                    <!-- Exam Preparation Checklist -->
                    <div class="checklist-box">
                        <div class="checklist-title">
                            <i class="fas fa-clipboard-check"></i> 6. Exam Preparation Checklist
                        </div>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #e65100; margin-bottom: 10px;">Complete Before Your Exam:</h4>
                            <ul>
                                <li>Review all handouts from Weeks 1–6.</li>
                                <li>Complete at least one full-length practice exam.</li>
                                <li>Practice performance-based tasks (available in the portal).</li>
                                <li>Test document inspection, accessibility, and compatibility features.</li>
                                <li>Familiarize yourself with the exam interface (MOS exam demo).</li>
                                <li>Schedule your exam if you haven't already.</li>
                            </ul>
                        </div>

                        <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                            <strong>Exam Submission:</strong> Complete the practice exercise and submit your cleaned document via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                        </div>
                    </div>

                    <!-- Tips for Success -->
                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-lightbulb"></i> 7. Tips for Success
                        </div>
                        <ul>
                            <li><strong>Clean Before Sharing:</strong> Always inspect documents before sending them out.</li>
                            <li><strong>Accessibility First:</strong> Build accessible documents from the start—it's easier than fixing later.</li>
                            <li><strong>Practice Under Pressure:</strong> Time yourself while doing practice exams.</li>
                            <li><strong>Stay Calm:</strong> On exam day, take a deep breath and read each question carefully.</li>
                            <li><strong>Review Flagged Questions:</strong> Use the flag feature to mark questions for review.</li>
                            <li><strong>Check Your Work:</strong> In performance tasks, double-check your work before submitting.</li>
                        </ul>
                    </div>

                    <!-- Additional Resources -->
                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-external-link-alt"></i> Additional Resources
                        </div>
                        <ul>
                            <li><a href="https://support.microsoft.com/word" target="_blank">Microsoft Document Inspector Guide</a></li>
                            <li><a href="https://support.microsoft.com/accessibility" target="_blank">Word Accessibility Checklist</a></li>
                            <li><a href="https://docs.microsoft.com/mos-certification" target="_blank">MOS Exam Preparation Page</a></li>
                            <li><strong>Practice exams and tutorial videos</strong> available in the Course Portal.</li>
                        </ul>
                    </div>

                    <!-- Next Week Preview -->
                    <div class="next-week">
                        <div class="next-week-title">
                            <i class="fas fa-calendar-alt"></i> 8. Next Week Preview
                        </div>
                        <p>In Week 8, we'll host a Mock Exam and Final Review Session. You'll:</p>
                        <ul>
                            <li>Take a simulated MO-100 exam under timed conditions</li>
                            <li>Review answers and explanations</li>
                            <li>Ask final questions before your certification test</li>
                            <li>Receive last-minute tips and strategies</li>
                            <li>Get guidance on scheduling your official exam</li>
                        </ul>
                        <p style="margin-top: 15px;"><strong>Preparation:</strong> Complete all practice exercises and review your weakest areas.</p>
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
                            <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week7.php">Week 7 Discussion</a></li>
                            <li><strong>Microsoft Word Help:</strong> <a href="https://support.microsoft.com/word" target="_blank">Official Support</a></li>
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
                    </div>
                </div>

                <footer>
                    <p>MO-100: Microsoft Word Certification Prep Program – Week 7 Handout</p>
                    <p>Impact Digital Academy • © <?php echo date('Y'); ?> Microsoft Office Certification Program. All rights reserved.</p>
                    <p id="current-date" style="margin-top: 10px; font-size: 0.8rem; color: #888;"></p>
                    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem; color: #888;">
                        <i class="fas fa-exclamation-triangle"></i> This handout is part of the MO-100 Word Certification Prep Program at Impact Digital Academy. Unauthorized distribution is prohibited.
                    </div>
                    <?php if ($user_role === 'student'): ?>
                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                            <i class="fas fa-user-graduate"></i> Student Access - <?php echo htmlspecialchars($user_full_name); ?> (<?php echo htmlspecialchars($user_email); ?>)
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 0.8rem;">
                            <i class="fas fa-chalkboard-teacher"></i> Instructor Access - <?php echo htmlspecialchars($user_full_name); ?> (<?php echo htmlspecialchars($user_email); ?>)
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 10px; font-size: 0.8rem; color: #666;">
                        Instructor: <?php echo htmlspecialchars($instructor_name); ?> | Last Updated: <?php echo date('F j, Y'); ?>
                    </div>
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

                // Simulate sample document download
                function downloadSample() {
                    alert('Sample document would download. This is a demo.');
                    // In production, this would link to a sample file
                    // window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/samples/week7_sample.docx';
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
                        console.log('Word Week 7 handout access logged for: <?php echo htmlspecialchars($user_email); ?>');
                        // In production, send AJAX request to log access
                    }
                });

                // Self-review answers functionality
                document.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && e.key === 'q') {
                        e.preventDefault();
                        const answers = [
                            "1. Document Inspector finds: comments, revisions, document properties (author, dates), headers/footers, hidden text, custom XML data.",
                            "2. Alt Text makes images accessible to screen readers for visually impaired users.",
                            "3. Use Compatibility Checker: File → Info → Check for Issues → Check Compatibility.",
                            "4. Two common accessibility issues: missing Alt Text on images, missing table headers.",
                            "5. Time management strategies: allocate ~1 minute per question, skip difficult questions and return later, review flagged questions at the end."
                        ];
                        alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
                    }
                });

                // Interactive tool demonstration
                document.addEventListener('DOMContentLoaded', function() {
                    const toolItems = document.querySelectorAll('.tool-item');
                    toolItems.forEach(item => {
                        item.addEventListener('click', function() {
                            const title = this.querySelector('h4').textContent;
                            alert(`You clicked on ${title}. See the instructions above for how to use this tool.`);
                        });
                    });

                    // Exam tips interaction
                    const examTips = document.querySelectorAll('.exam-tip');
                    examTips.forEach(tip => {
                        tip.addEventListener('click', function() {
                            const title = this.querySelector('h4').textContent;
                            const content = this.querySelector('p').textContent;
                            alert(`${title}\n\n${content}`);
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
    $viewer = new WordWeek7HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>