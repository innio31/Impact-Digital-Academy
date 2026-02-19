<?php
// modules/shared/mail/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get current user info
$current_user = [
    'id' => $_SESSION['user_id'],
    'name' => $_SESSION['user_name'] ?? 'User',
    'email' => $_SESSION['user_email'] ?? '',
    'role' => $_SESSION['user_role'] ?? 'student'
];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get folder from URL (default to inbox)
$folder = isset($_GET['folder']) ? sanitize($_GET['folder']) : 'inbox';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);

    if (isset($_POST['message_ids']) && is_array($_POST['message_ids'])) {
        $message_ids = array_map('intval', $_POST['message_ids']);
        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));

        switch ($action) {
            case 'mark_as_read':
                $sql = "UPDATE internal_messages SET is_read = 1, read_at = NOW() 
                        WHERE id IN ($placeholders) AND receiver_id = ?";
                $params = array_merge($message_ids, [$current_user['id']]);
                break;

            case 'mark_as_unread':
                $sql = "UPDATE internal_messages SET is_read = 0, read_at = NULL 
                        WHERE id IN ($placeholders) AND receiver_id = ?";
                $params = array_merge($message_ids, [$current_user['id']]);
                break;

            case 'move_to_trash':
                // Mark as deleted for receiver
                $sql = "UPDATE internal_messages SET is_deleted_receiver = 1 
                        WHERE id IN ($placeholders) AND receiver_id = ?";
                $params = array_merge($message_ids, [$current_user['id']]);

                // Also mark as deleted for sender if they're viewing sent folder
                $sql2 = "UPDATE internal_messages SET is_deleted_sender = 1 
                         WHERE id IN ($placeholders) AND sender_id = ?";
                $params2 = array_merge($message_ids, [$current_user['id']]);

                $stmt2 = $conn->prepare($sql2);
                $types2 = str_repeat('i', count($params2));
                $stmt2->bind_param($types2, ...$params2);
                $stmt2->execute();
                break;

            case 'delete_forever':
                $sql = "DELETE FROM internal_messages 
                        WHERE id IN ($placeholders) AND (sender_id = ? OR receiver_id = ?)";
                $params = array_merge($message_ids, [$current_user['id'], $current_user['id']]);
                break;

            default:
                $_SESSION['error'] = "Invalid action";
                break;
        }

        if (isset($sql)) {
            $types = str_repeat('i', count($params));
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Action completed successfully";
            } else {
                $_SESSION['error'] = "Failed to complete action";
            }
        }
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

// Get messages based on folder
switch ($folder) {
    case 'inbox':
        $sql = "SELECT m.*, 
                       u_sender.first_name as sender_first_name, 
                       u_sender.last_name as sender_last_name,
                       u_sender.email as sender_email
                FROM internal_messages m
                JOIN users u_sender ON u_sender.id = m.sender_id
                WHERE m.receiver_id = ? 
                AND m.is_deleted_receiver = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";
        $params = [$current_user['id'], $limit, $offset];
        break;

    case 'sent':
        $sql = "SELECT m.*, 
                       u_receiver.first_name as receiver_first_name, 
                       u_receiver.last_name as receiver_last_name,
                       u_receiver.email as receiver_email
                FROM internal_messages m
                JOIN users u_receiver ON u_receiver.id = m.receiver_id
                WHERE m.sender_id = ? 
                AND m.is_deleted_sender = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";
        $params = [$current_user['id'], $limit, $offset];
        break;

    case 'trash':
        $sql = "SELECT m.*, 
                       u_sender.first_name as sender_first_name, 
                       u_sender.last_name as sender_last_name,
                       u_receiver.first_name as receiver_first_name,
                       u_receiver.last_name as receiver_last_name
                FROM internal_messages m
                LEFT JOIN users u_sender ON u_sender.id = m.sender_id
                LEFT JOIN users u_receiver ON u_receiver.id = m.receiver_id
                WHERE (m.receiver_id = ? AND m.is_deleted_receiver = 1)
                   OR (m.sender_id = ? AND m.is_deleted_sender = 1)
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";
        $params = [$current_user['id'], $current_user['id'], $limit, $offset];
        break;

    default:
        $folder = 'inbox';
        $sql = "SELECT m.*, 
                       u_sender.first_name as sender_first_name, 
                       u_sender.last_name as sender_last_name
                FROM internal_messages m
                JOIN users u_sender ON u_sender.id = m.sender_id
                WHERE m.receiver_id = ? 
                AND m.is_deleted_receiver = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";
        $params = [$current_user['id'], $limit, $offset];
}

