<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

// Create upload directory if not exists
$upload_dir = 'uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Function to compress image
function compressImage($source, $destination, $quality = 60)
{
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } else {
        return false;
    }

    imagejpeg($image, $destination, $quality);
    imagedestroy($image);
    return true;
}

$member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
$phone_number = isset($_POST['phone_number']) ? $conn->real_escape_string($_POST['phone_number']) : '';
$email = isset($_POST['email']) ? $conn->real_escape_string($_POST['email']) : '';
$date_of_birth = isset($_POST['date_of_birth']) ? $conn->real_escape_string($_POST['date_of_birth']) : '';

if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

// Handle profile picture upload with compression
$profile_picture_path = null;
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $temp_file = $_FILES['profile_picture']['tmp_name'];
    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $filename = 'member_' . $member_id . '_' . time() . '.jpg';
    $filepath = $upload_dir . $filename;
    $compressed_path = $upload_dir . 'compressed_' . $filename;

    // Compress image
    if (compressImage($temp_file, $compressed_path, 60)) {
        $profile_picture_path = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $compressed_path;
    } else {
        // If compression fails, just move the original
        move_uploaded_file($temp_file, $filepath);
        $profile_picture_path = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $filepath;
    }
}

// Build update query
$updates = [];
if ($phone_number) $updates[] = "phone_number = '$phone_number'";
if ($email) $updates[] = "email = '$email'";
if ($date_of_birth) $updates[] = "date_of_birth = '$date_of_birth'";
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
