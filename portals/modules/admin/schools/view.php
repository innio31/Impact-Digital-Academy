<?php
// modules/admin/schools/view.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get school ID
$school_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$school_id) {
    $_SESSION['error'] = 'School ID is required';
    header('Location: manage.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Get school details
$sql = "SELECT s.*, 
               COUNT(DISTINCT p.id) as program_count,
               COUNT(DISTINCT u.id) as user_count,
               (SELECT COUNT(*) FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id 
                JOIN programs pr ON cb.course_id = pr.id 
                WHERE pr.school_id = s.id) as enrollment_count
        FROM schools s
        LEFT JOIN programs p ON s.id = p.school_id
        LEFT JOIN users u ON s.id = u.school_id
        WHERE s.id = ?
        GROUP BY s.id";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
$school = $result->fetch_assoc();

if (!$school) {
    $_SESSION['error'] = 'School not found';
    header('Location: manage.php');
    exit();
}

// Get recent programs for this school
$programs_sql = "SELECT p.*, 
                        (SELECT COUNT(*) FROM enrollments e 
                         JOIN class_batches cb ON e.class_id = cb.id 
                         WHERE cb.course_id = p.id) as student_count
                 FROM programs p
                 WHERE p.school_id = ?
                 ORDER BY p.created_at DESC
                 LIMIT 5";
$programs_stmt = $conn->prepare($programs_sql);
$programs_stmt->bind_param("i", $school_id);
$programs_stmt->execute();
$programs = $programs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent users from this school
$users_sql = "SELECT u.*, up.date_of_birth, up.gender, up.address 
              FROM users u
              LEFT JOIN user_profiles up ON u.id = up.user_id
              WHERE u.school_id = ?
              ORDER BY u.created_at DESC
              LIMIT 5";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->bind_param("i", $school_id);
$users_stmt->execute();
$users = $users_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get partnership timeline/activity
$activity_sql = "SELECT * FROM activity_logs 
                 WHERE table_name = 'schools' AND record_id = ?
                 ORDER BY created_at DESC
                 LIMIT 10";
$activity_stmt = $conn->prepare($activity_sql);
$activity_stmt->bind_param("i", $school_id);
$activity_stmt->execute();
$activities = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity('school_view', "Viewed school #$school_id: " . $school['name'], 'schools', $school_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> - Impact Digital Academy</title>
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

        /* Header */
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
        }

        .school-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        .school-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .school-subtitle {
            color: var(--gray);
            font-size: 1rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
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

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        /* Content Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* School Details */
        .detail-item {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: var(--dark);
            font-size: 1rem;
        }

        .detail-value a {
            color: var(--primary);
            text-decoration: none;
        }

        .detail-value a:hover {
            text-decoration: underline;
        }

        /* Status Badge */
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

        /* List Items */
        .list-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item:hover {
            background: var(--light);
        }

        .list-icon {
            width: 40px;
            height: 40px;
            background: var(--light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .list-content {
            flex: 1;
        }

        .list-title {
            font-weight: 500;
            color: var(--dark);
        }

        .list-subtitle {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .list-meta {
            color: var(--gray);
            font-size: 0.8rem;
        }

        /* Activity Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--light-gray);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid white;
        }

        .timeline-date {
            color: var(--gray);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid var(--light-gray);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .school-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .school-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
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
            <span><?php echo htmlspecialchars($school['name']); ?></span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="school-title">
                <div class="school-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="school-info">
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <div class="school-subtitle">
                        <?php if ($school['short_name']): ?>
                            <strong><?php echo htmlspecialchars($school['short_name']); ?></strong>
                            &nbsp;•&nbsp;
                        <?php endif; ?>
                        <span class="status-badge status-<?php echo $school['partnership_status']; ?>">
                            <?php echo ucfirst($school['partnership_status']); ?> Partnership
                        </span>
                        <?php if ($school['partnership_start_date']): ?>
                            &nbsp;•&nbsp;
                            Since <?php echo date('F Y', strtotime($school['partnership_start_date'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="page-actions">
                <a href="edit.php?edit=<?php echo $school_id; ?>" class="btn btn-success">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="programs.php?school_id=<?php echo $school_id; ?>" class="btn btn-warning">
                    <i class="fas fa-book"></i> Programs
                </a>
                <a href="manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $school['program_count']; ?></div>
                <div class="stat-label">Programs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $school['user_count']; ?></div>
                <div class="stat-label">Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $school['enrollment_count']; ?></div>
                <div class="stat-label">Enrollments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                        $days = $school['partnership_start_date'] ? 
                            floor((time() - strtotime($school['partnership_start_date'])) / (60 * 60 * 24)) : 
                            0;
                        echo $days;
                    ?>
                </div>
                <div class="stat-label">Days Active</div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- School Details -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> School Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="detail-item">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value"><?php echo htmlspecialchars($school['name']); ?></div>
                        </div>
                        
                        <?php if ($school['short_name']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Short Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($school['short_name']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($school['address']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Address</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($school['address'])); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="detail-item">
                            <div class="detail-label">Location</div>
                            <div class="detail-value">
                                <?php 
                                    $location_parts = [];
                                    if ($school['city']) $location_parts[] = $school['city'];
                                    if ($school['state']) $location_parts[] = $school['state'];
                                    if ($school['country']) $location_parts[] = $school['country'];
                                    echo htmlspecialchars(implode(', ', $location_parts));
                                ?>
                            </div>
                        </div>
                        
                        <?php if ($school['contact_person']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Contact Person</div>
                                <div class="detail-value"><?php echo htmlspecialchars($school['contact_person']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($school['contact_email']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Contact Email</div>
                                <div class="detail-value">
                                    <a href="mailto:<?php echo htmlspecialchars($school['contact_email']); ?>">
                                        <?php echo htmlspecialchars($school['contact_email']); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($school['contact_phone']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Contact Phone</div>
                                <div class="detail-value">
                                    <a href="tel:<?php echo htmlspecialchars($school['contact_phone']); ?>">
                                        <?php echo htmlspecialchars($school['contact_phone']); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="detail-item">
                            <div class="detail-label">Partnership Status</div>
                            <div class="detail-value">
                                <span class="status-badge status-<?php echo $school['partnership_status']; ?>">
                                    <?php echo ucfirst($school['partnership_status']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Partnership Start Date</div>
                            <div class="detail-value">
                                <?php echo $school['partnership_start_date'] ? 
                                    date('F d, Y', strtotime($school['partnership_start_date'])) : 'Not specified'; ?>
                            </div>
                        </div>
                        
                        <?php if ($school['notes']): ?>
                            <div class="detail-item">
                                <div class="detail-label">Notes</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($school['notes'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Programs -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-book"></i> Recent Programs</h3>
                        <a href="programs.php?school_id=<?php echo $school_id; ?>" class="btn btn-secondary btn-sm">
                            View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($programs)): ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <p>No programs found for this school</p>
                                <a href="<?php echo BASE_URL; ?>modules/admin/programs/create.php?school_id=<?php echo $school_id; ?>" 
                                   class="btn btn-primary mt-1">
                                    Add Program
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($programs as $program): ?>
                                <div class="list-item">
                                    <div class="list-icon">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div class="list-content">
                                        <div class="list-title">
                                            <?php echo htmlspecialchars($program['name']); ?>
                                            <small>(<?php echo htmlspecialchars($program['program_code']); ?>)</small>
                                        </div>
                                        <div class="list-subtitle">
                                            <?php echo htmlspecialchars($program['description'] ? substr($program['description'], 0, 100) . '...' : 'No description'); ?>
                                        </div>
                                        <div class="list-meta">
                                            <i class="fas fa-users"></i> <?php echo $program['student_count']; ?> students
                                            &nbsp;•&nbsp;
                                            <i class="fas fa-calendar"></i> 
                                            <?php echo date('M d, Y', strtotime($program['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Recent Users -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> Recent Users</h3>
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?school_id=<?php echo $school_id; ?>" 
                           class="btn btn-secondary btn-sm">
                            View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user"></i>
                                <p>No users found for this school</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <div class="list-item">
                                    <div class="list-icon">
                                        <?php if ($user['profile_image']): ?>
                                            <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                                 alt="<?php echo htmlspecialchars($user['first_name']); ?>"
                                                 style="width: 40px; height: 40px; border-radius: 50%;">
                                        <?php else: ?>
                                            <i class="fas fa-user"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="list-content">
                                        <div class="list-title">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        </div>
                                        <div class="list-subtitle">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </div>
                                        <div class="list-meta">
                                            <span class="badge badge-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                            &nbsp;•&nbsp;
                                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Activity Timeline -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($activities)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent activity</p>
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($activities as $activity): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-date">
                                            <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                        </div>
                                        <div class="timeline-content">
                                            <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                            <?php if ($activity['description']): ?>
                                                <p style="margin-top: 0.5rem; margin-bottom: 0;"><?php echo htmlspecialchars($activity['description']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($activity['user_id']): ?>
                                                <small style="color: var(--gray);">
                                                    By User #<?php echo $activity['user_id']; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/programs/create.php?school_id=<?php echo $school_id; ?>" 
                               class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-plus"></i> Add Program
                            </a>
                            <a href="edit.php?edit=<?php echo $school_id; ?>" 
                               class="btn btn-success" style="width: 100%;">
                                <i class="fas fa-edit"></i> Edit School
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/admin/reports/school.php?id=<?php echo $school_id; ?>" 
                               class="btn btn-info" style="width: 100%;">
                                <i class="fas fa-chart-bar"></i> Reports
                            </a>
                            <button onclick="printSchoolInfo()" 
                                    class="btn btn-secondary" style="width: 100%;">
                                <i class="fas fa-print"></i> Print Info
                            </button>
                        </div>
                        
                        <?php if ($school['notes']): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--light-gray);">
                                <strong>Internal Notes:</strong>
                                <p style="margin-top: 0.5rem; font-size: 0.9rem; color: var(--gray);">
                                    <?php echo nl2br(htmlspecialchars(substr($school['notes'], 0, 200))); ?>
                                    <?php if (strlen($school['notes']) > 200): ?>...<?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function printSchoolInfo() {
            const printContent = `
                <html>
                <head>
                    <title><?php echo htmlspecialchars($school['name']); ?> - School Information</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 2rem; }
                        .header { text-align: center; margin-bottom: 2rem; }
                        .header h1 { margin-bottom: 0.5rem; }
                        .info-table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
                        .info-table td { padding: 0.5rem; border: 1px solid #ddd; }
                        .info-table td:first-child { font-weight: bold; width: 30%; background: #f5f5f5; }
                        .timestamp { text-align: center; margin-top: 2rem; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                        <p>School Information Report</p>
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                    </div>
                    
                    <table class="info-table">
                        <tr>
                            <td>School Name:</td>
                            <td><?php echo htmlspecialchars($school['name']); ?></td>
                        </tr>
                        <?php if ($school['short_name']): ?>
                        <tr>
                            <td>Short Name:</td>
                            <td><?php echo htmlspecialchars($school['short_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($school['address']): ?>
                        <tr>
                            <td>Address:</td>
                            <td><?php echo nl2br(htmlspecialchars($school['address'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Location:</td>
                            <td>
                                <?php 
                                    $location_parts = [];
                                    if ($school['city']) $location_parts[] = $school['city'];
                                    if ($school['state']) $location_parts[] = $school['state'];
                                    if ($school['country']) $location_parts[] = $school['country'];
                                    echo htmlspecialchars(implode(', ', $location_parts));
                                ?>
                            </td>
                        </tr>
                        <?php if ($school['contact_person']): ?>
                        <tr>
                            <td>Contact Person:</td>
                            <td><?php echo htmlspecialchars($school['contact_person']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($school['contact_email']): ?>
                        <tr>
                            <td>Contact Email:</td>
                            <td><?php echo htmlspecialchars($school['contact_email']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($school['contact_phone']): ?>
                        <tr>
                            <td>Contact Phone:</td>
                            <td><?php echo htmlspecialchars($school['contact_phone']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Partnership Status:</td>
                            <td><?php echo ucfirst($school['partnership_status']); ?></td>
                        </tr>
                        <tr>
                            <td>Partnership Start Date:</td>
                            <td><?php echo $school['partnership_start_date'] ? date('F d, Y', strtotime($school['partnership_start_date'])) : 'Not specified'; ?></td>
                        </tr>
                        <tr>
                            <td>Programs Count:</td>
                            <td><?php echo $school['program_count']; ?></td>
                        </tr>
                        <tr>
                            <td>Users Count:</td>
                            <td><?php echo $school['user_count']; ?></td>
                        </tr>
                        <tr>
                            <td>Enrollments Count:</td>
                            <td><?php echo $school['enrollment_count']; ?></td>
                        </tr>
                        <?php if ($school['notes']): ?>
                        <tr>
                            <td>Notes:</td>
                            <td><?php echo nl2br(htmlspecialchars($school['notes'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <div class="timestamp">
                        Generated by Impact Digital Academy &copy; <?php echo date('Y'); ?>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printSchoolInfo();
            }
            // Ctrl+E to edit
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'edit.php?edit=<?php echo $school_id; ?>';
            }
            // Esc to go back
            if (e.key === 'Escape') {
                window.location.href = 'manage.php';
            }
        });
    </script>
</body>
</html>