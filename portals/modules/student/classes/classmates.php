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
$limit = 20;
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

// Get total pages
$totalPages = ceil($totalClassmates / $limit);

// Log activity
logActivity('view_classmates', "Viewed classmates for class: {$class['batch_code']}", 'class_batches', $class_id);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Classmates</title>
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
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .sort-select {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            font-size: 0.875rem;
            color: var(--dark);
            cursor: pointer;
        }

        .sort-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Classmates Grid */
        .classmates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .classmate-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .classmate-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .classmate-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .classmate-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.75rem;
            flex-shrink: 0;
        }

        .classmate-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .classmate-name {
            flex: 1;
        }

        .classmate-name h3 {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .classmate-email {
            color: var(--gray);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            word-break: break-all;
        }

        .classmate-info {
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--dark);
        }

        .info-item i {
            color: var(--primary);
            width: 16px;
        }

        .info-label {
            font-weight: 500;
            min-width: 100px;
            color: var(--gray);
        }

        .classmate-bio {
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--dark);
            line-height: 1.6;
        }

        .classmate-social {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .social-link {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f1f5f9;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
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

        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin: 2rem 0;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            max-width: 400px;
            margin: 0 auto 1.5rem;
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

        /* Stats */
        .stats {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .classmates-grid {
                grid-template-columns: 1fr;
            }

            .search-input {
                min-width: 100%;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .filter-options {
                width: 100%;
                justify-content: space-between;
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
            <span>Classmates</span>
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
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
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
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
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
                    <i class="fas fa-users"></i>
                    Classmates (<?php echo $totalClassmates; ?>)
                </h2>
                <p>Connect with your fellow students in <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="stats">
                <span><i class="fas fa-user-graduate"></i> Total: <?php echo $totalClassmates; ?></span>
                <?php if ($totalClassmates > 0): ?>
                    <span><i class="fas fa-chart-pie"></i> Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <form method="GET" action="" class="search-form">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <input type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search by name, email, company, or job title..."
                    value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if (!empty($search)): ?>
                    <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <div class="filter-options">
                <label for="sort">Sort by:</label>
                <select id="sort" name="sort" class="sort-select" onchange="window.location.href='classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=' + this.value">
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                    <option value="enrollment_date" <?php echo $sort === 'enrollment_date' ? 'selected' : ''; ?>>Enrollment Date</option>
                    <option value="company" <?php echo $sort === 'company' ? 'selected' : ''; ?>>Company</option>
                </select>

                <div class="stats">
                    <?php if (!empty($search)): ?>
                        <span><i class="fas fa-filter"></i> <?php echo $totalClassmates; ?> result(s) found</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Classmates Grid -->
        <?php if (empty($classmates)): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <?php if (!empty($search)): ?>
                    <h3>No classmates found</h3>
                    <p>No results found for "<?php echo htmlspecialchars($search); ?>". Try a different search term.</p>
                    <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                        <i class="fas fa-times"></i> Clear Search
                    </a>
                <?php else: ?>
                    <h3>No classmates yet</h3>
                    <p>You are currently the only active student in this class.</p>
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
                                    <?php echo strtoupper(substr($classmate['first_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="classmate-name">
                                <h3><?php echo htmlspecialchars($classmate['first_name'] . ' ' . $classmate['last_name']); ?></h3>
                                <div class="classmate-email">
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($classmate['email']); ?>
                                </div>
                                <?php if ($classmate['enrollment_status'] === 'completed'): ?>
                                    <span style="background: var(--success); color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                        <i class="fas fa-graduation-cap"></i> Completed
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="classmate-info">
                            <?php if (!empty($classmate['current_job_title']) || !empty($classmate['current_company'])): ?>
                                <div class="info-item">
                                    <i class="fas fa-briefcase"></i>
                                    <span class="info-label">Work:</span>
                                    <span>
                                        <?php if (!empty($classmate['current_job_title'])): ?>
                                            <?php echo htmlspecialchars($classmate['current_job_title']); ?>
                                            <?php if (!empty($classmate['current_company'])): ?>
                                                at <?php echo htmlspecialchars($classmate['current_company']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($classmate['current_company']); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($classmate['city']) || !empty($classmate['country'])): ?>
                                <div class="info-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="info-label">Location:</span>
                                    <span>
                                        <?php
                                        $locationParts = [];
                                        if (!empty($classmate['city'])) $locationParts[] = $classmate['city'];
                                        if (!empty($classmate['state'])) $locationParts[] = $classmate['state'];
                                        if (!empty($classmate['country'])) $locationParts[] = $classmate['country'];
                                        echo htmlspecialchars(implode(', ', $locationParts));
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($classmate['experience_years']) && $classmate['experience_years'] > 0): ?>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span class="info-label">Experience:</span>
                                    <span><?php echo $classmate['experience_years']; ?> year(s)</span>
                                </div>
                            <?php endif; ?>

                            <div class="info-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span class="info-label">Enrolled:</span>
                                <span><?php echo date('M d, Y', strtotime($classmate['enrollment_date'])); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($classmate['bio'])): ?>
                            <div class="classmate-bio">
                                <strong><i class="fas fa-quote-left"></i> About:</strong>
                                <?php echo nl2br(htmlspecialchars(substr($classmate['bio'], 0, 200))); ?>
                                <?php if (strlen($classmate['bio']) > 200): ?>...<?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="classmate-social">
                            <?php if (!empty($classmate['linkedin_url'])): ?>
                                <a href="<?php echo htmlspecialchars($classmate['linkedin_url']); ?>"
                                    target="_blank"
                                    class="social-link"
                                    title="LinkedIn">
                                    <i class="fab fa-linkedin"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($classmate['github_url'])): ?>
                                <a href="<?php echo htmlspecialchars($classmate['github_url']); ?>"
                                    target="_blank"
                                    class="social-link"
                                    title="GitHub">
                                    <i class="fab fa-github"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($classmate['website'])): ?>
                                <a href="<?php echo htmlspecialchars($classmate['website']); ?>"
                                    target="_blank"
                                    class="social-link"
                                    title="Website">
                                    <i class="fas fa-globe"></i>
                                </a>
                            <?php endif; ?>
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
                            class="pagination-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled">
                            <i class="fas fa-chevron-left"></i> Previous
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
                            <span class="pagination-link">...</span>
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
                            <span class="pagination-link">...</span>
                        <?php endif; ?>
                        <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $totalPages; ?>"
                            class="pagination-link"><?php echo $totalPages; ?></a>
                    <?php endif; ?>

                    <!-- Next Page -->
                    <?php if ($page < $totalPages): ?>
                        <a href="classmates.php?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&page=<?php echo $page + 1; ?>"
                            class="pagination-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled">
                            Next <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Back Button -->
        <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Class Dashboard
        </a>
    </div>

    <script>
        // Search with Enter key
        document.querySelector('.search-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });

        // Tooltip for social links
        document.querySelectorAll('.social-link').forEach(link => {
            link.addEventListener('mouseenter', function() {
                const title = this.getAttribute('title');
                if (title) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = title;
                    tooltip.style.cssText = `
                        position: absolute;
                        background: var(--dark);
                        color: white;
                        padding: 0.25rem 0.5rem;
                        border-radius: 4px;
                        font-size: 0.75rem;
                        white-space: nowrap;
                        z-index: 1000;
                        transform: translateY(-100%) translateX(-50%);
                        left: 50%;
                        top: -5px;
                    `;
                    this.appendChild(tooltip);
                }
            });

            link.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.tooltip');
                if (tooltip) {
                    tooltip.remove();
                }
            });
        });

        // Export classmates list (basic implementation)
        function exportClassmates() {
            // This is a basic implementation - you might want to implement server-side export
            alert('Export feature would generate a CSV file of all classmates.');
            // In a real implementation, this would make an AJAX request to an export endpoint
        }
    </script>
</body>

</html>