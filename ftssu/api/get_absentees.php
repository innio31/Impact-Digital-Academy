<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;
$command_filter = isset($_GET['command']) ? $_GET['command'] : '';

if (!$service_id) {
    echo json_encode(['success' => false, 'message' => 'Service ID required']);
    exit;
}

// Get service date
$stmt = $conn->prepare("SELECT service_date FROM services WHERE id = ?");
$stmt->bind_param("i", $service_id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();

if (!$service) {
    echo json_encode(['success' => false, 'message' => 'Service not found']);
    exit;
}

$service_date = $service['service_date'];

// Get all active members
$members_query = "
    SELECT 
        id,
        id_number,
        first_name,
        last_name,
        designation,
        command,
        role,
        phone_number
    FROM members 
    WHERE is_active = 1
";

if ($command_filter && $command_filter !== 'All') {
    $members_query .= " AND command = '" . $conn->real_escape_string($command_filter) . "'";
}

$members = $conn->query($members_query);

// Get members who attended this service
$attendance_query = "
    SELECT DISTINCT member_id 
    FROM attendance 
    WHERE service_id = $service_id
";
$attendance_result = $conn->query($attendance_query);
$attended_ids = [];
while ($row = $attendance_result->fetch_assoc()) {
    $attended_ids[] = $row['member_id'];
}

// Find absentees (members who didn't attend)
$absentees = [];
while ($member = $members->fetch_assoc()) {
    if (!in_array($member['id'], $attended_ids)) {
        $absentees[] = [
            'id' => $member['id'],
            'id_number' => $member['id_number'],
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'designation' => $member['designation'],
            'command' => $member['command'],
            'role' => $member['role'],
            'phone_number' => $member['phone_number']
        ];
    }
}

echo json_encode([
    'success' => true,
    'absentees' => $absentees,
    'total_absent' => count($absentees),
    'service_date' => $service_date
]);

$conn->close();
