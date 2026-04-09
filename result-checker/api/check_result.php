<?php
// api/check_result.php - Get student result with FULL report card data
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit();
}

// Validate required fields
$required = ['school_code', 'admission_number', 'session_year', 'term', 'pin'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit();
    }
}

require_once '../config/config.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // 1. Validate school
    $stmt = $conn->prepare("
        SELECT id, school_name, school_code, logo, address, phone, email 
        FROM schools 
        WHERE school_code = ? AND status = 'active'
    ");
    $stmt->execute([$input['school_code']]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        echo json_encode(['success' => false, 'error' => 'Invalid school code']);
        exit();
    }

    // 2. Validate PIN
    $pin = strtoupper(preg_replace('/[^A-Z0-9]/', '', $input['pin']));
    $stmt = $conn->prepare("
        SELECT id, student_id, school_id, pin_code, remaining_uses, expires_at, status 
        FROM result_pins 
        WHERE pin_code = ? AND school_id = ? AND status = 'active'
    ");
    $stmt->execute([$pin, $school['id']]);
    $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pinRecord) {
        echo json_encode(['success' => false, 'error' => 'Invalid or inactive PIN']);
        exit();
    }

    // Check expiry
    if ($pinRecord['expires_at'] && strtotime($pinRecord['expires_at']) < time()) {
        echo json_encode(['success' => false, 'error' => 'PIN has expired']);
        exit();
    }

    if ($pinRecord['remaining_uses'] <= 0) {
        echo json_encode(['success' => false, 'error' => 'PIN has no remaining uses']);
        exit();
    }

    // 3. Get student
    $stmt = $conn->prepare("
        SELECT id, admission_number, full_name, class, gender, 
               date_of_birth, parent_name, parent_phone, parent_email 
        FROM students 
        WHERE school_id = ? AND admission_number = ? AND status = 'active'
    ");
    $stmt->execute([$school['id'], $input['admission_number']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }

    // Check if PIN is assigned to this student (optional - some schools allow PINs for any student)
    if ($pinRecord['student_id'] && $pinRecord['student_id'] != $student['id']) {
        echo json_encode(['success' => false, 'error' => 'PIN not valid for this student']);
        exit();
    }

    // 4. Get result with FULL data
    $stmt = $conn->prepare("
        SELECT * FROM results 
        WHERE school_id = ? AND student_id = ? AND session_year = ? AND term = ? AND is_published = 1
    ");
    $stmt->execute([$school['id'], $student['id'], $input['session_year'], $input['term']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Result not found or not published yet']);
        exit();
    }

    // Decode result_data (contains full report card structure)
    $result_data = json_decode($result['result_data'], true);

    // 5. Update PIN usage
    $new_remaining = $pinRecord['remaining_uses'] - 1;
    $stmt = $conn->prepare("
        UPDATE result_pins 
        SET remaining_uses = ?, last_used_at = NOW(), last_used_by = ? 
        WHERE id = ?
    ");
    $stmt->execute([$new_remaining, $student['id'], $pinRecord['id']]);

    // Log access
    $stmt = $conn->prepare("
        INSERT INTO pin_access_logs (pin_id, student_id, school_id, session_year, term, ip_address, user_agent, accessed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $pinRecord['id'],
        $student['id'],
        $school['id'],
        $input['session_year'],
        $input['term'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // 6. Prepare response with FULL report card data
    $response = [
        'success' => true,
        'pin_remaining_uses' => $new_remaining,
        'school' => [
            'id' => $school['id'],
            'name' => $school['school_name'],
            'code' => $school['school_code'],
            'logo' => $school['logo'] ?? null,
            'address' => $school['address'] ?? '',
            'phone' => $school['phone'] ?? '',
            'email' => $school['email'] ?? ''
        ],
        'student' => [
            'id' => $student['id'],
            'admission_number' => $student['admission_number'],
            'name' => $student['full_name'],
            'class' => $student['class'],
            'gender' => $student['gender'],
            'date_of_birth' => $student['date_of_birth'],
            'parent_name' => $student['parent_name'],
            'parent_phone' => $student['parent_phone'],
            'parent_email' => $student['parent_email']
        ],
        'result' => [
            'session_year' => $result['session_year'],
            'term' => $result['term'],
            'total_marks' => $result['total_marks'],
            'average' => $result['average'],
            'grade' => $result['grade'],
            'class_position' => $result['class_position'],
            'class_total' => $result['class_total_students'],
            'promoted_to' => $result['promoted_to'],
            'teachers_comment' => $result['teachers_comment'],
            'principals_comment' => $result['principals_comment'],
            'days_present' => $result['days_present'],
            'days_absent' => $result['days_absent'],
            'affective_traits' => json_decode($result['affective_traits'], true),
            'psychomotor_skills' => json_decode($result['psychomotor_skills'], true),
            // Full scores with all components
            'scores' => isset($result_data['scores']) ? $result_data['scores'] : [],
            // Complete raw data for full report card display
            'full_data' => $result_data
        ]
    ];

    echo json_encode($response);
} catch (PDOException $e) {
    error_log("Check result error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
