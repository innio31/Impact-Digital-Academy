<?php
// modules/shared/course_materials/MSWord/word_week6_view.php

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
 * Word Week 6 Handout Viewer Class with PDF Download
 */
class WordWeek6HandoutViewer
{
    private $conn;
    private $user_id;
    private $user_role;
    private $class_id;
    private $allowed_roles = ['student', 'instructor'];
    
    // User details from database
    private $user_email;
    private $user_name;
    private $instructor_name;
    private $instructor_email;
    private $instructor_id;
    
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
        $sql = "SELECT email, CONCAT(first_name, ' ', last_name) as full_name 
                FROM users 
                WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $this->user_email = $row['email'];
                $this->user_name = $row['full_name'];
            } else {
                $this->user_email = 'Unknown User';
                $this->user_name = 'Unknown User';
            }
            $stmt->close();
        }
    }
    
    /**
     * Load instructor details from database
     */
    private function loadInstructorDetails(): void
    {
        if ($this->class_id !== null) {
            // Get instructor for specific class
            $sql = "SELECT u.id, u.email, CONCAT(u.first_name, ' ', u.last_name) as full_name
                    FROM class_batches cb
                    JOIN users u ON cb.instructor_id = u.id
                    WHERE cb.id = ?";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $this->class_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $this->instructor_id = $row['id'];
                    $this->instructor_email = $row['email'];
                    $this->instructor_name = $row['full_name'];
                } else {
                    // Fallback to default instructor
                    $this->getDefaultInstructor();
                }
                $stmt->close();
            } else {
                $this->getDefaultInstructor();
            }
        } else {
            // Get default Word program instructor
            $this->getDefaultInstructor();
        }
    }
    
    /**
     * Get default instructor for Word courses
     */
    private function getDefaultInstructor(): void
    {
        // Try to get any instructor teaching Word courses
        $sql = "SELECT DISTINCT u.id, u.email, CONCAT(u.first_name, ' ', u.last_name) as full_name
                FROM class_batches cb
                JOIN users u ON cb.instructor_id = u.id
                JOIN courses c ON cb.course_id = c.id
                WHERE c.title LIKE '%Microsoft Word (Office 2019)%'
                AND u.role = 'instructor'
                LIMIT 1";
        
        $result = $this->conn->query($sql);
        
        if ($row = $result->fetch_assoc()) {
            $this->instructor_id = $row['id'];
            $this->instructor_email = $row['email'];
            $this->instructor_name = $row['full_name'];
        } else {
            // Fallback to admin or default
            $this->instructor_name = 'Your Instructor';
            $this->instructor_email = 'instructor@impactdigitalacademy.com';
            $this->instructor_id = null;
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
        if (!isset($_GET['download']) || $_GET['download'] !== 'pdf') {
            return;
        }
        
        $autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        } else {
            $mpdfPath = __DIR__ . '/../../../../vendor/mpdf/mpdf/src/Mpdf.php';
            if (file_exists($mpdfPath)) {
                require_once $mpdfPath;
            } else {
                $this->showPDFError();
                return;
            }
        }
        
        $htmlContent = $this->getHTMLContentForPDF();
        
        try {
            if (version_compare(PHP_VERSION, '7.1.0') < 0) {
                throw new Exception('PHP 7.1.0 or higher is required for mPDF 8+. Your PHP version: ' . PHP_VERSION);
            }
            
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
            
            try {
                $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            } catch (Exception $e) {
                try {
                    $mpdf = new \mPDF($mpdfConfig);
                } catch (Exception $e2) {
                    throw new Exception('Could not initialize mPDF. Please check mPDF installation.');
                }
            }
            
            $mpdf->SetTitle('Week 6: Managing Document Collaboration');
            $mpdf->SetAuthor('Impact Digital Academy');
            $mpdf->SetCreator('Impact Digital Academy LMS');
            $mpdf->SetSubject('MO-100 Word Certification Preparation');
            $mpdf->SetKeywords('Microsoft Word, MO-100, Collaboration, Comments, Track Changes, Review');
            
            $mpdf->WriteHTML($this->getPDFCoverPage());
            $mpdf->AddPage();
            
            $mpdf->SetHTMLHeader($this->getPDFHeader());
            $mpdf->SetHTMLFooter($this->getPDFFooter());
            
            $mpdf->WriteHTML($htmlContent);
            
            $filename = 'Word_Week6_Collaboration_' . date('Y-m-d') . '.pdf';
            
            if (ob_get_length()) {
                ob_end_clean();
            }
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            
            $mpdf->Output($filename, 'D');
            exit();
            
        } catch (\Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
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
        ?>
        <div style="font-family: Arial, sans-serif; line-height: 1.6; font-size: 12pt;">
            <h1 style="color: #0d3d8c; border-bottom: 2px solid #0d3d8c; padding-bottom: 10px; font-size: 18pt;">
                Week 6: Managing Document Collaboration
            </h1>
            
            <!-- Welcome Section -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Welcome to Week 6!</h2>
                <p style="margin-bottom: 15px;">
                    This week, we'll focus on the collaborative features of Microsoft Word, which are essential for teamwork, editing, and review processes. You'll learn how to add and manage comments, track changes, and review edits made by others. These skills are crucial for professional environments where documents are often shared, edited by multiple people, and finalized through a review cycle—and they are key components of the MO-100 exam.
                </p>
            </div>
            
            <!-- Learning Objectives -->
            <div style="background: #f0f7ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Learning Objectives</h3>
                <p style="font-weight: bold; margin-bottom: 10px;">By the end of this week, you will be able to:</p>
                <ul style="margin-bottom: 0;">
                    <li>Add, review, reply to, resolve, and delete comments in a document.</li>
                    <li>Enable and use Track Changes to monitor edits.</li>
                    <li>Review tracked changes, and accept or reject them.</li>
                    <li>Lock and unlock change tracking to control editing permissions.</li>
                    <li>Understand the workflow for collaborative document review.</li>
                </ul>
            </div>
            
            <!-- Key Topics -->
            <div style="margin-bottom: 25px;">
                <h2 style="color: #185abd; font-size: 16pt;">Key Topics Covered</h2>
                
                <h3 style="color: #0d3d8c; font-size: 14pt;">1. Adding and Managing Comments</h3>
                <p><strong>Add a Comment:</strong></p>
                <ul>
                    <li>Select text or place cursor → Review tab → New Comment (Ctrl + Alt + M).</li>
                    <li>Type your comment in the markup area.</li>
                </ul>
                
                <p><strong>Review and Reply to Comments:</strong></p>
                <ul>
                    <li>Click a comment to read it.</li>
                    <li>Click Reply in the comment bubble to respond.</li>
                    <li>Use Review tab → Next and Previous to navigate through comments.</li>
                </ul>
                
                <p><strong>Resolve Comments:</strong></p>
                <ul>
                    <li>Once addressed, click Resolve to gray out the comment (keeps it visible).</li>
                </ul>
                
                <p><strong>Delete Comments:</strong></p>
                <ul>
                    <li>Right-click comment → Delete Comment.</li>
                    <li>Or use Review tab → Delete (delete one, all, or all shown).</li>
                </ul>
                
                <h3 style="color: #0d3d8c; margin-top: 20px; font-size: 14pt;">2. Managing Change Tracking</h3>
                <p><strong>Turn Track Changes On/Off:</strong></p>
                <ul>
                    <li>Review tab → Track Changes (Ctrl + Shift + E).</li>
                    <li>When on, all edits (additions, deletions, formatting) are recorded.</li>
                </ul>
                
                <p><strong>Choose What to Display:</strong></p>
                <ul>
                    <li>Review tab → Display for Review:</li>
                    <li style="margin-left: 20px;">• Simple Markup: Shows final document with red lines for changes.</li>
                    <li style="margin-left: 20px;">• All Markup: Shows all edits and comments inline.</li>
                    <li style="margin-left: 20px;">• No Markup: Hides edits; shows final version.</li>
                    <li style="margin-left: 20px;">• Original: Shows document before changes.</li>
                </ul>
                
                <p><strong>Review Tracked Changes:</strong></p>
                <ul>
                    <li>Use Review tab → Next and Previous to jump between changes.</li>
                    <li>Hover over a change to see editor details.</li>
                </ul>
                
                <p><strong>Accept or Reject Changes:</strong></p>
                <ul>
                    <li>Accept: Apply the change permanently.</li>
                    <li>Reject: Remove the change, reverting to original text.</li>
                    <li>Options: Accept/Reject one change, all changes in document, or all changes from a specific reviewer.</li>
                </ul>
                
                <p><strong>Lock and Unlock Change Tracking:</strong></p>
                <ul>
                    <li>Review tab → Track Changes → Lock Tracking.</li>
                    <li>Set a password to prevent others from turning Track Changes off.</li>
                    <li>Ensures all edits are recorded in sensitive or regulated environments.</li>
                </ul>
            </div>
            
            <!-- Practice Exercise -->
            <div style="background: #e8f0ff; padding: 15px; border-left: 5px solid #185abd; margin-bottom: 25px;">
                <h3 style="color: #0d3d8c; margin-top: 0; font-size: 14pt;">Step-by-Step Practice Exercise</h3>
                <p><strong>Activity: Collaborative Editing Simulation</strong></p>
                <p>Follow these steps to practice collaboration features:</p>
                <ol>
                    <li>Open the sample document Week6_Collaboration_Exercise.docx from the portal.</li>
                    <li>Add three comments:
                        <ul>
                            <li>Comment 1: Highlight the first paragraph and ask, "Is this data accurate?"</li>
                            <li>Comment 2: Select a heading and suggest "Consider a more descriptive title."</li>
                            <li>Comment 3: Place a general comment at the end: "Please review formatting."</li>
                        </ul>
                    </li>
                    <li>Turn on Track Changes (Ctrl + Shift + E).</li>
                    <li>Make the following edits:
                        <ul>
                            <li>Delete one sentence.</li>
                            <li>Add a new sentence.</li>
                            <li>Change a word (e.g., "good" to "excellent").</li>
                        </ul>
                    </li>
                    <li>Switch between markup views (Simple, All, No Markup, Original) to see differences.</li>
                    <li>Reply to the first comment with "Data verified from 2023 report."</li>
                    <li>Resolve the second comment.</li>
                    <li>Review the tracked changes:
                        <ul>
                            <li>Accept the added sentence.</li>
                            <li>Reject the deleted sentence.</li>
                        </ul>
                    </li>
                    <li>Lock Tracking with a simple password (e.g., "test123").</li>
                    <li>Save as YourName_Week6_Reviewed.docx.</li>
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
                            <td style="padding: 6px 8px;">Ctrl + Alt + M</td>
                            <td style="padding: 6px 8px;">Insert a new comment</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + E</td>
                            <td style="padding: 6px 8px;">Turn Track Changes on/off</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + A</td>
                            <td style="padding: 6px 8px;">Accept a tracked change</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + R</td>
                            <td style="padding: 6px 8px;">Reject a tracked change</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + ></td>
                            <td style="padding: 6px 8px;">Go to next comment/change</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 6px 8px;">Ctrl + Shift + <</td>
                            <td style="padding: 6px 8px;">Go to previous comment/change</td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 8px;">Ctrl + Shift + K</td>
                            <td style="padding: 6px 8px;">Delete comment</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Key Terms -->
            <div style="background: #f9f0ff; padding: 15px; border-radius: 5px; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Key Terms to Remember</h3>
                <div style="margin-bottom: 10px;">
                    <p><strong>Comment:</strong> A note attached to text for feedback, without altering content.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Track Changes:</strong> A feature that records all insertions, deletions, and formatting changes.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Markup:</strong> The visual display of changes and comments in the document.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <p><strong>Simple Markup:</strong> A clean view showing only change indicators.</p>
                </div>
                <div>
                    <p><strong>Lock Tracking:</strong> Password-protecting Track Changes to ensure all edits are recorded.</p>
                </div>
            </div>
            
            <!-- Self-Review -->
            <div style="background: #fff9e6; padding: 15px; border-left: 5px solid #ff9800; margin-bottom: 25px;">
                <h3 style="color: #e65100; margin-top: 0; font-size: 14pt;">Self-Review Questions</h3>
                <ol>
                    <li>What is the difference between "Simple Markup" and "All Markup"?</li>
                    <li>How do you prevent someone from turning off Track Changes?</li>
                    <li>What happens when you "Resolve" a comment vs. "Delete" it?</li>
                    <li>How can you view the document as it will look after all changes are accepted?</li>
                    <li>How do you accept all changes in a document at once?</li>
                </ol>
            </div>
            
            <!-- Exam Focus -->
            <div style="background: #e8f5e9; padding: 15px; border-left: 5px solid #4caf50; margin-bottom: 25px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 14pt;">MO-100 Exam Focus Areas This Week</h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li>Add comments</li>
                    <li>Review and reply to comments</li>
                    <li>Resolve comments</li>
                    <li>Delete comments</li>
                    <li>Track changes</li>
                    <li>Review tracked changes</li>
                    <li>Accept and reject tracked changes</li>
                    <li>Lock and unlock change tracking</li>
                </ul>
            </div>
            
            <!-- Tips -->
            <div style="background: #e3f2fd; padding: 15px; border-left: 5px solid #1976d2; margin-bottom: 25px;">
                <h3 style="color: #1976d2; margin-top: 0; font-size: 14pt;">Tips for Success</h3>
                <ul>
                    <li><strong>Use Simple Markup for Clarity:</strong> When reading, use Simple Markup to avoid clutter.</li>
                    <li><strong>Communicate with Comments:</strong> Be specific and polite in comments to avoid confusion.</li>
                    <li><strong>Review Before Finalizing:</strong> Always review all changes and comments before accepting/rejecting.</li>
                    <li><strong>Lock When Necessary:</strong> Use Lock Tracking for official or legal documents to maintain an edit trail.</li>
                </ul>
            </div>
            
            <!-- Next Week -->
            <div style="background: #f3e5f5; padding: 15px; border-left: 5px solid #7b1fa2; margin-bottom: 25px;">
                <h3 style="color: #7b1fa2; margin-top: 0; font-size: 14pt;">Next Week Preview</h3>
                <p>In Week 7, we'll cover Document Inspection and Final Preparation. You'll learn how to check documents for hidden data, accessibility issues, and compatibility problems, and prepare for the exam with tips and practice questions.</p>
            </div>
            
            <!-- Additional Resources -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #185abd; font-size: 14pt;">Additional Resources</h3>
                <ul>
                    <li>Word Track Changes Tutorial</li>
                    <li>Collaborate in Word with Comments</li>
                    <li>Practice documents and collaboration guides available in the Course Portal.</li>
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
                Week 6 Handout: Managing Document Collaboration
            </h3>
            <div style="margin: 40px 0;">
                <p style="font-size: 14pt; color: #666;">
                    Student: ' . htmlspecialchars($this->user_email) . '
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
            Week 6: Document Collaboration | Impact Digital Academy | ' . date('m/d/Y') . '
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
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week 6: Managing Document Collaboration - Impact Digital Academy</title>
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

        /* Collaboration Demo */
        .collab-demo {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 25px 0;
        }

        .collab-item {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .collab-icon {
            font-size: 3rem;
            color: #185abd;
            margin-bottom: 15px;
        }

        .markup-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .markup-option {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
            background: white;
            transition: transform 0.2s;
        }

        .markup-option:hover {
            transform: translateY(-3px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .markup-option h4 {
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

            .collab-demo {
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
                <strong>Access Granted:</strong> Word Week 6 Handout
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
            <a href="<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/word_week5_view.php?class_id=<?php echo $this->class_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Week 5
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1>Impact Digital Academy</h1>
            <div class="subtitle">MO-100 Word Certification Prep – Week 6 Handout</div>
            <div style="font-size: 1.6rem; margin: 15px 0;">Managing Document Collaboration</div>
            <div class="week-tag">Week 6 of 8</div>
        </div>

        <div class="content">
            <!-- Welcome Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-users"></i> Welcome to Week 6!
                </div>
                <p style="font-size: 1.1rem; line-height: 1.8; margin-bottom: 20px;">
                    This week, we'll focus on the collaborative features of Microsoft Word, which are essential for teamwork, editing, and review processes. You'll learn how to add and manage comments, track changes, and review edits made by others. These skills are crucial for professional environments where documents are often shared, edited by multiple people, and finalized through a review cycle—and they are key components of the MO-100 exam.
                </p>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80"
                        alt="Document Collaboration in Word"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCAxMjAwIDMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzY2NiI+RG9jdW1lbnQgQ29sbGFib3JhdGlvbiBpbiBXb3JkPC90ZXh0Pjwvc3ZnPg=='">
                    <div class="image-caption">Collaborative Document Editing and Review</div>
                </div>
            </div>

            <!-- Learning Objectives -->
            <div class="learning-objectives">
                <h3><i class="fas fa-bullseye"></i> Learning Objectives</h3>
                <p style="margin-bottom: 15px; font-weight: 600;">By the end of this week, you will be able to:</p>
                <ul>
                    <li>Add, review, reply to, resolve, and delete comments in a document.</li>
                    <li>Enable and use Track Changes to monitor edits.</li>
                    <li>Review tracked changes, and accept or reject them.</li>
                    <li>Lock and unlock change tracking to control editing permissions.</li>
                    <li>Understand the workflow for collaborative document review.</li>
                </ul>
            </div>

            <!-- Exam Focus Areas -->
            <div class="exam-focus">
                <h3><i class="fas fa-graduation-cap"></i> MO-100 Exam Focus Areas This Week</h3>
                <div style="column-count: 2; column-gap: 40px;">
                    <ul>
                        <li>Add comments</li>
                        <li>Review and reply to comments</li>
                        <li>Resolve comments</li>
                        <li>Delete comments</li>
                    </ul>
                    <ul>
                        <li>Track changes</li>
                        <li>Review tracked changes</li>
                        <li>Accept and reject tracked changes</li>
                        <li>Lock and unlock change tracking</li>
                    </ul>
                </div>
            </div>

            <!-- Section 1: Comments -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-comment"></i> 1. Adding and Managing Comments
                </div>

                <div class="collab-demo">
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <h4>Add Comment</h4>
                        <p>Select text → Review tab → New Comment</p>
                        <p><span class="shortcut-key">Ctrl + Alt + M</span></p>
                    </div>
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-reply"></i>
                        </div>
                        <h4>Reply to Comment</h4>
                        <p>Click comment → Reply button</p>
                        <p>Threaded conversation</p>
                    </div>
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <h4>Resolve Comment</h4>
                        <p>Click comment → Resolve</p>
                        <p>Keeps history visible</p>
                    </div>
                </div>

                <div class="collab-demo">
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-trash"></i>
                        </div>
                        <h4>Delete Comment</h4>
                        <p>Right-click → Delete Comment</p>
                        <p>Review tab → Delete</p>
                    </div>
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <h4>Navigate Comments</h4>
                        <p>Review tab → Next/Previous</p>
                        <p><span class="shortcut-key">Ctrl + Shift + ></span></p>
                    </div>
                    <div class="collab-item">
                        <div class="collab-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4>Multiple Reviewers</h4>
                        <p>Different colors per person</p>
                        <p>Hover to see reviewer name</p>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lightbulb"></i> Pro Tips for Comments</h3>
                    <ul>
                        <li><strong>Be Specific:</strong> Reference exact text or location in your comments.</li>
                        <li><strong>Use Threads:</strong> Reply to existing comments to keep conversations organized.</li>
                        <li><strong>@Mention Teammates:</strong> Type @ followed by name to notify specific people (in Word for Microsoft 365).</li>
                        <li><strong>Resolve vs Delete:</strong> Resolve keeps history; Delete removes completely.</li>
                    </ul>
                </div>
            </div>

            <!-- Section 2: Track Changes -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-exchange-alt"></i> 2. Managing Change Tracking
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-toggle-on"></i> A. Enable/Disable Track Changes</h3>
                    <ul>
                        <li><strong>Review tab → Track Changes</strong> toggle</li>
                        <li><strong>Shortcut:</strong> <span class="shortcut-key">Ctrl + Shift + E</span></li>
                        <li><strong>Status Indicator:</strong> Shows "Track Changes: On" in status bar</li>
                        <li><strong>Auto-record:</strong> All edits are recorded when enabled</li>
                    </ul>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-eye"></i> B. Markup Display Options</h3>
                    
                    <div class="markup-options">
                        <div class="markup-option">
                            <h4>Simple Markup</h4>
                            <p>Red lines in margin</p>
                            <p>Clean view</p>
                        </div>
                        <div class="markup-option">
                            <h4>All Markup</h4>
                            <p>Inline changes</p>
                            <p>Full detail</p>
                        </div>
                        <div class="markup-option">
                            <h4>No Markup</h4>
                            <p>Final version</p>
                            <p>No changes shown</p>
                        </div>
                        <div class="markup-option">
                            <h4>Original</h4>
                            <p>Before changes</p>
                            <p>Original text</p>
                        </div>
                    </div>
                    
                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                            alt="Track Changes Markup Views"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5UcmFjayBDaGFuZ2VzIE1hcmt1cCBWaWV3czwvdGV4dD48L3N2Zz4n'">
                        <div class="image-caption">Comparing Simple vs All Markup Views</div>
                    </div>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-check-circle"></i> C. Accept/Reject Changes</h3>
                    <table class="demo-table">
                        <thead>
                            <tr class="header-row">
                                <th>Action</th>
                                <th>Method</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Accept Single</strong></td>
                                <td>Click change → Review → Accept</td>
                                <td>Change becomes permanent</td>
                            </tr>
                            <tr>
                                <td><strong>Reject Single</strong></td>
                                <td>Click change → Review → Reject</td>
                                <td>Change is removed</td>
                            </tr>
                            <tr>
                                <td><strong>Accept All</strong></td>
                                <td>Review → Accept → Accept All</td>
                                <td>All changes applied</td>
                            </tr>
                            <tr>
                                <td><strong>Reject All</strong></td>
                                <td>Review → Reject → Reject All</td>
                                <td>All changes removed</td>
                            </tr>
                            <tr>
                                <td><strong>By Reviewer</strong></td>
                                <td>Show Markup → Reviewers → select</td>
                                <td>Filter by person</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="subsection">
                    <h3><i class="fas fa-lock"></i> D. Lock Tracking</h3>
                    <ul>
                        <li><strong>Purpose:</strong> Prevent unauthorized turning off of Track Changes</li>
                        <li><strong>Method:</strong> Review → Track Changes → Lock Tracking</li>
                        <li><strong>Password:</strong> Set password (required to unlock)</li>
                        <li><strong>Use Cases:</strong> Legal documents, official reviews, compliance</li>
                    </ul>

                    <div class="tip-box">
                        <div class="tip-title">
                            <i class="fas fa-shield-alt"></i> Security Tip
                        </div>
                        <p>Always use Lock Tracking for sensitive documents. This ensures an audit trail and prevents tampering with the change history.</p>
                    </div>
                </div>
            </div>

            <!-- Essential Shortcuts -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-bolt"></i> 3. Essential Shortcuts for Week 6
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
                            <td><span class="shortcut-key">Ctrl + Alt + M</span></td>
                            <td>Insert a new comment</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + E</span></td>
                            <td>Turn Track Changes on/off</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + A</span></td>
                            <td>Accept a tracked change</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + R</span></td>
                            <td>Reject a tracked change</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + ></span></td>
                            <td>Go to next comment/change</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + <</span></td>
                            <td>Go to previous comment/change</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Ctrl + Shift + K</span></td>
                            <td>Delete comment</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + R, G, A</span></td>
                            <td>Accept all changes</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + R, G, R</span></td>
                            <td>Reject all changes</td>
                        </tr>
                        <tr>
                            <td><span class="shortcut-key">Alt + R, C</span></td>
                            <td>New comment (ribbon)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hands-On Exercise -->
            <div class="exercise-box">
                <div class="exercise-title">
                    <i class="fas fa-laptop-code"></i> 4. Step-by-Step Practice Exercise
                </div>
                <p><strong>Activity:</strong> Collaborative Editing Simulation</p>

                <div style="margin: 20px 0;">
                    <h4 style="color: #0d3d8c; margin-bottom: 15px;">Follow these steps:</h4>
                    <ol style="padding-left: 30px;">
                        <li>Open the sample document <strong>Week6_Collaboration_Exercise.docx</strong> from the portal.</li>
                        <li>Add three comments:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Comment 1: Highlight the first paragraph and ask, "Is this data accurate?"</li>
                                <li>Comment 2: Select a heading and suggest "Consider a more descriptive title."</li>
                                <li>Comment 3: Place a general comment at the end: "Please review formatting."</li>
                            </ul>
                        </li>
                        <li>Turn on Track Changes (<span class="shortcut-key">Ctrl + Shift + E</span>).</li>
                        <li>Make the following edits:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Delete one sentence.</li>
                                <li>Add a new sentence.</li>
                                <li>Change a word (e.g., "good" to "excellent").</li>
                            </ul>
                        </li>
                        <li>Switch between markup views (Simple, All, No Markup, Original) to see differences.</li>
                        <li>Reply to the first comment with "Data verified from 2023 report."</li>
                        <li>Resolve the second comment.</li>
                        <li>Review the tracked changes:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li>Accept the added sentence.</li>
                                <li>Reject the deleted sentence.</li>
                            </ul>
                        </li>
                        <li>Lock Tracking with a simple password (e.g., "test123").</li>
                        <li>Save as <strong>YourName_Week6_Reviewed.docx</strong>.</li>
                    </ol>
                </div>

                <div class="image-container">
                    <img src="https://images.unsplash.com/photo-1545235617-9465d2a55698?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Collaboration Exercise Example"
                        onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIyMDAiIHZpZXdCb3g9IjAgMCA4MDAgMjAwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9IiNmMGYwZjAiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjIwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjY2Ij5Db2xsYWJvcmF0aW9uIEV4ZXJjaXNlIEV4YW1wbGU8L3RleHQ+PC9zdmc+'">
                    <div class="image-caption">Document with Comments and Track Changes</div>
                </div>

                <a href="#" class="download-btn" onclick="downloadExercise()">
                    <i class="fas fa-download"></i> Download Practice Document
                </a>
            </div>

            <!-- Key Terms -->
            <div class="key-terms">
                <h3><i class="fas fa-book"></i> Key Terms to Remember</h3>
                
                <div class="term">
                    <strong>Comment</strong>
                    <p>A note attached to text for feedback, without altering content.</p>
                </div>

                <div class="term">
                    <strong>Track Changes</strong>
                    <p>A feature that records all insertions, deletions, and formatting changes.</p>
                </div>

                <div class="term">
                    <strong>Markup</strong>
                    <p>The visual display of changes and comments in the document.</p>
                </div>

                <div class="term">
                    <strong>Simple Markup</strong>
                    <p>A clean view showing only change indicators in the margin.</p>
                </div>

                <div class="term">
                    <strong>Lock Tracking</strong>
                    <p>Password-protecting Track Changes to ensure all edits are recorded.</p>
                </div>
            </div>

            <!-- Homework Assignment -->
            <div class="homework-box">
                <div class="homework-title">
                    <i class="fas fa-homework"></i> 5. Self-Review Questions
                </div>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: #e65100; margin-bottom: 10px;">Test Your Knowledge:</h4>
                    <ol>
                        <li>What is the difference between "Simple Markup" and "All Markup"?</li>
                        <li>How do you prevent someone from turning off Track Changes?</li>
                        <li>What happens when you "Resolve" a comment vs. "Delete" it?</li>
                        <li>How can you view the document as it will look after all changes are accepted?</li>
                        <li>How do you accept all changes in a document at once?</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.5); border-radius: 5px;">
                    <strong>Homework:</strong> Complete the collaboration exercise and submit your <strong>YourName_Week6_Reviewed.docx</strong> file via the class portal by <?php echo date('l, F j, Y', strtotime('+7 days')); ?>.
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-lightbulb"></i> 6. Tips for Success
                </div>
                <ul>
                    <li><strong>Use Simple Markup for Clarity:</strong> When reading, use Simple Markup to avoid clutter.</li>
                    <li><strong>Communicate with Comments:</strong> Be specific and polite in comments to avoid confusion.</li>
                    <li><strong>Review Before Finalizing:</strong> Always review all changes and comments before accepting/rejecting.</li>
                    <li><strong>Lock When Necessary:</strong> Use Lock Tracking for official or legal documents to maintain an edit trail.</li>
                    <li><strong>Use @Mentions:</strong> In Word for Microsoft 365, use @ to notify specific team members.</li>
                    <li><strong>Set Reviewing Options:</strong> Customize what gets tracked (formatting, moves, etc.).</li>
                </ul>
            </div>

            <!-- Additional Resources -->
            <div class="tip-box">
                <div class="tip-title">
                    <i class="fas fa-external-link-alt"></i> Additional Resources
                </div>
                <ul>
                    <li><a href="https://support.microsoft.com/word/track-changes" target="_blank">Word Track Changes Tutorial</a></li>
                    <li><a href="https://support.microsoft.com/word/comments" target="_blank">Collaborate in Word with Comments</a></li>
                    <li><strong>Practice documents and collaboration guides</strong> available in the Course Portal.</li>
                </ul>
            </div>

            <!-- Next Week Preview -->
            <div class="next-week">
                <div class="next-week-title">
                    <i class="fas fa-calendar-alt"></i> 7. Next Week Preview
                </div>
                <p>In Week 7, we'll cover Document Inspection and Final Preparation. You'll learn to:</p>
                <ul>
                    <li>Check for hidden data and personal information</li>
                    <li>Use the Document Inspector</li>
                    <li>Check accessibility issues</li>
                    <li>Test document compatibility</li>
                    <li>Prepare final documents for sharing</li>
                    <li>Review exam preparation tips</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Preparation:</strong> Bring any documents you'd like to inspect for hidden data.</p>
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
                    <li><strong>Support Forum:</strong> <a href="<?php echo BASE_URL; ?>modules/forum/word-week6.php">Week 6 Discussion</a></li>
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
            <p>MO-100: Microsoft Word Certification Prep Program – Week 6 Handout</p>
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
            
            setTimeout(hidePdfAlert, 5000);
        }

        function hidePdfAlert() {
            document.getElementById('pdfAlert').style.display = 'none';
        }

        // Simulate exercise download
        function downloadExercise() {
            alert('Practice document would download. This is a demo.');
            // In production: window.location.href = '<?php echo BASE_URL; ?>modules/shared/course_materials/MSWord/exercises/week6_collaboration.docx';
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
                console.log('Word Week 6 handout access logged for: <?php echo htmlspecialchars($this->user_email); ?>');
                // In production, send AJAX request to log access
            }
        });

        // Self-review answers functionality
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                const answers = [
                    "1. Simple Markup shows red lines in margin (clean view); All Markup shows all changes and comments inline (detailed view).",
                    "2. Review tab → Track Changes → Lock Tracking → set password. This prevents unauthorized turning off of Track Changes.",
                    "3. Resolve grays out the comment but keeps it visible for history; Delete removes the comment completely.",
                    "4. Use 'No Markup' view (Review tab → Display for Review → No Markup) to see final version.",
                    "5. Review tab → Accept → Accept All Changes in Document (or Ctrl + Shift + A for current change, then select 'Accept All')."
                ];
                alert("Self-Review Answers:\n\n" + answers.join("\n\n"));
            }
        });

        // Interactive demonstration
        document.addEventListener('DOMContentLoaded', function() {
            const markupOptions = document.querySelectorAll('.markup-option');
            markupOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const markupType = this.querySelector('h4').textContent;
                    alert(`${markupType} selected. To apply: Review tab → Display for Review → ${markupType}`);
                });
            });

            const collabItems = document.querySelectorAll('.collab-item');
            collabItems.forEach(item => {
                item.addEventListener('click', function() {
                    const title = this.querySelector('h4').textContent;
                    alert(`You clicked on ${title}. This feature is accessed from the Review tab in Word.`);
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
    $viewer = new WordWeek6HandoutViewer();
    $viewer->display();
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>