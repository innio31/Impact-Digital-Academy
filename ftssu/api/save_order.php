<?php
include 'db_connect.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

// Generate unique order number
$order_number = 'FTSS-' . date('Ymd') . '-' . rand(1000, 9999);

// Escape strings
$customer_name = $conn->real_escape_string($input['customer_name']);
$customer_phone = $conn->real_escape_string($input['customer_phone']);
$customer_command = $conn->real_escape_string($input['customer_command']);
$total_amount = (float)$input['total_amount'];

// Insert order
$sql = "INSERT INTO orders (order_number, customer_name, customer_phone, customer_command, total_amount, status, created_at) 
        VALUES ('$order_number', '$customer_name', '$customer_phone', '$customer_command', $total_amount, 'pending', NOW())";

if ($conn->query($sql)) {
    $order_id = $conn->insert_id;

    // Insert order items
    foreach ($input['items'] as $item) {
        $product_id = (int)$item['id'];
        $product_name = $conn->real_escape_string($item['name']);

        if ($item['has_custom_price']) {
            $quantity = 1;
            $unit_price = (float)$item['customAmount'];
            $total_price = $unit_price;
        } else {
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            $total_price = $unit_price * $quantity;
        }

        $item_sql = "INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price) 
                     VALUES ($order_id, $product_id, '$product_name', $quantity, $unit_price, $total_price)";
        $conn->query($item_sql);
    }

    echo json_encode(['success' => true, 'order_number' => $order_number]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
