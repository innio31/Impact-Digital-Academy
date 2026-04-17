<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$member_id = $_POST['member_id'] ?? '';
$phone_number = $_POST['phone_number'] ?? '';
$email = $_POST['email'] ?? '';
$date_of_birth = $_POST['date_of_birth'] ?? '';
$date_joined = $_POST['date_joined'] ?? '';

if (empty($member_id) || empty($phone_number) || empty($date_of_birth) || empty($date_joined)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
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

try {
    $sql = "UPDATE members SET 
            phone_number = ?, 
            email = ?, 
            date_of_birth = ?, 
            date_joined = ?";
    $params = [$phone_number, $email, $date_of_birth, $date_joined];

    if ($profile_picture_path) {
        $sql .= ", profile_picture = ?";
        $params[] = $profile_picture_path;
    }

    $sql .= " WHERE id = ?";
    $params[] = $member_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Get updated member data
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'member' => $member
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
