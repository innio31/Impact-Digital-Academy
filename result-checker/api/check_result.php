<?php
// api/check_result.php - Main result checking endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['school_code', 'admission_number', 'session_year', 'term', 'pin'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing field: $field"]);
        exit();
    }
}

$school_code = trim($input['school_code']);
$admission_number = trim($input['admission_number']);
$session_year = trim($input['session_year']);
$term = trim($input['term']);
$pin_code = strtoupper(trim($input['pin']));

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Start transaction for PIN validation and update
    $conn->beginTransaction();

    // Get school information
    $stmt = $conn->prepare("
        SELECT id, school_name, school_logo, school_address, principal_name, school_code
        FROM schools 
        WHERE school_code = ? AND status = 'active'
    ");
    $stmt->execute([$school_code]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'School not found or inactive']);
        exit();
    }

    $school_id = $school['id'];

    // Get student information
    $stmt = $conn->prepare("
        SELECT id, admission_number, full_name, class, gender 
        FROM students 
        WHERE school_id = ? AND admission_number = ? AND status = 'active'
    ");
    $stmt->execute([$school_id, $admission_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found. Please check the admission number.']);
        exit();
    }

    $student_id = $student['id'];

    // Validate PIN
    $stmt = $conn->prepare("
        SELECT * FROM result_pins 
        WHERE pin_code = ? 
        AND school_id = ? 
        AND status IN ('unused', 'active')
        AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ");
    $stmt->execute([$pin_code, $school_id]);
    $pin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pin) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired PIN. Please check and try again.']);
        exit();
    }

    // Check if PIN can be used for this student (if already assigned to a different student)
    if ($pin['student_id'] !== null && $pin['student_id'] != $student_id) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This PIN is assigned to a different student.']);
        exit();
    }

    // Check max uses
    if ($pin['used_count'] >= $pin['max_uses']) {
        // Update PIN status to used_up
        $stmt = $conn->prepare("UPDATE result_pins SET status = 'used_up' WHERE id = ?");
        $stmt->execute([$pin['id']]);
        $conn->commit();

        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'PIN has reached maximum usage limit (' . $pin['max_uses'] . ' times).']);
        exit();
    }

    // Get result for the student
    $stmt = $conn->prepare("
        SELECT * FROM results 
        WHERE school_id = ? 
        AND student_id = ? 
        AND session_year = ? 
        AND term = ? 
        AND is_published = 1
    ");
    $stmt->execute([$school_id, $student_id, $session_year, $term]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Result not found or not yet published for this session and term.']);
        exit();
    }

    // Log PIN usage
    $stmt = $conn->prepare("
        INSERT INTO pin_usage_log (pin_id, student_id, session_year, term, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt->execute([$pin['id'], $student_id, $session_year, $term, $ip_address, $user_agent]);

    // Update PIN usage
    $new_used_count = $pin['used_count'] + 1;
    $new_status = $new_used_count >= $pin['max_uses'] ? 'used_up' : 'active';

    $stmt = $conn->prepare("
        UPDATE result_pins 
        SET used_count = ?, 
            status = ?,
            student_id = ?,
            first_used_at = COALESCE(first_used_at, NOW()),
            last_used_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_used_count, $new_status, $student_id, $pin['id']]);

    $conn->commit();

    // Parse result data
    $scores = json_decode($result['result_data'], true);

    // Calculate derived values if not present
    $total_marks = $result['total_marks'];
    $average = $result['average'];
    $grade = $result['grade'];

    // If average not stored, calculate from scores
    if (!$average && $scores && is_array($scores)) {
        $totalScored = 0;
        $totalMax = 0;
        foreach ($scores as $score) {
            $ca1 = $score['ca1'] ?? 0;
            $ca2 = $score['ca2'] ?? 0;
            $exam = $score['exam'] ?? 0;
            $totalScored += $ca1 + $ca2 + $exam;
            $totalMax += ($score['max_ca1'] ?? 20) + ($score['max_ca2'] ?? 20) + ($score['max_exam'] ?? 60);
        }
        if ($totalMax > 0) {
            $average = round(($totalScored / $totalMax) * 100, 2);
            $grade = calculateGrade($average);
        }
        $total_marks = "$totalScored / $totalMax";
    }

    // Parse affective traits
    $affective_traits = null;
    if ($result['affective_traits']) {
        $affective_traits = json_decode($result['affective_traits'], true);
    }

    // Parse psychomotor skills
    $psychomotor_skills = null;
    if ($result['psychomotor_skills']) {
        $psychomotor_skills = json_decode($result['psychomotor_skills'], true);
    }

    // Prepare response
    $response = [
        'success' => true,
        'school' => [
            'name' => $school['school_name'],
            'logo' => $school['school_logo'] ? '/uploads/' . $school['school_logo'] : null,
            'address' => $school['school_address'],
            'principal' => $school['principal_name']
        ],
        'student' => [
            'name' => $student['full_name'],
            'admission_number' => $student['admission_number'],
            'class' => $student['class'],
            'session' => $session_year,
            'term' => $term
        ],
        'result' => [
            'scores' => $scores,
            'total_marks' => $total_marks,
            'average' => $average,
            'grade' => $grade,
            'class_position' => $result['class_position'],
            'class_total' => $result['class_total_students'],
            'promoted_to' => $result['promoted_to'],
            'teachers_comment' => $result['teachers_comment'],
            'principals_comment' => $result['principals_comment'],
            'days_present' => $result['days_present'],
            'days_absent' => $result['days_absent'],
            'affective_traits' => $affective_traits,
            'psychomotor_skills' => $psychomotor_skills
        ],
        'pin_remaining_uses' => $pin['max_uses'] - $new_used_count
    ];

    echo json_encode($response);
} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("check_result.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred. Please try again later.']);
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("check_result.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred.']);
}

/**
 * Calculate grade based on percentage
 */
function calculateGrade($percentage)
{
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B';
    if ($percentage >= 60) return 'C';
    if ($percentage >= 50) return 'D';
    if ($percentage >= 40) return 'E';
    return 'F';
}
