<?php
require_once 'includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

// Check subscription
$stmt = $pdo->prepare("SELECT status, end_date FROM subscriptions WHERE school_id = ? AND status = 'active' AND end_date > CURDATE()");
$stmt->execute([SCHOOL_ID]);
$subscription = $stmt->fetch();

if (!$subscription) {
    sendResponse(['success' => false, 'message' => 'Subscription expired. Please renew.'], 403);
}

// Get last sync time
$stmt = $pdo->prepare("SELECT last_sync FROM sync_metadata WHERE school_id = ? AND table_name = 'all_tables'");
$stmt->execute([SCHOOL_ID]);
$lastSync = $stmt->fetchColumn();

if (!$lastSync) {
    $lastSync = '1970-01-01 00:00:00';
}

// Get records to sync (this would be populated by the offline system)
// For now, return success
sendResponse(['success' => true, 'message' => 'Sync completed', 'uploaded' => 0, 'downloaded' => 0]);
?>