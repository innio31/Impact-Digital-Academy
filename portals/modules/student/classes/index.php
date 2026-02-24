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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Classes - Student Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --primary-light: #60a5fa;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --gray-lighter: #e2e8f0;
            --white: #ffffff;
            --online-bg: #dcfce7;
            --online-text: #166534;
            --onsite-bg: #fef3c7;
            --onsite-text: #92400e;
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
            max-width: 1200px;
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

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Header */
        .header {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .header h1 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header p {
            color: var(--gray);
            font-size: 1rem;
            line-height: 1.5;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-light);
        }

        .stat-card.total {
            border-left: 4px solid var(--primary);
        }

        .stat-card.active {
            border-left: 4px solid var(--success);
        }

        .stat-card.upcoming {
            border-left: 4px solid var(--warning);
        }

        .stat-card.completed {
            border-left: 4px solid var(--gray);
        }

        .stat-card.alert {
            border-left: 4px solid var(--danger);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-value i {
            font-size: 1.2rem;
            color: var(--gray-light);
            opacity: 0.5;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -webkit-overflow-scrolling: touch;
        }

        .quick-stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--white);
            border-radius: 30px;
            font-size: 0.8rem;
            color: var(--gray);
            border: 2px solid var(--gray-lighter);
            white-space: nowrap;
        }

        .quick-stat i {
            color: var(--warning);
            font-size: 0.9rem;
        }

        .quick-stat.danger {
            border-color: var(--danger);
        }

        .quick-stat.danger i {
            color: var(--danger);
        }

        /* Filters Bar */
        .filters-bar {
            background: var(--white);
            border-radius: 20px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.5rem;
            border: 2px solid var(--gray-lighter);
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
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
            overflow-x: auto;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray-lighter);
            background: var(--white);
            border-radius: 30px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            text-decoration: none;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .filter-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border-color: transparent;
        }

        .filter-btn i {
            font-size: 0.8rem;
        }

        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .class-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }

        .class-card.financial-suspended {
            border-color: var(--danger);
            border-width: 2px;
        }

        .class-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            position: relative;
        }

        .badges-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
        }

        .status-active {
            background: rgba(16, 185, 129, 0.3);
        }

        .status-completed {
            background: rgba(255, 255, 255, 0.3);
        }

        .status-suspended {
            background: rgba(239, 68, 68, 0.3);
        }

        .status-dropped {
            background: rgba(100, 116, 139, 0.3);
        }

        .program-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .class-code {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }

        .class-name {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .financial-warning {
            background: rgba(239, 68, 68, 0.15);
            color: #fee2e2;
            padding: 0.75rem 1.25rem;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-top: 1px solid rgba(239, 68, 68, 0.3);
            margin-top: 0.75rem;
        }

        .financial-warning i {
            color: #fecaca;
        }

        .class-body {
            padding: 1.25rem;
        }

        .class-info {
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            color: var(--gray);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .info-item i {
            width: 16px;
            color: var(--primary);
            margin-top: 0.2rem;
        }

        .info-item span {
            flex: 1;
            word-break: break-word;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.75rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            background: var(--success);
            color: var(--white);
            margin-left: 0.5rem;
        }

        .certificate-icon {
            color: var(--warning);
            margin-left: 0.5rem;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin-top: 1rem;
            padding: 0.75rem;
            background: var(--light);
            border-radius: 12px;
            border: 2px solid var(--gray-lighter);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            display: block;
        }

        .stat-label {
            font-size: 0.6rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .class-footer {
            padding: 1rem 1.25rem;
            background: var(--light);
            border-top: 2px solid var(--gray-lighter);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .btn-row {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            border: 2px solid transparent;
            font-size: 0.85rem;
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
            border-color: var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: var(--white);
        }

        .btn-finance {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: var(--white);
        }

        .btn-finance:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.3);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 3rem 1.5rem;
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            font-size: 0.9rem;
        }

        /* Tablet Breakpoint */
        @media (min-width: 640px) {
            .container {
                padding: 1.5rem;
            }

            .header {
                padding: 2rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }

            .filter-buttons {
                justify-content: flex-start;
            }

            .classes-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }

            .class-footer {
                flex-direction: row;
            }

            .btn-row {
                flex: 1;
            }
        }

        /* Desktop Breakpoint */
        @media (min-width: 1024px) {
            .classes-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
        }

        /* Large Desktop Breakpoint */
        @media (min-width: 1280px) {
            .classes-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .class-card,
            .filter-btn,
            .quick-stat {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .class-card:active,
            .filter-btn:active {
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

        .class-card {
            animation: fadeInUp 0.5s ease;
        }

        .stat-card {
            animation: slideIn 0.5s ease;
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
            <h1><i class="fas fa-chalkboard-teacher"></i> My Classes</h1>
            <p>Welcome back, <?php echo htmlspecialchars($student_name); ?>! Here are all your enrolled classes and their current status.</p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total" onclick="window.location.href='?status=all'">
                <div class="stat-value">
                    <?php echo $stats['total']; ?>
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card active" onclick="window.location.href='?status=active'">
                <div class="stat-value">
                    <?php echo $stats['active']; ?>
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stat-label">Currently Active</div>
            </div>
            <div class="stat-card upcoming">
                <div class="stat-value">
                    <?php echo $upcoming_assignments; ?>
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card alert" onclick="window.location.href='#overdue'">
                <div class="stat-value">
                    <?php echo $overdue_assignments; ?>
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <?php if ($new_materials > 0): ?>
                <div class="quick-stat <?php echo $new_materials > 0 ? 'danger' : ''; ?>">
                    <i class="fas fa-book"></i>
                    <span><?php echo $new_materials; ?> new material<?php echo $new_materials > 1 ? 's' : ''; ?> this week</span>
                </div>
            <?php endif; ?>
            <?php if ($overdue_assignments > 0): ?>
                <div class="quick-stat danger">
                    <i class="fas fa-clock"></i>
                    <span><?php echo $overdue_assignments; ?> overdue assignment<?php echo $overdue_assignments > 1 ? 's' : ''; ?></span>
                </div>
            <?php endif; ?>
            <?php if ($stats['completed'] > 0): ?>
                <div class="quick-stat">
                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                    <span><?php echo $stats['completed']; ?> completed class<?php echo $stats['completed'] > 1 ? 'es' : ''; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" id="searchForm">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search by code, name, course, or program..."
                        value="<?php echo htmlspecialchars($search_term); ?>">
                    <?php if (!empty($search_term)): ?>
                        <button type="button" onclick="clearSearch()" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--gray); cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="filter-buttons">
                    <a href="?status=all" class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-th-large"></i> All
                    </a>
                    <a href="?status=active" class="filter-btn <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                        <i class="fas fa-play-circle"></i> Active
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
                    <h3>No Classes Found</h3>
                    <p><?php echo $search_term ? 'No classes match your search criteria.' : 'You are not enrolled in any classes yet.'; ?></p>
                    <?php if ($search_term): ?>
                        <a href="?" class="btn btn-primary">
                            <i class="fas fa-times"></i> Clear Search
                        </a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Browse Programs
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($classes as $class):
                    $is_suspended = $class['financial_suspended'] || $class['enrollment_status'] === 'suspended';
                    $can_enter = $class['enrollment_status'] === 'active' && !$class['financial_suspended'];
                ?>
                    <div class="class-card <?php echo $is_suspended ? 'financial-suspended' : ''; ?>"
                        onclick="<?php echo $is_suspended ? 'window.location.href=\'' . BASE_URL . 'modules/student/finance/status/\'' : ''; ?>">
                        <div class="class-header">
                            <div class="badges-container">
                                <span class="status-badge status-<?php echo $class['enrollment_status']; ?>">
                                    <?php echo ucfirst($class['enrollment_status']); ?>
                                </span>
                                <span class="program-type-badge">
                                    <i class="fas fa-<?php echo $class['program_type'] === 'online' ? 'laptop' : 'building'; ?>"></i>
                                    <?php echo ucfirst($class['program_type']); ?>
                                </span>
                            </div>
                            <div class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                            <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>

                            <?php if ($class['financial_suspended']): ?>
                                <div class="financial-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Financial Hold - Access Restricted</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="class-body">
                            <div class="class-info">
                                <div class="info-item">
                                    <i class="fas fa-book"></i>
                                    <span><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-layer-group"></i>
                                    <span><?php echo htmlspecialchars($class['program_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>
                                        <?php echo date('M j, Y', strtotime($class['start_date'])); ?> -
                                        <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Enrolled: <?php echo date('M j, Y', strtotime($class['enrollment_date'])); ?></span>
                                </div>
                                <?php if ($class['current_block']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-layer-group"></i>
                                        <span>Block <?php echo $class['current_block']; ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($class['final_grade']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-star"></i>
                                        <span>
                                            Grade: <span class="grade-badge"><?php echo htmlspecialchars($class['final_grade']); ?></span>
                                            <?php if ($class['certificate_issued']): ?>
                                                <i class="fas fa-certificate certificate-icon" title="Certificate Issued"></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($class['meeting_link']) && $can_enter): ?>
                                    <div class="info-item">
                                        <i class="fas fa-video"></i>
                                        <span><a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" style="color: var(--primary); text-decoration: none;">Join Live Session</a></span>
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
                                    <span class="stat-label">Tasks</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['announcement_count']; ?></span>
                                    <span class="stat-label">Updates</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number">
                                        <i class="fas fa-<?php echo $class['program_type'] === 'online' ? 'wifi' : 'building'; ?>"></i>
                                    </span>
                                    <span class="stat-label">Mode</span>
                                </div>
                            </div>
                        </div>

                        <div class="class-footer">
                            <?php if ($can_enter): ?>
                                <div class="btn-row">
                                    <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-door-open"></i> Enter Class
                                    </a>
                                </div>
                            <?php elseif ($class['financial_suspended']): ?>
                                <div class="btn-row">
                                    <a href="<?php echo BASE_URL; ?>modules/student/finance/status/" class="btn btn-finance">
                                        <i class="fas fa-credit-card"></i> Resolve Payment
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="btn-row">
                                <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-eye"></i> Details
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

        // Clear search
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

            // Ctrl + A to show all classes
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                window.location.href = '?status=all';
            }

            // Ctrl + 1 for active classes
            if (e.ctrlKey && e.key === '1') {
                e.preventDefault();
                window.location.href = '?status=active';
            }
        });

        // Animate elements on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.stat-card, .class-card');

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

        // Touch-friendly hover states
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .class-card, .filter-btn, .quick-stat').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Highlight classes with overdue assignments (simulated)
        const overdueCount = <?php echo $overdue_assignments; ?>;
        if (overdueCount > 0) {
            const alertCard = document.querySelector('.stat-card.alert');
            if (alertCard) {
                alertCard.addEventListener('click', function() {
                    document.querySelectorAll('.class-card').forEach(card => {
                        if (card.querySelector('.quick-stat.danger')) {
                            card.style.borderColor = 'var(--danger)';
                            setTimeout(() => {
                                card.style.borderColor = '';
                            }, 2000);
                        }
                    });
                });
            }
        }
    </script>
</body>

</html>