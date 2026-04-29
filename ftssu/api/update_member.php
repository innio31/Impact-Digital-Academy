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

// Database configuration
$host = 'localhost';
$user = 'impactdi_result-checker';
$password = 'uenrqFrgYbcY5YmSLTH6';
$database = 'impactdi_result-checker';


try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Handle file upload for profile picture
if (isset($_FILES['profile_picture'])) {
    $member_id = $_POST['member_id'];
    $upload_dir = 'uploads/profiles/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $filename = 'member_' . $member_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
        $image_url = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $filepath;

        $stmt = $pdo->prepare("UPDATE members SET profile_picture = ? WHERE id = ?");
        $stmt->execute([$image_url, $member_id]);

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
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data: ' . $input]);
    exit();
}

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit();
}

try {
    $updates = [];
    $params = [];

    // Check each possible field - match your database column names
    if (isset($data['first_name']) && $data['first_name'] !== '') {
        $updates[] = "first_name = ?";
        $params[] = $data['first_name'];
    }
    if (isset($data['last_name']) && $data['last_name'] !== '') {
        $updates[] = "last_name = ?";
        $params[] = $data['last_name'];
    }
    if (isset($data['designation']) && $data['designation'] !== '') {
        $updates[] = "designation = ?";
        $params[] = $data['designation'];
    }
    if (isset($data['command']) && $data['command'] !== '') {
        $updates[] = "command = ?";
        $params[] = $data['command'];
    }
    if (isset($data['role']) && $data['role'] !== '') {
        $updates[] = "role = ?";
        $params[] = $data['role'];
    }
    if (isset($data['gender']) && $data['gender'] !== '') {
        $updates[] = "gender = ?";
        $params[] = $data['gender'];
    }
    if (isset($data['phone_number']) && $data['phone_number'] !== '') {
        $updates[] = "phone_number = ?";
        $params[] = $data['phone_number'];
    }
    if (isset($data['email']) && $data['email'] !== '') {
        $updates[] = "email = ?";
        $params[] = $data['email'];
    }
    if (isset($data['date_of_birth']) && $data['date_of_birth'] !== '') {
        $updates[] = "date_of_birth = ?";
        $params[] = $data['date_of_birth'];
    }
    if (isset($data['date_joined']) && $data['date_joined'] !== '') {
        $updates[] = "date_joined = ?";
        $params[] = $data['date_joined'];
    }
    if (isset($data['password']) && $data['password'] !== '') {
        // Use MD5 as in your database schema
        $hashed_password = md5($data['password']);
        $updates[] = "password = ?";
        $params[] = $hashed_password;
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update. Available data: ' . json_encode($data)]);
        exit();
    }

    $params[] = $data['id'];
    $sql = "UPDATE members SET " . implode(', ', $updates) . " WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated member data
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$data['id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'member' => $member]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'General error: ' . $e->getMessage()]);
}
