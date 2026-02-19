<?php
// modules/student/assignments/index.php

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
$class_filter = $_GET['class_id'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'due_date_asc';

// Build query for student's assignments
$query = "SELECT 
            a.*,
            c.title as course_title,
            c.course_code,
            cb.batch_code,
            cb.name as class_name,
            cb.program_type,
            p.name as program_name,
            e.status as enrollment_status,
            sfs.is_suspended as financial_suspended,
            asub.id as submission_id,
            asub.submitted_at,
            asub.grade,
            asub.status as submission_status,
            asub.feedback,
            asub.late_submission,
            DATEDIFF(a.due_date, NOW()) as days_remaining,
            CASE 
                WHEN asub.id IS NULL AND a.due_date < NOW() THEN 'overdue'
                WHEN asub.id IS NULL AND a.due_date >= NOW() THEN 'pending'
                WHEN asub.status = 'submitted' THEN 'submitted'
                WHEN asub.status = 'graded' THEN 'graded'
                WHEN asub.status = 'late' THEN 'late_submitted'
                ELSE 'missing'
            END as assignment_status
          FROM enrollments e
          JOIN class_batches cb ON e.class_id = cb.id
          JOIN courses c ON cb.course_id = c.id
          JOIN programs p ON c.program_id = p.id
          LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
          JOIN assignments a ON cb.id = a.class_id
          LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.student_id = e.student_id
          WHERE e.student_id = ?
          AND e.status IN ('active', 'completed')
          AND a.is_published = 1";

$params = [$student_id];
$types = "i";

// Apply status filter
if ($status_filter !== 'all') {
    switch ($status_filter) {
        case 'pending':
            $query .= " AND asub.id IS NULL AND a.due_date >= NOW()";
            break;
        case 'overdue':
            $query .= " AND asub.id IS NULL AND a.due_date < NOW()";
            break;
        case 'submitted':
            $query .= " AND asub.status IN ('submitted', 'late')";
            break;
        case 'graded':
            $query .= " AND asub.status = 'graded'";
            break;
        case 'upcoming_week':
            $query .= " AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
            break;
    }
}

// Apply class filter
if ($class_filter !== 'all' && is_numeric($class_filter)) {
    $query .= " AND cb.id = ?";
    $params[] = $class_filter;
    $types .= "i";
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (a.title LIKE ? OR a.description LIKE ? OR c.title LIKE ? OR cb.name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

// Apply sorting
switch ($sort_by) {
    case 'due_date_asc':
        $query .= " ORDER BY a.due_date ASC";
        break;
    case 'due_date_desc':
        $query .= " ORDER BY a.due_date DESC";
        break;
    case 'created_desc':
        $query .= " ORDER BY a.created_at DESC";
        break;
    case 'course':
        $query .= " ORDER BY c.title, a.due_date";
        break;
    case 'status':
        $query .= " ORDER BY assignment_status, a.due_date";
        break;
    default:
        $query .= " ORDER BY a.due_date ASC";
}

// Get assignments
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);

// Get active classes for filter dropdown
$classes_query = "SELECT DISTINCT cb.id, cb.batch_code, cb.name, c.title as course_title
                 FROM enrollments e
                 JOIN class_batches cb ON e.class_id = cb.id
                 JOIN courses c ON cb.course_id = c.id
                 WHERE e.student_id = ? 
                 AND e.status IN ('active', 'completed')
                 ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'overdue' => 0,
    'submitted' => 0,
    'graded' => 0,
    'upcoming_week' => 0
];

foreach ($assignments as $assignment) {
    $stats['total']++;
    switch ($assignment['assignment_status']) {
        case 'pending':
            $stats['pending']++;
            if ($assignment['days_remaining'] <= 7 && $assignment['days_remaining'] >= 0) {
                $stats['upcoming_week']++;
            }
            break;
        case 'overdue':
            $stats['overdue']++;
            break;
        case 'submitted':
        case 'late_submitted':
            $stats['submitted']++;
            break;
        case 'graded':
            $stats['graded']++;
            break;
    }
}

// Get total average grade if available
$avg_grade_query = "SELECT AVG(asub.grade) as avg_grade, COUNT(*) as graded_count
                    FROM assignment_submissions asub
                    JOIN assignments a ON asub.assignment_id = a.id
                    JOIN enrollments e ON a.class_id = e.class_id
                    WHERE e.student_id = ?
                    AND asub.grade IS NOT NULL
                    AND asub.status = 'graded'";
$stmt = $conn->prepare($avg_grade_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$avg_result = $stmt->get_result();
$avg_row = $avg_result->fetch_assoc();
$average_grade = $avg_row['avg_grade'] ? round($avg_row['avg_grade'], 1) : 'N/A';
$graded_count = $avg_row['graded_count'] ?? 0;

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments - Student Dashboard</title>
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

        /* Stats Cards */
        .stats-grid {
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
            border-top: 4px solid var(--primary);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.pending {
            border-top-color: var(--warning);
        }

        .stat-card.overdue {
            border-top-color: var(--danger);
        }

        .stat-card.submitted {
            border-top-color: var(--info);
        }

        .stat-card.graded {
            border-top-color: var(--success);
        }

        .stat-card.avg-grade {
            border-top-color: #8b5cf6;
        }

        .stat-value {
            font-size: 1.75rem;
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

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-group select,
        .filter-group input {
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding-left: 2.5rem;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .filter-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #e6820b;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
        }

        .btn-outline {
            background: transparent;
            border-color: #e2e8f0;
            color: var(--dark);
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .btn-small {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Assignments Table */
        .assignments-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        tbody tr {
            transition: background-color 0.2s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        tbody tr.overdue {
            background: #fef2f2;
        }

        tbody tr.overdue:hover {
            background: #fee2e2;
        }

        tbody tr.financial-suspended {
            opacity: 0.6;
            background: #fef3c7;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-submitted {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-graded {
            background: #d1fae5;
            color: #065f46;
        }

        .status-late {
            background: #f3f4f6;
            color: #374151;
        }

        /* Grade Display */
        .grade {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .grade-excellent {
            color: var(--success);
        }

        .grade-good {
            color: var(--info);
        }

        .grade-average {
            color: var(--warning);
        }

        .grade-poor {
            color: var(--danger);
        }

        /* Days Remaining */
        .days-remaining {
            font-weight: 600;
        }

        .days-overdue {
            color: var(--danger);
        }

        .days-warning {
            color: var(--warning);
        }

        .days-ok {
            color: var(--success);
        }

        /* Program Type */
        .program-type {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
        }

        .program-type.online {
            background: #dcfce7;
            color: #166534;
        }

        .program-type.onsite {
            background: #fef3c7;
            color: #92400e;
        }

        /* Assignment Title */
        .assignment-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .assignment-course {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .assignment-class {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Due Date */
        .due-date {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .due-date.overdue {
            color: var(--danger);
        }

        /* Actions */
        .action-buttons-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
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
            margin: 0 auto 1.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                justify-content: center;
            }

            th,
            td {
                padding: 0.75rem 0.5rem;
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
            <a href="<?php echo BASE_URL; ?>modules/student/classes/">
                My Classes
            </a>
            <span class="separator">/</span>
            <span>Assignments</span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1>My Assignments</h1>
            <p>Welcome, <?php echo htmlspecialchars($student_name); ?>. View and manage all your course assignments here.</p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card overdue">
                <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
            <div class="stat-card submitted">
                <div class="stat-value"><?php echo $stats['submitted']; ?></div>
                <div class="stat-label">Submitted</div>
            </div>
            <div class="stat-card graded">
                <div class="stat-value"><?php echo $stats['graded']; ?></div>
                <div class="stat-label">Graded</div>
            </div>
            <div class="stat-card avg-grade">
                <div class="stat-value"><?php echo $average_grade; ?></div>
                <div class="stat-label">Avg Grade (<?php echo $graded_count; ?>)</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            <option value="graded" <?php echo $status_filter === 'graded' ? 'selected' : ''; ?>>Graded</option>
                            <option value="upcoming_week" <?php echo $status_filter === 'upcoming_week' ? 'selected' : ''; ?>>Due in Next 7 Days</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="class_id">Class</label>
                        <select name="class_id" id="class_id">
                            <option value="all" <?php echo $class_filter === 'all' ? 'selected' : ''; ?>>All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select name="sort" id="sort">
                            <option value="due_date_asc" <?php echo $sort_by === 'due_date_asc' ? 'selected' : ''; ?>>Due Date (Earliest First)</option>
                            <option value="due_date_desc" <?php echo $sort_by === 'due_date_desc' ? 'selected' : ''; ?>>Due Date (Latest First)</option>
                            <option value="created_desc" <?php echo $sort_by === 'created_desc' ? 'selected' : ''; ?>>Recently Added</option>
                            <option value="course" <?php echo $sort_by === 'course' ? 'selected' : ''; ?>>Course</option>
                            <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="search"
                                placeholder="Search assignments..."
                                value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <div class="action-buttons">
                        <a href="?" class="btn btn-outline">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                        <button type="button" class="btn btn-outline" onclick="window.print()">
                            <i class="fas fa-print"></i> Print List
                        </button>
                        <button type="button" class="btn btn-outline" onclick="exportToCSV()">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Assignments Table -->
        <div class="assignments-table">
            <div class="table-responsive">
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Assignments Found</h3>
                        <p>You don't have any assignments matching your current filters.</p>
                        <a href="?" class="btn btn-primary">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Class</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Grade</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <?php
                                $status_class = '';
                                $row_class = '';
                                if ($assignment['assignment_status'] === 'overdue') {
                                    $status_class = 'status-overdue';
                                    $row_class = 'overdue';
                                } elseif ($assignment['assignment_status'] === 'pending') {
                                    $status_class = 'status-pending';
                                } elseif ($assignment['assignment_status'] === 'submitted' || $assignment['assignment_status'] === 'late_submitted') {
                                    $status_class = 'status-submitted';
                                } elseif ($assignment['assignment_status'] === 'graded') {
                                    $status_class = 'status-graded';
                                }

                                if ($assignment['financial_suspended']) {
                                    $row_class .= ' financial-suspended';
                                }

                                // Grade display
                                $grade_class = '';
                                if ($assignment['grade']) {
                                    if ($assignment['grade'] >= 80) {
                                        $grade_class = 'grade-excellent';
                                    } elseif ($assignment['grade'] >= 70) {
                                        $grade_class = 'grade-good';
                                    } elseif ($assignment['grade'] >= 60) {
                                        $grade_class = 'grade-average';
                                    } else {
                                        $grade_class = 'grade-poor';
                                    }
                                }

                                // Days remaining display
                                $days_class = '';
                                if ($assignment['days_remaining'] < 0) {
                                    $days_class = 'days-overdue';
                                } elseif ($assignment['days_remaining'] <= 2) {
                                    $days_class = 'days-warning';
                                } else {
                                    $days_class = 'days-ok';
                                }
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <div class="assignment-title">
                                            <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/view.php?assignment_id=<?php echo $assignment['id']; ?>">
                                                <?php echo htmlspecialchars($assignment['title']); ?>
                                            </a>
                                            <?php if ($assignment['late_submission']): ?>
                                                <i class="fas fa-clock text-warning" title="Late Submission"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="assignment-course">
                                            <?php echo htmlspecialchars($assignment['course_title']); ?>
                                        </div>
                                        <div class="assignment-class">
                                            <?php echo htmlspecialchars($assignment['batch_code']); ?>
                                        </div>
                                        <?php if ($assignment['financial_suspended']): ?>
                                            <small class="text-danger">
                                                <i class="fas fa-exclamation-triangle"></i> Financial suspension - access restricted
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="program-type <?php echo $assignment['program_type']; ?>">
                                            <i class="fas fa-<?php echo $assignment['program_type'] === 'online' ? 'laptop' : 'building'; ?>"></i>
                                            <?php echo ucfirst($assignment['program_type']); ?>
                                        </div>
                                        <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                                            <?php echo htmlspecialchars($assignment['class_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="due-date <?php echo $assignment['days_remaining'] < 0 ? 'overdue' : ''; ?>">
                                            <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                        </div>
                                        <div class="days-remaining <?php echo $days_class; ?>">
                                            <?php if ($assignment['days_remaining'] < 0): ?>
                                                <?php echo abs($assignment['days_remaining']); ?> days overdue
                                            <?php elseif ($assignment['days_remaining'] == 0): ?>
                                                Due today
                                            <?php else: ?>
                                                <?php echo $assignment['days_remaining']; ?> days remaining
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($assignment['submitted_at']): ?>
                                            <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                                                Submitted: <?php echo date('M d, Y', strtotime($assignment['submitted_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php
                                            switch ($assignment['assignment_status']) {
                                                case 'pending':
                                                    echo 'Pending';
                                                    break;
                                                case 'overdue':
                                                    echo 'Overdue';
                                                    break;
                                                case 'submitted':
                                                    echo 'Submitted';
                                                    break;
                                                case 'late_submitted':
                                                    echo 'Late Submitted';
                                                    break;
                                                case 'graded':
                                                    echo 'Graded';
                                                    break;
                                            }
                                            ?>
                                        </span>
                                        <?php if ($assignment['total_points']): ?>
                                            <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                                                Max: <?php echo $assignment['total_points']; ?> pts
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($assignment['grade'] !== null): ?>
                                            <div class="grade <?php echo $grade_class; ?>">
                                                <?php echo $assignment['grade']; ?>
                                            </div>
                                            <?php if ($assignment['feedback']): ?>
                                                <button type="button" class="btn-small btn-outline"
                                                    onclick="showFeedback(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars(addslashes($assignment['feedback'])); ?>')">
                                                    View Feedback
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($assignment['submission_id']): ?>
                                            <span class="status-badge status-submitted">
                                                Awaiting Grade
                                            </span>
                                        <?php else: ?>
                                            <span>—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-cell">
                                            <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/view.php?assignment_id=<?php echo $assignment['id']; ?>"
                                                class="btn btn-small btn-secondary">
                                                <i class="fas fa-eye"></i> View
                                            </a>

                                            <?php if (!$assignment['submission_id'] && $assignment['assignment_status'] !== 'graded' && !$assignment['financial_suspended']): ?>
                                                <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/submit.php?assignment_id=<?php echo $assignment['id']; ?>"
                                                    class="btn btn-small <?php echo $assignment['assignment_status'] === 'overdue' ? 'btn-danger' : 'btn-primary'; ?>">
                                                    <i class="fas fa-paper-plane"></i>
                                                    <?php echo $assignment['assignment_status'] === 'overdue' ? 'Submit Late' : 'Submit'; ?>
                                                </a>
                                            <?php elseif ($assignment['submission_id'] && $assignment['grade'] === null): ?>
                                                <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/submit.php?assignment_id=<?php echo $assignment['id']; ?>"
                                                    class="btn btn-small btn-warning">
                                                    <i class="fas fa-edit"></i> Resubmit
                                                </a>
                                            <?php elseif ($assignment['financial_suspended']): ?>
                                                <button class="btn btn-small btn-danger" disabled title="Access restricted due to financial suspension">
                                                    <i class="fas fa-ban"></i> Restricted
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Tips -->
        <div style="background: white; border-radius: 10px; padding: 1.5rem; margin-top: 2rem; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);">
            <h3 style="margin-bottom: 1rem; color: var(--dark);">
                <i class="fas fa-lightbulb text-warning"></i> Assignment Tips
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                <div style="padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.875rem;">
                        <i class="fas fa-clock text-danger"></i> Overdue Assignments
                    </h4>
                    <p style="font-size: 0.875rem; color: var(--gray); margin: 0;">
                        Submit overdue assignments as soon as possible. Late submissions may incur grade penalties.
                    </p>
                </div>
                <div style="padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.875rem;">
                        <i class="fas fa-file-upload text-primary"></i> Submission Types
                    </h4>
                    <p style="font-size: 0.875rem; color: var(--gray); margin: 0;">
                        Check assignment instructions for allowed file types. Most accept PDF, DOC, and ZIP files.
                    </p>
                </div>
                <div style="padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.875rem;">
                        <i class="fas fa-exclamation-triangle text-warning"></i> Financial Suspension
                    </h4>
                    <p style="font-size: 0.875rem; color: var(--gray); margin: 0;">
                        If you see "Restricted" buttons, clear your financial suspension to access assignments.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Feedback Modal Script -->
    <script>
        function showFeedback(assignmentId, feedback) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
            `;

            modal.innerHTML = `
                <div style="background: white; border-radius: 10px; padding: 2rem; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="margin: 0; color: var(--dark);">
                            <i class="fas fa-comment-alt"></i> Instructor Feedback
                        </h3>
                        <button onclick="this.closest('div[style*=\"position: fixed\"]').remove()" 
                                style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray);">
                            &times;
                        </button>
                    </div>
                    <div style="margin-bottom: 1.5rem;">
                        <p style="white-space: pre-wrap; line-height: 1.6; color: var(--dark);">${feedback}</p>
                    </div>
                    <div style="text-align: right;">
                        <button onclick="this.closest('div[style*=\"position: fixed\"]').remove()" 
                                style="padding: 0.5rem 1.5rem; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer;">
                            Close
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        }

        // Export to CSV function
        function exportToCSV() {
            const rows = [];
            const headers = ['Assignment', 'Course', 'Class', 'Due Date', 'Status', 'Grade', 'Submitted Date'];

            <?php foreach ($assignments as $assignment): ?>
                rows.push([
                    '<?php echo addslashes($assignment['title']); ?>',
                    '<?php echo addslashes($assignment['course_title']); ?>',
                    '<?php echo addslashes($assignment['batch_code']); ?>',
                    '<?php echo date('Y-m-d', strtotime($assignment['due_date'])); ?>',
                    '<?php
                        switch ($assignment['assignment_status']) {
                            case 'pending':
                                echo 'Pending';
                                break;
                            case 'overdue':
                                echo 'Overdue';
                                break;
                            case 'submitted':
                                echo 'Submitted';
                                break;
                            case 'late_submitted':
                                echo 'Late Submitted';
                                break;
                            case 'graded':
                                echo 'Graded';
                                break;
                        }
                        ?>',
                    '<?php echo $assignment['grade'] ?? ''; ?>',
                    '<?php echo $assignment['submitted_at'] ? date('Y-m-d', strtotime($assignment['submitted_at'])) : ''; ?>'
                ]);
            <?php endforeach; ?>

            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += headers.join(",") + "\r\n";
            rows.forEach(row => {
                csvContent += row.map(cell => `"${cell}"`).join(",") + "\r\n";
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "assignments_<?php echo date('Y-m-d'); ?>.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Auto-refresh status for pending assignments
        function refreshStatus() {
            const now = new Date();
            document.querySelectorAll('.days-remaining').forEach(el => {
                const text = el.textContent.trim();
                if (text.includes('days remaining')) {
                    const days = parseInt(text);
                    if (days <= 1) {
                        el.className = 'days-remaining days-warning';
                        el.textContent = days === 1 ? '1 day remaining' : 'Due today';
                    }
                }
            });
        }

        // Refresh every minute
        setInterval(refreshStatus, 60000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
            }
        });
    </script>
</body>

</html>