<?php
// modules/shared/mail/view.php

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

// Get message ID from URL
$message_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$message_id) {
    header('Location: index.php');
    exit();
}

// Get the message
$sql = "SELECT m.*, 
               u_sender.id as sender_id,
               u_sender.first_name as sender_first_name, 
               u_sender.last_name as sender_last_name,
               u_sender.email as sender_email,
               u_sender.role as sender_role,
               u_sender.profile_image as sender_profile_image,
               u_receiver.id as receiver_id,
               u_receiver.first_name as receiver_first_name,
               u_receiver.last_name as receiver_last_name,
               u_receiver.email as receiver_email,
               u_receiver.role as receiver_role
        FROM internal_messages m
        LEFT JOIN users u_sender ON u_sender.id = m.sender_id
        LEFT JOIN users u_receiver ON u_receiver.id = m.receiver_id
        WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $message_id, $current_user['id'], $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Message not found or you don't have permission to view it";
    header('Location: index.php');
    exit();
}

$message = $result->fetch_assoc();

// Mark message as read if user is receiver
if ($message['receiver_id'] == $current_user['id'] && !$message['is_read']) {
    $update_sql = "UPDATE internal_messages SET is_read = 1, read_at = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $message_id);
    $update_stmt->execute();

    // Update the message array
    $message['is_read'] = 1;
    $message['read_at'] = date('Y-m-d H:i:s');
}

// Get message thread (replies)
$thread_sql = "SELECT m.*, 
                      u_sender.first_name as sender_first_name, 
                      u_sender.last_name as sender_last_name,
                      u_sender.email as sender_email,
                      u_sender.profile_image as sender_profile_image
               FROM internal_messages m
               LEFT JOIN users u_sender ON u_sender.id = m.sender_id
               WHERE m.parent_id = ? OR (m.parent_id IS NULL AND m.id = ?)
               ORDER BY m.created_at ASC";

$thread_stmt = $conn->prepare($thread_sql);
$thread_stmt->bind_param("ii", $message_id, $message_id);
$thread_stmt->execute();
$thread_result = $thread_stmt->get_result();
$thread_messages = $thread_result->fetch_all(MYSQLI_ASSOC);

// Get message attachments
$attachments_sql = "SELECT * FROM message_attachments WHERE message_id = ?";
$attachments_stmt = $conn->prepare($attachments_sql);
$attachments_stmt->bind_param("i", $message_id);
$attachments_stmt->execute();
$attachments_result = $attachments_stmt->get_result();
$attachments = $attachments_result->fetch_all(MYSQLI_ASSOC);

// Get reply count
$reply_count = count($thread_messages) - 1; // Subtract the original message

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reply') {
    $reply_message = sanitize($_POST['reply_message'] ?? '');

    if (!empty($reply_message)) {
        $reply_sql = "INSERT INTO internal_messages 
                      (sender_id, receiver_id, subject, message, parent_id, created_at) 
                      VALUES (?, ?, ?, ?, ?, NOW())";

        // Determine receiver - if current user is sender, reply to receiver, else reply to sender
        $receiver_id = ($current_user['id'] == $message['sender_id']) ?
            $message['receiver_id'] : $message['sender_id'];

        $reply_subject = 'Re: ' . (strpos($message['subject'], 'Re: ') === 0 ?
            $message['subject'] : 'Re: ' . $message['subject']);

        $reply_stmt = $conn->prepare($reply_sql);
        $reply_stmt->bind_param(
            "iissi",
            $current_user['id'],
            $receiver_id,
            $reply_subject,
            $reply_message,
            $message_id
        );

        if ($reply_stmt->execute()) {
            $_SESSION['success'] = "Reply sent successfully";

            // Log activity
            logActivity(
                $current_user['id'],
                'message_reply',
                'Replied to a message',
                $_SERVER['REMOTE_ADDR'],
                'internal_messages',
                $conn->insert_id
            );

            // Refresh page to show new reply
            header("Location: view.php?id=$message_id");
            exit();
        } else {
            $errors[] = "Failed to send reply. Please try again.";
        }
    } else {
        $errors[] = "Reply message cannot be empty";
    }
}

