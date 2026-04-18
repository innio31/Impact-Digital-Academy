<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead

// Database configuration
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'your_db_username';
$password = 'your_db_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit;
}

// Get member_id
$member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : null;

if (!$member_id) {
    echo json_encode(['success' => false, 'message' => 'Member ID is required']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    $error_message = 'No file uploaded or upload error';
    if (isset($_FILES['profile_picture']['error'])) {
        switch ($_FILES['profile_picture']['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $error_message = 'File too large (server limit)';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = 'File too large (form limit)';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = 'File was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error_message = 'No file was uploaded';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error_message = 'Missing temporary folder';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error_message = 'Failed to write file to disk';
                break;
            case UPLOAD_ERR_EXTENSION:
                $error_message = 'File upload stopped by extension';
                break;
        }
    }
    echo json_encode(['success' => false, 'message' => $error_message]);
    exit;
}

// Validate file type
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$file_type = $_FILES['profile_picture']['type'];
if (!in_array($file_type, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, and GIF images are allowed']);
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = '../uploads/profile_pictures/';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Generate unique filename
$file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
$file_name = 'profile_' . $member_id . '_' . time() . '.' . $file_extension;
$file_path = $upload_dir . $file_name;
$db_path = 'uploads/profile_pictures/' . $file_name;

// Get old profile picture to delete
$stmt = $pdo->prepare("SELECT profile_picture FROM members WHERE id = ?");
$stmt->execute([$member_id]);
$old_picture = $stmt->fetchColumn();

// Upload new file
if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
    // Delete old picture if exists
    if ($old_picture && file_exists('../' . $old_picture)) {
        unlink('../' . $old_picture);
    }

    // Update database
    $stmt = $pdo->prepare("UPDATE members SET profile_picture = ? WHERE id = ?");
    if ($stmt->execute([$db_path, $member_id])) {
        // Get updated member data
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Profile picture updated successfully',
            'profile_picture' => $db_path,
            'profile_picture_url' => 'https://impactdigitalacademy.com.ng/ftssu/' . $db_path,
            'member' => $member
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
}
