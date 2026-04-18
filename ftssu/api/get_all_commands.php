<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$sql = "SELECT DISTINCT command FROM members WHERE command IS NOT NULL AND command != '' ORDER BY command ASC";
$result = $conn->query($sql);
$commands = [];

while ($row = $result->fetch_assoc()) {
    $commands[] = $row['command'];
}

echo json_encode(['success' => true, 'commands' => $commands]);
$conn->close();
