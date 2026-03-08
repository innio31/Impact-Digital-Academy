<?php
header('Content-Type: application/json');
require_once 'config.php';

if (!isset($_GET['subject_id']) || !is_numeric($_GET['subject_id'])) {
    echo json_encode(['error' => 'Invalid subject ID']);
    exit;
}

$subject_id = intval($_GET['subject_id']);

try {
    $stmt = $pdo->prepare("SELECT id, topic_name FROM master_topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['topics' => $topics]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
