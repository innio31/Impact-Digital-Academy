<?php
// modules/instructor/classes/assignments.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/email_functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

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

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, 
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
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

// Handle assignment creation
$create_success = false;
$create_error = '';
$uploaded_file_path = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $due_date = $_POST['due_date'] ?? '';
    $due_time = $_POST['due_time'] ?? '23:59';
    $total_points = floatval($_POST['total_points'] ?? 100);
    $submission_type = $_POST['submission_type'] ?? 'file';
    $max_files = intval($_POST['max_files'] ?? 1);
    $allowed_extensions = trim($_POST['allowed_extensions'] ?? '');
    $has_attachment = isset($_POST['has_attachment']) ? 1 : 0;

    // Validate input
    if (empty($title)) {
        $create_error = "Assignment title is required";
    } elseif (empty($due_date)) {
        $create_error = "Due date is required";
    } elseif (strtotime($due_date . ' ' . $due_time) < time()) {
        $create_error = "Due date cannot be in the past";
    } else {
        // Combine date and time
        $due_datetime = $due_date . ' ' . $due_time . ':00';

        // Handle file upload if attachment is enabled
        $attachment_path = '';
        $original_filename = '';

        if ($has_attachment && isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === 0) {
            $file = $_FILES['assignment_file'];

            // Create upload directory if it doesn't exist
            $upload_dir = __DIR__ . '/../../../uploads/assignments/' . $class_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Generate unique filename
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
            $original_filename = $file['name'];
            $target_path = $upload_dir . $unique_name;

            // Allowed file types (you can customize this)
            $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip', 'rar'];

            if (in_array(strtolower($file_ext), $allowed_types)) {
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $attachment_path = 'uploads/assignments/' . $class_id . '/' . $unique_name;
                } else {
                    $create_error = "Failed to upload file. Please try again.";
                }
            } else {
                $create_error = "File type not allowed. Allowed types: " . implode(', ', $allowed_types);
            }
        } elseif ($has_attachment && (!isset($_FILES['assignment_file']) || $_FILES['assignment_file']['error'] !== 0)) {
            $create_error = "Please select a file to upload";
        }

        if (!$create_error) {
            // Insert into database
            $sql = "INSERT INTO assignments (class_id, instructor_id, title, description, instructions, 
                    due_date, total_points, submission_type, max_files, allowed_extensions,
                    has_attachment, attachment_path, original_filename) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "iissssdsssiss",
                $class_id,
                $instructor_id,
                $title,
                $description,
                $instructions,
                $due_datetime,
                $total_points,
                $submission_type,
                $max_files,
                $allowed_extensions,
                $has_attachment,
                $attachment_path,
                $original_filename
            );

            if ($stmt->execute()) {
                $create_success = true;
                $assignment_id = $stmt->insert_id;

                if ($stmt->execute()) {
                    $create_success = true;
                    $assignment_id = $stmt->insert_id;

                    // Send notifications - ADD THIS BLOCK
                    require_once __DIR__ . '/../../../includes/email_functions.php';

                    $notification_schedule = [
                        'class_id' => $class_id,
                        'instructor_id' => $instructor_id,
                        'title' => $title
                    ];

                    sendNewAssignmentNotifications($conn, $assignment_id, $notification_schedule);

                    // Log activity
                    logActivity('assignment_created', "Created assignment: $title", 'assignments', $assignment_id);

                    // Clear form
                    $_POST = [];
                    $_FILES = [];
                }

                // Log activity
                logActivity('assignment_created', "Created assignment: $title", 'assignments', $assignment_id);

                // Clear form
                $_POST = [];
                $_FILES = [];
            } else {
                $create_error = "Failed to create assignment: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Handle assignment update
$update_success = false;
$update_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $assignment_id = intval($_POST['assignment_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $due_date = $_POST['due_date'] ?? '';
    $due_time = $_POST['due_time'] ?? '23:59';
    $total_points = floatval($_POST['total_points'] ?? 100);
    $submission_type = $_POST['submission_type'] ?? 'file';
    $max_files = intval($_POST['max_files'] ?? 1);
    $allowed_extensions = trim($_POST['allowed_extensions'] ?? '');
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $has_attachment = isset($_POST['has_attachment']) ? 1 : 0;
    $remove_attachment = isset($_POST['remove_attachment']) ? 1 : 0;

    // Get current assignment data
    $sql = "SELECT attachment_path FROM assignments WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $assignment_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $update_error = "Assignment not found or you don't have permission to edit it";
    } elseif (empty($title)) {
        $update_error = "Assignment title is required";
    } elseif (empty($due_date)) {
        $update_error = "Due date is required";
    } else {
        $current_assignment = $result->fetch_assoc();
        $current_attachment = $current_assignment['attachment_path'] ?? '';
        $stmt->close();

        // Handle file upload for update
        $attachment_path = $current_attachment;
        $original_filename = '';

        if ($remove_attachment && $current_attachment) {
            // Remove existing file
            $file_path = __DIR__ . '/../../../' . $current_attachment;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $attachment_path = '';
        }

        if ($has_attachment && isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === 0) {
            // Remove old file if exists
            if ($current_attachment) {
                $old_file_path = __DIR__ . '/../../../' . $current_attachment;
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
            }

            $file = $_FILES['assignment_file'];

            // Create upload directory if it doesn't exist
            $upload_dir = __DIR__ . '/../../../uploads/assignments/' . $class_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Generate unique filename
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
            $original_filename = $file['name'];
            $target_path = $upload_dir . $unique_name;

            // Allowed file types
            $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip', 'rar'];

            if (in_array(strtolower($file_ext), $allowed_types)) {
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $attachment_path = 'uploads/assignments/' . $class_id . '/' . $unique_name;
                } else {
                    $update_error = "Failed to upload file. Please try again.";
                }
            } else {
                $update_error = "File type not allowed. Allowed types: " . implode(', ', $allowed_types);
            }
        }

        if (!$update_error) {
            // Combine date and time
            $due_datetime = $due_date . ' ' . $due_time . ':00';

            // Update assignment
            $update_sql = "UPDATE assignments SET 
                          title = ?, description = ?, instructions = ?, due_date = ?,
                          total_points = ?, submission_type = ?, max_files = ?,
                          allowed_extensions = ?, is_published = ?, updated_at = NOW(),
                          has_attachment = ?, attachment_path = ?";

            // Only update original_filename if we have a new file
            if ($original_filename) {
                $update_sql .= ", original_filename = ?";
                $update_sql .= " WHERE id = ? AND instructor_id = ?";

                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "ssssdsssissii",
                    $title,
                    $description,
                    $instructions,
                    $due_datetime,
                    $total_points,
                    $submission_type,
                    $max_files,
                    $allowed_extensions,
                    $is_published,
                    $has_attachment,
                    $attachment_path,
                    $original_filename,
                    $assignment_id,
                    $instructor_id
                );
            } else {
                $update_sql .= " WHERE id = ? AND instructor_id = ?";

                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "ssssdsssiiii",
                    $title,
                    $description,
                    $instructions,
                    $due_datetime,
                    $total_points,
                    $submission_type,
                    $max_files,
                    $allowed_extensions,
                    $is_published,
                    $has_attachment,
                    $attachment_path,
                    $assignment_id,
                    $instructor_id
                );
            }

            if ($update_stmt->execute()) {
                $update_success = true;

                // Log activity
                logActivity('assignment_updated', "Updated assignment: $title", 'assignments', $assignment_id);
            } else {
                $update_error = "Failed to update assignment: " . $conn->error;
            }
            $update_stmt->close();
        }
    }
    if (isset($stmt)) $stmt->close();
}

