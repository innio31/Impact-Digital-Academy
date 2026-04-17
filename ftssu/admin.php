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
                $stmt = $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?");
                $stmt->execute([$_POST['order_id']]);
                break;
            case 'mark_delivered':
                $stmt = $pdo->prepare("UPDATE orders SET status = 'delivered', delivered_at = NOW(), delivered_by = ? WHERE id = ?");
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

// Handle CSV/PDF export
if (isset($_GET['export'])) {
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';

    $query = "SELECT * FROM orders";
    $params = [];
    if ($startDate && $endDate) {
        $query .= " WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC";
        $params = [$startDate, $endDate];
    } elseif ($startDate) {
        $query .= " WHERE DATE(created_at) = ? ORDER BY created_at DESC";
        $params = [$startDate];
    } else {
        $query .= " ORDER BY created_at DESC";
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Order #', 'Customer Name', 'Phone', 'Command', 'Total Amount', 'Status', 'Delivery Date', 'Delivered By', 'Created At']);
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_number'],
                $order['customer_name'],
                $order['customer_phone'],
                $order['customer_command'],
                $order['total_amount'],
                $order['status'],
                $order['delivered_at'] ?? '',
                $order['delivered_by'] ?? '',
                $order['created_at']
            ]);
        }
        fclose($output);
        exit();
    } elseif ($_GET['export'] === 'pdf') {
        require_once('tcpdf/tcpdf.php');
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Faith Tabernacle Security');
        $pdf->SetAuthor('Admin');
        $pdf->SetTitle('Orders Report');
        $pdf->SetHeaderData('', 0, 'Orders Report', 'Generated: ' . date('Y-m-d'));
        $pdf->setHeaderFont(['helvetica', '', 10]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetDefaultMonospacedFont('courier');
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();
        $html = '<h2>Orders Report</h2>
                 <table border="1" cellpadding="5">
                  <thead>
                   <tr>
                    <th>Order #</th><th>Customer</th><th>Phone</th><th>Command</th><th>Total</th><th>Status</th><th>Delivered</th>
                   </tr>
                  </thead>
                  <tbody>';
        foreach ($orders as $order) {
            $html .= '<tr>
                       <td>' . htmlspecialchars($order['order_number']) . '</td>
                       <td>' . htmlspecialchars($order['customer_name']) . '</td>
                       <td>' . htmlspecialchars($order['customer_phone']) . '</td>
                       <td>' . htmlspecialchars($order['customer_command']) . '</td>
                       <td>₦' . number_format($order['total_amount'], 0) . '</td>
                       <td>' . ucfirst($order['status']) . '</td>
                       <td>' . ($order['delivered_at'] ? date('d/m/y', strtotime($order['delivered_at'])) : '-') . '</td>
                      </tr>';
        }
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('orders_' . date('Y-m-d') . '.pdf', 'D');
        exit();
    }
}

// Get all products ordered by sort_order
$products = $pdo->query("SELECT * FROM products ORDER BY sort_order ASC, id ASC")->fetchAll();

// Get filter parameters
$startDateFilter = $_GET['start_date'] ?? '';
$endDateFilter = $_GET['end_date'] ?? '';

// Build orders query with date filter
$ordersQuery = "SELECT * FROM orders";
$filterParams = [];

if ($startDateFilter && $endDateFilter) {
    $ordersQuery .= " WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC";
    $filterParams = [$startDateFilter, $endDateFilter];
} elseif ($startDateFilter) {
    $ordersQuery .= " WHERE DATE(created_at) = ? ORDER BY created_at DESC";
    $filterParams = [$startDateFilter];
} else {
    $ordersQuery .= " ORDER BY created_at DESC";
}

$stmt = $pdo->prepare($ordersQuery);
$stmt->execute($filterParams);
$orders = $stmt->fetchAll();

