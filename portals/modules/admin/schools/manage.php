<?php
// modules/admin/schools/manage.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_schools'])) {
        $school_ids = $_POST['selected_schools'];
        $bulk_action = $_POST['bulk_action'];
        
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
        } else {
            $ids = implode(',', array_map('intval', $school_ids));
            
            switch ($bulk_action) {
                case 'activate':
                    $sql = "UPDATE schools SET partnership_status = 'active' WHERE id IN ($ids)";
                    $action = 'activated';
                    break;
                case 'deactivate':
                    $sql = "UPDATE schools SET partnership_status = 'pending' WHERE id IN ($ids)";
                    $action = 'deactivated';
                    break;
                case 'terminate':
                    $sql = "UPDATE schools SET partnership_status = 'terminated' WHERE id IN ($ids)";
                    $action = 'terminated';
                    break;
                case 'delete':
                    // Check if schools have associated programs before deleting
                    $check_sql = "SELECT COUNT(*) as count FROM programs WHERE school_id IN ($ids)";
                    $result = $conn->query($check_sql);
                    $row = $result->fetch_assoc();
                    
                    if ($row['count'] > 0) {
                        $_SESSION['error'] = 'Cannot delete schools that have associated programs. Please reassign programs first.';
                    } else {
                        $sql = "DELETE FROM schools WHERE id IN ($ids)";
                        $action = 'deleted';
                    }
                    break;
                default:
                    $sql = null;
            }
            
            if ($sql && $conn->query($sql)) {
                $_SESSION['success'] = 'Successfully ' . $action . ' ' . count($school_ids) . ' school(s)';
                
                // Log activity
                logActivity('school_bulk_' . $bulk_action, "Bulk $action " . count($school_ids) . " schools", 'schools');
            }
        }
        header('Location: manage.php');
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'desc';

// Build query
$sql = "SELECT s.*, 
               COUNT(DISTINCT p.id) as program_count,
               COUNT(DISTINCT u.id) as user_count
        FROM schools s
        LEFT JOIN programs p ON s.id = p.school_id
        LEFT JOIN users u ON s.id = u.school_id";
        
