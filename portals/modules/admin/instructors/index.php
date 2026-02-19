<?php
// modules/admin/instructors/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get filter parameters
$status = $_GET['status'] ?? 'active';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'desc';
$department = $_GET['department'] ?? 'all';

// Validate sort and order
$valid_sorts = ['id', 'first_name', 'last_name', 'email', 'created_at', 'last_login'];
$valid_orders = ['asc', 'desc'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'created_at';
$order = in_array($order, $valid_orders) ? $order : 'desc';

// Build query with filters
$query = "SELECT u.*, 
                 up.current_job_title, 
                 up.current_company,
                 up.qualifications,
                 up.experience_years,
                 COUNT(DISTINCT cb.id) as active_classes_count,
                 COUNT(DISTINCT a.id) as assignments_count,
                 COUNT(DISTINCT m.id) as materials_count
          FROM users u
          LEFT JOIN user_profiles up ON u.id = up.user_id
          LEFT JOIN class_batches cb ON u.id = cb.instructor_id AND cb.status = 'ongoing'
          LEFT JOIN assignments a ON u.id = a.instructor_id
          LEFT JOIN materials m ON u.id = m.instructor_id
          WHERE u.role = 'instructor'";

$params = [];
$types = '';

// Apply status filter
if ($status !== 'all') {
    $query .= " AND u.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Apply search filter
if ($search) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR up.current_job_title LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

// Group by and order
$query .= " GROUP BY u.id ORDER BY u.$sort $order";

// Get total count for pagination
$count_query = str_replace(
    'u.*, up.current_job_title, up.current_company, up.qualifications, up.experience_years, COUNT(DISTINCT cb.id) as active_classes_count, COUNT(DISTINCT a.id) as assignments_count, COUNT(DISTINCT m.id) as materials_count',
    'COUNT(DISTINCT u.id) as total',
    $query
);

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_instructors = $count_result->fetch_assoc()['total'] ?? 0;

// Pagination
$per_page = 20;
$total_pages = ceil($total_instructors / $per_page);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute main query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$instructors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                AVG(up.experience_years) as avg_experience
                FROM users u
                LEFT JOIN user_profiles up ON u.id = up.user_id
                WHERE u.role = 'instructor'";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get departments/specialties for filter
$dept_query = "SELECT DISTINCT up.current_job_title as department 
               FROM users u 
               LEFT JOIN user_profiles up ON u.id = up.user_id 
               WHERE u.role = 'instructor' AND up.current_job_title IS NOT NULL 
               ORDER BY up.current_job_title";
$dept_result = $conn->query($dept_query);
$departments = $dept_result->fetch_all(MYSQLI_ASSOC);

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids)) {
        $success_count = 0;
        $error_count = 0;
        
        foreach ($selected_ids as $id) {
            $id = (int)$id;
            
            switch ($bulk_action) {
                case 'activate':
                    $result = updateUserStatus($id, 'active');
                    break;
                case 'suspend':
                    $result = updateUserStatus($id, 'suspended');
                    break;
                case 'delete':
                    $result = deleteInstructor($id);
                    break;
                default:
                    continue 2;
            }
            
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully processed $success_count instructor(s)";
        }
        if ($error_count > 0) {
            $_SESSION['error'] = "Failed to process $error_count instructor(s)";
        }
        
        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Function to update user status
function updateUserStatus($id, $status) {
    global $conn;
    
    $sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND role = 'instructor'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $id);
    
    if ($stmt->execute()) {
        logActivity('instructor_status_update', "Updated instructor #$id status to $status", 'users', $id);
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => 'Database error'];
}

