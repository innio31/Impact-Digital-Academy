<?php
// modules/shared/mail/compose.php

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

// Initialize variables
$message = [
    'id' => null,
    'sender_id' => $current_user['id'],
    'receiver_id' => null,
    'subject' => '',
    'message' => '',
    'parent_id' => null,
    'priority' => 'normal',
    'attachments' => []
];

$recipient_name = '';
$reply_to = null;
$forward_message = null;
$draft_id = isset($_GET['draft_id']) ? (int)$_GET['draft_id'] : null;

// Check if message_drafts table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'message_drafts'");
if ($table_check->num_rows === 0) {
    // Create message_drafts table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS `message_drafts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `receiver_id` int(11) DEFAULT NULL,
        `subject` varchar(255) DEFAULT NULL,
        `message` text DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_table_sql)) {
        die("Failed to create message_drafts table: " . $conn->error);
    }
}

/**
 * Get message by ID
 */
function getMessageById($id, $conn, $user_id)
{
    $sql = "SELECT m.*, 
                   u_sender.first_name as sender_first_name, 
                   u_sender.last_name as sender_last_name,
                   u_receiver.first_name as receiver_first_name,
                   u_receiver.last_name as receiver_last_name
            FROM internal_messages m
            LEFT JOIN users u_sender ON u_sender.id = m.sender_id
            LEFT JOIN users u_receiver ON u_receiver.id = m.receiver_id
            WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iii", $id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}


/**
 * Get draft by ID
 */
function getDraftById($id, $conn, $user_id)
{
    $sql = "SELECT * FROM message_drafts 
            WHERE id = ? AND user_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Search users
 */
function searchUsers($term, $conn, $exclude_user_id)
{
    $users = [];

    if (empty($term)) {
        return $users;
    }

    $sql = "SELECT id, first_name, last_name, email, role, profile_image 
            FROM users 
            WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) 
            AND id != ? 
            AND status = 'active'
            ORDER BY first_name, last_name
            LIMIT 20";

    $search_term = "%$term%";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $users;
    }

    $stmt->bind_param("sssi", $search_term, $search_term, $search_term, $exclude_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    return $users;
}

/**
 * Send message
 */
function sendMessage($message, $conn, $sender_id)
{
    // Verify sender exists and is active
    $sender_check = getUserById($sender_id, $conn);
    if (!$sender_check) {
        return false;
    }

    // Verify receiver exists and is active
    $receiver_check = getUserById($message['receiver_id'], $conn);
    if (!$receiver_check) {
        return false;
    }

    $sql = "INSERT INTO internal_messages 
            (sender_id, receiver_id, subject, message, parent_id, priority, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iissss",
        $sender_id,
        $message['receiver_id'],
        $message['subject'],
        $message['message'],
        $message['parent_id'],
        $message['priority']
    );

    if ($stmt->execute()) {
        $message_id = $conn->insert_id;

        // Log activity
        logActivity(
            $sender_id,
            'message_sent',
            'Sent a message to ' . $receiver_check['first_name'] . ' ' . $receiver_check['last_name'],
            $_SERVER['REMOTE_ADDR'],
            'internal_messages',
            $message_id
        );

        return $message_id;
    }

    return false;
}

/**
 * Save as draft
 */
function saveAsDraft($message, $conn, $user_id)
{
    if ($message['id']) {
        // Update existing draft
        $sql = "UPDATE message_drafts 
                SET receiver_id = ?, subject = ?, message = ?, updated_at = NOW() 
                WHERE id = ? AND user_id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "issii",
            $message['receiver_id'],
            $message['subject'],
            $message['message'],
            $message['id'],
            $user_id
        );
    } else {
        // Create new draft
        $sql = "INSERT INTO message_drafts 
                (user_id, receiver_id, subject, message, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "iiss",
            $user_id,
            $message['receiver_id'],
            $message['subject'],
            $message['message']
        );
    }

    if ($stmt->execute()) {
        return $message['id'] ?: $conn->insert_id;
    }

    return false;
}

/**
 * Delete draft
 */
function deleteDraft($draft_id, $conn)
{
    $sql = "DELETE FROM message_drafts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $draft_id);
    return $stmt->execute();
}

/**
 * Handle attachments
 */
