<?php
// modules/shared/mail/search.php

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
$search_results = [];
$total_results = 0;
$search_params = [];
$filters = [];

// Get search query and filters
$search_query = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$folder = isset($_GET['folder']) ? sanitize($_GET['folder']) : 'all';
$sender = isset($_GET['sender']) ? (int)$_GET['sender'] : 0;
$has_attachments = isset($_GET['has_attachments']) ? (int)$_GET['has_attachments'] : 0;
$priority = isset($_GET['priority']) ? sanitize($_GET['priority']) : '';
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? sanitize($_GET['sort_by']) : 'date';
$sort_order = isset($_GET['sort_order']) ? sanitize($_GET['sort_order']) : 'desc';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get recent search queries
$recent_searches = [];
if ($search_query) {
    // Store recent search (in session for simplicity, could use database)
    if (!isset($_SESSION['recent_searches'])) {
        $_SESSION['recent_searches'] = [];
    }

    // Add search to recent searches if not already there
    if (!in_array($search_query, $_SESSION['recent_searches'])) {
        array_unshift($_SESSION['recent_searches'], $search_query);
        $_SESSION['recent_searches'] = array_slice($_SESSION['recent_searches'], 0, 10);
    }

    $recent_searches = $_SESSION['recent_searches'];
}

// Perform search if query is provided
if (!empty($search_query) || !empty($sender) || !empty($priority) || !empty($date_from) || !empty($date_to) || $has_attachments) {
    // Build search query
    $sql = "SELECT DISTINCT m.*, 
                   u_sender.first_name as sender_first_name, 
                   u_sender.last_name as sender_last_name,
                   u_sender.email as sender_email,
                   u_receiver.first_name as receiver_first_name,
                   u_receiver.last_name as receiver_last_name,
                   u_receiver.email as receiver_email,
                   (SELECT COUNT(*) FROM message_attachments ma WHERE ma.message_id = m.id) as attachment_count
            FROM internal_messages m
            LEFT JOIN users u_sender ON u_sender.id = m.sender_id
            LEFT JOIN users u_receiver ON u_receiver.id = m.receiver_id
            WHERE (m.sender_id = ? OR m.receiver_id = ?) 
            AND (m.is_deleted_receiver = 0 OR m.is_deleted_sender = 0)";

    $params = [$current_user['id'], $current_user['id']];
    $types = "ii";

    // Add folder filter
    if ($folder != 'all') {
        switch ($folder) {
            case 'inbox':
                $sql .= " AND m.receiver_id = ?";
                $params[] = $current_user['id'];
                $types .= "i";
                break;
            case 'sent':
                $sql .= " AND m.sender_id = ?";
                $params[] = $current_user['id'];
                $types .= "i";
                break;
            case 'unread':
                $sql .= " AND m.receiver_id = ? AND m.is_read = 0";
                $params[] = $current_user['id'];
                $types .= "i";
                break;
            case 'starred':
                // Assuming you have a starred field or table
                $sql .= " AND m.is_starred = 1";
                break;
        }
    }

    // Add search query filter
    if (!empty($search_query)) {
        $sql .= " AND (m.subject LIKE ? OR m.message LIKE ? 
                      OR u_sender.first_name LIKE ? OR u_sender.last_name LIKE ? 
                      OR u_sender.email LIKE ? OR u_receiver.first_name LIKE ? 
                      OR u_receiver.last_name LIKE ? OR u_receiver.email LIKE ?)";
        $search_term = "%$search_query%";
        $params = array_merge($params, array_fill(0, 8, $search_term));
        $types .= str_repeat("s", 8);
    }

    // Add sender filter
    if ($sender > 0) {
        $sql .= " AND m.sender_id = ?";
        $params[] = $sender;
        $types .= "i";
    }

    // Add priority filter
    if (!empty($priority)) {
        $sql .= " AND m.priority = ?";
        $params[] = $priority;
        $types .= "s";
    }

    // Add attachment filter
    if ($has_attachments) {
        $sql .= " AND EXISTS (SELECT 1 FROM message_attachments ma WHERE ma.message_id = m.id)";
    }

    // Add date filters
    if (!empty($date_from)) {
        $sql .= " AND DATE(m.created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }

    if (!empty($date_to)) {
        $sql .= " AND DATE(m.created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    // Add sorting
    switch ($sort_by) {
        case 'date':
            $order_by = "m.created_at";
            break;
        case 'sender':
            $order_by = "u_sender.first_name, u_sender.last_name";
            break;
        case 'subject':
            $order_by = "m.subject";
            break;
        case 'priority':
            $order_by = "m.priority";
            break;
        default:
            $order_by = "m.created_at";
    }

    $sql .= " ORDER BY $order_by $sort_order LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    // Execute search
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $search_results = $result->fetch_all(MYSQLI_ASSOC);

    // Get total count for pagination
    $count_sql = preg_replace('/LIMIT \? OFFSET \?$/i', '', $sql);
    $count_params = array_slice($params, 0, -2);
    $count_types = substr($types, 0, -2);

    $count_stmt = $conn->prepare("SELECT COUNT(DISTINCT m.id) as total FROM ($count_sql) as count_table");
    if ($count_params) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_results = $count_result->fetch_assoc()['total'] ?? 0;
    $total_pages = ceil($total_results / $limit);
}

// Get available senders for filter dropdown
$senders_sql = "SELECT DISTINCT m.sender_id, 
                        u.first_name, u.last_name, u.email
                 FROM internal_messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.receiver_id = ?
                 ORDER BY u.first_name, u.last_name";
$senders_stmt = $conn->prepare($senders_sql);
$senders_stmt->bind_param("i", $current_user['id']);
$senders_stmt->execute();
$senders_result = $senders_stmt->get_result();
$available_senders = $senders_result->fetch_all(MYSQLI_ASSOC);

// Get search suggestions
$suggestions = [];
if (!empty($search_query)) {
    $suggestions_sql = "SELECT DISTINCT m.subject 
                        FROM internal_messages m
                        WHERE (m.sender_id = ? OR m.receiver_id = ?) 
                        AND m.subject LIKE ?
                        LIMIT 5";

    $suggestions_stmt = $conn->prepare($suggestions_sql);
    $search_suggestion = "%$search_query%";
    $suggestions_stmt->bind_param("iis", $current_user['id'], $current_user['id'], $search_suggestion);
    $suggestions_stmt->execute();
    $suggestions_result = $suggestions_stmt->get_result();

    while ($row = $suggestions_result->fetch_assoc()) {
        $suggestions[] = $row['subject'];
    }
}

// Log activity
logActivity($current_user['id'], 'mail_search', 'Searched messages', $_SERVER['REMOTE_ADDR'], null, null, json_encode(['query' => $search_query]));

// Store search filters for display
$filters = [
    'query' => $search_query,
    'folder' => $folder,
    'sender' => $sender,
    'has_attachments' => $has_attachments,
    'priority' => $priority,
    'date_from' => $date_from,
    'date_to' => $date_to,
    'sort_by' => $sort_by,
    'sort_order' => $sort_order
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Messages - Mail Center</title>
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

        .mail-search-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .mail-search-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .mail-search-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .mail-search-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .mail-search-main {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .mail-search-main {
                grid-template-columns: 1fr;
            }
        }

        /* Search Filters Sidebar */
        .mail-search-filters {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            height: fit-content;
            position: sticky;
            top: 2rem;
        }

        .mail-filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mail-filters-header h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mail-clear-filters {
            background: none;
            border: none;
            color: var(--danger);
            font-size: 0.875rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .mail-clear-filters:hover {
            background-color: rgba(230, 57, 70, 0.1);
        }

        .mail-filter-group {
            margin-bottom: 1.5rem;
        }

        .mail-filter-group:last-child {
            margin-bottom: 0;
        }

        .mail-filter-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .mail-filter-input,
        .mail-filter-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .mail-filter-input:focus,
        .mail-filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .mail-filter-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .mail-filter-checkbox input {
            width: 16px;
            height: 16px;
        }

        .mail-filter-checkbox label {
            font-size: 0.875rem;
            color: var(--dark);
            cursor: pointer;
        }

        .mail-date-filters {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .mail-apply-filters {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
        }

        .mail-apply-filters:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Search Results */
        .mail-search-results {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .mail-results-header {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .mail-search-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .mail-results-count {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .mail-results-count strong {
            color: var(--dark);
            font-weight: 600;
        }

        .mail-sort-options {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-sort-label {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .mail-sort-select {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background-color: white;
        }

        .mail-active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .mail-active-filter {
            background-color: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mail-active-filter-remove {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0.125rem;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.625rem;
        }

        .mail-active-filter-remove:hover {
            background-color: var(--danger);
            color: white;
        }

        /* Results List */
        .mail-results-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .mail-result-item {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border-left: 4px solid transparent;
        }

        .mail-result-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .mail-result-item.unread {
            border-left-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }

        .mail-result-item.high-priority {
            border-left-color: var(--warning);
        }

        .mail-result-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .mail-result-sender {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mail-result-avatar {
            width: 36px;
            height: 36px;
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

        .mail-result-sender-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }

        .mail-result-sender-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-result-meta {
            text-align: right;
            font-size: 0.75rem;
            color: var(--gray);
        }

        .mail-result-date {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .mail-result-priority {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .mail-result-priority.priority-high {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .mail-result-priority.priority-low {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .mail-result-content {
            margin-bottom: 0.75rem;
        }

        .mail-result-subject {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: block;
            text-decoration: none;
        }

        .mail-result-subject:hover {
            color: var(--primary);
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

        .mail-result-preview em {
            background-color: yellow;
            font-style: normal;
            font-weight: 600;
        }

        .mail-result-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border);
        }

        .mail-result-folder {
            font-size: 0.75rem;
            color: var(--gray);
            padding: 0.25rem 0.75rem;
            background-color: #f8f9fa;
            border-radius: 12px;
        }

        .mail-result-actions {
            display: flex;
            gap: 0.5rem;
        }

        .mail-result-action {
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

        .mail-result-action:hover {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .mail-result-action.attachment {
            color: var(--info);
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

        /* Pagination */
        .mail-pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
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

        .mail-page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Recent Searches */
        .mail-recent-searches {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-top: 1.5rem;
        }

        .mail-recent-searches h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .mail-recent-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .mail-recent-item {
            background-color: #f8f9fa;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .mail-recent-item:hover {
            background-color: var(--primary);
            color: white;
        }

        /* Search Suggestions */
        .mail-search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: var(--card-shadow);
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .mail-search-suggestions.show {
            display: block;
        }

        .mail-suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .mail-suggestion-item:hover {
            background-color: #f8f9fa;
        }

        @media (max-width: 768px) {
            .mail-search-container {
                padding: 1rem;
            }

            .mail-results-header {
                padding: 1rem;
            }

            .mail-search-stats {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .mail-result-item {
                padding: 1rem;
            }

            .mail-result-header {
                flex-direction: column;
                gap: 0.5rem;
            }

            .mail-result-meta {
                text-align: left;
                width: 100%;
            }

            .mail-date-filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="mail-search-container">
        <!-- Header -->
        <div class="mail-search-header">
            <h1><i class="fas fa-search"></i> Search Messages</h1>
            <p>Find messages using powerful search filters</p>
        </div>

        <div class="mail-search-main">
            <!-- Filters Sidebar -->
            <aside class="mail-search-filters">
                <div class="mail-filters-header">
                    <h3><i class="fas fa-filter"></i> Filters</h3>
                    <?php if ($search_query || $sender || $priority || $date_from || $date_to || $has_attachments): ?>
                        <button type="button" class="mail-clear-filters" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear All
                        </button>
                    <?php endif; ?>
                </div>

                <form method="GET" id="searchForm">
                    <!-- Search Query -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">Search for:</label>
                        <div style="position: relative;">
                            <input type="text"
                                class="mail-filter-input"
                                name="q"
                                placeholder="Search messages, subjects, senders..."
                                value="<?php echo htmlspecialchars($search_query); ?>"
                                autocomplete="off"
                                id="searchInput"
                                oninput="showSuggestions()">

                            <div class="mail-search-suggestions" id="searchSuggestions">
                                <?php foreach ($suggestions as $suggestion): ?>
                                    <div class="mail-suggestion-item" onclick="selectSuggestion('<?php echo htmlspecialchars($suggestion); ?>')">
                                        <?php echo htmlspecialchars($suggestion); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Folder Filter -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">Folder:</label>
                        <select class="mail-filter-select" name="folder">
                            <option value="all" <?php echo $folder == 'all' ? 'selected' : ''; ?>>All Messages</option>
                            <option value="inbox" <?php echo $folder == 'inbox' ? 'selected' : ''; ?>>Inbox Only</option>
                            <option value="sent" <?php echo $folder == 'sent' ? 'selected' : ''; ?>>Sent Only</option>
                            <option value="unread" <?php echo $folder == 'unread' ? 'selected' : ''; ?>>Unread Only</option>
                            <option value="starred" <?php echo $folder == 'starred' ? 'selected' : ''; ?>>Starred Only</option>
                        </select>
                    </div>

                    <!-- Sender Filter -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">From:</label>
                        <select class="mail-filter-select" name="sender">
                            <option value="0">Any Sender</option>
                            <?php foreach ($available_senders as $sender_option): ?>
                                <option value="<?php echo $sender_option['sender_id']; ?>"
                                    <?php echo $sender == $sender_option['sender_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sender_option['first_name'] . ' ' . $sender_option['last_name']); ?>
                                    (<?php echo htmlspecialchars($sender_option['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Priority Filter -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">Priority:</label>
                        <select class="mail-filter-select" name="priority">
                            <option value="">Any Priority</option>
                            <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="normal" <?php echo $priority == 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">Date Range:</label>
                        <div class="mail-date-filters">
                            <div>
                                <input type="date"
                                    class="mail-filter-input"
                                    name="date_from"
                                    value="<?php echo $date_from; ?>"
                                    max="<?php echo date('Y-m-d'); ?>">
                                <small style="display: block; margin-top: 0.25rem; font-size: 0.75rem; color: var(--gray);">From</small>
                            </div>
                            <div>
                                <input type="date"
                                    class="mail-filter-input"
                                    name="date_to"
                                    value="<?php echo $date_to; ?>"
                                    max="<?php echo date('Y-m-d'); ?>">
                                <small style="display: block; margin-top: 0.25rem; font-size: 0.75rem; color: var(--gray);">To</small>
                            </div>
                        </div>
                    </div>

                    <!-- Attachment Filter -->
                    <div class="mail-filter-group">
                        <label class="mail-filter-label">Attachments:</label>
                        <div class="mail-filter-checkbox">
                            <input type="checkbox"
                                name="has_attachments"
                                id="hasAttachments"
                                value="1"
                                <?php echo $has_attachments ? 'checked' : ''; ?>>
                            <label for="hasAttachments">Only show messages with attachments</label>
                        </div>
                    </div>

                    <button type="submit" class="mail-apply-filters">
                        <i class="fas fa-search"></i> Search Messages
                    </button>
                </form>
            </aside>

            <!-- Results Section -->
            <main class="mail-search-results">
                <!-- Results Header -->
                <div class="mail-results-header">
                    <div class="mail-search-stats">
                        <div class="mail-results-count">
                            <?php if ($search_query || $sender || $priority || $date_from || $date_to || $has_attachments): ?>
                                <strong><?php echo number_format($total_results); ?></strong> messages found
                            <?php else: ?>
                                <strong>Search for messages</strong> using the filters on the left
                            <?php endif; ?>
                        </div>

                        <div class="mail-sort-options">
                            <span class="mail-sort-label">Sort by:</span>
                            <select class="mail-sort-select" name="sort_by" id="sortBy">
                                <option value="date" <?php echo $sort_by == 'date' ? 'selected' : ''; ?>>Date</option>
                                <option value="sender" <?php echo $sort_by == 'sender' ? 'selected' : ''; ?>>Sender</option>
                                <option value="subject" <?php echo $sort_by == 'subject' ? 'selected' : ''; ?>>Subject</option>
                                <option value="priority" <?php echo $sort_by == 'priority' ? 'selected' : ''; ?>>Priority</option>
                            </select>

                            <select class="mail-sort-select" name="sort_order" id="sortOrder">
                                <option value="desc" <?php echo $sort_order == 'desc' ? 'selected' : ''; ?>>Descending</option>
                                <option value="asc" <?php echo $sort_order == 'asc' ? 'selected' : ''; ?>>Ascending</option>
                            </select>
                        </div>
                    </div>

                    <!-- Active Filters -->
                    <?php if ($search_query || $sender || $priority || $date_from || $date_to || $has_attachments): ?>
                        <div class="mail-active-filters">
                            <?php if ($search_query): ?>
                                <div class="mail-active-filter">
                                    <span>Search: "<?php echo htmlspecialchars($search_query); ?>"</span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('q')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($folder != 'all'): ?>
                                <div class="mail-active-filter">
                                    <span>Folder: <?php echo ucfirst($folder); ?></span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('folder')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($sender): ?>
                                <?php
                                $sender_name = '';
                                foreach ($available_senders as $s) {
                                    if ($s['sender_id'] == $sender) {
                                        $sender_name = $s['first_name'] . ' ' . $s['last_name'];
                                        break;
                                    }
                                }
                                ?>
                                <div class="mail-active-filter">
                                    <span>From: <?php echo htmlspecialchars($sender_name); ?></span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('sender')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($priority): ?>
                                <div class="mail-active-filter">
                                    <span>Priority: <?php echo ucfirst($priority); ?></span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('priority')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($has_attachments): ?>
                                <div class="mail-active-filter">
                                    <span>With attachments</span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('has_attachments')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($date_from): ?>
                                <div class="mail-active-filter">
                                    <span>From: <?php echo $date_from; ?></span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('date_from')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ($date_to): ?>
                                <div class="mail-active-filter">
                                    <span>To: <?php echo $date_to; ?></span>
                                    <button type="button" class="mail-active-filter-remove" onclick="removeFilter('date_to')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Results List -->
                <div class="mail-results-list">
                    <?php if (empty($search_results)): ?>
                        <div class="mail-empty-state">
                            <?php if ($search_query || $sender || $priority || $date_from || $date_to || $has_attachments): ?>
                                <i class="fas fa-search"></i>
                                <h3>No messages found</h3>
                                <p>Try adjusting your search filters or search for something else.</p>
                            <?php else: ?>
                                <i class="fas fa-search"></i>
                                <h3>Search for messages</h3>
                                <p>Use the filters on the left to find specific messages.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($search_results as $message): ?>
                            <?php
                            $is_sender = ($message['sender_id'] == $current_user['id']);
                            $is_unread = (!$is_sender && isset($message['is_read']) && $message['is_read'] == 0);
                            $has_attachments = $message['attachment_count'] > 0;

                            if ($is_sender) {
                                $contact_name = htmlspecialchars($message['receiver_first_name'] . ' ' . $message['receiver_last_name']);
                                $contact_email = htmlspecialchars($message['receiver_email']);
                                $folder_type = 'sent';
                            } else {
                                $contact_name = htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']);
                                $contact_email = htmlspecialchars($message['sender_email']);
                                $folder_type = 'inbox';
                            }

                            // Get initials for avatar
                            $first_name = $is_sender ? $message['receiver_first_name'] : $message['sender_first_name'];
                            $last_name = $is_sender ? $message['receiver_last_name'] : $message['sender_last_name'];
                            $avatar_initials = '';

                            if (!empty($first_name) && !empty($last_name)) {
                                $avatar_initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
                            } else if (!empty($contact_email)) {
                                $avatar_initials = strtoupper(substr($contact_email, 0, 2));
                            } else {
                                $avatar_initials = '??';
                            }

                            // Highlight search terms in preview
                            $preview = strip_tags($message['message']);
                            if (!empty($search_query) && strlen($preview) > 100) {
                                $preview = substr($preview, 0, 100) . '...';
                            }

                            if (!empty($search_query)) {
                                $preview = preg_replace(
                                    "/($search_query)/i",
                                    "<em>$1</em>",
                                    htmlspecialchars($preview)
                                );
                            } else {
                                $preview = htmlspecialchars($preview);
                            }
                            ?>

                            <div class="mail-result-item <?php echo $is_unread ? 'unread' : ''; ?> <?php echo $message['priority'] == 'high' ? 'high-priority' : ''; ?>">
                                <div class="mail-result-header">
                                    <div class="mail-result-sender">
                                        <div class="mail-result-avatar">
                                            <?php echo $avatar_initials; ?>
                                        </div>
                                        <div class="mail-result-sender-info">
                                            <h4><?php echo $contact_name; ?></h4>
                                            <p><?php echo $contact_email; ?></p>
                                        </div>
                                    </div>

                                    <div class="mail-result-meta">
                                        <div class="mail-result-date">
                                            <?php echo date('M d, Y', strtotime($message['created_at'])); ?>
                                        </div>
                                        <?php if ($message['priority'] != 'normal'): ?>
                                            <span class="mail-result-priority priority-<?php echo $message['priority']; ?>">
                                                <?php echo $message['priority']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mail-result-content">
                                    <a href="view.php?id=<?php echo $message['id']; ?>" class="mail-result-subject">
                                        <?php
                                        if (!empty($search_query)) {
                                            echo preg_replace(
                                                "/($search_query)/i",
                                                "<em>$1</em>",
                                                htmlspecialchars($message['subject'])
                                            );
                                        } else {
                                            echo htmlspecialchars($message['subject']);
                                        }
                                        ?>
                                    </a>

                                    <div class="mail-result-preview">
                                        <?php echo $preview; ?>
                                    </div>
                                </div>

                                <div class="mail-result-footer">
                                    <div class="mail-result-folder">
                                        <i class="fas fa-folder"></i> <?php echo ucfirst($folder_type); ?>
                                    </div>

                                    <div class="mail-result-actions">
                                        <?php if ($has_attachments): ?>
                                            <span class="mail-result-action attachment" title="Has attachments">
                                                <i class="fas fa-paperclip"></i>
                                            </span>
                                        <?php endif; ?>

                                        <a href="view.php?id=<?php echo $message['id']; ?>" class="mail-result-action" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <a href="compose.php?reply=<?php echo $message['id']; ?>" class="mail-result-action" title="Reply">
                                            <i class="fas fa-reply"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="mail-pagination">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo buildSearchUrl($page - 1, $filters); ?>" class="mail-page-btn">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <a href="<?php echo buildSearchUrl($i, $filters); ?>"
                                            class="mail-page-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <span class="mail-page-btn" style="cursor: default;">...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo buildSearchUrl($page + 1, $filters); ?>" class="mail-page-btn">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Recent Searches -->
                <?php if (!empty($recent_searches)): ?>
                    <div class="mail-recent-searches">
                        <h3><i class="fas fa-history"></i> Recent Searches</h3>
                        <div class="mail-recent-list">
                            <?php foreach ($recent_searches as $recent_search): ?>
                                <a href="search.php?q=<?php echo urlencode($recent_search); ?>" class="mail-recent-item">
                                    <?php echo htmlspecialchars($recent_search); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script>
        // Helper function to build search URLs (defined in PHP for pagination)
        <?php
        function buildSearchUrl($page, $filters)
        {
            $params = [];
            if (!empty($filters['query'])) $params['q'] = $filters['query'];
            if ($filters['folder'] != 'all') $params['folder'] = $filters['folder'];
            if ($filters['sender']) $params['sender'] = $filters['sender'];
            if ($filters['priority']) $params['priority'] = $filters['priority'];
            if ($filters['date_from']) $params['date_from'] = $filters['date_from'];
            if ($filters['date_to']) $params['date_to'] = $filters['date_to'];
            if ($filters['has_attachments']) $params['has_attachments'] = $filters['has_attachments'];
            if ($filters['sort_by'] != 'date') $params['sort_by'] = $filters['sort_by'];
            if ($filters['sort_order'] != 'desc') $params['sort_order'] = $filters['sort_order'];
            $params['page'] = $page;

            return 'search.php?' . http_build_query($params);
        }
        ?>

        // Sort change handler
        document.getElementById('sortBy').addEventListener('change', function() {
            updateSort();
        });

        document.getElementById('sortOrder').addEventListener('change', function() {
            updateSort();
        });

        function updateSort() {
            const form = document.getElementById('searchForm');
            const sortBy = document.getElementById('sortBy').value;
            const sortOrder = document.getElementById('sortOrder').value;

            // Add hidden inputs for sort parameters
            let sortByInput = form.querySelector('input[name="sort_by"]');
            let sortOrderInput = form.querySelector('input[name="sort_order"]');

            if (!sortByInput) {
                sortByInput = document.createElement('input');
                sortByInput.type = 'hidden';
                sortByInput.name = 'sort_by';
                form.appendChild(sortByInput);
            }

            if (!sortOrderInput) {
                sortOrderInput = document.createElement('input');
                sortOrderInput.type = 'hidden';
                sortOrderInput.name = 'sort_order';
                form.appendChild(sortOrderInput);
            }

            sortByInput.value = sortBy;
            sortOrderInput.value = sortOrder;

            form.submit();
        }

        // Clear all filters
        function clearFilters() {
            window.location.href = 'search.php';
        }

        // Remove specific filter
        function removeFilter(filterName) {
            const url = new URL(window.location.href);
            url.searchParams.delete(filterName);

            // If removing query, also remove page parameter
            if (filterName === 'q') {
                url.searchParams.delete('page');
            }

            window.location.href = url.toString();
        }

        // Search suggestions
        let suggestionTimeout;

        function showSuggestions() {
            clearTimeout(suggestionTimeout);
            suggestionTimeout = setTimeout(fetchSuggestions, 300);
        }

        function fetchSuggestions() {
            const query = document.getElementById('searchInput').value.trim();
            const suggestionsDiv = document.getElementById('searchSuggestions');

            if (!query) {
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.classList.remove('show');
                return;
            }

            fetch('search_suggestions.php?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(suggestions => {
                    suggestionsDiv.innerHTML = '';

                    if (suggestions.length === 0) {
                        suggestionsDiv.innerHTML = `
                            <div class="mail-suggestion-item" style="color: var(--gray);">
                                <i class="fas fa-search"></i> No suggestions found
                            </div>
                        `;
                    } else {
                        suggestions.forEach(suggestion => {
                            const item = document.createElement('div');
                            item.className = 'mail-suggestion-item';
                            item.textContent = suggestion;
                            item.addEventListener('click', function() {
                                selectSuggestion(suggestion);
                            });
                            suggestionsDiv.appendChild(item);
                        });
                    }

                    suggestionsDiv.classList.add('show');
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                });
        }

        function selectSuggestion(suggestion) {
            document.getElementById('searchInput').value = suggestion;
            document.getElementById('searchSuggestions').classList.remove('show');
            document.getElementById('searchForm').submit();
        }

        // Close suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#searchInput') && !e.target.closest('#searchSuggestions')) {
                document.getElementById('searchSuggestions').classList.remove('show');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search box
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }

            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.getElementById('searchInput');
                if (document.activeElement === searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
                document.getElementById('searchSuggestions').classList.remove('show');
            }

            // Arrow keys for suggestion navigation
            if (document.getElementById('searchSuggestions').classList.contains('show')) {
                const suggestions = document.querySelectorAll('.mail-suggestion-item');
                let currentIndex = -1;

                suggestions.forEach((item, index) => {
                    if (item.classList.contains('selected')) {
                        currentIndex = index;
                        item.classList.remove('selected');
                    }
                });

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const newIndex = (currentIndex + 1) % suggestions.length;
                    suggestions[newIndex].classList.add('selected');
                    suggestions[newIndex].scrollIntoView({
                        block: 'nearest'
                    });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const newIndex = (currentIndex - 1 + suggestions.length) % suggestions.length;
                    suggestions[newIndex].classList.add('selected');
                    suggestions[newIndex].scrollIntoView({
                        block: 'nearest'
                    });
                } else if (e.key === 'Enter' && currentIndex >= 0) {
                    e.preventDefault();
                    suggestions[currentIndex].click();
                }
            }
        });

        // Highlight search terms in results
        function highlightText(text, query) {
            if (!query) return text;

            const regex = new RegExp(`(${escapeRegex(query)})`, 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        }

        function escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Quick search from any page
        function quickSearch(query) {
            window.location.href = `search.php?q=${encodeURIComponent(query)}`;
        }

        // Auto-submit form when Enter is pressed in search box
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        });

        // Initialize date pickers max date
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.max) {
                    input.max = today;
                }
            });

            // Update sort hidden inputs if they don't exist
            const form = document.getElementById('searchForm');
            const sortBy = document.getElementById('sortBy').value;
            const sortOrder = document.getElementById('sortOrder').value;

            if (!form.querySelector('input[name="sort_by"]')) {
                const sortByInput = document.createElement('input');
                sortByInput.type = 'hidden';
                sortByInput.name = 'sort_by';
                sortByInput.value = sortBy;
                form.appendChild(sortByInput);
            }

            if (!form.querySelector('input[name="sort_order"]')) {
                const sortOrderInput = document.createElement('input');
                sortOrderInput.type = 'hidden';
                sortOrderInput.name = 'sort_order';
                sortOrderInput.value = sortOrder;
                form.appendChild(sortOrderInput);
            }
        });

        // Toggle advanced filters on mobile
        if (window.innerWidth < 768) {
            const filterGroups = document.querySelectorAll('.mail-filter-group');
            filterGroups.forEach((group, index) => {
                if (index > 0) { // Skip first group (search box)
                    const label = group.querySelector('.mail-filter-label');
                    label.style.cursor = 'pointer';
                    label.addEventListener('click', function() {
                        const inputs = group.querySelectorAll('input, select');
                        inputs.forEach(input => {
                            input.style.display = input.style.display === 'none' ? 'block' : 'none';
                        });
                    });

                    // Hide all inputs initially except search box
                    const inputs = group.querySelectorAll('input, select');
                    inputs.forEach(input => {
                        input.style.display = 'none';
                    });
                }
            });
        }

        // Save search as favorite
        function saveSearch() {
            const searchName = prompt('Enter a name for this search:');
            if (searchName) {
                // Send AJAX request to save search
                const params = new URLSearchParams(window.location.search);
                fetch('save_search.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            name: searchName,
                            params: Object.fromEntries(params)
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Search saved as favorite!');
                        } else {
                            alert('Failed to save search: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error saving search:', error);
                        alert('Failed to save search');
                    });
            }
        }

        // Add save search button
        document.addEventListener('DOMContentLoaded', function() {
            const resultsHeader = document.querySelector('.mail-results-header');
            if (resultsHeader && (<?php echo $total_results > 0 ? 'true' : 'false'; ?>)) {
                const saveBtn = document.createElement('button');
                saveBtn.className = 'mail-btn mail-btn-secondary';
                saveBtn.style.cssText = 'margin-left: auto;';
                saveBtn.innerHTML = '<i class="fas fa-star"></i> Save Search';
                saveBtn.onclick = saveSearch;

                const statsDiv = document.querySelector('.mail-search-stats');
                statsDiv.appendChild(saveBtn);
            }
        });
    </script>
</body>

</html>