<!-- backend/api/students.php -->
<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$request_method = $_SERVER['REQUEST_METHOD'];
$user_data = Auth::authenticate();

switch ($request_method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getStudentById($db, $_GET['id'], $user_data);
        } elseif (isset($_GET['class_id'])) {
            getStudentsByClass($db, $_GET['class_id'], $user_data);
        } else {
            getAllStudents($db, $user_data);
        }
        break;
    case 'POST':
        createStudent($db, $user_data);
        break;
    case 'PUT':
        if (isset($_GET['id'])) {
            updateStudent($db, $_GET['id'], $user_data);
        }
        break;
    case 'DELETE':
        if (isset($_GET['id'])) {
            deleteStudent($db, $_GET['id'], $user_data);
        }
        break;
}

function getAllStudents($db, $user_data)
{
    Auth::checkRole($user_data, ['admin', 'staff']);

    $page = $_GET['page'] ?? 1;
    $limit = $_GET['limit'] ?? 20;
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? '';
    $class_id = $_GET['class_id'] ?? '';

    $query = "SELECT s.*, c.class_name, c.class_code 
              FROM students s
              LEFT JOIN classes c ON s.class_id = c.id
              WHERE s.school_id = :school_id";

    $params = [':school_id' => $user_data['school_id']];

    if ($search) {
        $query .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search OR s.admission_number LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($class_id) {
        $query .= " AND s.class_id = :class_id";
        $params[':class_id'] = $class_id;
    }

    $query .= " ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($query);
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM students WHERE school_id = :school_id";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->bindParam(':school_id', $user_data['school_id']);
    $count_stmt->execute();
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'data' => $students,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

function getStudentById($db, $id, $user_data)
{
    Auth::checkRole($user_data, ['admin', 'staff', 'parent']);

    $query = "SELECT s.*, c.class_name, c.class_code,
                     (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name)) 
                      FROM student_parents sp 
                      JOIN parents p ON sp.parent_id = p.id 
                      JOIN users u ON p.user_id = u.id 
                      WHERE sp.student_id = s.id) as parent_names
              FROM students s
              LEFT JOIN classes c ON s.class_id = c.id
              WHERE s.id = :id AND s.school_id = :school_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':school_id', $user_data['school_id']);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get parent details
        $parent_query = "SELECT p.id, u.first_name, u.last_name, u.email, u.phone, p.relationship
                        FROM student_parents sp
                        JOIN parents p ON sp.parent_id = p.id
                        JOIN users u ON p.user_id = u.id
                        WHERE sp.student_id = :student_id";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':student_id', $id);
        $parent_stmt->execute();
        $student['parents'] = $parent_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $student]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
    }
}

