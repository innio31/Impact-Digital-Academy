<?php
// modules/admin/schools/programs.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get school ID
$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
if (!$school_id) {
    $_SESSION['error'] = 'School ID is required';
    header('Location: manage.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Get school details
$school_sql = "SELECT * FROM schools WHERE id = ?";
$school_stmt = $conn->prepare($school_sql);
$school_stmt->bind_param("i", $school_id);
$school_stmt->execute();
$school_result = $school_stmt->get_result();
$school = $school_result->fetch_assoc();

if (!$school) {
    $_SESSION['error'] = 'School not found';
    header('Location: manage.php');
    exit();
}

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
    
    switch ($action) {
        case 'delete':
            if ($program_id) {
                // Check if program belongs to this school
                $check_sql = "SELECT id FROM programs WHERE id = ? AND school_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $program_id, $school_id);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows > 0) {
                    // Soft delete or actual delete based on your preference
                    $delete_sql = "DELETE FROM programs WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $program_id);
                    
                    if ($delete_stmt->execute()) {
                        $_SESSION['success'] = 'Program deleted successfully';
                    } else {
                        $_SESSION['error'] = 'Failed to delete program';
                    }
                } else {
                    $_SESSION['error'] = 'Program not found or does not belong to this school';
                }
            }
            break;
    }
    
    header("Location: programs.php?school_id=$school_id");
    exit();
}

// Get programs for this school with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total programs
$count_sql = "SELECT COUNT(*) as total FROM programs WHERE school_id = ?";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param("i", $school_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_programs = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_programs / $limit);

// Get programs
$programs_sql = "SELECT p.*, 
                        (SELECT COUNT(*) FROM class_batches WHERE course_id = p.id) as class_count,
                        (SELECT COUNT(*) FROM enrollments e 
                         JOIN class_batches cb ON e.class_id = cb.id 
                         WHERE cb.course_id = p.id) as student_count
                 FROM programs p
                 WHERE p.school_id = ?
                 ORDER BY p.created_at DESC
                 LIMIT ? OFFSET ?";
