<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'sql110.infinityfree.com';
$user = 'if0_40361629';
$password = 'YOUR_DB_PASSWORD'; // Replace with your actual database password
$database = 'if0_40361629_ftacct';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");
