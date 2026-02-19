<?php
// modules/admin/schools/export.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
$sql = "SELECT s.*, 
               COUNT(p.id) as program_count,
               COUNT(u.id) as user_count
        FROM schools s
        LEFT JOIN programs p ON s.id = p.school_id
        LEFT JOIN users u ON s.id = u.school_id";
        
$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(s.name LIKE ? OR s.contact_person LIKE ? OR s.contact_email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if (!empty($status) && $status !== 'all') {
    $where[] = "s.partnership_status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " GROUP BY s.id ORDER BY s.name";

// Execute query
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$schools = $result->fetch_all(MYSQLI_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=impact_academy_schools_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// Add CSV headers
$headers = [
    'ID',
    'School Name',
    'Short Name',
    'Address',
    'City',
    'State',
    'Country',
    'Contact Person',
    'Contact Email',
    'Contact Phone',
    'Partnership Status',
    'Partnership Start Date',
    'Programs Count',
    'Users Count',
    'Notes',
    'Created At',
    'Updated At'
];
fputcsv($output, $headers);

// Add data rows
foreach ($schools as $school) {
    $row = [
        $school['id'],
        $school['name'],
        $school['short_name'] ?? '',
        $school['address'] ?? '',
        $school['city'] ?? '',
        $school['state'] ?? '',
        $school['country'] ?? 'Nigeria',
        $school['contact_person'] ?? '',
        $school['contact_email'] ?? '',
        $school['contact_phone'] ?? '',
        $school['partnership_status'],
        $school['partnership_start_date'] ?? '',
        $school['program_count'],
        $school['user_count'],
        str_replace(["\r\n", "\r", "\n"], ' ', $school['notes'] ?? ''),
        $school['created_at'],
        $school['updated_at']
    ];
    fputcsv($output, $row);
}

// Close output stream
fclose($output);

// Log activity
logActivity('schools_export', 'Exported schools to CSV', 'schools');

exit();