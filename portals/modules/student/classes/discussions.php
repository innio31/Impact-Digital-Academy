<?php
// modules/student/classes/discussions.php

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

// Handle actions
$action = $_GET['action'] ?? '';
$discussion_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_discussion'])) {
        // Create new discussion
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);

        // Validate
        if (empty($title) || empty($content)) {
            $message = 'Title and content are required.';
            $message_type = 'error';
        } else {
            $sql = "INSERT INTO discussions 
                    (class_id, user_id, title, content, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiss", $class_id, $student_id, $title, $content);

            if ($stmt->execute()) {
                $discussion_id = $stmt->insert_id;
                $message = 'Discussion created successfully!';
                $message_type = 'success';

                // After successful discussion creation, add:
                if (isset($discussion_id) && $discussion_id) {
                    // Send notifications to all class participants
                    sendNewDiscussionNotification($discussion_id, $conn);
                }
                // Log activity
                logActivity('create_discussion', "Created discussion: {$title}", 'discussions', $discussion_id);

                // Redirect to the new discussion
                header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
                exit();
            } else {
                $message = 'Failed to create discussion. Please try again.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['post_reply'])) {
        // Post a reply to a discussion
        $discussion_id = (int)$_POST['discussion_id'];
        $content = trim($_POST['content']);

        // Verify discussion exists and is not locked
        $sql_check = "SELECT is_locked FROM discussions WHERE id = ? AND class_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $discussion_id, $class_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Discussion not found in this class.';
            $message_type = 'error';
            $stmt_check->close();
        } else {
            $discussion = $result_check->fetch_assoc();
            $stmt_check->close();

            if ($discussion['is_locked']) {
                $message = 'This discussion is locked. No new replies can be posted.';
                $message_type = 'error';
            } elseif (empty($content)) {
                $message = 'Reply content is required.';
                $message_type = 'error';
            } else {
                $sql = "INSERT INTO discussion_replies 
                        (discussion_id, user_id, content, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iis", $discussion_id, $student_id, $content);

                if ($stmt->execute()) {
                    // Update discussion reply count and last reply timestamp
                    $sql_update = "UPDATE discussions 
                                  SET replies_count = replies_count + 1, last_reply_at = NOW(), updated_at = NOW()
                                  WHERE id = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->bind_param("i", $discussion_id);
                    $stmt_update->execute();
                    $stmt_update->close();

                    $message = 'Reply posted successfully!';
                    $message_type = 'success';

                    // After successful reply insertion, add:
                    if (isset($reply_id) && $reply_id) {
                        // Send notifications to discussion participants
                        sendDiscussionReplyNotification($reply_id, $conn);
                    }

                    // Log activity
                    logActivity('post_reply', "Posted reply to discussion #{$discussion_id}", 'discussion_replies', $stmt->insert_id);

                    // Redirect to the discussion
                    header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
                    exit();
                } else {
                    $message = 'Failed to post reply. Please try again.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['delete_reply'])) {
        // Delete a reply (only if it's the student's own reply)
        $reply_id = (int)$_POST['reply_id'];
        $discussion_id = (int)$_POST['discussion_id'];

        // Verify reply exists, belongs to student, and discussion is in class
        $sql_check = "SELECT dr.id 
                     FROM discussion_replies dr
                     JOIN discussions d ON dr.discussion_id = d.id
                     WHERE dr.id = ? AND dr.user_id = ? AND d.class_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("iii", $reply_id, $student_id, $class_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Reply not found or you do not have permission to delete it.';
            $message_type = 'error';
            $stmt_check->close();
        } else {
            $stmt_check->close();

            $sql = "DELETE FROM discussion_replies WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $reply_id);

            if ($stmt->execute()) {
                // Update discussion reply count
                $sql_update = "UPDATE discussions 
                              SET replies_count = GREATEST(0, replies_count - 1), updated_at = NOW()
                              WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("i", $discussion_id);
                $stmt_update->execute();
                $stmt_update->close();

                $message = 'Reply deleted successfully!';
                $message_type = 'success';

                // Log activity
                logActivity('delete_reply', "Deleted reply #{$reply_id}", 'discussion_replies', $reply_id);

                // Redirect back to discussion
                header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
                exit();
            } else {
                $message = 'Failed to delete reply. Please try again.';
                $message_type = 'error';
            }
            $stmt->close();
        }
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
$sort_by = $_GET['sort_by'] ?? 'last_reply_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

// Build query for discussions list
$where_conditions = ["d.class_id = ?"];
$params = [$class_id];
$param_types = "i";

if ($filter === 'unanswered') {
    $where_conditions[] = "d.replies_count = 0";
} elseif ($filter === 'answered') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM discussion_replies dr WHERE dr.discussion_id = d.id AND dr.is_instructor_answer = 1)";
} elseif ($filter === 'active') {
    $where_conditions[] = "d.is_locked = 0";
}

if (!empty($search)) {
    $where_conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $param_types .= "ssss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$sql_count = "SELECT COUNT(*) as total 
              FROM discussions d
              LEFT JOIN users u ON d.user_id = u.id
              WHERE {$where_clause}";
$stmt_count = $conn->prepare($sql_count);
if (count($params) > 1) {
    $stmt_count->bind_param($param_types, ...$params);
} else {
    $stmt_count->bind_param($param_types, $params[0]);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_discussions = $count_result->fetch_assoc()['total'];
$stmt_count->close();

// Pagination
$per_page = 10;
$total_pages = ceil($total_discussions / $per_page);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Order by - always show pinned first, then by sort
$order_by = "d.is_pinned DESC, d.{$sort_by} {$sort_order}";

// Get discussions with pagination
$sql_discussions = "SELECT d.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   u.profile_image as author_image,
                   u.role as author_role,
                   (SELECT COUNT(*) FROM discussion_replies dr WHERE dr.discussion_id = d.id) as reply_count,
                   (SELECT COUNT(*) FROM discussion_replies dr WHERE dr.discussion_id = d.id AND dr.is_instructor_answer = 1) as answer_count,
                   (SELECT CONCAT(u2.first_name, ' ', u2.last_name) 
                    FROM discussion_replies dr2 
                    LEFT JOIN users u2 ON dr2.user_id = u2.id 
                    WHERE dr2.discussion_id = d.id 
                    ORDER BY dr2.created_at DESC LIMIT 1) as last_replier
                   FROM discussions d
                   LEFT JOIN users u ON d.user_id = u.id
                   WHERE {$where_clause}
                   ORDER BY {$order_by}
                   LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt_discussions = $conn->prepare($sql_discussions);
if (count($params) > 1) {
    $stmt_discussions->bind_param($param_types, ...$params);
} else {
    $stmt_discussions->bind_param($param_types, $params[0]);
}
$stmt_discussions->execute();
$discussions_result = $stmt_discussions->get_result();
$discussions = $discussions_result->fetch_all(MYSQLI_ASSOC);
$stmt_discussions->close();

// Get specific discussion for view
$discussion = null;
$replies = [];
if ($action === 'view') {
    // Get discussion details
    $sql_single = "SELECT d.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   u.profile_image as author_image,
                   u.role as author_role
                   FROM discussions d
                   LEFT JOIN users u ON d.user_id = u.id
                   WHERE d.id = ? AND d.class_id = ?";
    $stmt_single = $conn->prepare($sql_single);
    $stmt_single->bind_param("ii", $discussion_id, $class_id);
    $stmt_single->execute();
    $single_result = $stmt_single->get_result();

    if ($single_result->num_rows > 0) {
        $discussion = $single_result->fetch_assoc();

        // Increment view count
        $sql_views = "UPDATE discussions SET views_count = views_count + 1 WHERE id = ?";
        $stmt_views = $conn->prepare($sql_views);
        $stmt_views->bind_param("i", $discussion_id);
        $stmt_views->execute();
        $stmt_views->close();

        // Get replies for this discussion
        $sql_replies = "SELECT dr.*, 
                       CONCAT(u.first_name, ' ', u.last_name) as author_name,
                       u.profile_image as author_image,
                       u.role as author_role
                       FROM discussion_replies dr
                       LEFT JOIN users u ON dr.user_id = u.id
                       WHERE dr.discussion_id = ?
                       ORDER BY dr.is_instructor_answer DESC, dr.created_at ASC";
        $stmt_replies = $conn->prepare($sql_replies);
        $stmt_replies->bind_param("i", $discussion_id);
        $stmt_replies->execute();
        $replies_result = $stmt_replies->get_result();
        $replies = $replies_result->fetch_all(MYSQLI_ASSOC);
        $stmt_replies->close();
    }
    $stmt_single->close();
}

// Get discussion statistics
$sql_stats = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN is_pinned = 1 THEN 1 ELSE 0 END) as pinned,
              SUM(CASE WHEN replies_count = 0 THEN 1 ELSE 0 END) as unanswered,
              SUM(views_count) as total_views
              FROM discussions 
              WHERE class_id = ?";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->bind_param("i", $class_id);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();
$stmt_stats->close();

$conn->close();


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Discussions</title>
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

        .stat-card.pinned {
            border-top-color: var(--warning);
        }

        .stat-card.unanswered {
            border-top-color: var(--danger);
        }

        .stat-card.views {
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

        /* Discussion Cards */
        .discussions-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .discussion-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .discussion-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .discussion-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            position: relative;
        }

        .discussion-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .discussion-title a {
            color: var(--dark);
            text-decoration: none;
        }

        .discussion-title a:hover {
            color: var(--primary);
        }

        .discussion-meta {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .discussion-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .discussion-body {
            padding: 1.5rem;
        }

        .discussion-content {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .discussion-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tag {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .tag-pinned {
            background: #fef3c7;
            color: #92400e;
        }

        .tag-answered {
            background: #d1fae5;
            color: #065f46;
        }

        .tag-unanswered {
            background: #fee2e2;
            color: #991b1b;
        }

        .tag-locked {
            background: #e5e7eb;
            color: #374151;
        }

        .tag-instructor {
            background: #dbeafe;
            color: #1e40af;
        }

        .discussion-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Discussion View */
        .discussion-view {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .discussion-view-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .discussion-view-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
            font-size: 0.875rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .discussion-view-content {
            line-height: 1.6;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        /* Replies Section */
        .replies-section {
            margin-top: 2rem;
        }

        .replies-section h3 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reply-form {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .reply-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .reply-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid transparent;
        }

        .reply-card.answer {
            border-left-color: var(--success);
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.05) 0%, white 10%);
        }

        .reply-card.instructor {
            border-left-color: var(--primary);
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, white 10%);
        }

        .reply-header {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .reply-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .reply-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .reply-author-info {
            flex: 1;
        }

        .reply-author-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .reply-author-role {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .reply-content {
            line-height: 1.6;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .reply-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .reply-actions {
            display: flex;
            gap: 0.5rem;
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

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
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

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            max-width: 500px;
            padding: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            margin-bottom: 1rem;
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

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .modal-actions .btn {
            flex: 1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .discussion-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .discussion-footer {
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            <span>Discussions</span>
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
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Quizzes
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
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
                    <i class="fas fa-comments"></i>
                    Discussions
                </h2>
                <p>Participate in class discussions for <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="stats">
                <span><i class="fas fa-comment-alt"></i> <?php echo $stats['total'] ?? 0; ?> discussions</span>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <div>
                    <strong><?php echo $message_type === 'error' ? 'Error' : 'Success'; ?>!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Discussions</div>
            </div>
            <div class="stat-card pinned">
                <div class="stat-value"><?php echo $stats['pinned'] ?? 0; ?></div>
                <div class="stat-label">Pinned</div>
            </div>
            <div class="stat-card unanswered">
                <div class="stat-value"><?php echo $stats['unanswered'] ?? 0; ?></div>
                <div class="stat-label">Unanswered</div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo $stats['total_views'] ?? 0; ?></div>
                <div class="stat-label">Total Views</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="discussions.php?class_id=<?php echo $class_id; ?>&filter=all"
                class="tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Discussions
            </a>
            <a href="discussions.php?class_id=<?php echo $class_id; ?>&filter=unanswered"
                class="tab <?php echo $filter === 'unanswered' ? 'active' : ''; ?>">
                <i class="fas fa-question-circle"></i> Unanswered
            </a>
            <a href="discussions.php?class_id=<?php echo $class_id; ?>&filter=answered"
                class="tab <?php echo $filter === 'answered' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Answered
            </a>
            <a href="discussions.php?class_id=<?php echo $class_id; ?>&filter=active"
                class="tab <?php echo $filter === 'active' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i> Active
            </a>
        </div>

        <?php if ($action === 'create'): ?>
            <!-- Create Discussion Form -->
            <div class="discussion-view">
                <h2 style="margin-bottom: 1.5rem; font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-plus-circle"></i> Start New Discussion
                </h2>

                <form method="post" action="">
                    <input type="hidden" name="create_discussion" value="1">

                    <div class="form-group">
                        <label for="title" class="required">Title</label>
                        <input type="text" id="title" name="title" class="form-control"
                            placeholder="What would you like to discuss?" required>
                    </div>

                    <div class="form-group">
                        <label for="content" class="required">Content</label>
                        <textarea id="content" name="content" class="form-control"
                            placeholder="Share your thoughts, questions, or ideas..." required></textarea>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary" style="flex: 1;">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-paper-plane"></i> Post Discussion
                        </button>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'view' && $discussion): ?>
            <!-- Discussion View -->
            <div class="discussion-view">
                <div class="discussion-view-title">
                    <?php echo htmlspecialchars($discussion['title']); ?>
                    <?php if ($discussion['is_pinned']): ?>
                        <span class="tag tag-pinned" style="margin-left: 0.5rem;">
                            <i class="fas fa-thumbtack"></i> Pinned
                        </span>
                    <?php endif; ?>
                    <?php if ($discussion['is_locked']): ?>
                        <span class="tag tag-locked" style="margin-left: 0.5rem;">
                            <i class="fas fa-lock"></i> Locked
                        </span>
                    <?php endif; ?>
                </div>

                <div class="discussion-view-meta">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <?php if ($discussion['author_image']): ?>
                                <img src="<?php echo htmlspecialchars($discussion['author_image']); ?>"
                                    alt="<?php echo htmlspecialchars($discussion['author_name']); ?>"
                                    style="width: 32px; height: 32px; border-radius: 50%;">
                            <?php else: ?>
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); 
                                            color: white; display: flex; align-items: center; justify-content: center;
                                            font-weight: 600;">
                                    <?php echo strtoupper(substr($discussion['author_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($discussion['author_name']); ?></span>
                            <span class="tag <?php echo $discussion['author_role'] === 'instructor' ? 'tag-instructor' : ''; ?>">
                                <?php echo $discussion['author_role'] === 'instructor' ? 'Instructor' : 'Student'; ?>
                            </span>
                        </div>
                        <span><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($discussion['created_at'])); ?></span>
                        <span><i class="fas fa-eye"></i> <?php echo $discussion['views_count'] + 1; ?></span>
                        <span><i class="fas fa-comment"></i> <?php echo $discussion['replies_count']; ?></span>
                    </div>
                </div>

                <div class="discussion-view-content">
                    <?php echo nl2br(htmlspecialchars($discussion['content'])); ?>
                </div>

                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Discussions
                </a>
            </div>

            <!-- Replies Section -->
            <div class="replies-section">
                <h3><i class="fas fa-comments"></i> Replies (<?php echo count($replies); ?>)</h3>

                <?php if (!$discussion['is_locked']): ?>
                    <!-- Reply Form -->
                    <div class="reply-form">
                        <form method="post" action="">
                            <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">

                            <div class="form-group">
                                <textarea name="content" class="form-control" placeholder="Write your reply here..." required></textarea>
                            </div>

                            <button type="submit" name="post_reply" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Post Reply
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Discussion Locked</strong>
                            <p style="margin-top: 0.25rem;">This discussion is locked. No new replies can be posted.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Replies List -->
                <div class="reply-list">
                    <?php if (empty($replies)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-slash"></i>
                            <h3>No Replies Yet</h3>
                            <p>Be the first to respond to this discussion!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($replies as $reply): ?>
                            <div class="reply-card <?php echo $reply['is_instructor_answer'] ? 'answer' : ($reply['author_role'] === 'instructor' ? 'instructor' : ''); ?>">
                                <div class="reply-header">
                                    <div class="reply-avatar">
                                        <?php if ($reply['author_image']): ?>
                                            <img src="<?php echo htmlspecialchars($reply['author_image']); ?>"
                                                alt="<?php echo htmlspecialchars($reply['author_name']); ?>">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($reply['author_name'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reply-author-info">
                                        <div class="reply-author-name">
                                            <?php echo htmlspecialchars($reply['author_name']); ?>
                                            <?php if ($reply['author_role'] === 'instructor'): ?>
                                                <span class="tag tag-instructor" style="margin-left: 0.5rem; font-size: 0.75rem;">
                                                    <i class="fas fa-chalkboard-teacher"></i> Instructor
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($reply['is_instructor_answer']): ?>
                                                <span class="tag tag-answered" style="margin-left: 0.5rem; font-size: 0.75rem;">
                                                    <i class="fas fa-check-circle"></i> Answer
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="reply-author-role">
                                            <?php echo $reply['author_role'] === 'instructor' ? 'Instructor' : 'Student'; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="reply-content">
                                    <?php echo nl2br(htmlspecialchars($reply['content'])); ?>
                                </div>

                                <div class="reply-meta">
                                    <span><i class="fas fa-clock"></i> <?php echo time_ago($reply['created_at']); ?></span>
                                    <?php if ($reply['user_id'] == $student_id): ?>
                                        <div class="reply-actions">
                                            <button type="button" class="btn btn-danger btn-small"
                                                onclick="showDeleteReplyModal(<?php echo $reply['id']; ?>, <?php echo $discussion_id; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Discussions List View -->
            <!-- Search and Filter -->
            <div class="search-filter">
                <div class="filters-header">
                    <h3><i class="fas fa-search"></i> Search Discussions</h3>
                    <?php if ($filter !== 'all' || !empty($search)): ?>
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
                        placeholder="Search discussions by title or content..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        id="searchInput">

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>

                    <?php if (!empty($search) || $filter !== 'all'): ?>
                        <a href="?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Create New Discussion Button -->
                <div style="text-align: center;">
                    <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i> Start New Discussion
                    </a>
                </div>
            </div>

            <!-- Discussions List -->
            <?php if (empty($discussions)): ?>
                <div class="empty-state">
                    <i class="fas fa-comments"></i>
                    <h3>No Discussions Found</h3>
                    <p>
                        <?php if ($filter !== 'all' || !empty($search)): ?>
                            No discussions match your current filters. <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">Clear filters</a> to see all discussions.
                        <?php else: ?>
                            No discussions have been started for this class yet.
                        <?php endif; ?>
                    </p>
                    <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-primary" style="margin-top: 1rem;">
                        <i class="fas fa-plus-circle"></i> Start First Discussion
                    </a>
                </div>
            <?php else: ?>
                <div class="discussions-grid">
                    <?php foreach ($discussions as $disc): ?>
                        <div class="discussion-card">
                            <div class="discussion-header">
                                <div class="discussion-title">
                                    <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $disc['id']; ?>">
                                        <?php echo htmlspecialchars($disc['title']); ?>
                                    </a>
                                    <div class="discussion-tags">
                                        <?php if ($disc['is_pinned']): ?>
                                            <span class="tag tag-pinned">
                                                <i class="fas fa-thumbtack"></i> Pinned
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($disc['is_locked']): ?>
                                            <span class="tag tag-locked">
                                                <i class="fas fa-lock"></i> Locked
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($disc['answer_count'] > 0): ?>
                                            <span class="tag tag-answered">
                                                <i class="fas fa-check-circle"></i> Answered
                                            </span>
                                        <?php elseif ($disc['reply_count'] == 0): ?>
                                            <span class="tag tag-unanswered">
                                                <i class="fas fa-question-circle"></i> Unanswered
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="discussion-meta">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($disc['author_name']); ?>
                                    <?php if ($disc['author_role'] === 'instructor'): ?>
                                        <span class="tag tag-instructor">Instructor</span>
                                    <?php endif; ?>
                                </div>
                                <div class="discussion-stats">
                                    <div class="stat-item">
                                        <i class="fas fa-comment"></i>
                                        <?php echo $disc['reply_count']; ?> replies
                                    </div>
                                    <div class="stat-item">
                                        <i class="fas fa-eye"></i>
                                        <?php echo $disc['views_count']; ?> views
                                    </div>
                                    <div class="stat-item">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo time_ago($disc['created_at']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="discussion-body">
                                <div class="discussion-content">
                                    <?php
                                    $content = strip_tags($disc['content']);
                                    echo htmlspecialchars(strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content);
                                    ?>
                                </div>
                            </div>

                            <div class="discussion-footer">
                                <div>
                                    <?php if ($disc['last_replier']): ?>
                                        <span style="color: var(--gray); font-size: 0.875rem;">
                                            Last reply by <?php echo htmlspecialchars($disc['last_replier']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-size: 0.875rem;">
                                            No replies yet
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $disc['id']; ?>"
                                        class="btn btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($disc['author_role'] === 'student' && strpos(strtolower($disc['author_name']), strtolower($_SESSION['user_name'] ?? '')) !== false): ?>
                                        <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $disc['id']; ?>#reply"
                                            class="btn btn-secondary">
                                            <i class="fas fa-reply"></i> Reply
                                        </a>
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
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                                <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>"
                                    class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php elseif ($i == $page - 2 || $i == $page + 2): ?>
                                <span>...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <!-- Delete Reply Modal -->
    <div id="deleteReplyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Reply</h3>
            </div>
            <div style="padding: 1rem 0;">
                <p>Are you sure you want to delete this reply?</p>
                <p style="color: var(--danger); font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteReplyModal()">Cancel</button>
                <form method="post" id="deleteReplyForm">
                    <input type="hidden" name="reply_id" id="deleteReplyId">
                    <input type="hidden" name="discussion_id" id="deleteReplyDiscussionId">
                    <button type="submit" name="delete_reply" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function showDeleteReplyModal(replyId, discussionId) {
            document.getElementById('deleteReplyId').value = replyId;
            document.getElementById('deleteReplyDiscussionId').value = discussionId;
            document.getElementById('deleteReplyModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function hideDeleteReplyModal() {
            document.getElementById('deleteReplyModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close modal on outside click
        document.addEventListener('click', function(event) {
            const deleteReplyModal = document.getElementById('deleteReplyModal');
            if (event.target === deleteReplyModal) {
                hideDeleteReplyModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideDeleteReplyModal();
            }
        });

        // Search with Enter key
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('filterForm').submit();
            }
        });

        // Debounced search for better UX
        let searchTimeout;
        document.getElementById('searchInput')?.addEventListener('input', function(e) {
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
                const searchInput = document.getElementById('searchInput');
                if (searchInput) searchInput.focus();
            }

            // Ctrl/Cmd + / to clear filters
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                window.location.href = 'discussions.php?class_id=<?php echo $class_id; ?>';
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

            // Auto-expand textareas
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                // Trigger once on load
                setTimeout(() => {
                    if (textarea.value) {
                        textarea.dispatchEvent(new Event('input'));
                    }
                }, 100);
            });

            // Save draft functionality for create form
            const discussionForm = document.querySelector('.discussion-view form');
            if (discussionForm && window.location.href.includes('action=create')) {
                const saveKey = 'student_discussion_draft_<?php echo $class_id; ?>';

                // Load draft
                const draft = localStorage.getItem(saveKey);
                if (draft) {
                    try {
                        const data = JSON.parse(draft);
                        const titleInput = document.getElementById('title');
                        const contentInput = document.getElementById('content');

                        if (titleInput && contentInput && !titleInput.value && !contentInput.value) {
                            titleInput.value = data.title || '';
                            contentInput.value = data.content || '';

                            // Show notification
                            const notification = document.createElement('div');
                            notification.className = 'alert alert-success';
                            notification.innerHTML = `
                                <i class="fas fa-save"></i>
                                <div>
                                    <strong>Draft Restored</strong>
                                    <p style="margin-top: 0.25rem;">Your draft has been restored from your last session.</p>
                                </div>
                            `;
                            discussionForm.parentElement.insertBefore(notification, discussionForm);
                        }
                    } catch (e) {
                        console.error('Failed to load draft:', e);
                    }
                }

                // Save draft with debounce
                let saveTimeout;

                function saveDraft() {
                    const title = document.getElementById('title')?.value || '';
                    const content = document.getElementById('content')?.value || '';

                    if (title || content) {
                        const data = {
                            title: title,
                            content: content,
                            timestamp: new Date().toISOString()
                        };
                        localStorage.setItem(saveKey, JSON.stringify(data));
                    }
                }

                const inputs = discussionForm.querySelectorAll('input, textarea');
                inputs.forEach(input => {
                    input.addEventListener('input', () => {
                        clearTimeout(saveTimeout);
                        saveTimeout = setTimeout(saveDraft, 1000);
                    });
                });

                // Clear draft on submit
                discussionForm.addEventListener('submit', () => {
                    localStorage.removeItem(saveKey);
                });
            }
        });
    </script>
</body>

</html>