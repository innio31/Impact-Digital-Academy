<?php
// modules/instructor/announcements/view.php

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
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get announcement ID
$announcement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$announcement_id) {
    header('Location: ' . BASE_URL . 'modules/instructor/announcements/');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Check if announcement requires acknowledgment
$requires_acknowledgment = $announcement['requires_acknowledgment'] ?? 0;

// Check if already acknowledged
$is_acknowledged = false;
try {
    $sql = "SELECT 1 FROM announcement_acknowledgments 
            WHERE announcement_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $announcement_id, $instructor_id);
    $stmt->execute();
    $is_acknowledged = $stmt->get_result()->num_rows > 0;
    $stmt->close();
} catch (Exception $e) {
    error_log("Acknowledgment check error: " . $e->getMessage());
}

// Process acknowledgment if requested
if (isset($_POST['acknowledge']) && !$is_acknowledged && $requires_acknowledgment) {
    try {
        // Start transaction
        $conn->begin_transaction();

        // Record acknowledgment
        $sql = "INSERT INTO announcement_acknowledgments 
                (announcement_id, user_id, acknowledged_at, ip_address, user_agent) 
                VALUES (?, ?, NOW(), ?, ?)";
        $stmt = $conn->prepare($sql);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt->bind_param("iiss", $announcement_id, $instructor_id, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();

        // Update acknowledgment count
        $sql = "UPDATE announcements 
                SET acknowledged_count = acknowledged_count + 1 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $announcement_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $is_acknowledged = true;

        // Log activity
        logActivity(
            'announcement_acknowledged',
            'Acknowledged announcement: ' . $announcement['title'],
            $announcement_id
        );

        // Set success message
        $_SESSION['success'] = 'Announcement acknowledged successfully!';

        // Redirect to prevent form resubmission
        header('Location: view.php?id=' . $announcement_id);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Acknowledgment error: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to acknowledge announcement. Please try again.';
    }
}

// Get acknowledgment stats
$acknowledgment_stats = [
    'total_acknowledged' => 0,
    'pending_count' => 0
];

if ($requires_acknowledgment) {
    // Get total acknowledgments
    $sql = "SELECT COUNT(*) as total FROM announcement_acknowledgments 
            WHERE announcement_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $acknowledgment_stats['total_acknowledged'] = $row['total'];
    }
    $stmt->close();

    // Get pending count (if we have total users who should see this)
    // This depends on your system structure - adjust as needed
    if ($announcement['class_id']) {
        $sql = "SELECT COUNT(DISTINCT e.student_id) as total_students 
                FROM enrollments e 
                WHERE e.class_id = ? AND e.status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $announcement['class_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $total_students = $row['total_students'];
            $acknowledgment_stats['pending_count'] = max(0, $total_students - $acknowledgment_stats['total_acknowledged']);
        }
        $stmt->close();
    }
}

// Get announcement details
$announcement = null;
$sql = "SELECT a.*, 
               cb.batch_code, 
               cb.name as class_name,
               cb.instructor_id as class_instructor,
               c.title as course_title,
               c.course_code,
               CONCAT(u.first_name, ' ', u.last_name) as author_name,
               u.role as author_role,
               u.email as author_email,
               u.profile_image as author_image,
               (SELECT COUNT(*) FROM notifications WHERE related_id = a.id AND type = 'announcement') as notification_count
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        LEFT JOIN courses c ON cb.course_id = c.id 
        LEFT JOIN users u ON a.author_id = u.id 
        WHERE a.id = ? AND (a.class_id IS NULL OR cb.instructor_id = ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $announcement_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    $_SESSION['error'] = 'Announcement not found or you do not have permission to view it.';
    header('Location: ' . BASE_URL . 'modules/instructor/announcements/');
    exit();
}

$announcement = $result->fetch_assoc();
$stmt->close();

// Get related announcements (same class or same author)
$related_announcements = [];
$sql = "SELECT a.id, a.title, a.created_at, a.priority,
               cb.batch_code,
               CONCAT(u.first_name, ' ', u.last_name) as author_name
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        LEFT JOIN users u ON a.author_id = u.id 
        WHERE a.id != ? 
        AND (a.class_id = ? OR a.author_id = ?)
        AND a.is_published = 1
        ORDER BY a.created_at DESC 
        LIMIT 5";

