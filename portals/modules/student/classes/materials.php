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

// Helper function for file icons
function getFileIcon($type)
{
    switch ($type) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'document':
            return 'fas fa-file-word';
        case 'presentation':
            return 'fas fa-file-powerpoint';
        case 'spreadsheet':
            return 'fas fa-file-excel';
        case 'video':
            return 'fas fa-file-video';
        case 'image':
            return 'fas fa-file-image';
        case 'audio':
            return 'fas fa-file-audio';
        case 'archive':
            return 'fas fa-file-archive';
        case 'code':
            return 'fas fa-file-code';
        default:
            return 'fas fa-file';
    }
}

// Helper function for file type labels
function getFileTypeLabel($type)
{
    $labels = [
        'pdf' => 'PDF',
        'document' => 'Document',
        'presentation' => 'Presentation',
        'spreadsheet' => 'Spreadsheet',
        'video' => 'Video',
        'image' => 'Image',
        'audio' => 'Audio',
        'archive' => 'Archive',
        'code' => 'Code',
        'other' => 'Other'
    ];
    return $labels[$type] ?? ucfirst($type);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Learning Materials</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS Variables - Matching class_home.php */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f94144;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border: #dee2e6;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
            --safe-bottom: env(safe-area-inset-bottom, 0);
            --safe-top: env(safe-area-inset-top, 0);
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overscroll-behavior: none;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: max(1rem, env(safe-area-inset-left)) max(1rem, env(safe-area-inset-right));
            padding-bottom: max(2rem, env(safe-area-inset-bottom));
        }

        /* Breadcrumb - Mobile Optimized */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: var(--gray);
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding: 0.25rem 0;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 2rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            background: white;
            border-color: var(--primary);
        }

        .breadcrumb .separator {
            opacity: 0.5;
            margin: 0 0.25rem;
        }

        .breadcrumb span {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
        }

        /* Main Header */
        .main-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .main-header::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .header-content {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        @media (min-width: 768px) {
            .header-content {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .class-info h1 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            word-break: break-word;
        }

        .class-info p {
            font-size: 1.1rem;
            opacity: 0.9;
            word-break: break-word;
        }

        /* Navigation - Mobile Optimized */
        .nav-container {
            display: flex;
            gap: 0.5rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.5rem 0 1rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            position: relative;
            z-index: 1;
        }

        .nav-container::-webkit-scrollbar {
            display: none;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.2rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 2rem;
            text-decoration: none;
            color: white;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            white-space: nowrap;
            font-size: 0.9rem;
            min-height: 48px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        /* Page Title */
        .page-title {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        @media (min-width: 640px) {
            .page-title {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .page-title h2 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.3rem;
            color: var(--dark);
        }

        .page-title h2 i {
            color: var(--primary);
        }

        .page-stats {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 1.5rem 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
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
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Search & Filter */
        .search-filter {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .search-form {
                flex-direction: row;
            }
        }

        .search-input {
            flex: 1;
            padding: 1rem 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            -webkit-appearance: none;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .filter-options {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .filter-select {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            background: white;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: var(--shadow);
            -webkit-tap-highlight-color: transparent;
            min-height: 52px;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--gray);
        }

        .btn-small {
            padding: 0.75rem 1rem;
            min-height: 44px;
            font-size: 0.9rem;
        }

        /* Materials List */
        .materials-list {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-light);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .list-header h2 {
            font-size: 1.2rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .list-header h2 i {
            color: var(--primary);
        }

        .result-count {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Type Filters */
        .type-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--radius-sm);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .type-filter {
            padding: 0.5rem 1rem;
            border: 2px solid var(--border);
            background: white;
            border-radius: 2rem;
            cursor: pointer;
            font-size: 0.9rem;
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark);
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            min-height: 44px;
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
            padding: 0.125rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .type-filter.active .count {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Material Items - Mobile Optimized */
        .material-item {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .material-item:active {
            background: var(--light);
        }

        .material-item:last-child {
            border-bottom: none;
        }

        .material-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-sm);
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

        .material-icon.audio {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .material-icon.archive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }

        .material-icon.code {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
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
            word-break: break-word;
        }

        .week-badge {
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .material-description {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
            line-height: 1.5;
            word-break: break-word;
        }

        .material-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .material-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .btn-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: white;
            border: 2px solid var(--border);
            color: var(--primary);
            transition: var(--transition);
        }

        .btn-icon:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-icon:active {
            transform: scale(0.96);
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
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .empty-state p {
            font-size: 0.95rem;
            line-height: 1.5;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Content Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Sidebar Cards */
        .sidebar-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .sidebar-card h3 {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-light);
        }

        .sidebar-card h3 i {
            color: var(--primary);
        }

        /* Quick Stats List */
        .quick-stats-list {
            list-style: none;
        }

        .quick-stats-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }

        .quick-stats-list li:last-child {
            border-bottom: none;
        }

        .file-type {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-type i {
            width: 20px;
            color: var(--primary);
        }

        /* Tips List */
        .tips-list {
            list-style: none;
        }

        .tips-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
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

        .tips-list span {
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: white;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--dark);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            margin-top: 1.5rem;
            min-height: 52px;
            width: 100%;
        }

        @media (min-width: 640px) {
            .back-button {
                width: auto;
            }
        }

        .back-button:hover {
            background: var(--light);
            border-color: var(--primary);
            color: var(--primary);
        }

        .back-button:active {
            transform: scale(0.98);
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: max(1rem, env(safe-area-inset-bottom));
            left: 1rem;
            right: 1rem;
            background: white;
            border-radius: var(--radius-sm);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 1000;
            animation: slideUp 0.3s ease;
            max-width: 400px;
            margin: 0 auto;
            border-left: 4px solid var(--success);
        }

        .toast i {
            font-size: 1.2rem;
            color: var(--success);
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .toast-message {
            font-size: 0.9rem;
            color: var(--gray);
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

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .material-item,
            .type-filter,
            .back-button {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .material-item:active,
            .type-filter:active,
            .back-button:active {
                transform: scale(0.98);
            }
        }

        /* Accessibility */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        :focus {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        :focus-visible {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i>
                <span class="visually-hidden">Dashboard</span>
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i>
                <span class="visually-hidden">My Classes</span>
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Materials</span>
        </div>

        <!-- Main Header -->
        <div class="main-header">
            <div class="header-content">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i><span>Home</span>
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-book"></i><span>Materials</span>
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i><span>Assignments</span>
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i><span>Quizzes</span>
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i><span>Grades</span>
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i><span>Discuss</span>
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i><span>Join</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h2>
                <i class="fas fa-book-open"></i>
                Learning Materials
            </h2>
            <div class="page-stats">
                <span><i class="fas fa-file"></i> <?php echo count($materials); ?> files</span>
                <?php if ($stats['total_size'] > 0): ?>
                    <span><i class="fas fa-hdd"></i> <?php echo formatFileSize($stats['total_size']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total_materials']; ?></div>
                <div class="stat-label">Materials</div>
            </div>
            <div class="stat-card size">
                <div class="stat-value"><?php echo formatFileSize($stats['total_size']); ?></div>
                <div class="stat-label">Total Size</div>
            </div>
            <div class="stat-card downloads">
                <div class="stat-value"><?php echo $stats['total_downloads']; ?></div>
                <div class="stat-label">Downloads</div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo $stats['total_views']; ?></div>
                <div class="stat-label">Views</div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <form method="GET" action="" class="search-form" id="filterForm">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search materials..."
                    value="<?php echo htmlspecialchars($search_term); ?>"
                    id="searchInput">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if (!empty($search_term) || $filter_week !== 'all' || $filter_type !== 'all'): ?>
                    <a href="materials.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary btn-small">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <div class="filter-options">
                <div class="filter-group">
                    <label for="week_filter"><i class="fas fa-calendar-week"></i> Week</label>
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
                    <label for="type_filter"><i class="fas fa-filter"></i> Type</label>
                    <select id="type_filter" name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="pdf" <?php echo $filter_type === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                        <option value="document" <?php echo $filter_type === 'document' ? 'selected' : ''; ?>>Documents</option>
                        <option value="presentation" <?php echo $filter_type === 'presentation' ? 'selected' : ''; ?>>Presentations</option>
                        <option value="spreadsheet" <?php echo $filter_type === 'spreadsheet' ? 'selected' : ''; ?>>Spreadsheets</option>
                        <option value="video" <?php echo $filter_type === 'video' ? 'selected' : ''; ?>>Videos</option>
                        <option value="image" <?php echo $filter_type === 'image' ? 'selected' : ''; ?>>Images</option>
                        <option value="audio" <?php echo $filter_type === 'audio' ? 'selected' : ''; ?>>Audio</option>
                        <option value="archive" <?php echo $filter_type === 'archive' ? 'selected' : ''; ?>>Archives</option>
                        <option value="code" <?php echo $filter_type === 'code' ? 'selected' : ''; ?>>Code</option>
                        <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
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
                        <h2><i class="fas fa-file-alt"></i> Available Materials</h2>
                        <span class="result-count"><?php echo count($materials); ?> found</span>
                    </div>

                    <!-- Type Filters (Horizontal Scroll) -->
                    <div class="type-filters">
                        <a href="?class_id=<?php echo $class_id; ?>&type=all&week=<?php echo $filter_week; ?>&search=<?php echo urlencode($search_term); ?>"
                            class="type-filter <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                            All
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
                            <h3>No Materials Found</h3>
                            <p>
                                <?php if ($filter_type !== 'all' || $filter_week !== 'all' || !empty($search_term)): ?>
                                    Try adjusting your filters or search term.
                                <?php else: ?>
                                    No materials have been published for this class yet.
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
                                        <span class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($material['instructor_name']); ?>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-weight-hanging"></i>
                                            <?php echo formatFileSize($material['file_size']); ?>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M d', strtotime($material['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="material-actions">
                                    <a href="<?php echo $material['external_url']; ?>"
                                        target="_blank"
                                        class="btn-icon"
                                        title="Preview"
                                        onclick="trackView(<?php echo $material['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <!-- <a href="?class_id=<?php echo $class_id; ?>&download=<?php echo $material['id']; ?>"
                                       class="btn-icon"
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
                    <h3><i class="fas fa-chart-pie"></i> File Types</h3>
                    <ul class="quick-stats-list">
                        <?php if (empty($type_counts)): ?>
                            <li>No files available</li>
                        <?php else: ?>
                            <?php foreach ($type_counts as $type => $count): ?>
                                <li>
                                    <span class="file-type">
                                        <i class="<?php echo getFileIcon($type); ?>"></i>
                                        <?php echo getFileTypeLabel($type); ?>
                                    </span>
                                    <span><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Class Activity -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-chart-line"></i> Activity</h3>
                    <ul class="quick-stats-list">
                        <li>
                            <span><i class="fas fa-download"></i> Downloads</span>
                            <span><?php echo $stats['total_downloads']; ?></span>
                        </li>
                        <li>
                            <span><i class="fas fa-eye"></i> Views</span>
                            <span><?php echo $stats['total_views']; ?></span>
                        </li>
                        <li>
                            <span><i class="fas fa-hdd"></i> Total Size</span>
                            <span><?php echo formatFileSize($stats['total_size']); ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Tips -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-lightbulb"></i> Tips</h3>
                    <ul class="tips-list">
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Preview files before downloading</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Download on WiFi to save data</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Use filters to find materials by week</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Press Ctrl+F to search this page</span>
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
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
                <i class="fas fa-check-circle"></i>
                <div class="toast-content">
                    <div class="toast-title">Download Started</div>
                    <div class="toast-message">${filename}</div>
                </div>
            `;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }

        // Search with debounce
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 2 || this.value.length === 0) {
                    document.getElementById('filterForm').submit();
                }
            }, 500);
        });

        // Keyboard shortcuts
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

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .material-item, .type-filter, .back-button').forEach(el => {
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