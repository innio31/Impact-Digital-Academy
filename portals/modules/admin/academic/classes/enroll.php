<?php
// modules/admin/academic/classes/enroll.php
// Add at the top after session start
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/enrollment_debug.log');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get class ID
$class_id = $_GET['class_id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class details
$sql = "SELECT cb.*, 
               c.title as course_title, 
               c.course_code, 
               p.name as program_name,
               p.program_type as program_program_type,
               cb.program_type as class_program_type,
               COUNT(e.id) as current_enrollments
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
        WHERE cb.id = ?
        GROUP BY cb.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();

// Determine which program_type to use (prefer class_batches program_type, fallback to program's)
$program_type = !empty($class['class_program_type']) ? $class['class_program_type'] : ($class['program_program_type'] ?? 'online');

// Add this to the class array for easy access
$class['program_type'] = $program_type;

// Debug log to verify
error_log("Class program_type from class_batches: " . ($class['class_program_type'] ?? 'empty'));
error_log("Class program_type from programs: " . ($class['program_program_type'] ?? 'empty'));
error_log("Final program_type being used: " . $class['program_type']);

if (!$class) {
    $_SESSION['error'] = 'Class not found.';
    header('Location: list.php');
    exit();
}

// Check if class is full
$is_full = $class['current_enrollments'] >= $class['max_students'];

// Fetch available students
$available_students = [];
$search_term = $_GET['search'] ?? '';

// Build query for available students
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status,
               up.date_of_birth, up.gender, up.city, up.state,
               COUNT(DISTINCT e.id) as total_enrollments,
               GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as enrolled_programs
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN enrollments e ON u.id = e.student_id AND e.status = 'active'
        LEFT JOIN class_batches cb ON e.class_id = cb.id
        LEFT JOIN courses c ON cb.course_id = c.id
        LEFT JOIN programs p ON c.program_id = p.id
        WHERE u.role = 'student' 
        AND u.status = 'active'
        AND u.id NOT IN (
            SELECT student_id FROM enrollments 
            WHERE class_id = ? AND status IN ('active', 'completed')
        )";

$params = [$class_id];
$types = 'i';

if (!empty($search_term)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search_term%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssss';
}

$sql .= " GROUP BY u.id
          ORDER BY u.first_name, u.last_name
          LIMIT 100";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $available_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $student_ids = $_POST['student_ids'] ?? [];

    if (!validateCSRFToken($csrf_token)) {
        $_SESSION['error'] = 'Invalid CSRF token.';
    } elseif (empty($student_ids)) {
        $_SESSION['error'] = 'Please select at least one student to enroll.';
    } elseif ($is_full) {
        $_SESSION['error'] = 'Class is at maximum capacity.';
    } else {
        $conn->begin_transaction();

        try {
            $success_count = 0;
            $errors = [];

            // Debug: Log the class details
            error_log("========== ENROLLMENT DEBUG START ==========");
            error_log("Class ID: " . $class_id);
            error_log("Class program_type: " . $class['program_type']);
            error_log("Student IDs to enroll: " . implode(', ', $student_ids));

            foreach ($student_ids as $student_id) {
                error_log("Processing student ID: " . $student_id);

                // Check if student exists
                $check_user_sql = "SELECT id, first_name, last_name FROM users WHERE id = ? AND role = 'student'";
                $check_user_stmt = $conn->prepare($check_user_sql);
                $check_user_stmt->bind_param('i', $student_id);
                $check_user_stmt->execute();
                $user_result = $check_user_stmt->get_result();

                if ($user_result->num_rows === 0) {
                    $errors[] = "Student #$student_id not found or is not a student.";
                    error_log("Student #$student_id not found");
                    continue;
                }

                $user_data = $user_result->fetch_assoc();
                error_log("Found student: " . $user_data['first_name'] . " " . $user_data['last_name']);

                // Check if already enrolled
                $check_sql = "SELECT id, status FROM enrollments 
                             WHERE student_id = ? AND class_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('ii', $student_id, $class_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $existing = $check_result->fetch_assoc();
                    $errors[] = "Student #$student_id is already enrolled in this class (Status: " . $existing['status'] . ").";
                    error_log("Student already enrolled with status: " . $existing['status']);
                    continue;
                }

                // Check capacity
                $current_count = $class['current_enrollments'] + $success_count;
                if ($current_count >= $class['max_students']) {
                    $errors[] = "Class capacity reached. Enrolled $success_count student(s).";
                    error_log("Class capacity reached: $current_count >= " . $class['max_students']);
                    break;
                }

                // Set attendance mode based on class type
                $attendance_mode = 'virtual';
                if ($class['program_type'] === 'onsite' || $class['program_type'] === 'school') {
                    $attendance_mode = 'physical';
                }

                // Debug: Show what we're inserting
                error_log("Attempting to insert:");
                error_log("student_id: $student_id (" . gettype($student_id) . ")");
                error_log("class_id: $class_id (" . gettype($class_id) . ")");
                error_log("program_type: " . $class['program_type'] . " (" . gettype($class['program_type']) . ")");
                error_log("attendance_mode: $attendance_mode (" . gettype($attendance_mode) . ")");

                // Insert enrollment
                $enroll_sql = "INSERT INTO enrollments 
                              (student_id, class_id, enrollment_date, status, 
                               program_type, attendance_mode, created_at, updated_at)
                              VALUES (?, ?, CURDATE(), 'active', ?, ?, NOW(), NOW())";

                $enroll_stmt = $conn->prepare($enroll_sql);
                if (!$enroll_stmt) {
                    throw new Exception("Prepare failed for enrollment: " . $conn->error);
                }

                $enroll_stmt->bind_param('iiss', $student_id, $class_id, $class['program_type'], $attendance_mode);

                if (!$enroll_stmt->execute()) {
                    throw new Exception("Failed to enroll student: " . $enroll_stmt->error . " (Error code: " . $enroll_stmt->errno . ")");
                }

                $enrollment_id = $conn->insert_id;
                error_log("Enrollment successful! Enrollment ID: " . $enrollment_id);

                // Get program fee
                $fee_sql = "SELECT p.fee, p.name, p.id as program_id 
                           FROM programs p
                           JOIN courses c ON p.id = c.program_id
                           JOIN class_batches cb ON c.id = cb.course_id
                           WHERE cb.id = ?";
                $fee_stmt = $conn->prepare($fee_sql);
                $fee_stmt->bind_param('i', $class_id);
                $fee_stmt->execute();
                $fee_result = $fee_stmt->get_result();
                $fee_data = $fee_result->fetch_assoc();
                $program_fee = $fee_data['fee'] ?? 0.00;

                error_log("Program fee retrieved: $program_fee for program: " . ($fee_data['name'] ?? 'Unknown'));

                // Insert financial status
                $financial_sql = "INSERT INTO student_financial_status 
                                 (student_id, class_id, total_fee, paid_amount, balance, 
                                  current_block, is_cleared, is_suspended, created_at, updated_at)
                                 VALUES (?, ?, ?, 0, ?, 1, 0, 0, NOW(), NOW())";

                $financial_stmt = $conn->prepare($financial_sql);
                if (!$financial_stmt) {
                    throw new Exception("Prepare failed for financial: " . $conn->error);
                }

                $balance = $program_fee;
                $financial_stmt->bind_param('iidd', $student_id, $class_id, $program_fee, $balance);

                if (!$financial_stmt->execute()) {
                    throw new Exception("Failed to create financial record: " . $financial_stmt->error . " (Error code: " . $financial_stmt->errno . ")");
                }

                $financial_id = $conn->insert_id;
                error_log("Financial status created! Financial ID: " . $financial_id);

                // Log activity
                logActivity(
                    $_SESSION['user_id'],
                    'enrollment_create',
                    "Enrolled student #$student_id in class #$class_id",
                    'enrollments',
                    $enrollment_id
                );

                $success_count++;
                error_log("Student $student_id successfully enrolled! Success count: $success_count");
            }

            $conn->commit();
            error_log("Transaction committed. Total enrolled: $success_count");
            error_log("========== ENROLLMENT DEBUG END ==========");

            if ($success_count > 0) {
                $_SESSION['success'] = "Successfully enrolled $success_count student(s).";
                header('Location: view.php?id=' . $class_id);
                exit();
            }

            if (!empty($errors)) {
                $_SESSION['error'] = implode('<br>', $errors);
                error_log("Errors: " . implode(', ', $errors));
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("========== ENROLLMENT ERROR ==========");
            error_log("Exception: " . $e->getMessage());
            error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
            error_log("Trace: " . $e->getTraceAsString());

            // Get MySQL error if available
            if (isset($conn)) {
                error_log("MySQL errno: " . $conn->errno);
                error_log("MySQL error: " . $conn->error);
            }
            error_log("========== END ERROR ==========");

            $_SESSION['error'] = "Enrollment failed: " . $e->getMessage();
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Enroll Students - <?php echo htmlspecialchars($class['batch_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Keep all the existing CSS styles from the previous version */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        body {
            background: #f3f4f6;
            min-height: 100vh;
        }

        .mobile-container {
            max-width: 100%;
            min-height: 100vh;
            background: white;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header-top {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
        }

        .header h1 {
            font-size: 1.2rem;
            font-weight: 600;
            flex: 1;
        }

        .header-sub {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            margin: 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Class Info Card */
        .class-card {
            background: white;
            margin: 1rem;
            padding: 1.25rem;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e7eb;
        }

        .class-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.5rem;
        }

        .class-code {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem 0.5rem;
            background: #f9fafb;
            border-radius: 12px;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2563eb;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .capacity-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0 0.5rem;
        }

        .capacity-fill {
            height: 100%;
            background: #2563eb;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .capacity-full .capacity-fill {
            background: #ef4444;
        }

        .capacity-text {
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }

        /* Search Section */
        .search-section {
            margin: 1rem;
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-box input {
            width: 100%;
            padding: 1rem 1rem 1rem 2.8rem;
            border: 2px solid #e5e7eb;
            border-radius: 50px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            flex: 1;
            padding: 0.875rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:active {
            background: #1e40af;
            transform: scale(0.98);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:active {
            background: #e5e7eb;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn:disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Students List */
        .students-list {
            margin: 1rem;
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0 0.5rem;
        }

        .list-title {
            font-weight: 600;
            color: #374151;
        }

        .select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: #2563eb;
        }

        .student-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s;
        }

        .student-item:active {
            background: #f9fafb;
            transform: scale(0.99);
        }

        .student-checkbox {
            width: 24px;
            height: 24px;
            accent-color: #2563eb;
        }

        .student-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.25rem;
        }

        .student-details {
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .student-details span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .student-badge {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            color: #374151;
        }

        /* Bulk Actions */
        .bulk-actions {
            position: sticky;
            bottom: 1rem;
            margin: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e5e7eb;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .selected-count {
            background: #2563eb;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .bulk-buttons {
            display: flex;
            gap: 0.5rem;
            flex: 1;
        }

        .bulk-btn {
            flex: 1;
            padding: 0.75rem;
            border: none;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            background: #f3f4f6;
            color: #374151;
        }

        .bulk-btn:active {
            background: #e5e7eb;
        }

        /* Form Actions */
        .form-actions {
            margin: 2rem 1rem;
            display: flex;
            gap: 0.75rem;
        }

        .form-actions .btn {
            flex: 1;
            padding: 1rem;
            font-size: 1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .empty-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        /* Loading Spinner */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f4f6;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
        @media (min-width: 768px) {
            .mobile-container {
                max-width: 500px;
                margin: 0 auto;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            }

            body {
                background: #f3f4f6;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="mobile-container">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <a href="view.php?id=<?php echo $class_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1>Enroll Students</h1>
            </div>
            <div class="header-sub">
                <i class="fas fa-chalkboard-teacher"></i>
                <span><?php echo htmlspecialchars($class['batch_code']); ?></span>
                <span class="class-code"><?php echo htmlspecialchars($class['course_code']); ?></span>
                <span class="class-code" style="background: #fef3c7; color: #92400e;">
                    <?php echo ucfirst($class['program_type']); ?>
                </span>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success'];
                unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error'];
                unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Class Info Card -->
        <div class="class-card">
            <div class="class-title"><?php echo htmlspecialchars($class['name']); ?></div>
            <div class="class-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $class['current_enrollments']; ?></div>
                    <div class="stat-label">Enrolled</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $class['max_students']; ?></div>
                    <div class="stat-label">Capacity</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $class['max_students'] - $class['current_enrollments']; ?></div>
                    <div class="stat-label">Available</div>
                </div>
            </div>
            <div class="capacity-bar <?php echo $is_full ? 'capacity-full' : ''; ?>">
                <div class="capacity-fill" style="width: <?php echo min(100, ($class['current_enrollments'] / $class['max_students']) * 100); ?>%"></div>
            </div>
            <div class="capacity-text">
                <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($class['start_date'])); ?></span>
                <span><i class="fas fa-calendar-check"></i> <?php echo date('M j', strtotime($class['end_date'])); ?></span>
            </div>
        </div>

        <?php if ($is_full): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                This class is full. No more students can be enrolled.
            </div>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="search-section">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text"
                    id="student-search"
                    placeholder="Search students..."
                    value="<?php echo htmlspecialchars($search_term); ?>"
                    onkeypress="if(event.key === 'Enter') searchStudents()">
            </div>
            <div class="search-actions">
                <button class="btn btn-primary" onclick="searchStudents()">
                    <i class="fas fa-search"></i> Search
                </button>
                <button class="btn btn-secondary" onclick="clearSearch()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>

        <!-- Students List -->
        <div class="students-list">
            <div class="list-header">
                <span class="list-title">Available Students (<?php echo count($available_students); ?>)</span>
                <?php if (!empty($available_students) && !$is_full): ?>
                    <label class="select-all">
                        <input type="checkbox" id="select-all" onchange="toggleAll()">
                        <span>Select All</span>
                    </label>
                <?php endif; ?>
            </div>

            <form method="POST" id="enrollment-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <?php if (!empty($available_students)): ?>
                    <?php foreach ($available_students as $student):
                        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                        $age = $student['date_of_birth'] ? date('Y') - date('Y', strtotime($student['date_of_birth'])) : null;
                    ?>
                        <div class="student-item">
                            <input type="checkbox"
                                name="student_ids[]"
                                value="<?php echo $student['id']; ?>"
                                class="student-checkbox"
                                id="student-<?php echo $student['id']; ?>"
                                onchange="updateSelection()"
                                <?php echo $is_full ? 'disabled' : ''; ?>>

                            <div class="student-avatar"><?php echo $initials; ?></div>

                            <div class="student-info">
                                <div class="student-name">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                </div>
                                <div class="student-details">
                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></span>
                                    <?php if ($student['phone']): ?>
                                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($student['phone']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($age): ?>
                                        <span><i class="fas fa-birthday-cake"></i> <?php echo $age; ?>y</span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 0.5rem;">
                                    <span class="student-badge">
                                        <i class="fas fa-book-open"></i> <?php echo $student['total_enrollments']; ?> active
                                    </span>
                                    <?php if ($student['city']): ?>
                                        <span class="student-badge">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($student['city']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <h3>No Available Students</h3>
                        <p>
                            <?php if (!empty($search_term)): ?>
                                No students match your search. Try a different term.
                            <?php else: ?>
                                All eligible students are already enrolled in this class.
                            <?php endif; ?>
                        </p>
                        <div class="empty-actions">
                            <a href="../users/create.php" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Add New Student
                            </a>
                            <a href="list.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Classes
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Bulk Actions (hidden by default) -->
                <div id="bulk-actions" class="bulk-actions" style="display: none;">
                    <span class="selected-count" id="selected-count">0</span>
                    <div class="bulk-buttons">
                        <button type="button" class="bulk-btn" onclick="clearSelection()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                        <button type="button" class="bulk-btn" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> All
                        </button>
                    </div>
                </div>

                <!-- Form Actions -->
                <?php if (!empty($available_students) && !$is_full): ?>
                    <div class="form-actions">
                        <a href="view.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success" id="enroll-btn">
                            <i class="fas fa-user-plus"></i> Enroll Selected
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        // Update selection count and show/hide bulk actions
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const selectedCount = checkboxes.length;
            const bulkActions = document.getElementById('bulk-actions');

            if (selectedCount > 0) {
                document.getElementById('selected-count').textContent = selectedCount;
                bulkActions.style.display = 'flex';
            } else {
                bulkActions.style.display = 'none';
            }

            // Update select all checkbox
            const totalCheckboxes = document.querySelectorAll('.student-checkbox:not(:disabled)').length;
            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
            }
        }

        // Toggle all checkboxes
        function toggleAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');

            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });

            updateSelection();
        }

        // Select all students
        function selectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelection();
        }

        // Clear all selections
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelection();
        }

        // Search students
        function searchStudents() {
            const searchTerm = document.getElementById('student-search').value.trim();
            let url = 'enroll.php?class_id=<?php echo $class_id; ?>';

            if (searchTerm) {
                url += '&search=' + encodeURIComponent(searchTerm);
            }

            window.location.href = url;
        }

        // Clear search
        function clearSearch() {
            window.location.href = 'enroll.php?class_id=<?php echo $class_id; ?>';
        }

        // Form submission
        document.getElementById('enrollment-form')?.addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');

            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one student to enroll.');
                return false;
            }

            const btn = document.getElementById('enroll-btn');
            if (btn) {
                btn.innerHTML = '<div class="loading"></div> Enrolling...';
                btn.disabled = true;
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelection();

            // Add click handlers to student items for better mobile experience
            document.querySelectorAll('.student-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.type !== 'checkbox') {
                        const checkbox = this.querySelector('.student-checkbox');
                        if (checkbox && !checkbox.disabled) {
                            checkbox.checked = !checkbox.checked;
                            updateSelection();
                        }
                    }
                });
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('student-search')?.focus();
            }

            // Escape to clear selection
            if (e.key === 'Escape') {
                clearSelection();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>