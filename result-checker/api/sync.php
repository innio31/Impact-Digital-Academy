<?php
// api/sync.php - School Data Sync Endpoint (with proper error handling)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Enable error logging but not display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Only POST requests are accepted.']);
    exit();
}

// Try to include config with error handling
try {
    require_once '../config/config.php';

    // Check if config loaded properly
    if (!class_exists('Database')) {
        throw new Exception('Database class not found. Check config/database.php');
    }

    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    error_log("Sync API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server configuration error: ' . $e->getMessage()
    ]);
    exit();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

// Check if JSON is valid
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

// Validate required fields
if (!isset($input['api_key']) || !isset($input['school_code'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing authentication credentials. Required: api_key, school_code']);
    exit();
}

if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action parameter. Available actions: test_connection, sync_classes, sync_students, sync_results, sync_full']);
    exit();
}

// Validate school
try {
    $stmt = $conn->prepare("
        SELECT id, school_name, school_code, status, subscription_plan, subscription_expiry 
        FROM schools 
        WHERE api_key = ? AND school_code = ? AND status = 'active'
    ");
    $stmt->execute([$input['api_key'], $input['school_code']]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key or school code, or school is inactive']);
        exit();
    }

    // Check subscription expiry
    if ($school['subscription_expiry'] && strtotime($school['subscription_expiry']) < time()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Subscription expired on ' . $school['subscription_expiry'] . '. Please renew to continue syncing.']);
        exit();
    }
} catch (PDOException $e) {
    error_log("Sync API Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again later.']);
    exit();
}

$school_id = $school['id'];
$response = ['success' => false, 'message' => '', 'data' => []];

// Get system settings
$system_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $system_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Settings table might not exist yet, use defaults
    $system_settings = [
        'enable_api_logging' => 1,
        'api_rate_limit' => 100,
        'api_rate_window' => 60
    ];
}

// Rate limiting check
if (($system_settings['enable_api_logging'] ?? 1) == 1) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rate_limit = $system_settings['api_rate_limit'] ?? 100;
    $rate_window = $system_settings['api_rate_window'] ?? 60;

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM api_request_logs 
            WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, $rate_window]);
        $request_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($request_count >= $rate_limit) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Maximum ' . $rate_limit . ' requests per ' . $rate_window . ' seconds.']);
            exit();
        }
    } catch (PDOException $e) {
        // Log table might not exist, skip rate limiting
        error_log("Rate limiting table not found: " . $e->getMessage());
    }
}

// Log API request (if logging enabled)
if (($system_settings['enable_api_logging'] ?? 1) == 1) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO api_request_logs (school_id, ip_address, action, request_data, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $school_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $input['action'],
            json_encode($input),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $e) {
        // Log table might not exist, skip logging
        error_log("API logging failed: " . $e->getMessage());
    }
}

// Process based on action
try {
    switch ($input['action']) {
        case 'sync_classes':
            $response = syncClasses($conn, $school_id, $input);
            break;

        case 'sync_students':
            $response = syncStudents($conn, $school_id, $input);
            break;

        case 'sync_results':
            $response = syncResults($conn, $school_id, $input);
            break;

        case 'sync_full':
            $response = syncFull($conn, $school_id, $input);
            break;

        case 'test_connection':
            $response = [
                'success' => true,
                'message' => 'Connection successful',
                'data' => [
                    'school' => $school['school_name'],
                    'school_code' => $school['school_code'],
                    'subscription_plan' => $school['subscription_plan'],
                    'subscription_expiry' => $school['subscription_expiry']
                ]
            ];
            break;

        default:
            http_response_code(400);
            $response = ['success' => false, 'error' => 'Unknown action: ' . $input['action'] . '. Available: test_connection, sync_classes, sync_students, sync_results, sync_full'];
    }
} catch (Exception $e) {
    error_log("Sync API Processing Error: " . $e->getMessage());
    http_response_code(500);
    $response = ['success' => false, 'error' => 'Processing error: ' . $e->getMessage()];
}

