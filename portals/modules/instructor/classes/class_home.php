<?php
// modules/instructor/classes/class_home.php

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
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.description as course_description,
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
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

// Get class statistics
$stats = [
    'students' => 0,
    'assignments' => 0,
    'materials' => 0,
    'announcements' => 0,
    'pending_grading' => 0
];

// Student count
$sql = "SELECT COUNT(*) as count FROM enrollments WHERE class_id = ? AND status = 'active'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['students'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Assignment count
$sql = "SELECT COUNT(*) as count FROM assignments WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['assignments'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Material count
$sql = "SELECT COUNT(*) as count FROM materials WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['materials'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Announcement count
$sql = "SELECT COUNT(*) as count FROM announcements WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['announcements'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Pending grading count
$sql = "SELECT COUNT(DISTINCT a.id) as count 
        FROM assignments a 
        JOIN assignment_submissions s ON a.id = s.assignment_id 
        WHERE a.class_id = ? AND s.status = 'submitted' AND s.grade IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['pending_grading'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Get recent students (for display)
$recent_students = [];
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.profile_image, e.enrollment_date
        FROM enrollments e 
        JOIN users u ON e.student_id = u.id 
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY e.enrollment_date DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get upcoming assignments (due in next 7 days)
$upcoming_assignments = [];
$sql = "SELECT a.*, COUNT(s.id) as submission_count
        FROM assignments a 
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id
        WHERE a.class_id = ? AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        GROUP BY a.id 
        ORDER BY a.due_date ASC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$upcoming_assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent announcements
$recent_announcements = [];
$sql = "SELECT * FROM announcements 
        WHERE class_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_announcements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent submissions (last 24 hours)
$recent_submissions = [];
$sql = "SELECT s.*, a.title as assignment_title, u.first_name, u.last_name
        FROM assignment_submissions s 
        JOIN assignments a ON s.assignment_id = a.id 
        JOIN users u ON s.student_id = u.id 
        WHERE a.class_id = ? AND s.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY s.submitted_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_submissions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();



// Log activity
logActivity('enter_class', "Entered class: {$class['batch_code']}", 'class_batches', $class_id);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Class Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--primary);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #e5e7eb;
            color: #374151;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.students {
            border-top-color: var(--success);
        }

        .stat-card.assignments {
            border-top-color: var(--warning);
        }

        .stat-card.materials {
            border-top-color: var(--info);
        }

        .stat-card.pending {
            border-top-color: var(--danger);
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

        .stat-link {
            margin-top: 0.5rem;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-link a:hover {
            text-decoration: underline;
        }

        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
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

        .card-header h2 i {
            color: var(--primary);
        }

        /* Activity Lists */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.3s ease;
        }

        .activity-item:hover {
            background: #f8fafc;
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

        .activity-assignment {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .activity-student {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .activity-announcement {
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
            display: flex;
            gap: 1rem;
        }

        /* Upcoming Assignments */
        .assignment-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .assignment-item:last-child {
            border-bottom: none;
        }

        .assignment-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .assignment-meta {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .due-date {
            color: var(--danger);
            font-weight: 500;
        }

        .submission-count {
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
        }

        /* Announcements */
        .announcement-item {
            padding: 1rem;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.05);
            margin-bottom: 1rem;
        }

        .announcement-item:last-child {
            margin-bottom: 0;
        }

        .announcement-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .announcement-date {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .announcement-content {
            font-size: 0.875rem;
            color: var(--gray);
            line-height: 1.5;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
            text-align: center;
        }

        .action-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            background: #f8fafc;
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        .action-label {
            font-size: 0.875rem;
            font-weight: 600;
        }

        /* Student List */
        .student-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .student-info h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }

        .student-info p {
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
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

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
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

        /* Class Details */
        .class-details {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .detail-item {
            margin-bottom: 1rem;
        }

        .detail-label {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
        }

        .detail-value a {
            color: var(--primary);
            text-decoration: none;
        }

        .detail-value a:hover {
            text-decoration: underline;
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
            <span><?php echo htmlspecialchars($class['batch_code']); ?></span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['name']); ?></p>
                </div>
                <span class="status-badge status-<?php echo $class['status']; ?>">
                    <?php echo ucfirst($class['status']); ?>
                </span>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link active">
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
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-value"><?php echo $stats['students']; ?></div>
                <div class="stat-label">Students Enrolled</div>
                <div class="stat-link">
                    <a href="students.php?class_id=<?php echo $class_id; ?>">
                        View Students <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="stat-card assignments">
                <div class="stat-value"><?php echo $stats['assignments']; ?></div>
                <div class="stat-label">Assignments</div>
                <div class="stat-link">
                    <a href="assignments.php?class_id=<?php echo $class_id; ?>">
                        Manage Assignments <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="stat-card materials">
                <div class="stat-value"><?php echo $stats['materials']; ?></div>
                <div class="stat-label">Teaching Materials</div>
                <div class="stat-link">
                    <a href="materials.php?class_id=<?php echo $class_id; ?>">
                        View Materials <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $stats['pending_grading']; ?></div>
                <div class="stat-label">Pending Grading</div>
                <div class="stat-link">
                    <a href="assignments.php?class_id=<?php echo $class_id; ?>&filter=pending">
                        Grade Now <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Class Details -->
        <div class="class-details">
            <h2 style="margin-bottom: 1rem; color: var(--dark);">
                <i class="fas fa-info-circle"></i> Class Information
            </h2>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Course</div>
                    <div class="detail-value">
                        <?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Program</div>
                    <div class="detail-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Instructor</div>
                    <div class="detail-value"><?php echo htmlspecialchars($class['instructor_name']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Schedule</div>
                    <div class="detail-value"><?php echo htmlspecialchars($class['schedule'] ?? 'Not specified'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Class Period</div>
                    <div class="detail-value">
                        <?php echo date('M d, Y', strtotime($class['start_date'])); ?> -
                        <?php echo date('M d, Y', strtotime($class['end_date'])); ?>
                    </div>
                </div>
                <?php if (!empty($class['meeting_link'])): ?>
                    <div class="detail-item">
                        <div class="detail-label">Meeting Link</div>
                        <div class="detail-value">
                            <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank">
                                <i class="fas fa-video"></i> Join Online Class
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($class['description'])): ?>
                <div class="detail-item" style="margin-top: 1.5rem;">
                    <div class="detail-label">Description</div>
                    <div class="detail-value" style="line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($class['description'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Recent Submissions -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-paper-plane"></i> Recent Submissions</h2>
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>&filter=submissions" class="btn btn-primary">
                            <i class="fas fa-list"></i> View All
                        </a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_submissions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No recent submissions</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_submissions as $submission): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-submission">
                                        <i class="fas fa-file-upload"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>
                                        </div>
                                        <div class="activity-description">
                                            Submitted: <?php echo htmlspecialchars($submission['assignment_title']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo time_ago($submission['submitted_at']); ?></span>
                                            <?php if ($submission['grade']): ?>
                                                <span><i class="fas fa-check-circle" style="color: var(--success);"></i> Graded</span>
                                            <?php else: ?>
                                                <span><i class="fas fa-clock" style="color: var(--warning);"></i> Needs grading</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Assignments -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-alt"></i> Upcoming Assignments</h2>
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>&filter=upcoming" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($upcoming_assignments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <p>No upcoming assignments</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_assignments as $assignment): ?>
                                <div class="assignment-item">
                                    <div class="assignment-title">
                                        <?php echo htmlspecialchars($assignment['title']); ?>
                                    </div>
                                    <div class="assignment-meta">
                                        <span><?php echo $assignment['total_points']; ?> points</span>
                                        <span class="due-date">
                                            Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                        </span>
                                        <span class="submission-count">
                                            <?php echo $assignment['submission_count']; ?> submissions
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>
                    <div class="quick-actions">
                        <a href="materials.php?class_id=<?php echo $class_id; ?>&action=upload" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div class="action-label">Upload Material</div>
                        </a>
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>&action=create" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="action-label">New Assignment</div>
                        </a>
                        <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=create" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="action-label">Post Announcement</div>
                        </a>
                        <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="action-label">View Gradebook</div>
                        </a>
                    </div>
                </div>

                <!-- Recent Students -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-graduate"></i> Recent Students</h2>
                        <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                            <i class="fas fa-users"></i> All Students
                        </a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_students)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate"></i>
                                <p>No students enrolled</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_students as $student): ?>
                                <div class="student-item">
                                    <div class="student-avatar">
                                        <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="student-info">
                                        <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($student['email']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Announcements -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-bullhorn"></i> Recent Announcements</h2>
                        <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                            <i class="fas fa-list"></i> All
                        </a>
                    </div>
                    <div>
                        <?php if (empty($recent_announcements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <p>No announcements yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_announcements as $announcement): ?>
                                <div class="announcement-item">
                                    <div class="announcement-title">
                                        <span><?php echo htmlspecialchars($announcement['title']); ?></span>
                                        <span class="announcement-date">
                                            <?php echo date('M d', strtotime($announcement['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="announcement-content">
                                        <?php echo htmlspecialchars(substr($announcement['content'], 0, 100)); ?>
                                        <?php if (strlen($announcement['content']) > 100): ?>...<?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update time ago every minute
        function updateTimeAgo() {
            document.querySelectorAll('.activity-meta span:first-child').forEach(el => {
                // This is a simplified version - in production, use a proper time ago library
                const timeText = el.textContent;
                // You could implement more sophisticated time updating here
            });
        }

        // Update every minute
        setInterval(updateTimeAgo, 60000);

        // Copy meeting link to clipboard
        function copyMeetingLink() {
            const link = "<?php echo htmlspecialchars($class['meeting_link'] ?? ''); ?>";
            if (link) {
                navigator.clipboard.writeText(link)
                    .then(() => {
                        // Show success message
                        const message = document.createElement('div');
                        message.style.cssText = `
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            background: var(--success);
                            color: white;
                            padding: 1rem 1.5rem;
                            border-radius: 8px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                            z-index: 1000;
                            animation: slideIn 0.3s ease;
                        `;
                        message.innerHTML = '<i class="fas fa-check-circle"></i> Meeting link copied to clipboard!';
                        document.body.appendChild(message);
                        setTimeout(() => message.remove(), 3000);
                    })
                    .catch(err => console.error('Failed to copy:', err));
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + M to copy meeting link
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                copyMeetingLink();
            }

            // Esc to go back
            if (e.key === 'Escape') {
                window.history.back();
            }
        });

        // Auto-refresh page every 5 minutes for updates
        setInterval(() => {
            // Optional: Implement partial page refresh or notification check
            console.log('Class dashboard auto-refresh check at:', new Date().toLocaleTimeString());
        }, 5 * 60 * 1000);
    </script>
</body>

</html>