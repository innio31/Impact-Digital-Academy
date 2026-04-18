<?php
header('Content-Type: application/json');
include 'db_connect.php';

$query = "SELECT * FROM announcements ORDER BY is_pinned DESC, created_at DESC";
$result = mysqli_query($conn, $query);

$announcements = [];
while ($row = mysqli_fetch_assoc($result)) {
    $announcements[] = $row;
}

echo json_encode(['success' => true, 'announcements' => $announcements]);
