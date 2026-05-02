<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

$result = $conn->query("
    SELECT DISTINCT command FROM members WHERE is_active = 1 ORDER BY command
");

$commands = [];
while ($row = $result->fetch_assoc()) {
    $commands[] = $row['command'];
}

echo json_encode([
    'success' => true,
    'commands' => $commands
]);

$conn->close();
