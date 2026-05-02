<?php

/**
 * submit_recruit.php
 * Handles evangelism recruit submission from FTSSU app
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['member_id', 'full_name', 'phone_number', 'command_name', 'submitted_by_name', 'registration_date'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$member_id    = intval($data['member_id']);
$full_name    = trim($data['full_name']);
$phone_number = trim($data['phone_number']);
$alt_phone    = trim($data['alternative_phone'] ?? '');
$email        = trim($data['email'] ?? '');
$address      = trim($data['address'] ?? '');
$notes        = trim($data['notes'] ?? '');
$command_name = trim($data['command_name']);
$submitted_by = trim($data['submitted_by_name']);
$reg_date     = $data['registration_date'];

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_date)) {
    $reg_date = date('Y-m-d');
}

// Check for duplicate phone number
$checkStmt = $conn->prepare("
    SELECT id, full_name, command_name, registration_date 
    FROM evangelism_recruits 
    WHERE phone_number = ?
");
$checkStmt->bind_param("s", $phone_number);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    echo json_encode([
        'success'   => false,
        'duplicate' => true,
        'message'   => "⚠️ Phone number already registered for: {$existing['full_name']} ({$existing['command_name']}) on " . date('d M Y', strtotime($existing['registration_date']))
    ]);
    exit;
}

// Insert recruit
$stmt = $conn->prepare("
    INSERT INTO evangelism_recruits 
    (full_name, phone_number, alternative_phone, email, address, notes, 
     command_name, submitted_by_member_id, submitted_by_name, registration_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "sssssssiss",
    $full_name,
    $phone_number,
    $alt_phone,
    $email,
    $address,
    $notes,
    $command_name,
    $member_id,
    $submitted_by,
    $reg_date
);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    echo json_encode([
        'success'    => true,
        'message'    => 'Recruit submitted successfully',
        'recruit_id' => $new_id
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
