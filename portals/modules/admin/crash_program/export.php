<?php
// modules/admin/crash_program/export.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$status = $_GET['status'] ?? 'all';

// Build query
$sql = "SELECT 
            id, 
            first_name, 
            last_name, 
            email, 
            phone, 
            program_choice, 
            school_name, 
            school_class, 
            is_student, 
            address, 
            city, 
            state, 
            how_heard, 
            payment_status, 
            transaction_reference, 
            payment_amount, 
            registered_at, 
            payment_confirmed_at 
        FROM crash_program_registrations 
        WHERE 1=1";

if ($status === 'confirmed') {
    $sql .= " AND payment_status = 'confirmed'";
}

$sql .= " ORDER BY registered_at DESC";

$result = $conn->query($sql);
$registrations = $result->fetch_all(MYSQLI_ASSOC);

// Set headers for CSV download
$filename = 'crash_program_registrations_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Headers
fputcsv($output, [
    'ID',
    'First Name',
    'Last Name',
    'Email',
    'Phone',
    'Program',
    'School',
    'Class',
    'Is Student',
    'Address',
    'City',
    'State',
    'How Heard',
    'Payment Status',
    'Transaction Ref',
    'Amount Paid',
    'Registration Date',
    'Payment Confirmed Date'
]);

// Data rows
foreach ($registrations as $reg) {
    fputcsv($output, [
        $reg['id'],
        $reg['first_name'],
        $reg['last_name'],
        $reg['email'],
        $reg['phone'],
        $reg['program_choice'] === 'web_development' ? 'Web Development' : 'AI Faceless Video',
        $reg['school_name'],
        $reg['school_class'],
        $reg['is_student'] ? 'Yes' : 'No',
        $reg['address'],
        $reg['city'],
        $reg['state'],
        $reg['how_heard'],
        $reg['payment_status'],
        $reg['transaction_reference'],
        $reg['payment_amount'],
        $reg['registered_at'],
        $reg['payment_confirmed_at']
    ]);
}

fclose($output);
$conn->close();
exit();
