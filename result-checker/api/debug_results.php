<?php
// api/debug_results.php - Check what results are stored
header('Content-Type: application/json');

require_once '../config/config.php';

// Simple auth - require API key in URL for security
$auth_key = $_GET['key'] ?? '';
if ($auth_key !== 'debug123') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Get schools
$stmt = $conn->query("SELECT id, school_name, school_code FROM schools LIMIT 5");
$schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

$response = [
    'schools' => $schools,
    'results' => []
];

foreach ($schools as $school) {
    $stmt = $conn->prepare("
        SELECT r.*, s.full_name, s.admission_number 
        FROM results r
        JOIN students s ON r.student_id = s.id
        WHERE r.school_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$school['id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as &$result) {
        $result['result_data'] = json_decode($result['result_data'], true);
    }

    $response['results'][$school['school_name']] = $results;
}

// Also check if any results exist at all
$stmt = $conn->query("SELECT COUNT(*) as count FROM results");
$total_results = $stmt->fetch(PDO::FETCH_ASSOC);

$response['total_results'] = $total_results['count'];

echo json_encode($response, JSON_PRETTY_PRINT);
