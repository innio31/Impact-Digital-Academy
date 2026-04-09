<?php
// api/check_result.php - With PIN restriction (one student per PIN)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
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

    // 1. Get school
    $stmt = $conn->prepare("
        SELECT id, school_name, school_code, school_logo as logo, school_address as address, 
               school_phone as phone, school_email as email 
        FROM schools 
        WHERE school_code = ? AND status = 'active'
    ");
    $stmt->execute([$input['school_code']]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        echo json_encode(['success' => false, 'error' => 'Invalid school code']);
        exit();
    }

    // 2. Clean PIN
    $pin_cleaned = strtoupper(preg_replace('/[^A-Z0-9]/', '', $input['pin']));

    // 3. Find PIN with multiple matching strategies
    $stmt = $conn->prepare("
        SELECT * FROM result_pins 
        WHERE school_id = ? 
        AND (
            pin_code = ? 
            OR REPLACE(pin_code, '-', '') = ?
            OR pin_code = REPLACE(?, '-', '')
        )
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$school['id'], $pin_cleaned, $pin_cleaned, $input['pin']]);
    $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found, try more direct approach
    if (!$pinRecord) {
        $stmt = $conn->prepare("
            SELECT * FROM result_pins 
            WHERE school_id = ? AND (pin_code = ? OR pin_code = ?)
        ");
        $stmt->execute([$school['id'], $input['pin'], $pin_cleaned]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$pinRecord) {
        // Last resort - compare cleaned versions
        $stmt = $conn->prepare("SELECT * FROM result_pins WHERE school_id = ?");
        $stmt->execute([$school['id']]);
        $allPins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allPins as $pin) {
            $dbPinClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $pin['pin_code']));
            if ($dbPinClean === $pin_cleaned) {
                $pinRecord = $pin;
                break;
            }
        }
    }

    if (!$pinRecord) {
        echo json_encode(['success' => false, 'error' => 'PIN not found in system']);
        exit();
    }

    // 4. Get student
    $stmt = $conn->prepare("
        SELECT id, admission_number, full_name, class, gender, date_of_birth, 
               parent_phone, parent_email 
        FROM students 
        WHERE school_id = ? AND admission_number = ? AND status = 'active'
    ");
    $stmt->execute([$school['id'], $input['admission_number']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }

    // ============ PIN RESTRICTION FEATURE ============
    // Check if PIN has already been used by a different student

    // First, check if this PIN has a student_id assigned
    if (!empty($pinRecord['student_id'])) {
        // PIN is already assigned to a student
        if ($pinRecord['student_id'] != $student['id']) {
            // Different student trying to use this PIN
            echo json_encode(['success' => false, 'error' => 'This PIN has already been used by another student. Each PIN can only be used by one student.']);
            exit();
        }
    } else {
        // PIN is not yet assigned to any student
        // Check if this PIN has been used before (via usage log)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as usage_count, student_id 
            FROM pin_usage_log 
            WHERE pin_id = ?
            GROUP BY student_id
        ");
        $stmt->execute([$pinRecord['id']]);
        $usageHistory = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usageHistory && $usageHistory['usage_count'] > 0) {
            // PIN has been used before
            if ($usageHistory['student_id'] != $student['id']) {
                // Different student trying to use this PIN
                echo json_encode(['success' => false, 'error' => 'This PIN has already been used by another student. Each PIN can only be used by one student.']);
                exit();
            }
        } else {
            // First time usage - assign this PIN to the current student
            $stmt = $conn->prepare("
                UPDATE result_pins 
                SET student_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([$student['id'], $pinRecord['id']]);
        }
    }

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
        $stmt = $conn->prepare("UPDATE result_pins SET status = 'expired' WHERE id = ?");
        $stmt->execute([$pinRecord['id']]);
        echo json_encode(['success' => false, 'error' => 'PIN has expired']);
        exit();
    }

    // Check usage count
    if ($pinRecord['used_count'] >= $pinRecord['max_uses']) {
        $stmt = $conn->prepare("UPDATE result_pins SET status = 'used_up' WHERE id = ?");
        $stmt->execute([$pinRecord['id']]);
        echo json_encode(['success' => false, 'error' => 'PIN has been fully used']);
        exit();
    }

    // 6. Get result
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

    // 7. Decode result data
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

    // 8. Update PIN usage
    $new_used_count = $pinRecord['used_count'] + 1;
    $new_status = ($new_used_count >= $pinRecord['max_uses']) ? 'used_up' : 'active';

    $stmt = $conn->prepare("
        UPDATE result_pins 
        SET used_count = ?, status = ?, last_used_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$new_used_count, $new_status, $pinRecord['id']]);

    // 9. Log access
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

    // 10. Calculate totals
    $total_scored = $result['total_marks'] ?? 0;
    $total_max = count($scores) * 100;
    $overall_percentage = $result['average'] ?? ($total_max > 0 ? ($total_scored / $total_max) * 100 : 0);

    // 11. Prepare response
    $response = [
        'success' => true,
        'pin_remaining_uses' => $pinRecord['max_uses'] - $new_used_count,
        'pin_assigned_to_student' => !empty($pinRecord['student_id']),
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
    error_log("Check result error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
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
