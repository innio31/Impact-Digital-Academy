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
                ORDER BY cb.start_date DESC";

$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $course_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity('course_view', "Viewed course: {$course['course_code']}", 'courses', $course_id);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
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
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .course-info {
            flex: 1;
        }

        .course-program {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .course-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .course-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .course-status {
            display: inline-block;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .status-active {
            background: var(--success);
            color: white;
        }

        .status-inactive {
            background: var(--danger);
            color: white;
        }

        .course-description {
            color: var(--gray);
            line-height: 1.6;
            font-size: 1.1rem;
            max-width: 800px;
            margin-bottom: 1rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-top: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.hours {
            border-top-color: var(--accent);
        }

        .stat-card.classes {
            border-top-color: var(--info);
        }

        .stat-card.students {
            border-top-color: var(--success);
        }

        .stat-card.order {
            border-top-color: var(--warning);
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--gray);
            margin-bottom: 1rem;
            opacity: 0.7;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
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
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--light-gray);
        }

        .section-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .section-content {
            padding: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .detail-group {
            margin-bottom: 1.5rem;
        }

        .detail-group label {
            display: block;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-group .value {
            font-size: 1.1rem;
            color: var(--dark);
            font-weight: 500;
            line-height: 1.6;
        }

        .level-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .level-beginner {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .level-intermediate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .level-advanced {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .classes-list {
            list-style: none;
        }

        .class-item {
            padding: 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .class-item:hover {
            border-color: var(--primary);
            background: var(--light);
            transform: translateX(5px);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .class-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .class-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-ongoing {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-completed {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }

        .class-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .page-actions {
                width: 100%;
                justify-content: center;
            }

            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/">Courses</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($course['course_code']); ?></span>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="course-info">
                <div class="course-program">
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $course['program_id']; ?>">
                        <?php echo htmlspecialchars($course['program_code'] . ' - ' . $course['program_name']); ?>
                    </a>
                </div>
                <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                <h1 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h1>
                <div class="course-status status-<?php echo $course['status']; ?>">
                    <?php echo ucfirst($course['status']); ?>
                </div>
                <div class="course-description">
                    <?php echo nl2br(htmlspecialchars($course['description'])); ?>
                </div>
            </div>

            <div class="page-actions">
                <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Course
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php if ($course['status'] === 'active'): ?>
                    <a href="?action=deactivate&id=<?php echo $course['id']; ?>"
                        class="btn btn-warning"
                        onclick="return confirm('Deactivate this course?')">
                        <i class="fas fa-pause"></i> Deactivate
                    </a>
                <?php elseif ($course['status'] === 'inactive'): ?>
                    <a href="?action=activate&id=<?php echo $course['id']; ?>"
                        class="btn btn-success"
                        onclick="return confirm('Activate this course?')">
                        <i class="fas fa-play"></i> Activate
                    </a>
                <?php endif; ?>
                <a href="?action=delete&id=<?php echo $course['id']; ?>"
                    class="btn btn-danger"
                    onclick="return confirm('Delete this course? This action cannot be undone.')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card hours">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $course['duration_hours']; ?></div>
                <div class="stat-label">Hours</div>
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
                <div class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2>Course Details</h2>
                    </div>
                    <div class="section-content">
                        <div class="detail-grid">
                            <div class="detail-group">
                                <label>Course Code</label>
                                <div class="value"><?php echo htmlspecialchars($course['course_code']); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Status</label>
                                <div class="value">
                                    <span class="course-status status-<?php echo $course['status']; ?>">
                                        <?php echo ucfirst($course['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="detail-group">
                                <label>Duration</label>
                                <div class="value"><?php echo $course['duration_hours']; ?> hours</div>
                            </div>

                            <div class="detail-group">
                                <label>Level</label>
                                <div class="value">
                                    <span class="level-badge level-<?php echo $course['level']; ?>">
                                        <?php echo ucfirst($course['level']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="detail-group">
                                <label>Order Number</label>
                                <div class="value">#<?php echo $course['order_number']; ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Required Course</label>
                                <div class="value"><?php echo $course['is_required'] ? 'Yes' : 'No'; ?></div>
                            </div>

                            <?php if ($course['creator_first_name']): ?>
                                <div class="detail-group">
                                    <label>Created By</label>
                                    <div class="value">
                                        <?php echo htmlspecialchars($course['creator_first_name'] . ' ' . $course['creator_last_name']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="detail-group">
                                <label>Created Date</label>
                                <div class="value"><?php echo formatDate($course['created_at'], 'F j, Y \a\t h:i A'); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Last Updated</label>
                                <div class="value"><?php echo formatDate($course['updated_at'], 'F j, Y \a\t h:i A'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Classes Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2>Active Classes</h2>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?course_id=<?php echo $course['id']; ?>"
                            class="btn btn-primary" style="background: white; color: var(--primary);">
                            <i class="fas fa-plus"></i> Create Class
                        </a>
                    </div>
                    <div class="section-content">
                        <?php if (empty($classes)): ?>
                            <div class="no-data">
                                <i class="fas fa-chalkboard"></i>
                                <h3>No active classes</h3>
                                <p>Create classes for this course</p>
                            </div>
                        <?php else: ?>
                            <ul class="classes-list">
                                <?php foreach ($classes as $class): ?>
                                    <li class="class-item">
                                        <div class="class-header">
                                            <div class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                                            <div class="class-status status-<?php echo $class['status']; ?>">
                                                <?php echo ucfirst($class['status']); ?>
                                            </div>
                                        </div>
                                        <div class="class-title"><?php echo htmlspecialchars($class['name']); ?></div>
                                        <div class="class-details">
                                            <div>
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M j, Y', strtotime($class['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-user"></i>
                                                <?php if ($class['instructor_first_name']): ?>
                                                    <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                                                <?php else: ?>
                                                    <em>No instructor</em>
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
                        <?php endif; ?>

                        <div style="text-align: center; margin-top: 1.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/?course=<?php echo $course['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-list"></i> View All Classes
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Quick Actions & Program Info -->
            <div>
                <!-- Program Information -->
                <div class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2>Program Information</h2>
                    </div>
                    <div class="section-content">
                        <div style="margin-bottom: 1rem;">
                            <div style="font-size: 0.9rem; color: var(--gray); margin-bottom: 0.25rem;">Program</div>
                            <div style="font-size: 1.2rem; font-weight: 600; color: var(--dark); margin-bottom: 0.5rem;">
                                <?php echo htmlspecialchars($course['program_code'] . ' - ' . $course['program_name']); ?>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 1.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $course['program_id']; ?>"
                                class="btn btn-secondary" style="width: 100%;">
                                <i class="fas fa-external-link-alt"></i> View Program Details
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="section-card">
                    <div class="section-header">
                        <h2>Quick Actions</h2>
                    </div>
                    <div class="section-content">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <a href="template_manager.php?course_id=<?php echo $course['id']; ?>" class="btn btn-info">
                                <i class="fas fa-layer-group"></i> Manage Content Templates
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?course_id=<?php echo $course['id']; ?>"
                                class="btn btn-primary">
                                <i class="fas fa-chalkboard"></i> Create New Class
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/create.php?program_id=<?php echo $course['program_id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-book"></i> Add Another Course
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/reports/?type=course&id=<?php echo $course['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-chart-bar"></i> Generate Report
                            </a>

                            <a href="edit.php?id=<?php echo $course['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-cog"></i> Course Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle URL actions
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');

        if (action && id && id === '<?php echo $course_id; ?>') {
            // Actions are handled server-side, just show confirmation
            const actionMessages = {
                'activate': 'Course has been activated successfully.',
                'deactivate': 'Course has been deactivated successfully.',
                'delete': 'Course has been deleted. Redirecting...'
            };

            if (actionMessages[action]) {
                alert(actionMessages[action]);
                if (action === 'delete') {
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 1500);
                }
            }
        }
    </script>
</body>

</html>