function handleAttachments($message_id, $conn)
{
    $upload_dir = '../../../uploads/message_attachments/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Check if message_attachments table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'message_attachments'");
    if ($table_check->num_rows === 0) {
        // Table doesn't exist, skip attachments
        return;
    }

    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['attachments']['name'][$key];
            $file_size = $_FILES['attachments']['size'][$key];
            $file_type = $_FILES['attachments']['type'][$key];

            // Generate unique filename
            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $extension;
            $file_path = $upload_dir . $unique_name;

            // Move uploaded file
            if (move_uploaded_file($tmp_name, $file_path)) {
                // Save to database
                $sql = "INSERT INTO message_attachments 
                        (message_id, file_name, file_path, file_type, file_size, uploaded_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("isssi", $message_id, $file_name, $file_path, $file_type, $file_size);
                    $stmt->execute();
                }
            }
        }
    }
}

// Handle reply/forward
if (isset($_GET['reply'])) {
    $message_id = (int)$_GET['reply'];
    $original = getMessageById($message_id, $conn, $current_user['id']);

    if ($original) {
        $message['parent_id'] = $message_id;
        $message['receiver_id'] = $original['sender_id'];
        $message['subject'] = 'Re: ' . (strpos($original['subject'], 'Re: ') === 0 ?
            $original['subject'] : 'Re: ' . $original['subject']);

        // Format reply message
        $original_sender = htmlspecialchars($original['sender_first_name'] . ' ' . $original['sender_last_name']);
        $original_date = date('F j, Y g:i A', strtotime($original['created_at']));
        $message['message'] = "\n\n\n----- Original Message -----\n";
        $message['message'] .= "From: " . $original_sender . "\n";
        $message['message'] .= "Date: " . $original_date . "\n";
        $message['message'] .= "Subject: " . $original['subject'] . "\n\n";
        $message['message'] .= strip_tags($original['message']);

        $reply_to = $original;
    }
} elseif (isset($_GET['forward'])) {
    $message_id = (int)$_GET['forward'];
    $original = getMessageById($message_id, $conn, $current_user['id']);

    if ($original) {
        $message['subject'] = 'Fwd: ' . (strpos($original['subject'], 'Fwd: ') === 0 ?
            $original['subject'] : 'Fwd: ' . $original['subject']);

        // Format forward message
        $original_sender = htmlspecialchars($original['sender_first_name'] . ' ' . $original['sender_last_name']);
        $original_date = date('F j, Y g:i A', strtotime($original['created_at']));
        $original_recipient = htmlspecialchars($original['receiver_first_name'] . ' ' . $original['receiver_last_name']);

        $message['message'] = "\n\n\n----- Forwarded Message -----\n";
        $message['message'] .= "From: " . $original_sender . "\n";
        $message['message'] .= "To: " . $original_recipient . "\n";
        $message['message'] .= "Date: " . $original_date . "\n";
        $message['message'] .= "Subject: " . $original['subject'] . "\n\n";
        $message['message'] .= strip_tags($original['message']);

        $forward_message = $original;
    }
} elseif (isset($_GET['to'])) {
    $message['receiver_id'] = (int)$_GET['to'];
    $recipient = getUserById($message['receiver_id'], $conn);
    if ($recipient) {
        $recipient_name = htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']);
    }
}

// Handle draft loading
if ($draft_id) {
    $draft = getDraftById($draft_id, $conn, $current_user['id']);
    if ($draft) {
        $message = array_merge($message, $draft);
    }
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = isset($_POST['action']) ? sanitize($_POST['action']) : 'send';

    // Get form data
    $message['receiver_id'] = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : null;
    $message['subject'] = sanitize($_POST['subject'] ?? '');
    $message['message'] = sanitize($_POST['message'] ?? '');
    $message['priority'] = sanitize($_POST['priority'] ?? 'normal');
    $message['parent_id'] = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    // Validate
    if (empty($message['receiver_id'])) {
        $errors[] = "Please select a recipient";
    } else {
        // Verify recipient exists and is active
        $recipient = getUserById($message['receiver_id'], $conn);
        if (!$recipient) {
            $errors[] = "Invalid recipient selected";
        } elseif ($recipient['status'] != 'active') {
            $errors[] = "Recipient account is not active";
        }
    }

    if (empty($message['message'])) {
        $errors[] = "Message content is required";
    }

    if (empty($errors)) {
        if ($action === 'send') {
            // Send message
            $message_id = sendMessage($message, $conn, $current_user['id']);

            if ($message_id) {
                // Handle attachments if any
                if (!empty($_FILES['attachments']['name'][0])) {
                    handleAttachments($message_id, $conn);
                }

                // Delete draft if this was a saved draft
                if ($draft_id) {
                    deleteDraft($draft_id, $conn);
                }

                $_SESSION['success'] = "Message sent successfully";
                header('Location: index.php');
                exit();
            } else {
                $errors[] = "Failed to send message. Please try again.";
            }
        } elseif ($action === 'save_draft') {
            // Save as draft
            $draft_id = saveAsDraft($message, $conn, $current_user['id']);

            if ($draft_id) {
                $_SESSION['success'] = "Draft saved successfully";
                header('Location: index.php');
                exit();
            } else {
                $errors[] = "Failed to save draft. Please try again.";
            }
        }
    }
}

