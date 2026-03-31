<?php
// api/sync.php - School Data Sync Endpoint (UPDATED - Preserves Original Score Structure)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Create logs directory if it doesn't exist
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0777, true);
}

// Debug logging function
function debug_log($message, $data = null)
{
    $log_file = __DIR__ . '/../logs/sync_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] $message";
    if ($data !== null) {
        $log .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    $log .= "\n" . str_repeat('-', 50) . "\n";
    file_put_contents($log_file, $log, FILE_APPEND);
}

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

debug_log("=== New Sync Request ===");

// Include database config
try {
    require_once '../config/config.php';

    if (!class_exists('Database')) {
        throw new Exception('Database class not found. Check config/database.php');
    }

    $db = Database::getInstance();
    $conn = $db->getConnection();
    debug_log("Database connected successfully");
} catch (Exception $e) {
    debug_log("Database connection error", ['error' => $e->getMessage()]);
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
    debug_log("Invalid JSON", ['error' => json_last_error_msg()]);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

debug_log("Request received", ['action' => $input['action'] ?? 'unknown']);

// Validate authentication
if (!isset($input['api_key']) || !isset($input['school_code'])) {
    debug_log("Missing authentication");
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing authentication credentials. Required: api_key, school_code']);
    exit();
}

if (!isset($input['action'])) {
    debug_log("Missing action");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action parameter']);
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
        debug_log("Invalid credentials", ['api_key' => substr($input['api_key'], 0, 10) . '...', 'school_code' => $input['school_code']]);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key or school code']);
        exit();
    }

    debug_log("School authenticated", ['school_id' => $school['id'], 'school_name' => $school['school_name']]);

    // Check subscription expiry
    if ($school['subscription_expiry'] && strtotime($school['subscription_expiry']) < time()) {
        debug_log("Subscription expired", ['expiry' => $school['subscription_expiry']]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Subscription expired on ' . $school['subscription_expiry']]);
        exit();
    }
} catch (PDOException $e) {
    debug_log("Database error during auth", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

$school_id = $school['id'];

// Process based on action
try {
    switch ($input['action']) {
        case 'test_connection':
            debug_log("Processing test_connection");
            echo json_encode([
                'success' => true,
                'message' => 'Connection successful',
                'data' => [
                    'school' => $school['school_name'],
                    'school_code' => $school['school_code']
                ]
            ]);
            break;

        case 'sync_results':
            debug_log("Processing sync_results", ['results_count' => isset($input['results']) ? count($input['results']) : 0]);
            $response = syncResults($conn, $school_id, $input);
            echo json_encode($response);
            break;

        case 'sync_full':
            debug_log("Processing sync_full");
            $response = syncFull($conn, $school_id, $input);
            echo json_encode($response);
            break;

        default:
            debug_log("Unknown action", ['action' => $input['action']]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $input['action']]);
    }
} catch (Exception $e) {
    debug_log("Processing error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Processing error: ' . $e->getMessage()]);
}

/**
 * Sync results from school - PRESERVES ORIGINAL SCORE STRUCTURE
 */
function syncResults($conn, $school_id, $input)
{
    if (!isset($input['results']) || !is_array($input['results'])) {
        debug_log("syncResults: No results data");
        return ['success' => false, 'error' => 'Missing or invalid results data'];
    }

    $results = $input['results'];
    $synced = 0;
    $failed = 0;
    $errors = [];

    debug_log("syncResults: Processing " . count($results) . " results");

    foreach ($results as $index => $result) {
        // Validate required fields
        if (empty($result['admission_number']) || empty($result['session_year']) || empty($result['term'])) {
            $failed++;
            $errors[] = 'Missing required fields for result #' . $index;
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

            // ============ KEY CHANGE: PRESERVE ORIGINAL SCORES EXACTLY ============
            $original_scores = $result['scores'];

            // Store the scores with their original structure
            $result_data = [
                'original_scores' => $original_scores,  // Keep EXACT original structure
                'metadata' => [
                    'export_timestamp' => date('Y-m-d H:i:s'),
                    'school_id' => $school_id,
                    'student_id' => $student_id
                ]
            ];

            // Optionally calculate summary statistics (for quick display, not required)
            // This doesn't modify the original data
            $summary = calculateScoreSummary($original_scores);
            $result_data['summary'] = $summary;

            // Prepare other fields
            $teachers_comment = isset($result['teachers_comment']) ? $result['teachers_comment'] : null;
            $principals_comment = isset($result['principals_comment']) ? $result['principals_comment'] : null;
            $class_position = isset($result['class_position']) ? $result['class_position'] : null;
            $class_total = isset($result['class_total_students']) ? $result['class_total_students'] : null;
            $promoted_to = isset($result['promoted_to']) ? $result['promoted_to'] : null;
            $days_present = isset($result['days_present']) ? $result['days_present'] : 0;
            $days_absent = isset($result['days_absent']) ? $result['days_absent'] : 0;

            // Affective traits
            $affective_traits = null;
            if (isset($result['affective_traits']) && is_array($result['affective_traits'])) {
                $affective_traits = json_encode($result['affective_traits']);
            }

            // Psychomotor skills
            $psychomotor_skills = null;
            if (isset($result['psychomotor_skills']) && is_array($result['psychomotor_skills'])) {
                $psychomotor_skills = json_encode($result['psychomotor_skills']);
            }

            // Check if result already exists
            $stmt = $conn->prepare("
                SELECT id FROM results 
                WHERE school_id = ? AND student_id = ? AND session_year = ? AND term = ?
            ");
            $stmt->execute([$school_id, $student_id, $result['session_year'], $result['term']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            // Total marks display (optional - can be empty string)
            $total_marks_display = $summary['total_display'] ?? '';

            if ($existing) {
                // Update existing result
                $stmt = $conn->prepare("
                    UPDATE results SET
                        result_data = ?,
                        total_marks = ?,
                        average = ?,
                        grade = ?,
                        class_position = ?,
                        class_total_students = ?,
                        promoted_to = ?,
                        teachers_comment = ?,
                        principals_comment = ?,
                        days_present = ?,
                        days_absent = ?,
                        affective_traits = ?,
                        psychomotor_skills = ?,
                        is_published = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    json_encode($result_data),
                    $total_marks_display,
                    $summary['average'] ?? null,
                    $summary['grade'] ?? null,
                    $class_position,
                    $class_total,
                    $promoted_to,
                    $teachers_comment,
                    $principals_comment,
                    $days_present,
                    $days_absent,
                    $affective_traits,
                    $psychomotor_skills,
                    1,
                    $existing['id']
                ]);
                debug_log("Updated existing result", ['student' => $result['admission_number']]);
            } else {
                // Insert new result
                $stmt = $conn->prepare("
                    INSERT INTO results (
                        school_id, student_id, session_year, term,
                        result_data, total_marks, average, grade,
                        class_position, class_total_students, promoted_to,
                        teachers_comment, principals_comment,
                        days_present, days_absent, affective_traits, psychomotor_skills,
                        is_published, synced_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $school_id,
                    $student_id,
                    $result['session_year'],
                    $result['term'],
                    json_encode($result_data),
                    $total_marks_display,
                    $summary['average'] ?? null,
                    $summary['grade'] ?? null,
                    $class_position,
                    $class_total,
                    $promoted_to,
                    $teachers_comment,
                    $principals_comment,
                    $days_present,
                    $days_absent,
                    $affective_traits,
                    $psychomotor_skills,
                    1
                ]);
                debug_log("Inserted new result", ['student' => $result['admission_number']]);
            }

            $synced++;
        } catch (PDOException $e) {
            $failed++;
            $errors[] = "Error for {$result['admission_number']}: " . $e->getMessage();
            debug_log("PDOException", ['error' => $e->getMessage(), 'student' => $result['admission_number']]);
        }
    }

    debug_log("syncResults complete", ['synced' => $synced, 'failed' => $failed]);

    return [
        'success' => true,
        'message' => "Results synced: $synced successful, $failed failed",
        'data' => [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors
        ]
    ];
}

/**
 * Calculate summary statistics from original scores without modifying them
 */
function calculateScoreSummary($scores)
{
    if (empty($scores) || !is_array($scores)) {
        return [
            'total_scored' => 0,
            'total_max' => 0,
            'average' => 0,
            'grade' => 'F',
            'total_display' => '0/0',
            'subjects_count' => 0
        ];
    }

    $total_scored = 0;
    $total_max = 0;
    $subjects_processed = 0;

    foreach ($scores as $score) {
        $subject_total = 0;
        $subject_max = 0;

        // Get the score data (could be in 'score_data' key or directly in the array)
        $data = isset($score['score_data']) && is_array($score['score_data'])
            ? $score['score_data']
            : $score;

        // Remove non-numeric keys for value extraction
        $exclude_keys = ['subject_name', 'subject_id', 'score_data', 'raw_values'];

        // Sum all numeric values in the score data
        foreach ($data as $key => $value) {
            if (in_array($key, $exclude_keys)) {
                continue;
            }

            if (is_numeric($value)) {
                // Check if this looks like a max value
                if (stripos($key, 'max') !== false || stripos($key, 'out_of') !== false || stripos($key, 'total') !== false) {
                    $subject_max += floatval($value);
                } else {
                    // Assume it's a scored value
                    $subject_total += floatval($value);
                }
            }
        }

        // If no max values found, try to infer from keys like 'ca1_max', 'exam_max'
        foreach ($data as $key => $value) {
            if (is_numeric($value) && (stripos($key, 'max') !== false || stripos($key, 'out_of') !== false)) {
                $subject_max += floatval($value);
            }
        }

        // If still no max, assume perfect score equals total (or use default)
        if ($subject_max == 0 && $subject_total > 0) {
            $subject_max = $subject_total;
        }

        $total_scored += $subject_total;
        $total_max += $subject_max;
        $subjects_processed++;
    }

    $average = $total_max > 0 ? round(($total_scored / $total_max) * 100, 2) : 0;
    $grade = calculateGrade($average);

    return [
        'total_scored' => $total_scored,
        'total_max' => $total_max,
        'average' => $average,
        'grade' => $grade,
        'total_display' => $total_scored . '/' . $total_max,
        'subjects_count' => $subjects_processed
    ];
}

/**
 * Full sync - students and results
 */
function syncFull($conn, $school_id, $input)
{
    $response = [
        'success' => true,
        'message' => 'Full sync completed',
        'data' => []
    ];

    // Sync students if provided
    if (isset($input['students']) && is_array($input['students'])) {
        debug_log("syncFull: Processing students", ['count' => count($input['students'])]);
        $student_result = syncStudents($conn, $school_id, $input);
        $response['data']['students'] = $student_result['data'];
    }

    // Sync results if provided
    if (isset($input['results']) && is_array($input['results'])) {
        debug_log("syncFull: Processing results", ['count' => count($input['results'])]);
        $result_result = syncResults($conn, $school_id, $input);
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
 * Sync students from school
 */
function syncStudents($conn, $school_id, $input)
{
    if (!isset($input['students']) || !is_array($input['students'])) {
        return ['success' => false, 'error' => 'Missing or invalid students data', 'data' => ['synced' => 0, 'updated' => 0, 'errors' => []]];
    }

    $students = $input['students'];
    $synced = 0;
    $updated = 0;
    $errors = [];

    foreach ($students as $student) {
        if (empty($student['admission_number']) || empty($student['full_name'])) {
            $errors[] = 'Admission number and full name are required';
            continue;
        }

        try {
            // Insert or update student
            $stmt = $conn->prepare("
                INSERT INTO students (
                    school_id, admission_number, full_name, class,
                    gender, date_of_birth, parent_phone, parent_email, status, last_sync_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    class = VALUES(class),
                    gender = VALUES(gender),
                    date_of_birth = VALUES(date_of_birth),
                    parent_phone = VALUES(parent_phone),
                    parent_email = VALUES(parent_email),
                    status = VALUES(status),
                    last_sync_at = NOW()
            ");

            $stmt->execute([
                $school_id,
                trim($student['admission_number']),
                trim($student['full_name']),
                $student['class'] ?? null,
                $student['gender'] ?? null,
                !empty($student['date_of_birth']) ? $student['date_of_birth'] : null,
                $student['parent_phone'] ?? null,
                $student['parent_email'] ?? null,
                $student['status'] ?? 'active'
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
        'data' => [
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors
        ]
    ];
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
