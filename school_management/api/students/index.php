<?php
// api/includes/cors.php
header('Access-Control-Allow-Origin: https://portal.mightyschoolforvalours.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
// api/students/index.php - GET all students, POST create student
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';


$method = $_SERVER['REQUEST_METHOD'];
$user_id = validateToken();

if (!$user_id) {
    exit();
}

$database = new Database();
$db = $database->getConnection();

if ($method === 'GET') {
    // Get all students
    $query = "SELECT s.*, c.name as class_name, ca.name as class_arm_name,
              CONCAT(u.first_name, ' ', u.last_name) as parent_name
              FROM students s
              LEFT JOIN student_class_enrollments sce ON s.id = sce.student_id AND sce.session_id = 
                  (SELECT id FROM sessions WHERE is_current = 1 LIMIT 1)
              LEFT JOIN class_arms ca ON sce.class_arm_id = ca.id
              LEFT JOIN classes c ON ca.class_id = c.id
              LEFT JOIN parent_student_links psl ON s.id = psl.student_id
              LEFT JOIN parents p ON psl.parent_id = p.id
              LEFT JOIN users u ON p.user_id = u.id
              WHERE s.school_id = 1
              ORDER BY s.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess($students);
} elseif ($method === 'POST') {
    // Create new student
    $data = json_decode(file_get_contents("php://input"), true);

    $required = ['first_name', 'last_name', 'gender', 'admission_number', 'date_of_birth'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendError("$field is required", 400);
        }
    }

    $query = "INSERT INTO students (school_id, admission_number, first_name, last_name, other_name, 
              date_of_birth, gender, state_of_origin, religion, blood_group, genotype, 
              home_address, admission_date, is_active)
              VALUES (1, :admission_number, :first_name, :last_name, :other_name, 
              :date_of_birth, :gender, :state_of_origin, :religion, :blood_group, :genotype, 
              :home_address, NOW(), 1)";

    $stmt = $db->prepare($query);

    $stmt->bindParam(':admission_number', $data['admission_number']);
    $stmt->bindParam(':first_name', $data['first_name']);
    $stmt->bindParam(':last_name', $data['last_name']);
    $stmt->bindParam(':other_name', $data['other_name']);
    $stmt->bindParam(':date_of_birth', $data['date_of_birth']);
    $stmt->bindParam(':gender', $data['gender']);
    $stmt->bindParam(':state_of_origin', $data['state_of_origin']);
    $stmt->bindParam(':religion', $data['religion']);
    $stmt->bindParam(':blood_group', $data['blood_group']);
    $stmt->bindParam(':genotype', $data['genotype']);
    $stmt->bindParam(':home_address', $data['home_address']);

    if ($stmt->execute()) {
        $student_id = $db->lastInsertId();
        sendSuccess(['id' => $student_id], "Student created successfully");
    } else {
        sendError("Failed to create student", 500);
    }
}
