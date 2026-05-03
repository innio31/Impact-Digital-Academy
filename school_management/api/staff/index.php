<?php
// api/staff/index.php - GET all staff, POST create staff
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
    $query = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.role, u.is_active,
              sp.staff_id_number, sp.designation, sp.department, sp.qualification, sp.date_employed, sp.is_teaching_staff
              FROM users u
              LEFT JOIN staff_profiles sp ON u.id = sp.user_id
              WHERE u.role IN ('staff', 'admin') AND u.school_id = 1
              ORDER BY u.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess($staff);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $required = ['first_name', 'last_name', 'email', 'password', 'designation'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendError("$field is required", 400);
        }
    }

    $db->beginTransaction();

    try {
        // Create user
        $password_hash = hashPassword($data['password']);
        $role = isset($data['role']) ? $data['role'] : 'staff';

        $user_query = "INSERT INTO users (school_id, role, first_name, last_name, email, phone, password_hash, is_active)
                       VALUES (1, :role, :first_name, :last_name, :email, :phone, :password_hash, 1)";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':role', $role);
        $user_stmt->bindParam(':first_name', $data['first_name']);
        $user_stmt->bindParam(':last_name', $data['last_name']);
        $user_stmt->bindParam(':email', $data['email']);
        $user_stmt->bindParam(':phone', $data['phone']);
        $user_stmt->bindParam(':password_hash', $password_hash);
        $user_stmt->execute();

        $user_id = $db->lastInsertId();

        // Create staff profile
        $staff_query = "INSERT INTO staff_profiles (user_id, school_id, staff_id_number, designation, department, qualification, date_employed, is_teaching_staff)
                        VALUES (:user_id, 1, :staff_id_number, :designation, :department, :qualification, NOW(), :is_teaching_staff)";
        $staff_stmt = $db->prepare($staff_query);

        $staff_id_number = isset($data['staff_id_number']) ? $data['staff_id_number'] : 'STF' . date('Y') . $user_id;
        $is_teaching = isset($data['is_teaching_staff']) ? $data['is_teaching_staff'] : 1;

        $staff_stmt->bindParam(':user_id', $user_id);
        $staff_stmt->bindParam(':staff_id_number', $staff_id_number);
        $staff_stmt->bindParam(':designation', $data['designation']);
        $staff_stmt->bindParam(':department', $data['department']);
        $staff_stmt->bindParam(':qualification', $data['qualification']);
        $staff_stmt->bindParam(':is_teaching_staff', $is_teaching);
        $staff_stmt->execute();

        $db->commit();

        sendSuccess(['id' => $user_id], "Staff created successfully");
    } catch (Exception $e) {
        $db->rollBack();
        sendError("Failed to create staff: " . $e->getMessage(), 500);
    }
}
