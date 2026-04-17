<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$id_number = $data['id_number'] ?? '';

if (empty($id_number)) {
    echo json_encode(['success' => false, 'message' => 'ID Number is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id_number = ? AND is_active = 1");
    $stmt->execute([$id_number]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        // Remove sensitive data if any
        unset($member['password']);
        echo json_encode([
            'success' => true,
            'member' => $member
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'ID Number not found or inactive'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
