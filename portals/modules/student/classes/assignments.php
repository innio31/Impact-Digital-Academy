<?php
// modules/student/classes/assignments.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify student is enrolled in this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e 
        JOIN class_batches cb ON e.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        JOIN users u ON cb.instructor_id = u.id 
        WHERE e.class_id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Handle assignment download
if (isset($_GET['download_assignment']) && is_numeric($_GET['download_assignment'])) {
    $assignment_id = (int)$_GET['download_assignment'];
    
    // Get assignment details and verify student can access it
    $sql = "SELECT a.*, cb.instructor_id, cb.id as class_id
            FROM assignments a 
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN enrollments e ON cb.id = e.class_id 
            WHERE a.id = ? AND e.student_id = ? AND e.class_id = ? 
            AND a.is_published = 1 AND e.status IN ('active', 'completed')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $assignment_id, $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $assignment = $result->fetch_assoc();
        
        if (!empty($assignment['attachment_path']) && !empty($assignment['original_filename'])) {
            $file_path = __DIR__ . '/../../../' . $assignment['attachment_path'];
            $original_name = $assignment['original_filename'];
            
            if (file_exists($file_path)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($original_name) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));
                flush();
                readfile($file_path);
                exit;
            } else {
                $_SESSION['error_message'] = "File not found on server.";
            }
        } else {
            $_SESSION['error_message'] = "No attachment available for this assignment.";
        }
    } else {
        $_SESSION['error_message'] = "Assignment not found or you don't have access to it.";
    }
    $stmt->close();
    
    // Redirect back to assignments page
    header("Location: assignments.php?class_id=$class_id");
    exit();
}

