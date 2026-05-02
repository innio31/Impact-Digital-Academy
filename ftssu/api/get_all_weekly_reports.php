<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$command = isset($_GET['command']) ? $_GET['command'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$query = "
    SELECT id, report_date, command_name, type_of_service, 
           service_1_attendance, service_2_attendance, service_3_attendance,
           average_attendance, submitted_by_name, created_at
    FROM weekly_activity_reports 
    WHERE 1=1
";

$params = [];
$types = "";

if ($command && $command !== 'All') {
    $query .= " AND command_name = ?";
    $params[] = $command;
    $types .= "s";
}

if ($start_date) {
    $query .= " AND report_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if ($end_date) {
    $query .= " AND report_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$query .= " ORDER BY report_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'reports' => $reports,
    'total' => count($reports)
]);

$conn->close();
