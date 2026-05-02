<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: fetch notifications for a member
if ($method === 'GET') {
    $member_id = intval($_GET['member_id'] ?? 0);
    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Missing member_id']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, title, body, type, is_read, created_at
        FROM notifications
        WHERE member_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    $unread = 0;
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
        if (!$row['is_read']) $unread++;
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'unread_count'  => $unread
    ]);
}

// POST: mark notifications as read
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $member_id = intval($data['member_id'] ?? 0);

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Missing member_id']);
        exit;
    }

    // Mark all as read, or specific one
    if (isset($data['notification_id'])) {
        $nid = intval($data['notification_id']);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND member_id = ?");
        $stmt->bind_param("ii", $nid, $member_id);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
    }

    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true]);
}