// Handle delete/restore actions
if (isset($_GET['action'])) {
    $action = sanitize($_GET['action']);

    if ($action == 'delete') {
        // Move to trash
        if ($message['receiver_id'] == $current_user['id']) {
            $delete_sql = "UPDATE internal_messages SET is_deleted_receiver = 1 WHERE id = ?";
        } else {
            $delete_sql = "UPDATE internal_messages SET is_deleted_sender = 1 WHERE id = ?";
        }

        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $message_id);

        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Message moved to trash";
            header('Location: index.php?folder=trash');
            exit();
        }
    } elseif ($action == 'restore') {
        // Restore from trash
        if ($message['receiver_id'] == $current_user['id']) {
            $restore_sql = "UPDATE internal_messages SET is_deleted_receiver = 0 WHERE id = ?";
        } else {
            $restore_sql = "UPDATE internal_messages SET is_deleted_sender = 0 WHERE id = ?";
        }

        $restore_stmt = $conn->prepare($restore_sql);
        $restore_stmt->bind_param("i", $message_id);

        if ($restore_stmt->execute()) {
            $_SESSION['success'] = "Message restored";
            header('Location: index.php');
            exit();
        }
    } elseif ($action == 'permanent_delete') {
        // Permanent delete
        $delete_sql = "DELETE FROM internal_messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("iii", $message_id, $current_user['id'], $current_user['id']);

        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Message permanently deleted";
            header('Location: index.php?folder=trash');
            exit();
        }
    }
}

// Get folder for back link
$folder = isset($_GET['folder']) ? sanitize($_GET['folder']) : 'inbox';

