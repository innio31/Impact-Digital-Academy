<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;

if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

$sql = "SELECT a.*, s.service_name, s.service_date 
        FROM attendance a
        JOIN services s ON a.service_id = s.id
        WHERE a.member_id = $member_id
        ORDER BY a.attendance_time DESC
        LIMIT 50";

$result = $conn->query($sql);
$attendance = [];

while ($row = $result->fetch_assoc()) {
    $attendance[] = $row;
}

echo json_encode(['success' => true, 'attendance' => $attendance]);
$conn->close();