echo json_encode($response);

/**
 * Sync classes from school
 */
function syncClasses($conn, $school_id, $input)
{
    if (!isset($input['classes']) || !is_array($input['classes'])) {
        return ['success' => false, 'error' => 'Missing or invalid classes data'];
    }

    $classes = $input['classes'];
    $synced = 0;
    $updated = 0;
    $errors = [];

    foreach ($classes as $class) {
        if (empty($class['class_name'])) {
            $errors[] = 'Class name is required';
            continue;
        }

        try {
            $class_name = trim($class['class_name']);
            $class_category = $class['class_category'] ?? determineClassCategory($class_name);
            $class_code = $class['class_code'] ?? null;
            $sort_order = $class['sort_order'] ?? 0;
            $status = $class['status'] ?? 'active';

            $stmt = $conn->prepare("
                INSERT INTO school_classes (school_id, class_name, class_category, class_code, sort_order, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    class_name = VALUES(class_name),
                    class_category = VALUES(class_category),
                    class_code = VALUES(class_code),
                    sort_order = VALUES(sort_order),
                    status = VALUES(status),
                    updated_at = NOW()
            ");

            $stmt->execute([$school_id, $class_name, $class_category, $class_code, $sort_order, $status]);

            if ($stmt->rowCount() > 0) {
                $synced++;
            } else {
                $updated++;
            }
        } catch (PDOException $e) {
            $errors[] = "Error syncing class {$class_name}: " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'message' => "Classes synced successfully",
        'data' => [
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors
        ]
    ];
}

/**
 * Sync students from school
 */
function syncStudents($conn, $school_id, $input)
{
    if (!isset($input['students']) || !is_array($input['students'])) {
        return ['success' => false, 'error' => 'Missing or invalid students data'];
    }

    $students = $input['students'];
    $synced = 0;
    $updated = 0;
    $errors = [];
    $class_cache = [];

    foreach ($students as $student) {
        if (empty($student['admission_number']) || empty($student['full_name'])) {
            $errors[] = 'Admission number and full name are required';
            continue;
        }

        try {
            // Get or create class reference
            $class_name = $student['class'] ?? null;
            $school_class_id = null;

            if ($class_name) {
                if (!isset($class_cache[$class_name])) {
                    $stmt = $conn->prepare("
                        SELECT id FROM school_classes 
                        WHERE school_id = ? AND class_name = ?
                    ");
                    $stmt->execute([$school_id, $class_name]);
                    $class = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($class) {
                        $class_cache[$class_name] = $class['id'];
                    } else {
                        // Auto-create class if it doesn't exist
                        $category = determineClassCategory($class_name);
                        $stmt = $conn->prepare("
                            INSERT INTO school_classes (school_id, class_name, class_category, status)
                            VALUES (?, ?, ?, 'active')
                        ");
                        $stmt->execute([$school_id, $class_name, $category]);
                        $class_cache[$class_name] = $conn->lastInsertId();
                    }
                }
                $school_class_id = $class_cache[$class_name];
            }

            // Insert or update student
            $stmt = $conn->prepare("
                INSERT INTO students (
                    school_id, admission_number, full_name, class, school_class_id,
                    gender, date_of_birth, parent_phone, parent_email, status, last_sync_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    class = VALUES(class),
                    school_class_id = VALUES(school_class_id),
                    gender = VALUES(gender),
                    date_of_birth = VALUES(date_of_birth),
                    parent_phone = VALUES(parent_phone),
                    parent_email = VALUES(parent_email),
                    status = VALUES(status),
                    last_sync_at = NOW()
            ");

            $admission_number = trim($student['admission_number']);
            $full_name = trim($student['full_name']);
            $gender = $student['gender'] ?? null;
            $dob = !empty($student['date_of_birth']) ? $student['date_of_birth'] : null;
            $parent_phone = $student['parent_phone'] ?? null;
            $parent_email = $student['parent_email'] ?? null;
            $status = $student['status'] ?? 'active';

            $stmt->execute([
                $school_id,
                $admission_number,
                $full_name,
                $class_name,
                $school_class_id,
                $gender,
                $dob,
                $parent_phone,
                $parent_email,
                $status
            ]);

            if ($conn->lastInsertId() > 0) {
                $synced++;
            } else {
                $updated++;
            }
        } catch (PDOException $e) {
            $errors[] = "Error syncing student {$student['admission_number']}: " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'message' => "Students synced successfully",
        'data' => [
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors
        ]
    ];
}

/**
 * Sync results from school
 */
function syncResults($conn, $school_id, $input)
{
    if (!isset($input['results']) || !is_array($input['results'])) {
        return ['success' => false, 'error' => 'Missing or invalid results data'];
    }

    $results = $input['results'];
    $synced = 0;
    $failed = 0;
    $errors = [];

    foreach ($results as $result) {
        // Validate required fields
        if (empty($result['admission_number']) || empty($result['session_year']) || empty($result['term'])) {
            $failed++;
            $errors[] = 'Missing required fields: admission_number, session_year, term';
            continue;
        }

        try {
            // Get student ID
            $stmt = $conn->prepare("
                SELECT id, class FROM students 
                WHERE school_id = ? AND admission_number = ? AND status = 'active'
            ");
            $stmt->execute([$school_id, $result['admission_number']]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                $failed++;
                $errors[] = "Student not found: {$result['admission_number']}";
                continue;
            }

            $student_id = $student['id'];
            $student_class = $student['class'];

            // Get school class ID
            $stmt = $conn->prepare("
                SELECT id FROM school_classes 
                WHERE school_id = ? AND class_name = ?
            ");
            $stmt->execute([$school_id, $student_class]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            $school_class_id = $class ? $class['id'] : null;

            // Prepare scores data
            $scores = [];
            $total_marks = 0;
            $total_obtainable = 0;

            if (isset($result['scores']) && is_array($result['scores'])) {
                foreach ($result['scores'] as $score) {
                    if (empty($score['subject_name'])) {
                        continue;
                    }

                    $ca1 = $score['ca1'] ?? 0;
                    $ca2 = $score['ca2'] ?? 0;
                    $exam = $score['exam'] ?? 0;
                    $max_ca1 = $score['max_ca1'] ?? 20;
                    $max_ca2 = $score['max_ca2'] ?? 20;
                    $max_exam = $score['max_exam'] ?? 60;

                    $subject_total = $ca1 + $ca2 + $exam;
                    $subject_max = $max_ca1 + $max_ca2 + $max_exam;
                    $percentage = $subject_max > 0 ? round(($subject_total / $subject_max) * 100, 2) : 0;
                    $grade = calculateGrade($percentage);

                    $scores[] = [
                        'subject_name' => $score['subject_name'],
                        'ca1' => $ca1,
                        'ca2' => $ca2,
                        'exam' => $exam,
                        'max_ca1' => $max_ca1,
                        'max_ca2' => $max_ca2,
                        'max_exam' => $max_exam,
                        'total' => $subject_total,
                        'percentage' => $percentage,
                        'grade' => $grade
                    ];

                    $total_marks += $subject_total;
                    $total_obtainable += $subject_max;
                }
            }

            // Calculate overall average
            $average = $total_obtainable > 0 ? round(($total_marks / $total_obtainable) * 100, 2) : 0;
            $overall_grade = calculateGrade($average);

            // Prepare affective traits
            $affective_traits = null;
            if (isset($result['affective_traits']) && is_array($result['affective_traits'])) {
                $affective_traits = json_encode(array_filter($result['affective_traits']));
            }

            // Prepare psychomotor skills
            $psychomotor_skills = null;
            if (isset($result['psychomotor_skills']) && is_array($result['psychomotor_skills'])) {
                $psychomotor_skills = json_encode(array_filter($result['psychomotor_skills']));
            }

            // Insert or update result
            $stmt = $conn->prepare("
                INSERT INTO results (
                    school_id, student_id, session_year, term, school_class_id,
                    result_data, total_marks, average, grade,
                    class_position, class_total_students, promoted_to,
                    teachers_comment, principals_comment,
                    days_present, days_absent, affective_traits, psychomotor_skills,
                    is_published, synced_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    school_class_id = VALUES(school_class_id),
                    result_data = VALUES(result_data),
                    total_marks = VALUES(total_marks),
                    average = VALUES(average),
                    grade = VALUES(grade),
                    class_position = VALUES(class_position),
                    class_total_students = VALUES(class_total_students),
                    promoted_to = VALUES(promoted_to),
                    teachers_comment = VALUES(teachers_comment),
                    principals_comment = VALUES(principals_comment),
                    days_present = VALUES(days_present),
                    days_absent = VALUES(days_absent),
                    affective_traits = VALUES(affective_traits),
                    psychomotor_skills = VALUES(psychomotor_skills),
                    is_published = VALUES(is_published),
                    updated_at = NOW()
            ");

            $stmt->execute([
                $school_id,
                $student_id,
                $result['session_year'],
                $result['term'],
                $school_class_id,
                json_encode($scores),
                $total_marks . '/' . $total_obtainable,
                $average,
                $overall_grade,
                $result['class_position'] ?? null,
                $result['class_total_students'] ?? null,
                $result['promoted_to'] ?? null,
                $result['teachers_comment'] ?? null,
                $result['principals_comment'] ?? null,
                $result['days_present'] ?? 0,
                $result['days_absent'] ?? 0,
                $affective_traits,
                $psychomotor_skills,
                $result['is_published'] ?? true
            ]);

            $synced++;
        } catch (PDOException $e) {
            $failed++;
            $errors[] = "Error syncing result for {$result['admission_number']}: " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'message' => "Results synced successfully",
        'data' => [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors
        ]
    ];
}

/**
 * Full sync - classes, students, and results
 */
function syncFull($conn, $school_id, $input)
{
    $response = [
        'success' => true,
        'message' => 'Full sync completed',
        'data' => []
    ];

    // Sync classes if provided
    if (isset($input['classes']) && is_array($input['classes'])) {
        $class_result = syncClasses($conn, $school_id, ['classes' => $input['classes']]);
        $response['data']['classes'] = $class_result['data'];
    }

    // Sync students if provided
    if (isset($input['students']) && is_array($input['students'])) {
        $student_result = syncStudents($conn, $school_id, ['students' => $input['students']]);
        $response['data']['students'] = $student_result['data'];
    }

    // Sync results if provided
    if (isset($input['results']) && is_array($input['results'])) {
        $result_result = syncResults($conn, $school_id, ['results' => $input['results']]);
        $response['data']['results'] = $result_result['data'];
    }

    // Update school last sync time
    try {
        $stmt = $conn->prepare("UPDATE schools SET last_sync = NOW() WHERE id = ?");
        $stmt->execute([$school_id]);
    } catch (PDOException $e) {
        // Non-critical, ignore
    }

    return $response;
}

/**
 * Determine class category based on class name
 */
function determineClassCategory($class_name)
{
    $class_name_lower = strtolower($class_name);

    if (strpos($class_name_lower, 'jss') !== false || strpos($class_name_lower, 'junior') !== false) {
        return 'Junior Secondary';
    } elseif (strpos($class_name_lower, 'sss') !== false || strpos($class_name_lower, 'senior') !== false) {
        return 'Senior Secondary';
    } elseif (strpos($class_name_lower, 'primary') !== false || strpos($class_name_lower, 'grade') !== false) {
        return 'Primary';
    } elseif (preg_match('/^[0-9]+$/', $class_name)) {
        return 'Primary';
    }

    return 'Other';
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
