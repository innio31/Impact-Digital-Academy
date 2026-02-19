<?php
// modules/admin/instructors/assign.php

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
$instructor = null;

// Fetch instructor details if ID provided
if ($instructor_id) {
    $stmt = $conn->prepare("
        SELECT u.*, up.current_job_title, up.current_company, up.qualifications
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'instructor'
    ");
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $instructor = $result->fetch_assoc();
}

// Fetch all active classes
$classes_query = "
    SELECT cb.*, c.title as course_title, p.name as program_name
    FROM class_batches cb
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    WHERE cb.status IN ('scheduled', 'ongoing')
    ORDER BY cb.start_date ASC
";
$classes_result = $conn->query($classes_query);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// Fetch all active instructors
$instructors_query = "
    SELECT u.id, u.first_name, u.last_name, u.email, 
           COUNT(DISTINCT cb.id) as current_load
    FROM users u
    LEFT JOIN class_batches cb ON u.id = cb.instructor_id AND cb.status IN ('scheduled', 'ongoing')
    WHERE u.role = 'instructor' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
";
$instructors_result = $conn->query($instructors_query);
$all_instructors = $instructors_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = $_POST['class_id'];
    $instructor_id = $_POST['instructor_id'];
    $action = $_POST['action'];
    
    if ($action === 'assign') {
        // Update class with new instructor
        $update_stmt = $conn->prepare("
            UPDATE class_batches 
            SET instructor_id = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->bind_param("ii", $instructor_id, $class_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Instructor assigned successfully to class";
            
            // Log the assignment
            logActivity('instructor_assignment', 
                "Assigned instructor #$instructor_id to class #$class_id", 
                'class_batches', $class_id);
            
            // Redirect based on context
            if (isset($_GET['id'])) {
                header("Location: view.php?id=" . $_GET['id']);
            } else {
                header("Location: index.php");
            }
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign instructor";
        }
    } elseif ($action === 'unassign') {
        // Remove instructor from class
        $update_stmt = $conn->prepare("
            UPDATE class_batches 
            SET instructor_id = NULL, updated_at = NOW() 
            WHERE id = ? AND instructor_id = ?
        ");
        $update_stmt->bind_param("ii", $class_id, $instructor_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Instructor unassigned successfully";
            
            logActivity('instructor_unassignment', 
                "Unassigned instructor #$instructor_id from class #$class_id", 
                'class_batches', $class_id);
            
            if (isset($_GET['id'])) {
                header("Location: view.php?id=" . $_GET['id']);
            } else {
                header("Location: index.php");
            }
            exit();
        } else {
            $_SESSION['error'] = "Failed to unassign instructor";
        }
    }
}

// Fetch instructor's current assignments if viewing specific instructor
$current_assignments = [];
if ($instructor_id) {
    $assignments_stmt = $conn->prepare("
        SELECT cb.*, c.title as course_title, p.name as program_name
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        WHERE cb.instructor_id = ? AND cb.status IN ('scheduled', 'ongoing')
        ORDER BY cb.start_date ASC
    ");
    $assignments_stmt->bind_param("i", $instructor_id);
    $assignments_stmt->execute();
    $assignments_result = $assignments_stmt->get_result();
    $current_assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $instructor ? 'Assign Instructor' : 'Manage Assignments'; ?> - Impact Digital Academy</title>
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

        .page-title {
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
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

        .instructor-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem;
            background: var(--light);
            border-radius: 8px;
        }

        .instructor-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            border: 3px solid var(--primary);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
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
            <span><?php echo $instructor ? 'Assign Instructor' : 'Manage Assignments'; ?></span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1><?php echo $instructor ? 'Assign Instructor' : 'Manage Class Assignments'; ?></h1>
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

        <?php if ($instructor): ?>
        <!-- Instructor Info -->
        <div class="instructor-info">
            <div class="instructor-avatar">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div>
                <h3 style="margin: 0 0 0.25rem 0;">
                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                </h3>
                <div style="color: var(--gray); margin-bottom: 0.25rem;">
                    <?php echo htmlspecialchars($instructor['email']); ?>
                </div>
                <?php if ($instructor['current_job_title']): ?>
                    <div style="font-size: 0.9rem;">
                        <strong><?php echo htmlspecialchars($instructor['current_job_title']); ?></strong>
                        <?php if ($instructor['current_company']): ?>
                            at <?php echo htmlspecialchars($instructor['current_company']); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current Assignments -->
        <?php if ($instructor && !empty($current_assignments)): ?>
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Current Assignments</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Class Code</th>
                        <th>Course/Program</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Students</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($current_assignments as $assignment): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($assignment['batch_code']); ?></strong>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    <?php echo htmlspecialchars($assignment['name']); ?>
                                </div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($assignment['course_title']); ?></div>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    <?php echo htmlspecialchars($assignment['program_name']); ?>
                                </div>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($assignment['start_date'])); ?> - 
                                <?php echo date('M d, Y', strtotime($assignment['end_date'])); ?>
                                <?php if ($assignment['schedule']): ?>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($assignment['schedule']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; 
                                      background: <?php echo $assignment['status'] === 'ongoing' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>;
                                      color: <?php echo $assignment['status'] === 'ongoing' ? '#10b981' : '#f59e0b'; ?>;">
                                    <?php echo ucfirst($assignment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                // Get student count for this class
                                $student_stmt = $conn->prepare("
                                    SELECT COUNT(*) as student_count 
                                    FROM enrollments 
                                    WHERE class_id = ? AND status = 'active'
                                ");
                                $student_stmt->bind_param("i", $assignment['id']);
                                $student_stmt->execute();
                                $student_result = $student_stmt->get_result();
                                $student_count = $student_result->fetch_assoc()['student_count'];
                                ?>
                                <div style="text-align: center;">
                                    <strong style="color: var(--primary);"><?php echo $student_count; ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--gray);">Students</div>
                                </div>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="class_id" value="<?php echo $assignment['id']; ?>">
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
        </div>
        <?php endif; ?>

        <!-- Assign New Class -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Assign to New Class</h3>
            <form method="POST" id="assignForm">
                <div class="form-group">
                    <label class="form-label" for="instructor_id">Instructor</label>
                    <select id="instructor_id" name="instructor_id" class="form-control" required
                        <?php echo $instructor_id ? 'disabled' : ''; ?>>
                        <option value="">Select Instructor</option>
                        <?php foreach ($all_instructors as $inst): ?>
                            <option value="<?php echo $inst['id']; ?>"
                                <?php echo ($instructor_id == $inst['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($inst['first_name'] . ' ' . $inst['last_name']); ?>
                                (<?php echo htmlspecialchars($inst['email']); ?>)
                                - <?php echo $inst['current_load']; ?> current classes
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($instructor_id): ?>
                        <input type="hidden" name="instructor_id" value="<?php echo $instructor_id; ?>">
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="class_id">Class to Assign</label>
                    <select id="class_id" name="class_id" class="form-control" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['batch_code']); ?> - 
                                <?php echo htmlspecialchars($class['course_title']); ?> 
                                (<?php echo htmlspecialchars($class['program_name']); ?>)
                                - <?php echo date('M d, Y', strtotime($class['start_date'])); ?>
                                <?php if ($class['instructor_id']): ?>
                                    <em style="color: var(--warning);"> - Currently assigned</em>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="action" value="assign">
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Assign Instructor
                    </button>
                    <a href="<?php echo $instructor_id ? 'view.php?id=' . $instructor_id : 'index.php'; ?>" 
                       class="btn" style="background: var(--light-gray);">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Instructor Workload Overview -->
        <?php if (!$instructor_id): ?>
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Instructor Workload Overview</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Instructor</th>
                        <th>Active Classes</th>
                        <th>Scheduled Classes</th>
                        <th>Total Students</th>
                        <th>Workload</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_instructors as $inst): 
                        // Get detailed workload info
                        $workload_stmt = $conn->prepare("
                            SELECT 
                                SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as active_count,
                                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count,
                                SUM(student_count) as total_students
                            FROM (
                                SELECT cb.status, COUNT(e.id) as student_count
                                FROM class_batches cb
                                LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
                                WHERE cb.instructor_id = ?
                                GROUP BY cb.id
                            ) as class_details
                        ");
                        $workload_stmt->bind_param("i", $inst['id']);
                        $workload_stmt->execute();
                        $workload_result = $workload_stmt->get_result();
                        $workload = $workload_result->fetch_assoc();
                        
                        $total_classes = ($workload['active_count'] ?? 0) + ($workload['scheduled_count'] ?? 0);
                        $workload_level = $total_classes > 3 ? 'High' : ($total_classes > 1 ? 'Moderate' : 'Light');
                        $workload_color = $total_classes > 3 ? 'var(--danger)' : ($total_classes > 1 ? 'var(--warning)' : 'var(--success)');
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($inst['first_name'] . ' ' . $inst['last_name']); ?></strong>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    <?php echo htmlspecialchars($inst['email']); ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <strong style="color: var(--success);"><?php echo $workload['active_count'] ?? 0; ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <strong style="color: var(--info);"><?php echo $workload['scheduled_count'] ?? 0; ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <strong style="color: var(--primary);"><?php echo $workload['total_students'] ?? 0; ?></strong>
                            </td>
                            <td>
                                <span style="color: <?php echo $workload_color; ?>; font-weight: 600;">
                                    <?php echo $workload_level; ?>
                                </span>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    <?php echo $total_classes; ?> total classes
                                </div>
                            </td>
                            <td>
                                <a href="assign.php?id=<?php echo $inst['id']; ?>" class="btn" 
                                   style="background: var(--primary); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                    <i class="fas fa-tasks"></i> Manage
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-select class if coming from specific instructor page
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const classId = urlParams.get('class_id');
            if (classId) {
                document.getElementById('class_id').value = classId;
            }
        });
    </script>
</body>
</html>