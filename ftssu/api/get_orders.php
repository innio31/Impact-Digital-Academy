<?php
include 'db_connect.php';

$phone = $conn->real_escape_string($_GET['phone'] ?? '');

if (!$phone) {
    echo json_encode(['success' => false, 'error' => 'Phone number required']);
    exit;
}

$sql = "SELECT order_number, total_amount, status, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as date 
        FROM orders 
        WHERE customer_phone = '$phone' 
        ORDER BY created_at DESC 
        LIMIT 20";

$result = $conn->query($sql);
$orders = [];

while ($row = $result->fetch_assoc()) {
    $orders[] = [
        'order_number' => $row['order_number'],
        'total_amount' => (float)$row['total_amount'],
        'status' => $row['status'],
        'date' => $row['date']
    ];
}

echo json_encode(['success' => true, 'orders' => $orders]);
$conn->close();
