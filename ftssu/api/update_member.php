<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

// Check if this is a multipart form (for profile picture) or JSON
$isMultipart = isset($_FILES) && count($_FILES) > 0;

if ($isMultipart) {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $date_of_birth = $conn->real_escape_string($_POST['date_of_birth'] ?? '');
    $password = isset($_POST['password']) ? md5(strtolower($_POST['password'])) : null;
} else {
    $data = json_decode(file_get_contents('php://input'), true);
    $member_id = isset($data['id']) ? (int)$data['id'] : 0;
    $id_number = isset($data['id_number']) ? $conn->real_escape_string($data['id_number']) : '';
    $first_name = isset($data['first_name']) ? $conn->real_escape_string($data['first_name']) : '';
    $last_name = isset($data['last_name']) ? $conn->real_escape_string($data['last_name']) : '';
    $designation = isset($data['designation']) ? $conn->real_escape_string($data['designation']) : 'Brother';
    $command = isset($data['command']) ? $conn->real_escape_string($data['command']) : '';
    $role = isset($data['role']) ? $conn->real_escape_string($data['role']) : 'Member';
    $gender = isset($data['gender']) ? $conn->real_escape_string($data['gender']) : 'Male';
    $phone_number = isset($data['phone_number']) ? $conn->real_escape_string($data['phone_number']) : '';
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    $date_of_birth = isset($data['date_of_birth']) ? $conn->real_escape_string($data['date_of_birth']) : '';
    $date_joined = isset($data['date_joined']) ? $conn->real_escape_string($data['date_joined']) : '';
    $password = isset($data['password']) ? md5(strtolower($data['password'])) : null;
}

if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

// Handle profile picture upload
$profile_picture_path = null;
if ($isMultipart && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = 'uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $filename = 'member_' . $member_id . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
        $profile_picture_path = $filepath;
    }
}

// Build update query
$updates = [];
if (!$isMultipart) {
    if ($id_number) $updates[] = "id_number = '$id_number'";
    if ($first_name) $updates[] = "first_name = '$first_name'";
    if ($last_name) $updates[] = "last_name = '$last_name'";
    if ($designation) $updates[] = "designation = '$designation'";
    if ($command) $updates[] = "command = '$command'";
    if ($role) $updates[] = "role = '$role'";
    if ($gender) $updates[] = "gender = '$gender'";
    if ($date_joined) $updates[] = "date_joined = '$date_joined'";
}
if ($phone_number) $updates[] = "phone_number = '$phone_number'";
if ($email) $updates[] = "email = '$email'";
if ($date_of_birth) $updates[] = "date_of_birth = '$date_of_birth'";
if ($password) $updates[] = "password = '$password'";
if ($profile_picture_path) $updates[] = "profile_picture = '$profile_picture_path'";

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'No fields to update']);
    exit;
}

$sql = "UPDATE members SET " . implode(', ', $updates) . " WHERE id = $member_id";

if ($conn->query($sql)) {
    // Get updated member
    $result = $conn->query("SELECT * FROM members WHERE id = $member_id");
    $member = $result->fetch_assoc();
    unset($member['password']);
    echo json_encode(['success' => true, 'member' => $member]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
