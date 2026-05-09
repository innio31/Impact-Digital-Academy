<?php
require_once '../includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

// Total Students
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ?");
$stmt->execute([SCHOOL_ID]);
$totalStudents = $stmt->fetch()['total'];

// Total Staff
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staff WHERE school_id = ?");
$stmt->execute([SCHOOL_ID]);
$totalStaff = $stmt->fetch()['total'];

// Total Exams
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM exams WHERE school_id = ?");
$stmt->execute([SCHOOL_ID]);
$totalExams = $stmt->fetch()['total'];

// Total Revenue
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE school_id = ? AND status = 'verified'");
$stmt->execute([SCHOOL_ID]);
$totalRevenue = $stmt->fetch()['total'] ?? 0;

// Subscription Status
$stmt = $pdo->prepare("SELECT status, end_date FROM subscriptions WHERE school_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([SCHOOL_ID]);
$sub = $stmt->fetch();

sendResponse([
    'success' => true,
    'data' => [
        'totalStudents' => $totalStudents,
        'totalStaff' => $totalStaff,
        'totalExams' => $totalExams,
        'totalRevenue' => $totalRevenue,
        'subscriptionStatus' => $sub['status'] ?? 'inactive',
        'subscriptionEndDate' => $sub['end_date'] ?? null,
        'lastSync' => date('Y-m-d H:i:s')
    ]
]);
?>