function createStudent($db, $user_data)
{
    Auth::checkRole($user_data, ['admin']);

    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    $required = ['first_name', 'last_name', 'admission_number', 'class_id'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'error' => "$field is required"]);
            return;
        }
    }

    // Check if admission number already exists
    $check_query = "SELECT id FROM students WHERE admission_number = :admission_number AND school_id = :school_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':admission_number', $data['admission_number']);
    $check_stmt->bindParam(':school_id', $user_data['school_id']);
    $check_stmt->execute();

    if ($check_stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'error' => 'Admission number already exists']);
        return;
    }

    // Check student limit based on subscription
    $sub_query = "SELECT current_students, max_students FROM schools WHERE id = :school_id";
    $sub_stmt = $db->prepare($sub_query);
    $sub_stmt->bindParam(':school_id', $user_data['school_id']);
    $sub_stmt->execute();
    $school = $sub_stmt->fetch(PDO::FETCH_ASSOC);

    if ($school['max_students'] && $school['current_students'] >= $school['max_students']) {
        echo json_encode(['success' => false, 'error' => 'Student limit reached. Please upgrade your subscription.']);
        return;
    }

    $query = "INSERT INTO students (school_id, admission_number, first_name, last_name, middle_name, 
              date_of_birth, gender, blood_group, address, phone, emergency_contact, medical_info, 
              class_id, enrollment_date, is_active)
              VALUES (:school_id, :admission_number, :first_name, :last_name, :middle_name,
              :date_of_birth, :gender, :blood_group, :address, :phone, :emergency_contact, 
              :medical_info, :class_id, :enrollment_date, :is_active)";

    $stmt = $db->prepare($query);

    $stmt->bindParam(':school_id', $user_data['school_id']);
    $stmt->bindParam(':admission_number', $data['admission_number']);
    $stmt->bindParam(':first_name', $data['first_name']);
    $stmt->bindParam(':last_name', $data['last_name']);
    $stmt->bindParam(':middle_name', $data['middle_name']);
    $stmt->bindParam(':date_of_birth', $data['date_of_birth']);
    $stmt->bindParam(':gender', $data['gender']);
    $stmt->bindParam(':blood_group', $data['blood_group']);
    $stmt->bindParam(':address', $data['address']);
    $stmt->bindParam(':phone', $data['phone']);
    $stmt->bindParam(':emergency_contact', $data['emergency_contact']);
    $stmt->bindParam(':medical_info', $data['medical_info']);
    $stmt->bindParam(':class_id', $data['class_id']);
    $stmt->bindParam(':enrollment_date', $data['enrollment_date']);
    $is_active = $data['is_active'] ?? true;
    $stmt->bindParam(':is_active', $is_active);

    if ($stmt->execute()) {
        $student_id = $db->lastInsertId();

        // Update school student count
        $update_count = "UPDATE schools SET current_students = current_students + 1 WHERE id = :school_id";
        $count_stmt = $db->prepare($update_count);
        $count_stmt->bindParam(':school_id', $user_data['school_id']);
        $count_stmt->execute();

        // Link parents if provided
        if (!empty($data['parent_ids'])) {
            foreach ($data['parent_ids'] as $parent_id) {
                $link_query = "INSERT INTO student_parents (student_id, parent_id, is_primary) 
                              VALUES (:student_id, :parent_id, :is_primary)";
                $link_stmt = $db->prepare($link_query);
                $link_stmt->bindParam(':student_id', $student_id);
                $link_stmt->bindParam(':parent_id', $parent_id);
                $is_primary = ($parent_id == ($data['primary_parent_id'] ?? null));
                $link_stmt->bindParam(':is_primary', $is_primary);
                $link_stmt->execute();
            }
        }

        // Log the action
        logAction($db, $user_data['user_id'], 'create_student', ['student_id' => $student_id]);

        echo json_encode(['success' => true, 'message' => 'Student created successfully', 'id' => $student_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create student']);
    }
}

function updateStudent($db, $id, $user_data)
{
    Auth::checkRole($user_data, ['admin']);

    $data = json_decode(file_get_contents("php://input"), true);

    $query = "UPDATE students SET 
              first_name = COALESCE(:first_name, first_name),
              last_name = COALESCE(:last_name, last_name),
              middle_name = COALESCE(:middle_name, middle_name),
              date_of_birth = COALESCE(:date_of_birth, date_of_birth),
              gender = COALESCE(:gender, gender),
              blood_group = COALESCE(:blood_group, blood_group),
              address = COALESCE(:address, address),
              phone = COALESCE(:phone, phone),
              emergency_contact = COALESCE(:emergency_contact, emergency_contact),
              medical_info = COALESCE(:medical_info, medical_info),
              class_id = COALESCE(:class_id, class_id),
              is_active = COALESCE(:is_active, is_active)
              WHERE id = :id AND school_id = :school_id";

    $stmt = $db->prepare($query);

    $stmt->bindParam(':first_name', $data['first_name']);
    $stmt->bindParam(':last_name', $data['last_name']);
    $stmt->bindParam(':middle_name', $data['middle_name']);
    $stmt->bindParam(':date_of_birth', $data['date_of_birth']);
    $stmt->bindParam(':gender', $data['gender']);
    $stmt->bindParam(':blood_group', $data['blood_group']);
    $stmt->bindParam(':address', $data['address']);
    $stmt->bindParam(':phone', $data['phone']);
    $stmt->bindParam(':emergency_contact', $data['emergency_contact']);
    $stmt->bindParam(':medical_info', $data['medical_info']);
    $stmt->bindParam(':class_id', $data['class_id']);
    $stmt->bindParam(':is_active', $data['is_active']);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':school_id', $user_data['school_id']);

    if ($stmt->execute()) {
        logAction($db, $user_data['user_id'], 'update_student', ['student_id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update student']);
    }
}

function deleteStudent($db, $id, $user_data)
{
    Auth::checkRole($user_data, ['admin']);

    // Soft delete - just mark as inactive
    $query = "UPDATE students SET is_active = 0 WHERE id = :id AND school_id = :school_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':school_id', $user_data['school_id']);

    if ($stmt->execute()) {
        // Decrease student count
        $update_count = "UPDATE schools SET current_students = current_students - 1 WHERE id = :school_id";
        $count_stmt = $db->prepare($update_count);
        $count_stmt->bindParam(':school_id', $user_data['school_id']);
        $count_stmt->execute();

        logAction($db, $user_data['user_id'], 'delete_student', ['student_id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Student deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete student']);
    }
}

function getStudentsByClass($db, $class_id, $user_data)
{
    Auth::checkRole($user_data, ['admin', 'staff']);

    $query = "SELECT s.* FROM students s
              WHERE s.class_id = :class_id AND s.school_id = :school_id AND s.is_active = 1
              ORDER BY s.last_name, s.first_name";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $class_id);
    $stmt->bindParam(':school_id', $user_data['school_id']);
    $stmt->execute();

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $students]);
}

function logAction($db, $user_id, $action, $details)
{
    $query = "INSERT INTO system_logs (user_id, action, details, ip_address) 
              VALUES (:user_id, :action, :details, :ip_address)";
    $stmt = $db->prepare($query);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $details_json = json_encode($details);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':details', $details_json);
    $stmt->bindParam(':ip_address', $ip);
    $stmt->execute();
}
?>