<?php
// modules/instructor/classes/index.php

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
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Handle filters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query
$query = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name,
                 (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = cb.id AND e.status = 'active') as student_count,
                 (SELECT COUNT(*) FROM assignments a WHERE a.class_id = cb.id) as assignment_count,
                 (SELECT COUNT(*) FROM materials m WHERE m.class_id = cb.id) as material_count
          FROM class_batches cb 
          JOIN courses c ON cb.course_id = c.id 
          JOIN programs p ON c.program_id = p.id 
          WHERE cb.instructor_id = ?";

$params = [$instructor_id];
$types = "i";

// Apply status filter
if ($status_filter !== 'all' && in_array($status_filter, ['scheduled', 'ongoing', 'completed', 'cancelled'])) {
    $query .= " AND cb.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (cb.batch_code LIKE ? OR cb.name LIKE ? OR c.title LIKE ? OR p.name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$query .= " ORDER BY 
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

// Get statistics
$stats = [
    'total' => 0,
    'scheduled' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'cancelled' => 0
];

$stat_sql = "SELECT status, COUNT(*) as count 
             FROM class_batches 
             WHERE instructor_id = ? 
             GROUP BY status";
$stmt = $conn->prepare($stat_sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$stat_result = $stmt->get_result();
while ($row = $stat_result->fetch_assoc()) {
    $stats[$row['status']] = $row['count'];
    $stats['total'] += $row['count'];
}

$stmt->close();

// Log activity
logActivity('view_classes', 'Instructor viewed classes list');
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
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

        .stat-card.ongoing {
            border-top-color: var(--success);
        }

        .stat-card.scheduled {
            border-top-color: var(--warning);
        }

        .stat-card.completed {
            border-top-color: var(--gray);
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
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
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

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
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

        .class-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
            justify-content: flex-end;
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

        .btn-view {
            background: var(--primary);
            color: white;
        }

        .btn-view:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-enter {
            background: white;
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-enter:hover {
            background: var(--primary);
            color: white;
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
            <span>My Classes</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1>My Teaching Classes</h1>
            <p>Welcome, <?php echo htmlspecialchars($instructor_name); ?>. Manage your assigned classes here.</p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card ongoing">
                <div class="stat-value"><?php echo $stats['ongoing']; ?></div>
                <div class="stat-label">Currently Teaching</div>
            </div>
            <div class="stat-card scheduled">
                <div class="stat-value"><?php echo $stats['scheduled']; ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-value"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" id="searchForm">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search classes by code, name, or course..."
                        value="<?php echo htmlspecialchars($search_term); ?>">
                </div>

                <div class="filter-buttons">
                    <a href="?status=all" class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                        All Classes
                    </a>
                    <a href="?status=scheduled" class="filter-btn <?php echo $status_filter === 'scheduled' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Scheduled
                    </a>
                    <a href="?status=ongoing" class="filter-btn <?php echo $status_filter === 'ongoing' ? 'active' : ''; ?>">
                        <i class="fas fa-chalkboard-teacher"></i> Ongoing
                    </a>
                    <a href="?status=completed" class="filter-btn <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> Completed
                    </a>
                    <a href="?status=cancelled" class="filter-btn <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i> Cancelled
                    </a>
                </div>
            </form>
        </div>

        <!-- Classes Grid -->
        <div class="classes-grid">
            <?php if (empty($classes)): ?>
                <div class="empty-state">
                    <i class="fas fa-chalkboard"></i>
                    <h3>No Classes Assigned</h3>
                    <p>You don't have any classes assigned to you yet. Contact the administrator if you believe this is an error.</p>
                </div>
            <?php else: ?>
                <?php foreach ($classes as $class): ?>
                    <div class="class-card">
                        <div class="class-header">
                            <span class="status-badge status-<?php echo $class['status']; ?>">
                                <?php echo ucfirst($class['status']); ?>
                            </span>
                            <div class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                            <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
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
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo htmlspecialchars($class['schedule'] ?? 'Schedule not set'); ?></span>
                                </div>
                                <?php if (!empty($class['meeting_link'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-video"></i>
                                        <span><a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" style="color: var(--primary);">Online Class Link</a></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="class-stats">
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['student_count']; ?></span>
                                    <span class="stat-label">Students</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['assignment_count']; ?></span>
                                    <span class="stat-label">Assignments</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $class['material_count']; ?></span>
                                    <span class="stat-label">Materials</span>
                                </div>
                            </div>
                        </div>

                        <div class="class-footer">
                            <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-enter">
                                <i class="fas fa-door-open"></i> Enter Class
                            </a>
                            <a href="class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-view">
                                <i class="fas fa-eye"></i> View Details
                            </a>
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
                searchInput.focus();
            }

            // Esc to clear search
            if (e.key === 'Escape' && searchInput.value) {
                clearSearch();
            }
        });
    </script>
</body>

</html>