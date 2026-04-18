<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Database configuration
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'your_db_username';
$password = 'your_db_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : null;
    $phone_number = isset($_POST['phone_number']) ? $_POST['phone_number'] : null;
    $email = isset($_POST['email']) ? $_POST['email'] : null;
    $date_of_birth = isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Member ID is required']);
        exit;
    }

    $update_fields = [];
    $params = [];

    if ($phone_number !== null) {
        $update_fields[] = "phone_number = ?";
        $params[] = $phone_number;
    }

    if ($email !== null) {
        $update_fields[] = "email = ?";
        $params[] = $email;
    }

    if ($date_of_birth !== null) {
        $update_fields[] = "date_of_birth = ?";
        $params[] = $date_of_birth;
    }

    if (empty($update_fields)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
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
            'member' => $member
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
