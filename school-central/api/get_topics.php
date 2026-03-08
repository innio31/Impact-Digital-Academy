<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Log errors to a file (optional)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

try {
    require_once 'config.php';

    if (!isset($_GET['subject_id'])) {
        throw new Exception('subject_id parameter is missing');
    }

    $subject_id = intval($_GET['subject_id']);

    if ($subject_id <= 0) {
        throw new Exception('Invalid subject_id: must be a positive integer');
    }

    // Debug: Check if connection works
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    // First, check if the subject exists
    $check_subject = $pdo->prepare("SELECT id FROM master_subjects WHERE id = ?");
    $check_subject->execute([$subject_id]);
    if ($check_subject->rowCount() == 0) {
        echo json_encode(['topics' => [], 'message' => 'Subject not found']);
        exit;
    }

    // Get topics
    $stmt = $pdo->prepare("SELECT id, topic_name FROM master_topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return success response
    echo json_encode([
        'success' => true,
        'topics' => $topics,
        'count' => count($topics)
    ]);
} catch (Exception $e) {
    // Log error
    error_log("Error in get_topics.php: " . $e->getMessage());

    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'topics' => []
    ]);
}
