<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

// Handle product add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_product':
                $stmt = $pdo->prepare("INSERT INTO products (name, price, description, has_custom_price, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['price'], $_POST['description'], $_POST['has_custom_price'] ?? 0, $_POST['sort_order']]);
                break;
            case 'update_product':
                $stmt = $pdo->prepare("UPDATE products SET name=?, price=?, description=?, has_custom_price=?, sort_order=? WHERE id=?");
                $stmt->execute([$_POST['name'], $_POST['price'], $_POST['description'], $_POST['has_custom_price'] ?? 0, $_POST['sort_order'], $_POST['id']]);
                break;
            case 'delete_product':
                $stmt = $pdo->prepare("DELETE FROM products WHERE id=?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'update_order_status':
                $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $stmt->execute([$_POST['status'], $_POST['order_id']]);
                break;
            case 'confirm_payment':
                $stmt = $pdo->prepare("UPDATE orders SET status = 'payment_confirmed' WHERE id = ?");
                $stmt->execute([$_POST['order_id']]);
                break;
            case 'mark_delivered':
                $stmt = $pdo->prepare("UPDATE orders SET status = 'goods_delivered', delivered_at = NOW(), delivered_by = ? WHERE id = ?");
                $stmt->execute([$_POST['delivered_by'], $_POST['order_id']]);
                break;
            case 'reorder_products':
                $orderData = json_decode($_POST['order_data'], true);
                foreach ($orderData as $item) {
                    $stmt = $pdo->prepare("UPDATE products SET sort_order = ? WHERE id = ?");
                    $stmt->execute([$item['sort_order'], $item['id']]);
                }
                break;
        }
        header('Location: admin.php');
        exit();
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';

    $query = "SELECT * FROM orders WHERE 1=1";
    $params = [];
    if ($startDate && $endDate) {
        $query .= " AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC";
        $params = [$startDate, $endDate];
    } elseif ($startDate) {
        $query .= " AND DATE(created_at) = ? ORDER BY created_at DESC";
        $params = [$startDate];
    } else {
        $query .= " ORDER BY created_at DESC";
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order #', 'Customer Name', 'Phone', 'Command', 'Total Amount', 'Status', 'Order Date', 'Delivery Date', 'Delivered By']);
    foreach ($orders as $order) {
        $statusDisplay = '';
        if ($order['status'] == 'pending') $statusDisplay = 'Pending';
        elseif ($order['status'] == 'payment_confirmed') $statusDisplay = 'Payment Confirmed';
        elseif ($order['status'] == 'goods_delivered') $statusDisplay = 'Goods Delivered';
        elseif ($order['status'] == 'cancelled') $statusDisplay = 'Cancelled';

        fputcsv($output, [
            $order['order_number'],
            $order['customer_name'],
            $order['customer_phone'],
            $order['customer_command'],
            $order['total_amount'],
            $statusDisplay,
            date('Y-m-d H:i', strtotime($order['created_at'])),
            $order['delivered_at'] ? date('Y-m-d H:i', strtotime($order['delivered_at'])) : '',
            $order['delivered_by'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

// Get all products ordered by sort_order
$products = $pdo->query("SELECT * FROM products ORDER BY sort_order ASC, id ASC")->fetchAll();

// Get filter parameters
$startDateFilter = $_GET['start_date'] ?? '';
$endDateFilter = $_GET['end_date'] ?? '';

// Build orders query with date filter
$ordersQuery = "SELECT * FROM orders WHERE 1=1";
$filterParams = [];

if (!empty($startDateFilter) && !empty($endDateFilter)) {
    $ordersQuery .= " AND DATE(created_at) BETWEEN ? AND ?";
    $filterParams = [$startDateFilter, $endDateFilter];
} elseif (!empty($startDateFilter)) {
    $ordersQuery .= " AND DATE(created_at) = ?";
    $filterParams = [$startDateFilter];
}

$ordersQuery .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($ordersQuery);
$stmt->execute($filterParams);
$orders = $stmt->fetchAll();

// Calculate revenue
$totalRevenue = 0;
$pendingOrdersCount = 0;
$paymentConfirmedCount = 0;
$goodsDeliveredCount = 0;
$cancelledOrdersCount = 0;

foreach ($orders as $order) {
    if ($order['status'] == 'payment_confirmed' || $order['status'] == 'goods_delivered') {
        $totalRevenue += $order['total_amount'];
        if ($order['status'] == 'goods_delivered') {
            $goodsDeliveredCount++;
        } else {
            $paymentConfirmedCount++;
        }
    } elseif ($order['status'] == 'pending') {
        $pendingOrdersCount++;
    } elseif ($order['status'] == 'cancelled') {
        $cancelledOrdersCount++;
    }
}

$totalOrders = count($orders);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard - Faith Tabernacle Security</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 0;
            margin: 0;
        }

        .header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            padding: 1rem;
            border-bottom: 3px solid #cc0000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .logo-icon {
            background: #cc0000;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-icon span {
            font-size: 1.5rem;
            color: white;
        }

        .header h1 {
            font-size: 1rem;
            color: #1a1a1a;
        }

        .header-subtitle {
            font-size: 0.7rem;
            color: #666;
        }

        .welcome-text {
            font-size: 0.8rem;
            color: #cc0000;
            font-weight: 600;
        }

        .logout-btn {
            background: #cc0000;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.8rem;
            padding: 1rem;
            background: #f0f2f5;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #cc0000;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: #cc0000;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #666;
            margin-top: 5px;
        }

        .container {
            padding: 1rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .tab-navigation {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            background: white;
            padding: 0.5rem;
            border-radius: 12px;
            flex-wrap: wrap;
        }

        .tab-btn {
            background: #f0f2f5;
            border: none;
            padding: 0.7rem 1.2rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: #666;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .tab-btn.active {
            background: #cc0000;
            color: white;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1rem;
            padding-bottom: 0.8rem;
            border-bottom: 2px solid #cc0000;
        }

        .section h2 {
            font-size: 1.1rem;
            color: #1a1a1a;
        }

        .export-bar {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .date-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            align-items: flex-end;
            flex: 1;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .filter-group label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #555;
        }

        .filter-group input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: #cc0000;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-success {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .btn-delivered {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .btn-edit {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .btn-view {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-save {
            background: #cc0000;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .sortable-list {
            list-style: none;
            padding: 0;
        }

        .sortable-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            padding: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .drag-handle {
            cursor: grab;
            color: #999;
            font-size: 1.2rem;
        }

        .product-info-drag {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            flex: 1;
            flex-wrap: wrap;
        }

        .drag-badge {
            background: #cc0000;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        /* Mobile Styles */
        .order-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 1rem;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #cc0000;
        }

        .order-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }

        .order-label {
            font-weight: 600;
            color: #555;
            font-size: 0.75rem;
        }

        .order-value {
            color: #333;
            font-size: 0.85rem;
            text-align: right;
        }

        .order-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.8rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .status-pending {
            background: #ffc107;
            color: #333;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-payment_confirmed {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-goods_delivered {
            background: #007bff;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-cancelled {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Desktop Table */
        .desktop-table {
            display: none;
        }

        @media (min-width: 768px) {
            .mobile-orders {
                display: none;
            }

            .desktop-table {
                display: block;
                overflow-x: auto;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #ddd;
                font-size: 0.85rem;
            }

            th {
                background: #f8f9fa;
                font-weight: 600;
            }
        }

        @media (max-width: 767px) {
            .desktop-table {
                display: none;
            }

            .mobile-orders {
                display: block;
            }
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            width: 90%;
            max-width: 450px;
        }

        .modal-content h3 {
            color: #cc0000;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .modal-buttons {
            display: flex;
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .reorder-notice {
            background: #e8f4f8;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-bottom: 1rem;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #999;
        }

        .btn-clear {
            background: #6c757d;
            text-decoration: none;
        }

        .btn-export-csv {
            background: #17a2b8;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-top">
            <div class="logo-section">
                <div class="logo-icon"><span>⚔️</span></div>
                <div>
                    <h1>FAITH TABERNACLE SECURITY</h1>
                    <div class="header-subtitle">Admin Control Panel</div>
                </div>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <div class="welcome-text">Welcome, <?= htmlspecialchars($_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Admin') ?>!</div>
                <a href="logout.php" class="logout-btn">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-number"><?= $totalOrders ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $pendingOrdersCount ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $paymentConfirmedCount ?></div>
            <div class="stat-label">Payment Confirmed</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $goodsDeliveredCount ?></div>
            <div class="stat-label">Goods Delivered</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">₦<?= number_format($totalRevenue) ?></div>
            <div class="stat-label">Total Revenue</div>
        </div>
    </div>

    <div class="container">
        <div class="export-bar">
            <form method="GET" class="date-filter">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDateFilter) ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDateFilter) ?>">
                </div>
                <button type="submit" class="btn-primary">🔍 Apply Filter</button>
                <a href="admin.php" class="btn-primary btn-clear" style="background:#6c757d;">🗑️ Clear</a>
            </form>
            <a href="?export=csv&start_date=<?= urlencode($startDateFilter) ?>&end_date=<?= urlencode($endDateFilter) ?>" class="btn-primary btn-export-csv" style="background:#17a2b8;">📊 Export CSV</a>
        </div>

        <div class="tab-navigation">
            <button class="tab-btn active" onclick="switchTab('products')">📦 Products</button>
            <button class="tab-btn" onclick="switchTab('orders')">📋 Orders</button>
        </div>

        <!-- Products Tab -->
        <div id="productsTab" class="tab-content active">
            <div class="section">
                <div class="section-header">
                    <h2>Products Management</h2>
                    <button class="btn-primary" onclick="showAddProductModal()">+ Add Product</button>
                </div>
                <div class="reorder-notice">🔄 Drag the ☰ icon to reorder products. Click "Save Order" when done.</div>
                <form method="POST" id="reorderForm">
                    <input type="hidden" name="action" value="reorder_products">
                    <input type="hidden" name="order_data" id="orderDataInput">
                    <ul id="sortable-list" class="sortable-list">
                        <?php foreach ($products as $index => $product): ?>
                            <li class="sortable-item" data-id="<?= $product['id'] ?>">
                                <div class="product-info-drag">
                                    <span class="drag-handle">☰</span>
                                    <span class="drag-badge">#<?= $index + 1 ?></span>
                                    <strong><?= htmlspecialchars($product['name']) ?></strong>
                                    <span style="color:#cc0000;">₦<?= number_format($product['price'], 0) ?></span>
                                    <?php if ($product['has_custom_price']): ?><span style="background:#fff0f0;padding:2px 6px;border-radius:12px;font-size:0.65rem;">💝 Custom</span><?php endif; ?>
                                </div>
                                <div>
                                    <button type="button" class="btn-edit" onclick='editProduct(<?= json_encode($product) ?>)'>Edit</button>
                                    <button type="button" class="btn-delete" onclick="deleteProduct(<?= $product['id'] ?>)">Delete</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top:1rem; text-align:right;">
                        <button type="button" class="btn-primary" onclick="saveProductOrder()">💾 Save Order</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders Tab -->
        <div id="ordersTab" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2>Orders Management</h2>
                </div>

                <?php if (count($orders) > 0): ?>
                    <!-- Desktop Table View -->
                    <div class="desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Command</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Order Date</th>
                                    <th>Delivered By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></span></span></span></span></span></span></span></span></td>
                                        <td><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></span></span></span></span></span></span></span></span></td>
                                        <td><strong>₦<?= number_format($order['total_amount'], 0) ?></strong></span></span></span></span></span></span></span></span></td>
                                        <td>
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <span class="status-pending">Pending</span>
                                            <?php elseif ($order['status'] == 'payment_confirmed'): ?>
                                                <span class="status-payment_confirmed">Payment Confirmed</span>
                                            <?php elseif ($order['status'] == 'goods_delivered'): ?>
                                                <span class="status-goods_delivered">Goods Delivered</span>
                                            <?php elseif ($order['status'] == 'cancelled'): ?>
                                                <span class="status-cancelled">Cancelled</span>
                                            <?php endif; ?>
                                            </span>
                                        <td><?= date('d/m/y H:i', strtotime($order['created_at'])) ?></span>
                                        <td><?= $order['delivered_by'] ? htmlspecialchars($order['delivered_by']) : '-' ?></span>
                                        <td>
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm payment for order <?= htmlspecialchars($order['order_number']) ?>?')">
                                                    <input type="hidden" name="action" value="confirm_payment">
                                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                    <button type="submit" class="btn-success">Confirm Payment</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($order['status'] == 'payment_confirmed'): ?>
                                                <button type="button" class="btn-delivered" onclick="showDeliveryModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')">Mark Delivered</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-view" onclick="viewOrder(<?= $order['id'] ?>)">View</button>
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order?')">
                                                    <input type="hidden" name="action" value="update_order_status">
                                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" class="btn-delete">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                            </span>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards View -->
                    <div class="mobile-orders">
                        <?php foreach ($orders as $order): ?>
                            <div class="order-card">
                                <div class="order-row">
                                    <span class="order-label">Order #</span>
                                    <span class="order-value"><strong><?= htmlspecialchars($order['order_number']) ?></strong></span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Customer</span>
                                    <span class="order-value"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Phone</span>
                                    <span class="order-value"><?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Command</span>
                                    <span class="order-value"><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Total</span>
                                    <span class="order-value"><strong>₦<?= number_format($order['total_amount'], 0) ?></strong></span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Status</span>
                                    <span class="order-value">
                                        <?php if ($order['status'] == 'pending'): ?>
                                            <span class="status-pending">Pending</span>
                                        <?php elseif ($order['status'] == 'payment_confirmed'): ?>
                                            <span class="status-payment_confirmed">Payment Confirmed</span>
                                        <?php elseif ($order['status'] == 'goods_delivered'): ?>
                                            <span class="status-goods_delivered">Goods Delivered</span>
                                        <?php elseif ($order['status'] == 'cancelled'): ?>
                                            <span class="status-cancelled">Cancelled</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="order-row">
                                    <span class="order-label">Order Date</span>
                                    <span class="order-value"><?= date('d/m/Y h:i A', strtotime($order['created_at'])) ?></span>
                                </div>
                                <?php if ($order['delivered_by']): ?>
                                    <div class="order-row">
                                        <span class="order-label">Delivered By</span>
                                        <span class="order-value"><?= htmlspecialchars($order['delivered_by']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="order-actions">
                                    <?php if ($order['status'] == 'pending'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm payment for order <?= htmlspecialchars($order['order_number']) ?>?')">
                                            <input type="hidden" name="action" value="confirm_payment">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn-success">Confirm Payment</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($order['status'] == 'payment_confirmed'): ?>
                                        <button type="button" class="btn-delivered" onclick="showDeliveryModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')">Mark Delivered</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-view" onclick="viewOrder(<?= $order['id'] ?>)">View</button>
                                    <?php if ($order['status'] == 'pending'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order?')">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <button type="submit" class="btn-delete">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">No orders found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Add Product</h3>
            <form method="POST" id="productForm">
                <input type="hidden" name="action" id="formAction" value="add_product">
                <input type="hidden" name="id" id="productId">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="name" id="productName" required>
                </div>
                <div class="form-group">
                    <label>Price (₦)</label>
                    <input type="number" name="price" id="productPrice" step="1" value="0">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="productDescription" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="has_custom_price" id="productCustomPrice" value="1"> Custom Price (Love Seed type)</label>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" id="productSortOrder" value="0">
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div id="deliveryModal" class="modal">
        <div class="modal-content">
            <h3>📦 Confirm Goods Delivery</h3>
            <form method="POST" id="deliveryForm">
                <input type="hidden" name="action" value="mark_delivered">
                <input type="hidden" name="order_id" id="deliveryOrderId">
                <div class="form-group">
                    <label>Order Number</label>
                    <input type="text" id="deliveryOrderNumber" readonly style="background:#f0f0f0; font-weight:bold;">
                </div>
                <div class="form-group">
                    <label>Delivered By</label>
                    <input type="text" name="delivered_by" id="deliveredByName" readonly style="background:#f0f0f0; font-weight:600; color:#cc0000;" value="<?= htmlspecialchars($_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Admin') ?>">
                    <small style="color:#28a745;">✓ Automatically detected from your account</small>
                </div>
                <div class="form-group">
                    <div style="background:#e8f4f8; padding:10px; border-radius:8px;">
                        <p style="font-size:0.8rem; margin:0;"><strong>Confirmation:</strong> I confirm that the goods have been physically handed over to the customer.</p>
                    </div>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeDeliveryModal()">Cancel</button>
                    <button type="submit" class="btn-save" onclick="return confirm('Confirm delivery of this order? This cannot be undone.')">✅ Confirm Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let sortableInstance = null;

        function switchTab(tabName) {
            document.getElementById('productsTab').classList.remove('active');
            document.getElementById('ordersTab').classList.remove('active');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            if (tabName === 'products') {
                document.getElementById('productsTab').classList.add('active');
                document.querySelector('.tab-btn:first-child').classList.add('active');
            } else {
                document.getElementById('ordersTab').classList.add('active');
                document.querySelector('.tab-btn:last-child').classList.add('active');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const list = document.getElementById('sortable-list');
            if (list) {
                sortableInstance = new Sortable(list, {
                    animation: 300,
                    handle: '.drag-handle',
                    onEnd: function() {
                        updatePositionBadges();
                    }
                });
            }
        });

        function updatePositionBadges() {
            const items = document.querySelectorAll('#sortable-list .sortable-item');
            items.forEach((item, index) => {
                const badge = item.querySelector('.drag-badge');
                if (badge) badge.textContent = '#' + (index + 1);
            });
        }

        function saveProductOrder() {
            const items = document.querySelectorAll('#sortable-list .sortable-item');
            const orderData = [];
            items.forEach((item, index) => {
                orderData.push({
                    id: item.dataset.id,
                    sort_order: index
                });
            });
            document.getElementById('orderDataInput').value = JSON.stringify(orderData);
            document.getElementById('reorderForm').submit();
        }

        function showAddProductModal() {
            document.getElementById('modalTitle').innerText = 'Add Product';
            document.getElementById('formAction').value = 'add_product';
            document.getElementById('productId').value = '';
            document.getElementById('productName').value = '';
            document.getElementById('productPrice').value = '0';
            document.getElementById('productDescription').value = '';
            document.getElementById('productCustomPrice').checked = false;
            document.getElementById('productSortOrder').value = '0';
            document.getElementById('productModal').style.display = 'flex';
        }

        function editProduct(product) {
            document.getElementById('modalTitle').innerText = 'Edit Product';
            document.getElementById('formAction').value = 'update_product';
            document.getElementById('productId').value = product.id;
            document.getElementById('productName').value = product.name;
            document.getElementById('productPrice').value = product.price;
            document.getElementById('productDescription').value = product.description || '';
            document.getElementById('productCustomPrice').checked = product.has_custom_price == 1;
            document.getElementById('productSortOrder').value = product.sort_order;
            document.getElementById('productModal').style.display = 'flex';
        }

        function deleteProduct(id) {
            if (confirm('Delete this product?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewOrder(orderId) {
            window.location.href = `view_order.php?id=${orderId}`;
        }

        function closeModal() {
            document.getElementById('productModal').style.display = 'none';
        }

        function showDeliveryModal(orderId, orderNumber) {
            document.getElementById('deliveryOrderId').value = orderId;
            document.getElementById('deliveryOrderNumber').value = orderNumber;
            document.getElementById('deliveryModal').style.display = 'flex';
        }

        function closeDeliveryModal() {
            document.getElementById('deliveryModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target === document.getElementById('productModal')) closeModal();
            if (event.target === document.getElementById('deliveryModal')) closeDeliveryModal();
        }
    </script>
</body>

</html>