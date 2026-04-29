<?php
header('Content-Type: application/json');
require_once 'config.php';

global $conn;

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . ($conn->connect_error ?? 'Unknown')]);
    exit();
}

// Check if announcements table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'announcements'");
$tableExists = $tableCheck->num_rows > 0;

// Get table structure if exists
$columns = [];
if ($tableExists) {
    $columnsResult = $conn->query("DESCRIBE announcements");
    while ($row = $columnsResult->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}

echo json_encode([
    'success' => true,
    'connection' => 'OK',
    'table_exists' => $tableExists,
    'columns' => $columns,
    'server_info' => $conn->server_info
]);
