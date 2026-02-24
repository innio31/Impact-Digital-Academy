<?php
// modules/instructor/classes/announcements.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

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
$sql = "SELECT cb.*, c.title as course_title, c.course_code
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
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

// Handle actions
$action = $_GET['action'] ?? '';
$announcement_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_announcement'])) {
        // Create new announcement
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $priority = $_POST['priority'] ?? 'medium';
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        // Validate
        if (empty($title) || empty($content)) {
            $message = 'Title and content are required.';
            $message_type = 'error';
        } else {
            $sql = "INSERT INTO announcements 
                    (class_id, author_id, title, content, priority, is_published, expiry_date, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisssis", $class_id, $instructor_id, $title, $content, $priority, $is_published, $expiry_date);

            if ($stmt->execute()) {
                $announcement_id = $stmt->insert_id;
                $message = 'Announcement created successfully!';
                $message_type = 'success';

                // Log activity
                logActivity('create_announcement', "Created announcement: {$title}", 'announcements', $announcement_id);

                // Redirect to avoid form resubmission
                header("Location: announcements.php?class_id={$class_id}&action=view&id={$announcement_id}&message=" . urlencode($message));
                exit();
            } else {
                $message = 'Failed to create announcement. Please try again.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['update_announcement'])) {
        // Update existing announcement
        $announcement_id = (int)$_POST['announcement_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $priority = $_POST['priority'] ?? 'medium';
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        // Verify ownership
        $sql_check = "SELECT id FROM announcements WHERE id = ? AND class_id = ? AND author_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("iii", $announcement_id, $class_id, $instructor_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Announcement not found or you do not have permission to edit it.';
            $message_type = 'error';
            $stmt_check->close();
        } else {
            $stmt_check->close();

            if (empty($title) || empty($content)) {
                $message = 'Title and content are required.';
                $message_type = 'error';
            } else {
                $sql = "UPDATE announcements 
                        SET title = ?, content = ?, priority = ?, is_published = ?, expiry_date = ?, updated_at = NOW()
                        WHERE id = ? AND class_id = ? AND author_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "sssssiii",
                    $title,
                    $content,
                    $priority,
                    $is_published,
                    $expiry_date,
                    $announcement_id,
                    $class_id,
                    $instructor_id
                );

                if ($stmt->execute()) {
                    $message = 'Announcement updated successfully!';
                    $message_type = 'success';

                    // Log activity
                    logActivity('update_announcement', "Updated announcement: {$title}", 'announcements', $announcement_id);

                    // Redirect to avoid form resubmission
                    header("Location: announcements.php?class_id={$class_id}&action=view&id={$announcement_id}&message=" . urlencode($message));
                    exit();
                } else {
                    $message = 'Failed to update announcement. Please try again.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['delete_announcement'])) {
        // Delete announcement
        $announcement_id = (int)$_POST['announcement_id'];

        // Verify ownership
        $sql_check = "SELECT title FROM announcements WHERE id = ? AND class_id = ? AND author_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("iii", $announcement_id, $class_id, $instructor_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Announcement not found or you do not have permission to delete it.';
            $message_type = 'error';
            $stmt_check->close();
        } else {
            $announcement = $result_check->fetch_assoc();
            $stmt_check->close();

            $sql = "DELETE FROM announcements WHERE id = ? AND class_id = ? AND author_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iii", $announcement_id, $class_id, $instructor_id);

            if ($stmt->execute()) {
                $message = 'Announcement deleted successfully!';
                $message_type = 'success';

                // Log activity
                logActivity('delete_announcement', "Deleted announcement: {$announcement['title']}", 'announcements', $announcement_id);

                // Redirect to announcements list
                header("Location: announcements.php?class_id={$class_id}&message=" . urlencode($message));
                exit();
            } else {
                $message = 'Failed to delete announcement. Please try again.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['toggle_publish'])) {
        // Toggle publish status
        $announcement_id = (int)$_POST['announcement_id'];
        $is_published = (int)$_POST['is_published'];

        $sql = "UPDATE announcements 
                SET is_published = ?, updated_at = NOW()
                WHERE id = ? AND class_id = ? AND author_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiii", $is_published, $announcement_id, $class_id, $instructor_id);

        if ($stmt->execute()) {
            $status = $is_published ? 'published' : 'unpublished';
            $message = "Announcement {$status} successfully!";
            $message_type = 'success';

            // Log activity
            logActivity('toggle_announcement', "{$status} announcement", 'announcements', $announcement_id);

            // Redirect
            header("Location: announcements.php?class_id={$class_id}&message=" . urlencode($message));
            exit();
        } else {
            $message = 'Failed to update announcement status.';
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Get message from URL if present
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $message_type = isset($_GET['type']) ? $_GET['type'] : 'success';
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

// Build query for announcements list
$where_conditions = ["a.class_id = ?"];
$params = [$class_id];
$param_types = "i";

if ($filter === 'published') {
    $where_conditions[] = "a.is_published = 1";
} elseif ($filter === 'draft') {
    $where_conditions[] = "a.is_published = 0";
} elseif ($filter === 'expired') {
    $where_conditions[] = "a.expiry_date < CURDATE()";
} elseif ($filter === 'active') {
    $where_conditions[] = "(a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
    $where_conditions[] = "a.is_published = 1";
}

if (!empty($search)) {
    $where_conditions[] = "(a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $param_types .= "ssss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$sql_count = "SELECT COUNT(*) as total 
              FROM announcements a
              LEFT JOIN users u ON a.author_id = u.id
              WHERE {$where_clause}";
$stmt_count = $conn->prepare($sql_count);
if (count($params) > 1) {
    $stmt_count->bind_param($param_types, ...$params);
} else {
    $stmt_count->bind_param($param_types, $params[0]);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_announcements = $count_result->fetch_assoc()['total'];
$stmt_count->close();

// Pagination
$per_page = 10;
$total_pages = ceil($total_announcements / $per_page);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Order by
$order_by = "a.{$sort_by} {$sort_order}";

// Get announcements with pagination
$sql_announcements = "SELECT a.*, 
                     CONCAT(u.first_name, ' ', u.last_name) as author_name,
                     CASE 
                         WHEN a.expiry_date < CURDATE() THEN 'expired'
                         WHEN a.is_published = 0 THEN 'draft'
                         ELSE 'active'
                     END as status_display
                     FROM announcements a
                     LEFT JOIN users u ON a.author_id = u.id
                     WHERE {$where_clause}
                     ORDER BY {$order_by}
                     LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt_announcements = $conn->prepare($sql_announcements);
if (count($params) > 1) {
    $stmt_announcements->bind_param($param_types, ...$params);
} else {
    $stmt_announcements->bind_param($param_types, $params[0]);
}
$stmt_announcements->execute();
$announcements_result = $stmt_announcements->get_result();
$announcements = $announcements_result->fetch_all(MYSQLI_ASSOC);
$stmt_announcements->close();

// Get specific announcement for view/edit
$announcement = null;
if ($action === 'view' || $action === 'edit') {
    $sql_single = "SELECT a.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name
                   FROM announcements a
                   LEFT JOIN users u ON a.author_id = u.id
                   WHERE a.id = ? AND a.class_id = ?";
    $stmt_single = $conn->prepare($sql_single);
    $stmt_single->bind_param("ii", $announcement_id, $class_id);
    $stmt_single->execute();
    $single_result = $stmt_single->get_result();

    if ($single_result->num_rows > 0) {
        $announcement = $single_result->fetch_assoc();

        // Verify ownership for edit
        if ($action === 'edit' && $announcement['author_id'] != $instructor_id) {
            $message = 'You do not have permission to edit this announcement.';
            $message_type = 'error';
            $announcement = null;
        }
    }
    $stmt_single->close();
}

// Get announcement statistics
$sql_stats = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published,
              SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) as draft,
              SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired
              FROM announcements 
              WHERE class_id = ?";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->bind_param("i", $class_id);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();
$stmt_stats->close();

// Get recent announcements for sidebar
$sql_recent = "SELECT a.*, 
              CONCAT(u.first_name, ' ', u.last_name) as author_name
              FROM announcements a
              LEFT JOIN users u ON a.author_id = u.id
              WHERE a.class_id = ? AND a.is_published = 1
              ORDER BY a.created_at DESC
              LIMIT 5";
$stmt_recent = $conn->prepare($sql_recent);
$stmt_recent->bind_param("i", $class_id);
$stmt_recent->execute();
$recent_announcements = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_recent->close();

// Log activity
logActivity('view_announcements', "Viewed announcements for class: {$class['batch_code']}", 'class_batches', $class_id);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Announcements</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        /* Reuse existing CSS from class_home.php with additions */
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

        /* Header & Navigation */
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

        .stat-card.published {
            border-top-color: var(--success);
        }

        .stat-card.draft {
            border-top-color: var(--warning);
        }

        .stat-card.expired {
            border-top-color: var(--danger);
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

        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Main Content */
        .main-content {
            flex: 1;
        }

        /* Sidebar */
        .sidebar {
            flex: 0 0 350px;
        }

        @media (max-width: 1024px) {
            .sidebar {
                flex: 1;
            }
        }

        /* Controls */
        .controls {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .search-box {
            flex: 1;
            min-width: 300px;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Announcements List */
        .announcements-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .announcement-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .announcement-header {
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .announcement-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            align-items: center;
            flex-wrap: wrap;
        }

        .announcement-content {
            padding: 1rem 1.5rem;
            color: var(--dark);
            line-height: 1.6;
        }

        .announcement-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-draft {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .priority-low {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .priority-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .priority-high {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
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

        /* Announcement Form */
        .announcement-form {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.5;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            transform: scale(1.2);
        }

        /* Announcement View */
        .announcement-view {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .announcement-view-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .announcement-view-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .announcement-view-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            color: var(--gray);
            font-size: 0.875rem;
        }

        .announcement-view-content {
            line-height: 1.8;
            color: var(--dark);
            margin-bottom: 2rem;
        }

        .announcement-view-content p {
            margin-bottom: 1rem;
        }

        .announcement-view-content ul,
        .announcement-view-content ol {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .announcement-view-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        /* Sidebar Cards */
        .sidebar-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .sidebar-card h3 {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-list {
            list-style: none;
        }

        .sidebar-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .sidebar-list li:last-child {
            border-bottom: none;
        }

        .sidebar-list a {
            text-decoration: none;
            color: var(--dark);
            display: block;
            transition: color 0.3s ease;
        }

        .sidebar-list a:hover {
            color: var(--primary);
        }

        .sidebar-list .meta {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger);
            color: var(--danger);
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--dark);
            background: white;
            border: 2px solid #e2e8f0;
        }

        .pagination a:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Delete Confirmation Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-content {
            margin-bottom: 1.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                min-width: 100%;
                max-width: 100%;
            }

            .filter-group {
                justify-content: center;
            }

            .announcement-header,
            .announcement-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .announcement-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .form-actions,
            .announcement-view-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
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
            <span>Announcements</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Announcements</h1>
                    <p><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
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
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Announcements</div>
            </div>
            <div class="stat-card published">
                <div class="stat-value"><?php echo $stats['published'] ?? 0; ?></div>
                <div class="stat-label">Published</div>
            </div>
            <div class="stat-card draft">
                <div class="stat-value"><?php echo $stats['draft'] ?? 0; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card expired">
                <div class="stat-value"><?php echo $stats['expired'] ?? 0; ?></div>
                <div class="stat-label">Expired</div>
            </div>
        </div>

        <div class="content-layout">
            <!-- Main Content -->
            <div class="main-content">
                <?php if ($action === 'create' || $action === 'edit'): ?>
                    <!-- Announcement Form -->
                    <div class="announcement-form">
                        <h2 style="margin-bottom: 2rem; color: var(--dark); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-<?php echo $action === 'create' ? 'plus-circle' : 'edit'; ?>"></i>
                            <?php echo $action === 'create' ? 'Create New Announcement' : 'Edit Announcement'; ?>
                        </h2>

                        <form method="post" action="">
                            <input type="hidden" name="<?php echo $action === 'create' ? 'create_announcement' : 'update_announcement'; ?>" value="1">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="announcement_id" value="<?php echo $announcement_id; ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title"
                                    value="<?php echo htmlspecialchars($announcement['title'] ?? ''); ?>"
                                    placeholder="Enter announcement title" required>
                            </div>

                            <div class="form-group">
                                <label for="content">Content *</label>
                                <textarea id="content" name="content"
                                    placeholder="Enter announcement content" required><?php echo htmlspecialchars($announcement['content'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="priority">Priority</label>
                                    <select id="priority" name="priority">
                                        <option value="low" <?php echo ($announcement['priority'] ?? 'medium') === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo ($announcement['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo ($announcement['priority'] ?? 'medium') === 'high' ? 'selected' : ''; ?>>High</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="expiry_date">Expiry Date (Optional)</label>
                                    <input type="date" id="expiry_date" name="expiry_date"
                                        value="<?php echo $announcement['expiry_date'] ?? ''; ?>">
                                    <small style="display: block; margin-top: 0.25rem; color: var(--gray);">
                                        Leave empty for no expiration
                                    </small>
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="is_published" name="is_published" value="1"
                                        <?php echo (($announcement['is_published'] ?? 1) == 1) ? 'checked' : ''; ?>>
                                    <label for="is_published">Publish immediately</label>
                                </div>
                            </div>

                            <div class="form-actions">
                                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-<?php echo $action === 'create' ? 'plus-circle' : 'save'; ?>"></i>
                                    <?php echo $action === 'create' ? 'Create Announcement' : 'Save Changes'; ?>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php elseif ($action === 'view' && $announcement): ?>
                    <!-- Announcement View -->
                    <div class="announcement-view">
                        <div class="announcement-view-header">
                            <div class="announcement-view-title">
                                <?php echo htmlspecialchars($announcement['title']); ?>
                            </div>

                            <div class="announcement-view-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                <?php if ($announcement['expiry_date']): ?>
                                    <span><i class="fas fa-clock"></i> Expires: <?php echo date('F j, Y', strtotime($announcement['expiry_date'])); ?></span>
                                <?php endif; ?>
                                <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                    <?php echo ucfirst($announcement['priority']); ?> Priority
                                </span>
                                <span class="status-badge status-<?php echo ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) ? 'expired' : ($announcement['is_published'] ? 'active' : 'draft'); ?>">
                                    <?php echo ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) ? 'Expired' : ($announcement['is_published'] ? 'Published' : 'Draft'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="announcement-view-content">
                            <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                        </div>

                        <div class="announcement-view-actions">
                            <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            <?php if ($announcement['author_id'] == $instructor_id): ?>
                                <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=edit&id=<?php echo $announcement_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button type="button" class="btn btn-danger" onclick="showDeleteModal(<?php echo $announcement_id; ?>, '<?php echo htmlspecialchars(addslashes($announcement['title'])); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Announcements List -->
                    <div class="controls">
                        <form method="get" action="" class="search-box">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="text" name="search" placeholder="Search announcements..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </form>

                        <div class="filter-group">
                            <a href="?class_id=<?php echo $class_id; ?>&filter=all"
                                class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">
                                All
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=active"
                                class="btn <?php echo $filter === 'active' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Active
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=published"
                                class="btn <?php echo $filter === 'published' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Published
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=draft"
                                class="btn <?php echo $filter === 'draft' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Drafts
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=expired"
                                class="btn <?php echo $filter === 'expired' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Expired
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> New Announcement
                            </a>
                        </div>
                    </div>

                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h3>No announcements found</h3>
                            <p>
                                <?php if (!empty($search)): ?>
                                    No announcements match your search criteria.
                                <?php elseif ($filter !== 'all'): ?>
                                    No announcements match the selected filter.
                                <?php else: ?>
                                    No announcements have been created yet.
                                <?php endif; ?>
                            </p>
                            <?php if (empty($search) && $filter === 'all'): ?>
                                <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-plus-circle"></i> Create Your First Announcement
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="announcements-list">
                            <?php foreach ($announcements as $ann): ?>
                                <div class="announcement-card">
                                    <div class="announcement-header">
                                        <div>
                                            <div class="announcement-title">
                                                <?php echo htmlspecialchars($ann['title']); ?>
                                                <?php if ($ann['status_display'] !== 'active'): ?>
                                                    <span class="status-badge status-<?php echo $ann['status_display']; ?>">
                                                        <?php echo ucfirst($ann['status_display']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="priority-badge priority-<?php echo $ann['priority']; ?>">
                                                    <?php echo ucfirst($ann['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="announcement-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($ann['author_name']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($ann['created_at'])); ?></span>
                                                <?php if ($ann['expiry_date']): ?>
                                                    <span><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y', strtotime($ann['expiry_date'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="announcement-content">
                                        <?php
                                        $content = strip_tags($ann['content']);
                                        if (strlen($content) > 200) {
                                            echo htmlspecialchars(substr($content, 0, 200)) . '...';
                                        } else {
                                            echo htmlspecialchars($content);
                                        }
                                        ?>
                                    </div>

                                    <div class="announcement-footer">
                                        <div class="announcement-actions">
                                            <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $ann['id']; ?>"
                                                class="btn btn-secondary btn-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if ($ann['author_id'] == $instructor_id): ?>
                                                <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=edit&id=<?php echo $ann['id']; ?>"
                                                    class="btn btn-primary btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="post" style="display: inline;"
                                                    onsubmit="return confirm('Are you sure you want to <?php echo $ann['is_published'] ? 'unpublish' : 'publish'; ?> this announcement?');">
                                                    <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                                    <input type="hidden" name="is_published" value="<?php echo $ann['is_published'] ? '0' : '1'; ?>">
                                                    <button type="submit" name="toggle_publish" class="btn btn-<?php echo $ann['is_published'] ? 'warning' : 'success'; ?> btn-sm">
                                                        <i class="fas fa-<?php echo $ann['is_published'] ? 'eye-slash' : 'eye'; ?>"></i>
                                                        <?php echo $ann['is_published'] ? 'Unpublish' : 'Publish'; ?>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-danger btn-sm"
                                                    onclick="showDeleteModal(<?php echo $ann['id']; ?>, '<?php echo htmlspecialchars(addslashes($ann['title'])); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>"
                                            class="<?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <span>...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Quick Actions -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <div style="display: grid; gap: 0.5rem;">
                        <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-primary" style="justify-content: center;">
                            <i class="fas fa-plus-circle"></i> New Announcement
                        </a>
                        <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn btn-secondary" style="justify-content: center;">
                            <i class="fas fa-home"></i> Class Home
                        </a>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-history"></i> Recent Announcements</h3>
                    <?php if (empty($recent_announcements)): ?>
                        <p style="color: var(--gray); text-align: center; padding: 1rem 0;">No recent announcements</p>
                    <?php else: ?>
                        <ul class="sidebar-list">
                            <?php foreach ($recent_announcements as $recent): ?>
                                <li>
                                    <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $recent['id']; ?>">
                                        <strong><?php echo htmlspecialchars(substr($recent['title'], 0, 50)); ?><?php echo strlen($recent['title']) > 50 ? '...' : ''; ?></strong>
                                        <div class="meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($recent['created_at'])); ?></span>
                                            <span class="priority-badge priority-<?php echo $recent['priority']; ?> btn-sm">
                                                <?php echo ucfirst($recent['priority']); ?>
                                            </span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Announcement Tips -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-lightbulb"></i> Tips for Effective Announcements</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Keep titles clear and descriptive
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Use high priority only for urgent matters
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Set expiry dates for time-sensitive info
                        </li>
                        <li style="padding: 0.5rem 0;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Draft important announcements first
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Announcement</h3>
            </div>
            <div class="modal-content">
                <p>Are you sure you want to delete the announcement "<strong id="deleteAnnouncementTitle"></strong>"?</p>
                <p style="color: var(--danger); font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                <form method="post" id="deleteForm" style="display: inline;">
                    <input type="hidden" name="announcement_id" id="deleteAnnouncementId">
                    <button type="submit" name="delete_announcement" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Announcement
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit search on enter
        document.querySelector('input[name="search"]')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Delete modal functions
        function showDeleteModal(id, title) {
            document.getElementById('deleteAnnouncementId').value = id;
            document.getElementById('deleteAnnouncementTitle').textContent = title;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });

        // Set minimum date for expiry date to today
        const expiryDateInput = document.getElementById('expiry_date');
        if (expiryDateInput) {
            const today = new Date().toISOString().split('T')[0];
            expiryDateInput.min = today;
        }

        // Auto-expand textarea
        const contentTextarea = document.getElementById('content');
        if (contentTextarea) {
            contentTextarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            // Trigger once on load
            setTimeout(() => {
                contentTextarea.dispatchEvent(new Event('input'));
            }, 100);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new announcement
            if (e.ctrlKey && e.key === 'n' && !window.location.href.includes('action=create')) {
                e.preventDefault();
                window.location.href = '?class_id=<?php echo $class_id; ?>&action=create';
            }

            // Esc to close modal
            if (e.key === 'Escape') {
                hideDeleteModal();
            }

            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });

        // Confirm before leaving unsaved form
        let formChanged = false;
        const form = document.querySelector('form');
        if (form) {
            const formInputs = form.querySelectorAll('input, textarea, select');
            formInputs.forEach(input => {
                input.addEventListener('change', () => formChanged = true);
                input.addEventListener('input', () => formChanged = true);
            });

            window.addEventListener('beforeunload', function(e) {
                if (formChanged) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                }
            });

            form.addEventListener('submit', () => formChanged = false);
        }

        // Toggle expiry date visibility based on checkbox
        const publishCheckbox = document.getElementById('is_published');
        const expiryDateGroup = document.querySelector('label[for="expiry_date"]').parentElement;

        if (publishCheckbox && expiryDateGroup) {
            function updateExpiryDateVisibility() {
                if (publishCheckbox.checked) {
                    expiryDateGroup.style.opacity = '1';
                    expiryDateGroup.style.pointerEvents = 'auto';
                } else {
                    expiryDateGroup.style.opacity = '0.5';
                    expiryDateGroup.style.pointerEvents = 'none';
                }
            }

            publishCheckbox.addEventListener('change', updateExpiryDateVisibility);
            updateExpiryDateVisibility();
        }
    </script>
</body>

</html>