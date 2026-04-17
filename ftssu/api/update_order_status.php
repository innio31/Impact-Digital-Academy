<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($data['order_id'] ?? 0);
$status = $conn->real_escape_string($data['status'] ?? '');

if (!$order_id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$sql = "UPDATE orders SET status = '$status' WHERE id = $order_id";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