// Calculate revenue from COMPLETED and DELIVERED orders only
$completedRevenue = 0;
$pendingOrdersCount = 0;
$completedOrdersCount = 0;
$deliveredOrdersCount = 0;
$cancelledOrdersCount = 0;

foreach ($orders as $order) {
    if ($order['status'] == 'completed' || $order['status'] == 'delivered') {
        $completedRevenue += $order['total_amount'];
        if ($order['status'] == 'delivered') {
            $deliveredOrdersCount++;
        } else {
            $completedOrdersCount++;
        }
    } elseif ($order['status'] == 'pending') {
        $pendingOrdersCount++;
    } elseif ($order['status'] == 'cancelled') {
        $cancelledOrdersCount++;
    }
}

$totalOrders = count($orders);
$totalProducts = count($products);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard - Faith Tabernacle Security</title>
    <!-- SortableJS for drag-and-drop -->
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
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
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
            font-size: 1.1rem;
            color: #1a1a1a;
            margin: 0;
            line-height: 1.3;
        }

        .header-subtitle {
            font-size: 0.7rem;
            color: #666;
            margin-top: 2px;
        }

        .logout-btn {
            background: #cc0000;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .logout-btn:hover {
            background: #990000;
            transform: scale(1.02);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
            font-size: 1.8rem;
            font-weight: 800;
            color: #cc0000;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #666;
            margin-top: 5px;
        }

        .revenue-info {
            font-size: 0.6rem;
            color: #28a745;
            margin-top: 5px;
        }

        .container {
            padding: 1rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .section {
            background: white;
            border-radius: 16px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
            padding-bottom: 0.8rem;
            border-bottom: 2px solid #cc0000;
        }

        .section h2 {
            font-size: 1.2rem;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Export Bar */
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

        .btn-export {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-export-csv {
            background: #17a2b8;
        }

        .btn-export-pdf {
            background: #dc3545;
        }

        .btn-filter {
            background: #cc0000;
        }

        .btn-clear {
            background: #6c757d;
        }

        .btn-primary {
            background: #cc0000;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary:hover {
            background: #990000;
            transform: scale(1.02);
        }

        .btn-save-order {
            background: #28a745;
        }

        .btn-save-order:hover {
            background: #1e7e34;
        }

        .btn-success {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            margin: 2px;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-delivered {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            margin: 2px;
        }

        .btn-delivered:hover {
            background: #138496;
        }

        .btn-edit {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            margin: 2px;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            margin: 2px;
        }

        .btn-view {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
        }

        /* Drag and drop styles */
        .sortable-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .sortable-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            padding: 0.8rem;
            cursor: grab;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .sortable-item:active {
            cursor: grabbing;
        }

        .sortable-item.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }

        .drag-handle {
            cursor: grab;
            color: #999;
            font-size: 1.2rem;
            display: inline-flex;
            align-items: center;
        }

        .drag-handle:active {
            cursor: grabbing;
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
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .desktop-table {
                display: none;
            }

            .mobile-cards {
                display: flex;
                flex-direction: column;
                gap: 0.8rem;
            }

            .product-card-item {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 0.8rem;
                border-left: 4px solid #cc0000;
            }

            .product-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0;
                border-bottom: 1px solid #eee;
            }

            .product-label {
                font-weight: 600;
                color: #555;
                font-size: 0.75rem;
            }

            .product-value {
                font-weight: 500;
                color: #333;
                font-size: 0.85rem;
            }

            .product-actions {
                margin-top: 0.8rem;
                display: flex;
                gap: 0.5rem;
                justify-content: flex-end;
                flex-wrap: wrap;
            }

            .export-bar {
                flex-direction: column;
            }

            .date-filter {
                width: 100%;
            }

            .sortable-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .product-info-drag {
                width: 100%;
            }
        }

        @media (min-width: 769px) {
            .mobile-cards {
                display: none;
            }

            .desktop-table {
                display: block;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }

            th {
                background: #f8f9fa;
                font-weight: 600;
                color: #333;
            }
        }

        .status-pending {
            background: #ffc107;
            color: #333;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-paid {
            background: #17a2b8;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-delivered {
            background: #007bff;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-cancelled {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
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
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
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
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .modal-buttons {
            display: flex;
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            flex: 1;
        }

        .btn-save {
            background: #cc0000;
            color: white;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            flex: 1;
        }

        .status-select {
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 0.75rem;
        }

        .reorder-notice {
            background: #e8f4f8;
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            font-size: 0.7rem;
            color: #0c5460;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Delivery info tooltip */
        .delivery-info {
            font-size: 0.65rem;
            color: #666;
            margin-top: 3px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-top">
            <div class="logo-section">
                <div class="logo-icon">
                    <span>⚔️</span>
                </div>
                <div>
                    <h1>FAITH TABERNACLE SECURITY</h1>
                    <div class="header-subtitle">Admin Control Panel</div>
                </div>
            </div>
            <a href="admin_logout.php" class="logout-btn">🚪 Logout</a>
        </div>
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-number"><?= $totalOrders ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $pendingOrdersCount ?></div>
            <div class="stat-label">Pending Payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $completedOrdersCount ?></div>
            <div class="stat-label">Payment Confirmed</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $deliveredOrdersCount ?></div>
            <div class="stat-label">Delivered</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">₦<?= number_format($completedRevenue) ?></div>
            <div class="stat-label">Revenue (Completed+Delivered)</div>
            <div class="revenue-info">✓ Only confirmed payments</div>
        </div>
    </div>

    <div class="container">
        <!-- Export Section -->
        <div class="export-bar">
            <form method="GET" class="date-filter" id="filterForm">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= $startDateFilter ?>" id="startDate">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= $endDateFilter ?>" id="endDate">
                </div>
                <button type="submit" class="btn-primary btn-filter">🔍 Apply Filter</button>
                <a href="admin.php" class="btn-export btn-clear" style="text-decoration: none;">🗑️ Clear</a>
            </form>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="?export=csv&start_date=<?= $startDateFilter ?>&end_date=<?= $endDateFilter ?>" class="btn-export btn-export-csv" style="text-decoration: none;">📊 Export CSV</a>
                <a href="?export=pdf&start_date=<?= $startDateFilter ?>&end_date=<?= $endDateFilter ?>" class="btn-export btn-export-pdf" style="text-decoration: none;">📄 Export PDF</a>
            </div>
        </div>

        <!-- Products Section with Drag and Drop Reordering -->
        <div class="section">
            <div class="section-header">
                <h2>📦 Products Management</h2>
                <button class="btn-primary" onclick="showAddProductModal()">+ Add Product</button>
            </div>

            <div class="reorder-notice">
                🔄 <strong>Drag and Drop to Reorder Products</strong> - Simply drag the ☰ icon next to any product to rearrange the order. Click "Save Order" when done.
            </div>

            <!-- Drag and Drop Sortable List (Desktop & Mobile friendly) -->
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
                                <span style="color: #cc0000;">₦<?= number_format($product['price'], 0) ?></span>
                                <?php if ($product['has_custom_price']): ?>
                                    <span style="background: #fff0f0; padding: 2px 6px; border-radius: 12px; font-size: 0.65rem;">💝 Custom Price</span>
                                <?php endif; ?>
                            </div>
                            <div class="product-actions">
                                <button type="button" class="btn-edit" onclick='editProduct(<?= json_encode($product) ?>)'>✏️ Edit</button>
                                <button type="button" class="btn-delete" onclick="deleteProduct(<?= $product['id'] ?>)">🗑️ Del</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div style="margin-top: 1rem; text-align: right;">
                    <button type="button" class="btn-primary btn-save-order" onclick="saveProductOrder()">💾 Save Product Order</button>
                </div>
            </form>

            <!-- Desktop Table View (Alternative view) -->
            <div style="margin-top: 2rem;">
                <details>
                    <summary style="cursor: pointer; color: #cc0000; font-weight: 600; margin-bottom: 0.5rem;">📋 View as Table</summary>
                    <div class="desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Sort Order</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Custom</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?= $product['id'] ?></span></td>
                                        <td><?= $product['sort_order'] ?></span></td>
                                        <td><?= htmlspecialchars($product['name']) ?></span></td>
                                        <td>₦<?= number_format($product['price'], 0) ?></span></td>
                                        <td><?= $product['has_custom_price'] ? '✅' : '❌' ?></span></td>
                                        <td>
                                            <button class="btn-edit" onclick='editProduct(<?= json_encode($product) ?>)'>✏️ Edit</button>
                                            <button class="btn-delete" onclick="deleteProduct(<?= $product['id'] ?>)">🗑️ Del</button>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>

        <!-- Orders Section -->
        <div class="section">
            <div class="section-header">
                <h2>📋 Orders</h2>
            </div>

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
                            <th>Delivered Info</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= htmlspecialchars($order['order_number']) ?></span></span></td>
                                <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></span></span></span></td>
                                <td><?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></span></span></span></td>
                                <td><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></span></span></span></td>
                                <td>₦<?= number_format($order['total_amount'], 0) ?></span></span></td>
                                <td>
                                    <?php if ($order['status'] == 'pending'): ?>
                                        <span class="status-pending">Pending</span>
                                    <?php elseif ($order['status'] == 'paid'): ?>
                                        <span class="status-paid">Paid</span>
                                    <?php elseif ($order['status'] == 'completed'): ?>
                                        <span class="status-completed">Completed</span>
                                    <?php elseif ($order['status'] == 'delivered'): ?>
                                        <span class="status-delivered">Delivered ✓</span>
                                    <?php elseif ($order['status'] == 'cancelled'): ?>
                                        <span class="status-cancelled">Cancelled</span>
                                    <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($order['status'] == 'delivered' && $order['delivered_at']): ?>
                                        <div style="font-size: 0.7rem;">
                                            By: <?= htmlspecialchars($order['delivered_by'] ?? 'Admin') ?><br>
                                            On: <?= date('d/m/y H:i', strtotime($order['delivered_at'])) ?>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/y', strtotime($order['created_at'])) ?></span></td>
                                <td>
                                    <?php if ($order['status'] == 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="confirm_payment">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn-success" onclick="return confirm('Confirm payment for order <?= htmlspecialchars($order['order_number']) ?>?')">
                                                ✅ Confirm
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($order['status'] == 'completed'): ?>
                                        <button class="btn-delivered" onclick="showDeliveryModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')">
                                            📦 Mark Delivered
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn-view" onclick="viewOrder(<?= $order['id'] ?>)">View</button>

                                    <?php if ($order['status'] != 'delivered' && $order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <button type="submit" class="btn-delete" onclick="return confirm('Cancel this order?')">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-cards">
                <?php foreach ($orders as $order): ?>
                    <div class="product-card-item">
                        <div class="product-row">
                            <span class="product-label">Order #</span>
                            <span class="product-value"><strong><?= htmlspecialchars($order['order_number']) ?></strong></span>
                        </div>
                        <div class="product-row">
                            <span class="product-label">Customer</span>
                            <span class="product-value"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></span>
                        </div>
                        <div class="product-row">
                            <span class="product-label">Command</span>
                            <span class="product-value"><?= htmlspecialchars($order['customer_command'] ?: 'N/A') ?></span>
                        </div>
                        <div class="product-row">
                            <span class="product-label">Total</span>
                            <span class="product-value">₦<?= number_format($order['total_amount'], 0) ?></span>
                        </div>
                        <div class="product-row">
                            <span class="product-label">Status</span>
                            <span class="product-value">
                                <?php if ($order['status'] == 'pending'): ?>
                                    <span class="status-pending">Pending</span>
                                <?php elseif ($order['status'] == 'paid'): ?>
                                    <span class="status-paid">Paid</span>
                                <?php elseif ($order['status'] == 'completed'): ?>
                                    <span class="status-completed">Completed</span>
                                <?php elseif ($order['status'] == 'delivered'): ?>
                                    <span class="status-delivered">Delivered ✓</span>
                                <?php elseif ($order['status'] == 'cancelled'): ?>
                                    <span class="status-cancelled">Cancelled</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($order['status'] == 'delivered' && $order['delivered_at']): ?>
                            <div class="product-row">
                                <span class="product-label">Delivered By</span>
                                <span class="product-value"><?= htmlspecialchars($order['delivered_by'] ?? 'Admin') ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="product-actions">
                            <?php if ($order['status'] == 'pending'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="confirm_payment">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="btn-success" onclick="return confirm('Confirm payment for order <?= htmlspecialchars($order['order_number']) ?>?')">
                                        ✅ Confirm
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($order['status'] == 'completed'): ?>
                                <button class="btn-delivered" onclick="showDeliveryModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')">
                                    📦 Mark Delivered
                                </button>
                            <?php endif; ?>

                            <button class="btn-view" onclick="viewOrder(<?= $order['id'] ?>)">View</button>

                            <?php if ($order['status'] != 'delivered' && $order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_order_status">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn-delete" onclick="return confirm('Cancel this order?')">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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
                    <label>
                        <input type="checkbox" name="has_custom_price" id="productCustomPrice" value="1">
                        Custom Price (Love Seed type - user enters any amount)
                    </label>
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
            <h3>📦 Mark Order as Delivered</h3>
            <form method="POST" id="deliveryForm">
                <input type="hidden" name="action" value="mark_delivered">
                <input type="hidden" name="order_id" id="deliveryOrderId">
                <div class="form-group">
                    <label>Order Number</label>
                    <input type="text" id="deliveryOrderNumber" readonly style="background: #f0f0f0;">
                </div>
                <div class="form-group">
                    <label>Delivered By (Your Name/ID)</label>
                    <input type="text" name="delivered_by" id="deliveredByName" required placeholder="Enter your name or staff ID">
                </div>
                <div class="form-group">
                    <label>Delivery Notes (Optional)</label>
                    <textarea name="delivery_notes" id="deliveryNotes" rows="2" placeholder="Any additional notes about delivery..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeDeliveryModal()">Cancel</button>
                    <button type="submit" class="btn-save">✅ Confirm Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let sortableInstance = null;

        // Initialize SortableJS on page load
        document.addEventListener('DOMContentLoaded', function() {
            const list = document.getElementById('sortable-list');
            if (list) {
                sortableInstance = new Sortable(list, {
                    animation: 300,
                    handle: '.drag-handle',
                    ghostClass: 'dragging',
                    onEnd: function() {
                        // Update the position badges after drag
                        updatePositionBadges();
                    }
                });
            }
        });

        function updatePositionBadges() {
            const items = document.querySelectorAll('#sortable-list .sortable-item');
            items.forEach((item, index) => {
                const badge = item.querySelector('.drag-badge');
                if (badge) {
                    badge.textContent = '#' + (index + 1);
                }
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
            if (confirm('Are you sure you want to delete this product?')) {
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

        // Delivery modal functions
        function showDeliveryModal(orderId, orderNumber) {
            document.getElementById('deliveryOrderId').value = orderId;
            document.getElementById('deliveryOrderNumber').value = orderNumber;
            document.getElementById('deliveredByName').value = '';
            document.getElementById('deliveryNotes').value = '';
            document.getElementById('deliveryModal').style.display = 'flex';
        }

        function closeDeliveryModal() {
            document.getElementById('deliveryModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target === document.getElementById('productModal')) {
                closeModal();
            }
            if (event.target === document.getElementById('deliveryModal')) {
                closeDeliveryModal();
            }
        }
    </script>
</body>

</html>