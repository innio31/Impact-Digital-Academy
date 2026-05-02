<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'cors.php';
include 'db_connect.php';

$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$member_id) {
    echo json_encode(['success' => false, 'message' => 'Member ID required']);
    exit;
}

// Get member basic info
$stmt = $conn->prepare("
    SELECT 
        m.*,
        DATEDIFF(CURDATE(), m.date_joined) as days_as_member
    FROM members m 
    WHERE m.id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit;
}

// Ensure profile picture has full URL
if ($member['profile_picture'] && !str_starts_with($member['profile_picture'], 'http')) {
    $member['profile_picture'] = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $member['profile_picture'];
}

// Calculate attendance statistics
$last_90_days = date('Y-m-d', strtotime('-90 days'));
$last_30_days = date('Y-m-d', strtotime('-30 days'));
$last_6_months = date('Y-m-d', strtotime('-6 months'));

// Total services in last 90 days and attendance percentage
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_services,
        SUM(CASE WHEN a.member_id IS NOT NULL THEN 1 ELSE 0 END) as attended_count,
        ROUND((SUM(CASE WHEN a.member_id IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
    FROM services s
    LEFT JOIN attendance a ON a.service_id = s.id AND a.member_id = ?
    WHERE s.service_date >= ? AND s.service_date <= CURDATE()
");
$stmt->bind_param("is", $member_id, $last_90_days);
$stmt->execute();
$attendance_stats = $stmt->get_result()->fetch_assoc();

// Last attendance date
$stmt = $conn->prepare("
    SELECT MAX(attendance_time) as last_attendance
    FROM attendance
    WHERE member_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$last_attendance = $stmt->get_result()->fetch_assoc();

// Monthly attendance breakdown (last 6 months)
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(s.service_date, '%Y-%m') as month,
        COUNT(*) as total_services,
        SUM(CASE WHEN a.member_id IS NOT NULL THEN 1 ELSE 0 END) as attended
    FROM services s
    LEFT JOIN attendance a ON a.service_id = s.id AND a.member_id = ?
    WHERE s.service_date >= ?
    GROUP BY DATE_FORMAT(s.service_date, '%Y-%m')
    ORDER BY month DESC
");
$stmt->bind_param("is", $member_id, $last_6_months);
$stmt->execute();
$monthly_attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate days since last attendance
$days_since_last = null;
if ($last_attendance['last_attendance']) {
    $last_date = new DateTime($last_attendance['last_attendance']);
    $now = new DateTime();
    $days_since_last = $now->diff($last_date)->days;
}

$response = [
    'success' => true,
    'member' => $member,
    'attendance_stats' => [
        'total_services_90days' => (int)($attendance_stats['total_services'] ?? 0),
        'attended_count' => (int)($attendance_stats['attended_count'] ?? 0),
        'attendance_percentage' => floatval($attendance_stats['attendance_percentage'] ?? 0),
        'last_attendance' => $last_attendance['last_attendance'],
        'days_since_last_attendance' => $days_since_last
    ],
    'monthly_attendance' => $monthly_attendance
];

$stmt->close();
$conn->close();

echo json_encode($response);
