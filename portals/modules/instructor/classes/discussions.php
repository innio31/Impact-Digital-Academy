<?php
// modules/instructor/classes/discussions.php

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
$discussion_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_discussion'])) {
        // Create new discussion
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;

        // Validate
        if (empty($title) || empty($content)) {
            $message = 'Title and content are required.';
            $message_type = 'error';
        } else {
            $sql = "INSERT INTO discussions 
                    (class_id, user_id, title, content, is_pinned, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissi", $class_id, $instructor_id, $title, $content, $is_pinned);

            if ($stmt->execute()) {
                $discussion_id = $stmt->insert_id;
                $message = 'Discussion created successfully!';
                $message_type = 'success';

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
    } elseif (isset($_POST['update_discussion'])) {
        // Update existing discussion
        $discussion_id = (int)$_POST['discussion_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $is_locked = isset($_POST['is_locked']) ? 1 : 0;

        // Verify ownership (instructors can edit any discussion in their class)
        $sql_check = "SELECT id FROM discussions WHERE id = ? AND class_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $discussion_id, $class_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Discussion not found in this class.';
            $message_type = 'error';
            $stmt_check->close();
        } else {
            $stmt_check->close();

            if (empty($title) || empty($content)) {
                $message = 'Title and content are required.';
                $message_type = 'error';
            } else {
                $sql = "UPDATE discussions 
                        SET title = ?, content = ?, is_pinned = ?, is_locked = ?, updated_at = NOW()
                        WHERE id = ? AND class_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssiiii", $title, $content, $is_pinned, $is_locked, $discussion_id, $class_id);

                if ($stmt->execute()) {
                    $message = 'Discussion updated successfully!';
                    $message_type = 'success';

                    // Log activity
                    logActivity('update_discussion', "Updated discussion: {$title}", 'discussions', $discussion_id);

                    // Redirect
                    header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
                    exit();
                } else {
                    $message = 'Failed to update discussion. Please try again.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['delete_discussion'])) {
        // Delete discussion
        $discussion_id = (int)$_POST['discussion_id'];

        // Verify it exists in this class
        $sql_check = "SELECT title FROM discussions WHERE id = ? AND class_id = ?";
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

            // Delete replies first (cascade delete)
            $sql_replies = "DELETE FROM discussion_replies WHERE discussion_id = ?";
            $stmt_replies = $conn->prepare($sql_replies);
            $stmt_replies->bind_param("i", $discussion_id);
            $stmt_replies->execute();
            $stmt_replies->close();

            // Delete discussion
            $sql = "DELETE FROM discussions WHERE id = ? AND class_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $discussion_id, $class_id);

            if ($stmt->execute()) {
                $message = 'Discussion deleted successfully!';
                $message_type = 'success';

                // Log activity
                logActivity('delete_discussion', "Deleted discussion: {$discussion['title']}", 'discussions', $discussion_id);

                // Redirect to discussions list
                header("Location: discussions.php?class_id={$class_id}&message=" . urlencode($message));
                exit();
            } else {
                $message = 'Failed to delete discussion. Please try again.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['post_reply'])) {
        // Post a reply to a discussion
        $discussion_id = (int)$_POST['discussion_id'];
        $content = trim($_POST['content']);
        $is_instructor_answer = isset($_POST['is_instructor_answer']) ? 1 : 0;

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
                        (discussion_id, user_id, content, is_instructor_answer, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iisi", $discussion_id, $instructor_id, $content, $is_instructor_answer);

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
        // Delete a reply
        $reply_id = (int)$_POST['reply_id'];
        $discussion_id = (int)$_POST['discussion_id'];

        // Verify reply exists and belongs to a discussion in this class
        $sql_check = "SELECT dr.id 
                     FROM discussion_replies dr
                     JOIN discussions d ON dr.discussion_id = d.id
                     WHERE dr.id = ? AND d.class_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $reply_id, $class_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $message = 'Reply not found or does not belong to this class.';
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
    } elseif (isset($_POST['toggle_pin'])) {
        // Toggle pin status
        $discussion_id = (int)$_POST['discussion_id'];
        $is_pinned = (int)$_POST['is_pinned'];

        $sql = "UPDATE discussions 
                SET is_pinned = ?, updated_at = NOW()
                WHERE id = ? AND class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $is_pinned, $discussion_id, $class_id);

        if ($stmt->execute()) {
            $status = $is_pinned ? 'pinned' : 'unpinned';
            $message = "Discussion {$status} successfully!";
            $message_type = 'success';

            // Log activity
            logActivity('toggle_pin', "{$status} discussion", 'discussions', $discussion_id);

            // Redirect
            header("Location: discussions.php?class_id={$class_id}&message=" . urlencode($message));
            exit();
        } else {
            $message = 'Failed to update discussion.';
            $message_type = 'error';
        }
        $stmt->close();
    } elseif (isset($_POST['toggle_lock'])) {
        // Toggle lock status
        $discussion_id = (int)$_POST['discussion_id'];
        $is_locked = (int)$_POST['is_locked'];

        $sql = "UPDATE discussions 
                SET is_locked = ?, updated_at = NOW()
                WHERE id = ? AND class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $is_locked, $discussion_id, $class_id);

        if ($stmt->execute()) {
            $status = $is_locked ? 'locked' : 'unlocked';
            $message = "Discussion {$status} successfully!";
            $message_type = 'success';

            // Log activity
            logActivity('toggle_lock', "{$status} discussion", 'discussions', $discussion_id);

            // Redirect
            header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
            exit();
        } else {
            $message = 'Failed to update discussion.';
            $message_type = 'error';
        }
        $stmt->close();
    } elseif (isset($_POST['toggle_answer'])) {
        // Toggle instructor answer status for a reply
        $reply_id = (int)$_POST['reply_id'];
        $discussion_id = (int)$_POST['discussion_id'];
        $is_instructor_answer = (int)$_POST['is_instructor_answer'];

        $sql = "UPDATE discussion_replies 
                SET is_instructor_answer = ?, updated_at = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $is_instructor_answer, $reply_id);

        if ($stmt->execute()) {
            $status = $is_instructor_answer ? 'marked as answer' : 'unmarked as answer';
            $message = "Reply {$status} successfully!";
            $message_type = 'success';

            // Log activity
            logActivity('toggle_answer', "{$status} for reply #{$reply_id}", 'discussion_replies', $reply_id);

            // Redirect
            header("Location: discussions.php?class_id={$class_id}&action=view&id={$discussion_id}&message=" . urlencode($message));
            exit();
        } else {
            $message = 'Failed to update reply.';
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
$sort_by = $_GET['sort_by'] ?? 'last_reply_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

// Build query for discussions list
$where_conditions = ["d.class_id = ?"];
$params = [$class_id];
$param_types = "i";

if ($filter === 'pinned') {
    $where_conditions[] = "d.is_pinned = 1";
} elseif ($filter === 'unanswered') {
    $where_conditions[] = "d.replies_count = 0";
} elseif ($filter === 'answered') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM discussion_replies dr WHERE dr.discussion_id = d.id AND dr.is_instructor_answer = 1)";
} elseif ($filter === 'locked') {
    $where_conditions[] = "d.is_locked = 1";
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
$per_page = 15;
$total_pages = ceil($total_discussions / $per_page);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Order by - always show pinned first, then by sort
$order_by = "d.is_pinned DESC, d.{$sort_by} {$sort_order}";

// Get discussions with pagination
$sql_discussions = "SELECT d.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   u.profile_image as author_image,
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

// Get specific discussion for view/edit
$discussion = null;
$replies = [];
if ($action === 'view' || $action === 'edit') {
    // Get discussion details
    $sql_single = "SELECT d.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   u.profile_image as author_image
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
              SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked,
              SUM(views_count) as total_views
              FROM discussions 
              WHERE class_id = ?";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->bind_param("i", $class_id);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();
$stmt_stats->close();

// Get recent discussions for sidebar
$sql_recent = "SELECT d.*, 
              CONCAT(u.first_name, ' ', u.last_name) as author_name
              FROM discussions d
              LEFT JOIN users u ON d.user_id = u.id
              WHERE d.class_id = ? AND d.is_locked = 0
              ORDER BY d.last_reply_at DESC, d.created_at DESC
              LIMIT 5";
$stmt_recent = $conn->prepare($sql_recent);
$stmt_recent->bind_param("i", $class_id);
$stmt_recent->execute();
$recent_discussions = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_recent->close();

// Get top contributors
$sql_contributors = "SELECT 
                    u.id, u.first_name, u.last_name, u.profile_image, u.role,
                    COUNT(dr.id) as reply_count
                    FROM discussion_replies dr
                    LEFT JOIN users u ON dr.user_id = u.id
                    LEFT JOIN discussions d ON dr.discussion_id = d.id
                    WHERE d.class_id = ?
                    GROUP BY u.id, u.first_name, u.last_name, u.profile_image, u.role
                    ORDER BY reply_count DESC
                    LIMIT 5";
$stmt_contributors = $conn->prepare($sql_contributors);
$stmt_contributors->bind_param("i", $class_id);
$stmt_contributors->execute();
$top_contributors = $stmt_contributors->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contributors->close();

// Log activity
logActivity('view_discussions', "Viewed discussions for class: {$class['batch_code']}", 'class_batches', $class_id);

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
        /* Reuse existing CSS with additions */
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

        /* Discussions List */
        .discussions-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .discussion-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid transparent;
        }

        .discussion-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .discussion-card.pinned {
            border-left-color: var(--warning);
            background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, white 10%);
        }

        .discussion-card.answered {
            border-left-color: var(--success);
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.05) 0%, white 10%);
        }

        .discussion-card.locked {
            border-left-color: var(--danger);
            background: linear-gradient(90deg, rgba(239, 68, 68, 0.05) 0%, white 10%);
        }

        .discussion-content {
            padding: 1.5rem;
            display: flex;
            gap: 1rem;
        }

        .discussion-stats {
            flex: 0 0 80px;
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label-small {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .discussion-details {
            flex: 1;
            min-width: 0;
        }

        .discussion-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .discussion-title a {
            text-decoration: none;
            color: inherit;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .discussion-title a:hover {
            color: var(--primary);
        }

        .discussion-preview {
            color: var(--gray);
            margin-bottom: 1rem;
            line-height: 1.5;

            /* For WebKit browsers (Chrome, Safari, newer Edge) */
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            /* Fallback for other browsers */
            overflow: hidden;
            text-overflow: ellipsis;

            /* For Firefox and other standards-compliant browsers */
            /* Note: line-clamp is still experimental */
            display: -moz-box;
            line-clamp: 2;
            box-orient: vertical;
        }

        .discussion-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .discussion-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .author-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .author-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .discussion-actions {
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
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-pinned {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-answered {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-unanswered {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-locked {
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

        /* Discussion Form */
        .discussion-form {
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

        /* Discussion View */
        .discussion-view {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .discussion-view-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .discussion-view-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .discussion-view-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            color: var(--gray);
            font-size: 0.875rem;
        }

        .discussion-view-content {
            line-height: 1.8;
            color: var(--dark);
            margin-bottom: 3rem;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .discussion-view-content p {
            margin-bottom: 1rem;
        }

        .discussion-view-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        /* Replies Section */
        .replies-section {
            margin-top: 3rem;
        }

        .replies-section h3 {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reply-form {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .reply-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .reply-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid transparent;
            transition: transform 0.3s ease;
        }

        .reply-card:hover {
            transform: translateY(-2px);
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
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .reply-author {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
        }

        .reply-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .reply-author-info h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }

        .reply-author-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .reply-actions {
            display: flex;
            gap: 0.5rem;
        }

        .reply-content {
            line-height: 1.6;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .reply-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
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

        /* Contributors */
        .contributor-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .contributor-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            background: #f8fafc;
        }

        .contributor-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .contributor-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .contributor-info {
            flex: 1;
        }

        .contributor-info h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }

        .contributor-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .contributor-stats {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.25rem;
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

            .discussion-content {
                flex-direction: column;
                gap: 1rem;
            }

            .discussion-stats {
                flex-direction: row;
                justify-content: center;
                gap: 2rem;
            }

            .discussion-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .discussion-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .form-actions,
            .discussion-view-actions,
            .modal-actions {
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
            <span>Discussions</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Discussions</h1>
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
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
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

        <div class="content-layout">
            <!-- Main Content -->
            <div class="main-content">
                <?php if ($action === 'create' || $action === 'edit'): ?>
                    <!-- Discussion Form -->
                    <div class="discussion-form">
                        <h2 style="margin-bottom: 2rem; color: var(--dark); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-<?php echo $action === 'create' ? 'plus-circle' : 'edit'; ?>"></i>
                            <?php echo $action === 'create' ? 'Start New Discussion' : 'Edit Discussion'; ?>
                        </h2>

                        <form method="post" action="">
                            <input type="hidden" name="<?php echo $action === 'create' ? 'create_discussion' : 'update_discussion'; ?>" value="1">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title"
                                    value="<?php echo htmlspecialchars($discussion['title'] ?? ''); ?>"
                                    placeholder="What would you like to discuss?" required>
                            </div>

                            <div class="form-group">
                                <label for="content">Content *</label>
                                <textarea id="content" name="content"
                                    placeholder="Share your thoughts, questions, or ideas..." required><?php echo htmlspecialchars($discussion['content'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-row">
                                <?php if ($action === 'create'): ?>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="is_pinned" name="is_pinned" value="1">
                                            <label for="is_pinned">Pin this discussion</label>
                                        </div>
                                        <small style="display: block; margin-top: 0.25rem; color: var(--gray);">
                                            Pinned discussions appear at the top of the list
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="is_pinned" name="is_pinned" value="1"
                                                <?php echo ($discussion['is_pinned'] ?? 0) ? 'checked' : ''; ?>>
                                            <label for="is_pinned">Pin this discussion</label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="is_locked" name="is_locked" value="1"
                                                <?php echo ($discussion['is_locked'] ?? 0) ? 'checked' : ''; ?>>
                                            <label for="is_locked">Lock this discussion</label>
                                        </div>
                                        <small style="display: block; margin-top: 0.25rem; color: var(--gray);">
                                            Locked discussions cannot receive new replies
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="form-actions">
                                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-<?php echo $action === 'create' ? 'paper-plane' : 'save'; ?>"></i>
                                    <?php echo $action === 'create' ? 'Start Discussion' : 'Save Changes'; ?>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php elseif ($action === 'view' && $discussion): ?>
                    <!-- Discussion View -->
                    <div class="discussion-view">
                        <div class="discussion-view-header">
                            <div style="flex: 1;">
                                <div class="discussion-view-title">
                                    <?php echo htmlspecialchars($discussion['title']); ?>
                                    <?php if ($discussion['is_pinned']): ?>
                                        <span class="status-badge status-pinned">
                                            <i class="fas fa-thumbtack"></i> Pinned
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($discussion['is_locked']): ?>
                                        <span class="status-badge status-locked">
                                            <i class="fas fa-lock"></i> Locked
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="discussion-view-meta">
                                    <div class="discussion-author">
                                        <div class="author-avatar">
                                            <?php if ($discussion['author_image']): ?>
                                                <img src="<?php echo htmlspecialchars($discussion['author_image']); ?>" alt="<?php echo htmlspecialchars($discussion['author_name']); ?>">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($discussion['author_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <span><?php echo htmlspecialchars($discussion['author_name']); ?></span>
                                    </div>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y g:i A', strtotime($discussion['created_at'])); ?></span>
                                    <span><i class="fas fa-eye"></i> <?php echo $discussion['views_count'] + 1; ?> views</span>
                                    <span><i class="fas fa-comment"></i> <?php echo $discussion['replies_count']; ?> replies</span>
                                </div>
                            </div>

                            <div class="discussion-view-actions">
                                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=edit&id=<?php echo $discussion_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button type="button" class="btn btn-danger" onclick="showDeleteModal(<?php echo $discussion_id; ?>, '<?php echo htmlspecialchars(addslashes($discussion['title'])); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>

                        <div class="discussion-view-content">
                            <?php echo nl2br(htmlspecialchars($discussion['content'])); ?>
                        </div>

                        <!-- Discussion Actions -->
                        <div class="discussion-view-actions">
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">
                                <input type="hidden" name="is_pinned" value="<?php echo $discussion['is_pinned'] ? '0' : '1'; ?>">
                                <button type="submit" name="toggle_pin" class="btn btn-<?php echo $discussion['is_pinned'] ? 'warning' : 'secondary'; ?>">
                                    <i class="fas fa-thumbtack"></i>
                                    <?php echo $discussion['is_pinned'] ? 'Unpin' : 'Pin'; ?>
                                </button>
                            </form>

                            <form method="post" style="display: inline;">
                                <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">
                                <input type="hidden" name="is_locked" value="<?php echo $discussion['is_locked'] ? '0' : '1'; ?>">
                                <button type="submit" name="toggle_lock" class="btn btn-<?php echo $discussion['is_locked'] ? 'warning' : 'danger'; ?>">
                                    <i class="fas fa-<?php echo $discussion['is_locked'] ? 'unlock' : 'lock'; ?>"></i>
                                    <?php echo $discussion['is_locked'] ? 'Unlock' : 'Lock'; ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Replies Section -->
                    <div class="replies-section">
                        <h3>
                            <i class="fas fa-comments"></i>
                            Replies (<?php echo count($replies); ?>)
                        </h3>

                        <?php if (!$discussion['is_locked']): ?>
                            <!-- Reply Form -->
                            <div class="reply-form">
                                <form method="post" action="">
                                    <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">

                                    <div class="form-group">
                                        <label for="reply_content">Your Reply *</label>
                                        <textarea id="reply_content" name="content"
                                            placeholder="Write your reply here..." required></textarea>
                                    </div>

                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="is_instructor_answer" name="is_instructor_answer" value="1">
                                            <label for="is_instructor_answer">Mark as instructor answer</label>
                                        </div>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" name="post_reply" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Post Reply
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-lock"></i>
                                This discussion is locked. No new replies can be posted.
                            </div>
                        <?php endif; ?>

                        <!-- Replies List -->
                        <div class="reply-list">
                            <?php if (empty($replies)): ?>
                                <div class="empty-state" style="padding: 2rem;">
                                    <i class="fas fa-comment-slash"></i>
                                    <p>No replies yet. Be the first to respond!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($replies as $reply): ?>
                                    <div class="reply-card <?php echo $reply['is_instructor_answer'] ? 'answer' : ($reply['author_role'] === 'instructor' ? 'instructor' : ''); ?>">
                                        <div class="reply-header">
                                            <div class="reply-author">
                                                <div class="reply-avatar">
                                                    <?php if ($reply['author_image']): ?>
                                                        <img src="<?php echo htmlspecialchars($reply['author_image']); ?>" alt="<?php echo htmlspecialchars($reply['author_name']); ?>">
                                                    <?php else: ?>
                                                        <?php echo strtoupper(substr($reply['author_name'], 0, 1)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="reply-author-info">
                                                    <h4>
                                                        <?php echo htmlspecialchars($reply['author_name']); ?>
                                                        <?php if ($reply['author_role'] === 'instructor'): ?>
                                                            <span class="status-badge status-pinned" style="background: rgba(59, 130, 246, 0.1); color: var(--primary); font-size: 0.7rem;">
                                                                <i class="fas fa-chalkboard-teacher"></i> Instructor
                                                            </span>
                                                        <?php endif; ?>
                                                    </h4>
                                                    <p><?php echo $reply['author_role'] === 'instructor' ? 'Instructor' : 'Student'; ?></p>
                                                </div>
                                            </div>

                                            <div class="reply-actions">
                                                <?php if ($reply['is_instructor_answer']): ?>
                                                    <span class="status-badge status-answered">
                                                        <i class="fas fa-check-circle"></i> Answer
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($reply['user_id'] == $instructor_id || $instructor_id == $_SESSION['user_id']): ?>
                                                    <form method="post" style="display: inline;">
                                                        <input type="hidden" name="reply_id" value="<?php echo $reply['id']; ?>">
                                                        <input type="hidden" name="discussion_id" value="<?php echo $discussion_id; ?>">
                                                        <input type="hidden" name="is_instructor_answer" value="<?php echo $reply['is_instructor_answer'] ? '0' : '1'; ?>">
                                                        <button type="submit" name="toggle_answer" class="btn btn-<?php echo $reply['is_instructor_answer'] ? 'warning' : 'success'; ?> btn-sm">
                                                            <i class="fas fa-<?php echo $reply['is_instructor_answer'] ? 'times' : 'check'; ?>"></i>
                                                            <?php echo $reply['is_instructor_answer'] ? 'Unmark Answer' : 'Mark as Answer'; ?>
                                                        </button>
                                                    </form>

                                                    <button type="button" class="btn btn-danger btn-sm"
                                                        onclick="showDeleteReplyModal(<?php echo $reply['id']; ?>, <?php echo $discussion_id; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="reply-content">
                                            <?php echo nl2br(htmlspecialchars($reply['content'])); ?>
                                        </div>

                                        <div class="reply-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo time_ago($reply['created_at']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Discussions List -->
                    <div class="controls">
                        <form method="get" action="" class="search-box">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="text" name="search" placeholder="Search discussions..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </form>

                        <div class="filter-group">
                            <a href="?class_id=<?php echo $class_id; ?>&filter=all"
                                class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">
                                All
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=pinned"
                                class="btn <?php echo $filter === 'pinned' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Pinned
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=unanswered"
                                class="btn <?php echo $filter === 'unanswered' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Unanswered
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=answered"
                                class="btn <?php echo $filter === 'answered' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Answered
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> New Discussion
                            </a>
                        </div>
                    </div>

                    <?php if (empty($discussions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <h3>No discussions found</h3>
                            <p>
                                <?php if (!empty($search)): ?>
                                    No discussions match your search criteria.
                                <?php elseif ($filter !== 'all'): ?>
                                    No discussions match the selected filter.
                                <?php else: ?>
                                    No discussions have been started yet.
                                <?php endif; ?>
                            </p>
                            <?php if (empty($search) && $filter === 'all'): ?>
                                <a href="?class_id=<?php echo $class_id; ?>&action=create" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-plus-circle"></i> Start First Discussion
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="discussions-list">
                            <?php foreach ($discussions as $disc):
                                $card_class = '';
                                if ($disc['is_pinned']) $card_class .= ' pinned';
                                if ($disc['answer_count'] > 0) $card_class .= ' answered';
                                if ($disc['is_locked']) $card_class .= ' locked';
                            ?>
                                <div class="discussion-card<?php echo $card_class; ?>">
                                    <div class="discussion-content">
                                        <div class="discussion-stats">
                                            <div>
                                                <div class="stat-number"><?php echo $disc['reply_count']; ?></div>
                                                <div class="stat-label-small">Replies</div>
                                            </div>
                                            <div>
                                                <div class="stat-number"><?php echo $disc['views_count']; ?></div>
                                                <div class="stat-label-small">Views</div>
                                            </div>
                                        </div>

                                        <div class="discussion-details">
                                            <div class="discussion-title">
                                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $disc['id']; ?>">
                                                    <?php echo htmlspecialchars($disc['title']); ?>
                                                </a>
                                                <?php if ($disc['is_pinned']): ?>
                                                    <span class="status-badge status-pinned">
                                                        <i class="fas fa-thumbtack"></i> Pinned
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($disc['is_locked']): ?>
                                                    <span class="status-badge status-locked">
                                                        <i class="fas fa-lock"></i> Locked
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($disc['answer_count'] > 0): ?>
                                                    <span class="status-badge status-answered">
                                                        <i class="fas fa-check-circle"></i> Answered
                                                    </span>
                                                <?php elseif ($disc['reply_count'] == 0): ?>
                                                    <span class="status-badge status-unanswered">
                                                        <i class="fas fa-question-circle"></i> Unanswered
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="discussion-preview">
                                                <?php
                                                $content = strip_tags($disc['content']);
                                                echo htmlspecialchars(strlen($content) > 150 ? substr($content, 0, 150) . '...' : $content);
                                                ?>
                                            </div>

                                            <div class="discussion-meta">
                                                <div class="discussion-author">
                                                    <div class="author-avatar">
                                                        <?php if ($disc['author_image']): ?>
                                                            <img src="<?php echo htmlspecialchars($disc['author_image']); ?>" alt="<?php echo htmlspecialchars($disc['author_name']); ?>">
                                                        <?php else: ?>
                                                            <?php echo strtoupper(substr($disc['author_name'], 0, 1)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span><?php echo htmlspecialchars($disc['author_name']); ?></span>
                                                    <span><i class="fas fa-clock"></i> <?php echo time_ago($disc['created_at']); ?></span>
                                                    <?php if ($disc['last_replier'] && $disc['last_reply_at']): ?>
                                                        <span>Last reply by <?php echo htmlspecialchars($disc['last_replier']); ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="discussion-actions">
                                                    <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $disc['id']; ?>"
                                                        class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=edit&id=<?php echo $disc['id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <form method="post" style="display: inline;"
                                                        onsubmit="return confirm('Are you sure you want to <?php echo $disc['is_pinned'] ? 'unpin' : 'pin'; ?> this discussion?');">
                                                        <input type="hidden" name="discussion_id" value="<?php echo $disc['id']; ?>">
                                                        <input type="hidden" name="is_pinned" value="<?php echo $disc['is_pinned'] ? '0' : '1'; ?>">
                                                        <button type="submit" name="toggle_pin" class="btn btn-<?php echo $disc['is_pinned'] ? 'warning' : 'secondary'; ?> btn-sm">
                                                            <i class="fas fa-thumbtack"></i>
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-danger btn-sm"
                                                        onclick="showDeleteModal(<?php echo $disc['id']; ?>, '<?php echo htmlspecialchars(addslashes($disc['title'])); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
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
                            <i class="fas fa-plus-circle"></i> New Discussion
                        </a>
                        <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn btn-secondary" style="justify-content: center;">
                            <i class="fas fa-home"></i> Class Home
                        </a>
                    </div>
                </div>

                <!-- Recent Discussions -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-history"></i> Recent Discussions</h3>
                    <?php if (empty($recent_discussions)): ?>
                        <p style="color: var(--gray); text-align: center; padding: 1rem 0;">No recent discussions</p>
                    <?php else: ?>
                        <ul class="sidebar-list">
                            <?php foreach ($recent_discussions as $recent): ?>
                                <li>
                                    <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $recent['id']; ?>">
                                        <strong><?php echo htmlspecialchars(substr($recent['title'], 0, 50)); ?><?php echo strlen($recent['title']) > 50 ? '...' : ''; ?></strong>
                                        <div class="meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($recent['author_name']); ?></span>
                                            <span><i class="fas fa-clock"></i> <?php echo time_ago($recent['created_at']); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Top Contributors -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-trophy"></i> Top Contributors</h3>
                    <?php if (empty($top_contributors)): ?>
                        <p style="color: var(--gray); text-align: center; padding: 1rem 0;">No contributors yet</p>
                    <?php else: ?>
                        <div class="contributor-list">
                            <?php foreach ($top_contributors as $contributor): ?>
                                <div class="contributor-item">
                                    <div class="contributor-avatar">
                                        <?php if ($contributor['profile_image']): ?>
                                            <img src="<?php echo htmlspecialchars($contributor['profile_image']); ?>" alt="<?php echo htmlspecialchars($contributor['first_name'] . ' ' . $contributor['last_name']); ?>">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($contributor['first_name'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="contributor-info">
                                        <h4><?php echo htmlspecialchars($contributor['first_name'] . ' ' . $contributor['last_name']); ?></h4>
                                        <p><?php echo $contributor['role'] === 'instructor' ? 'Instructor' : 'Student'; ?></p>
                                    </div>
                                    <div class="contributor-stats">
                                        <i class="fas fa-comment"></i> <?php echo $contributor['reply_count']; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Discussion Tips -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-lightbulb"></i> Discussion Tips</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Use clear, descriptive titles
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Mark helpful replies as answers
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Pin important announcements
                        </li>
                        <li style="padding: 0.5rem 0;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Lock resolved discussions
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Discussion Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Discussion</h3>
            </div>
            <div class="modal-content">
                <p>Are you sure you want to delete the discussion "<strong id="deleteDiscussionTitle"></strong>"?</p>
                <p style="color: var(--danger); font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-circle"></i> This will also delete all replies. This action cannot be undone.
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                <form method="post" id="deleteForm" style="display: inline;">
                    <input type="hidden" name="discussion_id" id="deleteDiscussionId">
                    <button type="submit" name="delete_discussion" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Discussion
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Reply Modal -->
    <div class="modal-overlay" id="deleteReplyModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Reply</h3>
            </div>
            <div class="modal-content">
                <p>Are you sure you want to delete this reply?</p>
                <p style="color: var(--danger); font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteReplyModal()">Cancel</button>
                <form method="post" id="deleteReplyForm" style="display: inline;">
                    <input type="hidden" name="reply_id" id="deleteReplyId">
                    <input type="hidden" name="discussion_id" id="deleteReplyDiscussionId">
                    <button type="submit" name="delete_reply" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Reply
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

        // Delete discussion modal functions
        function showDeleteModal(id, title) {
            document.getElementById('deleteDiscussionId').value = id;
            document.getElementById('deleteDiscussionTitle').textContent = title;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Delete reply modal functions
        function showDeleteReplyModal(replyId, discussionId) {
            document.getElementById('deleteReplyId').value = replyId;
            document.getElementById('deleteReplyDiscussionId').value = discussionId;
            document.getElementById('deleteReplyModal').style.display = 'flex';
        }

        function hideDeleteReplyModal() {
            document.getElementById('deleteReplyModal').style.display = 'none';
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'deleteModal') hideDeleteModal();
                    if (this.id === 'deleteReplyModal') hideDeleteReplyModal();
                }
            });
        });

        // Auto-expand textareas
        const textareas = document.querySelectorAll('textarea');
        textareas.forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            // Trigger once on load
            setTimeout(() => {
                textarea.dispatchEvent(new Event('input'));
            }, 100);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new discussion
            if (e.ctrlKey && e.key === 'n' && !window.location.href.includes('action=create')) {
                e.preventDefault();
                window.location.href = '?class_id=<?php echo $class_id; ?>&action=create';
            }

            // Esc to close modals
            if (e.key === 'Escape') {
                hideDeleteModal();
                hideDeleteReplyModal();
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

            // Ctrl + Enter to submit forms
            if (e.ctrlKey && e.key === 'Enter') {
                const activeElement = document.activeElement;
                if (activeElement && activeElement.tagName === 'TEXTAREA') {
                    const form = activeElement.closest('form');
                    if (form) {
                        e.preventDefault();
                        const submitButton = form.querySelector('button[type="submit"]');
                        if (submitButton) submitButton.click();
                    }
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

        // Focus reply textarea when clicking "Reply" button
        const replyButtons = document.querySelectorAll('[data-focus-reply]');
        replyButtons.forEach(button => {
            button.addEventListener('click', function() {
                const replyTextarea = document.getElementById('reply_content');
                if (replyTextarea) {
                    replyTextarea.focus();
                    window.scrollTo({
                        top: replyTextarea.offsetTop - 100,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Markdown preview (basic)
        const contentTextarea = document.getElementById('content');
        if (contentTextarea) {
            contentTextarea.addEventListener('keydown', function(e) {
                // Auto-indent for lists
                if (e.key === 'Enter') {
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    const value = this.value;

                    // Check if previous line starts with - or *
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const line = value.substring(lineStart, start);

                    if (line.match(/^\s*[-*]\s/)) {
                        e.preventDefault();
                        const indent = line.match(/^(\s*)/)[0];
                        this.value = value.substring(0, start) + '\n' + indent + '- ' + value.substring(end);
                        this.selectionStart = this.selectionEnd = start + indent.length + 3;
                    }
                }
            });
        }

        // Auto-save draft (local storage)
        const discussionForm = document.querySelector('.discussion-form form');
        if (discussionForm && window.location.href.includes('action=create')) {
            const saveKey = 'discussion_draft_<?php echo $class_id; ?>';

            // Load draft
            const draft = localStorage.getItem(saveKey);
            if (draft) {
                try {
                    const data = JSON.parse(draft);
                    document.getElementById('title').value = data.title || '';
                    document.getElementById('content').value = data.content || '';

                    // Show notification
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-info';
                    notification.innerHTML = `
                        <i class="fas fa-save"></i>
                        Draft restored from your last session. 
                        <button type="button" class="btn btn-sm btn-secondary" onclick="clearDraft()" style="margin-left: 1rem;">
                            Clear Draft
                        </button>
                    `;
                    discussionForm.parentElement.insertBefore(notification, discussionForm);
                } catch (e) {
                    console.error('Failed to load draft:', e);
                }
            }

            // Save draft
            function saveDraft() {
                const data = {
                    title: document.getElementById('title').value,
                    content: document.getElementById('content').value,
                    timestamp: new Date().toISOString()
                };
                localStorage.setItem(saveKey, JSON.stringify(data));
            }

            // Auto-save on input (with debounce)
            let saveTimeout;
            const inputs = discussionForm.querySelectorAll('input, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', () => {
                    clearTimeout(saveTimeout);
                    saveTimeout = setTimeout(saveDraft, 1000);
                });
            });

            // Clear draft on successful submit
            discussionForm.addEventListener('submit', () => {
                localStorage.removeItem(saveKey);
            });

            // Clear draft function
            window.clearDraft = function() {
                localStorage.removeItem(saveKey);
                document.getElementById('title').value = '';
                document.getElementById('content').value = '';
                const notification = document.querySelector('.alert-info');
                if (notification) notification.remove();
            };
        }
    </script>
</body>

</html>