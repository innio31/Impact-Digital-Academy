<?php
// modules/admin/academic/courses/view.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get course ID from URL
$course_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$course_id) {
    $_SESSION['error'] = 'Course ID is required';
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Fetch course details
$sql = "SELECT c.*, 
               p.name as program_name,
               p.program_code,
               p.id as program_id,
               COUNT(DISTINCT cb.id) as class_count,
               COUNT(DISTINCT e.id) as student_count,
               u.first_name as creator_first_name,
               u.last_name as creator_last_name
        FROM courses c
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN class_batches cb ON c.id = cb.course_id AND cb.status = 'ongoing'
        LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
        LEFT JOIN users u ON c.created_by = u.id
        WHERE c.id = ?
        GROUP BY c.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
$course = $result->fetch_assoc();

if (!$course) {
    $_SESSION['error'] = 'Course not found';
    header('Location: index.php');
    exit();
}

// Fetch related classes
$classes_sql = "SELECT cb.*, 
                       u.first_name as instructor_first_name,
                       u.last_name as instructor_last_name,
                       COUNT(DISTINCT e.id) as student_count
                FROM class_batches cb
                LEFT JOIN users u ON cb.instructor_id = u.id
                LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
                WHERE cb.course_id = ?
                GROUP BY cb.id
                ORDER BY cb.start_date DESC
                LIMIT 5";

$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $course_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total classes count
$total_classes_sql = "SELECT COUNT(*) as total FROM class_batches WHERE course_id = ?";
$total_classes_stmt = $conn->prepare($total_classes_sql);
$total_classes_stmt->bind_param("i", $course_id);
$total_classes_stmt->execute();
$total_classes = $total_classes_stmt->get_result()->fetch_assoc()['total'];

// Log activity
logActivity('course_view', "Viewed course: {$course['course_code']}", 'courses', $course_id);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($course['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --gray-lighter: #e2e8f0;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            min-height: 100vh;
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
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
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

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            border: 2px solid transparent;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .course-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .course-program {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            background: rgba(37, 99, 235, 0.1);
            border-radius: 30px;
            width: fit-content;
        }

        .course-program a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .course-program i {
            font-size: 0.8rem;
            color: var(--primary);
        }

        .course-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
            background: rgba(37, 99, 235, 0.05);
            padding: 0.3rem 1rem;
            border-radius: 30px;
            display: inline-block;
        }

        .course-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
            word-break: break-word;
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .course-description {
            color: var(--gray);
            line-height: 1.7;
            font-size: 1rem;
            padding: 1rem 0;
            border-top: 2px dashed var(--gray-lighter);
            border-bottom: 2px dashed var(--gray-lighter);
            margin: 1rem 0;
        }

        .page-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.7rem 1.2rem;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            flex: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            color: var(--white);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: var(--white);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: var(--white);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: 20px;
            padding: 1.25rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-lighter);
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .stat-card.hours {
            border-top: 4px solid var(--secondary);
        }

        .stat-card.classes {
            border-top: 4px solid var(--info);
        }

        .stat-card.students {
            border-top: 4px solid var(--success);
        }

        .stat-card.order {
            border-top: 4px solid var(--warning);
        }

        .stat-icon {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Section Cards */
        .section-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-lighter);
        }

        .section-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 i {
            font-size: 1rem;
        }

        .section-content {
            padding: 1.25rem;
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0.75rem;
            background: var(--light);
            border-radius: 12px;
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--dark);
            font-weight: 500;
        }

        .level-badge {
            display: inline-block;
            padding: 0.2rem 0.8rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .level-beginner {
            background: rgba(59, 130, 246, 0.15);
            color: var(--info);
        }

        .level-intermediate {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .level-advanced {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        /* Classes List */
        .classes-list {
            list-style: none;
            margin-bottom: 1rem;
        }

        .class-item {
            padding: 1rem;
            border: 2px solid var(--gray-lighter);
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .class-item:hover {
            border-color: var(--primary);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }

        .class-header {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .class-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.85rem;
            background: rgba(37, 99, 235, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }

        .class-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-ongoing {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .status-scheduled {
            background: rgba(59, 130, 246, 0.15);
            color: var(--info);
        }

        .status-completed {
            background: rgba(100, 116, 139, 0.15);
            color: var(--gray);
        }

        .status-cancelled {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .class-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .class-details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.5rem;
            color: var(--gray);
            font-size: 0.8rem;
        }

        .class-details div {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .class-details i {
            width: 16px;
            color: var(--primary);
        }

        /* Quick Actions Grid */
        .quick-actions-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: 12px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s ease;
            border: 2px solid var(--gray-lighter);
        }

        .quick-action-btn i {
            width: 24px;
            font-size: 1.1rem;
            color: var(--primary);
        }

        .quick-action-btn:hover {
            border-color: var(--primary);
            transform: translateX(5px);
            background: rgba(37, 99, 235, 0.05);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            opacity: 0.5;
            color: var(--primary);
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 1.1rem;
        }

        .no-data p {
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        /* View All Link */
        .view-all {
            text-align: center;
            padding: 1rem 0 0;
            border-top: 2px dashed var(--gray-lighter);
            margin-top: 0.5rem;
        }

        .view-all a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-all a:hover {
            gap: 0.75rem;
        }

        /* Tablet Breakpoint */
        @media (min-width: 640px) {
            .container {
                padding: 1.5rem;
            }

            .page-header {
                padding: 2rem;
            }

            .course-title {
                font-size: 2.2rem;
            }

            .page-actions {
                flex-direction: row;
            }

            .btn {
                flex: none;
            }

            .stats-cards {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }

            .detail-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .class-details {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Desktop Breakpoint */
        @media (min-width: 1024px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
                gap: 2rem;
            }

            .course-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }

            .section-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .detail-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .class-item,
            .quick-action-btn {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .class-item:active,
            .quick-action-btn:active {
                transform: scale(0.98);
            }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                transform: translateX(-20px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .course-card {
            animation: fadeInUp 0.5s ease;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/">Courses</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($course['course_code']); ?></span>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($_SESSION['success']); ?></div>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="course-header">
                <div>
                    <div class="course-program">
                        <i class="fas fa-layer-group"></i>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $course['program_id']; ?>">
                            <?php echo htmlspecialchars($course['program_code'] . ' - ' . $course['program_name']); ?>
                        </a>
                    </div>
                    <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                </div>
                <span class="status-badge status-<?php echo $course['status']; ?>">
                    <?php echo ucfirst($course['status']); ?>
                </span>
            </div>

            <h1 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h1>

            <div class="course-description">
                <?php echo nl2br(htmlspecialchars($course['description'])); ?>
            </div>

            <div class="page-actions">
                <div class="action-row">
                    <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
                <div class="action-row">
                    <?php if ($course['status'] === 'active'): ?>
                        <a href="?action=deactivate&id=<?php echo $course['id']; ?>"
                            class="btn btn-warning"
                            onclick="return confirmDeactivation()">
                            <i class="fas fa-pause-circle"></i> Deactivate
                        </a>
                    <?php elseif ($course['status'] === 'inactive'): ?>
                        <a href="?action=activate&id=<?php echo $course['id']; ?>"
                            class="btn btn-success"
                            onclick="return confirmActivation()">
                            <i class="fas fa-play-circle"></i> Activate
                        </a>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?php echo $course['id']; ?>"
                        class="btn btn-danger"
                        onclick="return confirmDelete()">
                        <i class="fas fa-trash-alt"></i> Delete
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card hours">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $course['duration_hours']; ?></div>
                <div class="stat-label">Total Hours</div>
            </div>

            <div class="stat-card classes" onclick="window.location.href='<?php echo BASE_URL; ?>modules/admin/academic/classes/?course=<?php echo $course['id']; ?>'">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <div class="stat-value"><?php echo $course['class_count'] ?: '0'; ?></div>
                <div class="stat-label">Active Classes</div>
            </div>

            <div class="stat-card students">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $course['student_count'] ?: '0'; ?></div>
                <div class="stat-label">Enrolled Students</div>
            </div>

            <div class="stat-card order">
                <div class="stat-icon">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div class="stat-value">#<?php echo $course['order_number']; ?></div>
                <div class="stat-label">Order</div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column: Course Details & Classes -->
            <div>
                <!-- Course Details -->
                <div class="section-card" style="margin-bottom: 1.5rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-info-circle"></i> Course Details</h2>
                    </div>
                    <div class="section-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Course Code</span>
                                <span class="detail-value"><?php echo htmlspecialchars($course['course_code']); ?></span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value">
                                    <span class="status-badge status-<?php echo $course['status']; ?>" style="font-size: 0.8rem;">
                                        <?php echo ucfirst($course['status']); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Duration</span>
                                <span class="detail-value"><?php echo $course['duration_hours']; ?> hours</span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Level</span>
                                <span class="detail-value">
                                    <span class="level-badge level-<?php echo $course['level']; ?>">
                                        <?php echo ucfirst($course['level']); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Order Number</span>
                                <span class="detail-value">#<?php echo $course['order_number']; ?></span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Required Course</span>
                                <span class="detail-value"><?php echo $course['is_required'] ? '✅ Yes' : '❌ No'; ?></span>
                            </div>

                            <?php if ($course['creator_first_name']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Created By</span>
                                    <span class="detail-value">
                                        <i class="fas fa-user-circle"></i>
                                        <?php echo htmlspecialchars($course['creator_first_name'] . ' ' . $course['creator_last_name']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="detail-item">
                                <span class="detail-label">Created Date</span>
                                <span class="detail-value">
                                    <i class="far fa-calendar-alt"></i>
                                    <?php echo date('M j, Y', strtotime($course['created_at'])); ?>
                                </span>
                            </div>

                            <div class="detail-item">
                                <span class="detail-label">Last Updated</span>
                                <span class="detail-value">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M j, Y', strtotime($course['updated_at'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Classes Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-chalkboard-teacher"></i> Recent Classes</h2>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?course_id=<?php echo $course['id']; ?>"
                            class="btn btn-primary" style="background: var(--white); color: var(--primary); border: none;">
                            <i class="fas fa-plus-circle"></i> New Class
                        </a>
                    </div>
                    <div class="section-content">
                        <?php if (empty($classes)): ?>
                            <div class="no-data">
                                <i class="fas fa-chalkboard"></i>
                                <h3>No classes yet</h3>
                                <p>Create your first class for this course</p>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?course_id=<?php echo $course['id']; ?>"
                                    class="btn btn-primary" style="margin-top: 0.5rem;">
                                    <i class="fas fa-plus-circle"></i> Create Class
                                </a>
                            </div>
                        <?php else: ?>
                            <ul class="classes-list">
                                <?php foreach ($classes as $class): ?>
                                    <li class="class-item" onclick="window.location.href='<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class['id']; ?>'">
                                        <div class="class-header">
                                            <span class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></span>
                                            <span class="class-status status-<?php echo $class['status']; ?>">
                                                <?php echo ucfirst($class['status']); ?>
                                            </span>
                                        </div>
                                        <div class="class-title"><?php echo htmlspecialchars($class['name']); ?></div>
                                        <div class="class-details">
                                            <div>
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo date('M j', strtotime($class['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-user"></i>
                                                <?php if ($class['instructor_first_name']): ?>
                                                    <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                                                <?php else: ?>
                                                    <em>Not assigned</em>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-users"></i>
                                                <?php echo $class['student_count'] ?: '0'; ?> students
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($total_classes > 5): ?>
                                <div class="view-all">
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/?course=<?php echo $course['id']; ?>">
                                        View all <?php echo $total_classes; ?> classes
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Quick Actions & Program Info -->
            <div>
                <!-- Program Information -->
                <div class="section-card" style="margin-bottom: 1.5rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-layer-group"></i> Program</h2>
                    </div>
                    <div class="section-content">
                        <div style="margin-bottom: 1rem;">
                            <div style="font-size: 0.8rem; color: var(--gray); margin-bottom: 0.25rem;">Program Code</div>
                            <div style="font-size: 1.1rem; font-weight: 600; color: var(--primary);">
                                <?php echo htmlspecialchars($course['program_code']); ?>
                            </div>
                        </div>

                        <div style="margin-bottom: 1.5rem;">
                            <div style="font-size: 0.8rem; color: var(--gray); margin-bottom: 0.25rem;">Program Name</div>
                            <div style="font-size: 1rem; color: var(--dark);">
                                <?php echo htmlspecialchars($course['program_name']); ?>
                            </div>
                        </div>

                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $course['program_id']; ?>"
                            class="btn btn-secondary" style="width: 100%;">
                            <i class="fas fa-external-link-alt"></i> View Program
                        </a>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>
                    <div class="section-content">
                        <div class="quick-actions-grid">
                            <a href="template_manager.php?course_id=<?php echo $course['id']; ?>" class="quick-action-btn">
                                <i class="fas fa-layer-group"></i>
                                <span>Manage Content Templates</span>
                                <i class="fas fa-chevron-right" style="margin-left: auto; color: var(--gray);"></i>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?course_id=<?php echo $course['id']; ?>" class="quick-action-btn">
                                <i class="fas fa-chalkboard"></i>
                                <span>Create New Class</span>
                                <i class="fas fa-chevron-right" style="margin-left: auto; color: var(--gray);"></i>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/create.php?program_id=<?php echo $course['program_id']; ?>" class="quick-action-btn">
                                <i class="fas fa-plus-circle"></i>
                                <span>Add Another Course</span>
                                <i class="fas fa-chevron-right" style="margin-left: auto; color: var(--gray);"></i>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/reports/?type=course&id=<?php echo $course['id']; ?>" class="quick-action-btn">
                                <i class="fas fa-chart-bar"></i>
                                <span>Generate Report</span>
                                <i class="fas fa-chevron-right" style="margin-left: auto; color: var(--gray);"></i>
                            </a>

                            <a href="edit.php?id=<?php echo $course['id']; ?>" class="quick-action-btn">
                                <i class="fas fa-cog"></i>
                                <span>Course Settings</span>
                                <i class="fas fa-chevron-right" style="margin-left: auto; color: var(--gray);"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Confirmation dialogs with better UX
        function confirmActivation() {
            return confirm('✅ Activate this course?\n\nThis will make the course available for class creation and enrollment.');
        }

        function confirmDeactivation() {
            return confirm('⏸️ Deactivate this course?\n\nThis will:\n• Prevent new class creation\n• Existing classes will continue\n• Students can still access active classes\n\nAre you sure?');
        }

        function confirmDelete() {
            return confirm('⚠️ Delete this course?\n\nWARNING: This will permanently delete:\n• All course data\n• Related classes\n• Student enrollments\n• Course materials\n\nThis action CANNOT be undone!\n\nAre you absolutely sure?');
        }

        // Handle URL actions with better UX
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');

        if (action && id && id === '<?php echo $course_id; ?>') {
            const actionMessages = {
                'activate': '✅ Course has been activated successfully!',
                'deactivate': '⏸️ Course has been deactivated successfully.',
                'delete': '🗑️ Course has been deleted successfully.'
            };

            if (actionMessages[action]) {
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success';
                alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> <div>${actionMessages[action]}</div>`;

                const container = document.querySelector('.container');
                container.insertBefore(alertDiv, container.firstChild.nextSibling);

                // Remove message after 3 seconds
                setTimeout(() => {
                    alertDiv.remove();
                    if (action === 'delete') {
                        window.location.href = 'index.php';
                    } else {
                        // Clean up URL
                        const newUrl = window.location.pathname + '?id=' + id;
                        window.history.replaceState({}, document.title, newUrl);
                    }
                }, 3000);
            }
        }

        // Animate elements on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.stat-card, .section-card, .class-item');

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            });

            elements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'all 0.5s ease';
                observer.observe(el);
            });
        });

        // Add touch-friendly hover states
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .class-item, .quick-action-btn').forEach(el => {
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