// Log activity
logActivity($current_user['id'], 'view_message', 'Viewed message', $_SERVER['REMOTE_ADDR'], 'internal_messages', $message_id);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Message - Mail Center</title>
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
        }

        .mail-view-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .mail-view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-view-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-view-actions {
            display: flex;
            gap: 0.5rem;
        }

        .mail-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            text-decoration: none;
        }

        .mail-btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .mail-btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .mail-btn-secondary {
            background-color: white;
            color: var(--dark);
            border: 1px solid var(--border);
        }

        .mail-btn-secondary:hover {
            background-color: #f8f9fa;
            border-color: var(--gray);
        }

        .mail-btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .mail-btn-danger:hover {
            background-color: #d32f2f;
        }

        .mail-btn-success {
            background-color: var(--success);
            color: white;
        }

        .mail-btn-success:hover {
            background-color: #3db8d9;
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

        .mail-message-card {
            background-color: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .mail-message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-sender-info {
            display: flex;
            gap: 1rem;
        }

        .mail-sender-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }

        .mail-sender-details h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .mail-sender-details p {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .mail-message-metadata {
            text-align: right;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .mail-message-metadata .mail-date {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .mail-priority-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .mail-priority-badge.priority-low {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .mail-priority-badge.priority-normal {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
            border: 1px solid var(--info);
        }

        .mail-priority-badge.priority-high {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .mail-message-content {
            font-size: 0.95rem;
            line-height: 1.8;
            margin-bottom: 1.5rem;
        }

        .mail-message-content pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: inherit;
        }

        .mail-attachments {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .mail-attachments h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .mail-attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .mail-attachment-item {
            background-color: #f8f9fa;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            width: 200px;
            transition: var(--transition);
        }

        .mail-attachment-item:hover {
            background-color: #e9ecef;
            border-color: var(--primary);
        }

        .mail-attachment-icon {
            font-size: 2rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .mail-attachment-info h5 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mail-attachment-info p {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .mail-attachment-actions {
            display: flex;
            gap: 0.5rem;
        }

        .mail-attachment-btn {
            padding: 0.25rem 0.5rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            background-color: white;
            color: var(--dark);
            font-size: 0.75rem;
            cursor: pointer;
            transition: var(--transition);
            flex: 1;
            text-align: center;
            text-decoration: none;
        }

        .mail-attachment-btn:hover {
            background-color: #f1f5f9;
            border-color: var(--primary);
            color: var(--primary);
        }

        .mail-reply-form {
            background-color: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
            margin-top: 1.5rem;
        }

        .mail-reply-form h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .mail-form-group {
            margin-bottom: 1rem;
        }

        .mail-form-textarea {
            width: 100%;
            min-height: 150px;
            padding: 1rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.875rem;
            line-height: 1.6;
            resize: vertical;
            transition: var(--transition);
        }

        .mail-form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .mail-reply-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .mail-thread {
            margin-top: 2rem;
        }

        .mail-thread-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .mail-thread-header h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-thread-count {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .mail-thread-messages {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .mail-thread-message {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
        }

        .mail-thread-message.own-message {
            border-left-color: var(--success);
            background-color: rgba(76, 201, 240, 0.05);
        }

        .mail-thread-header-small {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-thread-sender {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-thread-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.75rem;
            color: white;
            flex-shrink: 0;
        }

        .mail-thread-sender-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }

        .mail-thread-sender-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-thread-date {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-thread-content {
            font-size: 0.875rem;
            line-height: 1.6;
        }

        .mail-empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .mail-empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .mail-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .mail-breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .mail-breadcrumb a:hover {
            text-decoration: underline;
        }

        .mail-breadcrumb .separator {
            color: var(--gray-light);
        }

        @media (max-width: 768px) {
            .mail-view-container {
                padding: 1rem;
            }

            .mail-view-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .mail-view-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .mail-message-header {
                flex-direction: column;
                gap: 1rem;
            }

            .mail-message-metadata {
                text-align: left;
                width: 100%;
            }

            .mail-attachment-list {
                flex-direction: column;
            }

            .mail-attachment-item {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="mail-view-container">
        <!-- Breadcrumb -->
        <div class="mail-breadcrumb">
            <a href="index.php">Mail Center</a>
            <span class="separator">/</span>
            <a href="index.php?folder=<?php echo $folder; ?>"><?php echo ucfirst($folder); ?></a>
            <span class="separator">/</span>
            <span>View Message</span>
        </div>

        <!-- Header -->
        <div class="mail-view-header">
            <h1>
                <?php if ($message['parent_id']): ?>
                    <i class="fas fa-comments"></i> Message Thread
                <?php else: ?>
                    <i class="fas fa-envelope"></i> View Message
                <?php endif; ?>
            </h1>

            <div class="mail-view-actions">
                <a href="compose.php?reply=<?php echo $message_id; ?>" class="mail-btn mail-btn-primary">
                    <i class="fas fa-reply"></i> Reply
                </a>
                <a href="compose.php?forward=<?php echo $message_id; ?>" class="mail-btn mail-btn-secondary">
                    <i class="fas fa-share"></i> Forward
                </a>

                <?php if (
                    $message['receiver_id'] == $current_user['id'] && $message['is_deleted_receiver'] ||
                    $message['sender_id'] == $current_user['id'] && $message['is_deleted_sender']
                ): ?>
                    <a href="view.php?id=<?php echo $message_id; ?>&action=restore"
                        class="mail-btn mail-btn-success"
                        onclick="return confirm('Restore this message?')">
                        <i class="fas fa-undo"></i> Restore
                    </a>
                    <a href="view.php?id=<?php echo $message_id; ?>&action=permanent_delete"
                        class="mail-btn mail-btn-danger"
                        onclick="return confirm('Permanently delete this message? This cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete Forever
                    </a>
                <?php else: ?>
                    <a href="view.php?id=<?php echo $message_id; ?>&action=delete"
                        class="mail-btn mail-btn-danger"
                        onclick="return confirm('Move to trash?')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                <?php endif; ?>

                <a href="index.php?folder=<?php echo $folder; ?>" class="mail-btn mail-btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Alerts -->
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

        <?php if (isset($errors) && !empty($errors)): ?>
            <div class="mail-alert mail-alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin-top: 0.5rem; padding-left: 1rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Main Message -->
        <div class="mail-message-card">
            <div class="mail-message-header">
                <div class="mail-sender-info">
                    <?php
                    $sender_initials = '';
                    $sender_name = htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']);
                    if (!empty($message['sender_first_name']) && !empty($message['sender_last_name'])) {
                        $sender_initials = strtoupper(
                            substr($message['sender_first_name'], 0, 1) .
                                substr($message['sender_last_name'], 0, 1)
                        );
                    } else if (!empty($message['sender_email'])) {
                        $sender_initials = strtoupper(substr($message['sender_email'], 0, 2));
                    } else {
                        $sender_initials = '??';
                    }
                    ?>
                    <div class="mail-sender-avatar">
                        <?php echo $sender_initials; ?>
                    </div>
                    <div class="mail-sender-details">
                        <h3><?php echo $sender_name; ?></h3>
                        <p><?php echo htmlspecialchars($message['sender_email']); ?></p>
                        <p><small><?php echo ucfirst($message['sender_role']); ?></small></p>
                    </div>
                </div>

                <div class="mail-message-metadata">
                    <div class="mail-date">
                        <?php echo date('F j, Y g:i A', strtotime($message['created_at'])); ?>
                    </div>
                    <div>
                        <strong>To:</strong>
                        <?php echo htmlspecialchars($message['receiver_first_name'] . ' ' . $message['receiver_last_name']); ?>
                    </div>
                    <div>
                        <strong>Subject:</strong> <?php echo htmlspecialchars($message['subject']); ?>
                    </div>
                    <?php if ($message['priority'] != 'normal'): ?>
                        <div class="mail-priority-badge priority-<?php echo $message['priority']; ?>">
                            <i class="fas fa-<?php echo $message['priority'] == 'high' ? 'exclamation-circle' : 'arrow-down'; ?>"></i>
                            <?php echo ucfirst($message['priority']); ?> Priority
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mail-message-content">
                <pre><?php echo htmlspecialchars($message['message']); ?></pre>
            </div>

            <!-- Attachments -->
            <?php if (!empty($attachments)): ?>
                <div class="mail-attachments">
                    <h4><i class="fas fa-paperclip"></i> Attachments (<?php echo count($attachments); ?>)</h4>
                    <div class="mail-attachment-list">
                        <?php foreach ($attachments as $attachment): ?>
                            <?php
                            $file_icon = 'fa-file';
                            $extension = pathinfo($attachment['file_name'], PATHINFO_EXTENSION);

                            switch (strtolower($extension)) {
                                case 'pdf':
                                    $file_icon = 'fa-file-pdf';
                                    break;
                                case 'doc':
                                case 'docx':
                                    $file_icon = 'fa-file-word';
                                    break;
                                case 'xls':
                                case 'xlsx':
                                    $file_icon = 'fa-file-excel';
                                    break;
                                case 'jpg':
                                case 'jpeg':
                                case 'png':
                                case 'gif':
                                    $file_icon = 'fa-file-image';
                                    break;
                                case 'zip':
                                case 'rar':
                                    $file_icon = 'fa-file-archive';
                                    break;
                            }

                            $file_size = formatFileSize($attachment['file_size']);
                            ?>
                            <div class="mail-attachment-item">
                                <div class="mail-attachment-icon">
                                    <i class="fas <?php echo $file_icon; ?>"></i>
                                </div>
                                <div class="mail-attachment-info">
                                    <h5 title="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                        <?php echo htmlspecialchars($attachment['file_name']); ?>
                                    </h5>
                                    <p><?php echo $file_size; ?></p>
                                    <div class="mail-attachment-actions">
                                        <a href="download_attachment.php?id=<?php echo $attachment['id']; ?>"
                                            class="mail-attachment-btn"
                                            title="Download">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reply Form -->
        <div class="mail-reply-form">
            <h3><i class="fas fa-reply"></i> Reply to Message</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reply">

                <div class="mail-form-group">
                    <textarea class="mail-form-textarea"
                        name="reply_message"
                        placeholder="Type your reply here..."
                        required></textarea>
                </div>

                <div class="mail-reply-actions">
                    <button type="submit" class="mail-btn mail-btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                    <button type="button" class="mail-btn mail-btn-secondary" onclick="clearReply()">
                        <i class="fas fa-eraser"></i> Clear
                    </button>
                </div>
            </form>
        </div>

        <!-- Thread Messages (Replies) -->
        <?php if ($reply_count > 0): ?>
            <div class="mail-thread">
                <div class="mail-thread-header">
                    <h3><i class="fas fa-comments"></i> Conversation</h3>
                    <div class="mail-thread-count">
                        <?php echo $reply_count; ?> <?php echo $reply_count == 1 ? 'reply' : 'replies'; ?>
                    </div>
                </div>

                <div class="mail-thread-messages">
                    <?php foreach ($thread_messages as $thread_msg): ?>
                        <?php if ($thread_msg['id'] == $message_id) continue; // Skip the main message 
                        ?>

                        <?php
                        $is_own_message = ($thread_msg['sender_id'] == $current_user['id']);
                        $sender_initials = '';
                        $sender_name = htmlspecialchars($thread_msg['sender_first_name'] . ' ' . $thread_msg['sender_last_name']);

                        if (!empty($thread_msg['sender_first_name']) && !empty($thread_msg['sender_last_name'])) {
                            $sender_initials = strtoupper(
                                substr($thread_msg['sender_first_name'], 0, 1) .
                                    substr($thread_msg['sender_last_name'], 0, 1)
                            );
                        } else if (!empty($thread_msg['sender_email'])) {
                            $sender_initials = strtoupper(substr($thread_msg['sender_email'], 0, 2));
                        } else {
                            $sender_initials = '??';
                        }
                        ?>

                        <div class="mail-thread-message <?php echo $is_own_message ? 'own-message' : ''; ?>">
                            <div class="mail-thread-header-small">
                                <div class="mail-thread-sender">
                                    <div class="mail-thread-avatar">
                                        <?php echo $sender_initials; ?>
                                    </div>
                                    <div class="mail-thread-sender-info">
                                        <h4><?php echo $sender_name; ?></h4>
                                        <p><?php echo htmlspecialchars($thread_msg['sender_email']); ?></p>
                                    </div>
                                </div>
                                <div class="mail-thread-date">
                                    <?php echo date('M j, g:i A', strtotime($thread_msg['created_at'])); ?>
                                </div>
                            </div>
                            <div class="mail-thread-content">
                                <pre><?php echo htmlspecialchars($thread_msg['message']); ?></pre>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Format file size helper
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Clear reply form
        function clearReply() {
            document.querySelector('[name="reply_message"]').value = '';
        }

        // Auto-expand textarea
        document.querySelector('[name="reply_message"]').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + R to focus reply box
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                document.querySelector('[name="reply_message"]').focus();
            }

            // Escape to clear reply
            if (e.key === 'Escape' && document.activeElement.name === 'reply_message') {
                if (document.querySelector('[name="reply_message"]').value.trim()) {
                    if (confirm('Clear reply?')) {
                        clearReply();
                    }
                }
            }
        });

        // Print message
        function printMessage() {
            window.print();
        }

        // Copy message to clipboard
        function copyMessage() {
            const messageContent = document.querySelector('.mail-message-content').innerText;
            navigator.clipboard.writeText(messageContent)
                .then(() => {
                    alert('Message copied to clipboard');
                })
                .catch(err => {
                    console.error('Failed to copy: ', err);
                });
        }

        // Markdown to HTML rendering (basic)
        function renderMarkdown(text) {
            // Simple markdown rendering
            let html = text
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/__(.*?)__/g, '<u>$1</u>')
                .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank">$1</a>')
                .replace(/^# (.*$)/gm, '<h3>$1</h3>')
                .replace(/^## (.*$)/gm, '<h4>$1</h4>')
                .replace(/^### (.*$)/gm, '<h5>$1</h5>')
                .replace(/^\s*[-*] (.*$)/gm, '<li>$1</li>')
                .replace(/^\s*\d+\. (.*$)/gm, '<li>$1</li>');

            // Wrap lists
            html = html.replace(/(<li>.*<\/li>)/g, '<ul>$1</ul>');

            return html;
        }

        // Convert message content to HTML if it contains markdown
        document.addEventListener('DOMContentLoaded', function() {
            const messageContent = document.querySelector('.mail-message-content pre');
            if (messageContent) {
                const text = messageContent.textContent;
                // Check if text contains markdown
                if (text.includes('**') || text.includes('*') || text.includes('__') || text.includes('[')) {
                    messageContent.innerHTML = renderMarkdown(text);
                }
            }
        });

        // Smooth scroll to reply form
        function scrollToReply() {
            document.querySelector('.mail-reply-form').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            document.querySelector('[name="reply_message"]').focus();
        }

        // Add reply form toggling for mobile
        if (window.innerWidth < 768) {
            const replyForm = document.querySelector('.mail-reply-form');
            const replyHeader = replyForm.querySelector('h3');

            replyHeader.style.cursor = 'pointer';
            replyHeader.addEventListener('click', function() {
                const formContent = this.nextElementSibling;
                formContent.style.display = formContent.style.display === 'none' ? 'block' : 'none';
            });
        }
    </script>
</body>

</html>