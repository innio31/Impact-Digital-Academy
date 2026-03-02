<?php
// comments.php - Fixed version with NULL parent_id
require_once 'config.php';

header('Content-Type: application/json');

// Enable error logging
error_log("Comments.php accessed - " . date('Y-m-d H:i:s'));

// Handle GET request - fetch comments
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_comments') {
    $article_url = $_GET['article_url'] ?? '';

    if (empty($article_url)) {
        sendJSON(false, 'Article URL required');
    }

    try {
        $database = new Database();
        $db = $database->getConnection();

        if (!$db) {
            error_log("Database connection failed in get_comments");
            sendJSON(false, 'Database connection failed');
        }

        // Get just the path part of the URL
        $article_path = parse_url($article_url, PHP_URL_PATH);
        if (empty($article_path)) {
            $article_path = $article_url;
        }

        error_log("Fetching comments for: " . $article_path);

        // Fetch top-level comments (parent_id IS NULL)
        $stmt = $db->prepare("
            SELECT * FROM comments 
            WHERE article_url = ? AND parent_id IS NULL AND status = 'approved'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$article_path]);
        $comments = $stmt->fetchAll();

        error_log("Found " . count($comments) . " top-level comments");

        $html = '';

        foreach ($comments as $comment) {
            $html .= renderComment($comment, $db);

            // Fetch replies for this comment (parent_id = comment_id)
            $replyStmt = $db->prepare("
                SELECT * FROM comments 
                WHERE parent_id = ? AND status = 'approved'
                ORDER BY created_at ASC
            ");
            $replyStmt->execute([$comment['id']]);
            $replies = $replyStmt->fetchAll();

            if (!empty($replies)) {
                $html .= '<div class="replies">';
                foreach ($replies as $reply) {
                    $html .= renderComment($reply, $db, true);
                }
                $html .= '</div>';
            }
        }

        if (empty($html)) {
            $html = '<p style="text-align:center; color:#8b7a66; padding:2rem;">Be the first to share your thoughts!</p>';
        }

        sendJSON(true, 'Comments loaded', ['html' => $html]);
    } catch (Exception $e) {
        error_log("Error loading comments: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendJSON(false, 'Failed to load comments: ' . $e->getMessage());
    }
}

// Handle POST request - add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    error_log("Received comment data: " . print_r($input, true));

    $article_url = filter_var($input['article_url'] ?? '', FILTER_SANITIZE_URL);
    $author_name = trim(strip_tags($input['author_name'] ?? ''));
    $author_email = filter_var($input['author_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $comment_text = trim(strip_tags($input['comment_text'] ?? ''));
    $parent_id = isset($input['parent_id']) ? intval($input['parent_id']) : null;

    // Get just the path part of the URL
    $parsed_url = parse_url($article_url);
    $article_path = $parsed_url['path'] ?? $article_url;

    error_log("Processed - Article path: $article_path, Author: $author_name, Parent: " . ($parent_id ?? 'NULL'));

    // Validation
    if (empty($article_path)) {
        sendJSON(false, 'Invalid article reference');
    }

    if (empty($author_name)) {
        sendJSON(false, 'Please enter your name');
    }

    if (empty($comment_text)) {
        sendJSON(false, 'Please enter your comment');
    }

    if (strlen($comment_text) < 5) {
        sendJSON(false, 'Comment is too short');
    }

    if (strlen($comment_text) > 2000) {
        sendJSON(false, 'Comment is too long (max 2000 characters)');
    }

    try {
        $database = new Database();
        $db = $database->getConnection();

        if (!$db) {
            error_log("Database connection failed in post_comment");
            sendJSON(false, 'Database connection failed');
        }

        // If replying, verify parent exists
        if ($parent_id !== null && $parent_id > 0) {
            $checkStmt = $db->prepare("SELECT id FROM comments WHERE id = ?");
            $checkStmt->execute([$parent_id]);
            if (!$checkStmt->fetch()) {
                error_log("Parent comment $parent_id not found");
                sendJSON(false, 'Parent comment not found');
            }
        } else {
            // For top-level comments, set parent_id to NULL
            $parent_id = null;
        }

        $ip = getClientIP();

        error_log("Inserting comment with: article=$article_path, author=$author_name, parent=" . ($parent_id ?? 'NULL'));

        // Prepare the SQL statement based on whether parent_id is NULL or not
        if ($parent_id === null) {
            $stmt = $db->prepare("
                INSERT INTO comments (parent_id, article_url, author_name, author_email, comment_text, ip_address) 
                VALUES (NULL, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $article_path,
                $author_name,
                $author_email ?: null,
                $comment_text,
                $ip
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO comments (parent_id, article_url, author_name, author_email, comment_text, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $parent_id,
                $article_path,
                $author_name,
                $author_email ?: null,
                $comment_text,
                $ip
            ]);
        }

        if ($result) {
            $comment_id = $db->lastInsertId();
            error_log("Comment inserted successfully with ID: $comment_id");
            sendJSON(true, 'Comment posted successfully!');
        } else {
            error_log("Failed to insert comment: " . print_r($stmt->errorInfo(), true));
            sendJSON(false, 'Failed to post comment');
        }
    } catch (PDOException $e) {
        error_log("PDO Exception in comment insert: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        sendJSON(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log("General exception in comment insert: " . $e->getMessage());
        sendJSON(false, 'Failed to post comment: ' . $e->getMessage());
    }
}

// Helper function to render comment HTML
function renderComment($comment, $db, $isReply = false)
{
    $date = date('F j, Y', strtotime($comment['created_at']));
    $gravatar = $comment['author_email'] ? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($comment['author_email']))) . '?d=mp&s=40' : '';

    $html = '<div class="single-comment" data-id="' . $comment['id'] . '">';
    $html .= '<div class="comment-meta">';

    if ($gravatar) {
        $html .= '<img src="' . $gravatar . '" alt="avatar" loading="lazy" style="width:30px; height:30px; border-radius:50%; margin-right:10px;">';
    } else {
        $html .= '<div style="width:30px; height:30px; background:#c1a987; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:white; font-weight:bold; margin-right:10px;">' . strtoupper(substr($comment['author_name'], 0, 1)) . '</div>';
    }

    $html .= '<strong>' . htmlspecialchars($comment['author_name']) . '</strong>';
    $html .= '<span>' . $date . '</span>';
    $html .= '</div>';

    $html .= '<p>' . nl2br(htmlspecialchars($comment['comment_text'])) . '</p>';

    if (!$isReply) {
        $html .= '<span class="reply-link" onclick="showReplyForm(' . $comment['id'] . ')"><i class="fas fa-reply"></i> Reply</span>';
        $html .= '<div id="reply-form-' . $comment['id'] . '" style="display:none;" class="reply-form">';
        $html .= '<input type="text" id="replyInput' . $comment['id'] . '" placeholder="Write your reply..." class="reply-input">';
        $html .= '<input type="text" id="replyName' . $comment['id'] . '" placeholder="Your name" class="reply-name" style="width:120px;" value="Educator">';
        $html .= '<button onclick="postReply(' . $comment['id'] . ')">Reply</button>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

// Handle unsupported methods
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJSON(false, 'Method not allowed');
}