// Get messages
$stmt = $conn->prepare($sql);
$types = str_repeat('i', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$messages = $result->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$count_sql = preg_replace('/LIMIT \? OFFSET \?$/i', '', $sql);
$count_params = array_slice($params, 0, -2);
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM ($count_sql) as count_table");
if ($count_params) {
    $count_types = str_repeat('i', count($count_params));
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_messages = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_messages / $limit);

// Get unread count
$unread_sql = "SELECT COUNT(*) as unread_count 
               FROM internal_messages 
               WHERE receiver_id = ? 
               AND is_read = 0 
               AND is_deleted_receiver = 0";
$unread_stmt = $conn->prepare($unread_sql);
$unread_stmt->bind_param("i", $current_user['id']);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_count = $unread_result->fetch_assoc()['unread_count'] ?? 0;

// Get recent contacts
$recent_contacts = [];
$sql = "SELECT DISTINCT 
               CASE 
                   WHEN sender_id = ? THEN receiver_id
                   ELSE sender_id
               END as contact_id,
               u.first_name,
               u.last_name,
               u.email,
               u.role
        FROM internal_messages m
        JOIN users u ON u.id = CASE 
                                WHEN sender_id = ? THEN receiver_id
                                ELSE sender_id
                              END
        WHERE (sender_id = ? OR receiver_id = ?)
        AND u.status = 'active'
        AND (u.first_name IS NOT NULL OR u.last_name IS NOT NULL OR u.email IS NOT NULL)
        ORDER BY MAX(m.created_at) DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $current_user['id'], $current_user['id'], $current_user['id'], $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
$recent_contacts = $result->fetch_all(MYSQLI_ASSOC);

// Get folder counts
$folder_counts = [
    'inbox' => ['count' => 0, 'unread' => 0],
    'sent' => ['count' => 0, 'unread' => 0],
    'trash' => ['count' => 0, 'unread' => 0]
];

// Inbox count
$sql = "SELECT 
           COUNT(*) as count,
           SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM internal_messages 
        WHERE receiver_id = ? AND is_deleted_receiver = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
$folder_counts['inbox'] = $result->fetch_assoc();

// Sent count
$sql = "SELECT COUNT(*) as count
        FROM internal_messages 
        WHERE sender_id = ? AND is_deleted_sender = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
$folder_counts['sent'] = $result->fetch_assoc();

// Trash count
$sql = "SELECT COUNT(*) as count
        FROM internal_messages 
        WHERE (receiver_id = ? AND is_deleted_receiver = 1)
           OR (sender_id = ? AND is_deleted_sender = 1)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $current_user['id'], $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
$folder_counts['trash'] = $result->fetch_assoc();

// Log activity
logActivity($current_user['id'], 'mail_access', 'Accessed mail inbox', $_SERVER['REMOTE_ADDR']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail Center - <?php echo ucfirst($folder); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --mail-sidebar-bg: #1e293b;
            --mail-sidebar-text: #cbd5e1;
            --mail-sidebar-hover: #334155;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mail Sidebar */
        .mail-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background-color: var(--mail-sidebar-bg);
            color: var(--mail-sidebar-text);
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .mail-sidebar.collapsed {
            width: 70px;
        }

        .mail-sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mail-sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
        }

        .mail-logo-text {
            font-weight: 600;
            font-size: 1.25rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
        }

        .mail-sidebar.collapsed .mail-logo-text {
            display: none;
        }

        .mail-toggle-sidebar {
            background: none;
            border: none;
            color: var(--mail-sidebar-text);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .mail-toggle-sidebar:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .mail-user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .mail-user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
        }

        .mail-user-details {
            flex: 1;
            min-width: 0;
        }

        .mail-user-details h3 {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mail-user-details p {
            font-size: 0.875rem;
            color: var(--mail-sidebar-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mail-sidebar.collapsed .mail-user-details {
            display: none;
        }

        .mail-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .mail-nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--mail-sidebar-text);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            gap: 0.75rem;
            cursor: pointer;
        }

        .mail-nav-item:hover {
            background-color: var(--mail-sidebar-hover);
            color: white;
        }

        .mail-nav-item.active {
            background-color: rgba(67, 97, 238, 0.2);
            color: white;
            border-left: 3px solid var(--primary);
        }

        .mail-nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .mail-nav-label {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mail-sidebar.collapsed .mail-nav-label {
            display: none;
        }

        .mail-badge {
            background-color: var(--primary);
            color: white;
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 10px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .mail-compose-btn {
            margin: 1rem;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .mail-compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }

        .mail-sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .mail-nav-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 1rem 0;
        }

        /* Main Content */
        .mail-main-content {
            margin-left: 280px;
            min-height: 100vh;
            transition: var(--transition);
        }

        .mail-sidebar.collapsed~.mail-main-content {
            margin-left: 70px;
        }

        .mail-top-bar {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .mail-page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .mail-page-title p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .mail-actions-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .mail-search-box {
            position: relative;
        }

        .mail-search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .mail-search-box input {
            padding: 0.5rem 0.75rem 0.5rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            width: 250px;
            transition: var(--transition);
        }

        .mail-search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Mail Content Area */
        .mail-content-area {
            padding: 1.5rem;
        }

        .mail-content-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .mail-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .mail-card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-table-container {
            overflow-x: auto;
        }

        .mail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .mail-table thead {
            background-color: #f8f9fa;
        }

        .mail-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
        }

        .mail-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .mail-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .mail-table tbody tr.unread {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .mail-checkbox {
            width: 18px;
            height: 18px;
        }

        .mail-sender-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-sender-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.875rem;
            color: white;
            flex-shrink: 0;
        }

        .mail-subject-link {
            font-weight: 600;
            color: var(--dark);
            text-decoration: none;
        }

        .mail-subject-link:hover {
            color: var(--primary);
        }

        .mail-preview {
            color: var(--gray);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .mail-date {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .mail-actions {
            display: flex;
            gap: 0.5rem;
        }

        .mail-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            transition: var(--transition);
            background: none;
            color: var(--gray);
        }

        .mail-action-btn:hover {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .mail-bulk-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .mail-select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .mail-empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .mail-empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .mail-pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding: 1rem;
        }

        .mail-page-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border);
            background-color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark);
        }

        .mail-page-btn:hover {
            background-color: #f8f9fa;
        }

        .mail-page-btn.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .mail-alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .mail-alert-error {
            background-color: rgba(230, 57, 70, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .mail-contact-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .mail-contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 6px;
            transition: var(--transition);
            text-decoration: none;
            color: var(--mail-sidebar-text);
        }

        .mail-contact-item:hover {
            background-color: var(--mail-sidebar-hover);
            color: white;
        }

        .mail-contact-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--secondary), var(--warning));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.75rem;
            color: white;
        }

        .mail-contact-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
            color: white;
        }

        .mail-contact-info p {
            font-size: 0.75rem;
            color: var(--mail-sidebar-text);
        }

        .mail-sidebar.collapsed .mail-contact-info {
            display: none;
        }

        .mail-sidebar.collapsed .mail-contact-item {
            justify-content: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .mail-sidebar {
                width: 0;
            }

            .mail-sidebar.collapsed {
                width: 0;
            }

            .mail-main-content {
                margin-left: 0;
            }

            .mail-sidebar.collapsed~.mail-main-content {
                margin-left: 0;
            }

            .mail-actions-bar {
                display: none;
            }

            .mail-content-area {
                padding: 1rem;
            }

            .mail-search-box input {
                width: 200px;
            }

            .mail-table {
                font-size: 0.75rem;
            }

            .mail-table th,
            .mail-table td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>

<body>
    <!-- Mail Sidebar -->
    <aside class="mail-sidebar" id="mailSidebar">
        <div class="mail-sidebar-header">
            <div class="mail-sidebar-logo">
                <div class="mail-logo-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="mail-logo-text">Mail Center</div>
            </div>
            <button class="mail-toggle-sidebar" onclick="toggleMailSidebar()">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="mail-user-info">
            <div class="mail-user-avatar">
                <?php
                $initials = isset($current_user['name']) ? strtoupper(substr($current_user['name'], 0, 1)) : 'U';
                echo $initials;
                ?>
            </div>
            <div class="mail-user-details">
                <h3><?php echo htmlspecialchars($current_user['name']); ?></h3>
                <p>
                    <?php
                    $role_icon = '';
                    switch ($current_user['role']) {
                        case 'admin':
                            $role_icon = 'fa-crown';
                            break;
                        case 'instructor':
                            $role_icon = 'fa-chalkboard-teacher';
                            break;
                        case 'student':
                            $role_icon = 'fa-user-graduate';
                            break;
                        default:
                            $role_icon = 'fa-user';
                    }
                    ?>
                    <i class="fas <?php echo $role_icon; ?>"></i>
                    <?php echo ucfirst($current_user['role']); ?>
                </p>
            </div>
        </div>

        <button class="mail-compose-btn" onclick="window.location.href='compose.php'">
            <i class="fas fa-plus"></i>
            <span class="mail-nav-label">Compose</span>
        </button>

        <nav class="mail-nav">
            <a href="index.php?folder=inbox" class="mail-nav-item <?php echo $folder == 'inbox' ? 'active' : ''; ?>">
                <i class="fas fa-inbox"></i>
                <span class="mail-nav-label">Inbox</span>
                <?php if ($folder_counts['inbox']['unread'] > 0): ?>
                    <span class="mail-badge"><?php echo $folder_counts['inbox']['unread']; ?></span>
                <?php endif; ?>
            </a>

            <a href="index.php?folder=sent" class="mail-nav-item <?php echo $folder == 'sent' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i>
                <span class="mail-nav-label">Sent</span>
                <?php if ($folder_counts['sent']['count'] > 0): ?>
                    <span class="mail-badge"><?php echo $folder_counts['sent']['count']; ?></span>
                <?php endif; ?>
            </a>

            <a href="index.php?folder=trash" class="mail-nav-item <?php echo $folder == 'trash' ? 'active' : ''; ?>">
                <i class="fas fa-trash"></i>
                <span class="mail-nav-label">Trash</span>
                <?php if ($folder_counts['trash']['count'] > 0): ?>
                    <span class="mail-badge"><?php echo $folder_counts['trash']['count']; ?></span>
                <?php endif; ?>
            </a>

            <div class="mail-nav-divider"></div>

            <a href="<?php echo BASE_URL; ?>modules/<?php echo $current_user['role']; ?>/dashboard.php" class="mail-nav-item">
                <i class="fas fa-arrow-left"></i>
                <span class="mail-nav-label">Back to Dashboard</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="mail-nav-item" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i>
                <span class="mail-nav-label">Logout</span>
            </a>
        </nav>

        <!-- Recent Contacts -->

        <?php if (!empty($recent_contacts)): ?>
            <div class="mail-sidebar-footer">
                <h4 style="font-size: 0.875rem; font-weight: 600; color: white; margin-bottom: 0.75rem; padding: 0 0.5rem;">
                    <i class="fas fa-users"></i>
                    <span class="mail-nav-label">Recent Contacts</span>
                </h4>
                <div class="mail-contact-list">
                    <?php foreach ($recent_contacts as $contact): ?>
                        <?php
                        // Handle null values for first_name and last_name
                        $first_name = $contact['first_name'] ?? '';
                        $last_name = $contact['last_name'] ?? '';
                        $full_name = trim($first_name . ' ' . $last_name);
                        $email = $contact['email'] ?? '';

                        // Get initials for avatar (handle empty names)
                        if (!empty($full_name)) {
                            $initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
                        } else if (!empty($email)) {
                            $initials = strtoupper(substr($email, 0, 2));
                        } else {
                            $initials = '??';
                        }
                        ?>

                        <?php if (!empty($full_name) || !empty($email)): ?>
                            <a href="compose.php?to=<?php echo $contact['contact_id']; ?>" class="mail-contact-item">
                                <div class="mail-contact-avatar">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                                <div class="mail-contact-info">
                                    <?php if (!empty($full_name)): ?>
                                        <h4><?php echo htmlspecialchars($full_name); ?></h4>
                                    <?php else: ?>
                                        <h4>Unknown User</h4>
                                    <?php endif; ?>

                                    <?php if (!empty($email)): ?>
                                        <p><?php echo htmlspecialchars($email); ?></p>
                                    <?php else: ?>
                                        <p>No email</p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="mail-main-content">
        <!-- Top Bar -->
        <div class="mail-top-bar">
            <div class="mail-page-title">
                <h1>Mail Center</h1>
                <p>
                    <?php echo ucfirst($folder); ?> • <?php echo $total_messages; ?> messages
                    <?php if ($folder == 'inbox' && $unread_count > 0): ?>
                        • <span style="color: var(--warning);"><i class="fas fa-circle"></i> <?php echo $unread_count; ?> unread</span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="mail-actions-bar">
                <div class="mail-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search messages..." id="mailSearch">
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="mail-content-area">
            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mail-alert mail-alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="mail-alert mail-alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Bulk Actions -->
            <div class="mail-content-card">
                <div class="mail-card-header">
                    <h2 class="mail-card-title">
                        <?php echo ucfirst($folder); ?> Messages
                    </h2>

                    <div class="mail-bulk-actions">
                        <div class="mail-select-all">
                            <input type="checkbox" id="selectAll" class="mail-checkbox">
                            <label for="selectAll">Select All</label>
                        </div>

                        <form method="POST" id="bulkActionForm" style="display: flex; align-items: center; gap: 0.5rem;">
                            <select name="action" style="padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                                <option value="">Bulk Actions</option>
                                <?php if ($folder == 'inbox'): ?>
                                    <option value="mark_as_read">Mark as Read</option>
                                    <option value="mark_as_unread">Mark as Unread</option>
                                <?php endif; ?>
                                <?php if ($folder != 'trash'): ?>
                                    <option value="move_to_trash">Move to Trash</option>
                                <?php else: ?>
                                    <option value="delete_forever">Delete Forever</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" style="background-color: var(--primary); color: white; padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer; font-size: 0.875rem;">
                                Apply
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Messages Table -->
                <?php if (empty($messages)): ?>
                    <div class="mail-empty-state">
                        <i class="fas fa-envelope-open-text"></i>
                        <h3>No messages found</h3>
                        <p>Your <?php echo $folder; ?> is empty.</p>
                        <?php if ($folder == 'inbox'): ?>
                            <button class="mail-compose-btn" style="width: auto; padding: 0.75rem 1.5rem; margin-top: 1rem;" onclick="window.location.href='compose.php'">
                                <i class="fas fa-plus"></i> Compose New Message
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="mail-table-container">
                        <table class="mail-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th>From/To</th>
                                    <th>Subject</th>
                                    <th style="width: 120px;">Date</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messages as $message): ?>
                                    <?php
                                    $is_sender = ($message['sender_id'] == $current_user['id']);
                                    $is_unread = (!$is_sender && isset($message['is_read']) && $message['is_read'] == 0);

                                    if ($folder == 'sent') {
                                        $contact_name = htmlspecialchars(($message['receiver_first_name'] ?? '') . ' ' . ($message['receiver_last_name'] ?? ''));
                                        $contact_email = htmlspecialchars($message['receiver_email'] ?? '');
                                        $contact_id = $message['receiver_id'];
                                    } else {
                                        $contact_name = htmlspecialchars(($message['sender_first_name'] ?? '') . ' ' . ($message['sender_last_name'] ?? ''));
                                        $contact_email = htmlspecialchars($message['sender_email'] ?? '');
                                        $contact_id = $message['sender_id'];
                                    }

                                    // Get initials for avatar
                                    $first_name = $folder == 'sent' ? ($message['receiver_first_name'] ?? '') : ($message['sender_first_name'] ?? '');
                                    $last_name = $folder == 'sent' ? ($message['receiver_last_name'] ?? '') : ($message['sender_last_name'] ?? '');
                                    $avatar_initials = '';

                                    if (!empty($first_name) && !empty($last_name)) {
                                        $avatar_initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
                                    } else if (!empty($contact_email)) {
                                        $avatar_initials = strtoupper(substr($contact_email, 0, 2));
                                    } else {
                                        $avatar_initials = '??';
                                    }

                                    // Clean up contact name
                                    $contact_name = trim($contact_name);
                                    if (empty($contact_name)) {
                                        $contact_name = !empty($contact_email) ? $contact_email : 'Unknown User';
                                    }
                                    ?>

                                    <tr class="<?php echo $is_unread ? 'unread' : ''; ?>">
                                        <td>
                                            <input type="checkbox" class="mail-checkbox message-checkbox" name="message_ids[]" value="<?php echo $message['id']; ?>">
                                        </td>
                                        <td>
                                            <div class="mail-sender-info">
                                                <div class="mail-sender-avatar">
                                                    <?php echo $avatar_initials; ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo $contact_name; ?></div>
                                                    <?php if (!empty($contact_email)): ?>
                                                        <div style="font-size: 0.75rem; color: var(--gray);"><?php echo $contact_email; ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?php echo $message['id']; ?>" class="mail-subject-link">
                                                <?php echo htmlspecialchars($message['subject'] ?: '(No subject)'); ?>
                                            </a>
                                            <?php if (strlen($message['message']) > 100): ?>
                                                <div class="mail-preview">
                                                    <?php echo htmlspecialchars(substr(strip_tags($message['message']), 0, 100)); ?>...
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="mail-date">
                                            <?php echo date('M d, Y', strtotime($message['created_at'])); ?><br>
                                            <small style="color: var(--gray-light);"><?php echo date('h:i A', strtotime($message['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="mail-actions">
                                                <a href="view.php?id=<?php echo $message['id']; ?>" class="mail-action-btn" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($folder != 'trash'): ?>
                                                    <a href="index.php?folder=trash&delete=<?php echo $message['id']; ?>" class="mail-action-btn" title="Delete" onclick="return confirm('Move to trash?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="index.php?folder=trash&restore=<?php echo $message['id']; ?>" class="mail-action-btn" title="Restore" onclick="return confirm('Restore this message?')">
                                                        <i class="fas fa-undo"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="mail-pagination">
                            <?php if ($page > 1): ?>
                                <a href="?folder=<?php echo $folder; ?>&page=<?php echo $page - 1; ?>" class="mail-page-btn">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                    <a href="?folder=<?php echo $folder; ?>&page=<?php echo $i; ?>" class="mail-page-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                    <span class="mail-page-btn" style="cursor: default;">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?folder=<?php echo $folder; ?>&page=<?php echo $page + 1; ?>" class="mail-page-btn">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Toggle sidebar
        function toggleMailSidebar() {
            const sidebar = document.getElementById('mailSidebar');
            sidebar.classList.toggle('collapsed');

            // Save preference to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('mailSidebarCollapsed', isCollapsed);
        }

        // Load sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('mailSidebarCollapsed') === 'true';
            if (isCollapsed) {
                document.getElementById('mailSidebar').classList.add('collapsed');
            }
        });

        // Select all checkboxes
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.message-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Bulk action form validation
        document.getElementById('bulkActionForm').addEventListener('submit', function(e) {
            const action = this.querySelector('select[name="action"]').value;
            const checkboxes = document.querySelectorAll('.message-checkbox:checked');

            if (!action) {
                e.preventDefault();
                alert('Please select an action');
                return false;
            }

            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one message');
                return false;
            }

            if (action === 'move_to_trash' && !confirm('Move selected messages to trash?')) {
                e.preventDefault();
                return false;
            }

            if (action === 'delete_forever' && !confirm('Permanently delete selected messages? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        // Search functionality
        document.getElementById('mailSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.trim();
                if (searchTerm) {
                    // Implement search - for now reload with search param
                    window.location.href = 'search.php?q=' + encodeURIComponent(searchTerm);
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new message
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'compose.php';
            }

            // Ctrl + F for search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('mailSearch').focus();
            }

            // Ctrl + B to toggle sidebar
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                toggleMailSidebar();
            }

            // Escape to clear search and selections
            if (e.key === 'Escape') {
                document.getElementById('mailSearch').value = '';
                document.querySelectorAll('.message-checkbox:checked').forEach(cb => cb.checked = false);
                document.getElementById('selectAll').checked = false;
            }

            // Arrow keys for navigation
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                const rows = document.querySelectorAll('.mail-table tbody tr');
                let currentIndex = -1;

                // Find currently focused row
                rows.forEach((row, index) => {
                    if (row.querySelector('.message-checkbox:focus') || row.matches(':hover')) {
                        currentIndex = index;
                    }
                });

                if (currentIndex >= 0) {
                    e.preventDefault();
                    let newIndex;
                    if (e.key === 'ArrowUp' && currentIndex > 0) {
                        newIndex = currentIndex - 1;
                    } else if (e.key === 'ArrowDown' && currentIndex < rows.length - 1) {
                        newIndex = currentIndex + 1;
                    }

                    if (newIndex !== undefined) {
                        rows[newIndex].scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        // Add visual focus
                        rows.forEach(row => row.style.backgroundColor = '');
                        rows[newIndex].style.backgroundColor = 'rgba(67, 97, 238, 0.1)';
                    }
                }
            }

            // Enter to open selected message
            if (e.key === 'Enter' && !e.ctrlKey) {
                const focusedRow = document.querySelector('.mail-table tbody tr[style*="background-color"]');
                if (focusedRow) {
                    const link = focusedRow.querySelector('.mail-subject-link');
                    if (link) {
                        window.location.href = link.href;
                    }
                }
            }
        });

        // Auto-refresh inbox every 60 seconds
        let refreshInterval;

        function checkForNewMessages() {
            if (window.location.pathname.includes('index.php') && <?php echo $folder == 'inbox' ? 'true' : 'false'; ?>) {
                fetch('check_new.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.new_messages > 0) {
                            showNotification('New messages available', 'info');
                        }
                    })
                    .catch(error => console.error('Error checking for new messages:', error));
            }
        }

        // Start auto-refresh
        <?php if ($folder == 'inbox'): ?>
            refreshInterval = setInterval(checkForNewMessages, 60000);

            // Stop auto-refresh when page loses focus
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    clearInterval(refreshInterval);
                } else {
                    refreshInterval = setInterval(checkForNewMessages, 60000);
                }
            });
        <?php endif; ?>

        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `mail-notification mail-notification-${type}`;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background-color: ${type === 'info' ? 'var(--primary)' : type === 'success' ? 'var(--success)' : 'var(--warning)'};
                color: white;
                border-radius: 6px;
                box-shadow: var(--card-shadow);
                z-index: 10000;
                animation: mailSlideIn 0.3s ease;
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'mailSlideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes mailSlideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes mailSlideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
            
            .mail-notification {
                font-size: 0.875rem;
                font-weight: 500;
            }
        `;
        document.head.appendChild(style);

        // Mark message as read on click (for unread messages)
        <?php if ($folder == 'inbox'): ?>
            document.querySelectorAll('.mail-table tbody tr.unread .mail-subject-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    const messageId = this.closest('tr').querySelector('.message-checkbox').value;

                    // Mark as read via AJAX
                    fetch('mark_as_read.php?id=' + messageId, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        }
                    }).catch(error => console.error('Error marking as read:', error));
                });
            });
        <?php endif; ?>

        // Add hover effects to table rows
        document.querySelectorAll('.mail-table tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'rgba(67, 97, 238, 0.05)';
            });

            row.addEventListener('mouseleave', function() {
                if (!this.classList.contains('unread')) {
                    this.style.backgroundColor = '';
                }
            });
        });

        // Initialize tooltips
        document.querySelectorAll('.mail-action-btn').forEach(btn => {
            const title = btn.getAttribute('title');
            if (title) {
                btn.addEventListener('mouseenter', function(e) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'mail-tooltip';
                    tooltip.textContent = title;
                    tooltip.style.cssText = `
                        position: fixed;
                        background-color: var(--dark);
                        color: white;
                        padding: 0.5rem 0.75rem;
                        border-radius: 4px;
                        font-size: 0.75rem;
                        z-index: 10001;
                        pointer-events: none;
                        transform: translate(-50%, -100%);
                        top: ${e.clientY}px;
                        left: ${e.clientX}px;
                        white-space: nowrap;
                    `;
                    document.body.appendChild(tooltip);

                    this._tooltip = tooltip;
                });

                btn.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        this._tooltip = null;
                    }
                });

                btn.addEventListener('mousemove', function(e) {
                    if (this._tooltip) {
                        this._tooltip.style.top = (e.clientY - 10) + 'px';
                        this._tooltip.style.left = e.clientX + 'px';
                    }
                });
            }
        });
    </script>
</body>

</html>