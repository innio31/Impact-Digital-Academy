<?php
// ajax/save_course_fees.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/finance_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$program_id = $_POST['program_id'] ?? 0;
$course_fees = $_POST['course_fees'] ?? [];

if (!$program_id) {
    echo json_encode(['success' => false, 'error' => 'Program ID required']);
    exit();
}

$conn = getDBConnection();

// Start transaction
$conn->begin_transaction();

try {
    foreach ($course_fees as $course_id => $fee) {
        $course_id = (int)$course_id;
        $fee = (float)$fee;

        // Validate course belongs to program
        $check_sql = "SELECT id FROM courses WHERE id = ? AND program_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $course_id, $program_id);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0) {
            // Save course fee
            $sql = "INSERT INTO course_fees (program_id, course_id, fee, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    fee = VALUES(fee), 
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iidi", $program_id, $course_id, $fee, $_SESSION['user_id']);
            $stmt->execute();
        }
    }

    $conn->commit();

    // Log activity
    logActivity(
        'course_fees_updated',
        "Updated course fees for program ID: $program_id",
        'programs',
        $program_id
    );

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