$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(s.name LIKE ? OR s.contact_person LIKE ? OR s.contact_email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if (!empty($status) && $status !== 'all') {
    $where[] = "s.partnership_status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " GROUP BY s.id";

// Validate sort column
$allowed_sorts = ['name', 'partnership_status', 'program_count', 'created_at', 'partnership_start_date'];
$sort = in_array($sort, $allowed_sorts) ? $sort : 'created_at';
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
$sql .= " ORDER BY $sort $order";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_sql = "SELECT COUNT(DISTINCT s.id) as total FROM schools s";
if (!empty($where)) {
    $count_sql .= " WHERE " . implode(' AND ', $where);
}

// Execute count query without parameters if none exist
if (empty($params)) {
    $count_result = $conn->query($count_sql);
    $total_rows = $count_result->fetch_assoc()['total'];
} else {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_rows = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
}

$total_pages = ceil($total_rows / $limit);

// Add pagination to main query
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute main query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$schools = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats_sql = "SELECT 
    partnership_status,
    COUNT(*) as count
    FROM schools 
    GROUP BY partnership_status";
$stats_result = $conn->query($stats_sql);
$statistics = [];
while ($row = $stats_result->fetch_assoc()) {
    $statistics[$row['partnership_status']] = $row['count'];
}

// Log activity
logActivity('schools_view', 'Viewed schools management page', 'schools');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schools - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    
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

        /* Header */
        .header {
            margin-bottom: 2rem;
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

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card.active {
            border-left: 4px solid var(--success);
        }

        .stat-card.pending {
            border-left: 4px solid var(--warning);
        }

        .stat-card.expired {
            border-left: 4px solid var(--danger);
        }

        .stat-card.terminated {
            border-left: 4px solid var(--gray);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--dark);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .filter-select,
        .filter-input {
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .filter-input {
            min-width: 250px;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--light);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--light-gray);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        tr:hover {
            background: #f8fafc;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-terminated {
            background: #e5e7eb;
            color: #374151;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            text-decoration: none;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: var(--light);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .bulk-select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-actions-form {
            display: flex;
            align-items: center;
            gap: 1rem;
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
            border: 1px solid var(--light-gray);
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            background: white;
        }

        .page-link:hover {
            background: var(--light);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-input {
                min-width: auto;
                width: 100%;
            }

            table {
                display: block;
                overflow-x: auto;
            }

            .action-buttons {
                flex-direction: column;
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
            <span>Schools</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Manage Partner Schools</h1>
            <div class="page-actions">
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New School
                </a>
                <a href="export.php" class="btn btn-secondary">
                    <i class="fas fa-download"></i> Export
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

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card active">
                <div class="stat-number"><?php echo $statistics['active'] ?? 0; ?></div>
                <div class="stat-label">Active Schools</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-number"><?php echo $statistics['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending Schools</div>
            </div>
            <div class="stat-card expired">
                <div class="stat-number"><?php echo $statistics['expired'] ?? 0; ?></div>
                <div class="stat-label">Expired Schools</div>
            </div>
            <div class="stat-card terminated">
                <div class="stat-number"><?php echo $statistics['terminated'] ?? 0; ?></div>
                <div class="stat-label">Terminated Schools</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <span class="filter-label">Search:</span>
                    <input type="text" 
                           name="search" 
                           class="filter-input" 
                           placeholder="Search by name, contact person or email..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-group">
                    <span class="filter-label">Status:</span>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $status === 'all' || $status === '' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="terminated" <?php echo $status === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                
                <?php if (!empty($search) || !empty($status)): ?>
                    <a href="manage.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bulk Actions Form -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="bulk-actions">
                <div class="bulk-select-all">
                    <input type="checkbox" id="selectAll">
                    <label for="selectAll">Select All</label>
                </div>
                
                <div class="bulk-actions-form">
                    <select name="bulk_action" class="filter-select" required>
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Set as Pending</option>
                        <option value="terminate">Terminate</option>
                        <option value="delete">Delete</option>
                    </select>
                    
                    <button type="submit" class="btn btn-secondary" onclick="return confirmBulkAction()">
                        <i class="fas fa-play"></i> Apply
                    </button>
                    
                    <span class="filter-label" id="selectedCount">0 schools selected</span>
                </div>
            </div>

            <!-- Schools Table -->
            <div class="table-container">
                <?php if (empty($schools)): ?>
                    <div class="empty-state">
                        <i class="fas fa-school"></i>
                        <h3>No schools found</h3>
                        <p>Try adjusting your search or filter criteria</p>
                        <a href="create.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus"></i> Add First School
                        </a>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="checkbox-cell">
                                    <input type="checkbox" id="toggleAll">
                                </th>
                                <th>
                                    <a href="?<?php echo buildSortUrl('name'); ?>" class="sort-link">
                                        School Name
                                        <?php echo getSortIcon('name', $sort, $order); ?>
                                    </a>
                                </th>
                                <th>Short Name</th>
                                <th>Contact Person</th>
                                <th>Location</th>
                                <th>
                                    <a href="?<?php echo buildSortUrl('partnership_status'); ?>" class="sort-link">
                                        Status
                                        <?php echo getSortIcon('partnership_status', $sort, $order); ?>
                                    </a>
                                </th>
                                <th>Programs</th>
                                <th>Users</th>
                                <th>
                                    <a href="?<?php echo buildSortUrl('partnership_start_date'); ?>" class="sort-link">
                                        Start Date
                                        <?php echo getSortIcon('partnership_start_date', $sort, $order); ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schools as $school): ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" 
                                               name="selected_schools[]" 
                                               value="<?php echo $school['id']; ?>"
                                               class="school-checkbox">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($school['name']); ?></strong>
                                        <?php if ($school['short_name']): ?>
                                            <br>
                                            <small class="text-muted">(<?php echo htmlspecialchars($school['short_name']); ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($school['short_name'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($school['contact_person']): ?>
                                            <div><?php echo htmlspecialchars($school['contact_person']); ?></div>
                                            <?php if ($school['contact_email']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($school['contact_email']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($school['city']): ?>
                                            <?php echo htmlspecialchars($school['city']); ?>
                                            <?php if ($school['state']): ?>
                                                , <?php echo htmlspecialchars($school['state']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $status_class = 'status-' . $school['partnership_status'];
                                            $status_label = ucfirst($school['partnership_status']);
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo $school['program_count']; ?> program(s)
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo $school['user_count']; ?> user(s)
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $school['partnership_start_date'] ? date('M d, Y', strtotime($school['partnership_start_date'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $school['id']; ?>" 
                                               class="action-btn btn-info"
                                               title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $school['id']; ?>" 
                                               class="action-btn btn-success"
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="programs.php?school_id=<?php echo $school['id']; ?>" 
                                               class="action-btn btn-warning"
                                               title="View Programs">
                                                <i class="fas fa-book"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $school['id']; ?>" 
                                               class="action-btn btn-danger"
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this school?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo buildPageUrl($page - 1); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <a href="?<?php echo buildPageUrl($i); ?>" 
                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span class="page-link disabled">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo buildPageUrl($page + 1); ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.school-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelectedCount();
        });

        document.getElementById('toggleAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.school-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            document.getElementById('selectAll').checked = this.checked;
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.school-checkbox:checked');
            document.getElementById('selectedCount').textContent = 
                selected.length + ' school(s) selected';
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.school-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // Confirm bulk action
        function confirmBulkAction() {
            const selected = document.querySelectorAll('.school-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one school');
                return false;
            }

            const action = document.querySelector('select[name="bulk_action"]').value;
            if (!action) {
                alert('Please select an action');
                return false;
            }

            const actionText = {
                'activate': 'activate',
                'deactivate': 'set as pending',
                'terminate': 'terminate',
                'delete': 'delete'
            }[action];

            return confirm(`Are you sure you want to ${actionText} ${selected.length} school(s)?`);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+A to select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                document.getElementById('selectAll').click();
            }
            // Delete key for delete action
            if (e.key === 'Delete') {
                const selected = document.querySelectorAll('.school-checkbox:checked');
                if (selected.length > 0) {
                    if (confirm(`Delete ${selected.length} selected school(s)?`)) {
                        document.querySelector('select[name="bulk_action"]').value = 'delete';
                        document.getElementById('bulkForm').submit();
                    }
                }
            }
        });
    </script>
</body>
</html>

<?php
// Helper functions for building URLs
function buildSortUrl($sort_column) {
    global $search, $status, $sort, $order, $page;
    $params = [];
    if (!empty($search)) $params['search'] = $search;
    if (!empty($status)) $params['status'] = $status;
    $params['sort'] = $sort_column;
    $params['order'] = ($sort === $sort_column && $order === 'ASC') ? 'desc' : 'asc';
    $params['page'] = $page;
    return http_build_query($params);
}

function buildPageUrl($page_num) {
    global $search, $status, $sort, $order;
    $params = [];
    if (!empty($search)) $params['search'] = $search;
    if (!empty($status)) $params['status'] = $status;
    $params['sort'] = $sort;
    $params['order'] = $order;
    $params['page'] = $page_num;
    return http_build_query($params);
}

function getSortIcon($column, $current_sort, $current_order) {
    if ($column === $current_sort) {
        return $current_order === 'ASC' ? '↑' : '↓';
    }
    return '';
}
?>