// Handle assignment submission
$submit_success = false;
$submit_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    $assignment_id = intval($_POST['assignment_id'] ?? 0);
    $submission_text = trim($_POST['submission_text'] ?? '');

    // Verify assignment exists and is not past due
    $assignment_sql = "SELECT a.*, COUNT(s.id) as student_submissions
                      FROM assignments a 
                      LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
                      WHERE a.id = ? AND a.class_id = ? AND a.is_published = 1";
    $stmt = $conn->prepare($assignment_sql);
    $stmt->bind_param("iii", $student_id, $assignment_id, $class_id);
    $stmt->execute();
    $assignment_result = $stmt->get_result();

    if ($assignment_result->num_rows === 0) {
        $submit_error = "Assignment not found or not available for submission";
    } else {
        $assignment = $assignment_result->fetch_assoc();

        // Check if assignment is past due
        $due_time = strtotime($assignment['due_date']);
        $now = time();
        $is_late = $now > $due_time;

        // Check if student has already submitted
        if ($assignment['student_submissions'] > 0) {
            $submit_error = "You have already submitted this assignment";
        } elseif ($is_late && $due_time + 86400 < $now) { // Allow 24-hour grace period
            $submit_error = "Submission deadline has passed";
        } elseif (empty($submission_text) && $assignment['submission_type'] === 'text') {
            $submit_error = "Submission text is required";
        } else {
            // Insert submission
            $status = $is_late ? 'late' : 'submitted';

            $insert_sql = "INSERT INTO assignment_submissions 
                          (assignment_id, student_id, submission_text, status, late_submission) 
                          VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iissi", $assignment_id, $student_id, $submission_text, $status, $is_late);

            if ($insert_stmt->execute()) {
                $submission_id = $insert_stmt->insert_id;

                // Handle file uploads
if (isset($_FILES['submission_files']) && $assignment['submission_type'] !== 'text') {
    $file_count = count($_FILES['submission_files']['name']);
    $max_files = $assignment['max_files'];

    if ($file_count > $max_files) {
        $submit_error = "Maximum $max_files files allowed";
    } else {
        $allowed_extensions = !empty($assignment['allowed_extensions']) 
            ? explode(',', str_replace(' ', '', $assignment['allowed_extensions'])) 
            : [];

        // Define upload directory
        $upload_base_dir = realpath(__DIR__ . '/../../../') . '/uploads/assignments/submissions/';
        
        // Ensure directory exists
        if (!file_exists($upload_base_dir)) {
            if (!mkdir($upload_base_dir, 0755, true)) {
                $submit_error = "Upload directory could not be created. Please contact administrator.";
            }
        }
        
        // Check if directory is writable
        if (!is_writable($upload_base_dir)) {
            $submit_error = "Upload directory is not writable. Please check permissions.";
        }

        if (empty($submit_error)) {
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['submission_files']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['submission_files']['name'][$i];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $file_size = $_FILES['submission_files']['size'][$i];
                    $tmp_name = $_FILES['submission_files']['tmp_name'][$i];

                    // Check file type if allowed extensions are specified
                    if (!empty($allowed_extensions) && !in_array($file_ext, $allowed_extensions)) {
                        $submit_error = "File type not allowed: .$file_ext. Allowed types: " . implode(', ', $allowed_extensions);
                        break;
                    }

                    // Generate unique filename
                    $new_filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $file_name);
                    $upload_path = $upload_base_dir . $new_filename;
                    $file_url = 'uploads/assignments/submissions/' . $new_filename;

                    // Move uploaded file
                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        // Save file record
                        $file_sql = "INSERT INTO submission_files 
                                    (submission_id, file_url, file_name, file_type, file_size) 
                                    VALUES (?, ?, ?, ?, ?)";
                        $file_stmt = $conn->prepare($file_sql);
                        if ($file_stmt) {
                            $file_stmt->bind_param("isssi", $submission_id, $file_url, $file_name, $file_ext, $file_size);
                            if (!$file_stmt->execute()) {
                                $submit_error = "Failed to save file record: " . $conn->error;
                                @unlink($upload_path); // Clean up file
                                break;
                            }
                            $file_stmt->close();
                        } else {
                            $submit_error = "Failed to prepare file statement: " . $conn->error;
                            @unlink($upload_path); // Clean up file
                            break;
                        }
                    } else {
                        $error_code = $_FILES['submission_files']['error'][$i];
                        $error_messages = [
                            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
                        ];
                        
                        $submit_error = "Failed to upload file '$file_name': " . 
                                      ($error_messages[$error_code] ?? "Unknown error (code: $error_code)");
                        break;
                    }
                } elseif ($_FILES['submission_files']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    // Handle upload errors (except no file uploaded)
                    $error_code = $_FILES['submission_files']['error'][$i];
                    $error_messages = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
                    ];
                    
                    $submit_error = "Upload error for file #" . ($i + 1) . ": " . 
                                  ($error_messages[$error_code] ?? "Unknown error (code: $error_code)");
                    break;
                }
            }
        }
    }
}

                if (empty($submit_error)) {
                    $submit_success = true;

                    // Log activity
                    logActivity('assignment_submitted', "Submitted assignment #$assignment_id", 'assignment_submissions', $submission_id);
                }
            } else {
                $submit_error = "Failed to submit assignment: " . $conn->error;
            }
            $insert_stmt->close();
        }
    }
    $stmt->close();
}

