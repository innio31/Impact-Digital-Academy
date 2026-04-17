<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$id = (int)($data['id'] ?? 0);
$name = $conn->real_escape_string($data['name'] ?? '');
$price = (float)($data['price'] ?? 0);
$description = $conn->real_escape_string($data['description'] ?? '');
$has_custom_price = isset($data['has_custom_price']) ? (int)$data['has_custom_price'] : 0;

if (!$id || !$name) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$sql = "UPDATE products SET 
        name = '$name', 
        price = $price, 
        description = '$description', 
        has_custom_price = $has_custom_price 
        WHERE id = $id";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
