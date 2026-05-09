<?php
require_once '/../includes/config.php';
require_once '/../includes/cors.php';

$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$input = getJsonInput();

$admission_number = $input['admission_number'] ?? '';
$full_name = $input['full_name'] ?? '';
$class = $input['class'] ?? '';

if (empty($admission_number) || empty($full_name) || empty($class)) {
    sendResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

$surname = explode(' ', $full_name)[0];
$password = password_hash(strtolower($surname), PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO students (school_id, admission_number, password, full_name, class, status) VALUES (?, ?, ?, ?, ?, 'active')");
$stmt->execute([SCHOOL_ID, $admission_number, $password, $full_name, $class]);

sendResponse(['success' => true, 'message' => 'Student added successfully']);
?>