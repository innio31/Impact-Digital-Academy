<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT id, name, price, description, has_custom_price as hasCustomPrice, is_active FROM products WHERE is_active = 1 ORDER BY sort_order");
    $products = $stmt->fetchAll();
    echo json_encode($products);
}
?>