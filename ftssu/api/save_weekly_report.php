<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$required_fields = ['command_name', 'submitted_by_member_id', 'submitted_by_name', 'report_date', 'type_of_service'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
    ]);
    exit;
}

// Begin transaction
$conn->begin_transaction();

try {
    // Prepare tools_deployed JSON
    $tools_deployed = isset($data['tools_deployed']) ? json_encode($data['tools_deployed']) : '{}';

    // Calculate average attendance
    $service_1 = isset($data['service_1_attendance']) ? (int)$data['service_1_attendance'] : 0;
    $service_2 = isset($data['service_2_attendance']) ? (int)$data['service_2_attendance'] : 0;
    $service_3 = isset($data['service_3_attendance']) ? (int)$data['service_3_attendance'] : 0;

    $total_services = 0;
    $total_attendance = 0;

    if ($service_1 > 0) {
        $total_services++;
        $total_attendance += $service_1;
    }
    if ($service_2 > 0) {
        $total_services++;
        $total_attendance += $service_2;
    }
    if ($service_3 > 0) {
        $total_services++;
        $total_attendance += $service_3;
    }

    $average_attendance = $total_services > 0 ? round(($total_attendance / $total_services), 2) : 0;

    // Insert main report
    $stmt = $conn->prepare("
        INSERT INTO weekly_activity_reports 
        (report_date, command_name, submitted_by_member_id, submitted_by_name, 
         type_of_service, location_served, command_strength, 
         service_1_attendance, service_2_attendance, service_3_attendance, 
         average_attendance, tools_deployed, incident_report, recommendations, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted')
    ");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $report_date = $data['report_date'];
    $command_name = $data['command_name'];
    $submitted_by_member_id = (int)$data['submitted_by_member_id'];
    $submitted_by_name = $data['submitted_by_name'];
    $type_of_service = $data['type_of_service'];
    $location_served = isset($data['location_served']) ? $data['location_served'] : '';
    $command_strength = isset($data['command_strength']) ? (int)$data['command_strength'] : 0;
    $incident_report = isset($data['incident_report']) ? $data['incident_report'] : '';
    $recommendations = isset($data['recommendations']) ? $data['recommendations'] : '';

    $stmt->bind_param(
        "ssisssiiiidsss",
        $report_date,
        $command_name,
        $submitted_by_member_id,
        $submitted_by_name,
        $type_of_service,
        $location_served,
        $command_strength,
        $service_1,
        $service_2,
        $service_3,
        $average_attendance,
        $tools_deployed,
        $incident_report,
        $recommendations
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $report_id = $conn->insert_id;
    $stmt->close();

    // Save relocated members
    if (!empty($data['relocated_members']) && is_array($data['relocated_members'])) {
        $relocate_stmt = $conn->prepare("
            INSERT INTO relocated_members 
            (report_id, member_id, member_name, command_name, new_location, reason, reported_by, relocation_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$relocate_stmt) {
            throw new Exception('Relocate prepare failed: ' . $conn->error);
        }

        foreach ($data['relocated_members'] as $member) {
            $member_id = isset($member['member_id']) ? (int)$member['member_id'] : 0;
            $member_name = isset($member['name']) ? $member['name'] : '';
            $new_location = isset($member['new_location']) ? $member['new_location'] : '';
            $reason = isset($member['reason']) ? $member['reason'] : '';
            $relocation_date = isset($member['relocation_date']) ? $member['relocation_date'] : date('Y-m-d');

            $relocate_stmt->bind_param(
                "iissssss",
                $report_id,
                $member_id,
                $member_name,
                $command_name,
                $new_location,
                $reason,
                $submitted_by_name,
                $relocation_date
            );

            if (!$relocate_stmt->execute()) {
                throw new Exception('Relocate execute failed: ' . $relocate_stmt->error);
            }
        }
        $relocate_stmt->close();
    }

    // Save left LFC members
    if (!empty($data['left_members']) && is_array($data['left_members'])) {
        $left_stmt = $conn->prepare("
            INSERT INTO left_lfc_members 
            (report_id, member_id, member_name, command_name, reason, reported_by, left_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$left_stmt) {
            throw new Exception('Left prepare failed: ' . $conn->error);
        }

        foreach ($data['left_members'] as $member) {
            $member_id = isset($member['member_id']) ? (int)$member['member_id'] : 0;
            $member_name = isset($member['name']) ? $member['name'] : '';
            $reason = isset($member['reason']) ? $member['reason'] : '';
            $left_date = isset($member['left_date']) ? $member['left_date'] : date('Y-m-d');

            $left_stmt->bind_param(
                "iisssss",
                $report_id,
                $member_id,
                $member_name,
                $command_name,
                $reason,
                $submitted_by_name,
                $left_date
            );

            if (!$left_stmt->execute()) {
                throw new Exception('Left execute failed: ' . $left_stmt->error);
            }
        }
        $left_stmt->close();
    }

    // Save WSF attendance
    if (isset($data['wsf_attendance']) && !empty($data['wsf_attendance'])) {
        $wsf_stmt = $conn->prepare("
            INSERT INTO wsf_attendance 
            (report_id, command_name, attendance_date, total_present, recorded_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$wsf_stmt) {
            throw new Exception('WSF prepare failed: ' . $conn->error);
        }

        $wsf_attendance = (int)$data['wsf_attendance'];

        $wsf_stmt->bind_param(
            "issis",
            $report_id,
            $command_name,
            $report_date,
            $wsf_attendance,
            $submitted_by_name
        );

        if (!$wsf_stmt->execute()) {
            throw new Exception('WSF execute failed: ' . $wsf_stmt->error);
        }
        $wsf_stmt->close();
    }

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Report submitted successfully',
        'report_id' => $report_id
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
