<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$command = isset($_GET['command']) ? $_GET['command'] : '';
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id) {
    // Get single report with all details
    $stmt = $conn->prepare("
        SELECT * FROM weekly_activity_reports WHERE id = ?
    ");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();

    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit;
    }

    // Get relocated members
    $relocate_stmt = $conn->prepare("
        SELECT * FROM relocated_members WHERE report_id = ?
    ");
    $relocate_stmt->bind_param("i", $report_id);
    $relocate_stmt->execute();
    $relocated = $relocate_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get left members
    $left_stmt = $conn->prepare("
        SELECT * FROM left_lfc_members WHERE report_id = ?
    ");
    $left_stmt->bind_param("i", $report_id);
    $left_stmt->execute();
    $left = $left_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get WSF attendance
    $wsf_stmt = $conn->prepare("
        SELECT * FROM wsf_attendance WHERE report_id = ?
    ");
    $wsf_stmt->bind_param("i", $report_id);
    $wsf_stmt->execute();
    $wsf = $wsf_stmt->get_result()->fetch_assoc();

    echo json_encode([
        'success' => true,
        'report' => $report,
        'relocated_members' => $relocated,
        'left_members' => $left,
        'wsf_attendance' => $wsf
    ]);
} else {
    // Get all reports for a command
    $stmt = $conn->prepare("
        SELECT id, report_date, type_of_service, location_served, 
               service_1_attendance, service_2_attendance, service_3_attendance,
               average_attendance, created_at
        FROM weekly_activity_reports 
        WHERE command_name = ?
        ORDER BY report_date DESC
        LIMIT 20
    ");
    $stmt->bind_param("s", $command);
    $stmt->execute();
    $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);
}

$conn->close();
