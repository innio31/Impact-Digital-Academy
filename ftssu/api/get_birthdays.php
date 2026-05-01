<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$today = date('m-d'); // e.g. "05-01"

$stmt = $conn->prepare("
    SELECT first_name, last_name, designation, command, profile_picture, date_of_birth
    FROM members
    WHERE DATE_FORMAT(date_of_birth, '%m-%d') = ?
    AND is_active = 1
    ORDER BY first_name ASC
");
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

$birthdays = [];
while ($row = $result->fetch_assoc()) {
    $dob = new DateTime($row['date_of_birth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;

    $birthdays[] = [
        'name'            => $row['designation'] . ' ' . $row['first_name'] . ' ' . $row['last_name'],
        'command'         => $row['command'],
        'profile_picture' => $row['profile_picture'],
        'age'             => $age
    ];
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'birthdays' => $birthdays]);
