<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $pdo->beginTransaction();
        
        // Generate order number
        $orderNumber = 'FTSS-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Insert order
       $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, customer_command, total_amount) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$orderNumber, $data['customer_name'] ?? '', $data['customer_phone'] ?? '', $data['customer_command'] ?? '', $data['total_amount']]);
        
        $orderId = $pdo->lastInsertId();
        
        // Insert order items
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($data['items'] as $item) {
            $totalPrice = $item['hasCustomPrice'] ? $item['quantity'] : $item['price'] * $item['quantity'];
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['hasCustomPrice'] ? 0 : $item['price'],
                $totalPrice
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'order_number' => $orderNumber]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>