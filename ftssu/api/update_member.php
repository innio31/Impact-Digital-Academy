<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$member_id = (int)($_POST['member_id'] ?? 0);
$phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
$email = $conn->real_escape_string($_POST['email'] ?? '');
$date_of_birth = $conn->real_escape_string($_POST['date_of_birth'] ?? '');

if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

// Handle profile picture upload
$profile_picture_path = null;
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
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

$sql = "UPDATE members SET phone_number = '$phone_number', email = '$email'";
if ($date_of_birth) {
    $sql .= ", date_of_birth = '$date_of_birth'";
}
if ($profile_picture_path) {
    $sql .= ", profile_picture = '$profile_picture_path'";
}
$sql .= " WHERE id = $member_id";

if ($conn->query($sql)) {
    // Get updated member
    $result = $conn->query("SELECT * FROM members WHERE id = $member_id");
    $member = $result->fetch_assoc();
    echo json_encode(['success' => true, 'member' => $member]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