// Handle assignment deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $assignment_id = (int)$_GET['delete'];

    // Verify instructor owns this assignment
    $sql = "SELECT attachment_path FROM assignments WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $assignment_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $assignment = $result->fetch_assoc();

        // Delete attached file if exists
        if (!empty($assignment['attachment_path'])) {
            $file_path = __DIR__ . '/../../../' . $assignment['attachment_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // CHANGE THIS: Hard delete instead of soft delete
        $delete_sql = "DELETE FROM assignments WHERE id = ? AND instructor_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $assignment_id, $instructor_id);

        if ($delete_stmt->execute()) {
            // Also delete related submissions if you want to clean up
            $delete_submissions = "DELETE FROM assignment_submissions WHERE assignment_id = ?";
            $sub_stmt = $conn->prepare($delete_submissions);
            $sub_stmt->bind_param("i", $assignment_id);
            $sub_stmt->execute();
            $sub_stmt->close();
        }

        $delete_stmt->close();

        // Log activity
        logActivity('assignment_deleted', "Deleted assignment ID: $assignment_id", 'assignments', $assignment_id);

        // Redirect to avoid resubmission
        header("Location: assignments.php?class_id=$class_id&deleted=1");
        exit();
    }
    $stmt->close();
}

// Handle download request
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $assignment_id = (int)$_GET['download'];

    $sql = "SELECT attachment_path, original_filename FROM assignments WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $assignment_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $assignment = $result->fetch_assoc();

        if (!empty($assignment['attachment_path'])) {
            $file_path = __DIR__ . '/../../../' . $assignment['attachment_path'];
            $original_name = $assignment['original_filename'] ?: 'assignment_file';

            if (file_exists($file_path)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $original_name . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        }
    }
    $stmt->close();
}

