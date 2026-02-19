<?php
// modules/instructor/students/progress.php

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

// Get student_id and class_id from URL
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!$student_id || !$class_id) {
    $_SESSION['error'] = 'Student or class ID not specified.';
    header('Location: ' . BASE_URL . 'modules/instructor/students/list.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify instructor has access to this class
$sql = "SELECT COUNT(*) as access FROM class_batches WHERE id = ? AND instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$access = $result->fetch_assoc()['access'];
$stmt->close();

if (!$access) {
    $_SESSION['error'] = 'You do not have access to this class.';
    header('Location: ' . BASE_URL . 'modules/instructor/students/list.php');
    exit();
}

// Get student details
$sql = "SELECT u.*, up.*, e.*, cb.batch_code, cb.name as class_name, 
               c.title as course_title, c.course_code,
               sfs.total_fee, sfs.paid_amount, sfs.balance, sfs.is_cleared, sfs.is_suspended
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.student_id = ? AND e.class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    $_SESSION['error'] = 'Student not found or not enrolled in this class.';
    header('Location: ' . BASE_URL . 'modules/instructor/students/list.php');
    exit();
}

// Get attendance summary
$attendance_sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_days
                   FROM attendance 
                   WHERE enrollment_id = ?";
$stmt = $conn->prepare($attendance_sql);
$stmt->bind_param("i", $student['id']);
$stmt->execute();
$attendance_result = $stmt->get_result();
$attendance = $attendance_result->fetch_assoc();
$stmt->close();

// Calculate attendance percentage
$attendance_total = $attendance['total_days'] ?? 0;
$attendance_present = $attendance['present_days'] ?? 0;
$attendance_percentage = $attendance_total > 0 ? ($attendance_present / $attendance_total) * 100 : 0;

// Get assignments summary
$assignments_sql = "SELECT 
                    a.id, a.title, a.due_date, a.total_points,
                    s.id as submission_id, s.submitted_at, s.grade, s.status as submission_status,
                    s.feedback, s.late_submission
                   FROM assignments a
                   LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
                   WHERE a.class_id = ?
                   ORDER BY a.due_date";
$stmt = $conn->prepare($assignments_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$assignments_result = $stmt->get_result();
$assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate assignment statistics
$total_assignments = count($assignments);
$submitted_assignments = 0;
$graded_assignments = 0;
$late_assignments = 0;
$total_points = 0;
$earned_points = 0;

foreach ($assignments as $assignment) {
    if ($assignment['submission_id']) {
        $submitted_assignments++;
        if ($assignment['grade'] !== null) {
            $graded_assignments++;
            $earned_points += $assignment['grade'];
            $total_points += $assignment['total_points'];
        }
    }
    if ($assignment['late_submission']) {
        $late_assignments++;
    }
}

$completion_rate = $total_assignments > 0 ? ($submitted_assignments / $total_assignments) * 100 : 0;
$average_score = $graded_assignments > 0 ? ($earned_points / $total_points) * 100 : 0;

// Get gradebook entries
$gradebook_sql = "SELECT g.*, a.title as assignment_title, a.total_points
                  FROM gradebook g
                  JOIN assignments a ON g.assignment_id = a.id
                  WHERE g.student_id = ? AND g.enrollment_id = ?
                  ORDER BY a.due_date DESC
                  LIMIT 10";
$stmt = $conn->prepare($gradebook_sql);
$stmt->bind_param("ii", $student_id, $student['id']);
$stmt->execute();
$gradebook_result = $stmt->get_result();
$recent_grades = $gradebook_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get class materials accessed
$materials_sql = "SELECT m.title, m.file_type, m.created_at,
                         ma.access_time, ma.download_count
                  FROM materials m
                  LEFT JOIN material_access_log ma ON m.id = ma.material_id AND ma.user_id = ?
                  WHERE m.class_id = ?
                  ORDER BY m.created_at DESC
                  LIMIT 5";
$stmt = $conn->prepare($materials_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$materials_result = $stmt->get_result();
$materials = $materials_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get participation in discussions
$discussions_sql = "SELECT d.title, dr.created_at, dr.content
                    FROM discussion_replies dr
                    JOIN discussions d ON dr.discussion_id = d.id
                    WHERE dr.user_id = ? AND d.class_id = ?
                    ORDER BY dr.created_at DESC
                    LIMIT 5";
$stmt = $conn->prepare($discussions_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$discussions_result = $stmt->get_result();
$discussion_participation = $discussions_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get overall class ranking (simplified - in real app, use more complex calculation)
$ranking_sql = "SELECT 
                e.student_id,
                COALESCE(AVG(g.percentage), 0) as avg_score,
                COUNT(g.id) as graded_count
               FROM enrollments e
               LEFT JOIN gradebook g ON e.id = g.enrollment_id
               WHERE e.class_id = ?
               GROUP BY e.id
               ORDER BY avg_score DESC, graded_count DESC";
$stmt = $conn->prepare($ranking_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$ranking_result = $stmt->get_result();
$rankings = $ranking_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Find student's rank
$student_rank = 1;
$student_avg_score = 0;
foreach ($rankings as $index => $ranking) {
    if ($ranking['student_id'] == $student_id) {
        $student_rank = $index + 1;
        $student_avg_score = $ranking['avg_score'];
        break;
    }
}
$total_students_in_class = count($rankings);

// Get progress over time (simplified)
$progress_sql = "SELECT 
                 DATE(g.created_at) as date,
                 AVG(g.percentage) as daily_avg,
                 COUNT(g.id) as assignments_graded
                FROM gradebook g
                WHERE g.student_id = ? AND g.enrollment_id = ?
                GROUP BY DATE(g.created_at)
                ORDER BY date
                LIMIT 15";
$stmt = $conn->prepare($progress_sql);
$stmt->bind_param("ii", $student_id, $student['id']);
$stmt->execute();
$progress_result = $stmt->get_result();
$progress_data = $progress_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get upcoming assignments
$upcoming_sql = "SELECT a.*, 
                        CASE 
                            WHEN s.id IS NOT NULL THEN 'submitted'
                            WHEN a.due_date < NOW() THEN 'overdue'
                            ELSE 'pending'
                        END as status
                 FROM assignments a
                 LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
                 WHERE a.class_id = ? 
                 AND (s.id IS NULL OR s.status = 'submitted')
                 AND a.due_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY a.due_date
                 LIMIT 5";
$stmt = $conn->prepare($upcoming_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$upcoming_result = $stmt->get_result();
$upcoming_assignments = $upcoming_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Log activity
logActivity('view_student_progress', 'Instructor viewed student progress for student #' . $student_id, $student_id);

// Close database connection
$conn->close();

// Get instructor name
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
$student_name = $student['first_name'] . ' ' . $student['last_name'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($student_name); ?> - Progress Report - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            padding: 2rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: var(--gray);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Student Header */
        .student-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 2rem;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .student-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 600;
            border: 5px solid white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .student-info h2 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .student-meta {
            display: flex;
            gap: 2rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .meta-item i {
            width: 20px;
            color: var(--primary);
        }

        .student-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tag {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .tag-enrollment {
            background: #dbeafe;
            color: #1e40af;
        }

        .tag-finance {
            background: <?php echo $student['is_cleared'] ? '#d1fae5' : ($student['is_suspended'] ? '#fee2e2' : '#fef3c7'); ?>;
            color: <?php echo $student['is_cleared'] ? '#065f46' : ($student['is_suspended'] ? '#991b1b' : '#92400e'); ?>;
        }

        .tag-grade {
            background: #e0e7ff;
            color: #3730a3;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-icon.attendance {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .stat-icon.assignments {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.grades {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.ranking {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .stat-trend {
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Charts */
        .chart-container {
            height: 300px;
            margin-top: 1rem;
        }

        /* Tables and Lists */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 1rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: var(--light);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-submitted {
            background: #d1fae5;
            color: #065f46;
        }

        .status-graded {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-score {
            font-weight: 600;
            color: var(--dark);
        }

        .grade-a {
            color: var(--success);
        }

        .grade-b {
            color: #3b82f6;
        }

        .grade-c {
            color: var(--warning);
        }

        .grade-d {
            color: #f97316;
        }

        .grade-f {
            color: var(--danger);
        }

        /* Attendance Visualization */
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .attendance-item {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
        }

        .attendance-present {
            background: #d1fae5;
            color: #065f46;
        }

        .attendance-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .attendance-late {
            background: #fef3c7;
            color: #92400e;
        }

        .attendance-excused {
            background: #e0e7ff;
            color: #3730a3;
        }

        .attendance-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .attendance-label {
            font-size: 0.8rem;
        }

        /* Activity List */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .activity-submission {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .activity-material {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .activity-discussion {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .activity-description {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Progress Bars */
        .progress-bar {
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-attendance {
            background: var(--primary);
        }

        .progress-assignments {
            background: var(--success);
        }

        .progress-grades {
            background: var(--warning);
        }

        /* Notes Section */
        .notes-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--light-gray);
        }

        .notes-form textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.9rem;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 1rem;
        }

        .notes-form textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .student-header {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .student-avatar-large {
                margin: 0 auto;
            }

            .student-meta {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-chart-line"></i> Student Progress Report</h1>
                <p>Detailed academic performance and engagement tracking</p>
            </div>
            <div class="header-actions">
                <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>

        <!-- Student Header -->
        <div class="student-header">
            <div class="student-avatar-large">
                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
            </div>
            <div class="student-info">
                <h2><?php echo htmlspecialchars($student_name); ?></h2>
                <div class="student-meta">
                    <div class="meta-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($student['email']); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar"></i>
                        <span>Enrolled: <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-graduation-cap"></i>
                        <span><?php echo htmlspecialchars($student['course_code'] . ' - ' . $student['course_title']); ?></span>
                    </div>
                </div>
                <div class="student-tags">
                    <span class="tag tag-enrollment"><?php echo ucfirst($student['status']); ?></span>
                    <span class="tag tag-finance">
                        <?php
                        if ($student['is_cleared']) {
                            echo 'Payment Cleared';
                        } elseif ($student['is_suspended']) {
                            echo 'Payment Suspended';
                        } elseif ($student['balance'] > 0) {
                            echo 'Balance: ₦' . number_format($student['balance'], 2);
                        } else {
                            echo 'Payment Status: Check';
                        }
                        ?>
                    </span>
                    <span class="tag tag-grade">Class: <?php echo htmlspecialchars($student['batch_code']); ?></span>
                </div>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon attendance">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($attendance_percentage, 1); ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-attendance" style="width: <?php echo $attendance_percentage; ?>%"></div>
                    </div>
                    <div class="stat-trend">
                        <?php echo $attendance_present; ?> of <?php echo $attendance_total; ?> days
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon assignments">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($completion_rate, 1); ?>%</div>
                    <div class="stat-label">Assignment Completion</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-assignments" style="width: <?php echo $completion_rate; ?>%"></div>
                    </div>
                    <div class="stat-trend">
                        <?php echo $submitted_assignments; ?> of <?php echo $total_assignments; ?> submitted
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon grades">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($average_score, 1); ?>%</div>
                    <div class="stat-label">Average Score</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-grades" style="width: <?php echo $average_score; ?>%"></div>
                    </div>
                    <div class="stat-trend">
                        <?php echo $graded_assignments; ?> graded assignments
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon ranking">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">#<?php echo $student_rank; ?></div>
                    <div class="stat-label">Class Ranking</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-grades" style="width: <?php echo ($total_students_in_class > 1) ? (($total_students_in_class - $student_rank + 1) / $total_students_in_class * 100) : 100; ?>%"></div>
                    </div>
                    <div class="stat-trend">
                        Top <?php echo number_format(($total_students_in_class - $student_rank + 1) / $total_students_in_class * 100, 1); ?>% of <?php echo $total_students_in_class; ?> students
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Assignments Performance -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-tasks"></i> Assignments Performance</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/" class="btn btn-primary btn-sm">
                                View All Assignments
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($assignments)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Assignment</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Score</th>
                                        <th>Feedback</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        $grade_class = '';

                                        if ($assignment['submission_id']) {
                                            if ($assignment['grade'] !== null) {
                                                $status_class = 'status-graded';
                                                $status_text = 'Graded';
                                                $percentage = ($assignment['grade'] / $assignment['total_points']) * 100;

                                                if ($percentage >= 90) $grade_class = 'grade-a';
                                                elseif ($percentage >= 80) $grade_class = 'grade-b';
                                                elseif ($percentage >= 70) $grade_class = 'grade-c';
                                                elseif ($percentage >= 60) $grade_class = 'grade-d';
                                                else $grade_class = 'grade-f';
                                            } else {
                                                $status_class = 'status-submitted';
                                                $status_text = 'Submitted';
                                            }
                                        } else {
                                            if (strtotime($assignment['due_date']) < time()) {
                                                $status_class = 'status-overdue';
                                                $status_text = 'Overdue';
                                            } else {
                                                $status_class = 'status-pending';
                                                $status_text = 'Pending';
                                            }
                                        }

                                        if ($assignment['late_submission']) {
                                            $status_class = 'status-late';
                                            $status_text = 'Late';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--gray);">
                                                    Max: <?php echo $assignment['total_points']; ?> pts
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                                <?php if ($assignment['submitted_at']): ?>
                                                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                                        <?php echo date('M d', strtotime($assignment['submitted_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($assignment['grade'] !== null): ?>
                                                    <span class="grade-score <?php echo $grade_class; ?>">
                                                        <?php echo $assignment['grade']; ?>/<?php echo $assignment['total_points']; ?>
                                                    </span>
                                                    <div style="font-size: 0.875rem; color: var(--gray);">
                                                        <?php echo number_format(($assignment['grade'] / $assignment['total_points']) * 100, 1); ?>%
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--gray);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($assignment['feedback']): ?>
                                                    <div style="max-width: 200px; font-size: 0.875rem; color: var(--gray);">
                                                        <?php echo htmlspecialchars(substr($assignment['feedback'], 0, 50)); ?>...
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--gray); font-size: 0.875rem;">No feedback yet</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: var(--gray);">
                            <i class="fas fa-tasks" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No Assignments Found</h3>
                            <p>No assignments have been created for this class yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Grades Chart -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-chart-bar"></i> Performance Trend</h2>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Attendance Details -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Attendance Summary</h2>
                    </div>

                    <div class="attendance-grid">
                        <div class="attendance-item attendance-present">
                            <div class="attendance-count"><?php echo $attendance['present_days'] ?? 0; ?></div>
                            <div class="attendance-label">Present</div>
                        </div>
                        <div class="attendance-item attendance-absent">
                            <div class="attendance-count"><?php echo $attendance['absent_days'] ?? 0; ?></div>
                            <div class="attendance-label">Absent</div>
                        </div>
                        <div class="attendance-item attendance-late">
                            <div class="attendance-count"><?php echo $attendance['late_days'] ?? 0; ?></div>
                            <div class="attendance-label">Late</div>
                        </div>
                        <div class="attendance-item attendance-excused">
                            <div class="attendance-count"><?php echo $attendance['excused_days'] ?? 0; ?></div>
                            <div class="attendance-label">Excused</div>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--light-gray);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span style="font-weight: 600; color: var(--dark);">Attendance Rate</span>
                            <span style="font-weight: 700; color: var(--primary);"><?php echo number_format($attendance_percentage, 1); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-attendance" style="width: <?php echo $attendance_percentage; ?>%"></div>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--gray); text-align: right; margin-top: 0.25rem;">
                            Based on <?php echo $attendance_total; ?> class days
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-history"></i> Recent Activity</h2>
                    </div>

                    <div class="activity-list">
                        <?php if (empty($recent_grades) && empty($discussion_participation) && empty($materials)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No recent activity found</p>
                            </div>
                        <?php else: ?>
                            <!-- Recent Grades -->
                            <?php foreach (array_slice($recent_grades, 0, 3) as $grade): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-submission">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Grade Received</div>
                                        <div class="activity-description">
                                            <?php echo htmlspecialchars($grade['assignment_title']); ?> -
                                            <?php echo number_format($grade['percentage'], 1); ?>%
                                        </div>
                                        <div class="activity-meta">
                                            <span><?php echo time_ago($grade['created_at'] ?? date('Y-m-d H:i:s')); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Discussion Participation -->
                            <?php foreach (array_slice($discussion_participation, 0, 2) as $post): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-discussion">
                                        <i class="fas fa-comment"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Discussion Post</div>
                                        <div class="activity-description">
                                            <?php echo htmlspecialchars($post['title']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span><?php echo time_ago($post['created_at']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Material Access -->
                            <?php foreach (array_slice($materials, 0, 2) as $material): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-material">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Material Accessed</div>
                                        <div class="activity-description">
                                            <?php echo htmlspecialchars($material['title']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span><?php echo $material['access_time'] ? time_ago($material['access_time']) : 'Not accessed'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Deadlines -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-clock"></i> Upcoming Deadlines</h2>
                    </div>

                    <div class="activity-list">
                        <?php if (empty($upcoming_assignments)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; color: var(--success);"></i>
                                <p>No upcoming deadlines</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_assignments as $assignment): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $assignment['status'] == 'submitted' ? 'activity-submission' : 'activity-material'; ?>">
                                        <i class="fas fa-<?php echo $assignment['status'] == 'submitted' ? 'check' : 'clock'; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                        <div class="activity-description">
                                            Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                                <?php echo ucfirst($assignment['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes Section -->
        <div class="content-card">
            <div class="notes-section">
                <h3 style="margin-bottom: 1rem; color: var(--dark);">
                    <i class="fas fa-edit"></i> Instructor Notes
                </h3>
                <form class="notes-form" method="POST" action="<?php echo BASE_URL; ?>modules/instructor/students/save_notes.php">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                    <textarea name="notes" placeholder="Add notes about this student's progress, strengths, areas for improvement..."></textarea>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Notes
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="this.form.reset()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--gray);">
                            Notes are private to instructors only
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Performance Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');

        // Prepare chart data
        const labels = [];
        const scores = [];

        <?php if (!empty($progress_data)): ?>
            <?php foreach ($progress_data as $data): ?>
                labels.push('<?php echo date("M d", strtotime($data['date'])); ?>');
                scores.push(<?php echo $data['daily_avg']; ?>);
            <?php endforeach; ?>
        <?php else: ?>
            // Default data if no progress data
            labels.push('Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5');
            scores.push(75, 82, 78, 85, 88);
        <?php endif; ?>

        const performanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Average Score (%)',
                    data: scores,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score (%)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    }
                }
            }
        });

        // Print functionality
        function printReport() {
            const originalContent = document.body.innerHTML;
            const printContent = document.querySelector('.container').innerHTML;

            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Progress Report - <?php echo htmlspecialchars($student_name); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 2rem; }
                        .print-header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #ccc; padding-bottom: 1rem; }
                        .student-info { margin-bottom: 2rem; }
                        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 2rem; }
                        .stat-card { border: 1px solid #ddd; padding: 1rem; border-radius: 5px; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
                        th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
                        th { background-color: #f5f5f5; }
                        .no-print { display: none; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Student Progress Report</h1>
                        <h2><?php echo htmlspecialchars($student_name); ?></h2>
                        <p><?php echo htmlspecialchars($student['course_code'] . ' - ' . $student['course_title']); ?> | <?php echo htmlspecialchars($student['batch_code']); ?></p>
                        <p>Generated: <?php echo date('F j, Y, g:i a'); ?></p>
                    </div>
                    ${printContent}
                    <div style="margin-top: 3rem; text-align: center; color: #666; font-size: 0.9rem;">
                        <p>Report generated by Impact Digital Academy</p>
                        <p>This is an official progress report for internal use only.</p>
                    </div>
                </body>
                </html>
            `;

            window.print();
            document.body.innerHTML = originalContent;
            window.location.reload();
        }

        // Add click event to print button
        document.querySelector('button[onclick="window.print()"]').addEventListener('click', function(e) {
            e.preventDefault();
            printReport();
        });

        // Auto-refresh data every 5 minutes
        let refreshTimer = null;

        function startAutoRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            refreshTimer = setInterval(() => {
                console.log('Auto-refreshing student progress data...');
                // In a real app, you would fetch updated data via AJAX
                // For now, just log to console
            }, 5 * 60 * 1000); // 5 minutes
        }

        function stopAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        }

        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', startAutoRefresh);

        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printReport();
            }

            // Ctrl/Cmd + S to save notes
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('.notes-form button[type="submit"]').click();
            }

            // Esc to go back
            if (e.key === 'Escape') {
                window.history.back();
            }
        });

        // Highlight overdue assignments
        document.addEventListener('DOMContentLoaded', function() {
            const dueDates = document.querySelectorAll('.data-table td:nth-child(2)');
            dueDates.forEach(cell => {
                const dateText = cell.textContent.trim();
                const dueDate = new Date(dateText);
                const today = new Date();

                if (dueDate < today) {
                    cell.style.color = 'var(--danger)';
                    cell.style.fontWeight = '600';
                }
            });
        });
    </script>
</body>

</html>