// Function to delete instructor
function deleteInstructor($id) {
    global $conn;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if instructor has related records
        $check_sql = "SELECT 
                     (SELECT COUNT(*) FROM class_batches WHERE instructor_id = ?) as class_count,
                     (SELECT COUNT(*) FROM assignments WHERE instructor_id = ?) as assignment_count";
        
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $id, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $counts = $check_result->fetch_assoc();
        
        if ($counts['class_count'] > 0 || $counts['assignment_count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete instructor with active classes or assignments'];
        }
        
        // Delete instructor profile
        $delete_profile_sql = "DELETE FROM user_profiles WHERE user_id = ?";
        $delete_profile_stmt = $conn->prepare($delete_profile_sql);
        $delete_profile_stmt->bind_param("i", $id);
        $delete_profile_stmt->execute();
        
        // Delete instructor user
        $delete_user_sql = "DELETE FROM users WHERE id = ? AND role = 'instructor'";
        $delete_user_stmt = $conn->prepare($delete_user_sql);
        $delete_user_stmt->bind_param("i", $id);
        $delete_user_stmt->execute();
        
        $conn->commit();
        
        logActivity('instructor_delete', "Deleted instructor #$id", 'users', $id);
        return ['success' => true];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Log activity
logActivity('view_instructors', "Viewed instructors list with filters");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
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
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
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

        .stats-cards {
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
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .filters-card {
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
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .instructor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .instructor-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .instructor-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            position: relative;
        }

        .instructor-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active { background: rgba(255, 255, 255, 0.2); }
        .status-pending { background: rgba(245, 158, 11, 0.2); }
        .status-suspended { background: rgba(239, 68, 68, 0.2); }

        .instructor-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .instructor-content {
            padding: 1.5rem;
        }

        .instructor-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
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
            .instructor-grid {
                grid-template-columns: 1fr;
            }
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <span>Instructors</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Manage Instructors</h1>
            <div class="page-actions">
                <a href="assign.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Assign Instructor
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/" class="btn btn-secondary">
                    <i class="fas fa-chalkboard"></i> Manage Classes
                </a>
            </div>
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

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total" onclick="window.location.href='?status=all'">
                <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total Instructors</div>
            </div>
            <div class="stat-card active" onclick="window.location.href='?status=active'">
                <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card pending" onclick="window.location.href='?status=pending'">
                <div class="stat-value"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card suspended" onclick="window.location.href='?status=suspended'">
                <div class="stat-value"><?php echo number_format($stats['suspended'] ?? 0); ?></div>
                <div class="stat-label">Suspended</div>
            </div>
            <div class="stat-card experience">
                <div class="stat-value"><?php echo number_format($stats['avg_experience'] ?? 0, 1); ?></div>
                <div class="stat-label">Avg. Experience (Years)</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3>Filter Instructors</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset Filters
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="department">Department/Specialty</label>
                        <select id="department" name="department" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $department === 'all' ? 'selected' : ''; ?>>All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['department']); ?>"
                                    <?php echo $department === $dept['department'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort" class="form-control" onchange="this.form.submit()">
                            <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            <option value="last_name" <?php echo $sort === 'last_name' ? 'selected' : ''; ?>>Last Name</option>
                            <option value="first_name" <?php echo $sort === 'first_name' ? 'selected' : ''; ?>>First Name</option>
                            <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Last Login</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" class="form-control"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, email, or specialty...">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Instructors Grid -->
        <div class="instructor-grid">
            <?php if (empty($instructors)): ?>
                <div class="empty-state" style="grid-column: 1 / -1; text-align: center; padding: 3rem;">
                    <i class="fas fa-chalkboard-teacher" style="font-size: 3rem; color: var(--gray); margin-bottom: 1rem;"></i>
                    <h3>No instructors found</h3>
                    <p>No instructors match your current filters.</p>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="margin-top: 1rem;">
                        Reset Filters
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($instructors as $instructor): ?>
                    <div class="instructor-card">
                        <div class="instructor-header">
                            <div class="instructor-status status-<?php echo $instructor['status']; ?>">
                                <?php echo ucfirst($instructor['status']); ?>
                            </div>
                            <div class="instructor-avatar">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <h3 class="instructor-name">
                                <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                            </h3>
                            <div class="instructor-email"><?php echo htmlspecialchars($instructor['email']); ?></div>
                        </div>
                        
                        <div class="instructor-content">
                            <?php if ($instructor['current_job_title']): ?>
                                <div class="instructor-specialty" style="margin-bottom: 1rem;">
                                    <strong><?php echo htmlspecialchars($instructor['current_job_title']); ?></strong>
                                    <?php if ($instructor['current_company']): ?>
                                        <div style="font-size: 0.9rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($instructor['current_company']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($instructor['qualifications']): ?>
                                <div class="instructor-qualifications" style="margin-bottom: 1rem; font-size: 0.9rem;">
                                    <i class="fas fa-graduation-cap" style="color: var(--primary);"></i>
                                    <?php echo htmlspecialchars(substr($instructor['qualifications'], 0, 100)); ?>
                                    <?php if (strlen($instructor['qualifications']) > 100): ?>...<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="instructor-stats">
                                <div class="stat-item">
                                    <div style="font-weight: 600; color: var(--primary); font-size: 1.2rem;">
                                        <?php echo $instructor['active_classes_count'] ?: '0'; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray);">Active Classes</div>
                                </div>
                                <div class="stat-item">
                                    <div style="font-weight: 600; color: var(--success); font-size: 1.2rem;">
                                        <?php echo $instructor['assignments_count'] ?: '0'; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray);">Assignments</div>
                                </div>
                                <div class="stat-item">
                                    <div style="font-weight: 600; color: var(--info); font-size: 1.2rem;">
                                        <?php echo $instructor['experience_years'] ?: '0'; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray);">Years Exp</div>
                                </div>
                            </div>
                            
                            <div class="instructor-actions" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                <a href="view.php?id=<?php echo $instructor['id']; ?>" class="btn btn-primary" style="flex: 1; padding: 0.5rem;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="assign.php?id=<?php echo $instructor['id']; ?>" class="btn btn-secondary" style="flex: 1; padding: 0.5rem;">
                                    <i class="fas fa-tasks"></i> Assign
                                </a>
                                <?php if ($instructor['status'] === 'active'): ?>
                                    <a href="?action=suspend&id=<?php echo $instructor['id']; ?>" 
                                       class="btn btn-warning"
                                       onclick="return confirm('Suspend this instructor?')" style="padding: 0.5rem;">
                                        <i class="fas fa-pause"></i>
                                    </a>
                                <?php elseif ($instructor['status'] === 'suspended'): ?>
                                    <a href="?action=activate&id=<?php echo $instructor['id']; ?>" 
                                       class="btn btn-success"
                                       onclick="return confirm('Activate this instructor?')" style="padding: 0.5rem;">
                                        <i class="fas fa-play"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="btn btn-secondary">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-secondary">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <span style="margin: 0 1rem; color: var(--gray);">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </span>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-secondary">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="btn btn-secondary">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function resetFilters() {
            window.location.href = 'index.php';
        }

        // Auto-submit filters on search after delay
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });
    </script>
</body>
</html>