$class_id = $announcement['class_id'] ?? 0;
$author_id = $announcement['author_id'] ?? 0;

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $announcement_id, $class_id, $author_id);
$stmt->execute();
$result = $stmt->get_result();
$related_announcements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get announcement views (if tracking table exists)
$views_count = 0;
try {
    // First check if table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'announcement_views'");
    if ($check_table->num_rows > 0) {
        $sql = "SELECT COUNT(*) as views FROM announcement_views WHERE announcement_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $announcement_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $views_count = $row['views'] ?? 0;
        $stmt->close();

        // Record this view
        $sql = "INSERT INTO announcement_views (announcement_id, user_id, viewed_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE viewed_at = NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $announcement_id, $instructor_id);
        $stmt->execute();
        $stmt->close();
    }
} catch (Exception $e) {
    // Table doesn't exist or error, ignore
    error_log("Announcement views tracking error: " . $e->getMessage());
}

// Get comments/discussion for announcement (if enabled)
$comments = [];
try {
    // Check if table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'announcement_comments'");
    if ($check_table->num_rows > 0) {
        $sql = "SELECT ac.*, 
                       CONCAT(u.first_name, ' ', u.last_name) as commenter_name,
                       u.role as commenter_role,
                       u.profile_image as commenter_image
                FROM announcement_comments ac
                JOIN users u ON ac.user_id = u.id
                WHERE ac.announcement_id = ?
                ORDER BY ac.created_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $announcement_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Exception $e) {
    // Table doesn't exist or error, ignore
    error_log("Announcement comments error: " . $e->getMessage());
}

$conn->close();

// Format dates
$created_date = date('F j, Y', strtotime($announcement['created_at']));
$created_time = date('g:i A', strtotime($announcement['created_at']));
$publish_date = $announcement['publish_date'] ? date('F j, Y g:i A', strtotime($announcement['publish_date'])) : 'Not published';
$expiry_date = $announcement['expiry_date'] ? date('F j, Y', strtotime($announcement['expiry_date'])) : 'No expiry';

// Determine status
$status = 'Draft';
if ($announcement['is_published']) {
    $status = 'Published';
    if ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) {
        $status = 'Expired';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($announcement['title']); ?> - Announcement Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Announcement Detail */
        .announcement-detail {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .announcement-title-section {
            flex: 1;
        }

        .announcement-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.3;
        }

        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            font-size: 0.875rem;
        }

        .announcement-badges {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .badge-priority-high {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status-published {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status-draft {
            background: #f3f4f6;
            color: #4b5563;
        }

        .badge-status-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-author-admin {
            background: #e0e7ff;
            color: #3730a3;
        }

        .badge-author-instructor {
            background: #f0f9ff;
            color: #0369a1;
        }

        .badge-notification {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .author-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem;
            background: var(--light);
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .author-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .author-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .author-details {
            flex: 1;
        }

        .author-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .author-role {
            color: var(--gray);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .author-contact {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
        }

        .author-contact a {
            color: var(--primary);
            text-decoration: none;
        }

        .author-contact a:hover {
            text-decoration: underline;
        }

        .announcement-content {
            line-height: 1.8;
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 2rem;
        }

        .announcement-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .announcement-content h2,
        .announcement-content h3,
        .announcement-content h4 {
            margin: 1.5rem 0 1rem;
            color: var(--dark);
        }

        .announcement-content ul,
        .announcement-content ol {
            margin: 1rem 0 1rem 2rem;
        }

        .announcement-content blockquote {
            border-left: 4px solid var(--primary);
            padding-left: 1.5rem;
            margin: 1.5rem 0;
            color: var(--gray);
            font-style: italic;
        }

        .announcement-content pre {
            background: var(--dark);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1.5rem 0;
        }

        .announcement-content code {
            background: var(--light);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }

        .announcement-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
            padding: 1.5rem;
            background: var(--light);
            border-radius: 10px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .announcement-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        /* Related Announcements */
        .related-announcements {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .related-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .related-item {
            padding: 1.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .related-item:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.1);
        }

        .related-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .related-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Comments Section */
        .comments-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .comment-form {
            margin-bottom: 2rem;
        }

        .comment-input {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            font-size: 1rem;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 1rem;
        }

        .comment-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .comments-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .comment-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .comment-item:last-child {
            border-bottom: none;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .commenter-avatar {
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

        .commenter-info {
            flex: 1;
        }

        .commenter-name {
            font-weight: 600;
            color: var(--dark);
        }

        .commenter-role {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .comment-time {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .comment-content {
            line-height: 1.6;
            color: var(--dark);
        }

        /* Back button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .back-button:hover {
            color: var(--secondary);
        }

        /* Print styles */
        @media print {

            .header-actions,
            .announcement-actions,
            .related-announcements,
            .comments-section {
                display: none;
            }

            .announcement-detail {
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Back Button -->
        <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/index.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Announcements
        </a>

        <!-- Header -->
        <div class="header">
            <div>
                <h1><i class="fas fa-bullhorn"></i> Announcement Details</h1>
                <p style="color: var(--gray); margin-top: 0.5rem;">
                    Viewing announcement details
                </p>
            </div>

            <div class="header-actions">
                <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/" class="btn btn-secondary">
                    <i class="fas fa-list"></i> All Announcements
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
                <button onclick="shareAnnouncement()" class="btn btn-primary">
                    <i class="fas fa-share"></i> Share
                </button>
            </div>
        </div>

        <!-- Announcement Detail -->
        <div class="announcement-detail">
            <div class="announcement-header">
                <div class="announcement-title-section">
                    <h2 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h2>

                    <div class="announcement-badges">
                        <span class="badge badge-priority-<?php echo $announcement['priority']; ?>">
                            <i class="fas fa-flag"></i> <?php echo ucfirst($announcement['priority']); ?> Priority
                        </span>

                        <span class="badge badge-status-<?php echo strtolower($status); ?>">
                            <?php if ($status === 'Published'): ?>
                                <i class="fas fa-check"></i> Published
                            <?php elseif ($status === 'Draft'): ?>
                                <i class="fas fa-clock"></i> Draft
                            <?php else: ?>
                                <i class="fas fa-clock"></i> Expired
                            <?php endif; ?>
                        </span>

                        <span class="badge badge-author-<?php echo strtolower($announcement['author_role']); ?>">
                            <i class="fas fa-user"></i> <?php echo ucfirst($announcement['author_role']); ?>
                        </span>

                        <?php if ($announcement['notification_count'] > 0): ?>
                            <span class="badge badge-notification">
                                <i class="fas fa-bell"></i> <?php echo $announcement['notification_count']; ?> notified
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="announcement-meta">
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span>Created: <?php echo $created_date; ?> at <?php echo $created_time; ?></span>
                        </div>

                        <?php if ($announcement['publish_date']): ?>
                            <div class="meta-item">
                                <i class="fas fa-upload"></i>
                                <span>Published: <?php echo date('F j, Y g:i A', strtotime($announcement['publish_date'])); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($announcement['class_name']): ?>
                            <div class="meta-item">
                                <i class="fas fa-chalkboard"></i>
                                <span>Class: <?php echo htmlspecialchars($announcement['batch_code'] . ' - ' . $announcement['course_title']); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="meta-item">
                                <i class="fas fa-globe"></i>
                                <span>All Classes</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($announcement['expiry_date']): ?>
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                <span>Expires: <?php echo $expiry_date; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Author Info -->
            <div class="author-info">
                <div class="author-avatar">
                    <?php if ($announcement['author_image']): ?>
                        <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($announcement['author_image']); ?>" alt="<?php echo htmlspecialchars($announcement['author_name']); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($announcement['author_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="author-details">
                    <div class="author-name"><?php echo htmlspecialchars($announcement['author_name']); ?></div>
                    <div class="author-role"><?php echo ucfirst($announcement['author_role']); ?></div>
                    <div class="author-contact">
                        <a href="mailto:<?php echo htmlspecialchars($announcement['author_email']); ?>">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($announcement['author_email']); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Announcement Content -->
            <div class="announcement-content">
                <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
            </div>

            <!-- Statistics -->
            <div class="announcement-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $views_count; ?></div>
                    <div class="stat-label">Views</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $announcement['notification_count']; ?></div>
                    <div class="stat-label">Notified</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo count($comments); ?></div>
                    <div class="stat-label">Comments</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $status; ?></div>
                    <div class="stat-label">Status</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="announcement-actions">
                <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>

                <?php if ($announcement['class_instructor'] == $instructor_id): ?>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/edit.php?id=<?php echo $announcement['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Announcement
                    </a>
                <?php endif; ?>

                <button onclick="shareAnnouncement()" class="btn btn-success">
                    <i class="fas fa-share"></i> Share
                </button>

                <button onclick="copyLink()" class="btn btn-secondary">
                    <i class="fas fa-link"></i> Copy Link
                </button>
            </div>

        </div>

        <!-- Related Announcements -->
        <?php if (!empty($related_announcements)): ?>
            <div class="related-announcements">
                <h3 class="section-title">Related Announcements</h3>
                <div class="related-list">
                    <?php foreach ($related_announcements as $related): ?>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/view.php?id=<?php echo $related['id']; ?>" class="related-item">
                            <h4 class="related-title"><?php echo htmlspecialchars($related['title']); ?></h4>
                            <div class="meta-item">
                                <i class="fas fa-user"></i>
                                <span><?php echo htmlspecialchars($related['author_name']); ?></span>
                            </div>
                            <div class="related-meta">
                                <span class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo time_ago($related['created_at']); ?>
                                </span>
                                <?php if ($related['batch_code']): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-chalkboard"></i>
                                        <?php echo htmlspecialchars($related['batch_code']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Comments Section -->
        <?php if (!empty($comments)): ?>
            <div class="comments-section">
                <h3 class="section-title">Comments & Discussion</h3>
                <div class="comments-list">
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-header">
                                <div class="commenter-avatar">
                                    <?php if ($comment['commenter_image']): ?>
                                        <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($comment['commenter_image']); ?>" alt="<?php echo htmlspecialchars($comment['commenter_name']); ?>">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($comment['commenter_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="commenter-info">
                                    <div class="commenter-name"><?php echo htmlspecialchars($comment['commenter_name']); ?></div>
                                    <div class="commenter-role"><?php echo ucfirst($comment['commenter_role']); ?></div>
                                </div>
                                <div class="comment-time">
                                    <?php echo time_ago($comment['created_at']); ?>
                                </div>
                            </div>
                            <div class="comment-content">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Share announcement
        function shareAnnouncement() {
            const title = '<?php echo addslashes($announcement['title']); ?>';
            const url = window.location.href;

            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: 'Check out this announcement from Impact Digital Academy',
                    url: url
                });
            } else {
                // Fallback for browsers that don't support Web Share API
                navigator.clipboard.writeText(url).then(() => {
                    alert('Link copied to clipboard!');
                }).catch(() => {
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = url;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    alert('Link copied to clipboard!');
                });
            }
        }

        // Copy link to clipboard
        function copyLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                alert('Link copied to clipboard!');
            }).catch(() => {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Link copied to clipboard!');
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Esc to go back
            if (e.key === 'Escape') {
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/announcements/';
            }

            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }

            // Ctrl + C to copy link
            if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                copyLink();
            }

            // Ctrl + S to share
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                shareAnnouncement();
            }
        });

        // Add back to top button
        const backToTopButton = document.createElement('button');
        backToTopButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
        backToTopButton.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(59,130,246,0.3);
            transition: all 0.3s ease;
        `;

        backToTopButton.onclick = () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        };

        document.body.appendChild(backToTopButton);

        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                backToTopButton.style.display = 'flex';
            } else {
                backToTopButton.style.display = 'none';
            }
        });

        // Highlight important sections
        document.addEventListener('DOMContentLoaded', function() {
            const content = document.querySelector('.announcement-content');
            const text = content.textContent;

            // Highlight dates
            const dateRegex = /\b(\d{1,2}\/\d{1,2}\/\d{4}|\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4})\b/gi;
            content.innerHTML = content.innerHTML.replace(dateRegex, '<span style="background: #fef3c7; padding: 0.2rem 0.4rem; border-radius: 4px; font-weight: 600;">$&</span>');

            // Highlight times
            const timeRegex = /\b(\d{1,2}:\d{2}\s*(AM|PM|am|pm)?)\b/gi;
            content.innerHTML = content.innerHTML.replace(timeRegex, '<span style="background: #dbeafe; padding: 0.2rem 0.4rem; border-radius: 4px; font-weight: 600;">$&</span>');
        });
    </script>
</body>

</html>