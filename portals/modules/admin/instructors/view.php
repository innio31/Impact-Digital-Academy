<?php
// modules/admin/instructors/view.php

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

$instructor_id = $_GET['id'] ?? null;

if (!$instructor_id) {
    header("Location: index.php");
    exit();
}

// Fetch instructor details
$stmt = $conn->prepare("
    SELECT u.*, up.*
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id = ? AND u.role = 'instructor'
");
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$instructor = $result->fetch_assoc();

if (!$instructor) {
    $_SESSION['error'] = "Instructor not found";
    header("Location: index.php");
    exit();
}

// Fetch instructor's current classes
$classes_stmt = $conn->prepare("
    SELECT cb.*, c.title as course_title, p.name as program_name,
           COUNT(DISTINCT e.id) as student_count
    FROM class_batches cb
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
    WHERE cb.instructor_id = ?
    GROUP BY cb.id
    ORDER BY cb.start_date DESC
");
$classes_stmt->bind_param("i", $instructor_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$current_classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// Fetch instructor's recent assignments
$assignments_stmt = $conn->prepare("
    SELECT a.*, cb.batch_code, c.title as course_title
    FROM assignments a
    JOIN class_batches cb ON a.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    WHERE a.instructor_id = ?
    ORDER BY a.created_at DESC
    LIMIT 10
");
$assignments_stmt->bind_param("i", $instructor_id);
$assignments_stmt->execute();
$assignments_result = $assignments_stmt->get_result();
$recent_assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);

// Fetch instructor's materials
$materials_stmt = $conn->prepare("
    SELECT m.*, cb.batch_code, c.title as course_title
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    WHERE m.instructor_id = ?
    ORDER BY m.created_at DESC
    LIMIT 10
");
$materials_stmt->bind_param("i", $instructor_id);
$materials_stmt->execute();
$materials_result = $materials_stmt->get_result();
$recent_materials = $materials_result->fetch_all(MYSQLI_ASSOC);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $notes = $_POST['notes'] ?? '';
    
    $update_stmt = $conn->prepare("
        UPDATE users 
        SET status = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $update_stmt->bind_param("si", $new_status, $instructor_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Instructor status updated successfully";
        $instructor['status'] = $new_status;
        
        logActivity('instructor_status_change', 
            "Changed instructor #$instructor_id status to $new_status: $notes", 
            'users', $instructor_id);
    } else {
        $_SESSION['error'] = "Failed to update status";
    }
    
    header("Location: view.php?id=$instructor_id");
    exit();
}

// Log view activity
logActivity('instructor_view', "Viewed instructor profile: " . $instructor['first_name'] . ' ' . $instructor['last_name'], 'users', $instructor_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?> - Instructor Profile</title>
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
            max-width: 1200px;
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

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }

        .profile-info {
            flex: 1;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--light);
            border-radius: 8px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th {
            text-align: left;
            padding: 0.75rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .btn {
            padding: 0.5rem 1rem;
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

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .status-suspended { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
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
            <a href="index.php">Instructors</a>
            <i class="fas fa-chevron-right"></i>
            <span>Instructor Profile</span>
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

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="profile-info">
                <h1 style="margin: 0 0 0.5rem 0;">
                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                </h1>
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <span class="status-badge status-<?php echo $instructor['status']; ?>">
                        <?php echo ucfirst($instructor['status']); ?>
                    </span>
                    <span style="color: var(--gray);">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($instructor['email']); ?>
                    </span>
                    <?php if ($instructor['phone']): ?>
                        <span style="color: var(--gray);">
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($instructor['phone']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <?php if ($instructor['current_job_title']): ?>
                    <div style="margin-bottom: 0.5rem;">
                        <strong><?php echo htmlspecialchars($instructor['current_job_title']); ?></strong>
                        <?php if ($instructor['current_company']): ?>
                            at <?php echo htmlspecialchars($instructor['current_company']); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($instructor['experience_years']): ?>
                    <div style="margin-bottom: 0.5rem;">
                        <i class="fas fa-briefcase" style="color: var(--primary);"></i>
                        <?php echo $instructor['experience_years']; ?> years of experience
                    </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <a href="assign.php?id=<?php echo $instructor_id; ?>" class="btn btn-primary">
                        <i class="fas fa-tasks"></i> Assign Classes
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/users/edit.php?id=<?php echo $instructor_id; ?>" class="btn" style="background: var(--light-gray);">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-item">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">
                    <?php echo count($current_classes); ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--gray);">Total Classes</div>
            </div>
            <div class="stat-item">
                <div style="font-size: 2rem; font-weight: 700; color: var(--success);">
                    <?php 
                    $active_classes = array_filter($current_classes, function($class) {
                        return $class['status'] === 'ongoing';
                    });
                    echo count($active_classes);
                    ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--gray);">Active Classes</div>
            </div>
            <div class="stat-item">
                <div style="font-size: 2rem; font-weight: 700; color: var(--info);">
                    <?php 
                    $total_students = array_sum(array_column($current_classes, 'student_count'));
                    echo $total_students;
                    ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--gray);">Total Students</div>
            </div>
            <div class="stat-item">
                <div style="font-size: 2rem; font-weight: 700; color: var(--warning);">
                    <?php echo count($recent_assignments); ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--gray);">Assignments</div>
            </div>
        </div>

        <!-- Status Update Form -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Update Instructor Status</h3>
            <form method="POST">
                <div style="display: flex; gap: 1rem; align-items: flex-start;">
                    <div style="flex: 1;">
                        <select name="status" class="form-control" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid var(--light-gray);">
                            <option value="active" <?php echo $instructor['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $instructor['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="suspended" <?php echo $instructor['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div style="flex: 2;">
                        <input type="text" name="notes" placeholder="Reason for status change (optional)" 
                               class="form-control" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid var(--light-gray);">
                    </div>
                    <div>
                        <button type="submit" name="update_status" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Current Classes -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Current Classes</h3>
                <a href="assign.php?id=<?php echo $instructor_id; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Assign New Class
                </a>
            </div>
            
            <?php if (empty($current_classes)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                    <i class="fas fa-chalkboard" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p>No classes assigned to this instructor</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Course/Program</th>
                            <th>Schedule</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_classes as $class): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($class['batch_code']); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($class['course_title']); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($class['program_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($class['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($class['end_date'])); ?>
                                    <?php if ($class['schedule']): ?>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($class['schedule']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--primary);"><?php echo $class['student_count']; ?></strong>
                                </td>
                                <td>
                                    <span style="padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; 
                                          background: <?php echo $class['status'] === 'ongoing' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>;
                                          color: <?php echo $class['status'] === 'ongoing' ? '#10b981' : '#f59e0b'; ?>;">
                                        <?php echo ucfirst($class['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class['id']; ?>" 
                                       class="btn" style="background: var(--light-gray); padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <form method="POST" action="assign.php" style="display: inline;">
                                        <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                        <input type="hidden" name="instructor_id" value="<?php echo $instructor_id; ?>">
                                        <input type="hidden" name="action" value="unassign">
                                        <button type="submit" class="btn" 
                                                style="background: var(--danger); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                                onclick="return confirm('Unassign instructor from this class?')">
                                            <i class="fas fa-times"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Assignments -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Recent Assignments</h3>
            <?php if (empty($recent_assignments)): ?>
                <div style="text-align: center; padding: 1rem; color: var(--gray);">
                    <i class="fas fa-tasks" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No assignments created yet</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Assignment</th>
                            <th>Class</th>
                            <th>Due Date</th>
                            <th>Points</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_assignments as $assignment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                    <?php if ($assignment['description']): ?>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            <?php echo substr($assignment['description'], 0, 50); ?>
                                            <?php if (strlen($assignment['description']) > 50): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($assignment['batch_code']); ?>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($assignment['course_title']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong><?php echo $assignment['total_points']; ?></strong>
                                </td>
                                <td>
                                    <span style="padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; 
                                          background: rgba(37, 99, 235, 0.1); color: var(--primary);">
                                        <?php echo $assignment['is_published'] ? 'Published' : 'Draft'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Materials -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Recent Course Materials</h3>
            <?php if (empty($recent_materials)): ?>
                <div style="text-align: center; padding: 1rem; color: var(--gray);">
                    <i class="fas fa-file-alt" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No materials uploaded yet</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Class</th>
                            <th>Type</th>
                            <th>Uploaded</th>
                            <th>Downloads</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_materials as $material): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($material['title']); ?></strong>
                                    <?php if ($material['description']): ?>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            <?php echo substr($material['description'], 0, 50); ?>
                                            <?php if (strlen($material['description']) > 50): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($material['batch_code']); ?>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($material['course_title']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="text-transform: capitalize;">
                                        <?php echo $material['file_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong><?php echo $material['downloads_count']; ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Profile Information -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Profile Information</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                <div>
                    <h4 style="margin-bottom: 0.5rem; color: var(--gray); font-size: 0.9rem;">Contact Information</h4>
                    <div style="background: var(--light); padding: 1rem; border-radius: 8px;">
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Email:</strong> <?php echo htmlspecialchars($instructor['email']); ?>
                        </div>
                        <?php if ($instructor['phone']): ?>
                            <div style="margin-bottom: 0.5rem;">
                                <strong>Phone:</strong> <?php echo htmlspecialchars($instructor['phone']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($instructor['address']): ?>
                            <div style="margin-bottom: 0.5rem;">
                                <strong>Address:</strong> <?php echo htmlspecialchars($instructor['address']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($instructor['city'] || $instructor['state']): ?>
                            <div>
                                <strong>Location:</strong> 
                                <?php 
                                if ($instructor['city']) echo htmlspecialchars($instructor['city']);
                                if ($instructor['city'] && $instructor['state']) echo ', ';
                                if ($instructor['state']) echo htmlspecialchars($instructor['state']);
                                if ($instructor['country']) echo ', ' . htmlspecialchars($instructor['country']);
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($instructor['qualifications'] || $instructor['bio']): ?>
                <div>
                    <h4 style="margin-bottom: 0.5rem; color: var(--gray); font-size: 0.9rem;">Qualifications & Bio</h4>
                    <div style="background: var(--light); padding: 1rem; border-radius: 8px;">
                        <?php if ($instructor['qualifications']): ?>
                            <div style="margin-bottom: 1rem;">
                                <strong>Qualifications:</strong>
                                <div style="margin-top: 0.25rem; font-size: 0.9rem;">
                                    <?php echo nl2br(htmlspecialchars($instructor['qualifications'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($instructor['bio']): ?>
                            <div>
                                <strong>Bio:</strong>
                                <div style="margin-top: 0.25rem; font-size: 0.9rem;">
                                    <?php echo nl2br(htmlspecialchars($instructor['bio'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($instructor['linkedin_url'] || $instructor['github_url'] || $instructor['website']): ?>
            <div style="margin-top: 1rem;">
                <h4 style="margin-bottom: 0.5rem; color: var(--gray); font-size: 0.9rem;">Social Links</h4>
                <div style="display: flex; gap: 1rem;">
                    <?php if ($instructor['linkedin_url']): ?>
                        <a href="<?php echo htmlspecialchars($instructor['linkedin_url']); ?>" target="_blank" 
                           style="color: var(--primary); text-decoration: none;">
                            <i class="fab fa-linkedin"></i> LinkedIn
                        </a>
                    <?php endif; ?>
                    <?php if ($instructor['github_url']): ?>
                        <a href="<?php echo htmlspecialchars($instructor['github_url']); ?>" target="_blank"
                           style="color: var(--dark); text-decoration: none;">
                            <i class="fab fa-github"></i> GitHub
                        </a>
                    <?php endif; ?>
                    <?php if ($instructor['website']): ?>
                        <a href="<?php echo htmlspecialchars($instructor['website']); ?>" target="_blank"
                           style="color: var(--info); text-decoration: none;">
                            <i class="fas fa-globe"></i> Website
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Account Information -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Account Information</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                <div>
                    <h4 style="margin-bottom: 0.5rem; color: var(--gray); font-size: 0.9rem;">Account Details</h4>
                    <div style="background: var(--light); padding: 1rem; border-radius: 8px;">
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Role:</strong> <?php echo ucfirst($instructor['role']); ?>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Status:</strong> 
                            <span class="status-badge status-<?php echo $instructor['status']; ?>" style="display: inline-block;">
                                <?php echo ucfirst($instructor['status']); ?>
                            </span>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Joined:</strong> 
                            <?php echo date('M d, Y', strtotime($instructor['created_at'])); ?>
                        </div>
                        <?php if ($instructor['last_login']): ?>
                            <div>
                                <strong>Last Login:</strong> 
                                <?php echo date('M d, Y h:i A', strtotime($instructor['last_login'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <a href="<?php echo BASE_URL; ?>modules/admin/users/edit.php?id=<?php echo $instructor_id; ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Account
                </a>
                <?php if ($instructor['status'] === 'active'): ?>
                    <a href="?action=suspend&id=<?php echo $instructor_id; ?>" 
                       class="btn" style="background: var(--warning); color: white;"
                       onclick="return confirm('Suspend this instructor?')">
                        <i class="fas fa-pause"></i> Suspend Account
                    </a>
                <?php elseif ($instructor['status'] === 'suspended'): ?>
                    <a href="?action=activate&id=<?php echo $instructor_id; ?>" 
                       class="btn" style="background: var(--success); color: white;"
                       onclick="return confirm('Activate this instructor?')">
                        <i class="fas fa-play"></i> Activate Account
                    </a>
                <?php endif; ?>
                <a href="?action=delete&id=<?php echo $instructor_id; ?>" 
                   class="btn" style="background: var(--danger); color: white;"
                   onclick="return confirm('Delete this instructor? This action cannot be undone.')">
                    <i class="fas fa-trash"></i> Delete Account
                </a>
            </div>
        </div>
    </div>

    <script>
        // Handle URL actions
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');
        
        if (action && id) {
            // Actions are handled server-side via the functions
            // Remove action from URL after processing
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            window.history.replaceState({}, document.title, url.toString());
        }
    </script>
</body>
</html>