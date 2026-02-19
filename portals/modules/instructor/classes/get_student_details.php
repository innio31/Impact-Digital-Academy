<?php
// modules/instructor/classes/get_student_details.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit();
}

// Check required parameters
if (!isset($_GET['student_id']) || !isset($_GET['class_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

$student_id = (int)$_GET['student_id'];
$class_id = (int)$_GET['class_id'];
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Verify the instructor has access to this class
$sql = "SELECT 1 FROM class_batches WHERE id = ? AND instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}
$stmt->close();

// Get student details with enrollment info
$sql = "SELECT 
            u.id, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
            up.date_of_birth, up.gender, up.address, up.city, up.state, up.country,
            up.bio, up.linkedin_url, up.github_url,
            e.enrollment_date, e.status as enrollment_status, e.final_grade,
            sfs.total_fee, sfs.paid_amount, sfs.balance, sfs.is_cleared,
            sfs.registration_paid, sfs.block1_paid, sfs.block2_paid,
            sfs.is_suspended, sfs.suspension_reason
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        JOIN enrollments e ON u.id = e.student_id
        LEFT JOIN student_financial_status sfs ON u.id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE u.id = ? AND e.class_id = ? AND u.role = 'student'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    http_response_code(404);
    echo json_encode(['error' => 'Student not found in this class']);
    exit();
}

$student = $result->fetch_assoc();
$stmt->close();

// Get assignment statistics
$sql = "SELECT 
            COUNT(DISTINCT a.id) as total_assignments,
            COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN a.id END) as submitted_assignments,
            COUNT(DISTINCT CASE WHEN s.grade IS NOT NULL THEN a.id END) as graded_assignments,
            AVG(CASE WHEN s.grade IS NOT NULL THEN s.grade END) as average_grade,
            COUNT(DISTINCT CASE WHEN s.late_submission = 1 THEN a.id END) as late_submissions
        FROM assignments a
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
        WHERE a.class_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$assignment_stats = $result->fetch_assoc();
$stmt->close();

// Get attendance statistics
$sql = "SELECT 
            COUNT(*) as total_sessions,
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
            COUNT(CASE WHEN status = 'excused' THEN 1 END) as excused_count
        FROM attendance
        WHERE enrollment_id = (SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$attendance_stats = $result->fetch_assoc();
$stmt->close();

// Get recent submissions (last 5)
$sql = "SELECT 
            a.title, s.submitted_at, s.grade, s.status, s.feedback,
            DATE_FORMAT(s.submitted_at, '%Y-%m-%d %H:%i') as formatted_date
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.student_id = ? AND a.class_id = ?
        ORDER BY s.submitted_at DESC
        LIMIT 5";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_submissions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate attendance percentage
$attendance_percentage = 0;
if ($attendance_stats['total_sessions'] > 0) {
    $attendance_percentage = ($attendance_stats['present_count'] / $attendance_stats['total_sessions']) * 100;
}

// Prepare response data
$response = [
    'success' => true,
    'student' => [
        'basic_info' => [
            'full_name' => $student['first_name'] . ' ' . $student['last_name'],
            'email' => $student['email'],
            'phone' => $student['phone'] ?? 'Not provided',
            'enrollment_date' => date('M d, Y', strtotime($student['enrollment_date'])),
            'enrollment_status' => ucfirst($student['enrollment_status']),
            'final_grade' => $student['final_grade'] ?? 'Not assigned'
        ],
        'personal_info' => [
            'date_of_birth' => $student['date_of_birth'] ? date('M d, Y', strtotime($student['date_of_birth'])) : 'Not provided',
            'gender' => $student['gender'] ? ucfirst($student['gender']) : 'Not specified',
            'location' => implode(', ', array_filter([
                $student['city'],
                $student['state'],
                $student['country']
            ])) ?: 'Not provided',
            'bio' => $student['bio'] ?? 'No bio available'
        ],
        'financial_info' => [
            'total_fee' => number_format($student['total_fee'] ?? 0, 2),
            'paid_amount' => number_format($student['paid_amount'] ?? 0, 2),
            'balance' => number_format($student['balance'] ?? 0, 2),
            'is_cleared' => (bool)($student['is_cleared'] ?? false),
            'is_suspended' => (bool)($student['is_suspended'] ?? false),
            'suspension_reason' => $student['suspension_reason'] ?? 'Not suspended',
            'payment_status' => [
                'registration' => (bool)($student['registration_paid'] ?? false),
                'block1' => (bool)($student['block1_paid'] ?? false),
                'block2' => (bool)($student['block2_paid'] ?? false)
            ]
        ],
        'performance_stats' => [
            'total_assignments' => $assignment_stats['total_assignments'] ?? 0,
            'submitted_assignments' => $assignment_stats['submitted_assignments'] ?? 0,
            'graded_assignments' => $assignment_stats['graded_assignments'] ?? 0,
            'submission_rate' => $assignment_stats['total_assignments'] > 0 ?
                round(($assignment_stats['submitted_assignments'] / $assignment_stats['total_assignments']) * 100, 1) : 0,
            'average_grade' => $assignment_stats['average_grade'] ? round($assignment_stats['average_grade'], 1) : 'N/A',
            'late_submissions' => $assignment_stats['late_submissions'] ?? 0
        ],
        'attendance_stats' => [
            'total_sessions' => $attendance_stats['total_sessions'] ?? 0,
            'present' => $attendance_stats['present_count'] ?? 0,
            'absent' => $attendance_stats['absent_count'] ?? 0,
            'late' => $attendance_stats['late_count'] ?? 0,
            'excused' => $attendance_stats['excused_count'] ?? 0,
            'attendance_rate' => round($attendance_percentage, 1)
        ],
        'recent_submissions' => $recent_submissions
    ]
];

$conn->close();

header('Content-Type: application/json');
echo json_encode($response);
