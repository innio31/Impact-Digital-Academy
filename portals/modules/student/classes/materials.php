<?php
// modules/student/classes/materials.php

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

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify student is enrolled in this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        JOIN users u ON cb.instructor_id = u.id
        WHERE e.class_id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $student_id);
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

// Handle file download tracking
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $material_id = (int)$_GET['download'];

    // Verify material exists and student has access
    $sql = "SELECT m.* FROM materials m
            WHERE m.id = ? AND m.class_id = ? AND m.is_published = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $material_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $material = $result->fetch_assoc();

        // Update downloads count
        $update_sql = "UPDATE materials SET downloads_count = downloads_count + 1 WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $material_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Log download activity
        logActivity('material_downloaded', "Downloaded material: " . $material['title'], 'materials', $material_id);

        // Redirect to file
        $file_url = BASE_URL . 'public/' . $material['file_url'];
        header("Location: $file_url");
        exit();
    }
    $stmt->close();
}

// Handle view tracking (if previewed)
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $material_id = (int)$_GET['view'];

    $sql = "UPDATE materials SET views_count = views_count + 1 WHERE id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $material_id, $class_id);
    $stmt->execute();
    $stmt->close();
}

// Handle filters
$filter_week = $_GET['week'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for materials
$query = "SELECT m.*, 
                 CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
                 m.downloads_count + m.views_count as total_access
          FROM materials m 
          JOIN users u ON m.instructor_id = u.id 
          WHERE m.class_id = ? AND m.is_published = 1";

$params = [$class_id];
$types = "i";

// Apply week filter
if ($filter_week !== 'all' && is_numeric($filter_week)) {
    $query .= " AND m.week_number = ?";
    $params[] = $filter_week;
    $types .= "i";
}

// Apply type filter
if ($filter_type !== 'all') {
    $query .= " AND m.file_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.topic LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY m.week_number ASC, m.created_at DESC";

// Get materials
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$materials = $result->fetch_all(MYSQLI_ASSOC);

// Get unique weeks for filter
$weeks_sql = "SELECT DISTINCT week_number FROM materials 
              WHERE class_id = ? AND is_published = 1 AND week_number IS NOT NULL 
              ORDER BY week_number";
$stmt = $conn->prepare($weeks_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$weeks_result = $stmt->get_result();
$available_weeks = $weeks_result->fetch_all(MYSQLI_ASSOC);

// Get file type counts for stats
$type_counts_sql = "SELECT file_type, COUNT(*) as count 
                    FROM materials 
                    WHERE class_id = ? AND is_published = 1
                    GROUP BY file_type";
$stmt = $conn->prepare($type_counts_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$type_counts_result = $stmt->get_result();
$type_counts = [];
while ($row = $type_counts_result->fetch_assoc()) {
    $type_counts[$row['file_type']] = $row['count'];
}

// Get total stats
$stats_sql = "SELECT 
                COUNT(*) as total_materials,
                COALESCE(SUM(file_size), 0) as total_size,
                COALESCE(SUM(downloads_count), 0) as total_downloads,
                COALESCE(SUM(views_count), 0) as total_views
              FROM materials 
              WHERE class_id = ? AND is_published = 1";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();

$stmt->close();
$conn->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Learning Materials</title>
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
            --purple: #8b5cf6;
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
            padding-bottom: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .class-info h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .class-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-title h2 {
            font-size: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title p {
            color: var(--gray);
            margin-top: 0.5rem;
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
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.size {
            border-top-color: var(--info);
        }

        .stat-card.downloads {
            border-top-color: var(--success);
        }

        .stat-card.views {
            border-top-color: var(--warning);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--dark);
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
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 992px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Search and Filter */
        .search-filter {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
            font-size: 1rem;
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

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .filter-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .filter-select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Materials List */
        .materials-list {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .list-header h2 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Type Filters */
        .type-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .type-filter {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--dark);
            display: inline-flex;
            align-items: center;
        }

        .type-filter:hover {
            border-color: var(--primary);
            background: #f0f9ff;
        }

        .type-filter.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .type-filter .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.125rem 0.375rem;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 0.25rem;
        }

        /* Material Items */
        .material-item {
            padding: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.3s ease;
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }

        .material-item:hover {
            background: #f8fafc;
        }

        .material-item:last-child {
            border-bottom: none;
        }

        .material-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .material-icon.pdf {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .material-icon.document {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .material-icon.presentation {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .material-icon.spreadsheet {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .material-icon.video {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .material-icon.image {
            background: rgba(236, 72, 153, 0.1);
            color: #ec4899;
        }

        .material-icon.other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }

        .material-content {
            flex: 1;
            min-width: 0;
        }

        .material-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .material-description {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            line-height: 1.5;
        }

        .material-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .week-badge {
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .material-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        /* Sidebar Cards */
        .sidebar-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .sidebar-card h3 {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f1f5f9;
        }

        /* Quick Stats */
        .quick-stats-list {
            list-style: none;
        }

        .quick-stats-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .quick-stats-list li:last-child {
            border-bottom: none;
        }

        .quick-stats-list .file-type {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Tips */
        .tips-list {
            list-style: none;
        }

        .tips-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .tips-list li:last-child {
            border-bottom: none;
        }

        .tips-list i {
            color: var(--success);
            margin-top: 0.25rem;
            flex-shrink: 0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }

        .empty-state p {
            font-size: 0.95rem;
            line-height: 1.5;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .pagination-link {
            padding: 0.5rem 1rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .pagination-link:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .pagination-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .back-button:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .material-item {
                flex-direction: column;
                gap: 1rem;
            }

            .material-actions {
                align-self: flex-start;
            }

            .search-input {
                min-width: 100%;
            }

            .filter-options {
                grid-template-columns: 1fr;
            }
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
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Learning Materials</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?> - <?php echo htmlspecialchars($class['program_name']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-book"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Quizzes
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-users"></i> Classmates
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i> Join Class
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>
                    <i class="fas fa-book-open"></i>
                    Learning Materials
                </h2>
                <p>Access study materials for <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="stats">
                <span><i class="fas fa-file"></i> <?php echo count($materials); ?> files</span>
                <?php if (count($materials) > 0): ?>
                    <span><i class="fas fa-hdd"></i> <?php echo formatFileSize($stats['total_size']); ?> total</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total_materials']; ?></div>
                <div class="stat-label">Total Materials</div>
            </div>
            <div class="stat-card size">
                <div class="stat-value"><?php echo formatFileSize($stats['total_size']); ?></div>
                <div class="stat-label">Total Size</div>
            </div>
            <div class="stat-card downloads">
                <div class="stat-value"><?php echo $stats['total_downloads']; ?></div>
                <div class="stat-label">Total Downloads</div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo $stats['total_views']; ?></div>
                <div class="stat-label">Total Views</div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <form method="GET" action="" class="search-form" id="filterForm">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search materials by title, description, or topic..."
                    value="<?php echo htmlspecialchars($search_term); ?>"
                    id="searchInput">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if (!empty($search_term) || $filter_week !== 'all' || $filter_type !== 'all'): ?>
                    <a href="materials.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>

            <div class="filter-options">
                <div class="filter-group">
                    <label for="week_filter"><i class="fas fa-calendar-week"></i> Filter by Week</label>
                    <select id="week_filter" name="week" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_week === 'all' ? 'selected' : ''; ?>>All Weeks</option>
                        <?php foreach ($available_weeks as $week): ?>
                            <option value="<?php echo $week['week_number']; ?>"
                                <?php echo $filter_week == $week['week_number'] ? 'selected' : ''; ?>>
                                Week <?php echo $week['week_number']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="type_filter"><i class="fas fa-filter"></i> Filter by Type</label>
                    <select id="type_filter" name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="pdf" <?php echo $filter_type === 'pdf' ? 'selected' : ''; ?>>PDF Documents</option>
                        <option value="document" <?php echo $filter_type === 'document' ? 'selected' : ''; ?>>Documents</option>
                        <option value="presentation" <?php echo $filter_type === 'presentation' ? 'selected' : ''; ?>>Presentations</option>
                        <option value="spreadsheet" <?php echo $filter_type === 'spreadsheet' ? 'selected' : ''; ?>>Spreadsheets</option>
                        <option value="video" <?php echo $filter_type === 'video' ? 'selected' : ''; ?>>Videos</option>
                        <option value="image" <?php echo $filter_type === 'image' ? 'selected' : ''; ?>>Images</option>
                        <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other Files</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="content-grid">
            <!-- Main Content -->
            <div class="main-content">
                <!-- Materials List -->
                <div class="materials-list">
                    <div class="list-header">
                        <h2><i class="fas fa-book"></i> Available Materials</h2>
                        <span style="color: var(--gray); font-size: 0.875rem;">
                            <?php echo count($materials); ?> material(s) found
                        </span>
                    </div>

                    <!-- Type Filters -->
                    <div class="type-filters">
                        <a href="?class_id=<?php echo $class_id; ?>&type=all&week=<?php echo $filter_week; ?>&search=<?php echo urlencode($search_term); ?>"
                            class="type-filter <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                            All Types
                            <?php if ($filter_type === 'all'): ?>
                                <span class="count"><?php echo count($materials); ?></span>
                            <?php endif; ?>
                        </a>

                        <?php foreach ($type_counts as $type => $count): ?>
                            <a href="?class_id=<?php echo $class_id; ?>&type=<?php echo $type; ?>&week=<?php echo $filter_week; ?>&search=<?php echo urlencode($search_term); ?>"
                                class="type-filter <?php echo $filter_type === $type ? 'active' : ''; ?>">
                                <?php echo getFileTypeLabel($type); ?>
                                <?php if ($filter_type === $type): ?>
                                    <span class="count"><?php echo $count; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($materials)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-search"></i>
                            <h3>No Materials Available</h3>
                            <p>
                                <?php if ($filter_type !== 'all' || $filter_week !== 'all' || !empty($search_term)): ?>
                                    No materials match your current filters.
                                <?php else: ?>
                                    No materials have been published for this class yet. Please check back later.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($materials as $material): ?>
                            <div class="material-item">
                                <div class="material-icon <?php echo $material['file_type']; ?>">
                                    <i class="<?php echo getFileIcon($material['file_type']); ?>"></i>
                                </div>

                                <div class="material-content">
                                    <div class="material-title">
                                        <?php echo htmlspecialchars($material['title']); ?>
                                        <?php if ($material['week_number']): ?>
                                            <span class="week-badge">Week <?php echo $material['week_number']; ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($material['description'])): ?>
                                        <div class="material-description">
                                            <?php echo htmlspecialchars($material['description']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="material-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($material['instructor_name']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-file"></i>
                                            <?php echo getFileTypeLabel($material['file_type']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-weight-hanging"></i>
                                            <?php echo formatFileSize($material['file_size']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-download"></i>
                                            <?php echo $material['downloads_count']; ?> downloads
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-eye"></i>
                                            <?php echo $material['views_count']; ?> views
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="material-actions">
                                    <a href="<?php echo $material['external_url']; ?>"
                                        target="_blank"
                                        class="btn btn-primary btn-icon"
                                        title="Preview"
                                        onclick="trackView(<?php echo $material['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <!-- <a href="?class_id=<?php echo $class_id; ?>&download=<?php echo $material['id']; ?>"
                                        class="btn btn-secondary btn-icon"
                                        title="Download"
                                        onclick="showDownloadToast('<?php echo htmlspecialchars($material['title']); ?>')">
                                        <i class="fas fa-download"></i>
                                    </a> -->
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Quick Stats -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-chart-pie"></i> Quick Stats</h3>
                    <ul class="quick-stats-list">
                        <?php if (empty($type_counts)): ?>
                            <li style="color: var(--gray); font-style: italic;">
                                No materials available
                            </li>
                        <?php else: ?>
                            <?php foreach ($type_counts as $type => $count): ?>
                                <li>
                                    <span class="file-type">
                                        <i class="<?php echo getFileIcon($type); ?>"></i>
                                        <?php echo getFileTypeLabel($type); ?>
                                    </span>
                                    <span><strong><?php echo $count; ?></strong></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Class Activity -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-chart-line"></i> Class Activity</h3>
                    <ul class="quick-stats-list">
                        <li>
                            <span><i class="fas fa-download"></i> Total Downloads</span>
                            <span><strong><?php echo $stats['total_downloads']; ?></strong></span>
                        </li>
                        <li>
                            <span><i class="fas fa-eye"></i> Total Views</span>
                            <span><strong><?php echo $stats['total_views']; ?></strong></span>
                        </li>
                        <li>
                            <span><i class="fas fa-hdd"></i> Total Size</span>
                            <span><strong><?php echo formatFileSize($stats['total_size']); ?></strong></span>
                        </li>
                    </ul>
                </div>

                <!-- Tips -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-lightbulb"></i> Study Tips</h3>
                    <ul class="tips-list">
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Use filters to quickly find materials by week or type</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Preview files before downloading to save data</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Download materials when on WiFi to avoid data charges</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Organize downloaded files by week number</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <script>
        // Track view when previewing
        function trackView(materialId) {
            fetch(`?class_id=<?php echo $class_id; ?>&view=${materialId}`, {
                method: 'GET'
            }).catch(err => console.log('View tracking error:', err));
        }

        // Show download toast
        function showDownloadToast(filename) {
            // Create toast notification
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: var(--success);
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1000;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                animation: slideInUp 0.3s ease;
            `;
            toast.innerHTML = `
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Download Started</strong>
                    <div style="font-size: 0.875rem; opacity: 0.9;">${filename}</div>
                </div>
            `;
            document.body.appendChild(toast);

            // Remove toast after 3 seconds
            setTimeout(() => toast.remove(), 3000);
        }

        // Search with Enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('filterForm').submit();
            }
        });

        // Debounced search for better UX
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    document.getElementById('filterForm').submit();
                }
            }, 500);
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }

            // Ctrl/Cmd + / to clear filters
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                window.location.href = 'materials.php?class_id=<?php echo $class_id; ?>';
            }
        });

        // Add CSS animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInUp {
                from {
                    transform: translateY(100%);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>