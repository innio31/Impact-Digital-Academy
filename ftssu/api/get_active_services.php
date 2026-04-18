<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$sql = "SELECT id, service_name, service_date, start_time, end_time 
        FROM services 
        WHERE is_active = 1 AND service_date >= CURDATE() 
        ORDER BY service_date ASC";

$result = $conn->query($sql);
$services = [];

while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}

echo json_encode(['success' => true, 'services' => $services]);
$conn->close();