// Handle filters
$filter_status = $_GET['status'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// Get assignment statistics
$stats_sql = "SELECT 
                COUNT(*) as total_assignments,
                SUM(CASE WHEN due_date > NOW() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN due_date < NOW() THEN 1 ELSE 0 END) as past_due,
                SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN has_attachment = 1 THEN 1 ELSE 0 END) as with_files
              FROM assignments 
              WHERE class_id = ? AND instructor_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get submission statistics
$submission_stats_sql = "SELECT 
                            a.id,
                            a.title,
                            COUNT(s.id) as total_submissions,
                            SUM(CASE WHEN s.grade IS NOT NULL THEN 1 ELSE 0 END) as graded,
                            SUM(CASE WHEN s.status = 'submitted' AND s.grade IS NULL THEN 1 ELSE 0 END) as pending
                         FROM assignments a 
                         LEFT JOIN assignment_submissions s ON a.id = s.assignment_id
                         WHERE a.class_id = ? AND a.instructor_id = ?
                         GROUP BY a.id
                         ORDER BY a.due_date DESC";
$stmt = $conn->prepare($submission_stats_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$submission_stats_result = $stmt->get_result();
$submission_stats = [];
while ($row = $submission_stats_result->fetch_assoc()) {
    $submission_stats[$row['id']] = $row;
}

// Build query for assignments
$query = "SELECT a.*, 
                 COUNT(s.id) as submission_count,
                 SUM(CASE WHEN s.grade IS NOT NULL THEN 1 ELSE 0 END) as graded_count,
                 SUM(CASE WHEN s.status = 'submitted' AND s.grade IS NULL THEN 1 ELSE 0 END) as pending_count
          FROM assignments a 
          LEFT JOIN assignment_submissions s ON a.id = s.assignment_id
          WHERE a.class_id = ? AND a.instructor_id = ?";

$params = [$class_id, $instructor_id];
$types = "ii";

// Apply status filter
if ($filter_status === 'upcoming') {
    $query .= " AND a.due_date > NOW()";
} elseif ($filter_status === 'past_due') {
    $query .= " AND a.due_date < NOW()";
} elseif ($filter_status === 'published') {
    $query .= " AND a.is_published = 1";
} elseif ($filter_status === 'draft') {
    $query .= " AND a.is_published = 0";
}

// Apply search filter
if (!empty($filter_search)) {
    $query .= " AND (a.title LIKE ? OR a.description LIKE ?)";
    $search_param = "%$filter_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$query .= " GROUP BY a.id ORDER BY a.due_date DESC";

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

// Function to get submission type label
function getSubmissionTypeLabel($type)
{
    $labels = [
        'file' => 'File Upload',
        'text' => 'Text Submission',
        'both' => 'File & Text'
    ];
    return $labels[$type] ?? 'Unknown';
}

// Function to get status badge
function getStatusBadge($due_date, $is_published)
{
    $now = time();
    $due = strtotime($due_date);

    if (!$is_published) {
        return '<span class="status-badge status-draft">Draft</span>';
    } elseif ($due < $now) {
        return '<span class="status-badge status-overdue">Past Due</span>';
    } elseif ($due - $now < 86400) { // Less than 24 hours
        return '<span class="status-badge status-due-soon">Due Soon</span>';
    } else {
        return '<span class="status-badge status-upcoming">Upcoming</span>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - <?php echo htmlspecialchars($class['batch_code']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
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

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--primary);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #e5e7eb;
            color: #374151;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.upcoming {
            border-top-color: var(--success);
        }

        .stat-card.past {
            border-top-color: var(--warning);
        }

        .stat-card.published {
            border-top-color: var(--info);
        }

        .stat-card.files {
            border-top-color: #8b5cf6;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
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

        /* Main Content */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Assignment Cards */
        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
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
            transform: translateY(-5px);
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

        .status-badge-small {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-draft {
            background: #e5e7eb;
            color: #374151;
        }

        .status-upcoming {
            background: #d1fae5;
            color: #065f46;
        }

        .status-due-soon {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .assignment-body {
            padding: 1.5rem;
        }

        .assignment-description {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .assignment-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
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
            margin-top: 1rem;
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
            color: var(--primary);
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
        }

        .submission-stats {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .submission-stats .count {
            font-weight: 600;
            color: var(--dark);
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

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
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

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
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

        .form-control[disabled] {
            background: #f8fafc;
            cursor: not-allowed;
        }

        .form-help {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .form-check input {
            width: auto;
        }

        .form-check label {
            margin-bottom: 0;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 1rem;
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .file-upload.dragover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .file-upload-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .file-upload-text {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .file-upload-hint {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .file-preview-icon {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .file-preview-info {
            flex: 1;
        }

        .file-preview-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .file-preview-size {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .file-preview-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 1.25rem;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .message-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message i {
            font-size: 1.25rem;
        }

        /* Filters */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
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
        }

        .clear-filters {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .clear-filters:hover {
            text-decoration: underline;
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .search-box button {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
            text-align: center;
            cursor: pointer;
        }

        .action-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            background: #f8fafc;
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        .action-label {
            font-size: 0.875rem;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
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
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Assignments</h1>
                    <p><?php echo htmlspecialchars($class['name']); ?></p>
                </div>
                <span class="status-badge status-<?php echo $class['status']; ?>">
                    <?php echo ucfirst($class['status']); ?>
                </span>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($create_success): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Assignment created successfully!</strong>
                    <p style="margin-top: 0.25rem;">The assignment is now available to your students.</p>
                </div>
            </div>
        <?php elseif ($create_error): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Creation failed!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($create_error); ?></p>
                </div>
            </div>
        <?php elseif ($update_success): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Assignment updated successfully!</strong>
                </div>
            </div>
        <?php elseif ($update_error): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Update failed!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($update_error); ?></p>
                </div>
            </div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Assignment deleted successfully!</strong>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total_assignments']; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card upcoming">
                <div class="stat-value"><?php echo $stats['upcoming']; ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card past">
                <div class="stat-value"><?php echo $stats['past_due']; ?></div>
                <div class="stat-label">Past Due</div>
            </div>
            <div class="stat-card published">
                <div class="stat-value"><?php echo $stats['published']; ?></div>
                <div class="stat-label">Published</div>
            </div>
            <div class="stat-card files">
                <div class="stat-value"><?php echo $stats['with_files']; ?></div>
                <div class="stat-label">With Files</div>
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
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=past_due"
                class="tab <?php echo $filter_status === 'past_due' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Past Due
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=published"
                class="tab <?php echo $filter_status === 'published' ? 'active' : ''; ?>">
                <i class="fas fa-eye"></i> Published
            </a>
            <a href="assignments.php?class_id=<?php echo $class_id; ?>&status=draft"
                class="tab <?php echo $filter_status === 'draft' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i> Drafts
            </a>
            <button class="tab" onclick="openCreateModal()" style="margin-left: auto;">
                <i class="fas fa-plus-circle"></i> New Assignment
            </button>
        </div>

        <div class="content-grid">
            <!-- Left Column - Assignments -->
            <div class="left-column">
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <h3>No Assignments Found</h3>
                        <p>
                            <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                                No assignments match your current filters. <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">Clear filters</a> to see all assignments.
                            <?php else: ?>
                                You haven't created any assignments yet. Click "New Assignment" to create your first assignment.
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
                                        <?php echo getStatusBadge($assignment['due_date'], $assignment['is_published']); ?>
                                    </div>
                                    <div class="assignment-due <?php echo strtotime($assignment['due_date']) < time() ? 'overdue' : ''; ?>">
                                        <i class="fas fa-calendar-alt"></i>
                                        Due: <?php echo date('M d, Y g:i A', strtotime($assignment['due_date'])); ?>
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
                                        <?php if ($assignment['has_attachment']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-paperclip"></i>
                                                Has Attachment
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="assignment-body">
                                    <?php if (!empty($assignment['description'])): ?>
                                        <div class="assignment-description">
                                            <?php echo htmlspecialchars($assignment['description']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($assignment['has_attachment'] && $assignment['attachment_path']): ?>
                                        <div class="assignment-attachment">
                                            <div class="attachment-info">
                                                <div class="attachment-icon">
                                                    <?php echo getFileIcon($assignment['original_filename']); ?>
                                                </div>
                                                <div class="attachment-details">
                                                    <div class="attachment-name">
                                                        <?php echo htmlspecialchars($assignment['original_filename']); ?>
                                                    </div>
                                                    <div class="attachment-size">
                                                        Assignment file
                                                    </div>
                                                </div>
                                            </div>
                                            <a href="?class_id=<?php echo $class_id; ?>&download=<?php echo $assignment['id']; ?>"
                                                class="btn btn-primary btn-sm">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <div class="submission-stats">
                                        <strong>Submissions:</strong>
                                        <span class="count"><?php echo $assignment['submission_count']; ?></span> total •
                                        <span style="color: var(--success);"><?php echo $assignment['graded_count']; ?></span> graded •
                                        <span style="color: var(--warning);"><?php echo $assignment['pending_count']; ?></span> pending
                                    </div>
                                </div>

                                <div class="assignment-footer">
                                    <div class="submission-stats">
                                        <i class="fas fa-users"></i>
                                        <?php echo $assignment['submission_count']; ?> submissions
                                    </div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="#" class="btn btn-primary btn-sm"
                                            onclick="openEditModal(<?php echo $assignment['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?assignment_id=<?php echo $assignment['id']; ?>" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($assignment['has_attachment'] && $assignment['attachment_path']): ?>
                                            <a href="?class_id=<?php echo $class_id; ?>&download=<?php echo $assignment['id']; ?>"
                                                class="btn btn-info btn-sm">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?class_id=<?php echo $class_id; ?>&delete=<?php echo $assignment['id']; ?>"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Are you sure you want to delete this assignment? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Filters & Actions -->
            <div class="right-column">
                <!-- Filters -->
                <div class="filters-card">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <?php if ($filter_status !== 'all' || !empty($filter_search)): ?>
                            <a href="?class_id=<?php echo $class_id; ?>" class="clear-filters">
                                Clear All
                            </a>
                        <?php endif; ?>
                    </div>

                    <form method="GET" action="">
                        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                        <div class="search-box">
                            <input type="text" name="search" placeholder="Search assignments..."
                                value="<?php echo htmlspecialchars($filter_search); ?>"
                                onkeypress="if(event.key==='Enter') this.form.submit()">
                            <button type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Actions -->
                <div class="filters-card">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <div class="quick-actions">
                        <button class="action-item" onclick="openCreateModal()">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="action-label">New Assignment</div>
                        </button>
                        <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="action-label">Gradebook</div>
                        </a>
                        <a href="#" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-download"></i>
                            </div>
                            <div class="action-label">Export Grades</div>
                        </a>
                        <a href="#" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="action-label">Announce</div>
                        </a>
                    </div>
                </div>

                <!-- Submission Stats -->
                <div class="filters-card">
                    <h3><i class="fas fa-chart-bar"></i> Submission Overview</h3>
                    <div style="font-size: 0.875rem; line-height: 1.6;">
                        <?php if (empty($submission_stats)): ?>
                            <div style="color: var(--gray); font-style: italic;">
                                No submissions yet
                            </div>
                        <?php else: ?>
                            <?php
                            $total_submissions = 0;
                            $total_graded = 0;
                            $total_pending = 0;

                            foreach ($submission_stats as $stat) {
                                $total_submissions += $stat['total_submissions'];
                                $total_graded += $stat['graded'];
                                $total_pending += $stat['pending'];
                            }
                            ?>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Total Submissions:</strong> <?php echo $total_submissions; ?>
                            </div>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Graded:</strong> <span style="color: var(--success);"><?php echo $total_graded; ?></span>
                            </div>
                            <div style="margin-bottom: 0.75rem;">
                                <strong>Pending:</strong> <span style="color: var(--warning);"><?php echo $total_pending; ?></span>
                            </div>
                            <div>
                                <strong>Grading Progress:</strong>
                                <div style="margin-top: 0.25rem;">
                                    <?php
                                    $progress = $total_submissions > 0 ? ($total_graded / $total_submissions * 100) : 0;
                                    ?>
                                    <div style="background: #e2e8f0; border-radius: 10px; height: 8px; overflow: hidden; margin-bottom: 0.25rem;">
                                        <div style="background: var(--success); height: 100%; width: <?php echo $progress; ?>%;"></div>
                                    </div>
                                    <div style="text-align: center; font-size: 0.75rem; color: var(--gray);">
                                        <?php echo round($progress, 1); ?>% complete
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Assignment Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Assignment</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="modal-body">
                    <div class="form-group">
                        <label for="create_title" class="required">Assignment Title</label>
                        <input type="text" id="create_title" name="title" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                            placeholder="e.g., Mid-term Project Submission" required>
                    </div>

                    <div class="form-group">
                        <label for="create_description">Description</label>
                        <textarea id="create_description" name="description" class="form-control" rows="3"
                            placeholder="Describe the assignment requirements"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="create_instructions">Instructions</label>
                        <textarea id="create_instructions" name="instructions" class="form-control" rows="4"
                            placeholder="Detailed instructions for students"><?php echo htmlspecialchars($_POST['instructions'] ?? ''); ?></textarea>
                        <div class="form-help">Be specific about what students need to submit</div>
                    </div>

                    <!-- File Upload Section -->
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="create_has_attachment" name="has_attachment" value="1"
                                <?php echo isset($_POST['has_attachment']) ? 'checked' : ''; ?>
                                onchange="toggleAttachmentSection('create')">
                            <label for="create_has_attachment">Attach assignment file</label>
                        </div>
                        <div class="form-help">Check this if you want to upload a file (PDF, Word, etc.) for students to download</div>
                    </div>

                    <div id="create_attachment_section" style="display: none;">
                        <div class="file-upload" id="create_file_upload" onclick="document.getElementById('create_assignment_file').click()">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">
                                Click to upload assignment file
                            </div>
                            <div class="file-upload-hint">
                                Supports: PDF, Word, Excel, PowerPoint, Images, ZIP (Max: 50MB)
                            </div>
                            <input type="file" id="create_assignment_file" name="assignment_file"
                                accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar"
                                onchange="previewFile('create', this)">
                        </div>
                        <div id="create_file_preview"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="create_due_date" class="required">Due Date</label>
                            <input type="date" id="create_due_date" name="due_date" class="form-control"
                                value="<?php echo htmlspecialchars($_POST['due_date'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="create_due_time">Due Time</label>
                            <input type="time" id="create_due_time" name="due_time" class="form-control"
                                value="<?php echo htmlspecialchars($_POST['due_time'] ?? '23:59'); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="create_total_points" class="required">Total Points</label>
                            <input type="number" id="create_total_points" name="total_points" class="form-control"
                                value="<?php echo htmlspecialchars($_POST['total_points'] ?? 100); ?>"
                                min="1" max="1000" step="0.5" required>
                        </div>
                        <div class="form-group">
                            <label for="create_submission_type" class="required">Submission Type</label>
                            <select id="create_submission_type" name="submission_type" class="form-control" required>
                                <option value="file" <?php echo ($_POST['submission_type'] ?? 'file') === 'file' ? 'selected' : ''; ?>>File Upload</option>
                                <option value="text" <?php echo ($_POST['submission_type'] ?? '') === 'text' ? 'selected' : ''; ?>>Text Submission</option>
                                <option value="both" <?php echo ($_POST['submission_type'] ?? '') === 'both' ? 'selected' : ''; ?>>File & Text</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="create_max_files">Maximum Files</label>
                            <input type="number" id="create_max_files" name="max_files" class="form-control"
                                value="<?php echo htmlspecialchars($_POST['max_files'] ?? 1); ?>"
                                min="1" max="10">
                            <div class="form-help">For file upload submissions</div>
                        </div>
                        <div class="form-group">
                            <label for="create_allowed_extensions">Allowed File Types</label>
                            <input type="text" id="create_allowed_extensions" name="allowed_extensions" class="form-control"
                                value="<?php echo htmlspecialchars($_POST['allowed_extensions'] ?? 'pdf,doc,docx,jpg,jpeg,png'); ?>"
                                placeholder="pdf,doc,docx,jpg,jpeg,png">
                            <div class="form-help">Comma-separated list of extensions</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Assignment</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="" id="editForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="assignment_id" id="edit_assignment_id">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_title" class="required">Assignment Title</label>
                        <input type="text" id="edit_title" name="title" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit_instructions">Instructions</label>
                        <textarea id="edit_instructions" name="instructions" class="form-control" rows="4"></textarea>
                    </div>

                    <!-- File Upload Section for Edit -->
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="edit_has_attachment" name="has_attachment" value="1"
                                onchange="toggleAttachmentSection('edit')">
                            <label for="edit_has_attachment">Attach assignment file</label>
                        </div>
                        <div id="edit_current_file" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <i class="fas fa-file" style="font-size: 1.5rem; color: var(--primary);"></i>
                                    <div>
                                        <div id="edit_current_filename" style="font-weight: 600;"></div>
                                        <div style="font-size: 0.75rem; color: var(--gray);">Current file</div>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="edit_remove_attachment" name="remove_attachment" value="1">
                                    <label for="edit_remove_attachment" style="color: var(--danger);">Remove</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="edit_attachment_section" style="display: none;">
                        <div class="file-upload" id="edit_file_upload" onclick="document.getElementById('edit_assignment_file').click()">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">
                                Click to upload new assignment file
                            </div>
                            <div class="file-upload-hint">
                                Supports: PDF, Word, Excel, PowerPoint, Images, ZIP (Max: 50MB)
                            </div>
                            <input type="file" id="edit_assignment_file" name="assignment_file"
                                accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar"
                                onchange="previewFile('edit', this)">
                        </div>
                        <div id="edit_file_preview"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_due_date" class="required">Due Date</label>
                            <input type="date" id="edit_due_date" name="due_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_due_time">Due Time</label>
                            <input type="time" id="edit_due_time" name="due_time" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_total_points" class="required">Total Points</label>
                            <input type="number" id="edit_total_points" name="total_points" class="form-control"
                                min="1" max="1000" step="0.5" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_submission_type" class="required">Submission Type</label>
                            <select id="edit_submission_type" name="submission_type" class="form-control" required>
                                <option value="file">File Upload</option>
                                <option value="text">Text Submission</option>
                                <option value="both">File & Text</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_max_files">Maximum Files</label>
                            <input type="number" id="edit_max_files" name="max_files" class="form-control"
                                min="1" max="10">
                        </div>
                        <div class="form-group">
                            <label for="edit_allowed_extensions">Allowed File Types</label>
                            <input type="text" id="edit_allowed_extensions" name="allowed_extensions" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="edit_is_published" name="is_published" value="1">
                            <label for="edit_is_published">Publish assignment</label>
                        </div>
                        <div class="form-help">Uncheck to save as draft</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openCreateModal() {
            document.getElementById('createModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            // Initialize attachment section
            toggleAttachmentSection('create');
        }

        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function openEditModal(assignmentId) {
            // Fetch assignment data via AJAX
            fetch(`get_assignment.php?id=${assignmentId}&class_id=<?php echo $class_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Populate form fields
                        document.getElementById('edit_assignment_id').value = data.id;
                        document.getElementById('edit_title').value = data.title;
                        document.getElementById('edit_description').value = data.description || '';
                        document.getElementById('edit_instructions').value = data.instructions || '';

                        // Format date and time
                        const dueDate = new Date(data.due_date);
                        document.getElementById('edit_due_date').value = dueDate.toISOString().split('T')[0];
                        document.getElementById('edit_due_time').value = dueDate.toTimeString().slice(0, 5);

                        document.getElementById('edit_total_points').value = data.total_points;
                        document.getElementById('edit_submission_type').value = data.submission_type;
                        document.getElementById('edit_max_files').value = data.max_files;
                        document.getElementById('edit_allowed_extensions').value = data.allowed_extensions || '';
                        document.getElementById('edit_is_published').checked = data.is_published == 1;

                        // Handle attachment
                        document.getElementById('edit_has_attachment').checked = data.has_attachment == 1;
                        if (data.has_attachment && data.original_filename) {
                            document.getElementById('edit_current_filename').textContent = data.original_filename;
                            document.getElementById('edit_current_file').style.display = 'block';
                        }

                        // Show/hide attachment section
                        toggleAttachmentSection('edit');

                        // Show modal
                        document.getElementById('editModal').classList.add('show');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('Failed to load assignment data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load assignment data');
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close modals on outside click
        document.addEventListener('click', function(event) {
            const createModal = document.getElementById('createModal');
            const editModal = document.getElementById('editModal');

            if (event.target === createModal) {
                closeCreateModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCreateModal();
                closeEditModal();
            }
        });

        // Set minimum date to today for due date
        document.getElementById('create_due_date').min = new Date().toISOString().split('T')[0];
        document.getElementById('edit_due_date').min = new Date().toISOString().split('T')[0];

        // Show/hide file-related fields based on submission type
        function toggleFileFields(selectId) {
            const select = document.getElementById(selectId);
            const maxFiles = document.getElementById(selectId.replace('submission_type', 'max_files'));
            const allowedExt = document.getElementById(selectId.replace('submission_type', 'allowed_extensions'));

            if (select.value === 'text') {
                maxFiles.disabled = true;
                allowedExt.disabled = true;
            } else {
                maxFiles.disabled = false;
                allowedExt.disabled = false;
            }
        }

        document.getElementById('create_submission_type').addEventListener('change', function() {
            toggleFileFields('create_submission_type');
        });

        document.getElementById('edit_submission_type').addEventListener('change', function() {
            toggleFileFields('edit_submission_type');
        });

        // Toggle attachment section
        function toggleAttachmentSection(type) {
            const checkbox = document.getElementById(`${type}_has_attachment`);
            const section = document.getElementById(`${type}_attachment_section`);

            if (checkbox.checked) {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
                // Clear file preview
                document.getElementById(`${type}_file_preview`).innerHTML = '';
                // Clear file input
                document.getElementById(`${type}_assignment_file`).value = '';
            }
        }

        // Preview uploaded file
        function previewFile(type, input) {
            const preview = document.getElementById(`${type}_file_preview`);

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = (file.size / (1024 * 1024)).toFixed(2); // MB

                preview.innerHTML = `
                    <div class="file-preview">
                        <div class="file-preview-icon">
                            <i class="${getFileIcon(file.name)}"></i>
                        </div>
                        <div class="file-preview-info">
                            <div class="file-preview-name">${file.name}</div>
                            <div class="file-preview-size">${fileSize} MB</div>
                        </div>
                        <button type="button" class="file-preview-remove" onclick="removeFile('${type}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            }
        }

        // Remove file preview
        function removeFile(type) {
            document.getElementById(`${type}_file_preview`).innerHTML = '';
            document.getElementById(`${type}_assignment_file`).value = '';
        }

        // Get file icon based on extension
        function getFileIcon(filename) {
            if (!filename) return 'fas fa-file';

            const ext = filename.split('.').pop().toLowerCase();

            const icons = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'txt': 'fas fa-file-alt',
                'ppt': 'fas fa-file-powerpoint',
                'pptx': 'fas fa-file-powerpoint',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'jpg': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'zip': 'fas fa-file-archive',
                'rar': 'fas fa-file-archive'
            };

            return icons[ext] || 'fas fa-file';
        }

        // Drag and drop functionality
        ['create_file_upload', 'edit_file_upload'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });

                element.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });

                element.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');

                    const type = id.split('_')[0];
                    const fileInput = document.getElementById(`${type}_assignment_file`);

                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        previewFile(type, fileInput);
                    }
                });
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            toggleFileFields('create_submission_type');
            toggleAttachmentSection('create');

            // Show success message temporarily
            const successAlert = document.querySelector('.message-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N to create new assignment
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openCreateModal();
            }

            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });
    </script>
</body>

</html>