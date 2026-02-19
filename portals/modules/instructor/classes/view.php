<?php
// modules/instructor/classes/view.php

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

// Get class ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.description as course_description,
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
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

// Get class statistics
$stats = [
    'students' => 0,
    'assignments' => 0,
    'materials' => 0,
    'announcements' => 0
];

// Student count
$sql = "SELECT COUNT(*) as count FROM enrollments WHERE class_id = ? AND status = 'active'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['students'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Assignment count
$sql = "SELECT COUNT(*) as count FROM assignments WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['assignments'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Material count
$sql = "SELECT COUNT(*) as count FROM materials WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['materials'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Announcement count
$sql = "SELECT COUNT(*) as count FROM announcements WHERE class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['announcements'] = $result->fetch_assoc()['count'] ?? 0;
$stmt->close();

// Get recent students
$students = [];
$sql = "SELECT u.*, e.enrollment_date, e.status as enrollment_status
        FROM enrollments e 
        JOIN users u ON e.student_id = u.id 
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY e.enrollment_date DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent assignments
$assignments = [];
$sql = "SELECT * FROM assignments 
        WHERE class_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent announcements
$announcements = [];
$sql = "SELECT * FROM announcements 
        WHERE class_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$announcements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// Log activity
logActivity('view_class', "Viewed class: {$class['batch_code']} - {$class['name']}", 'class_batches', $class_id);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            padding: 2rem;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .header-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Buttons */
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
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-card.students {
            border-top-color: var(--success);
        }

        .stat-card.assignments {
            border-top-color: var(--warning);
        }

        .stat-card.materials {
            border-top-color: var(--primary);
        }

        .stat-card.announcements {
            border-top-color: #8b5cf6;
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

        .stat-link {
            margin-top: 0.5rem;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .stat-link a:hover {
            text-decoration: underline;
        }

        /* Main Content */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Class Info Card */
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .info-card h3 {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 1rem;
        }

        .info-label {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
        }

        .info-value a {
            color: var(--primary);
            text-decoration: none;
        }

        .info-value a:hover {
            text-decoration: underline;
        }

        /* Lists */
        .list-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .list-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
        }

        .list-content {
            max-height: 300px;
            overflow-y: auto;
        }

        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .item-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .item-icon.student {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .item-icon.assignment {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .item-icon.announcement {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .item-content {
            flex: 1;
        }

        .item-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .item-meta {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Status Badge */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
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

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .quick-actions h3 {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            background: white;
        }

        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        .action-label {
            font-size: 0.875rem;
            font-weight: 600;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Back Link -->
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Classes
        </a>

        <!-- Header -->
        <div class="header">
            <div class="header-info">
                <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                <p><?php echo htmlspecialchars($class['name']); ?></p>
            </div>
            <div class="header-actions">
                <span class="status-badge status-<?php echo $class['status']; ?>">
                    <?php echo ucfirst($class['status']); ?>
                </span>
                <a href="edit.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Edit Class
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/" class="btn btn-primary">
                    <i class="fas fa-door-open"></i> Enter Class
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-value"><?php echo $stats['students']; ?></div>
                <div class="stat-label">Students</div>
                <div class="stat-link">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/students/">View Students →</a>
                </div>
            </div>
            <div class="stat-card assignments">
                <div class="stat-value"><?php echo $stats['assignments']; ?></div>
                <div class="stat-label">Assignments</div>
                <div class="stat-link">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/">View Assignments →</a>
                </div>
            </div>
            <div class="stat-card materials">
                <div class="stat-value"><?php echo $stats['materials']; ?></div>
                <div class="stat-label">Materials</div>
                <div class="stat-link">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/">View Materials →</a>
                </div>
            </div>
            <div class="stat-card announcements">
                <div class="stat-value"><?php echo $stats['announcements']; ?></div>
                <div class="stat-label">Announcements</div>
                <div class="stat-link">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/announcements/">View Announcements →</a>
                </div>
            </div>
        </div>

        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Class Information -->
                <div class="info-card">
                    <h3>Class Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Course</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($class['program_code'] . ' - ' . $class['course_code'] . ': ' . $class['course_title']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Program</div>
                            <div class="info-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Dates</div>
                            <div class="info-value">
                                <?php echo date('M d, Y', strtotime($class['start_date'])); ?> -
                                <?php echo date('M d, Y', strtotime($class['end_date'])); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Schedule</div>
                            <div class="info-value"><?php echo htmlspecialchars($class['schedule'] ?? 'Not specified'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Capacity</div>
                            <div class="info-value"><?php echo $class['max_students']; ?> students</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Instructor</div>
                            <div class="info-value"><?php echo htmlspecialchars($class['instructor_name']); ?></div>
                        </div>
                        <?php if (!empty($class['meeting_link'])): ?>
                            <div class="info-item">
                                <div class="info-label">Meeting Link</div>
                                <div class="info-value">
                                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank">
                                        <i class="fas fa-video"></i> Join Class
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($class['description'])): ?>
                        <div style="margin-top: 1.5rem;">
                            <div class="info-label">Description</div>
                            <div class="info-value" style="line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($class['description'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Assignments -->
                <div class="list-card">
                    <div class="list-header">
                        <h3>Recent Assignments</h3>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                            View All
                        </a>
                    </div>
                    <div class="list-content">
                        <?php if (empty($assignments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <p>No assignments yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="list-item">
                                    <div class="item-icon assignment">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                        <div class="item-meta">
                                            Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?> •
                                            <?php echo $assignment['total_points']; ?> points
                                        </div>
                                    </div>
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/view.php?id=<?php echo $assignment['id']; ?>"
                                        class="btn btn-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                                        View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h3>Quick Actions</h3>
                    <div class="action-grid">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/upload.php" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div class="action-label">Upload Material</div>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/create.php" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="action-label">New Assignment</div>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/announcements/create.php" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="action-label">Post Announcement</div>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/gradebook/" class="action-item">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="action-label">Gradebook</div>
                        </a>
                    </div>
                </div>

                <!-- Recent Students -->
                <div class="list-card">
                    <div class="list-header">
                        <h3>Recent Students</h3>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/students/" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                            View All
                        </a>
                    </div>
                    <div class="list-content">
                        <?php if (empty($students)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate"></i>
                                <p>No students enrolled</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <div class="list-item">
                                    <div class="item-icon student">
                                        <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                        <div class="item-meta">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <div class="list-card">
                    <div class="list-header">
                        <h3>Recent Announcements</h3>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/announcements/" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                            View All
                        </a>
                    </div>
                    <div class="list-content">
                        <?php if (empty($announcements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <p>No announcements yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="list-item">
                                    <div class="item-icon announcement">
                                        <i class="fas fa-bullhorn"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                        <div class="item-meta">
                                            <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Confirm before deleting
        function confirmDelete() {
            return confirm('Are you sure you want to delete this class? This action cannot be undone.');
        }

        // Copy meeting link to clipboard
        function copyMeetingLink() {
            const link = "<?php echo htmlspecialchars($class['meeting_link']); ?>";
            if (link) {
                navigator.clipboard.writeText(link)
                    .then(() => alert('Meeting link copied to clipboard!'))
                    .catch(err => console.error('Failed to copy:', err));
            }
        }
    </script>
</body>

</html>