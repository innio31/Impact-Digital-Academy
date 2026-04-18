<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'your_db_username';
$password = 'your_db_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? null;

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Member ID is required']);
        exit;
    }

    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        exit;
    }

    $upload_dir = 'uploads/profile_pictures/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $file_name = 'profile_' . $member_id . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;

    // Delete old profile picture if exists
    $stmt = $pdo->prepare("SELECT profile_picture FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $old_picture = $stmt->fetchColumn();

    if ($old_picture && file_exists($old_picture)) {
        unlink($old_picture);
    }

    // Upload new file
    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
        // Update database
        $stmt = $pdo->prepare("UPDATE members SET profile_picture = ? WHERE id = ?");
        if ($stmt->execute([$file_path, $member_id])) {
            echo json_encode([
                'success' => true,
                'message' => 'Profile picture uploaded successfully',
                'profile_picture' => $file_path,
                'profile_picture_url' => 'https://impactdigitalacademy.com.ng/ftssu/' . $file_path
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update database']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
