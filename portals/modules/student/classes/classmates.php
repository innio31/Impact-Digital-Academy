<?php
// modules/student/classes/classmates.php

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

// Get class details and verify student enrollment
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        JOIN enrollments e ON cb.id = e.class_id 
        WHERE cb.id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
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

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12; // Changed to 12 for better grid display on mobile
$offset = ($page - 1) * $limit;

// Build query for classmates
$query = "SELECT u.id, u.first_name, u.last_name, u.email, u.profile_image,
                 up.date_of_birth, up.gender, up.city, up.state, up.country,
                 up.bio, up.website, up.linkedin_url, up.github_url,
                 up.qualifications, up.experience_years,
                 up.current_job_title, up.current_company,
                 e.enrollment_date, e.status as enrollment_status,
                 e.final_grade, e.completion_date
          FROM enrollments e 
          JOIN users u ON e.student_id = u.id 
          LEFT JOIN user_profiles up ON u.id = up.user_id
          WHERE e.class_id = ? AND e.status = 'active' AND e.student_id != ?
          AND u.status = 'active'";

$params = [$class_id, $student_id];
$types = "ii";

// Add search condition
if (!empty($search)) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? 
                   OR up.current_job_title LIKE ? OR up.current_company LIKE ? 
                   OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= str_repeat('s', 6);
}

// Add sorting
switch ($sort) {
    case 'name':
        $query .= " ORDER BY u.first_name, u.last_name";
        break;
    case 'name_desc':
        $query .= " ORDER BY u.first_name DESC, u.last_name DESC";
        break;
    case 'enrollment_date':
        $query .= " ORDER BY e.enrollment_date DESC";
        break;
    case 'company':
        $query .= " ORDER BY up.current_company, u.first_name";
        break;
    default:
        $query .= " ORDER BY u.first_name, u.last_name";
}

// Count total classmates
$countQuery = str_replace(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.profile_image,
                 up.date_of_birth, up.gender, up.city, up.state, up.country,
                 up.bio, up.website, up.linkedin_url, up.github_url,
                 up.qualifications, up.experience_years,
                 up.current_job_title, up.current_company,
                 e.enrollment_date, e.status as enrollment_status,
                 e.final_grade, e.completion_date",
    "SELECT COUNT(*) as total",
    $query
);

$stmt = $conn->prepare($countQuery);
if (count($params) > 2) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param($types, $class_id, $student_id);
}
$stmt->execute();
$result = $stmt->get_result();
$totalClassmates = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

// Get paginated classmates
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$classmates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get company stats for filtering (optional enhancement)
$companyStats = [];
$companyQuery = "SELECT up.current_company, COUNT(*) as count
                FROM enrollments e
                JOIN users u ON e.student_id = u.id
                LEFT JOIN user_profiles up ON u.id = up.user_id
                WHERE e.class_id = ? AND e.status = 'active' AND e.student_id != ?
                AND up.current_company IS NOT NULL AND up.current_company != ''
                GROUP BY up.current_company
                ORDER BY count DESC
                LIMIT 5";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $companyStats[] = $row;
}
$stmt->close();

// Get location stats
$locationQuery = "SELECT up.country, COUNT(*) as count
                 FROM enrollments e
                 JOIN users u ON e.student_id = u.id
                 LEFT JOIN user_profiles up ON u.id = up.user_id
                 WHERE e.class_id = ? AND e.status = 'active' AND e.student_id != ?
                 AND up.country IS NOT NULL AND up.country != ''
                 GROUP BY up.country
                 ORDER BY count DESC
                 LIMIT 5";
$stmt = $conn->prepare($locationQuery);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();
$locationStats = [];
while ($row = $result->fetch_assoc()) {
    $locationStats[] = $row;
}
$stmt->close();

// Get total pages
$totalPages = ceil($totalClassmates / $limit);

// Log activity
logActivity('view_classmates', "Viewed classmates for class: {$class['batch_code']}", 'class_batches', $class_id);
$conn->close();

