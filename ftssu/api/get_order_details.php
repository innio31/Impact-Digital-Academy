<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$order_number = $conn->real_escape_string($_GET['order_number'] ?? '');

if (!$order_number) {
    echo json_encode(['success' => false, 'error' => 'Order number required']);
    exit;
}

// Get order info
$order_sql = "SELECT order_number, customer_name, customer_phone, customer_command, total_amount, status, created_at 
              FROM orders 
              WHERE order_number = '$order_number'";
$order_result = $conn->query($order_sql);
$order = $order_result->fetch_assoc();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

// Get order items
$items_sql = "SELECT product_name, quantity, unit_price, total_price 
              FROM order_items 
              WHERE order_id = (SELECT id FROM orders WHERE order_number = '$order_number')";
$items_result = $conn->query($items_sql);
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = [
        'product_name' => $row['product_name'],
        'quantity' => (int)$row['quantity'],
        'unit_price' => (float)$row['unit_price'],
        'total_price' => (float)$row['total_price']
    ];
}

echo json_encode([
    'success' => true,
    'order' => $order,
    'items' => $items
]);

$conn->close();