// Search for recipients
$search_term = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$recipients = searchUsers($search_term, $conn, $current_user['id']);

// Log activity
logActivity($current_user['id'], 'mail_compose', 'Accessed compose message', $_SERVER['REMOTE_ADDR']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compose Message - Mail Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* All CSS styles remain the same as before */
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

        .mail-compose-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .mail-compose-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-compose-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-back-btn {
            padding: 0.5rem 1rem;
            background-color: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .mail-back-btn:hover {
            background-color: #f8f9fa;
            border-color: var(--primary);
            color: var(--primary);
        }

        .mail-compose-card {
            background-color: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
        }

        .mail-alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-alert-error {
            background-color: rgba(230, 57, 70, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .mail-compose-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .mail-form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .mail-form-row {
            display: flex;
            gap: 1rem;
        }

        .mail-form-row .mail-form-group {
            flex: 1;
        }

        .mail-form-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .mail-form-input,
        .mail-form-textarea,
        .mail-form-select {
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .mail-form-input:focus,
        .mail-form-textarea:focus,
        .mail-form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .mail-form-textarea {
            min-height: 300px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.6;
        }

        .mail-recipient-search {
            position: relative;
        }

        .mail-recipient-search input {
            width: 100%;
        }

        .mail-recipient-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: var(--card-shadow);
            z-index: 100;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .mail-recipient-results.show {
            display: block;
        }

        .mail-recipient-item {
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .mail-recipient-item:hover {
            background-color: #f8f9fa;
        }

        .mail-recipient-avatar {
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

        .mail-recipient-info {
            flex: 1;
            min-width: 0;
        }

        .mail-recipient-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .mail-recipient-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-selected-recipient {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            margin-top: 0.5rem;
        }

        .mail-remove-recipient {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mail-remove-recipient:hover {
            background-color: var(--danger);
            color: white;
        }

        .mail-attachments {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .mail-attachment-item {
            background-color: #f8f9fa;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .mail-remove-attachment {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0.125rem;
            border-radius: 4px;
        }

        .mail-remove-attachment:hover {
            color: var(--danger);
        }

        .mail-form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .mail-action-buttons {
            display: flex;
            gap: 0.75rem;
        }

        .mail-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .mail-btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .mail-btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
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

        .mail-btn-success {
            background-color: var(--success);
            color: white;
        }

        .mail-btn-success:hover {
            background-color: #3db8d9;
        }

        .mail-btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .mail-btn-danger:hover {
            background-color: #d32f2f;
        }

        .mail-character-count {
            color: var(--gray);
            font-size: 0.75rem;
            text-align: right;
        }

        .mail-priority-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .mail-priority-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            background-color: #f8f9fa;
            color: var(--gray);
            border: 2px solid transparent;
        }

        .mail-priority-badge:hover {
            background-color: #e9ecef;
        }

        .mail-priority-badge.selected {
            border-color: currentColor;
        }

        .mail-priority-badge.priority-low {
            color: var(--success);
        }

        .mail-priority-badge.priority-normal {
            color: var(--info);
        }

        .mail-priority-badge.priority-high {
            color: var(--warning);
        }

        .mail-toolbar {
            display: flex;
            gap: 0.5rem;
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }

        .mail-toolbar-btn {
            background: none;
            border: none;
            padding: 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
        }

        .mail-toolbar-btn:hover {
            background-color: white;
            color: var(--dark);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .mail-original-message {
            background-color: #f8f9fa;
            border-left: 3px solid var(--border);
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 0 6px 6px 0;
            font-size: 0.875rem;
            color: var(--gray);
            white-space: pre-wrap;
        }

        .mail-original-header {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }

        .mail-file-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mail-compose-container {
                padding: 1rem;
            }

            .mail-compose-card {
                padding: 1rem;
            }

            .mail-form-row {
                flex-direction: column;
                gap: 1.5rem;
            }

            .mail-form-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .mail-action-buttons {
                width: 100%;
                flex-direction: column;
            }

            .mail-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="mail-compose-container">
        <div class="mail-compose-header">
            <h1>
                <?php if ($reply_to): ?>
                    <i class="fas fa-reply"></i> Reply to Message
                <?php elseif ($forward_message): ?>
                    <i class="fas fa-share"></i> Forward Message
                <?php elseif ($draft_id): ?>
                    <i class="fas fa-edit"></i> Edit Draft
                <?php else: ?>
                    <i class="fas fa-plus"></i> Compose New Message
                <?php endif; ?>
            </h1>
            <a href="index.php" class="mail-back-btn">
                <i class="fas fa-arrow-left"></i> Back to Inbox
            </a>
        </div>

        <?php if (!empty($errors)): ?>
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

        <div class="mail-compose-card">
            <form method="POST" class="mail-compose-form" enctype="multipart/form-data">
                <input type="hidden" name="parent_id" value="<?php echo $message['parent_id']; ?>">

                <div class="mail-form-group">
                    <label class="mail-form-label">To:</label>
                    <div class="mail-recipient-search">
                        <input type="text"
                            class="mail-form-input"
                            id="recipientSearch"
                            placeholder="Start typing to search for recipients..."
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            autocomplete="off">

                        <input type="hidden" name="receiver_id" id="receiverId" value="<?php echo $message['receiver_id']; ?>">

                        <?php if ($message['receiver_id'] && !empty($recipient_name)): ?>
                            <div class="mail-selected-recipient">
                                <span><?php echo $recipient_name; ?></span>
                                <button type="button" class="mail-remove-recipient" onclick="clearRecipient()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="mail-recipient-results" id="recipientResults"></div>
                    </div>
                </div>

                <div class="mail-form-group">
                    <label class="mail-form-label">Subject:</label>
                    <input type="text"
                        class="mail-form-input"
                        name="subject"
                        placeholder="Message subject"
                        value="<?php echo htmlspecialchars($message['subject']); ?>"
                        required>
                </div>

                <div class="mail-form-row">
                    <div class="mail-form-group">
                        <label class="mail-form-label">Priority:</label>
                        <div class="mail-priority-badges">
                            <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $value => $label): ?>
                                <div class="mail-priority-badge priority-<?php echo $value; ?> <?php echo $message['priority'] == $value ? 'selected' : ''; ?>"
                                    onclick="setPriority('<?php echo $value; ?>')">
                                    <i class="fas fa-<?php echo $value == 'high' ? 'exclamation-circle' : ($value == 'low' ? 'arrow-down' : 'circle'); ?>"></i>
                                    <?php echo $label; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="priority" id="messagePriority" value="<?php echo $message['priority']; ?>">
                    </div>

                    <div class="mail-form-group">
                        <label class="mail-form-label">Attachments (optional):</label>
                        <div class="mail-file-input-wrapper">
                            <button type="button" class="mail-btn mail-btn-secondary">
                                <i class="fas fa-paperclip"></i> Attach Files
                            </button>
                            <input type="file"
                                class="mail-file-input"
                                name="attachments[]"
                                id="attachments"
                                multiple
                                onchange="showAttachments()">
                        </div>
                        <div class="mail-attachments" id="attachmentList"></div>
                    </div>
                </div>

                <?php if ($reply_to || $forward_message): ?>
                    <div class="mail-original-message">
                        <div class="mail-original-header">
                            <?php if ($reply_to): ?>
                                <i class="fas fa-reply"></i> Replying to:
                            <?php else: ?>
                                <i class="fas fa-share"></i> Forwarding:
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 0.875rem; color: var(--gray);">
                            <?php
                            $original_info = $reply_to ? $reply_to : $forward_message;
                            if ($original_info):
                            ?>
                                <strong>From:</strong> <?php echo htmlspecialchars($original_info['sender_first_name'] . ' ' . $original_info['sender_last_name']); ?><br>
                                <strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($original_info['created_at'])); ?><br>
                                <strong>Subject:</strong> <?php echo htmlspecialchars($original_info['subject']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mail-form-group">
                    <div class="mail-toolbar" id="messageToolbar">
                        <button type="button" class="mail-toolbar-btn" onclick="formatText('bold')" title="Bold">
                            <i class="fas fa-bold"></i>
                        </button>
                        <button type="button" class="mail-toolbar-btn" onclick="formatText('italic')" title="Italic">
                            <i class="fas fa-italic"></i>
                        </button>
                        <button type="button" class="mail-toolbar-btn" onclick="formatText('underline')" title="Underline">
                            <i class="fas fa-underline"></i>
                        </button>
                        <div style="width: 1px; background-color: var(--border); margin: 0 0.25rem;"></div>
                        <button type="button" class="mail-toolbar-btn" onclick="insertList('ul')" title="Bullet List">
                            <i class="fas fa-list-ul"></i>
                        </button>
                        <button type="button" class="mail-toolbar-btn" onclick="insertList('ol')" title="Numbered List">
                            <i class="fas fa-list-ol"></i>
                        </button>
                    </div>

                    <textarea class="mail-form-textarea"
                        name="message"
                        id="messageContent"
                        placeholder="Type your message here..."
                        oninput="updateCharacterCount()"
                        required><?php echo htmlspecialchars($message['message']); ?></textarea>

                    <div class="mail-character-count" id="characterCount">
                        Characters: <span id="charCount">0</span>
                    </div>
                </div>

                <div class="mail-form-actions">
                    <div class="mail-action-buttons">
                        <button type="submit" name="action" value="send" class="mail-btn mail-btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                        <button type="submit" name="action" value="save_draft" class="mail-btn mail-btn-secondary">
                            <i class="fas fa-save"></i> Save as Draft
                        </button>
                        <button type="button" onclick="window.location.href='index.php'" class="mail-btn mail-btn-danger">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>

                    <div id="draftStatus">
                        <?php if ($draft_id): ?>
                            <span style="color: var(--gray); font-size: 0.875rem;">
                                <i class="fas fa-info-circle"></i> Editing draft
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Initialize character count
        document.addEventListener('DOMContentLoaded', function() {
            updateCharacterCount();

            // Warn user if they try to leave with unsaved changes
            const messageContent = document.getElementById('messageContent');
            const originalContent = messageContent.value;

            window.addEventListener('beforeunload', function(e) {
                if (messageContent.value !== originalContent && messageContent.value.trim()) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
        });

        // Recipient search
        const recipientSearch = document.getElementById('recipientSearch');
        const recipientResults = document.getElementById('recipientResults');
        const receiverId = document.getElementById('receiverId');

        let searchTimeout;

        recipientSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(searchRecipients, 300);
        });

        recipientSearch.addEventListener('focus', function() {
            if (this.value.trim() && !receiverId.value) {
                searchRecipients();
            }
        });

        document.addEventListener('click', function(e) {
            if (!recipientSearch.contains(e.target) && !recipientResults.contains(e.target)) {
                recipientResults.classList.remove('show');
            }
        });

        function searchRecipients() {
            const term = recipientSearch.value.trim();
            if (!term) {
                recipientResults.classList.remove('show');
                return;
            }

            // Use AJAX to search for recipients
            fetch('search_recipients.php?q=' + encodeURIComponent(term))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(users => {
                    recipientResults.innerHTML = '';

                    if (users.length === 0) {
                        recipientResults.innerHTML = `
                            <div class="mail-recipient-item" style="color: var(--gray);">
                                <i class="fas fa-search"></i> No users found
                            </div>
                        `;
                    } else {
                        users.forEach(user => {
                            const item = document.createElement('div');
                            item.className = 'mail-recipient-item';
                            item.innerHTML = `
                                <div class="mail-recipient-avatar">
                                    ${getInitials(user.first_name, user.last_name)}
                                </div>
                                <div class="mail-recipient-info">
                                    <h4>${escapeHtml(user.first_name + ' ' + user.last_name)}</h4>
                                    <p>${escapeHtml(user.email)} • ${escapeHtml(user.role)}</p>
                                </div>
                            `;

                            item.addEventListener('click', function() {
                                selectRecipient(user.id, user.first_name + ' ' + user.last_name);
                            });

                            recipientResults.appendChild(item);
                        });
                    }

                    recipientResults.classList.add('show');
                })
                .catch(error => {
                    console.error('Error searching recipients:', error);
                    recipientResults.innerHTML = `
                        <div class="mail-recipient-item" style="color: var(--danger);">
                            <i class="fas fa-exclamation-circle"></i> Error searching recipients
                        </div>
                    `;
                    recipientResults.classList.add('show');
                });
        }

        function selectRecipient(id, name) {
            receiverId.value = id;
            recipientSearch.value = name;
            recipientResults.classList.remove('show');

            // Show selected recipient badge
            const searchContainer = recipientSearch.parentElement;
            let badge = searchContainer.querySelector('.mail-selected-recipient');

            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'mail-selected-recipient';
                searchContainer.appendChild(badge);
            }

            badge.innerHTML = `
                <span>${escapeHtml(name)}</span>
                <button type="button" class="mail-remove-recipient" onclick="clearRecipient()">
                    <i class="fas fa-times"></i>
                </button>
            `;
        }

        function clearRecipient() {
            receiverId.value = '';
            recipientSearch.value = '';
            const badge = recipientSearch.parentElement.querySelector('.mail-selected-recipient');
            if (badge) {
                badge.remove();
            }
        }

        function getInitials(firstName, lastName) {
            return (firstName ? firstName.charAt(0) : '') + (lastName ? lastName.charAt(0) : '');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Priority selection
        function setPriority(priority) {
            document.getElementById('messagePriority').value = priority;

            // Update UI
            document.querySelectorAll('.mail-priority-badge').forEach(badge => {
                badge.classList.remove('selected');
            });

            const selectedBadge = document.querySelector(`.mail-priority-badge.priority-${priority}`);
            if (selectedBadge) {
                selectedBadge.classList.add('selected');
            }
        }

        // Text formatting
        function formatText(command) {
            const textarea = document.getElementById('messageContent');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);

            let formattedText = selectedText;

            switch (command) {
                case 'bold':
                    formattedText = `**${selectedText}**`;
                    break;
                case 'italic':
                    formattedText = `*${selectedText}*`;
                    break;
                case 'underline':
                    formattedText = `__${selectedText}__`;
                    break;
            }

            textarea.value = textarea.value.substring(0, start) +
                formattedText +
                textarea.value.substring(end);

            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + formattedText.length;

            updateCharacterCount();
        }

        function insertList(type) {
            const textarea = document.getElementById('messageContent');
            const start = textarea.selectionStart;

            let listItem = '';
            if (type === 'ul') {
                listItem = '- ';
            } else {
                listItem = '1. ';
            }

            textarea.value = textarea.value.substring(0, start) +
                listItem +
                textarea.value.substring(start);

            textarea.focus();
            updateCharacterCount();
        }

        // Character count
        function updateCharacterCount() {
            const text = document.getElementById('messageContent').value;
            const charCount = text.length;
            document.getElementById('charCount').textContent = charCount.toLocaleString();

            // Warn if message is very long
            const countElement = document.getElementById('characterCount');
            if (charCount > 10000) {
                countElement.style.color = 'var(--warning)';
            } else if (charCount > 5000) {
                countElement.style.color = 'var(--info)';
            } else {
                countElement.style.color = 'var(--gray)';
            }
        }

        // Attachments
        function showAttachments() {
            const files = document.getElementById('attachments').files;
            const attachmentList = document.getElementById('attachmentList');
            attachmentList.innerHTML = '';

            if (files.length === 0) {
                return;
            }

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const item = document.createElement('div');
                item.className = 'mail-attachment-item';
                item.innerHTML = `
                    <i class="fas fa-paperclip"></i>
                    <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
                    <button type="button" class="mail-remove-attachment" onclick="removeAttachment(${i})">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                attachmentList.appendChild(item);
            }
        }

        function removeAttachment(index) {
            const input = document.getElementById('attachments');
            const dt = new DataTransfer();

            for (let i = 0; i < input.files.length; i++) {
                if (i !== index) {
                    dt.items.add(input.files[i]);
                }
            }

            input.files = dt.files;
            showAttachments();
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter to send
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('[name="action"][value="send"]').click();
            }

            // Ctrl + S to save draft
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('[name="action"][value="save_draft"]').click();
            }
        });
    </script>
</body>

</html>