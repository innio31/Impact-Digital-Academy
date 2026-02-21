<?php
// modules/admin/academic/classes/view.php

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
$class_id = $_GET['id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class data with comprehensive information
$sql = "SELECT 
    cb.*,
    c.title as course_title,
    c.course_code,
    c.description as course_description,
    c.duration_hours,
    c.level,
    p.name as program_name,
    p.program_code,
    p.program_type,
    p.fee as program_fee,
    u.first_name as instructor_first_name,
    u.last_name as instructor_last_name,
    u.email as instructor_email,
    u.phone as instructor_phone,
    COUNT(DISTINCT e.id) as total_enrollments,
    COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.id END) as active_enrollments,
    COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.id END) as completed_enrollments,
    COUNT(DISTINCT m.id) as total_materials,
    COUNT(DISTINCT a.id) as total_assignments,
    COUNT(DISTINCT asub.id) as total_submissions,
    COUNT(DISTINCT CASE WHEN asub.grade IS NOT NULL THEN asub.id END) as graded_submissions
FROM class_batches cb
JOIN courses c ON cb.course_id = c.id
JOIN programs p ON c.program_id = p.id
LEFT JOIN users u ON cb.instructor_id = u.id
LEFT JOIN enrollments e ON cb.id = e.class_id
LEFT JOIN materials m ON cb.id = m.class_id
LEFT JOIN assignments a ON cb.id = a.class_id
LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id
WHERE cb.id = ?
GROUP BY cb.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();

if (!$class) {
    $_SESSION['error'] = 'Class not found.';
    header('Location: list.php');
    exit();
}

// Fetch enrolled students
$students_sql = "SELECT 
    e.*,
    u.id as user_id,
    u.first_name,
    u.last_name,
    u.email,
    u.phone,
    up.date_of_birth,
    up.gender,
    up.city,
    up.state
FROM enrollments e
JOIN users u ON e.student_id = u.id
LEFT JOIN user_profiles up ON u.id = up.user_id
WHERE e.class_id = ?
ORDER BY e.enrollment_date DESC";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param('i', $class_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class materials
$materials_sql = "SELECT * FROM materials 
                  WHERE class_id = ? 
                  ORDER BY week_number, created_at DESC";
$materials_stmt = $conn->prepare($materials_sql);
$materials_stmt->bind_param('i', $class_id);
$materials_stmt->execute();
$materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class assignments
$assignments_sql = "SELECT a.*, COUNT(asub.id) as submission_count 
                    FROM assignments a
                    LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id
                    WHERE a.class_id = ?
                    GROUP BY a.id
                    ORDER BY a.due_date";
$assignments_stmt = $conn->prepare($assignments_sql);
$assignments_stmt->bind_param('i', $class_id);
$assignments_stmt->execute();
$assignments = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class announcements
$announcements_sql = "SELECT * FROM announcements 
                      WHERE class_id = ? 
                      ORDER BY publish_date DESC 
                      LIMIT 10";
$announcements_stmt = $conn->prepare($announcements_sql);
$announcements_stmt->bind_param('i', $class_id);
$announcements_stmt->execute();
$announcements = $announcements_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate class progress
$class_start = strtotime($class['start_date']);
$class_end = strtotime($class['end_date']);
$today = time();
$class_duration = $class_end - $class_start;
$elapsed = $today - $class_start;
$progress_percentage = $class_duration > 0 ? min(100, max(0, ($elapsed / $class_duration) * 100)) : 0;

// Determine class timeline status
$timeline_status = '';
if ($class['status'] === 'completed') {
    $timeline_status = 'completed';
} elseif ($class['status'] === 'cancelled') {
    $timeline_status = 'cancelled';
} elseif ($progress_percentage >= 100) {
    $timeline_status = 'completed';
} elseif ($progress_percentage > 0) {
    $timeline_status = 'ongoing';
} else {
    $timeline_status = 'scheduled';
}

