<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'impactdi_ftssu';
$password = 'ftssu@2026';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : null;

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Member ID is required']);
        exit;
    }

    $update_fields = [];
    $params = [];

    // Update phone number if provided
    if (isset($_POST['phone_number'])) {
        $update_fields[] = "phone_number = ?";
        $params[] = $_POST['phone_number'];
    }

    // Update email if provided
    if (isset($_POST['email'])) {
        $update_fields[] = "email = ?";
        $params[] = $_POST['email'];
    }

    // Update date of birth if provided
    if (isset($_POST['date_of_birth'])) {
        $update_fields[] = "date_of_birth = ?";
        $params[] = $_POST['date_of_birth'];
    }

    // Handle profile picture upload
    $profile_picture_path = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
        $file_type = $_FILES['profile_picture']['type'];

        if (in_array($file_type, $allowed_types)) {
            $upload_dir = '../uploads/profile_pictures/';

            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_name = 'profile_' . $member_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            $db_path = 'uploads/profile_pictures/' . $file_name;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
                // Delete old profile picture
                $stmt = $pdo->prepare("SELECT profile_picture FROM members WHERE id = ?");
                $stmt->execute([$member_id]);
                $old_picture = $stmt->fetchColumn();
                if ($old_picture && file_exists('../' . $old_picture)) {
                    unlink('../' . $old_picture);
                }

                $update_fields[] = "profile_picture = ?";
                $params[] = $db_path;
                $profile_picture_path = $db_path;
            }
        }
    }

    if (empty($update_fields)) {
        // Just return current member data if nothing to update
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'member' => $member]);
        exit;
    }

    $params[] = $member_id;
    $sql = "UPDATE members SET " . implode(", ", $update_fields) . " WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    if ($stmt->execute($params)) {
        // Get updated member data
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'member' => $member,
            'profile_picture' => $profile_picture_path
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
