<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : null;
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : null;

// Build date filter
$date_filter = "";
if ($start_date && $end_date) {
    $date_filter = " AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
} elseif ($start_date) {
    $date_filter = " AND DATE(created_at) = '$start_date'";
}

// Get total revenue and counts
$revenue_sql = "SELECT 
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COUNT(*) as total_orders,
    SUM(CASE WHEN status = 'goods_delivered' THEN 1 ELSE 0 END) as completed_orders,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders
FROM orders WHERE 1=1 $date_filter";
$revenue_result = $conn->query($revenue_sql);
$revenue_data = $revenue_result->fetch_assoc();

// Get top products
$products_sql = "SELECT 
    oi.product_name,
    SUM(oi.quantity) as total_quantity,
    SUM(oi.total_price) as total_sales
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
WHERE 1=1 $date_filter
GROUP BY oi.product_name
ORDER BY total_sales DESC
LIMIT 10";
$products_result = $conn->query($products_sql);
$top_products = [];
while ($row = $products_result->fetch_assoc()) {
    $top_products[] = $row;
}

// Get daily breakdown
$daily_sql = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as order_count,
    SUM(total_amount) as total
FROM orders
WHERE 1=1 $date_filter
GROUP BY DATE(created_at)
ORDER BY date DESC";
$daily_result = $conn->query($daily_sql);
$daily_breakdown = [];
while ($row = $daily_result->fetch_assoc()) {
    $daily_breakdown[] = $row;
}

echo json_encode([
    'success' => true,
    'total_revenue' => (float)$revenue_data['total_revenue'],
    'total_orders' => (int)$revenue_data['total_orders'],
    'completed_orders' => (int)$revenue_data['completed_orders'],
    'pending_orders' => (int)$revenue_data['pending_orders'],
    'top_products' => $top_products,
    'daily_breakdown' => $daily_breakdown
]);

$conn->close();
