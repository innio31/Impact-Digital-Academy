<?php
// api/check_result.php - For impactdi_result-checker database
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Temporarily enable to see errors
ini_set('log_errors', 1);

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

// Include database config
require_once '../config/config.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // DEBUG: Log the request
    error_log("=== PIN Check Request ===");
    error_log("School Code: " . $input['school_code']);
    error_log("PIN Raw: " . $input['pin']);

    // 1. First, get the school ID from school_code
    $stmt = $conn->prepare("
        SELECT id, school_name, school_code, school_logo as logo, school_address as address, 
               school_phone as phone, school_email as email 
        FROM schools 
        WHERE school_code = ? AND status = 'active'
    ");
    $stmt->execute([$input['school_code']]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        error_log("School not found: " . $input['school_code']);
        echo json_encode(['success' => false, 'error' => 'Invalid school code']);
        exit();
    }

    error_log("School Found: ID=" . $school['id'] . ", Name=" . $school['school_name']);

    // 2. Clean and validate PIN
    $pin_raw = $input['pin'];
    $pin_cleaned = strtoupper(preg_replace('/[^A-Z0-9]/', '', $pin_raw));

    error_log("PIN Raw: " . $pin_raw);
    error_log("PIN Cleaned: " . $pin_cleaned);

    // 3. First, check if PIN exists at all (without any conditions)
    $stmt = $conn->prepare("
        SELECT * FROM result_pins 
        WHERE pin_code = ?
    ");
    $stmt->execute([$pin_cleaned]);
    $pinExists = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pinExists) {
        error_log("PIN not found in database: " . $pin_cleaned);
        echo json_encode(['success' => false, 'error' => 'PIN not found in system']);
        exit();
    }

    error_log("PIN Found in DB: " . print_r($pinExists, true));

    // 4. Now check PIN with school_id and status
    $stmt = $conn->prepare("
        SELECT id, student_id, school_id, pin_code, max_uses, used_count, 
               status, expiry_date, last_used_at, created_at
        FROM result_pins 
        WHERE pin_code = ? AND school_id = ?
    ");
    $stmt->execute([$pin_cleaned, $school['id']]);
    $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pinRecord) {
        error_log("PIN exists but not for this school. PIN School ID: " . ($pinExists['school_id'] ?? 'null') . ", Request School ID: " . $school['id']);
        echo json_encode(['success' => false, 'error' => 'PIN not valid for this school']);
        exit();
    }

    error_log("PIN Record for this school: " . print_r($pinRecord, true));

    // 5. Check PIN status
    if ($pinRecord['status'] === 'expired') {
        echo json_encode(['success' => false, 'error' => 'PIN has expired']);
        exit();
    }

    if ($pinRecord['status'] === 'used_up') {
        echo json_encode(['success' => false, 'error' => 'PIN has been fully used']);
        exit();
    }

    // Check expiry date
    if ($pinRecord['expiry_date'] && strtotime($pinRecord['expiry_date']) < time()) {
        // Update status to expired
        $stmt = $conn->prepare("UPDATE result_pins SET status = 'expired' WHERE id = ?");
        $stmt->execute([$pinRecord['id']]);
        echo json_encode(['success' => false, 'error' => 'PIN has expired']);
        exit();
    }

    // Check usage count
    if ($pinRecord['used_count'] >= $pinRecord['max_uses']) {
        // Update status to used_up
        $stmt = $conn->prepare("UPDATE result_pins SET status = 'used_up' WHERE id = ?");
        $stmt->execute([$pinRecord['id']]);
        echo json_encode(['success' => false, 'error' => 'PIN has been fully used']);
        exit();
    }

    // 6. Get student
    $stmt = $conn->prepare("
        SELECT id, admission_number, full_name, class, gender, date_of_birth, 
               parent_phone, parent_email 
        FROM students 
        WHERE school_id = ? AND admission_number = ? AND status = 'active'
    ");
    $stmt->execute([$school['id'], $input['admission_number']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        error_log("Student not found: " . $input['admission_number']);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }

    error_log("Student Found: ID=" . $student['id'] . ", Name=" . $student['full_name']);

    // 7. Check if PIN is assigned to this student (if student_id is set in PIN)
    if (!empty($pinRecord['student_id']) && $pinRecord['student_id'] != $student['id']) {
        error_log("PIN assigned to student ID " . $pinRecord['student_id'] . " but trying to access student ID " . $student['id']);
        echo json_encode(['success' => false, 'error' => 'This PIN is assigned to a different student']);
        exit();
    }

    // 8. Get result
    $stmt = $conn->prepare("
        SELECT * FROM results 
        WHERE school_id = ? AND student_id = ? AND session_year = ? AND term = ? AND is_published = 1
    ");
    $stmt->execute([$school['id'], $student['id'], $input['session_year'], $input['term']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        error_log("Result not found for student " . $student['id'] . " in " . $input['session_year'] . " " . $input['term']);
        echo json_encode(['success' => false, 'error' => 'Result not found or not published yet']);
        exit();
    }

    error_log("Result Found: ID=" . $result['id']);

    // 9. Decode result_data
    $result_data = json_decode($result['result_data'], true);
    $affective_traits = json_decode($result['affective_traits'], true);
    $psychomotor_skills = json_decode($result['psychomotor_skills'], true);

    // Extract scores
    $scores = [];
    if (isset($result_data['scores']) && is_array($result_data['scores'])) {
        $scores = $result_data['scores'];
    } elseif (isset($result_data['original_data']['scores']) && is_array($result_data['original_data']['scores'])) {
        $scores = $result_data['original_data']['scores'];
    }

    // 10. Update PIN usage
    $new_used_count = $pinRecord['used_count'] + 1;
    $new_status = ($new_used_count >= $pinRecord['max_uses']) ? 'used_up' : 'active';

    $stmt = $conn->prepare("
        UPDATE result_pins 
        SET used_count = ?, status = ?, last_used_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$new_used_count, $new_status, $pinRecord['id']]);

    error_log("PIN Updated: used_count=" . $new_used_count . ", status=" . $new_status);

    // 11. Log access
    $stmt = $conn->prepare("
        INSERT INTO pin_usage_log (pin_id, student_id, session_year, term, ip_address, user_agent, accessed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $pinRecord['id'],
        $student['id'],
        $input['session_year'],
        $input['term'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // 12. Prepare response
    $total_scored = $result['total_marks'] ?? 0;
    $total_max = count($scores) * 100;
    $overall_percentage = $result['average'] ?? ($total_max > 0 ? ($total_scored / $total_max) * 100 : 0);

    $response = [
        'success' => true,
        'pin_remaining_uses' => $pinRecord['max_uses'] - $new_used_count,
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
            'parent_phone' => $student['parent_phone'],
            'parent_email' => $student['parent_email']
        ],
        'result' => [
            'session_year' => $result['session_year'],
            'term' => $result['term'],
            'total_marks' => $result['total_marks'] ?? $total_scored,
            'average' => $result['average'] ?? round($overall_percentage, 2),
            'grade' => $result['grade'] ?? calculateGrade($overall_percentage),
            'class_position' => $result['class_position'],
            'class_total' => $result['class_total_students'],
            'promoted_to' => $result['promoted_to'],
            'teachers_comment' => $result['teachers_comment'] ?? ($result_data['comments']['teachers_comment'] ?? null),
            'principals_comment' => $result['principals_comment'] ?? ($result_data['comments']['principals_comment'] ?? null),
            'days_present' => $result['days_present'] ?? ($result_data['attendance']['days_present'] ?? 0),
            'days_absent' => $result['days_absent'] ?? ($result_data['attendance']['days_absent'] ?? 0),
            'affective_traits' => $affective_traits ?? ($result_data['affective_traits'] ?? null),
            'psychomotor_skills' => $psychomotor_skills ?? ($result_data['psychomotor_skills'] ?? null),
            'scores' => $scores,
            'full_data' => $result_data
        ]
    ];

    echo json_encode($response);
} catch (PDOException $e) {
    error_log("Check result PDO Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Check result General Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

function calculateGrade($percentage)
{
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B';
    if ($percentage >= 60) return 'C';
    if ($percentage >= 50) return 'D';
    if ($percentage >= 40) return 'E';
    return 'F';
}
