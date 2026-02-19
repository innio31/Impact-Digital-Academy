<?php
// modules/shared/mail/drafts.php

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

// Check if message_drafts table exists, create if not
$table_check = $conn->query("SHOW TABLES LIKE 'message_drafts'");
if ($table_check->num_rows == 0) {
    createDraftsTable($conn);
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);

    if (isset($_POST['draft_ids']) && is_array($_POST['draft_ids'])) {
        $draft_ids = array_map('intval', $_POST['draft_ids']);
        $placeholders = implode(',', array_fill(0, count($draft_ids), '?'));

        switch ($action) {
            case 'delete':
                $sql = "DELETE FROM message_drafts WHERE id IN ($placeholders) AND user_id = ?";
                $params = array_merge($draft_ids, [$current_user['id']]);
                break;

            case 'send':
                // Get drafts and send them
                $sent_count = 0;
                foreach ($draft_ids as $draft_id) {
                    if (sendDraft($draft_id, $conn, $current_user['id'])) {
                        $sent_count++;
                    }
                }

                if ($sent_count > 0) {
                    $_SESSION['success'] = "Sent $sent_count draft(s) successfully";
                } else {
                    $_SESSION['error'] = "Failed to send drafts";
                }
                header("Location: drafts.php");
                exit();

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

// Handle single draft actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitize($_GET['action']);
    $draft_id = (int)$_GET['id'];

    switch ($action) {
        case 'edit':
            header("Location: compose.php?draft_id=$draft_id");
            exit();

        case 'delete':
            $sql = "DELETE FROM message_drafts WHERE id = ? AND user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $draft_id, $current_user['id']);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Draft deleted successfully";
            } else {
                $_SESSION['error'] = "Failed to delete draft";
            }
            break;

        case 'send':
            if (sendDraft($draft_id, $conn, $current_user['id'])) {
                $_SESSION['success'] = "Draft sent successfully";
            } else {
                $_SESSION['error'] = "Failed to send draft";
            }
            break;

        case 'duplicate':
            if (duplicateDraft($draft_id, $conn, $current_user['id'])) {
                $_SESSION['success'] = "Draft duplicated successfully";
            } else {
                $_SESSION['error'] = "Failed to duplicate draft";
            }
            break;
    }

    header("Location: drafts.php");
    exit();
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? sanitize($_GET['sort_by']) : 'updated_at';
$sort_order = isset($_GET['sort_order']) ? sanitize($_GET['sort_order']) : 'desc';

// Get drafts with optional search
$sql = "SELECT d.*, 
               u.first_name as receiver_first_name,
               u.last_name as receiver_last_name,
               u.email as receiver_email
        FROM message_drafts d
        LEFT JOIN users u ON u.id = d.receiver_id
        WHERE d.user_id = ?";

$params = [$current_user['id']];
$types = "i";

if (!empty($search)) {
    $sql .= " AND (d.subject LIKE ? OR d.message LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, array_fill(0, 5, $search_term));
    $types .= str_repeat("s", 5);
}

// Add sorting
$valid_sort_columns = ['subject', 'receiver_id', 'created_at', 'updated_at'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'updated_at';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

$sql .= " ORDER BY d.$sort_by $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Get drafts
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$drafts = $result->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$count_sql = preg_replace('/LIMIT \? OFFSET \?$/i', '', $sql);
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);

$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM ($count_sql) as count_table");
if ($count_params) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_drafts = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_drafts / $limit);

