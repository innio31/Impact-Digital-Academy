<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Required fields
$required = ['full_name', 'phone_number', 'member_id', 'command_name', 'registration_date'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$full_name         = trim($data['full_name']);
$phone_number      = trim($data['phone_number']);
$alternative_phone = trim($data['alternative_phone'] ?? '');
$email             = trim($data['email'] ?? '');
$address           = trim($data['address'] ?? '');
$notes             = trim($data['notes'] ?? '');
$member_id         = intval($data['member_id']);
$command_name      = trim($data['command_name']);
$registration_date = $data['registration_date'];

// Validate registration date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $registration_date)) {
    $registration_date = date('Y-m-d');
}

// Get submitting member's name
$mStmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
$mStmt->bind_param("i", $member_id);
$mStmt->execute();
$mResult = $mStmt->get_result()->fetch_assoc();
$mStmt->close();

if (!$mResult) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit;
}
$submitted_by_name = $mResult['first_name'] . ' ' . $mResult['last_name'];

// Get command_id from command_name
$cStmt = $conn->prepare("SELECT id FROM commands WHERE command_name = ? AND status = 'active'");
$cStmt->bind_param("s", $command_name);
$cStmt->execute();
$cResult = $cStmt->get_result()->fetch_assoc();
$cStmt->close();

if (!$cResult) {
    echo json_encode(['success' => false, 'message' => "Command not found: $command_name"]);
    exit;
}
$command_id = $cResult['id'];

// Check for duplicate phone number
$dupStmt = $conn->prepare("SELECT id, full_name, created_at FROM recruits WHERE phone_number = ?");
$dupStmt->bind_param("s", $phone_number);
$dupStmt->execute();
$duplicate = $dupStmt->get_result()->fetch_assoc();
$dupStmt->close();

if ($duplicate) {
    echo json_encode([
        'success'   => false,
        'duplicate' => true,
        'message'   => "This phone number is already registered for: " . $duplicate['full_name'],
        'existing'  => [
            'name' => $duplicate['full_name'],
            'date' => date('F j, Y', strtotime($duplicate['created_at']))
        ]
    ]);
    exit;
}

// Insert recruit
$stmt = $conn->prepare("
    INSERT INTO recruits 
        (full_name, phone_number, alternative_phone, email, address, notes,
         command_id, command_name, submitted_by_member_id, submitted_by_name, registration_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "ssssssissss",
    $full_name,
    $phone_number,
    $alternative_phone,
    $email,
    $address,
    $notes,
    $command_id,
    $command_name,
    $member_id,
    $submitted_by_name,
    $registration_date
);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    echo json_encode([
        'success' => true,
        'message' => 'Recruit submitted successfully',
        'id'      => $new_id
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
