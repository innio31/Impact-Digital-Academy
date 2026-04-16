<?php
include 'db_connect.php';

$result = $conn->query("SELECT id, name, price, description, has_custom_price, is_active FROM products WHERE is_active = 1 ORDER BY sort_order ASC");

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'price' => (float)$row['price'],
        'description' => $row['description'] ?? '',
        'has_custom_price' => (bool)$row['has_custom_price']
    ];
}

echo json_encode(['success' => true, 'products' => $products]);
$conn->close();
