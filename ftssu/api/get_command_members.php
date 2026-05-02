<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$command = isset($_GET['command']) ? $_GET['command'] : '';

if (!$command) {
    echo json_encode(['success' => false, 'message' => 'Command name required']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, id_number, first_name, last_name, designation, role, phone_number
    FROM members 
    WHERE command = ? AND is_active = 1
    ORDER BY first_name ASC
");
$stmt->bind_param("s", $command);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'members' => $members,
    'total' => count($members)
]);

$conn->close();
