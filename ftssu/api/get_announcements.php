<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$sql = "SELECT * FROM announcements ORDER BY is_pinned DESC, created_at DESC";
$result = $conn->query($sql);
$announcements = [];

while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}

echo json_encode(['success' => true, 'announcements' => $announcements]);
$conn->close();
