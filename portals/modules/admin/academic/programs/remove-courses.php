<?php
// modules/admin/academic/programs/remove-courses.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get program ID from URL
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

if (!$program_id) {
    $_SESSION['error'] = 'Program ID is required';
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Handle bulk removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_remove'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: remove-courses.php?program_id=' . $program_id);
        exit();
    }

    $selected_courses = isset($_POST['selected_courses']) ? $_POST['selected_courses'] : [];
    $hard_delete = isset($_POST['hard_delete']) && $_POST['hard_delete'] === '1';

    if (empty($selected_courses)) {
        $_SESSION['error'] = 'Please select at least one course to remove';
    } else {
        $results = bulkRemoveCoursesFromProgram($conn, $program_id, $selected_courses, $hard_delete);

        $success_count = 0;
        $fail_count = 0;
        $messages = [];

        foreach ($results as $course_id => $result) {
            if ($result['success']) {
                $success_count++;
                $messages[] = "Course ID $course_id: " . $result['message'];
            } else {
                $fail_count++;
                $messages[] = "Course ID $course_id: " . $result['message'];
            }
        }

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully removed $success_count course(s). " . implode(' | ', $messages);
        }
        if ($fail_count > 0) {
            $_SESSION['error'] = "Failed to remove $fail_count course(s). " . implode(' | ', $messages);
        }

        // Log activity
        logActivity('courses_bulk_removed', "Removed $success_count courses from program $program_id", 'programs', $program_id);
    }

    header('Location: view.php?id=' . $program_id);
    exit();
}

// Handle single course removal
if (isset($_GET['remove']) && isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    $hard_delete = isset($_GET['hard_delete']) && $_GET['hard_delete'] === '1';

    // Check if course can be removed
    $check = canRemoveCourseFromProgram($conn, $program_id, $course_id);

    if ($check['can_remove']) {
        $result = removeCourseFromProgram($conn, $program_id, $course_id, $hard_delete);

        if ($result['success']) {
            $_SESSION['success'] = 'Course removed successfully. ' . $result['message'];
            if (!empty($result['affected_data'])) {
                $_SESSION['info'] = 'Affected data: ' . json_encode($result['affected_data']);
            }
        } else {
            $_SESSION['error'] = 'Failed to remove course: ' . $result['message'];
        }
    } else {
        $_SESSION['error'] = 'Cannot remove course: ' . $check['message'];
    }

    // Log activity
    logActivity('course_removed', "Removed course $course_id from program $program_id", 'programs', $program_id);

    header('Location: view.php?id=' . $program_id);
    exit();
}

// Fetch program details
$program_sql = "SELECT name, program_code FROM programs WHERE id = ?";
$program_stmt = $conn->prepare($program_sql);
$program_stmt->bind_param("i", $program_id);
$program_stmt->execute();
$program_result = $program_stmt->get_result();
$program = $program_result->fetch_assoc();

if (!$program) {
    $_SESSION['error'] = 'Program not found';
    header('Location: index.php');
    exit();
}

// Fetch all courses in this program with removal check status
$courses_sql = "SELECT c.*, 
                       COUNT(DISTINCT cb.id) as class_count,
                       COUNT(DISTINCT e.id) as student_count,
                       (SELECT COUNT(*) FROM courses WHERE prerequisite_course_id = c.id AND program_id = ?) as is_prerequisite_for
                FROM courses c
                LEFT JOIN class_batches cb ON c.id = cb.course_id
                LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
                WHERE c.program_id = ?
                GROUP BY c.id
                ORDER BY c.order_number, c.title";

