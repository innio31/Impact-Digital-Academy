<?php
// api/get_schools.php - Returns list of active schools with their classes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$response = ['success' => false, 'schools' => [], 'message' => ''];

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Get all active schools
    $stmt = $conn->prepare("
        SELECT id, school_code, school_name, school_logo, school_address 
        FROM schools 
        WHERE status = 'active' 
        ORDER BY school_name ASC
    ");
    $stmt->execute();
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each school, get its classes
    foreach ($schools as &$school) {
        $stmt2 = $conn->prepare("
            SELECT id, class_name, class_code, class_category 
            FROM school_classes 
            WHERE school_id = ? AND status = 'active'
            ORDER BY 
                CASE 
                    WHEN class_category = 'Primary' THEN 1
                    WHEN class_category = 'Junior Secondary' THEN 2
                    WHEN class_category = 'Senior Secondary' THEN 3
                    ELSE 4
                END,
                class_name ASC
        ");
        $stmt2->execute([$school['id']]);
        $school['classes'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Remove id from response (keep school_code as identifier)
        unset($school['id']);
    }

    $response['success'] = true;
    $response['schools'] = $schools;
    $response['message'] = 'Schools retrieved successfully';
} catch (PDOException $e) {
    error_log("get_schools.php error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
    http_response_code(500);
} catch (Exception $e) {
    error_log("get_schools.php error: " . $e->getMessage());
    $response['message'] = 'An error occurred';
    http_response_code(500);
}

echo json_encode($response);
