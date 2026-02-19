<?php
// modules/admin/finance/payments/ajax/mark_completed.php

require_once __DIR__ . '/../../../../../includes/config.php';
require_once __DIR__ . '/../../../../../includes/functions.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

$payment_id = $_POST['payment_id'] ?? 0;
if (!$payment_id) {
    echo json_encode(['error' => 'Payment ID required']);
    exit();
}

$conn = getDBConnection();

// Update payment status
$sql = "UPDATE financial_transactions 
        SET status = 'completed', updated_at = NOW()
        WHERE id = ? AND status = 'pending'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $payment_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    // Log activity
    logActivity($_SESSION['user_id'], 'payment_marked_completed', 
        "Marked payment #$payment_id as completed", 'financial_transactions', $payment_id);
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to update payment status']);
}
?>