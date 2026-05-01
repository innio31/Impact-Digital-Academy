<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php'; // your existing DB config

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['member_id']) || !isset($data['subscription'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$member_id = intval($data['member_id']);
$endpoint  = $data['subscription']['endpoint'];
$p256dh    = $data['subscription']['keys']['p256dh'];
$auth      = $data['subscription']['keys']['auth'];

// Remove old subscription for this member (re-subscribe fresh)
$stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();

// Save new subscription
$stmt = $conn->prepare("INSERT INTO push_subscriptions (member_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $member_id, $endpoint, $p256dh, $auth);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Subscription saved']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save subscription']);
}

$stmt->close();
$conn->close();
