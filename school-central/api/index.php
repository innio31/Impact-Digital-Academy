<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'auth.php';

// Get action from URL
$action = $_GET['action'] ?? '';

// Log all API requests (except auth check)
if ($action != 'auth') {
    $api_key = $_GET['api_key'] ?? '';
    if (!empty($api_key)) {
        $stmt = $pdo->prepare("SELECT id FROM schools WHERE api_key = ?");
        $stmt->execute([$api_key]);
        $school = $stmt->fetch();
        if ($school) {
            logApiCall($pdo, $school['id'], $action, http_response_code());
        }
    }
}

// Route to appropriate function
switch ($action) {
    case 'auth':
        testAuth();
        break;

    case 'get_subjects':
        getSubjects();
        break;

    case 'get_topics':
        getTopics();
        break;

    case 'get_questions':
        getQuestions();
        break;

    case 'get_question_count':
        getQuestionCount();
        break;

    case 'sync_status':
        getSyncStatus();
        break;

    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action',
            'available_actions' => [
                'auth',
                'get_subjects',
                'get_topics',
                'get_questions',
                'get_question_count',
                'sync_status'
            ]
        ]);
}

function testAuth()
{
    $school = authenticateSchool();
    echo json_encode([
        'status' => 'success',
        'message' => 'Authentication successful',
        'school' => [
            'name' => $school['school_name'],
            'code' => $school['school_code'],
            'expiry' => $school['subscription_expiry']
        ]
    ]);
}

function getSubjects()
{
    global $pdo;

    $stmt = $pdo->query("SELECT id, subject_name, subject_code, description FROM master_subjects WHERE is_active = 1 ORDER BY subject_name");
    $subjects = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'count' => count($subjects),
        'subjects' => $subjects
    ]);
}

function getTopics()
{
    global $pdo;

    $subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

    if ($subject_id > 0) {
        $stmt = $pdo->prepare("SELECT id, topic_name, subject_id, description, difficulty_level, class_range FROM master_topics WHERE subject_id = ? ORDER BY topic_name");
        $stmt->execute([$subject_id]);
    } else {
        $stmt = $pdo->query("SELECT id, topic_name, subject_id, description, difficulty_level, class_range FROM master_topics ORDER BY subject_id, topic_name");
    }

    $topics = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'count' => count($topics),
        'topics' => $topics
    ]);
}

function getQuestions()
{
    global $pdo;
    $school = authenticateSchool();

    // Get parameters
    $subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
    $topic_id = isset($_GET['topic_id']) ? intval($_GET['topic_id']) : 0;
    $class = isset($_GET['class']) ? $_GET['class'] : '';
    $difficulty = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
    $limit = isset($_GET['limit']) ? min(intval($_GET['limit']), MAX_QUESTIONS_PER_BATCH) : 50;
    $type = isset($_GET['type']) ? $_GET['type'] : 'objective';

    $batch_id = uniqid('batch_');
    $response = [
        'status' => 'success',
        'batch_id' => $batch_id,
        'download_date' => date('Y-m-d H:i:s')
    ];

    if ($type == 'objective' || $type == 'all') {
        // Build query
        $sql = "SELECT 
                    id,
                    question_text,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    correct_answer,
                    subject_id,
                    topic_id,
                    difficulty_level,
                    marks,
                    class_level,
                    explanation
                FROM central_objective_questions 
                WHERE is_approved = 1";

        $params = [];

        if ($subject_id > 0) {
            $sql .= " AND subject_id = ?";
            $params[] = $subject_id;
        }
        if ($topic_id > 0) {
            $sql .= " AND topic_id = ?";
            $params[] = $topic_id;
        }
        if (!empty($class)) {
            $sql .= " AND class_level = ?";
            $params[] = $class;
        }
        if (!empty($difficulty)) {
            $sql .= " AND difficulty_level = ?";
            $params[] = $difficulty;
        }

        $sql .= " ORDER BY RAND() LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();

        $response['objective'] = [
            'count' => count($questions),
            'questions' => $questions
        ];

        // Update times_used for downloaded questions
        foreach ($questions as $q) {
            $update = $pdo->prepare("UPDATE central_objective_questions SET times_used = times_used + 1, last_used = CURDATE() WHERE id = ?");
            $update->execute([$q['id']]);

            // Log download
            $log = $pdo->prepare("INSERT INTO question_downloads (school_id, question_type, question_id, download_batch) VALUES (?, 'objective', ?, ?)");
            $log->execute([$school['id'], $q['id'], $batch_id]);
        }
    }

    if ($type == 'theory' || $type == 'all') {
        // Similar for theory questions
        $sql = "SELECT 
                    id,
                    question_text,
                    question_file,
                    subject_id,
                    topic_id,
                    class_level,
                    marks,
                    difficulty_level,
                    model_answer
                FROM central_theory_questions 
                WHERE is_approved = 1";

        $params = [];

        if ($subject_id > 0) {
            $sql .= " AND subject_id = ?";
            $params[] = $subject_id;
        }
        if ($topic_id > 0) {
            $sql .= " AND topic_id = ?";
            $params[] = $topic_id;
        }
        if (!empty($class)) {
            $sql .= " AND class_level = ?";
            $params[] = $class;
        }

        $sql .= " ORDER BY RAND() LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();

        $response['theory'] = [
            'count' => count($questions),
            'questions' => $questions
        ];
    }

    echo json_encode($response);
}

function getQuestionCount()
{
    global $pdo;
    authenticateSchool(); // Verify API key but don't need school data

    $subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN difficulty_level = 'easy' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN difficulty_level = 'medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN difficulty_level = 'hard' THEN 1 ELSE 0 END) as hard
            FROM central_objective_questions 
            WHERE is_approved = 1";

    $params = [];
    if ($subject_id > 0) {
        $sql .= " AND subject_id = ?";
        $params[] = $subject_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts = $stmt->fetch();

    echo json_encode([
        'status' => 'success',
        'counts' => $counts
    ]);
}

function getSyncStatus()
{
    global $pdo;
    $school = authenticateSchool();

    echo json_encode([
        'status' => 'success',
        'school' => $school['school_name'],
        'last_sync' => date('Y-m-d H:i:s'),
        'api_version' => API_VERSION
    ]);
}
