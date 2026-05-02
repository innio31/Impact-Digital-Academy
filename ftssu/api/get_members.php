<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'cors.php';
include 'db_connect.php';

// Add status to the SELECT query
$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, gender, 
        phone_number, email, profile_picture, date_of_birth, date_joined, is_active,
        status, attendance_percentage, last_attendance_date
        FROM members 
        ORDER BY created_at DESC";

$result = $conn->query($sql);
$members = [];

while ($row = $result->fetch_assoc()) {
    // Ensure profile picture has full URL
    if ($row['profile_picture'] && !str_starts_with($row['profile_picture'], 'http')) {
        $row['profile_picture'] = 'https://impactdigitalacademy.com.ng/ftssu/api/' . $row['profile_picture'];
    }
    $members[] = $row;
}

echo json_encode(['success' => true, 'members' => $members]);
$conn->close();
