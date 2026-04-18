<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

// Create upload directory if not exists
$upload_dir = 'uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Function to compress and save image
function saveImage($source, $destination, $quality = 70)
{
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepng($image, $destination, 8);
    } else {
        return false;
    }
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

// Handle profile picture upload
$profile_picture_url = null;
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $temp_file = $_FILES['profile_picture']['tmp_name'];
    $filename = 'member_' . $member_id . '_' . time() . '.jpg';
    $filepath = $upload_dir . $filename;
    $full_url = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $filepath;

    if (saveImage($temp_file, $filepath, 70)) {
        $profile_picture_url = $full_url;
    } else {
        // If compression fails, just move the original
        move_uploaded_file($temp_file, $filepath);
        $profile_picture_url = $full_url;
    }
}

// Build update query
$updates = [];
if ($phone_number) $updates[] = "phone_number = '$phone_number'";
if ($email) $updates[] = "email = '$email'";
if ($date_of_birth) $updates[] = "date_of_birth = '$date_of_birth'";
if ($profile_picture_url) $updates[] = "profile_picture = '$profile_picture_url'";

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
