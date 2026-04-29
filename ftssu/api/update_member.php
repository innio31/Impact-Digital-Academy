<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your database configuration
require_once 'db_connect.php';

// Use the global connection
global $conn;

// Check if connection exists
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Handle file upload for profile picture
if (isset($_FILES['profile_picture'])) {
    $member_id = $_POST['member_id'];
    $upload_dir = 'uploads/profiles/';

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $filename = 'member_' . $member_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
        $image_url = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $filepath;

        $stmt = $conn->prepare("UPDATE members SET profile_picture = ? WHERE id = ?");
        $stmt->bind_param("si", $image_url, $member_id);
        $stmt->execute();

        echo json_encode(['success' => true, 'member' => ['profile_picture' => $image_url]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
    }
    exit();
}

// Handle JSON data for profile updates
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit();
}

try {
    $updates = [];
    $params = [];
    $types = "";

    // Check each possible field
    if (isset($data['first_name']) && $data['first_name'] !== '') {
        $updates[] = "first_name = ?";
        $params[] = $data['first_name'];
        $types .= "s";
    }
    if (isset($data['last_name']) && $data['last_name'] !== '') {
        $updates[] = "last_name = ?";
        $params[] = $data['last_name'];
        $types .= "s";
    }
    if (isset($data['designation']) && $data['designation'] !== '') {
        $updates[] = "designation = ?";
        $params[] = $data['designation'];
        $types .= "s";
    }
    if (isset($data['command']) && $data['command'] !== '') {
        $updates[] = "command = ?";
        $params[] = $data['command'];
        $types .= "s";
    }
    if (isset($data['role']) && $data['role'] !== '') {
        $updates[] = "role = ?";
        $params[] = $data['role'];
        $types .= "s";
    }
    if (isset($data['gender']) && $data['gender'] !== '') {
        $updates[] = "gender = ?";
        $params[] = $data['gender'];
        $types .= "s";
    }
    if (isset($data['phone_number']) && $data['phone_number'] !== '') {
        $updates[] = "phone_number = ?";
        $params[] = $data['phone_number'];
        $types .= "s";
    }
    if (isset($data['email']) && $data['email'] !== '') {
        $updates[] = "email = ?";
        $params[] = $data['email'];
        $types .= "s";
    }
    if (isset($data['date_of_birth']) && $data['date_of_birth'] !== '') {
        $updates[] = "date_of_birth = ?";
        $params[] = $data['date_of_birth'];
        $types .= "s";
    }
    if (isset($data['date_joined']) && $data['date_joined'] !== '') {
        $updates[] = "date_joined = ?";
        $params[] = $data['date_joined'];
        $types .= "s";
    }
    if (isset($data['password']) && $data['password'] !== '') {
        $hashed_password = md5($data['password']);
        $updates[] = "password = ?";
        $params[] = $hashed_password;
        $types .= "s";
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        exit();
    }

    // Add the ID parameter
    $params[] = $data['id'];
    $types .= "i";

    $sql = "UPDATE members SET " . implode(', ', $updates) . " WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows >= 0) {
        echo json_encode(['success' => true, 'message' => 'Member updated successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No changes made']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
