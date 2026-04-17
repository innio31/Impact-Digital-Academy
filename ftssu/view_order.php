<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

require_once 'config.php';

$orderId = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found');
}

$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - <?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; padding: 1rem; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 1.5rem; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #cc0000; margin-bottom: 1rem; font-size: 1.5rem; }
        .info-row { display: flex; padding: 0.8rem 0; border-bottom: 1px solid #eee; flex-wrap: wrap; }
        .info-label { font-weight: 600; width: 140px; color: #555; }
        .info-value { flex: 1; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .total { font-size: 1.2rem; font-weight: bold; text-align: right; margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #cc0000; }
        .back-btn { display: inline-block; margin-top: 1rem; padding: 10px 20px; background: #333; color: white; text-decoration: none; border-radius: 8px; }
        .status-pending { background: #ffc107; color: #333; padding: 4px 12px; border-radius: 20px; display: inline-block; }
        .status-completed { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; display: inline-block; }
        .status-cancelled { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; display: inline-block; }
        @media (max-width: 600px) {
            .info-label { width: 100%; margin-bottom: 5px; }
            .container { padding: 1rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Order Details</h1>
        
        <div class="info-row">
            <div class="info-label">Order Number:</div>
            <div class="info-value"><?= htmlspecialchars($order['order_number']) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Customer Name:</div>
            <div class="info-value"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Phone Number:</div>
            <div class="info-value"><?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></div>
        </div>
        <div class="info-row">
    <div class="info-label">Command:</div>
    <div class="info-value"><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></div>
</div>
        <div class="info-row">
            <div class="info-label">Status:</div>
            <div class="info-value">
                <?php if($order['status'] == 'pending'): ?>
                    <span class="status-pending">⏳ Pending Payment</span>
                <?php elseif($order['status'] == 'completed'): ?>
                    <span class="status-completed">✅ Completed</span>
                <?php elseif($order['status'] == 'cancelled'): ?>
                    <span class="status-cancelled">❌ Cancelled</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="info-row">
            <div class="info-label">Date:</div>
            <div class="info-value"><?= date('F j, Y g:i A', strtotime($order['created_at'])) ?></div>
        </div>
        
        <h2 style="margin: 1.5rem 0 0.5rem;">Items Ordered</h2>
        <table>
            <thead>
                <tr><th>Product</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>
                <?php foreach($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td>₦<?= number_format($item['unit_price'], 0) ?></td>
                    <td>₦<?= number_format($item['total_price'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="total">Grand Total: ₦<?= number_format($order['total_amount'], 0) ?></div>
        
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>
</body>
</html>