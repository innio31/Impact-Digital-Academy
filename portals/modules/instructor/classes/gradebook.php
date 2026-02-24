<?php
// modules/instructor/classes/gradebook.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Handle actions
$action = $_GET['action'] ?? '';
$assignment_id = $_GET['assignment_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_grade'])) {
        // Submit individual grade
        $submission_id = (int)$_POST['submission_id'];
        $grade = (float)$_POST['grade'];
        $feedback = $conn->real_escape_string($_POST['feedback'] ?? '');

        // Get max points for validation
        $sql = "SELECT a.total_points, a.id as assignment_id, s.student_id 
                FROM assignment_submissions s
                JOIN assignments a ON s.assignment_id = a.id
                WHERE s.id = ? AND a.class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $submission_id, $class_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $max_points = $data['total_points'];

            // Validate grade
            if ($grade < 0 || $grade > $max_points) {
                $error = "Grade must be between 0 and {$max_points}";
            } else {
                // Update submission
                $sql = "UPDATE assignment_submissions 
                        SET grade = ?, feedback = ?, graded_by = ?, graded_at = NOW(), status = 'graded'
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("dsii", $grade, $feedback, $instructor_id, $submission_id);

                if ($stmt->execute()) {
                    // Update or insert into gradebook
                    $percentage = ($grade / $max_points) * 100;
                    $grade_letter = calculateGradeLetter($percentage);

                    // Get enrollment_id
                    $sql_enroll = "SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?";
                    $stmt_enroll = $conn->prepare($sql_enroll);
                    $stmt_enroll->bind_param("ii", $data['student_id'], $class_id);
                    $stmt_enroll->execute();
                    $result_enroll = $stmt_enroll->get_result();

                    if ($result_enroll->num_rows > 0) {
                        $enrollment = $result_enroll->fetch_assoc();
                        $enrollment_id = $enrollment['id'];

                        // Check if gradebook entry exists
                        $sql_check = "SELECT id FROM gradebook 
                                     WHERE enrollment_id = ? AND assignment_id = ? AND student_id = ?";
                        $stmt_check = $conn->prepare($sql_check);
                        $stmt_check->bind_param("iii", $enrollment_id, $data['assignment_id'], $data['student_id']);
                        $stmt_check->execute();
                        $result_check = $stmt_check->get_result();

                        if ($result_check->num_rows > 0) {
                            // Update existing
                            $sql_update = "UPDATE gradebook 
                                          SET score = ?, percentage = ?, grade_letter = ?, notes = ?, updated_at = NOW()
                                          WHERE enrollment_id = ? AND assignment_id = ? AND student_id = ?";
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->bind_param(
                                "dddsiii",
                                $grade,
                                $percentage,
                                $grade_letter,
                                $feedback,
                                $enrollment_id,
                                $data['assignment_id'],
                                $data['student_id']
                            );
                            $stmt_update->execute();
                            $stmt_update->close();
                        } else {
                            // Insert new
                            $sql_insert = "INSERT INTO gradebook 
                                          (enrollment_id, assignment_id, student_id, score, max_score, 
                                           percentage, grade_letter, notes, created_at, updated_at)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                            $stmt_insert = $conn->prepare($sql_insert);
                            $stmt_insert->bind_param(
                                "iiidddds",
                                $enrollment_id,
                                $data['assignment_id'],
                                $data['student_id'],
                                $grade,
                                $max_points,
                                $percentage,
                                $grade_letter,
                                $feedback
                            );
                            $stmt_insert->execute();
                            $stmt_insert->close();
                        }

                        $stmt_check->close();
                    }
                    $stmt_enroll->close();

                    $success = "Grade submitted successfully!";
                    logActivity('grade_assignment', "Graded submission #{$submission_id}", 'assignment_submissions', $submission_id);
                } else {
                    $error = "Failed to submit grade. Please try again.";
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['bulk_grade'])) {
        // Bulk grade submissions
        if (!empty($_POST['grades'])) {
            $processed = 0;
            $errors = [];

            foreach ($_POST['grades'] as $submission_id => $grade_data) {
                $submission_id = (int)$submission_id;
                $grade = (float)$grade_data['grade'];
                $feedback = $conn->real_escape_string($grade_data['feedback'] ?? '');

                // Get submission details
                $sql = "SELECT a.total_points, a.id as assignment_id, s.student_id 
                        FROM assignment_submissions s
                        JOIN assignments a ON s.assignment_id = a.id
                        WHERE s.id = ? AND a.class_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $submission_id, $class_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $data = $result->fetch_assoc();
                    $max_points = $data['total_points'];

                    if ($grade >= 0 && $grade <= $max_points) {
                        // Update submission
                        $sql_update = "UPDATE assignment_submissions 
                                      SET grade = ?, feedback = ?, graded_by = ?, graded_at = NOW(), status = 'graded'
                                      WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->bind_param("dsii", $grade, $feedback, $instructor_id, $submission_id);

                        if ($stmt_update->execute()) {
                            // Update gradebook (similar to individual grading)
                            $percentage = ($grade / $max_points) * 100;
                            $grade_letter = calculateGradeLetter($percentage);

                            // Get enrollment_id
                            $sql_enroll = "SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?";
                            $stmt_enroll = $conn->prepare($sql_enroll);
                            $stmt_enroll->bind_param("ii", $data['student_id'], $class_id);
                            $stmt_enroll->execute();
                            $result_enroll = $stmt_enroll->get_result();

                            if ($result_enroll->num_rows > 0) {
                                $enrollment = $result_enroll->fetch_assoc();
                                $enrollment_id = $enrollment['id'];

                                // Check and update gradebook
                                $sql_check = "SELECT id FROM gradebook 
                                             WHERE enrollment_id = ? AND assignment_id = ? AND student_id = ?";
                                $stmt_check = $conn->prepare($sql_check);
                                $stmt_check->bind_param("iii", $enrollment_id, $data['assignment_id'], $data['student_id']);
                                $stmt_check->execute();
                                $result_check = $stmt_check->get_result();

                                if ($result_check->num_rows > 0) {
                                    $sql_gb_update = "UPDATE gradebook 
                                                     SET score = ?, percentage = ?, grade_letter = ?, notes = ?, updated_at = NOW()
                                                     WHERE enrollment_id = ? AND assignment_id = ? AND student_id = ?";
                                    $stmt_gb_update = $conn->prepare($sql_gb_update);
                                    $stmt_gb_update->bind_param(
                                        "dddsiii",
                                        $grade,
                                        $percentage,
                                        $grade_letter,
                                        $feedback,
                                        $enrollment_id,
                                        $data['assignment_id'],
                                        $data['student_id']
                                    );
                                    $stmt_gb_update->execute();
                                    $stmt_gb_update->close();
                                } else {
                                    $sql_gb_insert = "INSERT INTO gradebook 
                                                     (enrollment_id, assignment_id, student_id, score, max_score, 
                                                      percentage, grade_letter, notes, created_at, updated_at)
                                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                                    $stmt_gb_insert = $conn->prepare($sql_gb_insert);
                                    $stmt_gb_insert->bind_param(
                                        "iiidddds",
                                        $enrollment_id,
                                        $data['assignment_id'],
                                        $data['student_id'],
                                        $grade,
                                        $max_points,
                                        $percentage,
                                        $grade_letter,
                                        $feedback
                                    );
                                    $stmt_gb_insert->execute();
                                    $stmt_gb_insert->close();
                                }
                                $stmt_check->close();
                            }
                            $stmt_enroll->close();

                            $processed++;
                            logActivity('grade_assignment', "Bulk graded submission #{$submission_id}", 'assignment_submissions', $submission_id);
                        }
                        $stmt_update->close();
                    } else {
                        $errors[] = "Submission #{$submission_id}: Invalid grade (must be 0-{$max_points})";
                    }
                }
                $stmt->close();
            }

            if ($processed > 0) {
                $success = "Successfully graded {$processed} submissions!";
            }
            if (!empty($errors)) {
                $error = implode('<br>', $errors);
            }
        }
    } elseif (isset($_POST['export_grades'])) {
        // Export grades to CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="grades_' . $class['batch_code'] . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // Get all assignments
        $sql_assignments = "SELECT 'assignment' as type, id, title, total_points, due_date 
                           FROM assignments WHERE class_id = ?
                           UNION ALL
                           SELECT 'quiz' as type, id, title, total_points, available_to as due_date 
                           FROM quizzes WHERE class_id = ? AND quiz_type = 'graded'
                           ORDER BY due_date";
        $stmt_assignments = $conn->prepare($sql_assignments);
        $stmt_assignments->bind_param("ii", $class_id, $class_id);
        $stmt_assignments->execute();
        $assignments = $stmt_assignments->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_assignments->close();

        // Header row
        $header = ['Student ID', 'Name', 'Email', 'Enrollment Date'];
        foreach ($assignments as $assignment) {
            $header[] = $assignment['title'] . " ({$assignment['total_points']} pts)";
        }
        $header[] = 'Total Score';
        $header[] = 'Average %';
        $header[] = 'Final Grade';
        fputcsv($output, $header);

        // Get all students with their grades
        $sql_students = "SELECT 
            u.id, u.first_name, u.last_name, u.email, e.enrollment_date,
            GROUP_CONCAT(DISTINCT CONCAT('a:', g.assignment_id, ':', COALESCE(g.score, 0), ':', COALESCE(g.max_score, 100)) SEPARATOR ';') as assignment_data,
            GROUP_CONCAT(DISTINCT CONCAT('q:', qa.quiz_id, ':', COALESCE(qa.total_score, 0), ':', COALESCE(qa.max_score, 100)) SEPARATOR ';') as quiz_data
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            LEFT JOIN gradebook g ON e.id = g.enrollment_id AND g.student_id = u.id
            LEFT JOIN (
                SELECT 
                    qa.student_id,
                    q.id as quiz_id,
                    MAX(qa.total_score) as total_score,
                    q.total_points as max_score
                FROM quiz_attempts qa
                JOIN quizzes q ON qa.quiz_id = q.id
                WHERE q.class_id = ? AND qa.status = 'graded'
                GROUP BY qa.student_id, q.id
            ) qa ON u.id = qa.student_id
            WHERE e.class_id = ? AND e.status = 'active'
            GROUP BY u.id, u.first_name, u.last_name, u.email, e.enrollment_date
            ORDER BY u.last_name, u.first_name";

        $stmt_students = $conn->prepare($sql_students);
        $stmt_students->bind_param("ii", $class_id, $class_id);
        $stmt_students->execute();
        $students = $stmt_students->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_students->close();

        foreach ($students as $student) {
            $row = [
                $student['id'],
                $student['first_name'] . ' ' . $student['last_name'],
                $student['email'],
                $student['enrollment_date']
            ];

            $assignment_data = [];
            if ($student['assignment_data']) {
                $pairs = explode(';', $student['assignment_data']);
                foreach ($pairs as $pair) {
                    if (strpos($pair, 'a:') === 0) {
                        list($type, $ass_id, $score, $max) = explode(':', $pair);
                        $assignment_data[$ass_id] = ['score' => $score, 'max' => $max];
                    }
                }
            }

            $quiz_data = [];
            if ($student['quiz_data']) {
                $pairs = explode(';', $student['quiz_data']);
                foreach ($pairs as $pair) {
                    if (strpos($pair, 'q:') === 0) {
                        list($type, $quiz_id, $score, $max) = explode(':', $pair);
                        $quiz_data[$quiz_id] = ['score' => $score, 'max' => $max];
                    }
                }
            }

            $total_score = 0;
            $total_max = 0;

            foreach ($assignments as $assessment) {
                if ($assessment['type'] === 'assignment') {
                    $score = $assignment_data[$assessment['id']]['score'] ?? 0;
                    $max = $assignment_data[$assessment['id']]['max'] ?? $assessment['total_points'];
                } else {
                    $score = $quiz_data[$assessment['id']]['score'] ?? 0;
                    $max = $quiz_data[$assessment['id']]['max'] ?? $assessment['total_points'];
                }
                $row[] = $score;
                $total_score += $score;
                $total_max += $max;
            }

            $average = $total_max > 0 ? ($total_score / $total_max) * 100 : 0;
            $final_grade = calculateGradeLetter($average);

            $row[] = $total_score;
            $row[] = round($average, 2);
            $row[] = $final_grade;

            fputcsv($output, $row);
        }

        fclose($output);
        exit();
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';

// Build query based on filter (for assignment submissions only)
$where_conditions = ["e.class_id = ?", "e.status = 'active'"];
$params = [$class_id];
$param_types = "i";

if ($filter === 'ungraded') {
    $where_conditions[] = "s.status = 'submitted' AND s.grade IS NULL";
} elseif ($filter === 'graded') {
    $where_conditions[] = "s.grade IS NOT NULL";
}

// Get assignments for this class
$sql_assignments = "SELECT id, title, total_points, due_date FROM assignments 
                   WHERE class_id = ? ORDER BY due_date";
$stmt_assignments = $conn->prepare($sql_assignments);
$stmt_assignments->bind_param("i", $class_id);
$stmt_assignments->execute();
$assignments_result = $stmt_assignments->get_result();
$assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
$stmt_assignments->close();

// Get quizzes for this class
$sql_quizzes = "SELECT id, title, total_points, available_to as due_date FROM quizzes 
               WHERE class_id = ? AND quiz_type = 'graded' 
               ORDER BY available_to";
$stmt_quizzes = $conn->prepare($sql_quizzes);
$stmt_quizzes->bind_param("i", $class_id);
$stmt_quizzes->execute();
$quizzes_result = $stmt_quizzes->get_result();
$quizzes = $quizzes_result->fetch_all(MYSQLI_ASSOC);
$stmt_quizzes->close();

// Combine assignments and quizzes for display
$all_assessments = array_merge(
    array_map(function ($a) {
        $a['type'] = 'assignment';
        $a['due_date'] = $a['due_date'] ?? null;
        return $a;
    }, $assignments),
    array_map(function ($q) {
        $q['type'] = 'quiz';
        $q['due_date'] = $q['due_date'] ?? null;
        return $q;
    }, $quizzes)
);

// Sort by due date
usort($all_assessments, function ($a, $b) {
    $dateA = strtotime($a['due_date'] ?? '9999-12-31');
    $dateB = strtotime($b['due_date'] ?? '9999-12-31');
    return $dateA - $dateB;
});

// Get all enrolled students with their grades (assignments + quizzes)
$sql_students = "SELECT 
    u.id as student_id, 
    u.first_name, 
    u.last_name, 
    u.email, 
    e.id as enrollment_id, 
    e.enrollment_date,
    GROUP_CONCAT(DISTINCT CONCAT(
        'a:', g.assignment_id, ':', 
        COALESCE(g.score, 0), ':', 
        COALESCE(g.max_score, 100), ':', 
        COALESCE(g.percentage, 0), ':', 
        COALESCE(g.grade_letter, '')
    ) SEPARATOR ';') as assignment_grades,
    GROUP_CONCAT(DISTINCT CONCAT(
        'q:', qa.quiz_id, ':', 
        COALESCE(qa.total_score, 0), ':', 
        COALESCE(qa.max_score, 100), ':', 
        COALESCE(qa.percentage, 0), ':'
    ) SEPARATOR ';') as quiz_grades
FROM enrollments e
JOIN users u ON e.student_id = u.id
LEFT JOIN gradebook g ON e.id = g.enrollment_id AND g.student_id = u.id
LEFT JOIN (
    SELECT 
        qa.student_id,
        q.id as quiz_id,
        MAX(qa.total_score) as total_score,
        q.total_points as max_score,
        MAX((qa.total_score / q.total_points) * 100) as percentage
    FROM quiz_attempts qa
    JOIN quizzes q ON qa.quiz_id = q.id
    WHERE q.class_id = ? AND qa.status = 'graded'
    GROUP BY qa.student_id, q.id
) qa ON u.id = qa.student_id
WHERE e.class_id = ? AND e.status = 'active'
GROUP BY u.id, u.first_name, u.last_name, u.email, e.id, e.enrollment_date
ORDER BY u.last_name, u.first_name";

$stmt_students = $conn->prepare($sql_students);
$stmt_students->bind_param("ii", $class_id, $class_id);
$stmt_students->execute();
$students_result = $stmt_students->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$stmt_students->close();

// Process grade data for each student
$student_grades = [];
$class_stats = [
    'total_students' => count($students),
    'graded_assignments' => 0,
    'graded_quizzes' => 0,
    'average_score' => 0,
    'top_performer' => null,
    'needs_attention' => []
];

foreach ($students as $student) {
    $grades = [];
    $total_score = 0;
    $total_max = 0;
    $assignment_count = 0;
    $quiz_count = 0;

    // Process assignment grades
    if ($student['assignment_grades']) {
        $pairs = explode(';', $student['assignment_grades']);
        foreach ($pairs as $pair) {
            if (strpos($pair, 'a:') === 0) {
                list($type, $ass_id, $score, $max, $percentage, $grade_letter) = explode(':', $pair);
                $grades['a' . $ass_id] = [
                    'type' => 'assignment',
                    'id' => $ass_id,
                    'score' => (float)$score,
                    'max' => (float)$max,
                    'percentage' => (float)$percentage,
                    'grade_letter' => $grade_letter
                ];
                $total_score += (float)$score;
                $total_max += (float)$max;
                $assignment_count++;
            }
        }
    }

    // Process quiz grades
    if ($student['quiz_grades']) {
        $pairs = explode(';', $student['quiz_grades']);
        foreach ($pairs as $pair) {
            if (strpos($pair, 'q:') === 0) {
                list($type, $quiz_id, $score, $max, $percentage) = explode(':', $pair);
                $grades['q' . $quiz_id] = [
                    'type' => 'quiz',
                    'id' => $quiz_id,
                    'score' => (float)$score,
                    'max' => (float)$max,
                    'percentage' => (float)$percentage,
                    'grade_letter' => calculateGradeLetter((float)$percentage)
                ];
                $total_score += (float)$score;
                $total_max += (float)$max;
                $quiz_count++;
            }
        }
    }

    $average = $total_max > 0 ? ($total_score / $total_max) * 100 : 0;
    $final_grade = calculateGradeLetter($average);

    $student_grades[$student['student_id']] = [
        'student' => $student,
        'grades' => $grades,
        'total_score' => $total_score,
        'total_max' => $total_max,
        'average' => $average,
        'final_grade' => $final_grade,
        'assignment_count' => $assignment_count,
        'quiz_count' => $quiz_count
    ];

    // Update class stats
    if ($average > $class_stats['average_score']) {
        $class_stats['average_score'] = $average;
        $class_stats['top_performer'] = $student['first_name'] . ' ' . $student['last_name'];
    }

    if ($average < 50 && (count($grades) > 2)) {
        $class_stats['needs_attention'][] = $student['first_name'] . ' ' . $student['last_name'];
    }
}

$class_stats['average_score'] = round($class_stats['average_score'], 2);

// Get submissions that need grading
$sql_pending = "SELECT COUNT(s.id) as count 
               FROM assignment_submissions s
               JOIN assignments a ON s.assignment_id = a.id
               WHERE a.class_id = ? AND s.status = 'submitted' AND s.grade IS NULL";
$stmt_pending = $conn->prepare($sql_pending);
$stmt_pending->bind_param("i", $class_id);
$stmt_pending->execute();
$pending_result = $stmt_pending->get_result();
$pending_count = $pending_result->fetch_assoc()['count'] ?? 0;
$stmt_pending->close();

// Get recent grade updates (assignments + quizzes)
$sql_recent = "SELECT 
    'assignment' as type,
    g.id,
    g.student_id,
    g.score,
    g.max_score,
    g.percentage,
    g.grade_letter,
    g.notes,
    g.updated_at,
    u.first_name,
    u.last_name,
    a.title as assessment_title
FROM gradebook g
JOIN users u ON g.student_id = u.id
JOIN assignments a ON g.assignment_id = a.id
WHERE a.class_id = ?
UNION ALL
SELECT 
    'quiz' as type,
    qa.id,
    qa.student_id,
    qa.total_score as score,
    q.total_points as max_score,
    qa.percentage,
    '' as grade_letter,
    '' as notes,
    qa.updated_at,
    u.first_name,
    u.last_name,
    q.title as assessment_title
FROM quiz_attempts qa
JOIN users u ON qa.student_id = u.id
JOIN quizzes q ON qa.quiz_id = q.id
WHERE q.class_id = ? AND qa.status = 'graded'
ORDER BY updated_at DESC
LIMIT 10";

$stmt_recent = $conn->prepare($sql_recent);
$stmt_recent->bind_param("ii", $class_id, $class_id);
$stmt_recent->execute();
$recent_grades = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_recent->close();

// Log activity
logActivity('view_gradebook', "Viewed gradebook for class: {$class['batch_code']}", 'class_batches', $class_id);

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Gradebook</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        /* CSS Variables */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f94144;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border: #dee2e6;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
            --safe-bottom: env(safe-area-inset-bottom, 0);
            --safe-top: env(safe-area-inset-top, 0);
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: max(1rem, env(safe-area-inset-left)) max(1rem, env(safe-area-inset-right));
            padding-bottom: max(2rem, env(safe-area-inset-bottom));
        }

        /* Breadcrumb - Mobile Optimized */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: var(--gray);
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding: 0.25rem 0;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 2rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            background: white;
            border-color: var(--primary);
        }

        .breadcrumb .separator {
            opacity: 0.5;
            margin: 0 0.25rem;
        }

        .breadcrumb span {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
        }

        /* Header */
        .header {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            border-left: 6px solid var(--primary);
        }

        .header-top {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .header-top {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .class-info h1 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            word-break: break-word;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Navigation - Horizontal Scroll */
        .nav-container {
            display: flex;
            gap: 0.5rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.5rem 0;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .nav-container::-webkit-scrollbar {
            display: none;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid var(--border);
            border-radius: 2rem;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
            font-size: 0.9rem;
            min-height: 48px;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Stats Grid - Mobile First */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 1.25rem 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:active {
            transform: scale(0.97);
        }

        .stat-card.students {
            border-top-color: var(--success);
        }

        .stat-card.assignments {
            border-top-color: var(--warning);
        }

        .stat-card.quizzes {
            border-top-color: var(--info);
        }

        .stat-card.pending {
            border-top-color: var(--danger);
        }

        .stat-card.average {
            border-top-color: #8b5cf6;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Controls - Mobile First */
        .controls {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .controls {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .search-box {
            width: 100%;
        }

        @media (min-width: 768px) {
            .search-box {
                flex: 1;
                max-width: 400px;
            }
        }

        .search-box input {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            min-height: 48px;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            font-size: 0.9rem;
            min-height: 48px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--gray);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2a9d8f);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #f3722c);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #e63946);
            color: white;
        }

        /* Bulk Grade Form */
        .bulk-grade-form {
            background: var(--light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--warning);
            display: none;
        }

        .bulk-grade-form.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            min-height: 48px;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .form-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
        }

        .alert-success {
            background: #d4edda;
            border: 2px solid var(--success);
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 2px solid var(--danger);
            color: #721c24;
        }

        /* Gradebook Container */
        .gradebook-container {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-bottom: 1.5rem;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: var(--radius);
        }

        .gradebook-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            /* Ensures table is scrollable on small screens */
        }

        .gradebook-table th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .gradebook-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }

        .gradebook-table tbody tr:hover {
            background: var(--light);
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .student-info h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .student-info p {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .grade-cell {
            text-align: center;
        }

        .grade-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            min-width: 40px;
        }

        .grade-a {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .grade-b {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .grade-c {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .grade-d {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .grade-f {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
            border: 1px solid var(--danger);
        }

        .percentage {
            font-size: 0.7rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .empty-cell {
            color: #cbd5e1;
            font-style: italic;
            font-size: 0.8rem;
        }

        .assessment-badge {
            font-size: 0.6rem;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
            margin-left: 0.3rem;
            background: rgba(255, 255, 255, 0.2);
        }

        .assessment-assignment {
            background: rgba(16, 185, 129, 0.2);
        }

        .assessment-quiz {
            background: rgba(139, 92, 246, 0.2);
        }

        .final-grade {
            font-weight: 700;
            font-size: 1.2rem;
        }

        /* Tooltip */
        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: var(--dark);
            color: white;
            text-align: center;
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            position: absolute;
            z-index: 100;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
            font-weight: normal;
            pointer-events: none;
            box-shadow: var(--shadow-lg);
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        @media (min-width: 640px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .card-header h2 {
            font-size: 1.2rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--success), #2a9d8f);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .activity-description {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.7rem;
            color: var(--gray);
            display: flex;
            gap: 1rem;
        }

        /* Performance Summary */
        .performance-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 640px) {
            .performance-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .performance-item {
            padding: 1rem;
            background: var(--light);
            border-radius: var(--radius-sm);
        }

        .performance-item h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 1rem;
            color: var(--dark);
        }

        .performance-item p {
            color: var(--gray);
            line-height: 1.6;
        }

        .grade-distribution {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }

        .grade-distribution span {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0;
            border-bottom: 1px dashed var(--border);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1rem;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .nav-link {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .nav-link:active {
                transform: scale(0.97);
            }
        }

        /* Accessibility */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        :focus {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        :focus-visible {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i>
                <span class="visually-hidden">Dashboard</span>
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i>
                <span class="visually-hidden">My Classes</span>
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Gradebook</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Gradebook</h1>
                    <p><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-value"><?php echo $class_stats['total_students']; ?></div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-card assignments">
                <div class="stat-value"><?php echo count($assignments); ?></div>
                <div class="stat-label">Assignments</div>
            </div>
            <div class="stat-card quizzes">
                <div class="stat-value"><?php echo count($quizzes); ?></div>
                <div class="stat-label">Quizzes</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label">To Grade</div>
                <?php if ($pending_count > 0): ?>
                    <div class="stat-link">
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>&filter=pending">
                            Grade <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-card average">
                <div class="stat-value"><?php echo $class_stats['average_score']; ?>%</div>
                <div class="stat-label">Class Avg</div>
            </div>
        </div>

        <!-- Gradebook Controls -->
        <div class="controls">
            <form method="get" action="" class="search-box">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <input type="text" name="search" placeholder="Search students..."
                    value="<?php echo htmlspecialchars($search); ?>">
            </form>

            <div class="filter-group">
                <a href="?class_id=<?php echo $class_id; ?>&filter=all"
                    class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">
                    All
                </a>
                <a href="?class_id=<?php echo $class_id; ?>&filter=ungraded"
                    class="btn <?php echo $filter === 'ungraded' ? 'btn-primary' : 'btn-secondary'; ?>">
                    Ungraded
                </a>

                <button type="button" class="btn btn-warning" id="toggleBulkGrade">
                    <i class="fas fa-edit"></i> Bulk
                </button>

                <form method="post" style="display: inline;">
                    <button type="submit" name="export_grades" class="btn btn-success">
                        <i class="fas fa-file-export"></i> Export
                    </button>
                </form>
            </div>
        </div>

        <!-- Bulk Grade Form -->
        <div class="bulk-grade-form" id="bulkGradeForm">
            <form method="post">
                <div class="form-group">
                    <label>Default Grade (leave blank to skip):</label>
                    <input type="number" name="default_grade" min="0" max="100" step="0.1" placeholder="0-100">
                </div>
                <div class="form-group">
                    <label>Default Feedback:</label>
                    <textarea name="default_feedback" placeholder="Enter feedback..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelBulkGrade">Cancel</button>
                    <button type="submit" name="bulk_grade" class="btn btn-primary">
                        Apply to Ungraded
                    </button>
                </div>
            </form>
        </div>

        <!-- Gradebook Table -->
        <div class="gradebook-container">
            <div class="table-container">
                <table class="gradebook-table">
                    <thead>
                        <tr>
                            <th style="min-width: 200px;">Student</th>
                            <?php foreach ($all_assessments as $assessment): ?>
                                <th class="tooltip">
                                    <?php
                                    $short_title = strlen($assessment['title']) > 15 ?
                                        substr($assessment['title'], 0, 12) . '...' :
                                        $assessment['title'];
                                    echo htmlspecialchars($short_title);
                                    ?>
                                    <span class="tooltiptext">
                                        <?php echo htmlspecialchars($assessment['title']); ?><br>
                                        Type: <?php echo ucfirst($assessment['type']); ?><br>
                                        Max: <?php echo $assessment['total_points']; ?> pts
                                    </span>
                                </th>
                            <?php endforeach; ?>
                            <th style="min-width: 80px;">Total</th>
                            <th style="min-width: 80px;">Avg</th>
                            <th style="min-width: 80px;">Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($student_grades)): ?>
                            <tr>
                                <td colspan="<?php echo count($all_assessments) + 4; ?>" style="text-align: center; padding: 3rem;">
                                    <div class="empty-state">
                                        <i class="fas fa-user-graduate"></i>
                                        <p>No students enrolled yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($student_grades as $student_id => $data): ?>
                                <tr>
                                    <td>
                                        <div class="student-cell">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($data['student']['first_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-info">
                                                <h4><?php echo htmlspecialchars($data['student']['first_name'] . ' ' . $data['student']['last_name']); ?></h4>
                                                <p><?php echo htmlspecialchars($data['student']['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <?php foreach ($all_assessments as $assessment): ?>
                                        <td class="grade-cell">
                                            <?php
                                            $key = ($assessment['type'] === 'assignment' ? 'a' : 'q') . $assessment['id'];
                                            $grade = $data['grades'][$key] ?? null;
                                            if ($grade):
                                                $percentage = $grade['percentage'];
                                                $grade_class = '';
                                                if ($percentage >= 90) $grade_class = 'grade-a';
                                                elseif ($percentage >= 80) $grade_class = 'grade-b';
                                                elseif ($percentage >= 70) $grade_class = 'grade-c';
                                                elseif ($percentage >= 60) $grade_class = 'grade-d';
                                                else $grade_class = 'grade-f';
                                            ?>
                                                <div class="grade-badge <?php echo $grade_class; ?>">
                                                    <?php echo $grade['grade_letter']; ?>
                                                </div>
                                                <div class="percentage">
                                                    <?php echo round($grade['score'], 1); ?>/<?php echo round($grade['max'], 1); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="empty-cell">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>

                                    <td class="grade-cell">
                                        <strong><?php echo round($data['total_score'], 1); ?></strong>
                                    </td>

                                    <td class="grade-cell">
                                        <div class="grade-badge <?php
                                                                if ($data['average'] >= 90) echo 'grade-a';
                                                                elseif ($data['average'] >= 80) echo 'grade-b';
                                                                elseif ($data['average'] >= 70) echo 'grade-c';
                                                                elseif ($data['average'] >= 60) echo 'grade-d';
                                                                else echo 'grade-f';
                                                                ?>">
                                            <?php echo round($data['average'], 1); ?>%
                                        </div>
                                    </td>

                                    <td class="grade-cell final-grade">
                                        <?php echo $data['final_grade']; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Grade Updates -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Recent Updates</h2>
            </div>
            <div class="activity-list">
                <?php if (empty($recent_grades)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No recent updates</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_grades as $grade): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas <?php echo $grade['type'] === 'assignment' ? 'fa-tasks' : 'fa-question-circle'; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <?php echo htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name']); ?>
                                </div>
                                <div class="activity-description">
                                    <?php echo htmlspecialchars($grade['assessment_title']); ?>:
                                    <?php echo round($grade['score'], 1); ?>/<?php echo round($grade['max_score'], 1); ?>
                                </div>
                                <div class="activity-meta">
                                    <span><i class="fas fa-clock"></i> <?php echo time_ago($grade['updated_at']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Class Performance Summary -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-bar"></i> Class Performance</h2>
            </div>
            <div class="performance-grid">
                <div class="performance-item">
                    <h3><i class="fas fa-trophy" style="color: var(--warning);"></i> Top Performer</h3>
                    <p>
                        <?php echo $class_stats['top_performer'] ? htmlspecialchars($class_stats['top_performer']) : 'N/A'; ?>
                        <br><small>Avg: <?php echo $class_stats['average_score']; ?>%</small>
                    </p>
                </div>

                <?php if (!empty($class_stats['needs_attention'])): ?>
                    <div class="performance-item">
                        <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Needs Help</h3>
                        <p><?php echo implode(', ', array_map('htmlspecialchars', array_slice($class_stats['needs_attention'], 0, 3))); ?></p>
                    </div>
                <?php endif; ?>

                <div class="performance-item">
                    <h3><i class="fas fa-chart-pie" style="color: var(--info);"></i> Grade Distribution</h3>
                    <div class="grade-distribution">
                        <span>A <span><?php echo count(array_filter($student_grades, fn($s) => $s['average'] >= 90)); ?></span></span>
                        <span>B <span><?php echo count(array_filter($student_grades, fn($s) => $s['average'] >= 80 && $s['average'] < 90)); ?></span></span>
                        <span>C <span><?php echo count(array_filter($student_grades, fn($s) => $s['average'] >= 70 && $s['average'] < 80)); ?></span></span>
                        <span>D <span><?php echo count(array_filter($student_grades, fn($s) => $s['average'] >= 60 && $s['average'] < 70)); ?></span></span>
                        <span>F <span><?php echo count(array_filter($student_grades, fn($s) => $s['average'] < 60)); ?></span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle bulk grade form
        const toggleBtn = document.getElementById('toggleBulkGrade');
        const bulkForm = document.getElementById('bulkGradeForm');
        const cancelBtn = document.getElementById('cancelBulkGrade');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                bulkForm.classList.toggle('active');
                this.classList.toggle('btn-warning');
                this.classList.toggle('btn-secondary');
                this.innerHTML = bulkForm.classList.contains('active') ?
                    '<i class="fas fa-times"></i> Cancel' :
                    '<i class="fas fa-edit"></i> Bulk';
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                bulkForm.classList.remove('active');
                toggleBtn.classList.remove('btn-secondary');
                toggleBtn.classList.add('btn-warning');
                toggleBtn.innerHTML = '<i class="fas fa-edit"></i> Bulk';
            });
        }

        // Auto-submit search
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    this.form.submit();
                }
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                document.querySelector('button[name="export_grades"]')?.click();
            }
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                toggleBtn?.click();
            }
            if (e.key === 'Escape' && bulkForm?.classList.contains('active')) {
                cancelBtn?.click();
            }
        });

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .nav-link, .gradebook-table tr').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }
    </script>
</body>

</html>