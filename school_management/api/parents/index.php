<?php
// api/parents/index.php - GET all parents, POST create parent
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once 'cors.php';

$user_id = validateToken();

if (!$user_id) {
    exit();
}

$database = new Database();
$db = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $query = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.is_active,
              p.occupation, p.relationship,
              GROUP_CONCAT(CONCAT(s.first_name, ' ', s.last_name) SEPARATOR ', ') as children
              FROM users u
              LEFT JOIN parents p ON u.id = p.user_id
              LEFT JOIN parent_student_links psl ON p.id = psl.parent_id
              LEFT JOIN students s ON psl.student_id = s.id
              WHERE u.role = 'parent' AND u.school_id = 1
              GROUP BY u.id
              ORDER BY u.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess($parents);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $required = ['first_name', 'last_name', 'email', 'password', 'phone'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendError("$field is required", 400);
        }
    }

    $db->beginTransaction();

    try {
        $password_hash = hashPassword($data['password']);

        $user_query = "INSERT INTO users (school_id, role, first_name, last_name, email, phone, password_hash, is_active)
                       VALUES (1, 'parent', :first_name, :last_name, :email, :phone, :password_hash, 1)";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':first_name', $data['first_name']);
        $user_stmt->bindParam(':last_name', $data['last_name']);
        $user_stmt->bindParam(':email', $data['email']);
        $user_stmt->bindParam(':phone', $data['phone']);
        $user_stmt->bindParam(':password_hash', $password_hash);
        $user_stmt->execute();

        $user_id = $db->lastInsertId();

        $parent_query = "INSERT INTO parents (user_id, school_id, occupation, relationship)
                         VALUES (:user_id, 1, :occupation, :relationship)";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':user_id', $user_id);
        $parent_stmt->bindParam(':occupation', $data['occupation']);
        $parent_stmt->bindParam(':relationship', $data['relationship']);
        $parent_stmt->execute();

        $db->commit();

        sendSuccess(['id' => $user_id], "Parent created successfully");
    } catch (Exception $e) {
        $db->rollBack();
        sendError("Failed to create parent: " . $e->getMessage(), 500);
    }
}
