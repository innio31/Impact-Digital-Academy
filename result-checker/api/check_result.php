<?php
// api/check_result.php - For impactdi_result-checker database
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Error reporting for debugging
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

// Database configuration for result-checker
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'impactdi_result-checker';
$password = 'uenrqFrgYbcY5YmSLTH6';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Validate school
    $stmt = $pdo->prepare("
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

    // 2. Validate PIN
    $pin = strtoupper(preg_replace('/[^A-Z0-9]/', '', $input['pin']));
    $stmt = $pdo->prepare("
        SELECT id, student_id, school_id, pin_code, max_uses, used_count, 
               status, expiry_date, last_used_at
        FROM result_pins 
        WHERE pin_code = ? AND school_id = ? AND status IN ('active', 'unused')
    ");
    $stmt->execute([$pin, $school['id']]);
    $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pinRecord) {
        echo json_encode(['success' => false, 'error' => 'Invalid or inactive PIN']);
        exit();
    }

    // Check expiry
    if ($pinRecord['expiry_date'] && strtotime($pinRecord['expiry_date']) < time()) {
        echo json_encode(['success' => false, 'error' => 'PIN has expired']);
        exit();
    }

    if ($pinRecord['used_count'] >= $pinRecord['max_uses']) {
        echo json_encode(['success' => false, 'error' => 'PIN has no remaining uses']);
        exit();
    }

    // 3. Get student
    $stmt = $pdo->prepare("
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

    // Check if PIN is assigned to this student (optional)
    if ($pinRecord['student_id'] && $pinRecord['student_id'] != $student['id']) {
        echo json_encode(['success' => false, 'error' => 'PIN not valid for this student']);
        exit();
    }

    // 4. Get result with FULL data from results table
    $stmt = $pdo->prepare("
        SELECT * FROM results 
        WHERE school_id = ? AND student_id = ? AND session_year = ? AND term = ? AND is_published = 1
    ");
    $stmt->execute([$school['id'], $student['id'], $input['session_year'], $input['term']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Result not found or not published yet']);
        exit();
    }

    // Decode result_data (JSON field containing full report card data)
    $result_data = json_decode($result['result_data'], true);
    $affective_traits = json_decode($result['affective_traits'], true);
    $psychomotor_skills = json_decode($result['psychomotor_skills'], true);

    // If result_data is empty but we have individual fields, build the structure
    if (empty($result_data)) {
        $result_data = [
            'scores' => [],
            'summary' => [
                'total_marks' => $result['total_marks'],
                'average' => $result['average'],
                'grade' => $result['grade']
            ],
            'position' => [
                'class_position' => $result['class_position'],
                'class_total' => $result['class_total_students']
            ],
            'comments' => [
                'teachers_comment' => $result['teachers_comment'],
                'principals_comment' => $result['principals_comment']
            ],
            'attendance' => [
                'days_present' => $result['days_present'],
                'days_absent' => $result['days_absent']
            ]
        ];
    }

    // 5. Update PIN usage
    $new_used_count = $pinRecord['used_count'] + 1;
    $new_status = ($new_used_count >= $pinRecord['max_uses']) ? 'used_up' : 'active';

    $stmt = $pdo->prepare("
        UPDATE result_pins 
        SET used_count = ?, status = ?, last_used_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$new_used_count, $new_status, $pinRecord['id']]);

    // Log access
    $stmt = $pdo->prepare("
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

    // 6. Prepare scores array for display
    $scores = [];
    if (isset($result_data['scores']) && !empty($result_data['scores'])) {
        $scores = $result_data['scores'];
    } elseif (isset($result_data['original_data']['scores'])) {
        $scores = $result_data['original_data']['scores'];
    }

    // If scores still empty, try to build from student_scores table (if available)
    if (empty($scores)) {
        // Try to get from cbt_tts database (if linked)
        try {
            $cbt_pdo = new PDO("mysql:host=localhost;dbname=cbt_tts;charset=utf8mb4", $username, $password);
            $cbt_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $cbt_pdo->prepare("
                SELECT ss.*, sub.subject_name 
                FROM student_scores ss
                JOIN subjects sub ON ss.subject_id = sub.id
                WHERE ss.student_id = (SELECT id FROM students WHERE admission_number = ? LIMIT 1)
                AND ss.session = ? AND ss.term = ?
            ");
            $stmt->execute([$student['admission_number'], $input['session_year'], $input['term']]);
            $db_scores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($db_scores as $db_score) {
                $score_data = json_decode($db_score['score_data'], true);
                $scores[] = [
                    'subject_name' => $db_score['subject_name'],
                    'score_data' => $score_data,
                    'total_score' => $db_score['total_score'],
                    'percentage' => $db_score['percentage'],
                    'grade' => $db_score['grade'],
                    'components' => $score_data
                ];
            }
        } catch (Exception $e) {
            // Ignore - cbt_tts might not be accessible
        }
    }

    // Calculate summary if not present
    $total_scored = 0;
    $total_max = 0;
    foreach ($scores as $score) {
        $total_scored += $score['total_score'] ?? 0;
        $total_max += 100; // Default max
        if (isset($score['max_score'])) {
            $total_max = $score['max_score'];
        }
    }

    $overall_percentage = $result['average'] ?? ($total_max > 0 ? ($total_scored / $total_max) * 100 : 0);

    // Prepare response
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
    error_log("Check result error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
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
