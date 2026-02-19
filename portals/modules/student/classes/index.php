<?php
// modules/student/classes/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Handle filters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for student's enrolled classes
$query = "SELECT 
            cb.*, 
            c.title as course_title, 
            c.course_code, 
            p.name as program_name,
            p.program_type,
            e.status as enrollment_status,
            e.enrollment_date,
            e.final_grade,
            e.completion_date,
            e.certificate_issued,
            (SELECT COUNT(*) FROM assignments a WHERE a.class_id = cb.id AND a.is_published = 1) as assignment_count,
            (SELECT COUNT(*) FROM materials m WHERE m.class_id = cb.id AND m.is_published = 1) as material_count,
            (SELECT COUNT(*) FROM announcements an WHERE an.class_id = cb.id AND an.is_published = 1) as announcement_count,
            sfs.current_block,
            sfs.is_cleared as financial_cleared,
            sfs.is_suspended as financial_suspended
          FROM enrollments e
          JOIN class_batches cb ON e.class_id = cb.id
          JOIN courses c ON cb.course_id = c.id
          JOIN programs p ON c.program_id = p.id
          LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
          WHERE e.student_id = ?";

$params = [$student_id];
$types = "i";

// Apply enrollment status filter
if ($status_filter !== 'all' && in_array($status_filter, ['active', 'completed', 'dropped', 'suspended'])) {
    $query .= " AND e.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (cb.batch_code LIKE ? OR cb.name LIKE ? OR c.title LIKE ? OR c.course_code LIKE ? OR p.name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
}

$query .= " ORDER BY 
            CASE e.status 
                WHEN 'active' THEN 1 
                WHEN 'suspended' THEN 2
                WHEN 'completed' THEN 3 
                WHEN 'dropped' THEN 4
                ELSE 5 
            END,
            CASE cb.status 
                WHEN 'ongoing' THEN 1 
                WHEN 'scheduled' THEN 2 
                WHEN 'completed' THEN 3 
                WHEN 'cancelled' THEN 4 
                ELSE 5 
            END,
            cb.start_date DESC";

// Get classes
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics for student
$stats = [
    'total' => 0,
    'active' => 0,
    'completed' => 0,
    'dropped' => 0,
    'suspended' => 0
];

$stat_sql = "SELECT status, COUNT(*) as count 
             FROM enrollments 
             WHERE student_id = ? 
             GROUP BY status";
