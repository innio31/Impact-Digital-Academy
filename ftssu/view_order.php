<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
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
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            border-bottom: 3px solid #cc0000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .order-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .order-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .order-label {
            font-weight: 600;
            color: #555;
        }

        .order-value {
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f8f9fa;
        }

        .total-row {
            font-weight: bold;
            font-size: 1.2em;
            color: #cc0000;
        }

        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background: #ffc107;
            color: #333;
        }

        .status-completed {
            background: #28a745;
            color: white;
        }

        .status-delivered {
            background: #007bff;
            color: white;
        }

        .status-cancelled {
            background: #dc3545;
            color: white;
        }

        .delivery-info {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #007bff;
        }

        .back-btn {
            background: #cc0000;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            display: inline-block;
            margin-top: 20px;
        }

        .back-btn:hover {
            background: #990000;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Order Details</h1>
            <p>Faith Tabernacle Security Service Unit</p>
        </div>

        <div class="order-info">
            <div class="order-row">
                <span class="order-label">Order Number:</span>
                <span class="order-value"><?= htmlspecialchars($order['order_number']) ?></span>
            </div>
            <div class="order-row">
                <span class="order-label">Customer Name:</span>
                <span class="order-value"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></span>
            </div>
            <div class="order-row">
                <span class="order-label">Phone Number:</span>
                <span class="order-value"><?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></span>
            </div>
            <div class="order-row">
                <span class="order-label">Command:</span>
                <span class="order-value"><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></span>
            </div>
            <div class="order-row">
                <span class="order-label">Status:</span>
                <span class="order-value">
                    <?php if ($order['status'] == 'pending'): ?>
                        <span class="status status-pending">Pending Payment</span>
                    <?php elseif ($order['status'] == 'completed'): ?>
                        <span class="status status-completed">Payment Confirmed</span>
                    <?php elseif ($order['status'] == 'delivered'): ?>
                        <span class="status status-delivered">Delivered ✓</span>
                    <?php elseif ($order['status'] == 'cancelled'): ?>
                        <span class="status status-cancelled">Cancelled</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="order-row">
                <span class="order-label">Order Date:</span>
                <span class="order-value"><?= date('F j, Y g:i A', strtotime($order['created_at'])) ?></span>
            </div>
        </div>

        <h3>Order Items</h3>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></span></span></span></td>
                        <td><?= $item['quantity'] ?></span></span></span></span></span></span></span></span></td>
                        <td>₦<?= number_format($item['unit_price'], 0) ?></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span>
                        <td class="total-row">₦<?= number_format($item['total_price'], 0) ?></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span></span>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3" style="text-align: right;">Grand Total:</td>
                    <td>₦<?= number_format($order['total_amount'], 0) ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($order['status'] == 'delivered'): ?>
            <div class="delivery-info">
                <h3>📦 Delivery Information</h3>
                <div class="order-row">
                    <span class="order-label">Delivered By:</span>
                    <span class="order-value"><?= htmlspecialchars($order['delivered_by'] ?? 'N/A') ?></span>
                </div>
                <div class="order-row">
                    <span class="order-label">Delivery Date:</span>
                    <span class="order-value"><?= date('F j, Y g:i A', strtotime($order['delivered_at'])) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>
</body>

</html>