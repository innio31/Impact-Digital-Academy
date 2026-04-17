<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$name = $conn->real_escape_string($data['name'] ?? '');
$price = (float)($data['price'] ?? 0);
$description = $conn->real_escape_string($data['description'] ?? '');
$has_custom_price = isset($data['has_custom_price']) ? (int)$data['has_custom_price'] : 0;

if (!$name) {
    echo json_encode(['success' => false, 'error' => 'Product name required']);
    exit;
}

$sql = "INSERT INTO products (name, price, description, has_custom_price, sort_order) 
        VALUES ('$name', $price, '$description', $has_custom_price, 0)";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
