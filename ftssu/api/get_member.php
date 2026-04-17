<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

$sql = "SELECT * FROM members WHERE id = $id";
$result = $conn->query($sql);
$member = $result->fetch_assoc();

if ($member) {
    echo json_encode(['success' => true, 'member' => $member]);
} else {
    echo json_encode(['success' => false, 'error' => 'Member not found']);
}

$conn->close();
