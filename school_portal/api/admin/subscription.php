<?php
require_once '../includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE school_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([SCHOOL_ID]);
$sub = $stmt->fetch();

if (!$sub) {
    sendResponse(['success' => true, 'status' => 'inactive', 'amount' => 10000]);
}

sendResponse(['success' => true, 'status' => $sub['status'], 'end_date' => $sub['end_date'], 'amount' => $sub['amount']]);
?>