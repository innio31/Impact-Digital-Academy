<?php
// api/get_result.php - Get detailed result information
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';

$result_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($result_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid result ID']);
    exit();
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT r.*, 
               s.full_name as student_name, 
               s.admission_number, 
               s.class,
               sc.school_name
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN schools sc ON r.school_id = sc.id
        WHERE r.id = ?
    ");
    $stmt->execute([$result_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Result not found']);
        exit();
    }

    // Parse result data
    $result['scores'] = json_decode($result['result_data'], true);
    $result['affective_traits'] = json_decode($result['affective_traits'], true);
    $result['psychomotor_skills'] = json_decode($result['psychomotor_skills'], true);

    // Format total_marks if it's a string like "450/600"
    if (is_string($result['total_marks']) && strpos($result['total_marks'], '/') !== false) {
        // Keep as is
    } elseif (is_numeric($result['total_marks'])) {
        $result['total_marks'] = $result['total_marks'] . ' marks';
    }

    echo json_encode(['success' => true, 'result' => $result]);
} catch (PDOException $e) {
    error_log("get_result.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