$courses_stmt = $conn->prepare($courses_sql);
$courses_stmt->bind_param("ii", $program_id, $program_id);
$courses_stmt->execute();
$courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check removal status for each course
foreach ($courses as &$course) {
    $check = canRemoveCourseFromProgram($conn, $program_id, $course['id']);
    $course['can_remove'] = $check['can_remove'];
    $course['remove_message'] = $check['message'];
    $course['warnings'] = $check['warnings'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remove Courses - <?php echo htmlspecialchars($program['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
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
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .program-info {
            color: var(--gray);
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .section-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--light-gray);
        }

        .section-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .section-content {
            padding: 1.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .alert-warning {
            background-color: #ffedd5;
            color: #9a3412;
            border: 1px solid #fed7aa;
        }

        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid var(--warning);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            color: #856404;
        }

        .warning-box i {
            margin-right: 0.5rem;
            color: var(--warning);
        }

        .courses-table {
            width: 100%;
            border-collapse: collapse;
        }

        .courses-table th {
            text-align: left;
            padding: 1rem;
            background: var(--light);
            border-bottom: 2px solid var(--light-gray);
            color: var(--dark);
            font-weight: 600;
        }

        .courses-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .courses-table tr:hover {
            background: var(--light);
        }

        .course-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .can-remove-yes {
            color: var(--success);
            font-weight: 600;
        }

        .can-remove-no {
            color: var(--danger);
            font-weight: 600;
        }

        .warning-text {
            color: var(--warning);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .checkbox-column {
            width: 40px;
            text-align: center;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: var(--dark);
            color: white;
            text-align: center;
            padding: 5px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .bulk-actions {
            background: var(--light);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .courses-table {
                display: block;
                overflow-x: auto;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <a href="view.php?id=<?php echo $program_id; ?>"><?php echo htmlspecialchars($program['program_code']); ?></a>
            <i class="fas fa-chevron-right"></i>
            <span>Remove Courses</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Remove Courses from Program</h1>
                <div class="program-info">
                    <i class="fas fa-graduation-cap"></i>
                    <?php echo htmlspecialchars($program['name']); ?> (<?php echo htmlspecialchars($program['program_code']); ?>)
                </div>
            </div>
            <a href="view.php?id=<?php echo $program_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Program
            </a>
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

        <?php if (isset($_SESSION['info'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <?php echo htmlspecialchars($_SESSION['info']); ?>
                <?php unset($_SESSION['info']); ?>
            </div>
        <?php endif; ?>

        <!-- Warning Box -->
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Warning:</strong> Removing courses from a program is permanent and may affect student enrollments,
            grades, and other related data. Please review the warnings for each course before proceeding.
        </div>

        <!-- Main Content -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="fas fa-list"></i> Courses in Program</h2>
                <span style="background: rgba(255,255,255,0.2); padding: 0.25rem 1rem; border-radius: 20px;">
                    Total: <?php echo count($courses); ?>
                </span>
            </div>
            <div class="section-content">
                <?php if (empty($courses)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray);">
                        <i class="fas fa-book" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                        <h3>No Courses Found</h3>
                        <p>This program doesn't have any courses yet.</p>
                    </div>
                <?php else: ?>
                    <!-- Bulk Actions Form -->
                    <form method="POST" id="bulkRemoveForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="bulk_remove" value="1">

                        <div class="bulk-actions">
                            <div class="checkbox-group">
                                <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                <label for="selectAll">Select All</label>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" name="hard_delete" id="hard_delete" value="1">
                                <label for="hard_delete" class="tooltip">
                                    Hard Delete (Permanent)
                                    <span class="tooltiptext">Permanently deletes all course data including classes, enrollments, grades, etc.</span>
                                </label>
                            </div>

                            <button type="submit" class="btn btn-danger" onclick="return confirmBulkDelete()">
                                <i class="fas fa-trash-alt"></i> Remove Selected Courses
                            </button>
                        </div>

                        <!-- Courses Table -->
                        <table class="courses-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-column">
                                        <i class="fas fa-check"></i>
                                    </th>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Status</th>
                                    <th>Classes</th>
                                    <th>Students</th>
                                    <th>Removable</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $course): ?>
                                    <tr>
                                        <td class="checkbox-column">
                                            <input type="checkbox" name="selected_courses[]"
                                                value="<?php echo $course['id']; ?>"
                                                <?php echo $course['can_remove'] ? '' : 'disabled'; ?>
                                                onchange="updateSelectAll()">
                                        </td>
                                        <td>
                                            <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($course['title']); ?></strong>
                                            <?php if (!empty($course['warnings'])): ?>
                                                <div class="warning-text">
                                                    <?php foreach ($course['warnings'] as $warning): ?>
                                                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($warning); ?><br>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $course['status']; ?>">
                                                <?php echo ucfirst($course['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $course['class_count'] ?: '0'; ?></td>
                                        <td><?php echo $course['student_count'] ?: '0'; ?></td>
                                        <td>
                                            <?php if ($course['can_remove']): ?>
                                                <span class="can-remove-yes">
                                                    <i class="fas fa-check-circle"></i> Yes
                                                </span>
                                            <?php else: ?>
                                                <span class="can-remove-no tooltip">
                                                    <i class="fas fa-times-circle"></i> No
                                                    <span class="tooltiptext"><?php echo htmlspecialchars($course['remove_message']); ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($course['can_remove']): ?>
                                                    <a href="?program_id=<?php echo $program_id; ?>&remove=1&course_id=<?php echo $course['id']; ?>&hard_delete=0"
                                                        class="btn btn-warning btn-sm"
                                                        onclick="return confirm('Deactivate this course? It will be marked as inactive but data will be preserved.')">
                                                        <i class="fas fa-pause-circle"></i> Deactivate
                                                    </a>
                                                    <a href="?program_id=<?php echo $program_id; ?>&remove=1&course_id=<?php echo $course['id']; ?>&hard_delete=1"
                                                        class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Permanently delete this course and all its data? This cannot be undone.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                <?php else: ?>
                                                    <span class="tooltip">
                                                        <button class="btn btn-secondary btn-sm" disabled>
                                                            <i class="fas fa-ban"></i> Cannot Remove
                                                        </button>
                                                        <span class="tooltiptext"><?php echo htmlspecialchars($course['remove_message']); ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Additional Info Card -->
        <div style="margin-top: 2rem; background: white; border-radius: 8px; padding: 1.5rem; border: 1px solid var(--light-gray);">
            <h3 style="margin-bottom: 1rem; color: var(--dark);">
                <i class="fas fa-info-circle"></i> About Course Removal
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                <div>
                    <h4 style="color: var(--primary); margin-bottom: 0.5rem;">Soft Delete (Deactivate)</h4>
                    <p style="color: var(--gray); font-size: 0.9rem; line-height: 1.5;">
                        Marks the course as inactive. The course remains in the database but is hidden from active views.
                        Student data, grades, and enrollments are preserved. You can reactivate the course later.
                    </p>
                </div>
                <div>
                    <h4 style="color: var(--danger); margin-bottom: 0.5rem;">Hard Delete (Permanent)</h4>
                    <p style="color: var(--gray); font-size: 0.9rem; line-height: 1.5;">
                        Permanently deletes the course and all associated data including classes, enrollments, grades,
                        submissions, payments, and attendance records. This action cannot be undone.
                    </p>
                </div>
                <div>
                    <h4 style="color: var(--warning); margin-bottom: 0.5rem;">Removal Restrictions</h4>
                    <p style="color: var(--gray); font-size: 0.9rem; line-height: 1.5;">
                        Courses with active classes, enrolled students, or serving as prerequisites cannot be removed.
                        You must first resolve these dependencies (e.g., complete classes, unenroll students).
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Select/Deselect all functionality
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('input[name="selected_courses[]"]:not([disabled])');
            checkboxes.forEach(checkbox => checkbox.checked = source.checked);
        }

        function updateSelectAll() {
            const checkboxes = document.querySelectorAll('input[name="selected_courses[]"]:not([disabled])');
            const selectAll = document.getElementById('selectAll');
            const checkedCount = document.querySelectorAll('input[name="selected_courses[]"]:checked').length;

            if (checkedCount === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (checkedCount === checkboxes.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.indeterminate = true;
            }
        }

        // Confirm bulk delete
        function confirmBulkDelete() {
            const selected = document.querySelectorAll('input[name="selected_courses[]"]:checked');
            const hardDelete = document.getElementById('hard_delete').checked;

            if (selected.length === 0) {
                alert('Please select at least one course to remove.');
                return false;
            }

            const action = hardDelete ? 'permanently delete' : 'deactivate';
            const message = `Are you sure you want to ${action} ${selected.length} course(s)?\n\n` +
                (hardDelete ?
                    'This will permanently delete all course data including classes, enrollments, grades, and payments. This action CANNOT be undone.' :
                    'These courses will be marked as inactive but data will be preserved. You can reactivate them later.');

            return confirm(message);
        }

        // Auto-refresh select all state
        document.querySelectorAll('input[name="selected_courses[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectAll);
        });

        // Initialize tooltips
        document.querySelectorAll('.tooltip').forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltip = this.querySelector('.tooltiptext');
                if (tooltip) {
                    tooltip.style.visibility = 'visible';
                    tooltip.style.opacity = '1';
                }
            });
            element.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.tooltiptext');
                if (tooltip) {
                    tooltip.style.visibility = 'hidden';
                    tooltip.style.opacity = '0';
                }
            });
        });

        // Add keyboard shortcut for select all (Ctrl+A)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                toggleSelectAll({
                    checked: !document.getElementById('selectAll').checked
                });
            }
        });
    </script>
</body>

</html>

<?php
// Close database connections
$program_stmt->close();
$courses_stmt->close();
$conn->close();
?>