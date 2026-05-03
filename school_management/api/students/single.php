<?php
// api/students/single.php - GET, PUT, DELETE single student
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

$user_id = validateToken();

if (!$user_id) {
    exit();
}

if (!isset($_GET['id'])) {
    sendError("Student ID is required", 400);
}

$student_id = $_GET['id'];
$method = $_SERVER['REQUEST_METHOD'];

$database = new Database();
$db = $database->getConnection();

if ($method === 'GET') {
    $query = "SELECT * FROM students WHERE id = :id AND school_id = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $student_id);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError("Student not found", 404);
    }

    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    sendSuccess($student);
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);

    $allowed_fields = [
        'first_name',
        'last_name',
        'other_name',
        'date_of_birth',
        'gender',
        'state_of_origin',
        'religion',
        'blood_group',
        'genotype',
        'home_address',
        'is_active'
    ];

    $set_parts = [];
    $params = [':id' => $student_id];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) {
            $set_parts[] = "$key = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($set_parts)) {
        sendError("No valid fields to update", 400);
    }

    $query = "UPDATE students SET " . implode(', ', $set_parts) . " WHERE id = :id";
    $stmt = $db->prepare($query);

    if ($stmt->execute($params)) {
        sendSuccess(null, "Student updated successfully");
    } else {
        sendError("Failed to update student", 500);
    }
} elseif ($method === 'DELETE') {
    $query = "DELETE FROM students WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $student_id);

    if ($stmt->execute()) {
        sendSuccess(null, "Student deleted successfully");
    } else {
        sendError("Failed to delete student", 500);
    }
}
