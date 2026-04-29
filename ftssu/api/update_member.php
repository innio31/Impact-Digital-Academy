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

require_once 'config.php';

// Handle file upload for profile picture
if (isset($_FILES['profile_picture'])) {
    $member_id = $_POST['member_id'];
    $upload_dir = '../uploads/profiles/';

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $filename = 'member_' . $member_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
        $image_url = 'https://impactdigitalacademy.com.ng/ftssu/api/uploads/profiles/' . $filename;

        $stmt = $pdo->prepare("UPDATE members SET profile_picture = ? WHERE id = ?");
        $stmt->execute([$image_url, $member_id]);

        echo json_encode(['success' => true, 'member' => ['profile_picture' => $image_url]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
    }
    exit();
}

// Handle JSON data for profile updates
$data = json_decode(file_get_contents('php://input'), true);

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

    // Check each possible field
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
        $hashed_password = md5($data['password']);
        $updates[] = "password = ?";
        $params[] = $hashed_password;
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
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
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
