<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : '';
$command = isset($_GET['command']) ? $conn->real_escape_string($_GET['command']) : '';

$sql = "SELECT a.*, 
        m.first_name, m.last_name, m.id_number, m.command,
        s.service_name, s.service_date
        FROM attendance a
        JOIN members m ON a.member_id = m.id
        JOIN services s ON a.service_id = s.id
        WHERE 1=1";

if ($start_date) {
    $sql .= " AND DATE(a.attendance_time) >= '$start_date'";
}
if ($end_date) {
    $sql .= " AND DATE(a.attendance_time) <= '$end_date'";
}
if ($command && $command != 'All Commands') {
    $sql .= " AND m.command = '$command'";
}

$sql .= " ORDER BY a.attendance_time DESC";

$result = $conn->query($sql);
$attendance = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance[] = $row;
    }
    echo json_encode(['success' => true, 'attendance' => $attendance]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