$programs_stmt = $conn->prepare($programs_sql);
$programs_stmt->bind_param("iii", $school_id, $limit, $offset);
$programs_stmt->execute();
$programs = $programs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity('school_programs_view', "Viewed programs for school #$school_id: " . $school['name'], 'schools', $school_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> - Programs - Impact Digital Academy</title>
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

        /* Breadcrumb */
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-header h1 {
            font-size: 1.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .school-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Cards */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .program-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid var(--light-gray);
        }

        .program-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .program-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .program-code {
            font-size: 0.85rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-weight: 600;
        }

        .program-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-weight: 500;
        }

        .status-active {
            background: var(--success);
        }

        .status-inactive {
            background: var(--gray);
        }

        .status-upcoming {
            background: var(--warning);
        }

        .program-body {
            padding: 1.5rem;
        }

        .program-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .program-description {
            color: var(--gray);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .program-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-icon {
            color: var(--primary);
            font-size: 0.9rem;
        }

        .detail-label {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .detail-value {
            color: var(--dark);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .program-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            border: 1px solid var(--light-gray);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.85rem;
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

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-small {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 10px;
            border: 2px dashed var(--light-gray);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--light-gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--light);
            border-color: var(--primary);
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

        /* Actions Bar */
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid var(--light-gray);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .search-input {
            padding: 0.5rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            width: 300px;
            font-size: 0.9rem;
        }

        .search-btn {
            padding: 0.5rem 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .actions-bar {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .search-input {
                width: 100%;
            }

            .program-details {
                grid-template-columns: 1fr;
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
            <a href="manage.php">Schools</a>
            <i class="fas fa-chevron-right"></i>
            <a href="view.php?id=<?php echo $school_id; ?>"><?php echo htmlspecialchars($school['name']); ?></a>
            <i class="fas fa-chevron-right"></i>
            <span>Programs</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($school['name']); ?> Programs</h1>
                <div style="display: flex; gap: 1rem; margin-top: 0.5rem; align-items: center;">
                    <span class="school-badge">
                        <?php if ($school['short_name']): ?>
                            <?php echo htmlspecialchars($school['short_name']); ?>
                        <?php else: ?>
                            School Programs
                        <?php endif; ?>
                    </span>
                    <span style="color: var(--gray); font-size: 0.9rem;">
                        <?php echo $total_programs; ?> program<?php echo $total_programs != 1 ? 's' : ''; ?>
                    </span>
                </div>
            </div>
            
            <div class="page-actions">
                <a href="view.php?id=<?php echo $school_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to School
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/create.php?school_id=<?php echo $school_id; ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Program
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/index.php" 
                   class="btn btn-secondary">
                    <i class="fas fa-list"></i> All Programs
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_programs; ?></div>
                <div class="stat-label">Total Programs</div>
            </div>
            <?php
                // Count active programs
                $active_sql = "SELECT COUNT(*) as count FROM programs WHERE school_id = ? AND status = 'active'";
                $active_stmt = $conn->prepare($active_sql);
                $active_stmt->bind_param("i", $school_id);
                $active_stmt->execute();
                $active_count = $active_stmt->get_result()->fetch_assoc()['count'];
            ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_count; ?></div>
                <div class="stat-label">Active Programs</div>
            </div>
            <?php
                // Get total students in all programs
                $students_sql = "SELECT COUNT(DISTINCT e.student_id) as count 
                                FROM enrollments e
                                JOIN class_batches cb ON e.class_id = cb.id
                                JOIN programs p ON cb.course_id = p.id
                                WHERE p.school_id = ?";
                $students_stmt = $conn->prepare($students_sql);
                $students_stmt->bind_param("i", $school_id);
                $students_stmt->execute();
                $students_count = $students_stmt->get_result()->fetch_assoc()['count'];
            ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo $students_count; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <?php
                // Get total revenue (example)
                $revenue_sql = "SELECT COALESCE(SUM(ft.amount), 0) as total 
                               FROM financial_transactions ft
                               JOIN enrollments e ON ft.student_id = e.student_id
                               JOIN class_batches cb ON e.class_id = cb.id
                               JOIN programs p ON cb.course_id = p.id
                               WHERE p.school_id = ? AND ft.status = 'completed'";
                $revenue_stmt = $conn->prepare($revenue_sql);
                $revenue_stmt->bind_param("i", $school_id);
                $revenue_stmt->execute();
                $revenue_total = $revenue_stmt->get_result()->fetch_assoc()['total'];
            ?>
            <div class="stat-card">
                <div class="stat-number">₦<?php echo number_format($revenue_total, 0); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>

        <!-- Actions Bar -->
        <div class="actions-bar">
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Search programs..." id="searchInput">
                <button class="btn btn-primary search-btn">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
            
            <div>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/index.php?filter_school=<?php echo $school_id; ?>" 
                   class="btn btn-secondary">
                    <i class="fas fa-external-link-alt"></i> View in Academic Panel
                </a>
            </div>
        </div>

        <!-- Programs Grid -->
        <?php if (empty($programs)): ?>
            <div class="empty-state">
                <i class="fas fa-graduation-cap"></i>
                <h3>No Programs Found</h3>
                <p>This school doesn't have any programs yet. Add the first program to get started.</p>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/create.php?school_id=<?php echo $school_id; ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create First Program
                </a>
            </div>
        <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($programs as $program): ?>
                    <div class="program-card">
                        <div class="program-header">
                            <span class="program-code"><?php echo htmlspecialchars($program['program_code']); ?></span>
                            <span class="program-status status-<?php echo $program['status']; ?>">
                                <?php echo ucfirst($program['status']); ?>
                            </span>
                        </div>
                        
                        <div class="program-body">
                            <h3 class="program-title"><?php echo htmlspecialchars($program['name']); ?></h3>
                            
                            <?php if ($program['description']): ?>
                                <p class="program-description">
                                    <?php echo htmlspecialchars(substr($program['description'], 0, 200)); ?>
                                    <?php if (strlen($program['description']) > 200): ?>...<?php endif; ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="program-details">
                                <div class="detail-item">
                                    <i class="fas fa-clock detail-icon"></i>
                                    <div>
                                        <div class="detail-label">Duration</div>
                                        <div class="detail-value"><?php echo $program['duration_weeks']; ?> weeks</div>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <i class="fas fa-money-bill detail-icon"></i>
                                    <div>
                                        <div class="detail-label">Fee</div>
                                        <div class="detail-value">₦<?php echo number_format($program['fee'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <i class="fas fa-users detail-icon"></i>
                                    <div>
                                        <div class="detail-label">Students</div>
                                        <div class="detail-value"><?php echo $program['student_count']; ?></div>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <i class="fas fa-chalkboard detail-icon"></i>
                                    <div>
                                        <div class="detail-label">Classes</div>
                                        <div class="detail-value"><?php echo $program['class_count']; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 1rem;">
                                <span class="detail-label" style="display: block; margin-bottom: 0.25rem;">Program Type:</span>
                                <span class="detail-value">
                                    <?php echo ucfirst($program['program_type']); ?>
                                    <?php if ($program['duration_mode']): ?>
                                        • <?php echo str_replace('_', ' ', $program['duration_mode']); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="program-footer">
                            <div>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $program['id']; ?>" 
                                   class="btn btn-primary btn-small">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/edit.php?id=<?php echo $program['id']; ?>" 
                                   class="btn btn-success btn-small">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </div>
                            
                            <div>
                                <a href="programs.php?school_id=<?php echo $school_id; ?>&action=delete&program_id=<?php echo $program['id']; ?>" 
                                   class="btn btn-danger btn-small"
                                   onclick="return confirm('Are you sure you want to delete this program? This action cannot be undone.');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="programs.php?school_id=<?php echo $school_id; ?>&page=<?php echo $page - 1; ?>" 
                           class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled">
                            <i class="fas fa-chevron-left"></i> Previous
                        </span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="page-link active"><?php echo $i; ?></span>
                        <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <a href="programs.php?school_id=<?php echo $school_id; ?>&page=<?php echo $i; ?>" 
                               class="page-link"><?php echo $i; ?></a>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span class="page-link">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="programs.php?school_id=<?php echo $school_id; ?>&page=<?php echo $page + 1; ?>" 
                           class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled">
                            Next <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchPrograms();
            }
        });

        function searchPrograms() {
            const searchTerm = document.getElementById('searchInput').value.trim();
            if (searchTerm) {
                window.location.href = `<?php echo BASE_URL; ?>modules/admin/academic/programs/index.php?filter_school=<?php echo $school_id; ?>&search=${encodeURIComponent(searchTerm)}`;
            }
        }

        // Filter by status
        function filterByStatus(status) {
            window.location.href = `programs.php?school_id=<?php echo $school_id; ?>&filter_status=${status}`;
        }

        // Quick actions
        document.addEventListener('keydown', function(e) {
            // Ctrl+N to add new program
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = `<?php echo BASE_URL; ?>modules/admin/academic/programs/create.php?school_id=<?php echo $school_id; ?>`;
            }
            // Ctrl+F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });
    </script>
</body>
</html>