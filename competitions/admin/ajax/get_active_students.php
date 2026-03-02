<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Get active students (students who have submitted answers in the last 5 minutes)
$result = $conn->query("
    SELECT DISTINCT s.*, MAX(a.submission_time) as last_activity 
    FROM students s 
    INNER JOIN answers a ON s.id = a.student_id 
    WHERE a.submission_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    GROUP BY s.id
    ORDER BY last_activity DESC
");

$active_students = [];
while ($row = $result->fetch_assoc()) {
    $active_students[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'login_code' => $row['login_code']
    ];
}

echo json_encode([
    'count' => count($active_students),
    'students' => $active_students
]);
