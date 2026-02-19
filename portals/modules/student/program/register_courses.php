<?php
// modules/student/program/register_courses.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];
$user_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($user_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

$period_id = $_GET['period_id'] ?? 0;
$error = '';
$success = '';

// Get period details
$period = [];
$period_sql = "SELECT * FROM academic_periods WHERE id = ?";
$period_stmt = $conn->prepare($period_sql);
$period_stmt->bind_param("i", $period_id);
$period_stmt->execute();
$period_result = $period_stmt->get_result();
if ($period_result->num_rows > 0) {
    $period = $period_result->fetch_assoc();
}
$period_stmt->close();

if (empty($period) || $period['status'] !== 'upcoming') {
    header('Location: index.php');
    exit();
}

// Check if registration period is open
$current_date = date('Y-m-d');
$registration_open = false;
$registration_message = '';

// Check if registration has started
if (!empty($period['registration_start_date'])) {
    if ($period['registration_start_date'] > $current_date) {
        $registration_message = "Registration for this period has not yet started. Registration opens on " .
            date('M d, Y', strtotime($period['registration_start_date'])) . ".";
    } elseif (!empty($period['registration_deadline']) && $period['registration_deadline'] < $current_date) {
        $registration_message = "Registration for this period has closed. The deadline was " .
            date('M d, Y', strtotime($period['registration_deadline'])) . ".";
    } else {
        $registration_open = true;
    }
} else {
    // If no registration start date is set, allow registration
    $registration_open = true;
}

// Check if student has an approved application for a program
$program = [];
$application_sql = "SELECT a.*, p.* FROM applications a
                   JOIN programs p ON a.program_id = p.id
                   WHERE a.user_id = ? 
                   AND a.applying_as = 'student'
                   AND a.status = 'approved'
                   ORDER BY a.created_at DESC
                   LIMIT 1";
$application_stmt = $conn->prepare($application_sql);
$application_stmt->bind_param("i", $user_id);
$application_stmt->execute();
$application_result = $application_stmt->get_result();
if ($application_result->num_rows > 0) {
    $program = $application_result->fetch_assoc();
}
$application_stmt->close();

if (empty($program)) {
    header('Location: index.php');
    exit();
}

// Check if student has paid registration fee
$registration_fee_paid = false;
$registration_fee_amount = $program['registration_fee'] ?? 0;

if ($registration_fee_amount > 0) {
    $registration_fee_sql = "SELECT * FROM registration_fee_payments 
                            WHERE student_id = ? 
                            AND program_id = ? 
                            AND status = 'completed'";
    $registration_fee_stmt = $conn->prepare($registration_fee_sql);
    $registration_fee_stmt->bind_param("ii", $user_id, $program['id']);
    $registration_fee_stmt->execute();
    $registration_fee_result = $registration_fee_stmt->get_result();
    if ($registration_fee_result->num_rows > 0) {
        $registration_fee_paid = true;
    }
    $registration_fee_stmt->close();
} else {
    $registration_fee_paid = true; // No registration fee required
}

// Add check for registration fee payment
if (!$registration_fee_paid && $registration_fee_amount > 0) {
    $registration_open = false;
    $registration_message = "Registration fee payment required before course registration.";
    $error = "You need to pay the registration fee before registering for courses. 
              Please visit the Finance section to make payment.";
}

// Check if student is already registered for this period
$existing_registration_sql = "SELECT COUNT(*) as count FROM enrollments e
                             WHERE e.student_id = ?
                             AND (
                                 (e.program_type = 'onsite' AND e.term_id = ?) OR 
                                 (e.program_type = 'online' AND e.block_id = ?)
                             )
                             AND e.status = 'active'";
$existing_stmt = $conn->prepare($existing_registration_sql);
$existing_stmt->bind_param("iii", $user_id, $period_id, $period_id);
$existing_stmt->execute();
$existing_result = $existing_stmt->get_result();
$existing_row = $existing_result->fetch_assoc();
$already_registered = $existing_row['count'] > 0;
$existing_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_courses'])) {
    // Validate registration period
    if (!$registration_open) {
        $error = $registration_message;
    } elseif ($already_registered) {
        $error = "You are already registered for courses in this period.";
    } elseif (!$registration_fee_paid && $registration_fee_amount > 0) {
        $error = "You need to pay the registration fee before registering for courses.";
    } else {
        $selected_courses = $_POST['courses'] ?? [];
        $program_type = $period['program_type'];

        if (count($selected_courses) == 0) {
            $error = "Please select at least one course to register.";
        } else {
            // Validate course selection based on program requirements
            $core_courses_selected = 0;
            $elective_courses_selected = 0;

            // Get program meta for validation
            $program_meta_sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
            $program_meta_stmt = $conn->prepare($program_meta_sql);
            $program_meta_stmt->bind_param("i", $program['id']);
            $program_meta_stmt->execute();
            $program_meta_result = $program_meta_stmt->get_result();
            $program_meta = $program_meta_result->num_rows > 0 ? $program_meta_result->fetch_assoc() : [];
            $program_meta_stmt->close();

            // Start transaction
            $conn->begin_transaction();

            try {
                foreach ($selected_courses as $course_id) {
                    // Get course details for validation
                    $course_sql = "SELECT c.*, pr.course_type FROM courses c
                                  JOIN program_requirements pr ON c.id = pr.course_id
                                  WHERE c.id = ? AND pr.program_id = ?";
                    $course_stmt = $conn->prepare($course_sql);
                    $course_stmt->bind_param("ii", $course_id, $program['id']);
                    $course_stmt->execute();
                    $course_result = $course_stmt->get_result();

                    if ($course_result->num_rows == 0) {
                        throw new Exception("Invalid course selection.");
                    }

                    $course = $course_result->fetch_assoc();
                    $course_stmt->close();

                    // Count course types
                    if ($course['course_type'] == 'core') {
                        $core_courses_selected++;
                    } else {
                        $elective_courses_selected++;
                    }

                    // Check if already enrolled in this course in any period
                    $already_enrolled_sql = "SELECT COUNT(*) as count FROM enrollments e
                                            JOIN class_batches cb ON e.class_id = cb.id
                                            WHERE e.student_id = ? AND cb.course_id = ?
                                            AND e.status IN ('active', 'completed')";
                    $already_enrolled_stmt = $conn->prepare($already_enrolled_sql);
                    $already_enrolled_stmt->bind_param("ii", $user_id, $course_id);
                    $already_enrolled_stmt->execute();
                    $already_enrolled_result = $already_enrolled_stmt->get_result();
                    $already_enrolled_row = $already_enrolled_result->fetch_assoc();
                    $already_enrolled_stmt->close();

                    if ($already_enrolled_row['count'] > 0) {
                        throw new Exception("You are already enrolled in or have completed course: " . $course['title']);
                    }

                    // Check prerequisites
                    if (!empty($course['prerequisite_course_id'])) {
                        $prereq_sql = "SELECT COUNT(*) as count FROM enrollments e
                                       JOIN class_batches cb ON e.class_id = cb.id
                                       WHERE e.student_id = ? AND cb.course_id = ?
                                       AND e.status = 'completed'";
                        $prereq_stmt = $conn->prepare($prereq_sql);
                        $prereq_stmt->bind_param("ii", $user_id, $course['prerequisite_course_id']);
                        $prereq_stmt->execute();
                        $prereq_result = $prereq_stmt->get_result()->fetch_assoc();
                        $prereq_stmt->close();

                        if ($prereq_result['count'] == 0) {
                            throw new Exception("Prerequisite not met for course: " . $course['title']);
                        }
                    }

                    // Find or create class batch for this period
                    $class_id = findOrCreateClassBatch($conn, $course_id, $period, $program_type);

                    // Create enrollment
                    $enroll_sql = "INSERT INTO enrollments 
                                   (student_id, class_id, enrollment_date, status, program_type,
                                    " . ($program_type == 'onsite' ? "term_id" : "block_id") . ")
                                   VALUES (?, ?, CURDATE(), 'active', ?, ?)";
                    $enroll_stmt = $conn->prepare($enroll_sql);
                    $enroll_stmt->bind_param("iisi", $user_id, $class_id, $program_type, $period_id);

                    if (!$enroll_stmt->execute()) {
                        throw new Exception("Failed to enroll in course: " . $course['title']);
                    }
                    $enroll_stmt->close();
                }

                // Validate elective requirements (only for max limit, not minimum for this block)
                if (!empty($program_meta)) {
                    $max_electives = $program_meta['max_electives'] ?? 0;

                    if ($max_electives > 0 && $elective_courses_selected > $max_electives) {
                        throw new Exception("You can select at most $max_electives elective course(s) in one period.");
                    }
                }

                // Commit transaction
                $conn->commit();

                // Create initial financial status for the enrolled classes
                createFinancialStatusForEnrollments($user_id, $period_id, $program_type, $selected_courses);

                $success = "Course registration successful! You have registered for " . count($selected_courses) . " course(s).";
                logActivity(
                    $user_id,
                    'course_registration',
                    'Student registered for courses in period: ' . $period['period_name'] . ' - ' . $period['academic_year'],
                    $_SERVER['REMOTE_ADDR']
                );
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

// Get available courses for this program and period
$available_courses_sql = "SELECT c.*, pr.course_type, pr.is_required, pr.min_grade,
                                 pc.course_code as prereq_code, pc.title as prereq_title,
                                 (SELECT COUNT(*) FROM enrollments e2 
                                  JOIN class_batches cb2 ON e2.class_id = cb2.id 
                                  WHERE e2.student_id = ? AND cb2.course_id = c.id 
                                  AND e2.status IN ('active', 'completed')) as is_enrolled_or_completed
                          FROM courses c
                          JOIN program_requirements pr ON c.id = pr.course_id
                          LEFT JOIN courses pc ON c.prerequisite_course_id = pc.id
                          WHERE pr.program_id = ?
                          ORDER BY pr.course_type DESC, c.order_number, c.course_code";

$courses_stmt = $conn->prepare($available_courses_sql);
$courses_stmt->bind_param("ii", $user_id, $program['id']);
$courses_stmt->execute();
$courses_result = $courses_stmt->get_result();
$all_courses = $courses_result->fetch_all(MYSQLI_ASSOC);
$courses_stmt->close();

// Filter courses based on prerequisites and enrollment status
$registerable_courses = [];
$core_courses = [];
$elective_courses = [];

foreach ($all_courses as $course) {
    // Skip if already enrolled or completed
    if ($course['is_enrolled_or_completed'] > 0) {
        continue;
    }

    // Check prerequisite
    $prereq_met = true;
    if (!empty($course['prerequisite_course_id'])) {
        $prereq_sql = "SELECT COUNT(*) as count FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       WHERE e.student_id = ? AND cb.course_id = ?
                       AND e.status = 'completed'";
        $prereq_stmt = $conn->prepare($prereq_sql);
        $prereq_stmt->bind_param("ii", $user_id, $course['prerequisite_course_id']);
        $prereq_stmt->execute();
        $prereq_result = $prereq_stmt->get_result()->fetch_assoc();
        $prereq_stmt->close();

        if ($prereq_result['count'] == 0) {
            $prereq_met = false;
        }
    }

    $course['prereq_met'] = $prereq_met;

    if ($course['course_type'] == 'core') {
        $core_courses[] = $course;
    } else {
        $elective_courses[] = $course;
    }

    if ($prereq_met) {
        $registerable_courses[] = $course;
    }
}

// Get completed courses count for elective validation
$completed_electives_sql = "SELECT COUNT(*) as count FROM enrollments e
                           JOIN class_batches cb ON e.class_id = cb.id
                           JOIN courses c ON cb.course_id = c.id
                           JOIN program_requirements pr ON c.id = pr.course_id
                           WHERE e.student_id = ? 
                           AND pr.program_id = ?
                           AND pr.course_type = 'elective'
                           AND e.status = 'completed'";
$completed_electives_stmt = $conn->prepare($completed_electives_sql);
$completed_electives_stmt->bind_param("ii", $user_id, $program['id']);
$completed_electives_stmt->execute();
$completed_electives_result = $completed_electives_stmt->get_result();
$completed_electives_row = $completed_electives_result->fetch_assoc();
$completed_electives_count = $completed_electives_row['count'] ?? 0;
$completed_electives_stmt->close();

// Get program meta for elective requirements
$program_meta_sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
$program_meta_stmt = $conn->prepare($program_meta_sql);
$program_meta_stmt->bind_param("i", $program['id']);
$program_meta_stmt->execute();
$program_meta_result = $program_meta_stmt->get_result();
$program_meta = $program_meta_result->num_rows > 0 ? $program_meta_result->fetch_assoc() : [];
$program_meta_stmt->close();

$min_electives_required = $program_meta['min_electives'] ?? 0;
$max_electives_allowed = $program_meta['max_electives'] ?? 999;
$remaining_electives_needed = max(0, $min_electives_required - $completed_electives_count);

// Helper functions
function generateBatchCode($program_type, $period_number, $course_code)
{
    $prefix = $program_type == 'onsite' ? 'ONS' : 'ONL';
    $period_code = str_pad($period_number, 2, '0', STR_PAD_LEFT);
    $random = substr(strtoupper(uniqid()), -3);
    return $prefix . '-' . $period_code . '-' . $course_code . '-' . $random;
}

function findOrCreateClassBatch($conn, $course_id, $period, $program_type)
{
    // First, try to find an existing class batch for this course in this period
    $batch_sql = "SELECT id FROM class_batches 
                 WHERE course_id = ? 
                 AND program_type = ?
                 AND " . ($program_type == 'onsite' ? "term_number" : "block_number") . " = ?
                 AND academic_year = ?
                 AND status = 'scheduled'";
    $batch_stmt = $conn->prepare($batch_sql);
    $batch_stmt->bind_param("isis", $course_id, $program_type, $period['period_number'], $period['academic_year']);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();

    if ($batch_result->num_rows > 0) {
        // Use existing class batch
        $batch = $batch_result->fetch_assoc();
        $class_id = $batch['id'];
        $batch_stmt->close();
        return $class_id;
    }
    $batch_stmt->close();

    // No existing class found, create a new one
    // Get course details
    $course_sql = "SELECT * FROM courses WHERE id = ?";
    $course_stmt = $conn->prepare($course_sql);
    $course_stmt->bind_param("i", $course_id);
    $course_stmt->execute();
    $course_result = $course_stmt->get_result();
    $course = $course_result->fetch_assoc();
    $course_stmt->close();

    // Add payment deadline to the period if not set
    if (empty($period['payment_deadline'])) {
        $start_date = new DateTime($period['start_date']);
        $payment_deadline = $start_date->modify('+2 weeks')->format('Y-m-d');

        // Update academic_periods table
        $update_sql = "UPDATE academic_periods SET payment_deadline = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $payment_deadline, $period['id']);
        $update_stmt->execute();
        $update_stmt->close();
    }

    // Generate batch code and name
    $batch_code = generateBatchCode($program_type, $period['period_number'], $course['course_code']);
    $batch_name = $course['title'] . " - " . $period['period_name'] . " " . $period['academic_year'];

    // Get default instructor for this program type
    $instructor_sql = "SELECT id FROM users 
                      WHERE role = 'instructor' 
                      AND status = 'active'
                      ORDER BY RAND() LIMIT 1";
    $instructor_result = $conn->query($instructor_sql);
    $instructor_id = $instructor_result->num_rows > 0 ? $instructor_result->fetch_assoc()['id'] : 1;

    // Create new class batch
    $create_batch_sql = "INSERT INTO class_batches 
                        (course_id, batch_code, name, instructor_id, start_date, end_date,
                         program_type, " . ($program_type == 'onsite' ? "term_number" : "block_number") . ",
                         academic_year, " . ($program_type == 'onsite' ? "term_name" : "block_name") . ",
                         status, location_type, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', 'virtual', NOW())";

    $create_stmt = $conn->prepare($create_batch_sql);
    $create_stmt->bind_param(
        "isssssssss",
        $course_id,
        $batch_code,
        $batch_name,
        $instructor_id,
        $period['start_date'],
        $period['end_date'],
        $program_type,
        $period['period_number'],
        $period['academic_year'],
        $period['period_name']
    );

    if (!$create_stmt->execute()) {
        throw new Exception("Failed to create class batch for course.");
    }

    $class_id = $conn->insert_id;
    $create_stmt->close();

    return $class_id;
}

function createFinancialStatusForEnrollments($student_id, $period_id, $program_type, $selected_courses)
{
    global $conn;

    // Get payment deadline
    $deadline_sql = "SELECT payment_deadline FROM academic_periods WHERE id = ?";
    $deadline_stmt = $conn->prepare($deadline_sql);
    $deadline_stmt->bind_param("i", $period_id);
    $deadline_stmt->execute();
    $deadline_result = $deadline_stmt->get_result();
    $deadline_row = $deadline_result->fetch_assoc();
    $payment_deadline = $deadline_row['payment_deadline'] ?? null;
    $deadline_stmt->close();

    // If no payment deadline set, calculate it (2 weeks from start)
    if (!$payment_deadline) {
        $period_sql = "SELECT start_date FROM academic_periods WHERE id = ?";
        $period_stmt = $conn->prepare($period_sql);
        $period_stmt->bind_param("i", $period_id);
        $period_stmt->execute();
        $period_result = $period_stmt->get_result();
        $period_row = $period_result->fetch_assoc();

        if ($period_row) {
            $start_date = new DateTime($period_row['start_date']);
            $payment_deadline = $start_date->modify('+2 weeks')->format('Y-m-d');
        }
        $period_stmt->close();
    }

    // Get the program's fee structure
    $program_sql = "SELECT p.* FROM enrollments e
                   JOIN class_batches cb ON e.class_id = cb.id
                   JOIN courses c ON cb.course_id = c.id
                   JOIN programs p ON c.program_id = p.id
                   WHERE e.student_id = ?
                   LIMIT 1";
    $program_stmt = $conn->prepare($program_sql);
    $program_stmt->bind_param("i", $student_id);
    $program_stmt->execute();
    $program_result = $program_stmt->get_result();
    $program_data = $program_result->fetch_assoc();
    $program_stmt->close();

    if (!$program_data) return;

    // Get fee structure for the program
    $fee_sql = "SELECT * FROM fee_structures WHERE program_id = ? AND is_active = 1 LIMIT 1";
    $fee_stmt = $conn->prepare($fee_sql);
    $fee_stmt->bind_param("i", $program_data['id']);
    $fee_stmt->execute();
    $fee_result = $fee_stmt->get_result();
    $fee_structure = $fee_result->fetch_assoc();
    $fee_stmt->close();

    // Calculate total fee per course (assuming equal distribution)
    $total_courses = count($selected_courses);
    $course_fee = $fee_structure ? ($fee_structure['total_amount'] / $total_courses) : 0;

    // Create financial status for each enrollment
    $enrollments_sql = "SELECT e.id as enrollment_id, e.class_id FROM enrollments e
                       WHERE e.student_id = ?
                       AND (
                           (e.program_type = 'onsite' AND e.term_id = ?) OR 
                           (e.program_type = 'online' AND e.block_id = ?)
                       )
                       AND e.status = 'active'";
    $enrollments_stmt = $conn->prepare($enrollments_sql);
    $enrollments_stmt->bind_param("iii", $student_id, $period_id, $period_id);
    $enrollments_stmt->execute();
    $enrollments_result = $enrollments_stmt->get_result();

    while ($enrollment = $enrollments_result->fetch_assoc()) {
        // Check if financial status already exists
        $check_sql = "SELECT id FROM student_financial_status 
                     WHERE student_id = ? AND class_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $student_id, $enrollment['class_id']);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows == 0) {
            // Create financial status record
            $insert_sql = "INSERT INTO student_financial_status 
                          (student_id, class_id, total_fee, paid_amount, balance, 
                           current_block, next_payment_due, payment_deadline, created_at)
                          VALUES (?, ?, ?, 0, ?, 1, DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, NOW())";

            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iidds", $student_id, $enrollment['class_id'], $course_fee, $course_fee, $payment_deadline);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
    $enrollments_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Registration - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        /* All CSS styles remain exactly the same as before */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --sidebar-bg: #1e293b;
            --sidebar-text: #cbd5e1;
            --sidebar-hover: #334155;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar Styles - Unchanged */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
        }

        .logo-text {
            font-weight: 600;
            font-size: 1.25rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--sidebar-text);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .toggle-sidebar:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
        }

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-details h3 {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-details p {
            font-size: 0.875rem;
            color: var(--sidebar-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar.collapsed .user-details {
            display: none;
        }

        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 2px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            gap: 0.75rem;
        }

        .nav-item:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .nav-item.active {
            background-color: rgba(67, 97, 238, 0.2);
            color: white;
            border-left: 3px solid var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .nav-label {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar.collapsed .nav-label {
            display: none;
        }

        .badge {
            background-color: var(--primary);
            color: white;
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 10px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .nav-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 1rem 0;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Main Content Styles */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: var(--transition);
        }

        .sidebar.collapsed~.main-content {
            margin-left: 70px;
        }

        .top-bar {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Registration Container */
        .registration-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Period Info Card */
        .period-info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
        }

        .period-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .period-header h2 {
            color: var(--dark);
            font-size: 1.5rem;
        }

        .period-badge {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .period-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .period-detail-item {
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .period-detail-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .period-detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .registration-status {
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .registration-open {
            background: rgba(76, 201, 240, 0.1);
            border-left: 3px solid var(--success);
        }

        .registration-closed {
            background: rgba(230, 57, 70, 0.1);
            border-left: 3px solid var(--danger);
        }

        .registration-upcoming {
            background: rgba(247, 37, 133, 0.1);
            border-left: 3px solid var(--warning);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-danger {
            background-color: rgba(230, 57, 70, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--dark);
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert-warning {
            background-color: rgba(247, 37, 133, 0.1);
            border-left: 4px solid var(--warning);
            color: var(--dark);
        }

        .alert-info {
            background-color: rgba(72, 149, 239, 0.1);
            border-left: 4px solid var(--info);
            color: var(--dark);
        }

        /* Course Selection Form */
        .course-selection-form {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .form-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .form-header h3 {
            color: var(--dark);
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Course List */
        .course-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .course-item {
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
        }

        .course-item:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }

        .course-item.selected {
            border-color: var(--success);
            background: #f0f9ff;
        }

        .course-item.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            border-color: var(--gray-light);
        }

        .course-item.disabled:hover {
            border-color: var(--gray-light);
            background: #f8f9fa;
        }

        .checkbox-container {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .course-checkbox {
            width: 20px;
            height: 20px;
            margin-top: 0.25rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .course-checkbox:disabled {
            cursor: not-allowed;
        }

        .course-details {
            flex: 1;
        }

        .course-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .course-title h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .course-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-core {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .badge-elective {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .course-meta {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .course-meta span {
            margin-right: 1rem;
        }

        .prereq-info {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .prereq-warning {
            color: var(--warning);
            font-weight: 500;
        }

        /* Registration Summary */
        .registration-summary {
            background: #e7f5ff;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
            border: 1px solid var(--info);
        }

        .summary-header {
            margin-bottom: 1rem;
        }

        .summary-header h4 {
            color: var(--dark);
            font-size: 1.125rem;
            margin: 0;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .summary-stat {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Terms and Conditions */
        .terms-container {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
            border: 1px solid var(--border);
        }

        .terms-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .terms-checkbox input {
            margin-top: 0.25rem;
        }

        .terms-label {
            flex: 1;
        }

        .terms-label h5 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .terms-list {
            margin: 0.5rem 0 0 1rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .terms-list li {
            margin-bottom: 0.25rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        /* Button Styles */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Dashboard Footer */
        .dashboard-footer {
            background-color: white;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 2rem;
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            display: block;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
            }

            .sidebar.collapsed {
                width: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar.collapsed~.main-content {
                margin-left: 0;
            }

            .top-actions {
                display: none;
            }

            .registration-container {
                padding: 1rem;
            }

            .period-details-grid {
                grid-template-columns: 1fr;
            }

            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">IDA</div>
                <div class="logo-text">Student Portal</div>
            </div>
            <button class="toggle-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php
                $initials = strtoupper(substr($user_details['first_name'] ?? '', 0, 1) . substr($user_details['last_name'] ?? '', 0, 1));
                echo $initials ?: 'S';
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></h3>
                <p><i class="fas fa-user-graduate"></i> Student</p>
                <?php if (!empty($user_details['current_job_title'])): ?>
                    <p><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($user_details['current_job_title']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-label">Dashboard</span>
            </a>

            <!-- Program Dropdown -->
            <div class="nav-dropdown active">
                <div class="nav-item dropdown-toggle active" onclick="toggleDropdown(this)">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="nav-label">My Program</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content" style="display: block;">
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="nav-item">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-label">Program Progress</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/register_courses.php?period_id=<?php echo $period_id ?? ''; ?>" class="nav-item active">
                        <i class="fas fa-calendar-plus"></i>
                        <span class="nav-label">Course Registration</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/courses.php" class="nav-item">
                        <i class="fas fa-book"></i>
                        <span class="nav-label">All Courses</span>
                    </a>
                </div>
            </div>

            <!-- Classes Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-chalkboard"></i>
                    <span class="nav-label">My Classes</span>
                    <?php
                    // Get enrolled classes count
                    $class_count_sql = "SELECT COUNT(*) as count FROM enrollments WHERE student_id = ? AND status = 'active'";
                    $class_stmt = $conn->prepare($class_count_sql);
                    $class_stmt->bind_param("i", $user_id);
                    $class_stmt->execute();
                    $class_result = $class_stmt->get_result();
                    $class_row = $class_result->fetch_assoc();
                    $class_count = $class_row['count'] ?? 0;
                    $class_stmt->close();
                    if ($class_count > 0): ?>
                        <span class="badge"><?php echo $class_count; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="nav-item">
                        <i class="fas fa-list"></i>
                        <span class="nav-label">All Classes</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/calendar.php" class="nav-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-label">Class Schedule</span>
                    </a>
                </div>
            </div>

            <div class="nav-divider"></div>

            <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" class="nav-item">
                <i class="fas fa-user"></i>
                <span class="nav-label">My Profile</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/shared/notifications/" class="nav-item">
                <i class="fas fa-bell"></i>
                <span class="nav-label">Notifications</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i>
                <span class="nav-label">Logout</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div style="padding: 0.5rem; font-size: 0.75rem; color: var(--sidebar-text); text-align: center;">
                <div>Impact Digital Academy</div>
                <div style="font-size: 0.625rem; opacity: 0.7;">Student Portal v1.0</div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>Course Registration</h1>
                <p>Register for courses in <?php echo htmlspecialchars($period['period_name'] ?? 'upcoming period'); ?></p>
            </div>

            <div class="top-actions">
                <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Program
                </a>
            </div>
        </div>

        <div class="registration-container">
            <?php if ($already_registered): ?>
                <!-- Already Registered Alert -->
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <h3>Already Registered</h3>
                        <p>You are already registered for courses in <?php echo htmlspecialchars($period['period_name']); ?>.</p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary" style="margin-left: auto;">
                        Back to Program
                    </a>
                </div>
            <?php elseif (!$registration_open): ?>
                <!-- Registration Not Available -->
                <div class="alert alert-warning">
                    <i class="fas fa-calendar-times"></i>
                    <div>
                        <h3>Registration Not Available</h3>
                        <p><?php echo htmlspecialchars($registration_message); ?></p>
                        <p>Please check back during the registration period.</p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary" style="margin-left: auto;">
                        Back to Program
                    </a>
                </div>
            <?php else: ?>
                <!-- Error/Success Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <h3>Registration Successful!</h3>
                            <p><?php echo htmlspecialchars($success); ?></p>
                            <p><strong>Class Creation:</strong> Classes have been automatically created or assigned for your selected courses. You can view them in "My Classes".</p>
                            <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-success" style="margin-top: 1rem;">
                                Back to Program
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Period Information -->
                    <div class="period-info-card">
                        <div class="period-header">
                            <h2><?php echo htmlspecialchars($period['period_name']); ?></h2>
                            <span class="period-badge"><?php echo $period['program_type'] == 'onsite' ? 'Term' : 'Block'; ?> <?php echo $period['period_number']; ?></span>
                        </div>

                        <div class="period-details-grid">
                            <div class="period-detail-item">
                                <div class="period-detail-label">Program</div>
                                <div class="period-detail-value"><?php echo htmlspecialchars($program['name']); ?></div>
                            </div>

                            <div class="period-detail-item">
                                <div class="period-detail-label">Academic Year</div>
                                <div class="period-detail-value"><?php echo htmlspecialchars($period['academic_year']); ?></div>
                            </div>

                            <div class="period-detail-item">
                                <div class="period-detail-label">Duration</div>
                                <div class="period-detail-value"><?php echo $period['duration_weeks']; ?> weeks</div>
                            </div>

                            <div class="period-detail-item">
                                <div class="period-detail-label">Start Date</div>
                                <div class="period-detail-value"><?php echo date('M d, Y', strtotime($period['start_date'])); ?></div>
                            </div>

                            <div class="period-detail-item">
                                <div class="period-detail-label">Registration Status</div>
                                <div class="period-detail-value">
                                    <?php if ($registration_open): ?>
                                        <span style="color: var(--success); font-weight: bold;">Open</span>
                                    <?php else: ?>
                                        <span style="color: var(--danger); font-weight: bold;">Closed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Registration Status Banner -->
                        <div class="registration-status <?php echo $registration_open ? 'registration-open' : (empty($period['registration_start_date']) || $period['registration_start_date'] > $current_date ? 'registration-upcoming' : 'registration-closed'); ?>">
                            <i class="fas <?php echo $registration_open ? 'fa-calendar-check' : (empty($period['registration_start_date']) || $period['registration_start_date'] > $current_date ? 'fa-clock' : 'fa-calendar-times'); ?>"></i>
                            <div>
                                <?php if ($registration_open): ?>
                                    <strong>Registration is Open</strong>
                                    <?php if (!empty($period['registration_deadline'])): ?>
                                        <p>Registration closes on <?php echo date('M d, Y', strtotime($period['registration_deadline'])); ?></p>
                                    <?php endif; ?>
                                <?php elseif (empty($period['registration_start_date']) || $period['registration_start_date'] > $current_date): ?>
                                    <strong>Registration Not Yet Started</strong>
                                    <?php if (!empty($period['registration_start_date'])): ?>
                                        <p>Registration opens on <?php echo date('M d, Y', strtotime($period['registration_start_date'])); ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong>Registration Closed</strong>
                                    <?php if (!empty($period['registration_deadline'])): ?>
                                        <p>Registration closed on <?php echo date('M d, Y', strtotime($period['registration_deadline'])); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($registration_open && !empty($period['registration_deadline'])): ?>
                            <div class="deadline-warning" style="background: rgba(247, 37, 133, 0.1); border-left: 3px solid var(--warning); padding: 1rem; border-radius: 6px; margin-top: 1rem; display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-calendar-times"></i>
                                <div>
                                    <strong>Registration Deadline:</strong>
                                    <?php echo date('M d, Y', strtotime($period['registration_deadline'])); ?>
                                    <?php
                                    $days_left = floor((strtotime($period['registration_deadline']) - time()) / (60 * 60 * 24));
                                    if ($days_left >= 0): ?>
                                        <span style="margin-left: 10px; font-weight: bold; color: <?php echo $days_left <= 3 ? 'var(--danger)' : 'var(--warning)'; ?>">
                                            (<?php echo $days_left; ?> days left)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Registration Requirements -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <h3>Registration Requirements</h3>
                            <p>
                                <strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?> |
                                <strong>Program Type:</strong> <?php echo ucfirst($program['program_type']); ?>
                            </p>
                            <p>
                                <strong>Course Requirements:</strong>
                                <?php if ($min_electives_required > 0): ?>
                                    Program requires minimum <?php echo $min_electives_required; ?> elective(s) to graduate
                                    (<?php echo $completed_electives_count; ?> completed so far).
                                    <strong>Note:</strong> You can register electives in any block - not required in this block.
                                <?php else: ?>
                                    No minimum elective requirement
                                <?php endif; ?>
                            </p>
                            <p style="margin-top: 0.5rem;">
                                <strong>Class Creation:</strong> When you register for a course, a class will be automatically created if one doesn't exist for this block. If a class already exists, you'll be enrolled in the existing class.
                            </p>
                        </div>
                    </div>

                    <!-- Course Selection Form -->
                    <form method="POST" action="" class="course-selection-form">
                        <div class="form-header">
                            <h3>Select Courses</h3>
                            <p>Choose the courses you want to take during this <?php echo $period['program_type'] == 'onsite' ? 'term' : 'block'; ?>. Core courses are required, electives are optional.</p>
                        </div>

                        <?php if (empty($registerable_courses)): ?>
                            <!-- No Courses Available -->
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <h3>No Courses Available</h3>
                                <p>You have either completed all available courses or need to complete prerequisites first.</p>
                                <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary" style="margin-top: 1rem;">
                                    Back to Program
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Core Courses Section -->
                            <?php if (!empty($core_courses)): ?>
                                <div style="margin-bottom: 2rem;">
                                    <h3 style="color: var(--primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-star"></i> Core Courses
                                        <span style="font-size: 0.875rem; color: var(--gray); margin-left: auto;">
                                            Required: <?php echo count(array_filter($core_courses, function ($c) {
                                                            return $c['prereq_met'];
                                                        })); ?> available
                                        </span>
                                    </h3>

                                    <div class="course-list" id="core-courses-list">
                                        <?php foreach ($core_courses as $course): ?>
                                            <?php
                                            $is_disabled = !$course['prereq_met'];
                                            ?>
                                            <div class="course-item <?php echo $is_disabled ? 'disabled' : ''; ?>" id="course-<?php echo $course['id']; ?>" data-course-id="<?php echo $course['id']; ?>">
                                                <div class="checkbox-container">
                                                    <input type="checkbox" name="courses[]" value="<?php echo $course['id']; ?>" id="course_checkbox_<?php echo $course['id']; ?>" class="course-checkbox" onchange="updateSelection()" <?php echo $is_disabled ? 'disabled' : ''; ?>>
                                                    <div class="course-details">
                                                        <div class="course-title">
                                                            <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                                            <span class="course-badge badge-core">
                                                                Core <?php echo $course['is_required'] ? '(Required)' : ''; ?>
                                                            </span>
                                                        </div>
                                                        <div class="course-meta">
                                                            <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($course['course_code']); ?></span>
                                                            <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                                            <span><i class="fas fa-star"></i> Min Grade: <?php echo $course['min_grade']; ?></span>
                                                        </div>
                                                        <?php if (!empty($course['prerequisite_course_id'])): ?>
                                                            <div class="prereq-info <?php echo !$course['prereq_met'] ? 'prereq-warning' : ''; ?>">
                                                                <i class="fas fa-link"></i>
                                                                Prerequisite: <?php echo htmlspecialchars($course['prereq_code'] ?? 'Unknown'); ?> - <?php echo htmlspecialchars($course['prereq_title'] ?? 'Unknown'); ?>
                                                                <?php if (!$course['prereq_met']): ?>
                                                                    <span style="color: var(--warning); margin-left: 10px;">
                                                                        <i class="fas fa-exclamation-triangle"></i> Not completed
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Elective Courses Section -->
                            <?php if (!empty($elective_courses)): ?>
                                <div style="margin-bottom: 2rem;">
                                    <h3 style="color: var(--warning); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-list"></i> Elective Courses
                                        <span style="font-size: 0.875rem; color: var(--gray); margin-left: auto;">
                                            <?php if ($min_electives_required > 0): ?>
                                                Minimum <?php echo $min_electives_required; ?> required for graduation
                                                (<?php echo $completed_electives_count; ?> completed)
                                            <?php endif; ?>
                                        </span>
                                    </h3>

                                    <div class="course-list" id="elective-courses-list">
                                        <?php foreach ($elective_courses as $course): ?>
                                            <?php
                                            $is_disabled = !$course['prereq_met'];
                                            ?>
                                            <div class="course-item <?php echo $is_disabled ? 'disabled' : ''; ?>" id="course-<?php echo $course['id']; ?>" data-course-id="<?php echo $course['id']; ?>">
                                                <div class="checkbox-container">
                                                    <input type="checkbox" name="courses[]" value="<?php echo $course['id']; ?>" id="course_checkbox_<?php echo $course['id']; ?>" class="course-checkbox" onchange="updateSelection()" <?php echo $is_disabled ? 'disabled' : ''; ?>>
                                                    <div class="course-details">
                                                        <div class="course-title">
                                                            <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                                            <span class="course-badge badge-elective">
                                                                Elective
                                                            </span>
                                                        </div>
                                                        <div class="course-meta">
                                                            <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($course['course_code']); ?></span>
                                                            <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                                            <span><i class="fas fa-star"></i> Min Grade: <?php echo $course['min_grade']; ?></span>
                                                        </div>
                                                        <?php if (!empty($course['prerequisite_course_id'])): ?>
                                                            <div class="prereq-info <?php echo !$course['prereq_met'] ? 'prereq-warning' : ''; ?>">
                                                                <i class="fas fa-link"></i>
                                                                Prerequisite: <?php echo htmlspecialchars($course['prereq_code'] ?? 'Unknown'); ?> - <?php echo htmlspecialchars($course['prereq_title'] ?? 'Unknown'); ?>
                                                                <?php if (!$course['prereq_met']): ?>
                                                                    <span style="color: var(--warning); margin-left: 10px;">
                                                                        <i class="fas fa-exclamation-triangle"></i> Not completed
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Registration Summary -->
                            <div class="registration-summary">
                                <div class="summary-header">
                                    <h4>Registration Summary</h4>
                                </div>

                                <div class="summary-stats">
                                    <div class="summary-stat">
                                        <div class="stat-value" id="selected-count">0</div>
                                        <div class="stat-label">Total Courses</div>
                                    </div>

                                    <div class="summary-stat">
                                        <div class="stat-value" id="core-count">0</div>
                                        <div class="stat-label">Core Courses</div>
                                    </div>

                                    <div class="summary-stat">
                                        <div class="stat-value" id="elective-count">0</div>
                                        <div class="stat-label">Elective Courses</div>
                                    </div>

                                    <div class="summary-stat">
                                        <div class="stat-value" id="total-hours">0</div>
                                        <div class="stat-label">Total Hours</div>
                                    </div>
                                </div>

                                <?php if ($min_electives_required > 0): ?>
                                    <div id="elective-info" style="text-align: center; margin-top: 1rem; padding: 1rem; background: rgba(247, 37, 133, 0.1); border-radius: 6px;">
                                        <p style="color: var(--warning); font-weight: 600; margin: 0;">
                                            <i class="fas fa-info-circle"></i>
                                            Minimum <?php echo $min_electives_required; ?> elective(s) required for graduation
                                            (<?php echo $completed_electives_count; ?> completed so far, <?php echo $remaining_electives_needed; ?> more needed)
                                        </p>
                                        <p style="margin-top: 0.5rem; font-size: 0.875rem;">
                                            Note: You can register electives in any block. Not required to select electives in this block.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Terms and Conditions -->
                            <div class="terms-container">
                                <div class="terms-checkbox">
                                    <input type="checkbox" id="terms" name="terms" required>
                                    <label for="terms" class="terms-label">
                                        <h5>I agree to the terms and conditions</h5>
                                        <p>By checking this box, I understand and agree to the following:</p>
                                        <ul class="terms-list">
                                            <li>Course registration is binding once submitted</li>
                                            <li>Classes will be automatically created or assigned</li>
                                            <li>I will be billed for the selected courses</li>
                                            <li>Dropping courses after registration may incur fees</li>
                                            <li>I must maintain academic standards to remain enrolled</li>
                                            <li>Registration is subject to seat availability</li>
                                            <li>If a class already exists for this block, I'll be added to the existing class</li>
                                        </ul>
                                    </label>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <button type="submit" name="register_courses" class="btn btn-success btn-lg" id="submit-btn" disabled>
                                    <i class="fas fa-check-circle"></i> Complete Registration
                                </button>

                                <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <div class="system-status">
                <div class="status-indicator"></div>
                <span>System Status: Operational</span>
            </div>
            <div>
                <span><?php echo date('F j, Y'); ?></span>
                <?php if ($registration_open && !empty($period['registration_deadline'])): ?>
                    <span style="margin-left: 1rem; color: var(--warning);">
                        <i class="fas fa-clock"></i>
                        Deadline: <?php echo date('M d', strtotime($period['registration_deadline'])); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        let coursesData = <?php echo json_encode($registerable_courses); ?>;
        let minElectivesRequired = <?php echo $min_electives_required; ?>;
        let maxElectivesAllowed = <?php echo $max_electives_allowed; ?>;
        let remainingElectivesNeeded = <?php echo $remaining_electives_needed; ?>;
        let registrationOpen = <?php echo $registration_open ? 'true' : 'false'; ?>;

        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }

        // Toggle dropdown navigation
        function toggleDropdown(element) {
            const dropdown = element.closest('.nav-dropdown');
            dropdown.classList.toggle('active');
            const allDropdowns = document.querySelectorAll('.nav-dropdown.active');
            allDropdowns.forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('active');
                }
            });
        }

        // Update selection summary
        function updateSelection() {
            let selectedCount = 0;
            let totalHours = 0;
            let coreCount = 0;
            let electiveCount = 0;

            // Update UI for selected courses
            document.querySelectorAll('.course-checkbox:not(:disabled)').forEach(checkbox => {
                const courseId = checkbox.value;
                const courseItem = document.getElementById('course-' + courseId);
                const course = coursesData.find(c => c.id == courseId);

                if (!course) return;

                if (checkbox.checked) {
                    selectedCount++;
                    totalHours += parseInt(course.duration_hours) || 0;

                    if (course.course_type === 'core') {
                        coreCount++;
                    } else {
                        electiveCount++;
                    }

                    if (courseItem) {
                        courseItem.classList.add('selected');
                    }
                } else {
                    if (courseItem) {
                        courseItem.classList.remove('selected');
                    }
                }
            });

            // Update summary
            document.getElementById('selected-count').textContent = selectedCount;
            document.getElementById('total-hours').textContent = totalHours;
            document.getElementById('core-count').textContent = coreCount;
            document.getElementById('elective-count').textContent = electiveCount;

            // Validate maximum electives per block (if any)
            let electiveWarning = '';
            if (maxElectivesAllowed > 0 && electiveCount > maxElectivesAllowed) {
                electiveWarning = `Maximum ${maxElectivesAllowed} elective(s) allowed per block. You have selected ${electiveCount}.`;
            }

            // Validate minimum requirements
            const submitBtn = document.getElementById('submit-btn');
            const termsCheckbox = document.getElementById('terms');

            let isValid = selectedCount > 0;
            let errorMessage = '';

            if (selectedCount === 0) {
                errorMessage = 'Please select at least one course to register.';
            } else if (maxElectivesAllowed > 0 && electiveCount > maxElectivesAllowed) {
                errorMessage = electiveWarning;
                isValid = false;
            }

            if (isValid) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="fas fa-check-circle"></i> Complete Registration (${selectedCount} courses)`;

                if (electiveWarning) {
                    submitBtn.title = electiveWarning;
                } else {
                    submitBtn.title = '';
                }
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Complete Registration';
                submitBtn.title = errorMessage;
            }

            // Enable/disable submit based on terms
            if (isValid && termsCheckbox && termsCheckbox.checked) {
                submitBtn.disabled = false;
            } else if (isValid) {
                submitBtn.disabled = true;
                submitBtn.title = 'You must agree to the terms and conditions';
            }
        }

        // Confirm registration
        function confirmRegistration() {
            if (!registrationOpen) {
                alert("Registration for this period is not currently open.");
                return false;
            }

            const selectedCount = parseInt(document.getElementById('selected-count').textContent);
            if (selectedCount === 0) {
                alert('Please select at least one course to register.');
                return false;
            }

            const coreCount = parseInt(document.getElementById('core-count').textContent);
            const electiveCount = parseInt(document.getElementById('elective-count').textContent);

            // Only check maximum electives per block, not minimum
            if (maxElectivesAllowed > 0 && electiveCount > maxElectivesAllowed) {
                alert(`You can select at most ${maxElectivesAllowed} elective course(s) in one block. You have selected ${electiveCount}.`);
                return false;
            }

            const termsCheckbox = document.getElementById('terms');
            if (!termsCheckbox.checked) {
                alert('You must agree to the terms and conditions to proceed.');
                return false;
            }

            return confirm(`Are you sure you want to register for ${selectedCount} course(s) (${coreCount} core, ${electiveCount} elective)?\n\nClasses will be automatically created or assigned for your selected courses.`);
        }

        // Handle form submission
        function handleFormSubmit(e) {
            if (!confirmRegistration()) {
                e.preventDefault();
            }
        }

        // Load sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                document.getElementById('sidebar').classList.add('collapsed');
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.nav-dropdown') && !event.target.closest('.sidebar')) {
                    document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                        dropdown.classList.remove('active');
                    });
                }
            });

            // Initialize selection
            updateSelection();

            // Add click event to course items
            document.querySelectorAll('.course-item:not(.disabled)').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.type !== 'checkbox' && !e.target.closest('a') && !e.target.closest('button')) {
                        const checkbox = this.querySelector('.course-checkbox');
                        if (checkbox && !checkbox.disabled) {
                            checkbox.checked = !checkbox.checked;
                            updateSelection();
                        }
                    }
                });
            });

            // Add direct change event to checkboxes
            document.querySelectorAll('.course-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const courseId = this.value;
                    const courseItem = document.getElementById('course-' + courseId);

                    if (this.checked) {
                        courseItem?.classList.add('selected');
                    } else {
                        courseItem?.classList.remove('selected');
                    }

                    updateSelection();
                });
            });

            // Add change event to terms checkbox
            const termsCheckbox = document.getElementById('terms');
            if (termsCheckbox) {
                termsCheckbox.addEventListener('change', function() {
                    updateSelection();
                });
            }

            // Add submit event to form
            const form = document.querySelector('.course-selection-form');
            if (form) {
                form.addEventListener('submit', handleFormSubmit);
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + R for registration
                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                    window.location.reload();
                }

                // Ctrl + P for program
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>modules/student/program/';
                }

                // Esc to close dropdowns
                if (e.key === 'Escape') {
                    document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                        dropdown.classList.remove('active');
                    });
                }

                // Ctrl + Enter to submit form
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    const submitBtn = document.getElementById('submit-btn');
                    if (!submitBtn.disabled) {
                        submitBtn.click();
                    }
                }
            });
        });

        // Print registration summary
        function printRegistrationSummary() {
            const printContent = `
            <h2>Course Registration Summary</h2>
            <p><strong>Period:</strong> ${document.querySelector('.period-header h2').textContent}</p>
            <p><strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?></p>
            <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
            <hr>
            <h3>Selected Courses:</h3>
            <ul>
                ${Array.from(document.querySelectorAll('.course-checkbox:checked')).map(checkbox => {
                    const courseId = checkbox.value;
                    const course = coursesData.find(c => c.id == courseId);
                    return `<li>${course.title} (${course.course_code}) - ${course.course_type}</li>`;
                }).join('')}
            </ul>
            <hr>
            <p><strong>Total Courses:</strong> ${document.getElementById('selected-count').textContent}</p>
            <p><strong>Core Courses:</strong> ${document.getElementById('core-count').textContent}</p>
            <p><strong>Elective Courses:</strong> ${document.getElementById('elective-count').textContent}</p>
            <p><strong>Total Hours:</strong> ${document.getElementById('total-hours').textContent}</p>
            <p><strong>Student:</strong> <?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></p>
        `;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
            <html>
                <head>
                    <title>Registration Summary</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2, h3 { color: #333; }
                        hr { margin: 20px 0; border: none; border-top: 1px solid #ddd; }
                    </style>
                </head>
                <body>
                    ${printContent}
                </body>
            </html>
        `);
            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>