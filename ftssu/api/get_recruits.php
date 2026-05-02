<?php

/**
 * get_recruits.php
 * Returns recruits submitted for a member's command
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$member_id    = intval($_GET['member_id'] ?? 0);
$command_name = trim($_GET['command_name'] ?? '');

if (!$member_id || !$command_name) {
    echo json_encode(['success' => false, 'message' => 'Missing member_id or command_name']);
    exit;
}

// Summary count
$summaryStmt = $conn->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN DATE(registration_date) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM evangelism_recruits 
    WHERE command_name = ?
");
$summaryStmt->bind_param("s", $command_name);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

// Recent 20 recruits for this command
$stmt = $conn->prepare("
    SELECT id, full_name, phone_number, alternative_phone,
           email, address, notes, registration_date,
           submitted_by_name, status, created_at
    FROM evangelism_recruits
    WHERE command_name = ?
    ORDER BY registration_date DESC, created_at DESC
    LIMIT 20
");
$stmt->bind_param("s", $command_name);
$stmt->execute();
$result = $stmt->get_result();

$recruits = [];
while ($row = $result->fetch_assoc()) {
    $recruits[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode([
    'success'  => true,
    'summary'  => $summary,
    'recruits' => $recruits
]);
