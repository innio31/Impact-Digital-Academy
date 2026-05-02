<?php

/**
 * check_recruit_phone.php
 * Fast live duplicate phone check for evangelism form
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$phone = trim($_GET['phone'] ?? '');

if (strlen($phone) < 10) {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT full_name, command_name, registration_date 
    FROM evangelism_recruits 
    WHERE phone_number = ? 
    LIMIT 1
");
$stmt->bind_param("s", $phone);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if ($row) {
    echo json_encode([
        'exists'  => true,
        'message' => "Already registered: {$row['full_name']} ({$row['command_name']}) on " . date('d M Y', strtotime($row['registration_date']))
    ]);
} else {
    echo json_encode(['exists' => false]);
}