// Helper function to get initials
function getInitials($firstName, $lastName)
{
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Classmates</title>
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

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .page-header {
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
            margin-bottom: 0.5rem;
        }

        .page-title h2 i {
            color: var(--primary);
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .page-stats {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
            flex-wrap: wrap;
        }

        /* Stats Cards */
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
            padding: 1.25rem 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.companies {
            border-top-color: var(--success);
        }

        .stat-card.locations {
            border-top-color: var(--warning);
        }

        .stat-card.completed {
            border-top-color: var(--info);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.25rem;
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
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .filter-options {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .sort-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .sort-wrapper label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .sort-select {
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1em;
            min-width: 180px;
        }

        .sort-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .result-badge {
            background: var(--light);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.9rem;
            color: var(--gray);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-badge i {
            color: var(--primary);
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

        /* Classmates Grid - Mobile First */
        .classmates-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 640px) {
            .classmates-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .classmates-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Classmate Card */
        .classmate-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .classmate-card:active {
            transform: scale(0.98);
            border-color: var(--primary);
        }

        .classmate-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .classmate-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .classmate-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.5rem;
            flex-shrink: 0;
            box-shadow: var(--shadow);
        }

        .classmate-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .classmate-name {
            flex: 1;
            min-width: 0;
        }

        .classmate-name h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
            word-break: break-word;
        }

        .classmate-email {
            color: var(--gray);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            word-break: break-all;
        }

        .completed-badge {
            background: var(--success);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-top: 0.5rem;
        }

        .classmate-info {
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .info-item i {
            color: var(--primary);
            width: 18px;
            margin-top: 0.2rem;
        }

        .info-content {
            flex: 1;
            line-height: 1.5;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray);
            margin-right: 0.25rem;
        }

        .classmate-bio {
            background: var(--light);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--dark);
            line-height: 1.6;
            border-left: 4px solid var(--primary);
        }

        .classmate-bio i {
            color: var(--primary);
            margin-right: 0.25rem;
        }

        .classmate-social {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray-light);
        }

        .social-link {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--light);
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
            border: 2px solid var(--border);
        }

        .social-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .social-link:active {
            transform: scale(0.96);
        }

        /* Quick Stats Sidebar (for larger screens) */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                grid-template-columns: 3fr 1fr;
            }
        }

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

        .stat-list {
            list-style: none;
        }

        .stat-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }

        .stat-list li:last-child {
            border-bottom: none;
        }

        .stat-list .company-name,
        .stat-list .location-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-list i {
            color: var(--primary);
            width: 20px;
        }

        .stat-count {
            background: var(--light);
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .pagination-link {
            min-width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.75rem;
            background: white;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--dark);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .pagination-link:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .pagination-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
            background: var(--light);
        }

        .pagination-dots {
            color: var(--gray);
            font-weight: 600;
            padding: 0 0.25rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin: 2rem 0;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray);
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            max-width: 400px;
            margin: 0 auto 1.5rem;
            line-height: 1.6;
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
            .classmate-card,
            .social-link,
            .pagination-link,
            .back-button {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .classmate-card:active,
            .social-link:active,
            .pagination-link:active,
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
            <span>Classmates</span>
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
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
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
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-users"></i><span>Classmates</span>
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i><span>Join</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>
                    <i class="fas fa-users"></i>
                    Classmates
                </h2>
                <p>Connect with <?php echo $totalClassmates; ?> fellow students</p>
            </div>
            <div class="page-stats">
                <span><i class="fas fa-user-graduate"></i> <?php echo $totalClassmates; ?> total</span>
                <?php if ($totalClassmates > 0): ?>
                    <span><i class="fas fa-chart-pie"></i> Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats Grid -->
        <?php if (!empty($classmates)): ?>
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-value"><?php echo $totalClassmates; ?></div>
                    <div class="stat-label">Classmates</div>
                </div>
                <div class="stat-card companies">
                    <div class="stat-value"><?php echo count($companyStats); ?></div>
                    <div class="stat-label">Companies</div>
                </div>
                <div class="stat-card locations">
                    <div class="stat-value"><?php echo count($locationStats); ?></div>
                    <div class="stat-label">Locations</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-value">
                        <?php
                        $completed = array_filter($classmates, function ($c) {
                            return $c['enrollment_status'] === 'completed';
                        });
                        echo count($completed);
                        ?>
                    </div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search and Filter -->
        <div class="search-filter">
            <form method="GET" action="" class="search-form" id="searchForm">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search by name, company, or job title..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    id="searchInput">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if (!empty($search)): ?>
                    <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary btn-small">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <div class="filter-options">
                <div class="sort-wrapper">
                    <label for="sort"><i class="fas fa-sort"></i> Sort:</label>
                    <select id="sort" name="sort" class="sort-select" onchange="updateSort(this.value)">
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                        <option value="enrollment_date" <?php echo $sort === 'enrollment_date' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="company" <?php echo $sort === 'company' ? 'selected' : ''; ?>>Company</option>
                    </select>
                </div>

                <?php if (!empty($search)): ?>
                    <div class="result-badge">
                        <i class="fas fa-filter"></i> <?php echo $totalClassmates; ?> result(s)
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Classmates Grid -->
            <div class="main-content">
                <?php if (empty($classmates)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <?php if (!empty($search)): ?>
                            <h3>No classmates found</h3>
                            <p>We couldn't find anyone matching "<?php echo htmlspecialchars($search); ?>". Try a different search term.</p>
                            <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-times"></i> Clear Search
                            </a>
                        <?php else: ?>
                            <h3>No classmates yet</h3>
                            <p>You're currently the only active student in this class. Invite your friends to join!</p>
                            <a href="class_home.php?id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-arrow-left"></i> Back to Class
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="classmates-grid">
                        <?php foreach ($classmates as $classmate): ?>
                            <div class="classmate-card">
                                <div class="classmate-header">
                                    <div class="classmate-avatar">
                                        <?php if (!empty($classmate['profile_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($classmate['profile_image']); ?>"
                                                alt="<?php echo htmlspecialchars($classmate['first_name']); ?>">
                                        <?php else: ?>
                                            <?php echo getInitials($classmate['first_name'], $classmate['last_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="classmate-name">
                                        <h3><?php echo htmlspecialchars($classmate['first_name'] . ' ' . $classmate['last_name']); ?></h3>
                                        <div class="classmate-email">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($classmate['email']); ?>
                                        </div>
                                        <?php if ($classmate['enrollment_status'] === 'completed'): ?>
                                            <div class="completed-badge">
                                                <i class="fas fa-graduation-cap"></i> Completed
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="classmate-info">
                                    <?php if (!empty($classmate['current_job_title']) || !empty($classmate['current_company'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-briefcase"></i>
                                            <div class="info-content">
                                                <?php if (!empty($classmate['current_job_title'])): ?>
                                                    <span class="info-label"><?php echo htmlspecialchars($classmate['current_job_title']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($classmate['current_company'])): ?>
                                                    <?php if (!empty($classmate['current_job_title'])): ?>at <?php endif; ?>
                                                <?php echo htmlspecialchars($classmate['current_company']); ?>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($classmate['city']) || !empty($classmate['country'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <div class="info-content">
                                                <?php
                                                $locationParts = [];
                                                if (!empty($classmate['city'])) $locationParts[] = $classmate['city'];
                                                if (!empty($classmate['state'])) $locationParts[] = $classmate['state'];
                                                if (!empty($classmate['country'])) $locationParts[] = $classmate['country'];
                                                echo htmlspecialchars(implode(', ', $locationParts));
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($classmate['experience_years']) && $classmate['experience_years'] > 0): ?>
                                        <div class="info-item">
                                            <i class="fas fa-clock"></i>
                                            <div class="info-content">
                                                <span class="info-label">Experience:</span>
                                                <?php echo $classmate['experience_years']; ?> year(s)
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($classmate['qualifications'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-graduation-cap"></i>
                                            <div class="info-content">
                                                <?php echo htmlspecialchars($classmate['qualifications']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <div class="info-content">
                                            <span class="info-label">Enrolled:</span>
                                            <?php echo date('M d, Y', strtotime($classmate['enrollment_date'])); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($classmate['bio'])): ?>
                                    <div class="classmate-bio">
                                        <i class="fas fa-quote-left"></i>
                                        <?php echo nl2br(htmlspecialchars(substr($classmate['bio'], 0, 150))); ?>
                                        <?php if (strlen($classmate['bio']) > 150): ?>...<?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="classmate-social">
                                    <?php if (!empty($classmate['linkedin_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($classmate['linkedin_url']); ?>"
                                            target="_blank"
                                            class="social-link"
                                            title="LinkedIn"
                                            onclick="showSocialToast('LinkedIn')">
                                            <i class="fab fa-linkedin-in"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($classmate['github_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($classmate['github_url']); ?>"
                                            target="_blank"
                                            class="social-link"
                                            title="GitHub"
                                            onclick="showSocialToast('GitHub')">
                                            <i class="fab fa-github"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($classmate['website'])): ?>
                                        <a href="<?php echo htmlspecialchars($classmate['website']); ?>"
                                            target="_blank"
                                            class="social-link"
                                            title="Website"
                                            onclick="showSocialToast('Website')">
                                            <i class="fas fa-globe"></i>
                                        </a>
                                    <?php endif; ?>

                                    <a href="mailto:<?php echo htmlspecialchars($classmate['email']); ?>"
                                        class="social-link"
                                        title="Send Email"
                                        onclick="showSocialToast('Email')">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <!-- Previous Page -->
                            <?php if ($page > 1): ?>
                                <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $page - 1; ?>"
                                    class="pagination-link"
                                    title="Previous page">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            <?php endif; ?>

                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1): ?>
                                <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=1"
                                    class="pagination-link">1</a>
                                <?php if ($startPage > 2): ?>
                                    <span class="pagination-dots">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $i; ?>"
                                    class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <span class="pagination-dots">...</span>
                                <?php endif; ?>
                                <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $totalPages; ?>"
                                    class="pagination-link"><?php echo $totalPages; ?></a>
                            <?php endif; ?>

                            <!-- Next Page -->
                            <?php if ($page < $totalPages): ?>
                                <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $page + 1; ?>"
                                    class="pagination-link"
                                    title="Next page">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar (visible on larger screens) -->
            <?php if (!empty($companyStats) || !empty($locationStats)): ?>
                <div class="sidebar">
                    <!-- Top Companies -->
                    <?php if (!empty($companyStats)): ?>
                        <div class="sidebar-card">
                            <h3><i class="fas fa-building"></i> Top Companies</h3>
                            <ul class="stat-list">
                                <?php foreach ($companyStats as $company): ?>
                                    <li>
                                        <span class="company-name">
                                            <i class="fas fa-briefcase"></i>
                                            <?php echo htmlspecialchars($company['current_company']); ?>
                                        </span>
                                        <span class="stat-count"><?php echo $company['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Locations -->
                    <?php if (!empty($locationStats)): ?>
                        <div class="sidebar-card">
                            <h3><i class="fas fa-globe"></i> Locations</h3>
                            <ul class="stat-list">
                                <?php foreach ($locationStats as $location): ?>
                                    <li>
                                        <span class="location-name">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($location['country']); ?>
                                        </span>
                                        <span class="stat-count"><?php echo $location['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Quick Tips -->
                    <div class="sidebar-card">
                        <h3><i class="fas fa-lightbulb"></i> Networking Tips</h3>
                        <ul class="stat-list" style="list-style: none;">
                            <li style="display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.75rem 0;">
                                <i class="fas fa-handshake" style="color: var(--primary);"></i>
                                <span>Connect on LinkedIn to grow your network</span>
                            </li>
                            <li style="display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.75rem 0;">
                                <i class="fas fa-comments" style="color: var(--primary);"></i>
                                <span>Start discussions to collaborate</span>
                            </li>
                            <li style="display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.75rem 0;">
                                <i class="fas fa-users" style="color: var(--primary);"></i>
                                <span>Form study groups for better learning</span>
                            </li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <script>
        // Update sort and submit form
        function updateSort(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        }

        // Search with debounce
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 2 || this.value.length === 0) {
                    document.getElementById('searchForm').submit();
                }
            }, 500);
        });

        // Show toast for social links
        function showSocialToast(platform) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
                <i class="fas fa-external-link-alt"></i>
                <div class="toast-content">
                    <div class="toast-title">Opening ${platform}</div>
                    <div class="toast-message">You'll be redirected to ${platform}</div>
                </div>
            `;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }

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
                window.location.href = 'classmates.php?class_id=<?php echo $class_id; ?>';
            }

            // Left/right arrow for pagination
            if (e.key === 'ArrowLeft' && <?php echo $page > 1 ? 'true' : 'false'; ?>) {
                window.location.href = 'classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $page - 1; ?>';
            }
            if (e.key === 'ArrowRight' && <?php echo $page < $totalPages ? 'true' : 'false'; ?>) {
                window.location.href = 'classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $page + 1; ?>';
            }
        });

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .classmate-card, .social-link, .pagination-link, .back-button').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Lazy load images
        if ('IntersectionObserver' in window) {
            const images = document.querySelectorAll('.classmate-avatar img');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src || img.src;
                        imageObserver.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));
        }
    </script>
</body>

</html>