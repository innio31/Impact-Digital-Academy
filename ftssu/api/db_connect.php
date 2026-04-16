<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$user = 'impactdi_result-checker';
$password = 'uenrqFrgYbcY5YmSLTH6'; // Replace with your actual database password
$database = 'impactdi_result-checker';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");