// Log activity
logActivity($_SESSION['user_id'], 'class_view', "Viewed class #$class_id", 'class_batches', $class_id);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['name']); ?> - Class Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --scheduled: #8b5cf6;
            --ongoing: #10b981;
            --completed: #3b82f6;
            --cancelled: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .class-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .class-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-title h2 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-code {
            font-size: 1.2rem;
            color: #64748b;
            font-weight: 500;
        }

        .class-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }

        .meta-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .status-scheduled {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-onsite {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-online {
            background: #dcfce7;
            color: #166534;
        }

        .class-progress {
            margin-top: 1.5rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .progress-label {
            font-size: 0.9rem;
            color: #64748b;
        }

        .progress-percentage {
            font-weight: 600;
            color: var(--primary);
        }

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .class-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
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
            font-size: 0.85rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-card.enrollments {
            border-top-color: var(--success);
        }

        .stat-card.materials {
            border-top-color: var(--accent);
        }

        .stat-card.assignments {
            border-top-color: var(--primary);
        }

        .stat-card.submissions {
            border-top-color: var(--warning);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

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

        .section-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header i {
            color: var(--primary);
        }

        .section-body {
            padding: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            margin-bottom: 1rem;
        }

        .info-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
        }

        .text-content {
            line-height: 1.6;
            color: var(--dark);
            white-space: pre-wrap;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .instructor-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            margin-bottom: 1rem;
        }

        .instructor-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .instructor-contact {
            font-size: 0.9rem;
            color: #64748b;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.35rem;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 2px solid white;
        }

        .timeline-item.current::before {
            background: var(--primary);
        }

        .timeline-item.completed::before {
            background: var(--success);
        }

        .timeline-item.cancelled::before {
            background: var(--danger);
        }

        .timeline-date {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
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
            background: #e0f2fe;
            color: #0369a1;
        }

        .student-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.875rem;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .student-name {
            font-weight: 500;
            color: var(--dark);
        }

        .student-email {
            font-size: 0.85rem;
            color: #64748b;
        }

        .tab-nav {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            margin-bottom: 2rem;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
        }

        .tab-btn {
            padding: 1rem 1.5rem;
            border: none;
            background: none;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            background: white;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .class-title {
                flex-direction: column;
            }

            .class-actions {
                flex-direction: column;
            }

            .class-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-nav {
                flex-direction: column;
            }

            .tab-btn {
                padding: 0.75rem 1rem;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="active">
                            <i class="fas fa-chalkboard-teacher"></i> Classes</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                            <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">
                            <i class="fas fa-history"></i> Activity Logs</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php">
                            <i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Academic</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php">Classes</a> &rsaquo;
                        Class Details
                    </div>
                    <h1>Class Details</h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
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

            <!-- Class Header -->
            <div class="class-header">
                <div class="class-title">
                    <div>
                        <h2><?php echo htmlspecialchars($class['name']); ?></h2>
                        <div class="class-code">
                            <?php echo htmlspecialchars($class['batch_code']); ?>
                            <span class="program-badge badge-<?php echo $class['program_type']; ?>" style="margin-left: 1rem;">
                                <?php echo ucfirst($class['program_type']); ?> Program
                            </span>
                            <span class="status-badge status-<?php echo $class['status']; ?>" style="margin-left: 0.5rem;">
                                <?php echo ucfirst($class['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="class-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class_id; ?>"
                            class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Class
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/home.php"
                            class="btn btn-success" target="_blank">
                            <i class="fas fa-chalkboard-teacher"></i> Instructor View
                        </a>
                        <?php if ($class['meeting_link']): ?>
                            <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>"
                                class="btn btn-warning" target="_blank">
                                <i class="fas fa-video"></i> Join Class
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="class-meta">
                    <div class="meta-item">
                        <div class="meta-label">Course</div>
                        <div class="meta-value"><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Program</div>
                        <div class="meta-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Instructor</div>
                        <div class="meta-value">
                            <?php if ($class['instructor_first_name']): ?>
                                <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                            <?php else: ?>
                                <span style="color: #64748b; font-style: italic;">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Duration</div>
                        <div class="meta-value"><?php echo $class['duration_hours']; ?> hours</div>
                    </div>
                </div>

                <?php if ($class['description']): ?>
                    <div style="margin-top: 1.5rem;">
                        <div class="info-label">Class Description</div>
                        <div class="text-content" style="margin-top: 0.5rem;">
                            <?php echo nl2br(htmlspecialchars($class['description'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Class Progress -->
                <div class="class-progress">
                    <div class="progress-header">
                        <div class="progress-label">Class Timeline</div>
                        <div class="progress-percentage"><?php echo round($progress_percentage); ?>%</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #64748b; margin-top: 0.5rem;">
                        <div>
                            <i class="far fa-calendar-alt"></i>
                            <?php echo date('M j, Y', strtotime($class['start_date'])); ?>
                        </div>
                        <div>
                            <i class="far fa-calendar-alt"></i>
                            <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $class['total_enrollments']; ?></div>
                    <div class="stat-label">Total Enrollments</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo $class['active_enrollments']; ?> active
                    </div>
                </div>
                <div class="stat-card enrollments">
                    <div class="stat-number"><?php echo $class['max_students']; ?></div>
                    <div class="stat-label">Max Capacity</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo round(($class['active_enrollments'] / $class['max_students']) * 100); ?>% filled
                    </div>
                </div>
                <div class="stat-card materials">
                    <div class="stat-number"><?php echo $class['total_materials']; ?></div>
                    <div class="stat-label">Materials</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        Course resources
                    </div>
                </div>
                <div class="stat-card assignments">
                    <div class="stat-number"><?php echo $class['total_assignments']; ?></div>
                    <div class="stat-label">Assignments</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo $class['graded_submissions']; ?> graded
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button type="button" class="tab-btn active" onclick="showTab('overview')">
                    <i class="fas fa-chalkboard"></i> Overview
                </button>
                <button type="button" class="tab-btn" onclick="showTab('students')">
                    <i class="fas fa-users"></i> Students (<?php echo $class['total_enrollments']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('materials')">
                    <i class="fas fa-book"></i> Materials (<?php echo $class['total_materials']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('assignments')">
                    <i class="fas fa-tasks"></i> Assignments (<?php echo $class['total_assignments']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('timeline')">
                    <i class="fas fa-history"></i> Timeline
                </button>
            </div>

            <!-- Overview Tab -->
            <div id="overview-tab" class="tab-content active">
                <div class="content-grid">
                    <!-- Left Column: Course & Instructor Details -->
                    <div>
                        <!-- Course Information -->
                        <div class="section-card" style="margin-bottom: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-graduation-cap"></i> Course Information</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Course Code</div>
                                        <div class="info-value"><?php echo htmlspecialchars($class['course_code']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Course Title</div>
                                        <div class="info-value"><?php echo htmlspecialchars($class['course_title']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Program</div>
                                        <div class="info-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Program Type</div>
                                        <div class="info-value">
                                            <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                                <?php echo ucfirst($class['program_type']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Course Level</div>
                                        <div class="info-value"><?php echo ucfirst($class['level']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Duration</div>
                                        <div class="info-value"><?php echo $class['duration_hours']; ?> hours</div>
                                    </div>
                                </div>

                                <?php if ($class['course_description']): ?>
                                    <div style="margin-top: 1.5rem;">
                                        <div class="info-label">Course Description</div>
                                        <div class="text-content" style="margin-top: 0.5rem;">
                                            <?php echo nl2br(htmlspecialchars($class['course_description'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Schedule Information -->
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-calendar-alt"></i> Schedule & Meeting</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Class Schedule</div>
                                        <div class="info-value">
                                            <?php echo $class['schedule'] ? nl2br(htmlspecialchars($class['schedule'])) : 'Not specified'; ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Meeting Link</div>
                                        <div class="info-value">
                                            <?php if ($class['meeting_link']): ?>
                                                <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>"
                                                    target="_blank" style="color: var(--primary); text-decoration: none;">
                                                    Join Class Meeting
                                                    <i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-left: 0.25rem;"></i>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-style: italic;">Not provided</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Class Dates</div>
                                        <div class="info-value">
                                            <?php echo date('M j, Y', strtotime($class['start_date'])); ?> -
                                            <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Days Remaining</div>
                                        <div class="info-value">
                                            <?php
                                            $end_date = strtotime($class['end_date']);
                                            $today = time();
                                            $days_remaining = ceil(($end_date - $today) / (60 * 60 * 24));
                                            echo $days_remaining > 0 ? $days_remaining . ' days' : 'Completed';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Instructor & Quick Actions -->
                    <div>
                        <!-- Instructor Information -->
                        <?php if ($class['instructor_first_name']): ?>
                            <div class="section-card" style="margin-bottom: 1.5rem;">
                                <div class="section-header">
                                    <h3><i class="fas fa-user-tie"></i> Instructor Information</h3>
                                </div>
                                <div class="section-body">
                                    <div class="instructor-card">
                                        <div class="instructor-name">
                                            <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                                        </div>
                                        <div class="instructor-contact">
                                            <i class="fas fa-envelope"></i>
                                            <?php echo htmlspecialchars($class['instructor_email']); ?>
                                        </div>
                                        <?php if ($class['instructor_phone']): ?>
                                            <div class="instructor-contact">
                                                <i class="fas fa-phone"></i>
                                                <?php echo htmlspecialchars($class['instructor_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                        <a href="mailto:<?php echo urlencode($class['instructor_email']); ?>"
                                            class="btn btn-secondary btn-sm">
                                            <i class="fas fa-envelope"></i> Email Instructor
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $class['instructor_id']; ?>"
                                            class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i> View Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Quick Actions -->
                        <div class="section-card" style="margin-bottom: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                            </div>
                            <div class="section-body">
                                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class_id; ?>"
                                        class="btn btn-primary" style="justify-content: center;">
                                        <i class="fas fa-edit"></i> Edit Class Details
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>"
                                        class="btn btn-success" style="justify-content: center;">
                                        <i class="fas fa-user-plus"></i> Enroll New Student
                                    </a>
                                    <?php if ($class['status'] !== 'completed' && $class['status'] !== 'cancelled'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="mark_completed">
                                            <button type="submit" class="btn btn-warning" style="width: 100%; justify-content: center;"
                                                onclick="return confirm('Mark this class as completed? This action cannot be undone.')">
                                                <i class="fas fa-check-circle"></i> Mark as Completed
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($class['status'] !== 'cancelled'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="cancel_class">
                                            <button type="submit" class="btn btn-danger" style="width: 100%; justify-content: center;"
                                                onclick="return confirm('Cancel this class? All enrolled students will be notified.')">
                                                <i class="fas fa-times-circle"></i> Cancel Class
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="#" onclick="window.print()" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-print"></i> Print Class Details
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Class Stats -->
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-chart-bar"></i> Class Statistics</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Enrollment Rate</div>
                                        <div class="info-value">
                                            <?php echo round(($class['active_enrollments'] / $class['max_students']) * 100); ?>%
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Assignment Rate</div>
                                        <div class="info-value">
                                            <?php echo $class['total_assignments'] > 0 ? round(($class['graded_submissions'] / $class['total_submissions']) * 100) : 0; ?>%
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Completion Rate</div>
                                        <div class="info-value">
                                            <?php echo $class['total_enrollments'] > 0 ? round(($class['completed_enrollments'] / $class['total_enrollments']) * 100) : 0; ?>%
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Class Progress</div>
                                        <div class="info-value"><?php echo round($progress_percentage); ?>%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Tab -->
            <div id="students-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-users"></i> Enrolled Students (<?php echo $class['total_enrollments']; ?>)</h3>
                        <div>
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>"
                                class="btn btn-primary btn-sm">
                                <i class="fas fa-user-plus"></i> Enroll Student
                            </a>
                        </div>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($students)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Contact</th>
                                            <th>Enrollment Date</th>
                                            <th>Status</th>
                                            <th>Completion</th>
                                            <th>Grade</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student):
                                            $initials = strtoupper(
                                                substr($student['first_name'], 0, 1) .
                                                    substr($student['last_name'], 0, 1)
                                            );
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="student-cell">
                                                        <div class="student-avatar">
                                                            <?php echo $initials; ?>
                                                        </div>
                                                        <div>
                                                            <div class="student-name">
                                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                            </div>
                                                            <?php if ($student['gender']): ?>
                                                                <div style="font-size: 0.8rem; color: #64748b;">
                                                                    <?php echo ucfirst($student['gender']); ?>
                                                                    <?php if ($student['date_of_birth']): ?>
                                                                        • <?php echo date('Y') - date('Y', strtotime($student['date_of_birth'])); ?> years
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                                    <?php if ($student['phone']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b;">
                                                            <?php echo htmlspecialchars($student['phone']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    if ($student['status'] === 'active') $status_class = 'badge-success';
                                                    elseif ($student['status'] === 'completed') $status_class = 'badge-info';
                                                    elseif ($student['status'] === 'dropped') $status_class = 'badge-danger';
                                                    else $status_class = 'badge-warning';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($student['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($student['completion_date']): ?>
                                                        <?php echo date('M j, Y', strtotime($student['completion_date'])); ?>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">In progress</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($student['final_grade']): ?>
                                                        <span style="font-weight: bold; color: var(--success);">
                                                            <?php echo $student['final_grade']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $student['user_id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i> Profile
                                                    </a>
                                                    <a href="mailto:<?php echo urlencode($student['email']); ?>"
                                                        class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-envelope"></i> Email
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h3>No Students Enrolled</h3>
                                <p>There are no students enrolled in this class yet.</p>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>"
                                    class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-user-plus"></i> Enroll First Student
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Materials Tab -->
            <div id="materials-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-book"></i> Course Materials (<?php echo $class['total_materials']; ?>)</h3>
                        <div>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/upload.php"
                                class="btn btn-primary btn-sm" target="_blank">
                                <i class="fas fa-upload"></i> Upload Material
                            </a>
                        </div>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($materials)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>Week</th>
                                            <th>Topic</th>
                                            <th>Downloads</th>
                                            <th>Published</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($materials as $material): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($material['title']); ?></strong>
                                                    <?php if ($material['description']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($material['description'], 0, 100)); ?>...
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo ucfirst($material['file_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $material['week_number'] ? 'Week ' . $material['week_number'] : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $material['topic'] ? htmlspecialchars($material['topic']) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $material['downloads_count']; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($material['publish_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php if ($material['file_url']): ?>
                                                        <a href="<?php echo BASE_URL . 'public/uploads/' . $material['file_url']; ?>"
                                                            class="btn btn-primary btn-sm" target="_blank">
                                                            <i class="fas fa-download"></i> Download
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/manage.php"
                                                        class="btn btn-secondary btn-sm" target="_blank">
                                                        <i class="fas fa-cog"></i> Manage
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <h3>No Course Materials</h3>
                                <p>No course materials have been uploaded for this class yet.</p>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/upload.php"
                                    class="btn btn-primary" style="margin-top: 1rem;" target="_blank">
                                    <i class="fas fa-upload"></i> Upload First Material
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Assignments Tab -->
            <div id="assignments-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-tasks"></i> Assignments (<?php echo $class['total_assignments']; ?>)</h3>
                        <div>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/create.php"
                                class="btn btn-primary btn-sm" target="_blank">
                                <i class="fas fa-plus"></i> Create Assignment
                            </a>
                        </div>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($assignments)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Due Date</th>
                                            <th>Points</th>
                                            <th>Submissions</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                                    <?php if ($assignment['description']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($assignment['description'], 0, 100)); ?>...
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                                    <?php if (strtotime($assignment['due_date']) < time()): ?>
                                                        <div class="badge badge-danger" style="margin-top: 0.25rem;">Overdue</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $assignment['total_points']; ?> pts
                                                </td>
                                                <td>
                                                    <?php echo $assignment['submission_count']; ?> / <?php echo $class['active_enrollments']; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo ucfirst(str_replace('_', ' ', $assignment['submission_type'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($assignment['is_published']): ?>
                                                        <span class="badge badge-success">Published</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/submissions.php?assignment_id=<?php echo $assignment['id']; ?>"
                                                        class="btn btn-primary btn-sm" target="_blank">
                                                        <i class="fas fa-eye"></i> Submissions
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/grade.php?assignment_id=<?php echo $assignment['id']; ?>"
                                                        class="btn btn-success btn-sm" target="_blank">
                                                        <i class="fas fa-check"></i> Grade
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <h3>No Assignments</h3>
                                <p>No assignments have been created for this class yet.</p>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/create.php"
                                    class="btn btn-primary" style="margin-top: 1rem;" target="_blank">
                                    <i class="fas fa-plus"></i> Create First Assignment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Timeline Tab -->
            <div id="timeline-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-history"></i> Class Timeline</h3>
                    </div>
                    <div class="section-body">
                        <div class="timeline">
                            <div class="timeline-item <?php echo $timeline_status === 'scheduled' ? 'current' : ($timeline_status === 'completed' ? 'completed' : ''); ?>">
                                <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['start_date'])); ?></div>
                                <div class="timeline-content">
                                    <strong>Class Scheduled</strong>
                                    <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                        Class was scheduled to start on this date.
                                    </p>
                                </div>
                            </div>

                            <?php if ($class['status'] === 'ongoing' || $timeline_status === 'ongoing'): ?>
                                <div class="timeline-item current">
                                    <div class="timeline-date">Currently</div>
                                    <div class="timeline-content">
                                        <strong>Class in Progress</strong>
                                        <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                            Class is currently ongoing. <?php echo round($progress_percentage); ?>% completed.
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($class['status'] === 'completed' || $timeline_status === 'completed'): ?>
                                <div class="timeline-item completed">
                                    <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['end_date'])); ?></div>
                                    <div class="timeline-content">
                                        <strong>Class Completed</strong>
                                        <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                            Class was completed on this date.
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($class['status'] === 'cancelled'): ?>
                                <div class="timeline-item cancelled">
                                    <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['updated_at'])); ?></div>
                                    <div class="timeline-content">
                                        <strong>Class Cancelled</strong>
                                        <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                            Class was cancelled by administrator.
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <?php if (!empty($announcements)): ?>
                    <div class="section-card" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                        </div>
                        <div class="section-body">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($announcements as $announcement): ?>
                                    <div class="instructor-card" style="margin-bottom: 1rem;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                                <?php if ($announcement['priority'] === 'high'): ?>
                                                    <span class="badge badge-danger" style="margin-left: 0.5rem;">High Priority</span>
                                                <?php elseif ($announcement['priority'] === 'medium'): ?>
                                                    <span class="badge badge-warning" style="margin-left: 0.5rem;">Medium Priority</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: #64748b;">
                                                <?php echo date('M j, Y', strtotime($announcement['publish_date'])); ?>
                                            </div>
                                        </div>
                                        <?php if ($announcement['content']): ?>
                                            <div style="margin-top: 0.5rem; font-size: 0.9rem; color: #64748b;">
                                                <?php echo htmlspecialchars(substr($announcement['content'], 0, 150)); ?>
                                                <?php if (strlen($announcement['content']) > 150): ?>...<?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="text-align: center; margin-top: 1rem;">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/announcements/"
                                    class="btn btn-secondary btn-sm" target="_blank">
                                    <i class="fas fa-bullhorn"></i> View All Announcements
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Tab navigation functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Mark selected tab button as active
            event.currentTarget.classList.add('active');

            // Store active tab in sessionStorage
            sessionStorage.setItem('activeClassTab', tabName);
        }

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem('activeClassTab') || 'overview';
            if (document.getElementById(activeTab + '-tab')) {
                showTab(activeTab);

                // Update tab button active state
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    if (btn.textContent.includes(getTabNameFromId(activeTab))) {
                        btn.classList.add('active');
                    }
                });
            }

            // Animate progress bar
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) {
                const width = progressFill.style.width;
                progressFill.style.width = '0%';
                setTimeout(() => {
                    progressFill.style.width = width;
                }, 100);
            }

            // Handle form submissions (for mark completed/cancel class)
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (this.querySelector('input[name="action"]')) {
                        const action = this.querySelector('input[name="action"]').value;
                        let confirmed = false;

                        if (action === 'mark_completed') {
                            confirmed = confirm('Are you sure you want to mark this class as completed?\n\nThis will: \n• Close enrollment\n• Lock all assignments\n• Generate final grades\n\nThis action cannot be undone.');
                        } else if (action === 'cancel_class') {
                            confirmed = confirm('Are you sure you want to cancel this class?\n\nThis will: \n• Cancel all enrollments\n• Notify all students\n• Remove class from active listings\n\nThis action cannot be undone.');
                        }

                        if (!confirmed) {
                            e.preventDefault();
                        }
                    }
                });
            });

            // Add download functionality for class report
            const printBtn = document.querySelector('[onclick*="print"]');
            if (printBtn) {
                printBtn.addEventListener('click', function(e) {
                    e.preventDefault();

                    // Store current active tab
                    const currentTab = sessionStorage.getItem('activeClassTab');

                    // Switch to overview tab for printing
                    showTab('overview');

                    // Wait a moment for tab switch to complete
                    setTimeout(() => {
                        window.print();

                        // Restore previous tab after printing
                        if (currentTab) {
                            setTimeout(() => {
                                showTab(currentTab);
                            }, 100);
                        }
                    }, 500);
                });
            }
        });

        function getTabNameFromId(id) {
            const tabNames = {
                'overview': 'Overview',
                'students': 'Students',
                'materials': 'Materials',
                'assignments': 'Assignments',
                'timeline': 'Timeline'
            };
            return tabNames[id] || 'Overview';
        }

        // Handle bulk actions for students
        function bulkAction(action) {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one student.');
                return false;
            }

            const studentIds = Array.from(checkboxes).map(cb => cb.value);
            let confirmMessage = '';

            switch (action) {
                case 'email':
                    // Get emails for selected students
                    const emails = Array.from(checkboxes).map(cb =>
                        cb.closest('tr').querySelector('.student-email').textContent.trim()
                    );

                    // Open email client
                    window.location.href = `mailto:?bcc=${emails.join(',')}&subject=Regarding%20Class%20${encodeURIComponent($class['batch_code'])}`;
                    return true;

                case 'export':
                    confirmMessage = `Export data for ${studentIds.length} selected student(s)?`;
                    break;

                case 'certificates':
                    confirmMessage = `Generate certificates for ${studentIds.length} selected student(s)?`;
                    break;
            }

            if (confirmMessage && !confirm(confirmMessage)) {
                return false;
            }

            // Submit form for server-side processing
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $class_id; ?>';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo generateCSRFToken(); ?>';
            form.appendChild(csrfInput);

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_action';
            actionInput.value = action;
            form.appendChild(actionInput);

            studentIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();

            return false;
        }

        // Search functionality for students table
        function searchStudents() {
            const searchTerm = document.getElementById('studentSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#students-tab tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        // Sort functionality for tables
        function sortTable(tableId, columnIndex) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const isAscending = table.getAttribute('data-sort-dir') !== 'asc';

            rows.sort((a, b) => {
                const aText = a.children[columnIndex].textContent.trim();
                const bText = b.children[columnIndex].textContent.trim();

                // Try to parse as numbers
                const aNum = parseFloat(aText);
                const bNum = parseFloat(bText);

                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAscending ? aNum - bNum : bNum - aNum;
                }

                // Compare as strings
                return isAscending ?
                    aText.localeCompare(bText) :
                    bText.localeCompare(aText);
            });

            // Clear and re-add sorted rows
            rows.forEach(row => tbody.appendChild(row));

            // Update sort indicator
            table.setAttribute('data-sort-dir', isAscending ? 'asc' : 'desc');
        }

        // Toggle student selection
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>