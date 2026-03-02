<?php
// comments.php - Handle comments and replies
header('Content-Type: application/json');
require_once 'config.php';

class CommentHandler
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function addComment($data)
    {
        try {
            $required = ['article_url', 'author_name', 'comment_text'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
                }
            }

            // Sanitize inputs
            $article_url = filter_var($data['article_url'], FILTER_SANITIZE_URL);
            $author_name = htmlspecialchars(strip_tags($data['author_name']));
            $author_email = filter_var($data['author_email'] ?? '', FILTER_SANITIZE_EMAIL);
            $comment_text = htmlspecialchars(strip_tags($data['comment_text']));
            $parent_id = intval($data['parent_id'] ?? 0);
            $ip = getClientIP();

            // Validate parent comment exists if provided
            if ($parent_id > 0) {
                $checkParent = $this->db->prepare("SELECT id FROM comments WHERE id = ?");
                $checkParent->execute([$parent_id]);
                if (!$checkParent->fetch()) {
                    return ['success' => false, 'message' => 'Parent comment not found'];
                }
            }

            // Insert comment
            $stmt = $this->db->prepare("
                INSERT INTO comments (parent_id, article_url, author_name, author_email, comment_text, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$parent_id, $article_url, $author_name, $author_email, $comment_text, $ip]);
            $commentId = $this->db->lastInsertId();

            // Send notification to admin
            $mailer = new Mailer();
            $mailer->sendNotificationEmail([
                'author' => $author_name,
                'article' => $article_url,
                'text' => $comment_text
            ]);

            // Return the new comment HTML
            $newComment = $this->getCommentHTML($commentId);

            return [
                'success' => true,
                'message' => 'Comment posted successfully',
                'comment_id' => $commentId,
                'html' => $newComment
            ];
        } catch (Exception $e) {
            error_log("Comment error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to post comment'];
        }
    }

    public function getComments($article_url)
    {
        try {
            // Get all top-level comments (parent_id = 0)
            $stmt = $this->db->prepare("
                SELECT * FROM comments 
                WHERE article_url = ? AND parent_id = 0 AND status = 'approved'
                ORDER BY created_at DESC
            ");
            $stmt->execute([$article_url]);
            $comments = $stmt->fetchAll();

            $output = '';
            foreach ($comments as $comment) {
                $output .= $this->renderComment($comment);
                // Get replies for this comment
                $output .= $this->getReplies($comment['id']);
            }

            return $output;
        } catch (Exception $e) {
            error_log("Fetch comments error: " . $e->getMessage());
            return '';
        }
    }

    private function getReplies($parent_id)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM comments 
                WHERE parent_id = ? AND status = 'approved'
                ORDER BY created_at ASC
            ");
            $stmt->execute([$parent_id]);
            $replies = $stmt->fetchAll();

            if (empty($replies)) return '';

            $html = '<div class="replies">';
            foreach ($replies as $reply) {
                $html .= $this->renderComment($reply, true);
            }
            $html .= '</div>';

            return $html;
        } catch (Exception $e) {
            return '';
        }
    }

    private function renderComment($comment, $isReply = false)
    {
        $gravatar = $comment['author_email'] ? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($comment['author_email']))) . '?d=mp&s=40' : '';
        $date = date('F j, Y', strtotime($comment['created_at']));

        $html = '<div class="single-comment" data-id="' . $comment['id'] . '">';
        $html .= '<div class="comment-meta">';
        if ($gravatar) {
            $html .= '<img src="' . $gravatar . '" alt="avatar" style="width:30px; height:30px; border-radius:50%; margin-right:10px;">';
        }
        $html .= '<strong>' . htmlspecialchars($comment['author_name']) . '</strong>';
        $html .= '<span>' . $date . '</span>';
        $html .= '</div>';
        $html .= '<p>' . nl2br(htmlspecialchars($comment['comment_text'])) . '</p>';

        if (!$isReply) {
            $html .= '<span class="reply-link" onclick="showReplyForm(' . $comment['id'] . ')"><i class="fas fa-reply"></i> Reply</span>';
            $html .= '<div id="reply-form-' . $comment['id'] . '" style="display:none;" class="reply-form">';
            $html .= '<input type="text" id="replyInput' . $comment['id'] . '" placeholder="Write a reply..." class="reply-input">';
            $html .= '<input type="text" id="replyName' . $comment['id'] . '" placeholder="Your name" class="reply-name" style="width:120px;">';
            $html .= '<button onclick="postReply(' . $comment['id'] . ')">Reply</button>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function getCommentHTML($commentId)
    {
        $stmt = $this->db->prepare("SELECT * FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();

        if ($comment) {
            return $this->renderComment($comment, $comment['parent_id'] > 0);
        }
        return '';
    }
}

// Handle API requests
$handler = new CommentHandler();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }

    $result = $handler->addComment($data);
    echo json_encode($result);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_comments') {
    $article_url = $_GET['article_url'] ?? '';
    if ($article_url) {
        $comments = $handler->getComments($article_url);
        echo json_encode(['success' => true, 'html' => $comments]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Article URL required']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