// Get draft statistics
$stats_sql = "SELECT 
               COUNT(*) as total,
               SUM(CASE WHEN receiver_id IS NULL THEN 1 ELSE 0 END) as no_recipient,
               SUM(CASE WHEN LENGTH(TRIM(subject)) = 0 THEN 1 ELSE 0 END) as no_subject,
               SUM(CASE WHEN LENGTH(TRIM(message)) = 0 THEN 1 ELSE 0 END) as no_content
        FROM message_drafts 
        WHERE user_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $current_user['id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$draft_stats = $stats_result->fetch_assoc();

// Get recent recipients for quick compose
$recipients_sql = "SELECT DISTINCT d.receiver_id, 
                         u.first_name, u.last_name, u.email
                  FROM message_drafts d
                  JOIN users u ON u.id = d.receiver_id
                  WHERE d.user_id = ? AND d.receiver_id IS NOT NULL
                  ORDER BY d.updated_at DESC
                  LIMIT 5";

$recipients_stmt = $conn->prepare($recipients_sql);
$recipients_stmt->bind_param("i", $current_user['id']);
$recipients_stmt->execute();
$recipients_result = $recipients_stmt->get_result();
$recent_recipients = $recipients_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($current_user['id'], 'mail_drafts', 'Accessed draft messages', $_SERVER['REMOTE_ADDR']);

// Helper functions
function createDraftsTable($conn)
{
    $sql = "CREATE TABLE message_drafts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        receiver_id INT DEFAULT NULL,
        subject VARCHAR(255) DEFAULT '',
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (receiver_id) REFERENCES users(id)
    )";

    $conn->query($sql);
}

function sendDraft($draft_id, $conn, $user_id)
{
    // Get draft
    $sql = "SELECT * FROM message_drafts WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $draft_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $draft = $result->fetch_assoc();

    if (!$draft) {
        return false;
    }

    // Check if recipient is specified
    if (empty($draft['receiver_id'])) {
        return false;
    }

    // Send message
    $send_sql = "INSERT INTO internal_messages 
                 (sender_id, receiver_id, subject, message, created_at) 
                 VALUES (?, ?, ?, ?, NOW())";

    $send_stmt = $conn->prepare($send_sql);
    $send_stmt->bind_param(
        "iiss",
        $user_id,
        $draft['receiver_id'],
        $draft['subject'],
        $draft['message']
    );

    if ($send_stmt->execute()) {
        // Delete draft after sending
        $delete_sql = "DELETE FROM message_drafts WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $draft_id);
        $delete_stmt->execute();

        // Log activity
        logActivity(
            $user_id,
            'draft_sent',
            'Sent draft message',
            $_SERVER['REMOTE_ADDR'],
            'internal_messages',
            $conn->insert_id
        );

        return true;
    }

    return false;
}

