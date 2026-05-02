<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
require_once 'cors.php';
include 'db_connect.php';

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

// Add status and attendance fields to SELECT
$sql = "SELECT *, status, attendance_percentage, last_attendance_date FROM members WHERE id = $id";
$result = $conn->query($sql);
$member = $result->fetch_assoc();

if ($member) {
    // Ensure profile picture has full URL
    if ($member['profile_picture'] && !str_starts_with($member['profile_picture'], 'http')) {
        $member['profile_picture'] = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $member['profile_picture'];
    }
    echo json_encode(['success' => true, 'member' => $member]);
} else {
    echo json_encode(['success' => false, 'error' => 'Member not found']);
}

$conn->close();