// Handle filters
$filter_status = $_GET['status'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// Get student's submissions for this class
$submissions_sql = "SELECT s.*, a.title as assignment_title, a.due_date, a.total_points
                   FROM assignment_submissions s 
                   JOIN assignments a ON s.assignment_id = a.id
                   WHERE s.student_id = ? AND a.class_id = ?";
$stmt = $conn->prepare($submissions_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$submissions_result = $stmt->get_result();
$student_submissions = [];
while ($row = $submissions_result->fetch_assoc()) {
    $student_submissions[$row['assignment_id']] = $row;
}
$stmt->close();

// Build query for assignments
$query = "SELECT a.*, 
                 s.id as submission_id,
                 s.status as submission_status,
                 s.grade as submission_grade,
                 s.feedback as submission_feedback,
                 s.submitted_at as submission_date,
                 s.late_submission
          FROM assignments a 
          LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
          WHERE a.class_id = ? AND a.is_published = 1";

$params = [$student_id, $class_id];
$types = "ii";

// Apply status filter
if ($filter_status === 'upcoming') {
    $query .= " AND a.due_date > NOW()";
} elseif ($filter_status === 'due_soon') {
    $query .= " AND a.due_date > NOW() AND a.due_date < DATE_ADD(NOW(), INTERVAL 3 DAY)";
} elseif ($filter_status === 'past_due') {
    $query .= " AND a.due_date < NOW()";
} elseif ($filter_status === 'submitted') {
    $query .= " AND s.id IS NOT NULL";
} elseif ($filter_status === 'not_submitted') {
    $query .= " AND s.id IS NULL AND a.due_date > NOW()";
} elseif ($filter_status === 'graded') {
    $query .= " AND s.grade IS NOT NULL";
} elseif ($filter_status === 'ungraded') {
    $query .= " AND s.id IS NOT NULL AND s.grade IS NULL";
}

// Apply search filter
if (!empty($filter_search)) {
    $query .= " AND (a.title LIKE ? OR a.description LIKE ?)";
    $search_param = "%$filter_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$query .= " ORDER BY a.due_date ASC";

// Get assignments
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();

// Function to get submission status badge
function getSubmissionStatusBadge($assignment, $submission) {
    $now = time();
    $due = strtotime($assignment['due_date']);

    if ($submission && $submission['submission_id']) {
        if ($submission['submission_grade'] !== null) {
            return '<span class="status-badge status-graded">Graded</span>';
        } elseif ($submission['late_submission']) {
            return '<span class="status-badge status-late">Submitted Late</span>';
        } else {
            return '<span class="status-badge status-submitted">Submitted</span>';
        }
    } else {
        if ($due < $now) {
            return '<span class="status-badge status-missing">Missing</span>';
        } elseif ($due - $now < 86400) { // Less than 24 hours
            return '<span class="status-badge status-due-soon">Due Soon</span>';
        } else {
            return '<span class="status-badge status-pending">Not Submitted</span>';
        }
    }
}

// Function to get grade color
function getGradeColor($grade) {
    if ($grade >= 90) return 'grade-excellent';
    if ($grade >= 80) return 'grade-good';
    if ($grade >= 70) return 'grade-average';
    if ($grade >= 60) return 'grade-poor';
    return 'grade-fail';
}

// Function to get submission type label
function getSubmissionTypeLabel($type) {
    $labels = [
        'file' => 'File Upload',
        'text' => 'Text Submission',
        'both' => 'File & Text'
    ];
    return $labels[$type] ?? 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Assignments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --purple: #8b5cf6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            padding-bottom: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .class-info h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .class-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-title h2 {
            font-size: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title p {
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.submitted {
            border-top-color: var(--success);
        }

        .stat-card.missing {
            border-top-color: var(--warning);
        }

        .stat-card.graded {
            border-top-color: var(--info);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px 8px 0 0;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Search and Filter */
        .search-filter {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .clear-filters {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .clear-filters:hover {
            text-decoration: underline;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0d9c6e;
            transform: translateY(-2px);
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #0c8ec8;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: white;
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Assignment Cards */
        .assignments-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .assignment-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .assignment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .assignment-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            position: relative;
        }

        .assignment-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .assignment-due {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .assignment-due.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #e5e7eb;
            color: #374151;
        }

        .status-submitted {
            background: #d1fae5;
            color: #065f46;
        }

        .status-graded {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .status-missing {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-due-soon {
            background: #fef3c7;
            color: #92400e;
        }

        .assignment-body {
            padding: 1.5rem;
        }

        .assignment-description {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .assignment-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .assignment-attachment {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .attachment-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .attachment-details {
            flex: 1;
        }

        .attachment-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .attachment-size {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .assignment-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .grade-display {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .grade-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.25rem;
        }

        .grade-excellent {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-good {
            background: #dbeafe;
            color: #1e40af;
        }

        .grade-average {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-poor {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-fail {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            color: var(--gray);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray);
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f1f5f9;
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-help {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .file-upload-area {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .file-upload-area.dragover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Submission Files */
        .submission-files {
            margin-top: 1rem;
        }

        .file-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-icon {
            color: var(--primary);
        }

        .feedback-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .feedback-content {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .back-button:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .assignment-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .assignment-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .search-input {
                min-width: 100%;
            }

            .tabs {
                justify-content: center;
            }

            .tab {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Assignments</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?> - <?php echo htmlspecialchars($class['program_name']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-book"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Quizzes
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-users"></i> Classmates
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i> Join Class
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>
                    <i class="fas fa-tasks"></i>
                    Assignments
                </h2>
                <p>View and submit assignments for <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="stats">
                <span><i class="fas fa-file-alt"></i> <?php echo count($assignments); ?> assignments</span>
                <?php
                $submitted_count = count(array_filter($assignments, fn($a) => $a['submission_id']));
                $graded_count = count(array_filter($assignments, fn($a) => $a['submission_grade'] !== null));
                ?>
                <span><i class="fas fa-check-circle"></i> <?php echo $submitted_count; ?> submitted</span>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($submit_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Assignment submitted successfully!</strong>
                    <p style="margin-top: 0.25rem;">Your submission has been received and is awaiting grading.</p>
                </div>
            </div>
        <?php elseif ($submit_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Submission failed!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($submit_error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Error!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
                </div>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                </div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $total_assignments = count($assignments);
        $submitted_count = count(array_filter($assignments, fn($a) => $a['submission_id']));
        $missing_count = count(array_filter($assignments, fn($a) => !$a['submission_id'] && strtotime($a['due_date']) < time()));
        $graded_count = count(array_filter($assignments, fn($a) => $a['submission_grade'] !== null));
        ?>
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $total_assignments; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card submitted">
                <div class="stat-value"><?php echo $submitted_count; ?></div>
                <div class="stat-label">Submitted</div>
            </div>
            <div class="stat-card missing">
                <div class="stat-value"><?php echo $missing_count; ?></div>
                <div class="stat-label">Missing</div>
            </div>
            <div class="stat-card graded">
                <div class="stat-value"><?php echo $graded_count; ?></div>
                <div class="stat-label">Graded</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=all"
                class="tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Assignments
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=upcoming"
                class="tab <?php echo $filter_status === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> Upcoming
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=due_soon"
                class="tab <?php echo $filter_status === 'due_soon' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Due Soon
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=submitted"
                class="tab <?php echo $filter_status === 'submitted' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Submitted
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=graded"
                class="tab <?php echo $filter_status === 'graded' ? 'active' : ''; ?>">
                <i class="fas fa-star"></i> Graded
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=missing"
                class="tab <?php echo $filter_status === 'missing' ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle"></i> Missing
            </a>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> Search Assignments</h3>
                <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                    <a href="?class_id=<?php echo $class_id; ?>" class="clear-filters">
                        Clear All
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="" class="search-form" id="filterForm">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search assignments by title or description..."
                    value="<?php echo htmlspecialchars($filter_search); ?>"
                    id="searchInput">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if (!empty($filter_search) || $filter_status !== 'all'): ?>
                    <a href="?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Assignments List -->
        <?php if (empty($assignments)): ?>
            <div class="empty-state">
                <i class="fas fa-tasks"></i>
                <h3>No Assignments Found</h3>
                <p>
                    <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                        No assignments match your current filters. <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">Clear filters</a> to see all assignments.
                    <?php else: ?>
                        No assignments have been published for this class yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="assignments-grid">
                <?php foreach ($assignments as $assignment): ?>
                    <div class="assignment-card">
                        <div class="assignment-header">
                            <div class="assignment-title">
                                <span><?php echo htmlspecialchars($assignment['title']); ?></span>
                                <?php echo getSubmissionStatusBadge($assignment, $assignment); ?>
                            </div>
                            <div class="assignment-due <?php echo strtotime($assignment['due_date']) < time() && !$assignment['submission_id'] ? 'overdue' : ''; ?>">
                                <i class="fas fa-calendar-alt"></i>
                                Due: <?php echo date('M d, Y g:i A', strtotime($assignment['due_date'])); ?>
                                <?php if ($assignment['late_submission']): ?>
                                    <span style="color: var(--warning); margin-left: 0.5rem;">
                                        <i class="fas fa-exclamation-circle"></i> Submitted Late
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-meta">
                                <div class="meta-item">
                                    <i class="fas fa-star"></i>
                                    <?php echo $assignment['total_points']; ?> points
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-file-upload"></i>
                                    <?php echo getSubmissionTypeLabel($assignment['submission_type']); ?>
                                </div>
                                <?php if ($assignment['submission_date']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-paper-plane"></i>
                                        Submitted: <?php echo date('M d, Y g:i A', strtotime($assignment['submission_date'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="assignment-body">
                            <?php if (!empty($assignment['description'])): ?>
                                <div class="assignment-description">
                                    <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($assignment['instructions'])): ?>
                                <div class="assignment-description">
                                    <strong>Instructions:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Display assignment attachment if available -->
                            <?php if (!empty($assignment['has_attachment']) && !empty($assignment['attachment_path'])): ?>
                                <div class="assignment-attachment">
                                    <div class="attachment-info">
                                        <div class="attachment-icon">
                                            <i class="<?php echo getFileIcon($assignment['original_filename']); ?>"></i>
                                        </div>
                                        <div class="attachment-details">
                                            <div class="attachment-name">
                                                <?php echo htmlspecialchars($assignment['original_filename']); ?>
                                            </div>
                                            <div class="attachment-size">
                                                Assignment file provided by instructor
                                            </div>
                                        </div>
                                    </div>
                                    <a href="?class_id=<?php echo $class_id; ?>&download_assignment=<?php echo $assignment['id']; ?>" 
                                       class="btn btn-primary btn-small">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if ($assignment['submission_grade'] !== null): ?>
                                <div class="feedback-section">
                                    <h4>Feedback & Grade</h4>
                                    <div class="grade-display">
                                        <div class="grade-circle <?php echo getGradeColor($assignment['submission_grade']); ?>">
                                            <?php echo $assignment['submission_grade']; ?>%
                                        </div>
                                        <div>
                                            <strong>Grade:</strong> <?php echo $assignment['submission_grade']; ?> / <?php echo $assignment['total_points']; ?><br>
                                            <small>Graded on: <?php echo date('M d, Y', strtotime($assignment['submission_date'])); ?></small>
                                        </div>
                                    </div>

                                    <?php if (!empty($assignment['submission_feedback'])): ?>
                                        <div class="feedback-content">
                                            <strong>Instructor Feedback:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($assignment['submission_feedback'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($assignment['submission_id']): ?>
                                <div class="submission-files">
                                    <h4>Your Submission</h4>
                                    <?php if (!empty($assignment['submission_text'])): ?>
                                        <div class="feedback-content">
                                            <strong>Your Text Submission:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($assignment['submission_text'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="assignment-footer">
                            <div>
                                <?php if ($assignment['submission_grade'] !== null): ?>
                                    <span style="color: var(--success);">
                                        <i class="fas fa-check-circle"></i> Graded
                                    </span>
                                <?php elseif ($assignment['submission_id']): ?>
                                    <span style="color: var(--info);">
                                        <i class="fas fa-clock"></i> Awaiting Grade
                                    </span>
                                <?php elseif (strtotime($assignment['due_date']) < time()): ?>
                                    <span style="color: var(--danger);">
                                        <i class="fas fa-exclamation-circle"></i> Past Due
                                    </span>
                                <?php else: ?>
                                    <?php
                                    $time_left = strtotime($assignment['due_date']) - time();
                                    $days_left = floor($time_left / (60 * 60 * 24));
                                    $hours_left = floor(($time_left % (60 * 60 * 24)) / (60 * 60));

                                    if ($days_left > 0) {
                                        echo "<span style='color: var(--warning);'><i class='fas fa-clock'></i> Due in $days_left days</span>";
                                    } else {
                                        echo "<span style='color: var(--warning);'><i class='fas fa-clock'></i> Due in $hours_left hours</span>";
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <?php if (!$assignment['submission_id'] && strtotime($assignment['due_date']) > time()): ?>
                                    <button class="btn btn-primary"
                                        onclick="openSubmitModal(<?php echo $assignment['id']; ?>)">
                                        <i class="fas fa-paper-plane"></i> Submit
                                    </button>
                                <?php elseif ($assignment['submission_id'] && !$assignment['submission_grade']): ?>
                                    <span class="btn btn-secondary">
                                        <i class="fas fa-clock"></i> Submitted
                                    </span>
                                <?php elseif ($assignment['submission_grade'] !== null): ?>
                                    <span class="btn btn-success">
                                        <i class="fas fa-check"></i> Graded
                                    </span>
                                <?php else: ?>
                                    <span class="btn btn-danger">
                                        <i class="fas fa-times"></i> Missing
                                    </span>
                                <?php endif; ?>
                                
                                <!-- View assignment file button -->
                                <?php if (!empty($assignment['has_attachment']) && !empty($assignment['attachment_path'])): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&download_assignment=<?php echo $assignment['id']; ?>" 
                                       class="btn btn-info btn-small">
                                        <i class="fas fa-file-download"></i> Assignment File
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <!-- Submit Assignment Modal -->
    <div id="submitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane"></i> Submit Assignment</h3>
                <button class="modal-close" onclick="closeSubmitModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="submitForm">
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="assignment_id" id="submit_assignment_id">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="modal-body">
                    <div id="submitModalContent">
                        <!-- Content will be loaded via JavaScript -->
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary);"></i>
                            <p>Loading assignment details...</p>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openSubmitModal(assignmentId) {
            document.getElementById('submit_assignment_id').value = assignmentId;
            document.getElementById('submitModal').classList.add('show');
            document.body.style.overflow = 'hidden';

            // Load assignment details via AJAX
            loadAssignmentDetails(assignmentId);
        }

        function closeSubmitModal() {
            document.getElementById('submitModal').classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('submitModalContent').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary);"></i>
                    <p>Loading assignment details...</p>
                </div>
            `;
        }

        function loadAssignmentDetails(assignmentId) {
            // In a real implementation, this would be an AJAX call
            // For now, we'll simulate with a timeout
            setTimeout(() => {
                // Simulate assignment data
                const assignmentData = {
                    id: assignmentId,
                    title: "Sample Assignment",
                    submission_type: "both",
                    max_files: 3,
                    allowed_extensions: "pdf,doc,docx,jpg,jpeg,png",
                    due_date: "<?php echo date('Y-m-d H:i:s', strtotime('+3 days')); ?>"
                };

                const dueDate = new Date(assignmentData.due_date);
                const now = new Date();
                const isLate = now > dueDate;

                let modalContent = `
                    <h4>${assignmentData.title}</h4>
                    <p style="color: var(--gray); font-size: 0.875rem; margin-bottom: 1rem;">
                        <i class="fas fa-calendar-alt"></i> Due: ${dueDate.toLocaleDateString()} ${dueDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                        ${isLate ? '<span style="color: var(--warning); margin-left: 0.5rem;"><i class="fas fa-exclamation-circle"></i> Late Submission</span>' : ''}
                    </p>
                    
                    <div class="form-group">
                        <label for="submission_text">Submission Text</label>
                        <textarea id="submission_text" name="submission_text" class="form-control" rows="6"
                                  placeholder="Type your submission here..."></textarea>
                        <div class="form-help">Required for text submissions</div>
                    </div>
                `;

                if (assignmentData.submission_type !== 'text') {
                    modalContent += `
                        <div class="form-group">
                            <label for="submission_files">Upload Files</label>
                            <div class="file-upload-area" id="fileDropArea" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary); margin-bottom: 1rem;"></i>
                                <p>Click to select files or drag and drop</p>
                                <p class="form-help">Maximum ${assignmentData.max_files} files allowed</p>
                                <p class="form-help">Allowed types: ${assignmentData.allowed_extensions}</p>
                                <input type="file" id="fileInput" name="submission_files[]" multiple 
                                       style="display: none;" onchange="updateFileList()">
                                <div id="fileList" class="file-list" style="margin-top: 1rem;"></div>
                            </div>
                        </div>
                    `;
                }

                modalContent += `
                    <div class="alert" style="background: #fef3c7; color: #92400e;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Important:</strong> Once submitted, you cannot edit your submission unless your instructor allows resubmission.
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeSubmitModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Assignment
                        </button>
                    </div>
                `;

                document.getElementById('submitModalContent').innerHTML = modalContent;

                // Initialize file upload functionality
                if (assignmentData.submission_type !== 'text') {
                    initializeFileUpload();
                }

            }, 500);
        }

        // File upload functionality
        function initializeFileUpload() {
            const dropArea = document.getElementById('fileDropArea');
            const fileInput = document.getElementById('fileInput');

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });

            function highlight() {
                dropArea.classList.add('dragover');
            }

            function unhighlight() {
                dropArea.classList.remove('dragover');
            }

            dropArea.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;
                updateFileList();
            }
        }

        function updateFileList() {
            const fileInput = document.getElementById('fileInput');
            const fileList = document.getElementById('fileList');

            if (!fileList) return;

            fileList.innerHTML = '';

            if (fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                    const file = fileInput.files[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.innerHTML = `
                        <div class="file-info">
                            <i class="fas fa-file ${getFileIcon(file.type)}"></i>
                            <span>${file.name} (${formatFileSize(file.size)})</span>
                        </div>
                        <button type="button" class="btn btn-danger btn-small" onclick="removeFile(${i})">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    fileList.appendChild(fileItem);
                }
            }
        }

        function removeFile(index) {
            const fileInput = document.getElementById('fileInput');
            const files = Array.from(fileInput.files);
            files.splice(index, 1);

            const newFileList = new DataTransfer();
            files.forEach(file => newFileList.items.add(file));
            fileInput.files = newFileList.files;

            updateFileList();
        }

        function getFileIcon(fileType) {
            if (fileType.includes('pdf')) return 'fa-file-pdf text-danger';
            if (fileType.includes('word') || fileType.includes('document')) return 'fa-file-word text-primary';
            if (fileType.includes('image')) return 'fa-file-image text-info';
            if (fileType.includes('zip') || fileType.includes('compressed')) return 'fa-file-archive text-warning';
            return 'fa-file text-secondary';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Close modal on outside click
        document.addEventListener('click', function(event) {
            const submitModal = document.getElementById('submitModal');
            if (event.target === submitModal) {
                closeSubmitModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSubmitModal();
            }
        });

        // Form submission handling
        document.getElementById('submitForm')?.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('fileInput');
            const submissionText = document.getElementById('submission_text');
            const assignmentType = "<?php echo $assignment['submission_type'] ?? 'file'; ?>";

            if (assignmentType === 'text' && (!submissionText || submissionText.value.trim() === '')) {
                e.preventDefault();
                alert('Please provide a text submission.');
                return;
            }

            if (fileInput && fileInput.files.length === 0 && assignmentType === 'file') {
                e.preventDefault();
                alert('Please upload at least one file.');
                return;
            }

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            submitBtn.disabled = true;
        });

        // Search with Enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('filterForm').submit();
            }
        });

        // Debounced search for better UX
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    document.getElementById('filterForm').submit();
                }
            }, 500);
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }

            // Ctrl/Cmd + / to clear filters
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                window.location.href = 'assignments.php?class_id=<?php echo $class_id; ?>';
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Show success message temporarily
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }

            // Update due soon status in real-time
            setInterval(() => {
                document.querySelectorAll('.assignment-due').forEach(el => {
                    const dueText = el.textContent.match(/Due: (.+)/);
                    if (dueText) {
                        const dueDate = new Date(dueText[1]);
                        const now = new Date();
                        const hoursLeft = (dueDate - now) / (1000 * 60 * 60);

                        if (hoursLeft < 24 && hoursLeft > 0) {
                            el.classList.add('overdue');
                        }
                    }
                });
            }, 60000); // Update every minute
        });
    </script>
</body>
</html>