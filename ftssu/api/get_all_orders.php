<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$sql = "SELECT id, order_number, customer_name, customer_phone, customer_command, total_amount, status, created_at 
        FROM orders 
        ORDER BY created_at DESC 
        LIMIT 100";

$result = $conn->query($sql);
$orders = [];

while ($row = $result->fetch_assoc()) {
    $orders[] = [
        'id' => $row['id'],
        'order_number' => $row['order_number'],
        'customer_name' => $row['customer_name'],
        'customer_phone' => $row['customer_phone'],
        'customer_command' => $row['customer_command'],
        'total_amount' => (float)$row['total_amount'],
        'status' => $row['status'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode(['success' => true, 'orders' => $orders]);
$conn->close();
