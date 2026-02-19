<?php
// modules/admin/content/index.php

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
$content_type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'desc';

// Validate sort and order
$valid_sorts = ['created_at', 'updated_at', 'title', 'views_count', 'downloads_count'];
$valid_orders = ['asc', 'desc'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'created_at';
$order = in_array($order, $valid_orders) ? $order : 'desc';

// Build query for materials
$materials_query = "
    SELECT 
        m.id,
        m.title,
        m.description,
        m.file_type,
        m.file_size,
        m.views_count,
        m.downloads_count,
        m.is_published,
        m.created_at,
        m.updated_at,
        cb.batch_code as class_code,
        c.title as course_title,
        p.name as program_name,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name,
        'material' as content_type
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    JOIN users u ON m.instructor_id = u.id
    WHERE 1=1
";

// Build query for assignments
$assignments_query = "
    SELECT 
        a.id,
        a.title,
        a.description,
        'assignment' as file_type,
        NULL as file_size,
        NULL as views_count,
        NULL as downloads_count,
        a.is_published,
        a.created_at,
        a.updated_at,
        cb.batch_code as class_code,
        c.title as course_title,
        p.name as program_name,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name,
        'assignment' as content_type
    FROM assignments a
    JOIN class_batches cb ON a.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    JOIN users u ON a.instructor_id = u.id
    WHERE 1=1
";

// Build query for announcements
$announcements_query = "
    SELECT 
        an.id,
        an.title,
        an.content as description,
        'announcement' as file_type,
        NULL as file_size,
        NULL as views_count,
        NULL as downloads_count,
        an.is_published,
        an.created_at,
        an.updated_at,
        cb.batch_code as class_code,
        c.title as course_title,
        p.name as program_name,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name,
        'announcement' as content_type
    FROM announcements an
    LEFT JOIN class_batches cb ON an.class_id = cb.id
    LEFT JOIN courses c ON cb.course_id = c.id
    LEFT JOIN programs p ON c.program_id = p.id
    JOIN users u ON an.author_id = u.id
    WHERE 1=1
";

// Apply filters based on content type
$queries = [];
$params = [];
$types = '';

if ($content_type === 'all' || $content_type === 'materials') {
    $queries[] = $materials_query;
}
if ($content_type === 'all' || $content_type === 'assignments') {
    $queries[] = $assignments_query;
}
if ($content_type === 'all' || $content_type === 'announcements') {
    $queries[] = $announcements_query;
}

// Combine queries
if (empty($queries)) {
    $combined_query = "SELECT * FROM (SELECT NULL as id) as empty WHERE 1=0";
} else {
    $combined_query = "(" . implode(") UNION ALL (", $queries) . ")";
}

// Add WHERE conditions
$where_conditions = [];

// Apply status filter
if ($status !== 'all') {
    if ($status === 'published') {
        $where_conditions[] = "is_published = 1";
    } elseif ($status === 'unpublished') {
        $where_conditions[] = "is_published = 0";
    } elseif ($status === 'draft') {
        $where_conditions[] = "is_published = 0";
    }
}

// Apply search filter
if ($search) {
    $where_conditions[] = "(title LIKE ? OR description LIKE ? OR course_title LIKE ? OR program_name LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
}

// Add WHERE conditions to combined query
if (!empty($where_conditions)) {
    $combined_query .= " WHERE " . implode(" AND ", $where_conditions);
}

// Add order and limit for count
$count_query = "SELECT COUNT(*) as total FROM ($combined_query) as combined_content";

// Get total count
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_content = $count_result->fetch_assoc()['total'] ?? 0;

// Pagination
$per_page = 20;
$total_pages = ceil($total_content / $per_page);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Add ordering and pagination to main query
$main_query = "SELECT * FROM ($combined_query) as combined_content ORDER BY $sort $order LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute main query
$stmt = $conn->prepare($main_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$content_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics for dashboard
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM materials) as total_materials,
        (SELECT COUNT(*) FROM materials WHERE is_published = 1) as published_materials,
        (SELECT COUNT(*) FROM assignments) as total_assignments,
        (SELECT COUNT(*) FROM assignments WHERE is_published = 1) as published_assignments,
        (SELECT COUNT(*) FROM announcements) as total_announcements,
        (SELECT COUNT(*) FROM announcements WHERE is_published = 1) as published_announcements,
        (SELECT SUM(downloads_count) FROM materials) as total_downloads,
        (SELECT SUM(views_count) FROM materials) as total_views,
        (SELECT COUNT(DISTINCT instructor_id) FROM materials) as active_instructors,
        (SELECT COUNT(DISTINCT class_id) FROM materials) as active_classes
";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get file type distribution
$file_types_query = "
    SELECT 
        file_type,
        COUNT(*) as count,
        SUM(file_size) as total_size,
        AVG(file_size) as avg_size
    FROM materials 
    WHERE file_type IS NOT NULL 
    GROUP BY file_type 
    ORDER BY count DESC
";
$file_types_result = $conn->query($file_types_query);
$file_types = $file_types_result->fetch_all(MYSQLI_ASSOC);

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    $content_type = $_POST['content_type'] ?? 'material';
    
    if (!empty($selected_ids)) {
        $success_count = 0;
        $error_count = 0;
        
        foreach ($selected_ids as $id) {
            $id = (int)$id;
            
            switch ($content_type) {
                case 'material':
                    $table = 'materials';
                    break;
                case 'assignment':
                    $table = 'assignments';
                    break;
                case 'announcement':
                    $table = 'announcements';
                    break;
                default:
                    continue 2;
            }
            
            switch ($bulk_action) {
                case 'publish':
                    $result = updateContentStatus($table, $id, 1);
                    break;
                case 'unpublish':
                    $result = updateContentStatus($table, $id, 0);
                    break;
                case 'delete':
                    $result = deleteContent($table, $id);
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
            $_SESSION['success'] = "Successfully processed $success_count item(s)";
        }
        if ($error_count > 0) {
            $_SESSION['error'] = "Failed to process $error_count item(s)";
        }
        
        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Function to update content status
function updateContentStatus($table, $id, $status) {
    global $conn;
    
    $sql = "UPDATE $table SET is_published = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $status, $id);
    
    if ($stmt->execute()) {
        logActivity('content_status_update', "Updated $table #$id status to " . ($status ? 'published' : 'unpublished'), $table, $id);
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => 'Database error'];
}

// Function to delete content
function deleteContent($table, $id) {
    global $conn;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // For materials, also delete related files if needed
        if ($table === 'materials') {
            // Get file URL to delete physical file
            $file_sql = "SELECT file_url FROM materials WHERE id = ?";
            $file_stmt = $conn->prepare($file_sql);
            $file_stmt->bind_param("i", $id);
            $file_stmt->execute();
            $file_result = $file_stmt->get_result();
            $material = $file_result->fetch_assoc();
            
            if ($material && $material['file_url']) {
                // Delete physical file (optional - handle with care)
                // $file_path = $_SERVER['DOCUMENT_ROOT'] . $material['file_url'];
                // if (file_exists($file_path)) {
                //     unlink($file_path);
                // }
            }
        }
        
        // Delete content
        $delete_sql = "DELETE FROM $table WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        $delete_stmt->execute();
        
        $conn->commit();
        
        logActivity('content_delete', "Deleted $table #$id", $table, $id);
        return ['success' => true];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Log activity
logActivity('view_content_oversight', "Viewed content oversight dashboard");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Oversight - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .stat-card.materials { border-left-color: var(--primary); }
        .stat-card.assignments { border-left-color: var(--warning); }
        .stat-card.announcements { border-left-color: var(--info); }
        .stat-card.downloads { border-left-color: var(--success); }
        .stat-card.views { border-left-color: var(--secondary); }
        .stat-card.instructors { border-left-color: var(--danger); }

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

        .content-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .content-table th {
            text-align: left;
            padding: 1rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
            cursor: pointer;
        }

        .content-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: top;
        }

        .content-table tr:hover {
            background: var(--light);
        }

        .content-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-material { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .type-assignment { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .type-announcement { background: rgba(59, 130, 246, 0.1); color: var(--info); }

        .file-type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            background: var(--light);
            color: var(--gray);
            text-transform: capitalize;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-published { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-unpublished { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .chart-container {
            height: 300px;
            margin: 2rem 0;
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
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .content-table {
                font-size: 0.85rem;
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
            <span>Content Oversight</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Content Oversight Dashboard</h1>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="exportContentReport()">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
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
            <div class="stat-card materials" onclick="window.location.href='?type=materials'">
                <div class="stat-value"><?php echo number_format($stats['total_materials'] ?? 0); ?></div>
                <div class="stat-label">Total Materials</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['published_materials'] ?? 0); ?> published
                </div>
            </div>
            <div class="stat-card assignments" onclick="window.location.href='?type=assignments'">
                <div class="stat-value"><?php echo number_format($stats['total_assignments'] ?? 0); ?></div>
                <div class="stat-label">Total Assignments</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['published_assignments'] ?? 0); ?> published
                </div>
            </div>
            <div class="stat-card announcements" onclick="window.location.href='?type=announcements'">
                <div class="stat-value"><?php echo number_format($stats['total_announcements'] ?? 0); ?></div>
                <div class="stat-label">Total Announcements</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['published_announcements'] ?? 0); ?> published
                </div>
            </div>
            <div class="stat-card downloads">
                <div class="stat-value"><?php echo number_format($stats['total_downloads'] ?? 0); ?></div>
                <div class="stat-label">Total Downloads</div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo number_format($stats['total_views'] ?? 0); ?></div>
                <div class="stat-label">Total Views</div>
            </div>
            <div class="stat-card instructors">
                <div class="stat-value"><?php echo number_format($stats['active_instructors'] ?? 0); ?></div>
                <div class="stat-label">Active Instructors</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['active_classes'] ?? 0); ?> classes
                </div>
            </div>
        </div>

        <!-- File Type Distribution -->
        <?php if (!empty($file_types)): ?>
        <div class="filters-card">
            <h3 style="margin-bottom: 1rem;">File Type Distribution</h3>
            <div class="chart-container">
                <canvas id="fileTypeChart"></canvas>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <?php foreach ($file_types as $type): ?>
                    <div style="text-align: center; padding: 1rem; background: var(--light); border-radius: 8px;">
                        <div style="font-weight: 600; color: var(--primary);">
                            <?php echo ucfirst($type['file_type']); ?>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: 700; margin: 0.5rem 0;">
                            <?php echo $type['count']; ?>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--gray);">
                            <?php echo formatFileSize($type['total_size'] ?? 0); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="margin: 0;">Filter Content</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()" style="background: none; border: none; color: var(--primary); cursor: pointer;">
                    <i class="fas fa-redo"></i> Reset Filters
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="type">Content Type</label>
                        <select id="type" name="type" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $content_type === 'all' ? 'selected' : ''; ?>>All Content Types</option>
                            <option value="materials" <?php echo $content_type === 'materials' ? 'selected' : ''; ?>>Course Materials</option>
                            <option value="assignments" <?php echo $content_type === 'assignments' ? 'selected' : ''; ?>>Assignments</option>
                            <option value="announcements" <?php echo $content_type === 'announcements' ? 'selected' : ''; ?>>Announcements</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Publication Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="published" <?php echo $status === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="unpublished" <?php echo $status === 'unpublished' ? 'selected' : ''; ?>>Unpublished/Draft</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort" class="form-control" onchange="this.form.submit()">
                            <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Date Created</option>
                            <option value="updated_at" <?php echo $sort === 'updated_at' ? 'selected' : ''; ?>>Last Updated</option>
                            <option value="title" <?php echo $sort === 'title' ? 'selected' : ''; ?>>Title</option>
                            <option value="views_count" <?php echo $sort === 'views_count' ? 'selected' : ''; ?>>Views</option>
                            <option value="downloads_count" <?php echo $sort === 'downloads_count' ? 'selected' : ''; ?>>Downloads</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" class="form-control"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by title, description, course...">
                    </div>
                </div>

                <div class="filter-actions" style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Content List -->
        <div class="filters-card">
            <form method="POST" id="bulkForm">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0;">
                        Content List 
                        <span style="font-size: 0.9rem; font-weight: normal; color: var(--gray);">
                            (<?php echo number_format($total_content); ?> items)
                        </span>
                    </h3>
                    
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            <label for="selectAll" style="font-size: 0.9rem; color: var(--gray);">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="form-control" style="width: 150px;">
                            <option value="">Bulk Actions</option>
                            <option value="publish">Publish</option>
                            <option value="unpublish">Unpublish</option>
                            <option value="delete">Delete</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                            <i class="fas fa-play"></i> Apply
                        </button>
                    </div>
                </div>
                
                <?php if (empty($content_items)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray);">
                        <i class="fas fa-file-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <h3>No content found</h3>
                        <p>No content matches your current filters.</p>
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="margin-top: 1rem;">
                            Reset Filters
                        </button>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="content-table">
                            <thead>
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="selectAllTable" onchange="toggleSelectAll()">
                                    </th>
                                    <th onclick="sortTable('title')">Title <i class="fas fa-sort"></i></th>
                                    <th onclick="sortTable('content_type')">Type <i class="fas fa-sort"></i></th>
                                    <th>Course/Class</th>
                                    <th onclick="sortTable('creator_first_name')">Creator <i class="fas fa-sort"></i></th>
                                    <th onclick="sortTable('created_at')">Created <i class="fas fa-sort"></i></th>
                                    <th>Stats</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($content_items as $item): 
                                    $type_class = 'type-' . $item['content_type'];
                                    $status_class = $item['is_published'] ? 'status-published' : 'status-unpublished';
                                ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo $item['id']; ?>" 
                                                   class="row-selector" data-type="<?php echo $item['content_type']; ?>">
                                            <input type="hidden" name="content_type" value="<?php echo $item['content_type']; ?>">
                                        </td>
                                        <td>
                                            <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($item['title']); ?>
                                            </div>
                                            <?php if ($item['description']): ?>
                                                <div style="font-size: 0.85rem; color: var(--gray); line-height: 1.4;">
                                                    <?php echo substr($item['description'], 0, 100); ?>
                                                    <?php if (strlen($item['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="content-type-badge <?php echo $type_class; ?>">
                                                <?php echo ucfirst($item['content_type']); ?>
                                            </span>
                                            <?php if ($item['file_type'] && $item['content_type'] === 'material'): ?>
                                                <div style="margin-top: 0.25rem;">
                                                    <span class="file-type-badge">
                                                        <?php echo $item['file_type']; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500; color: var(--primary);">
                                                <?php echo htmlspecialchars($item['course_title']); ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: var(--gray);">
                                                <?php echo htmlspecialchars($item['program_name']); ?>
                                                <?php if ($item['class_code']): ?>
                                                    <br><?php echo htmlspecialchars($item['class_code']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($item['creator_first_name'] . ' ' . $item['creator_last_name']); ?>
                                        </td>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($item['created_at'])); ?></div>
                                            <div style="font-size: 0.85rem; color: var(--gray);">
                                                <?php echo date('h:i A', strtotime($item['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($item['content_type'] === 'material'): ?>
                                                <div style="display: flex; gap: 1rem;">
                                                    <div style="text-align: center;">
                                                        <div style="font-weight: 600; color: var(--primary);">
                                                            <?php echo $item['views_count'] ?? 0; ?>
                                                        </div>
                                                        <div style="font-size: 0.75rem; color: var(--gray);">Views</div>
                                                    </div>
                                                    <div style="text-align: center;">
                                                        <div style="font-weight: 600; color: var(--success);">
                                                            <?php echo $item['downloads_count'] ?? 0; ?>
                                                        </div>
                                                        <div style="font-size: 0.75rem; color: var(--gray);">Downloads</div>
                                                    </div>
                                                    <?php if ($item['file_size']): ?>
                                                        <div style="text-align: center;">
                                                            <div style="font-weight: 600; color: var(--warning);">
                                                                <?php echo formatFileSize($item['file_size']); ?>
                                                            </div>
                                                            <div style="font-size: 0.75rem; color: var(--gray);">Size</div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="color: var(--gray); font-size: 0.85rem;">
                                                    No stats available
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $item['is_published'] ? 'Published' : 'Unpublished'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem;">
                                                <?php if ($item['content_type'] === 'material'): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/materials/view.php?id=<?php echo $item['id']; ?>" 
                                                       class="btn" style="background: var(--light-gray); padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                       target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php elseif ($item['content_type'] === 'assignment'): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/assignments/view.php?id=<?php echo $item['id']; ?>" 
                                                       class="btn" style="background: var(--light-gray); padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                       target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php elseif ($item['content_type'] === 'announcement'): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/announcements/view.php?id=<?php echo $item['id']; ?>" 
                                                       class="btn" style="background: var(--light-gray); padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                       target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if ($item['is_published']): ?>
                                                    <a href="?action=unpublish&type=<?php echo $item['content_type']; ?>&id=<?php echo $item['id']; ?>" 
                                                       class="btn" style="background: var(--warning); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                       onclick="return confirm('Unpublish this content?')">
                                                        <i class="fas fa-eye-slash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?action=publish&type=<?php echo $item['content_type']; ?>&id=<?php echo $item['id']; ?>" 
                                                       class="btn" style="background: var(--success); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                       onclick="return confirm('Publish this content?')">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <a href="?action=delete&type=<?php echo $item['content_type']; ?>&id=<?php echo $item['id']; ?>" 
                                                   class="btn" style="background: var(--danger); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                   onclick="return confirm('Delete this content? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="page-info" style="color: var(--gray); font-size: 0.9rem; margin-right: 1rem;">
                Showing <?php echo (($page - 1) * $per_page) + 1; ?>-<?php echo min($page * $per_page, $total_content); ?> of <?php echo number_format($total_content); ?> items
            </div>
            
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="btn" style="background: var(--light-gray);">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn" style="background: var(--light-gray);">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($p = $start_page; $p <= $end_page; $p++):
                if ($p == 1 || $p == $total_pages || ($p >= $page - 2 && $p <= $page + 2)):
            ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" 
                   class="btn <?php echo $p == $page ? 'btn-primary' : ''; ?>"
                   style="<?php echo $p == $page ? '' : 'background: var(--light-gray);'; ?>">
                    <?php echo $p; ?>
                </a>
            <?php 
                elseif ($p == $start_page + 2 || $p == $end_page - 2):
            ?>
                <span style="padding: 0.5rem; color: var(--gray);">...</span>
            <?php endif; endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn" style="background: var(--light-gray);">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="btn" style="background: var(--light-gray);">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // File type chart
        const ctx = document.getElementById('fileTypeChart').getContext('2d');
        <?php if (!empty($file_types)): ?>
        const fileTypeChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($type) { return "'" . ucfirst($type['file_type']) . "'"; }, $file_types)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($file_types, 'count')); ?>],
                    backgroundColor: [
                        'rgba(37, 99, 235, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgb(37, 99, 235)',
                        'rgb(245, 158, 11)',
                        'rgb(16, 185, 129)',
                        'rgb(239, 68, 68)',
                        'rgb(139, 92, 246)',
                        'rgb(59, 130, 246)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
        <?php endif; ?>

        // Reset filters
        function resetFilters() {
            window.location.href = 'index.php';
        }

        // Sort table
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort');
            const currentOrder = url.searchParams.get('order');
            
            let newOrder = 'asc';
            if (currentSort === column && currentOrder === 'asc') {
                newOrder = 'desc';
            }
            
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            window.location.href = url.toString();
        }

        // Bulk selection
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll') || document.getElementById('selectAllTable');
            const checkboxes = document.querySelectorAll('.row-selector');
            const isChecked = selectAll.checked;
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            
            // Sync both select all checkboxes
            const otherSelectAll = document.getElementById('selectAll') ? document.getElementById('selectAllTable') : document.getElementById('selectAll');
            if (otherSelectAll) {
                otherSelectAll.checked = isChecked;
            }
        }

        // Confirm bulk action
        function confirmBulkAction() {
            const form = document.getElementById('bulkForm');
            const bulkAction = form.bulk_action.value;
            const selectedCount = document.querySelectorAll('.row-selector:checked').length;
            
            if (!bulkAction) {
                alert('Please select a bulk action');
                return false;
            }
            
            if (selectedCount === 0) {
                alert('Please select at least one item');
                return false;
            }
            
            const actionMap = {
                'publish': 'publish',
                'unpublish': 'unpublish',
                'delete': 'delete'
            };
            
            const actionText = actionMap[bulkAction] || bulkAction;
            return confirm(`Are you sure you want to ${actionText} ${selectedCount} item(s)?`);
        }

        // Auto-submit filters on search after delay
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

        // Handle URL actions
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const type = urlParams.get('type');
        const id = urlParams.get('id');
        
        if (action && type && id) {
            // Actions are already handled server-side
            // Remove action from URL after processing
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('type');
            url.searchParams.delete('id');
            window.history.replaceState({}, document.title, url.toString());
        }

        // Export content report
        function exportContentReport() {
            // Get filter parameters
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'csv');
            window.location.href = url.toString();
        }

        // Format file size function (for JavaScript if needed)
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>