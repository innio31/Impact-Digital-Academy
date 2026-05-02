<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
require_once 'cors.php';
include 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['member_id']) || !isset($data['status'])) {
    echo json_encode(['success' => false, 'message' => 'Member ID and status required']);
    exit;
}

$member_id = intval($data['member_id']);
$status = $data['status'];
$valid_statuses = ['active', 'inactive', 'not_available', 'revalidation', 'deceased', 'pending'];

if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}

$stmt = $conn->prepare("UPDATE members SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $member_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $conn->error]);
}

$stmt->close();
$conn->close();
