<?php
// modules/instructor/classes/student_profile.php

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

// Check required parameters
if (!isset($_GET['student_id']) || !isset($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$student_id = (int)$_GET['student_id'];
$class_id = (int)$_GET['class_id'];
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify the instructor has access to this class
$sql = "SELECT cb.*, c.course_code, c.title as course_title 
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

// Get student details with enrollment info
$sql = "SELECT 
            u.id, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
            up.date_of_birth, up.gender, up.address, up.city, up.state, up.country,
            up.bio, up.website, up.linkedin_url, up.github_url,
            up.qualifications, up.experience_years, up.current_job_title, up.current_company,
            e.enrollment_date, e.status as enrollment_status, e.final_grade, e.completion_date,
            e.certificate_issued, e.certificate_url
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        JOIN enrollments e ON u.id = e.student_id
        WHERE u.id = ? AND e.class_id = ? AND u.role = 'student'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: students.php?class_id=' . $class_id);
    exit();
}

$student = $result->fetch_assoc();
$stmt->close();

// Get financial status
$sql = "SELECT * FROM student_financial_status 
        WHERE student_id = ? AND class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$financial_status = $result->fetch_assoc();
$stmt->close();

// Get all assignments with submission status
$sql = "SELECT 
            a.id, a.title, a.due_date, a.total_points,
            s.id as submission_id, s.submitted_at, s.grade, s.status as submission_status,
            s.feedback, s.late_submission
        FROM assignments a
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
        WHERE a.class_id = ?
        ORDER BY a.due_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get attendance records
$sql = "SELECT 
            attendance_date, status, check_in_time, check_out_time, notes
        FROM attendance
        WHERE enrollment_id = (SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?)
        ORDER BY attendance_date DESC
        LIMIT 30";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$attendance = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get attendance summary
$sql = "SELECT 
            COUNT(*) as total_sessions,
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count
        FROM attendance
        WHERE enrollment_id = (SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$attendance_summary = $result->fetch_assoc();
$stmt->close();

// Calculate statistics
$total_assignments = count($assignments);
$submitted_assignments = count(array_filter($assignments, function ($a) {
    return !empty($a['submission_id']);
}));
$graded_assignments = count(array_filter($assignments, function ($a) {
    return !empty($a['grade']);
}));
$late_submissions = count(array_filter($assignments, function ($a) {
    return $a['late_submission'] == 1;
}));

$total_points = 0;
$earned_points = 0;
foreach ($assignments as $assignment) {
    if (!empty($assignment['grade'])) {
        $total_points += $assignment['total_points'];
        $earned_points += $assignment['grade'];
    }
}
$average_grade = $graded_assignments > 0 ? round($earned_points / $graded_assignments, 1) : 0;

// Calculate attendance percentage
$attendance_percentage = $attendance_summary['total_sessions'] > 0
    ? round(($attendance_summary['present_count'] / $attendance_summary['total_sessions']) * 100, 1)
    : 0;

$conn->close();

// Log activity
logActivity('view_student_profile', "Viewed student profile: {$student['first_name']} {$student['last_name']}", 'users', $student_id);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> - Student Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Add to existing CSS or create new */
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Header */
        .profile-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .profile-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .meta-item i {
            color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
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
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
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
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .card-header h2 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Financial Status */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .financial-item {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .financial-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .financial-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .financial-value.positive {
            color: var(--success);
        }

        .financial-value.negative {
            color: var(--danger);
        }

        /* Attendance Chart */
        .attendance-chart {
            display: flex;
            gap: 0.25rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .attendance-day {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            cursor: pointer;
            position: relative;
        }

        .attendance-day.present {
            background: var(--success);
        }

        .attendance-day.absent {
            background: var(--danger);
        }

        .attendance-day.late {
            background: var(--warning);
        }

        .attendance-day.excused {
            background: var(--info);
        }

        .attendance-day.no-data {
            background: #e2e8f0;
        }

        .attendance-tooltip {
            position: absolute;
            background: var(--dark);
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            z-index: 10;
            display: none;
            white-space: nowrap;
        }

        .attendance-day:hover .attendance-tooltip {
            display: block;
        }

        /* Progress bars */
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
        }

        /* Empty states */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <a href="students.php?class_id=<?php echo $class_id; ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <span class="separator">/</span>
            <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
        </div>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
                <p><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></p>

                <div class="profile-meta">
                    <div class="meta-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($student['email']); ?></span>
                    </div>
                    <?php if (!empty($student['phone'])): ?>
                        <div class="meta-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($student['phone']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Enrolled: <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-user-check"></i>
                        <span>Status: <span class="badge badge-<?php echo $student['enrollment_status'] == 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($student['enrollment_status']); ?>
                            </span></span>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <a href="send_message.php?student_id=<?php echo $student_id; ?>&class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                    <i class="fas fa-envelope"></i> Send Message
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_assignments; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $submitted_assignments; ?></div>
                <div class="stat-label">Submitted</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $total_assignments > 0 ? ($submitted_assignments / $total_assignments * 100) : 0; ?>%"></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $average_grade; ?></div>
                <div class="stat-label">Average Grade</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $attendance_percentage; ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
        </div>

        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Personal Information -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-circle"></i> Personal Information</h2>
                    </div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Date of Birth</div>
                            <div class="detail-value">
                                <?php echo $student['date_of_birth'] ? date('M d, Y', strtotime($student['date_of_birth'])) : 'Not provided'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Gender</div>
                            <div class="detail-value">
                                <?php echo $student['gender'] ? ucfirst($student['gender']) : 'Not specified'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Location</div>
                            <div class="detail-value">
                                <?php
                                $location = array_filter([
                                    $student['city'],
                                    $student['state'],
                                    $student['country']
                                ]);
                                echo $location ? htmlspecialchars(implode(', ', $location)) : 'Not provided';
                                ?>
                            </div>
                        </div>
                        <?php if (!empty($student['current_job_title'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Current Position</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($student['current_job_title']); ?>
                                    <?php if (!empty($student['current_company'])): ?>
                                        at <?php echo htmlspecialchars($student['current_company']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($student['experience_years'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Experience</div>
                                <div class="detail-value">
                                    <?php echo $student['experience_years']; ?> year<?php echo $student['experience_years'] != 1 ? 's' : ''; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($student['bio'])): ?>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Bio</div>
                                <div class="detail-value" style="line-height: 1.6;">
                                    <?php echo nl2br(htmlspecialchars($student['bio'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Financial Status -->
                <?php if ($financial_status): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-money-bill-wave"></i> Financial Status</h2>
                            <span class="badge <?php echo $financial_status['is_cleared'] ? 'badge-success' : ($financial_status['is_suspended'] ? 'badge-danger' : 'badge-warning'); ?>">
                                <?php echo $financial_status['is_cleared'] ? 'Cleared' : ($financial_status['is_suspended'] ? 'Suspended' : 'Pending'); ?>
                            </span>
                        </div>
                        <div class="financial-summary">
                            <div class="financial-item">
                                <div class="financial-label">Total Fee</div>
                                <div class="financial-value">₦<?php echo number_format($financial_status['total_fee'] ?? 0, 2); ?></div>
                            </div>
                            <div class="financial-item">
                                <div class="financial-label">Paid Amount</div>
                                <div class="financial-value positive">₦<?php echo number_format($financial_status['paid_amount'] ?? 0, 2); ?></div>
                            </div>
                            <div class="financial-item">
                                <div class="financial-label">Balance</div>
                                <div class="financial-value <?php echo ($financial_status['balance'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                                    ₦<?php echo number_format($financial_status['balance'] ?? 0, 2); ?>
                                </div>
                            </div>
                        </div>
                        <div class="payment-status" style="margin-top: 1.5rem;">
                            <h3 style="margin-bottom: 1rem; font-size: 1rem; color: var(--dark);">Payment Breakdown</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Registration Fee</div>
                                    <div class="detail-value">
                                        <span class="badge <?php echo $financial_status['registration_paid'] ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $financial_status['registration_paid'] ? 'Paid' : 'Pending'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Block 1</div>
                                    <div class="detail-value">
                                        <span class="badge <?php echo $financial_status['block1_paid'] ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $financial_status['block1_paid'] ? 'Paid' : 'Pending'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Block 2</div>
                                    <div class="detail-value">
                                        <span class="badge <?php echo $financial_status['block2_paid'] ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $financial_status['block2_paid'] ? 'Paid' : 'Pending'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Recent Assignments -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-tasks"></i> Assignments</h2>
                        <span class="badge badge-info"><?php echo $late_submissions; ?> Late</span>
                    </div>
                    <div class="table-container">
                        <?php if (empty($assignments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <p>No assignments found</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Assignment</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($assignment['due_date'])); ?></td>
                                            <td>
                                                <?php if (empty($assignment['submission_id'])): ?>
                                                    <span class="badge badge-danger">Not Submitted</span>
                                                <?php else: ?>
                                                    <span class="badge <?php echo $assignment['late_submission'] ? 'badge-warning' : 'badge-success'; ?>">
                                                        <?php echo $assignment['late_submission'] ? 'Late' : 'Submitted'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($assignment['grade'])): ?>
                                                    <strong><?php echo $assignment['grade']; ?></strong>/<?php echo $assignment['total_points']; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Attendance -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-check"></i> Attendance</h2>
                        <span><?php echo $attendance_summary['present_count']; ?>/<?php echo $attendance_summary['total_sessions']; ?> sessions</span>
                    </div>
                    <?php if (empty($attendance)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <p>No attendance records</p>
                        </div>
                    <?php else: ?>
                        <div class="attendance-chart">
                            <?php foreach ($attendance as $record): ?>
                                <div class="attendance-day <?php echo $record['status']; ?>">
                                    <?php echo date('d', strtotime($record['attendance_date'])); ?>
                                    <div class="attendance-tooltip">
                                        <?php echo date('M d, Y', strtotime($record['attendance_date'])); ?><br>
                                        Status: <?php echo ucfirst($record['status']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div class="attendance-day present" style="width: 16px; height: 16px;"></div>
                                <span style="font-size: 0.875rem;">Present</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div class="attendance-day absent" style="width: 16px; height: 16px;"></div>
                                <span style="font-size: 0.875rem;">Absent</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div class="attendance-day late" style="width: 16px; height: 16px;"></div>
                                <span style="font-size: 0.875rem;">Late</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Attendance chart tooltips
        document.querySelectorAll('.attendance-day').forEach(day => {
            day.addEventListener('mouseenter', function(e) {
                const tooltip = this.querySelector('.attendance-tooltip');
                if (tooltip) {
                    const rect = this.getBoundingClientRect();
                    tooltip.style.top = '-40px';
                    tooltip.style.left = '50%';
                    tooltip.style.transform = 'translateX(-50%)';
                }
            });
        });
    </script>
</body>

</html>