$stmt = $conn->prepare($stat_sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stat_result = $stmt->get_result();
while ($row = $stat_result->fetch_assoc()) {
    $stats[$row['status']] = $row['count'];
    $stats['total'] += $row['count'];
}

// Get upcoming assignments count
$upcoming_assignments_sql = "SELECT COUNT(DISTINCT a.id) as count
                            FROM enrollments e
                            JOIN assignments a ON e.class_id = a.class_id
                            WHERE e.student_id = ? 
                            AND e.status = 'active'
                            AND a.is_published = 1
                            AND a.due_date > NOW()
                            AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                            AND NOT EXISTS (
                                SELECT 1 FROM assignment_submissions asub 
                                WHERE asub.assignment_id = a.id 
                                AND asub.student_id = ? 
                                AND asub.status IN ('submitted', 'graded')
                            )";
$stmt = $conn->prepare($upcoming_assignments_sql);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$upcoming_result = $stmt->get_result();
$upcoming_row = $upcoming_result->fetch_assoc();
$upcoming_assignments = $upcoming_row['count'] ?? 0;

// Get overdue assignments count
$overdue_assignments_sql = "SELECT COUNT(DISTINCT a.id) as count
                           FROM enrollments e
                           JOIN assignments a ON e.class_id = a.class_id
                           WHERE e.student_id = ? 
                           AND e.status = 'active'
                           AND a.is_published = 1
                           AND a.due_date < NOW()
                           AND NOT EXISTS (
                               SELECT 1 FROM assignment_submissions asub 
                               WHERE asub.assignment_id = a.id 
                               AND asub.student_id = ? 
                               AND asub.status IN ('submitted', 'graded')
                           )";
$stmt = $conn->prepare($overdue_assignments_sql);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$overdue_result = $stmt->get_result();
$overdue_row = $overdue_result->fetch_assoc();
$overdue_assignments = $overdue_row['count'] ?? 0;

// Get pending materials count
$new_materials_sql = "SELECT COUNT(DISTINCT m.id) as count
                     FROM enrollments e
                     JOIN materials m ON e.class_id = m.class_id
                     LEFT JOIN notifications n ON n.related_id = m.id 
                     AND n.user_id = ? 
                     AND n.type = 'announcement'
                     WHERE e.student_id = ? 
                     AND e.status = 'active'
                     AND m.is_published = 1
                     AND m.publish_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
                     AND n.id IS NULL";
$stmt = $conn->prepare($new_materials_sql);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$materials_result = $stmt->get_result();
$materials_row = $materials_result->fetch_assoc();
$new_materials = $materials_row['count'] ?? 0;

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - Student Dashboard</title>
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
            padding: 2rem 1rem;
        }

        /* Header */
        .header {
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        /* Stats Cards */
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
            border-top: 4px solid var(--primary);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.active {
            border-top-color: var(--success);
        }

        .stat-card.upcoming {
            border-top-color: var(--warning);
        }

        .stat-card.completed {
            border-top-color: var(--gray);
        }

        .stat-card.alert {
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

        /* Filters */
        .filters-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--dark);
        }

        .filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .classes-grid {
                grid-template-columns: 1fr;
            }
        }

        .class-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
        }

        .class-card.financial-suspended {
            border-color: var(--danger);
            border-width: 2px;
        }

        .class-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            position: relative;
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #e5e7eb;
            color: #374151;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-dropped {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .class-code {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .class-name {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .financial-warning {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 0.5rem 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .class-body {
            padding: 1.5rem;
        }

        .class-info {
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            color: var(--gray);
        }

        .info-item i {
            width: 20px;
            color: var(--primary);
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            background: var(--success);
            color: white;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            display: block;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .class-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
        }

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
            border: 2px solid transparent;
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
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-finance {
            background: var(--warning);
            color: white;
        }

        .btn-finance:hover {
            background: #e6820b;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            max-width: 400px;
            margin: 0 auto;
        }

        .empty-state .btn {
            margin-top: 1rem;
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
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Program type indicator */
        .program-type {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
            margin-top: 0.5rem;
        }

        .program-type.online {
            background: #dcfce7;
            color: #166534;
        }

        .program-type.onsite {
            background: #fef3c7;
            color: #92400e;
        }

        /* Quick stats */
        .quick-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        .quick-stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .quick-stat i {
            color: var(--warning);
        }

        .quick-stat.danger i {
            color: var(--danger);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <span>My Classes</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1>My Enrolled Classes</h1>
            <p>Welcome, <?php echo htmlspecialchars($student_name); ?>. View and access all your enrolled classes here.</p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card active">
                <div class="stat-value"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Currently Active</div>
            </div>
            <div class="stat-card upcoming">
                <div class="stat-value"><?php echo $upcoming_assignments; ?></div>
                <div class="stat-label">Upcoming Assignments</div>
            </div>
            <div class="stat-card alert">
                <div class="stat-value"><?php echo $overdue_assignments; ?></div>
                <div class="stat-label">Overdue Assignments</div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="quick-stat <?php echo $new_materials > 0 ? 'danger' : ''; ?>">
                <i class="fas fa-book"></i>
                <span><?php echo $new_materials; ?> new materials in last 7 days</span>
            </div>
            <?php if ($overdue_assignments > 0): ?>
                <div class="quick-stat danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo $overdue_assignments; ?> assignments overdue</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" id="searchForm">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search classes by code, name, course, or program..."
                        value="<?php echo htmlspecialchars($search_term); ?>">
                </div>

                <div class="filter-buttons">
                    <a href="?status=all" class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                        All Classes
                    </a>
                    <a href="?status=active" class="filter-btn <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                        <i class="fas fa-chalkboard-teacher"></i> Active
                    </a>
                    <a href="?status=completed" class="filter-btn <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> Completed
                    </a>
                    <a href="?status=suspended" class="filter-btn <?php echo $status_filter === 'suspended' ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i> Suspended
                    </a>
                    <a href="?status=dropped" class="filter-btn <?php echo $status_filter === 'dropped' ? 'active' : ''; ?>">
                        <i class="fas fa-times-circle"></i> Dropped
                    </a>
                </div>
            </form>
        </div>

        <!-- Classes Grid -->
        <div class="classes-grid">
            <?php if (empty($classes)): ?>
                <div class="empty-state">
                    <i class="fas fa-chalkboard"></i>
                    <h3>No Classes Enrolled</h3>
                    <p>You are not enrolled in any classes yet. Browse available programs and apply to start your learning journey.</p>
                    <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-primary">
                        <i class="fas fa-search"></i> Browse Programs
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($classes as $class): ?>
                    <div class="class-card <?php echo $class['financial_suspended'] ? 'financial-suspended' : ''; ?>">
                        <div class="class-header">
                            <span class="status-badge status-<?php echo $class['enrollment_status']; ?>">
                                <?php echo ucfirst($class['enrollment_status']); ?>
                            </span>
                            <?php if ($class['financial_suspended']): ?>
                                <div class="financial-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Financial Suspension - Access Restricted</span>
                                </div>
                            <?php endif; ?>
                            <div class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                            <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
                            <span class="program-type <?php echo $class['program_type']; ?>">
                                <i class="fas fa-<?php echo $class['program_type'] === 'online' ? 'laptop' : 'building'; ?>"></i>
                                <?php echo ucfirst($class['program_type']); ?>
                            </span>
                        </div>

                        <div class="class-body">
                            <div class="class-info">
                                <div class="info-item">
                                    <i class="fas fa-book"></i>
                                    <span><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-project-diagram"></i>
                                    <span><?php echo htmlspecialchars($class['program_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>
                                        <?php echo date('M d, Y', strtotime($class['start_date'])); ?> -
                                        <?php echo date('M d, Y', strtotime($class['end_date'])); ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-user-graduate"></i>
                                    <span>Enrolled: <?php echo date('M d, Y', strtotime($class['enrollment_date'])); ?></span>
                                </div>
                                <?php if ($class['current_block']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-layer-group"></i>
                                        <span>Current Block: <?php echo $class['current_block']; ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($class['final_grade']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-star"></i>
                                        <span>
                                            Final Grade:
                                            <span class="grade-badge"><?php echo htmlspecialchars($class['final_grade']); ?></span>
                                            <?php if ($class['certificate_issued']): ?>
                                                <i class="fas fa-certificate" title="Certificate Issued"></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($class['meeting_link'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-video"></i>
                                        <span><a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" style="color: var(--primary);">Join Class</a></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="class-stats">
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['material_count']; ?></span>
                                    <span class="stat-label">Materials</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['assignment_count']; ?></span>
                                    <span class="stat-label">Assignments</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['announcement_count']; ?></span>
                                    <span class="stat-label">Announcements</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number">
                                        <?php echo $class['program_type'] === 'online' ? 'Online' : 'Onsite'; ?>
                                    </span>
                                    <span class="stat-label">Mode</span>
                                </div>
                            </div>
                        </div>

                        <div class="class-footer">
                            <div>
                                <?php if ($class['enrollment_status'] === 'active' && !$class['financial_suspended']): ?>
                                    <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-door-open"></i> Enter Class
                                    </a>
                                <?php elseif ($class['financial_suspended']): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/student/finance/status/" class="btn btn-finance">
                                        <i class="fas fa-unlock-alt"></i> Resolve Payment
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div>
                                <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit search after typing stops
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 500);
            });
        }

        // Clear search button
        function clearSearch() {
            document.querySelector('input[name="search"]').value = '';
            document.getElementById('searchForm').submit();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + / to focus search
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                if (searchInput) {
                    searchInput.focus();
                }
            }

            // Esc to clear search
            if (e.key === 'Escape' && searchInput && searchInput.value) {
                clearSearch();
            }
        });

        // Highlight classes with financial suspension
        document.querySelectorAll('.class-card.financial-suspended').forEach(card => {
            card.addEventListener('click', function(e) {
                if (!e.target.closest('a')) {
                    window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/status/';
                }
            });
        });
    </script>
</body>

</html>