function duplicateDraft($draft_id, $conn, $user_id)
{
    $sql = "INSERT INTO message_drafts (user_id, receiver_id, subject, message)
            SELECT user_id, receiver_id, CONCAT('Copy of ', subject), message
            FROM message_drafts 
            WHERE id = ? AND user_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $draft_id, $user_id);
    return $stmt->execute();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Messages - Mail Center</title>
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

        .mail-drafts-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .mail-drafts-header {
            margin-bottom: 2rem;
        }

        .mail-drafts-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .mail-drafts-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Statistics Cards */
        .mail-draft-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .mail-stat-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }

        .mail-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .mail-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .mail-stat-icon.total {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .mail-stat-icon.incomplete {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .mail-stat-info h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .mail-stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .mail-stat-desc {
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Main Content */
        .mail-drafts-main {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .mail-drafts-main {
                grid-template-columns: 1fr;
            }
        }

        /* Drafts List */
        .mail-drafts-list-container {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .mail-drafts-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-drafts-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-drafts-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
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
            width: 200px;
            transition: var(--transition);
        }

        .mail-search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
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

        .mail-btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .mail-btn-warning:hover {
            background-color: #e01171;
        }

        /* Bulk Actions */
        .mail-bulk-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 8px;
        }

        .mail-select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .mail-bulk-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mail-bulk-select select {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
        }

        /* Drafts Table */
        .mail-drafts-table {
            width: 100%;
            border-collapse: collapse;
        }

        .mail-drafts-table thead {
            background-color: #f8f9fa;
        }

        .mail-drafts-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
            cursor: pointer;
            user-select: none;
        }

        .mail-drafts-table th:hover {
            background-color: #e9ecef;
        }

        .mail-drafts-table th i {
            margin-left: 0.25rem;
            font-size: 0.75rem;
            opacity: 0.5;
        }

        .mail-drafts-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
            vertical-align: top;
        }

        .mail-drafts-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .mail-draft-checkbox {
            width: 18px;
            height: 18px;
        }

        .mail-draft-subject {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            display: block;
        }

        .mail-draft-preview {
            color: var(--gray);
            font-size: 0.8125rem;
            margin-top: 0.25rem;
            overflow: hidden;

            /* Modern browsers - the standard way */
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            /* Standard properties for compatibility */
            display: box;
            line-clamp: 2;
            box-orient: vertical;

            /* Fallback for older browsers */
            max-height: 2.4em;
            /* approximately 2 lines based on your font-size */
            line-height: 1.2;
        }

        .mail-draft-recipient {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mail-draft-avatar {
            width: 28px;
            height: 28px;
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

        .mail-draft-recipient-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .mail-draft-recipient-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-draft-date {
            color: var(--gray);
            font-size: 0.8125rem;
            white-space: nowrap;
        }

        .mail-draft-actions {
            display: flex;
            gap: 0.25rem;
        }

        .mail-draft-action {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mail-draft-action:hover {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .mail-draft-action.edit:hover {
            color: var(--primary);
        }

        .mail-draft-action.send:hover {
            color: var(--success);
        }

        .mail-draft-action.duplicate:hover {
            color: var(--info);
        }

        .mail-draft-action.delete:hover {
            color: var(--danger);
        }

        /* Draft Status Indicators */
        .mail-draft-status {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 0.5rem;
        }

        .mail-status-badge {
            padding: 0.125rem 0.5rem;
            border-radius: 10px;
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .mail-status-badge.warning {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .mail-status-badge.info {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        /* Empty State */
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

        /* Pagination */
        .mail-pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
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
            font-size: 0.875rem;
        }

        .mail-page-btn:hover {
            background-color: #f8f9fa;
        }

        .mail-page-btn.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Sidebar */
        .mail-drafts-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .mail-sidebar-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .mail-sidebar-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        /* Quick Actions */
        .mail-quick-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .mail-quick-action {
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background-color: white;
            color: var(--dark);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
        }

        .mail-quick-action:hover {
            background-color: #f8f9fa;
            border-color: var(--primary);
            color: var(--primary);
            transform: translateX(5px);
        }

        .mail-quick-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .mail-quick-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .mail-quick-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Recent Recipients */
        .mail-recent-recipients {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .mail-recipient-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark);
        }

        .mail-recipient-item:hover {
            background-color: #f8f9fa;
        }

        .mail-recipient-avatar {
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

        .mail-recipient-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .mail-recipient-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Tips */
        .mail-tips {
            background-color: rgba(76, 201, 240, 0.1);
            border: 1px solid var(--success);
            border-radius: 8px;
            padding: 1rem;
        }

        .mail-tips h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--success);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mail-tips ul {
            padding-left: 1.25rem;
            margin: 0;
        }

        .mail-tips li {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        /* Alerts */
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

        @media (max-width: 768px) {
            .mail-drafts-container {
                padding: 1rem;
            }

            .mail-draft-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .mail-drafts-header-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .mail-drafts-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .mail-search-box input {
                width: 100%;
            }

            .mail-bulk-actions {
                flex-direction: column;
                align-items: flex-start;
            }

            .mail-drafts-table {
                font-size: 0.75rem;
            }

            .mail-drafts-table th,
            .mail-drafts-table td {
                padding: 0.75rem 0.5rem;
            }

            .mail-draft-actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="mail-drafts-container">
        <!-- Header -->
        <div class="mail-drafts-header">
            <h1><i class="fas fa-edit"></i> Draft Messages</h1>
            <p>Manage your unsent messages and finish composing them</p>
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

        <!-- Statistics -->
        <div class="mail-draft-stats">
            <div class="mail-stat-card">
                <div class="mail-stat-icon total">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="mail-stat-info">
                    <h3>Total Drafts</h3>
                    <div class="mail-stat-number"><?php echo $draft_stats['total'] ?? 0; ?></div>
                    <p class="mail-stat-desc">All unsent messages</p>
                </div>
            </div>

            <div class="mail-stat-card">
                <div class="mail-stat-icon incomplete">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="mail-stat-info">
                    <h3>No Recipient</h3>
                    <div class="mail-stat-number"><?php echo $draft_stats['no_recipient'] ?? 0; ?></div>
                    <p class="mail-stat-desc">Need recipient address</p>
                </div>
            </div>

            <div class="mail-stat-card">
                <div class="mail-stat-icon incomplete">
                    <i class="fas fa-heading"></i>
                </div>
                <div class="mail-stat-info">
                    <h3>No Subject</h3>
                    <div class="mail-stat-number"><?php echo $draft_stats['no_subject'] ?? 0; ?></div>
                    <p class="mail-stat-desc">Missing subject line</p>
                </div>
            </div>

            <div class="mail-stat-card">
                <div class="mail-stat-icon incomplete">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="mail-stat-info">
                    <h3>Empty Content</h3>
                    <div class="mail-stat-number"><?php echo $draft_stats['no_content'] ?? 0; ?></div>
                    <p class="mail-stat-desc">No message body</p>
                </div>
            </div>
        </div>

        <div class="mail-drafts-main">
            <!-- Main Content -->
            <main class="mail-drafts-list-container">
                <!-- Header Bar -->
                <div class="mail-drafts-header-bar">
                    <h2 class="mail-drafts-title">Your Drafts</h2>

                    <div class="mail-drafts-actions">
                        <div class="mail-search-box">
                            <i class="fas fa-search"></i>
                            <input type="text"
                                placeholder="Search drafts..."
                                id="draftSearch"
                                value="<?php echo htmlspecialchars($search); ?>"
                                onkeypress="if(event.key === 'Enter') searchDrafts()">
                        </div>

                        <a href="compose.php" class="mail-btn mail-btn-primary">
                            <i class="fas fa-plus"></i> New Draft
                        </a>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <?php if (!empty($drafts)): ?>
                    <form method="POST" id="bulkActionForm" class="mail-bulk-actions">
                        <div class="mail-select-all">
                            <input type="checkbox" id="selectAll" class="mail-draft-checkbox">
                            <label for="selectAll">Select All</label>
                        </div>

                        <div class="mail-bulk-select">
                            <select name="action" style="padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                                <option value="">Bulk Actions</option>
                                <option value="send">Send Selected</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            <button type="submit" class="mail-btn mail-btn-primary" style="padding: 0.5rem 1rem;">
                                Apply
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- Drafts Table -->
                <?php if (empty($drafts)): ?>
                    <div class="mail-empty-state">
                        <i class="fas fa-edit"></i>
                        <h3>No draft messages</h3>
                        <p>You haven't saved any draft messages yet.</p>
                        <a href="compose.php" class="mail-btn mail-btn-primary" style="margin-top: 1rem; display: inline-flex;">
                            <i class="fas fa-plus"></i> Create Your First Draft
                        </a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="mail-drafts-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th onclick="sortTable('subject')">
                                        Subject
                                        <?php if ($sort_by == 'subject'): ?>
                                            <i class="fas fa-arrow-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </th>
                                    <th onclick="sortTable('receiver_id')">
                                        Recipient
                                        <?php if ($sort_by == 'receiver_id'): ?>
                                            <i class="fas fa-arrow-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </th>
                                    <th onclick="sortTable('updated_at')">
                                        Last Modified
                                        <?php if ($sort_by == 'updated_at'): ?>
                                            <i class="fas fa-arrow-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drafts as $draft): ?>
                                    <?php
                                    $has_recipient = !empty($draft['receiver_id']);
                                    $has_subject = !empty(trim($draft['subject']));
                                    $has_content = !empty(trim($draft['message']));
                                    $is_complete = $has_recipient && $has_subject && $has_content;

                                    // Get recipient info
                                    if ($has_recipient) {
                                        $recipient_name = htmlspecialchars($draft['receiver_first_name'] . ' ' . $draft['receiver_last_name']);
                                        $recipient_email = htmlspecialchars($draft['receiver_email']);
                                        $avatar_initials = strtoupper(
                                            substr($draft['receiver_first_name'], 0, 1) .
                                                substr($draft['receiver_last_name'], 0, 1)
                                        );
                                    } else {
                                        $recipient_name = 'No recipient';
                                        $recipient_email = '';
                                        $avatar_initials = '?';
                                    }

                                    // Preview text
                                    $preview = strip_tags($draft['message']);
                                    if (strlen($preview) > 100) {
                                        $preview = substr($preview, 0, 100) . '...';
                                    }
                                    ?>

                                    <tr>
                                        <td>
                                            <input type="checkbox"
                                                class="mail-draft-checkbox draft-checkbox"
                                                name="draft_ids[]"
                                                value="<?php echo $draft['id']; ?>">
                                        </td>
                                        <td>
                                            <a href="compose.php?draft_id=<?php echo $draft['id']; ?>" class="mail-draft-subject">
                                                <?php echo $has_subject ? htmlspecialchars($draft['subject']) : '(No subject)'; ?>
                                            </a>
                                            <div class="mail-draft-preview">
                                                <?php echo htmlspecialchars($preview); ?>
                                            </div>

                                            <!-- Status Indicators -->
                                            <div class="mail-draft-status">
                                                <?php if (!$has_recipient): ?>
                                                    <span class="mail-status-badge warning" title="Missing recipient">
                                                        <i class="fas fa-user-slash"></i> No recipient
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!$has_subject): ?>
                                                    <span class="mail-status-badge warning" title="Missing subject">
                                                        <i class="fas fa-heading"></i> No subject
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!$has_content): ?>
                                                    <span class="mail-status-badge warning" title="Empty content">
                                                        <i class="fas fa-file-alt"></i> Empty
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($is_complete): ?>
                                                    <span class="mail-status-badge info" title="Ready to send">
                                                        <i class="fas fa-check-circle"></i> Ready
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mail-draft-recipient">
                                                <div class="mail-draft-avatar">
                                                    <?php echo $avatar_initials; ?>
                                                </div>
                                                <div class="mail-draft-recipient-info">
                                                    <h4><?php echo $recipient_name; ?></h4>
                                                    <?php if ($has_recipient): ?>
                                                        <p><?php echo $recipient_email; ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="mail-draft-date">
                                            <?php echo date('M d, Y', strtotime($draft['updated_at'])); ?><br>
                                            <small style="color: var(--gray-light);">
                                                <?php echo date('h:i A', strtotime($draft['updated_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="mail-draft-actions">
                                                <a href="compose.php?draft_id=<?php echo $draft['id']; ?>"
                                                    class="mail-draft-action edit"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <?php if ($is_complete): ?>
                                                    <a href="drafts.php?action=send&id=<?php echo $draft['id']; ?>"
                                                        class="mail-draft-action send"
                                                        title="Send"
                                                        onclick="return confirm('Send this draft?')">
                                                        <i class="fas fa-paper-plane"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <a href="drafts.php?action=duplicate&id=<?php echo $draft['id']; ?>"
                                                    class="mail-draft-action duplicate"
                                                    title="Duplicate"
                                                    onclick="return confirm('Duplicate this draft?')">
                                                    <i class="fas fa-copy"></i>
                                                </a>

                                                <a href="drafts.php?action=delete&id=<?php echo $draft['id']; ?>"
                                                    class="mail-draft-action delete"
                                                    title="Delete"
                                                    onclick="return confirm('Delete this draft?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
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
                                <a href="<?php echo buildDraftUrl($page - 1, $search, $sort_by, $sort_order); ?>" class="mail-page-btn">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                    <a href="<?php echo buildDraftUrl($i, $search, $sort_by, $sort_order); ?>"
                                        class="mail-page-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                    <span class="mail-page-btn" style="cursor: default;">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo buildDraftUrl($page + 1, $search, $sort_by, $sort_order); ?>" class="mail-page-btn">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>

            <!-- Sidebar -->
            <aside class="mail-drafts-sidebar">
                <!-- Quick Actions -->
                <div class="mail-sidebar-card">
                    <h3 class="mail-sidebar-title">Quick Actions</h3>
                    <div class="mail-quick-actions">
                        <a href="compose.php" class="mail-quick-action">
                            <div class="mail-quick-icon">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="mail-quick-info">
                                <h4>New Draft</h4>
                                <p>Create a new message</p>
                            </div>
                        </a>

                        <a href="index.php" class="mail-quick-action">
                            <div class="mail-quick-icon">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <div class="mail-quick-info">
                                <h4>Go to Inbox</h4>
                                <p>View received messages</p>
                            </div>
                        </a>

                        <a href="sent.php" class="mail-quick-action">
                            <div class="mail-quick-icon">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="mail-quick-info">
                                <h4>Sent Messages</h4>
                                <p>View sent messages</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Recent Recipients -->
                <?php if (!empty($recent_recipients)): ?>
                    <div class="mail-sidebar-card">
                        <h3 class="mail-sidebar-title">Recent Recipients</h3>
                        <div class="mail-recent-recipients">
                            <?php foreach ($recent_recipients as $recipient): ?>
                                <a href="compose.php?to=<?php echo $recipient['receiver_id']; ?>" class="mail-recipient-item">
                                    <div class="mail-recipient-avatar">
                                        <?php echo strtoupper(
                                            substr($recipient['first_name'], 0, 1) .
                                                substr($recipient['last_name'], 0, 1)
                                        ); ?>
                                    </div>
                                    <div class="mail-recipient-info">
                                        <h4><?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($recipient['email']); ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tips -->
                <div class="mail-sidebar-card">
                    <div class="mail-tips">
                        <h4><i class="fas fa-lightbulb"></i> Draft Tips</h4>
                        <ul>
                            <li>Drafts are auto-saved every 30 seconds</li>
                            <li>Complete drafts show a "Ready" badge</li>
                            <li>Click any draft to continue editing</li>
                            <li>Use bulk actions for multiple drafts</li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <script>
        // Helper function to build URLs (defined in PHP for pagination)
        <?php
        function buildDraftUrl($page, $search, $sort_by, $sort_order)
        {
            $params = [];
            if (!empty($search)) $params['search'] = $search;
            if ($sort_by != 'updated_at') $params['sort_by'] = $sort_by;
            if ($sort_order != 'desc') $params['sort_order'] = $sort_order;
            $params['page'] = $page;

            return 'drafts.php?' . http_build_query($params);
        }
        ?>

        // Select all checkboxes
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.draft-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Bulk action form validation
        document.getElementById('bulkActionForm')?.addEventListener('submit', function(e) {
            const action = this.querySelector('select[name="action"]').value;
            const checkboxes = document.querySelectorAll('.draft-checkbox:checked');

            if (!action) {
                e.preventDefault();
                alert('Please select an action');
                return false;
            }

            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one draft');
                return false;
            }

            if (action === 'send' && !confirm('Send selected drafts?')) {
                e.preventDefault();
                return false;
            }

            if (action === 'delete' && !confirm('Delete selected drafts? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        // Search drafts
        function searchDrafts() {
            const searchInput = document.getElementById('draftSearch');
            const searchTerm = searchInput.value.trim();

            if (searchTerm) {
                window.location.href = `drafts.php?search=${encodeURIComponent(searchTerm)}`;
            } else {
                window.location.href = 'drafts.php';
            }
        }

        // Sort table
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort_by');
            const currentOrder = url.searchParams.get('sort_order');

            let newOrder = 'desc';
            if (currentSort === column) {
                newOrder = currentOrder === 'desc' ? 'asc' : 'desc';
            }

            url.searchParams.set('sort_by', column);
            url.searchParams.set('sort_order', newOrder);
            window.location.href = url.toString();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new draft
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'compose.php';
            }

            // Ctrl + F for search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('draftSearch')?.focus();
            }

            // Delete key for selected drafts
            if (e.key === 'Delete') {
                const selected = document.querySelectorAll('.draft-checkbox:checked');
                if (selected.length > 0) {
                    if (confirm(`Delete ${selected.length} selected draft(s)?`)) {
                        const form = document.getElementById('bulkActionForm');
                        form.querySelector('select[name="action"]').value = 'delete';
                        form.submit();
                    }
                }
            }

            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.getElementById('draftSearch');
                if (searchInput && document.activeElement === searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
            }
        });

        // Auto-save indicator
        function showAutoSaveNotification() {
            const notification = document.createElement('div');
            notification.innerHTML = `
                <div style="position: fixed; bottom: 20px; right: 20px; padding: 0.75rem 1.5rem; background-color: var(--success); color: white; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 10000; animation: slideIn 0.3s ease;">
                    <i class="fas fa-save"></i> Draft auto-saved
                </div>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 2000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);

        // Mark complete drafts with animation
        document.addEventListener('DOMContentLoaded', function() {
            const completeBadges = document.querySelectorAll('.mail-status-badge.info');
            completeBadges.forEach(badge => {
                badge.style.animation = 'pulse 2s infinite';
            });
        });

        // Quick send from list view (double-click)
        document.querySelectorAll('.mail-draft-subject').forEach(link => {
            link.addEventListener('dblclick', function(e) {
                e.preventDefault();
                const draftId = this.closest('tr').querySelector('.draft-checkbox').value;
                if (confirm('Edit this draft?')) {
                    window.location.href = `compose.php?draft_id=${draftId}`;
                }
            });
        });

        // Export drafts (CSV)
        function exportDrafts() {
            const confirmExport = confirm('Export all drafts as CSV?');
            if (confirmExport) {
                window.location.href = 'export_drafts.php';
            }
        }

        // Import drafts (placeholder)
        function importDrafts() {
            alert('Import feature coming soon!');
        }

        // Add export/import buttons
        document.addEventListener('DOMContentLoaded', function() {
            const actionsDiv = document.querySelector('.mail-drafts-actions');
            if (actionsDiv && <?php echo $total_drafts > 0 ? 'true' : 'false'; ?>) {
                const exportBtn = document.createElement('button');
                exportBtn.className = 'mail-btn mail-btn-secondary';
                exportBtn.innerHTML = '<i class="fas fa-download"></i> Export';
                exportBtn.onclick = exportDrafts;

                actionsDiv.appendChild(exportBtn);
            }
        });

        // Draft expiration warning (if drafts older than 30 days)
        document.addEventListener('DOMContentLoaded', function() {
            const draftDates = document.querySelectorAll('.mail-draft-date');
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

            draftDates.forEach(dateCell => {
                const dateText = dateCell.textContent.trim();
                const draftDate = new Date(dateText.split('\n')[0] + ' ' + new Date().getFullYear());

                if (draftDate < thirtyDaysAgo) {
                    const row = dateCell.closest('tr');
                    row.style.borderLeft = '4px solid var(--warning)';

                    // Add warning icon
                    const statusDiv = row.querySelector('.mail-draft-status');
                    if (statusDiv) {
                        const warningBadge = document.createElement('span');
                        warningBadge.className = 'mail-status-badge warning';
                        warningBadge.innerHTML = '<i class="fas fa-clock"></i> Old';
                        warningBadge.title = 'Draft is older than 30 days';
                        statusDiv.appendChild(warningBadge);
                    }
                }
            });
        });

        // Quick filter by status
        function filterByStatus(status) {
            let searchTerm = '';

            switch (status) {
                case 'ready':
                    searchTerm = 'status:ready';
                    break;
                case 'incomplete':
                    searchTerm = 'status:incomplete';
                    break;
                case 'no_recipient':
                    searchTerm = 'status:no_recipient';
                    break;
                case 'no_subject':
                    searchTerm = 'status:no_subject';
                    break;
                case 'empty':
                    searchTerm = 'status:empty';
                    break;
            }

            window.location.href = `drafts.php?search=${encodeURIComponent(searchTerm)}`;
        }

        // Add quick filter buttons
        document.addEventListener('DOMContentLoaded', function() {
            const statsCards = document.querySelectorAll('.mail-stat-card');
            statsCards.forEach((card, index) => {
                if (index > 0) { // Skip total count card
                    card.style.cursor = 'pointer';
                    card.addEventListener('click', function() {
                        const title = this.querySelector('h3').textContent.toLowerCase();
                        filterByStatus(title.replace(' ', '_'));
                    });
                }
            });
        });
    </script>
</body>

</html>