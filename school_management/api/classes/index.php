<?php

// api/classes/index.php - GET all classes and arms
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';


$user_id = validateToken();

if (!$user_id) {
    exit();
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $query = "SELECT c.id, c.name, c.order as level_order,
              sl.name as level_name, sl.grading_type,
              GROUP_CONCAT(
                  JSON_OBJECT('id', ca.id, 'name', ca.name, 'form_teacher_id', ca.form_teacher_id)
              ) as arms
              FROM classes c
              LEFT JOIN school_levels sl ON c.level_id = sl.id
              LEFT JOIN class_arms ca ON c.id = ca.class_id
              WHERE c.school_id = 1
              GROUP BY c.id
              ORDER BY c.order ASC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse arms JSON
    foreach ($classes as &$class) {
        if ($class['arms']) {
            $class['arms'] = json_decode('[' . $class['arms'] . ']', true);
        } else {
            $class['arms'] = [];
        }
    }

    sendSuccess($classes);
}
