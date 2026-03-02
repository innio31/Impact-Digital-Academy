<?php
// comments.php - Fixed version
require_once 'config.php';

header('Content-Type: application/json');

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
            sendJSON(false, 'Database connection failed');
        }

        // Fetch top-level comments
        $stmt = $db->prepare("
            SELECT * FROM comments 
            WHERE article_url = ? AND parent_id = 0 AND status = 'approved'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$article_url]);
        $comments = $stmt->fetchAll();

        $html = '';

        foreach ($comments as $comment) {
            $html .= renderComment($comment, $db);

            // Fetch replies for this comment
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
        sendJSON(false, 'Failed to load comments');
    }
}

// Handle POST request - add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    $article_url = filter_var($input['article_url'] ?? '', FILTER_SANITIZE_URL);
    $author_name = trim(strip_tags($input['author_name'] ?? ''));
    $author_email = filter_var($input['author_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $comment_text = trim(strip_tags($input['comment_text'] ?? ''));
    $parent_id = intval($input['parent_id'] ?? 0);

    // Validation
    if (empty($article_url)) {
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
            sendJSON(false, 'Database connection failed');
        }

        // If replying, verify parent exists
        if ($parent_id > 0) {
            $checkStmt = $db->prepare("SELECT id FROM comments WHERE id = ?");
            $checkStmt->execute([$parent_id]);
            if (!$checkStmt->fetch()) {
                sendJSON(false, 'Parent comment not found');
            }
        }

        $ip = getClientIP();

        $stmt = $db->prepare("
            INSERT INTO comments (parent_id, article_url, author_name, author_email, comment_text, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if ($stmt->execute([$parent_id, $article_url, $author_name, $author_email ?: null, $comment_text, $ip])) {
            sendJSON(true, 'Comment posted successfully!');
        } else {
            sendJSON(false, 'Failed to post comment');
        }
    } catch (PDOException $e) {
        error_log("Comment insert error: " . $e->getMessage());
        sendJSON(false, 'Database error occurred');
    } catch (Exception $e) {
        error_log("Comment error: " . $e->getMessage());
        sendJSON(false, 'Failed to post comment');
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
        $html .= '<img src="' . $gravatar . '" alt="avatar" loading="lazy">';
    } else {
        $html .= '<div style="width:30px; height:30px; background:#c1a987; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:white; font-weight:bold;">' . strtoupper(substr($comment['author_name'], 0, 1)) . '</div>';
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
