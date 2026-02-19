<?php
// modules/admin/academic/programs/select-courses.php

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
$target_program_id = isset($_GET['target_program_id']) ? (int)$_GET['target_program_id'] : 0;
$source_program_id = isset($_GET['source_program_id']) ? (int)$_GET['source_program_id'] : 0;

if (!$target_program_id) {
    $_SESSION['error'] = 'Target program ID is required';
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Fetch target program details
$target_program_sql = "SELECT * FROM programs WHERE id = ?";
$target_stmt = $conn->prepare($target_program_sql);
$target_stmt->bind_param("i", $target_program_id);
$target_stmt->execute();
$target_program = $target_stmt->get_result()->fetch_assoc();

if (!$target_program) {
    $_SESSION['error'] = 'Target program not found';
    header('Location: index.php');
    exit();
}

// Fetch all programs except the target program
$programs_sql = "SELECT id, program_code, name FROM programs WHERE id != ? AND status = 'active' ORDER BY name";
$programs_stmt = $conn->prepare($programs_sql);
$programs_stmt->bind_param("i", $target_program_id);
$programs_stmt->execute();
$programs = $programs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch courses based on source program selection
$courses = [];
if ($source_program_id) {
    $courses_sql = "SELECT c.*, p.program_code as source_program_code, p.name as source_program_name
                    FROM courses c
                    JOIN programs p ON c.program_id = p.id
                    WHERE c.program_id = ? AND c.status = 'active'
                    ORDER BY c.order_number";

    $courses_stmt = $conn->prepare($courses_sql);
    $courses_stmt->bind_param("i", $source_program_id);
    $courses_stmt->execute();
    $courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission for adding courses
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_courses = isset($_POST['selected_courses']) ? $_POST['selected_courses'] : [];

    if (empty($selected_courses)) {
        $_SESSION['error'] = 'Please select at least one course';
        // Don't redirect - stay on page
    } else {
        $success_count = 0;
        $errors = [];

        foreach ($selected_courses as $course_id) {
            // Get course details
            $course_sql = "SELECT * FROM courses WHERE id = ?";
            $course_stmt = $conn->prepare($course_sql);
            $course_stmt->bind_param("i", $course_id);
            $course_stmt->execute();
            $course = $course_stmt->get_result()->fetch_assoc();

            if ($course) {
                // Check if course already exists in target program
                $check_sql = "SELECT id FROM courses WHERE program_id = ? AND course_code = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("is", $target_program_id, $course['course_code']);
                $check_stmt->execute();

                if ($check_stmt->get_result()->num_rows > 0) {
                    $errors[] = "Course {$course['course_code']} already exists in this program";
                    continue;
                }

                // Copy course to target program
                $copy_sql = "INSERT INTO courses (program_id, course_code, title, description, duration_hours, level, order_number, is_required, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $copy_stmt = $conn->prepare($copy_sql);
                $copy_stmt->bind_param(
                    "isssisiss",
                    $target_program_id,
                    $course['course_code'],
                    $course['title'],
                    $course['description'],
                    $course['duration_hours'],
                    $course['level'],
                    $course['order_number'],
                    $course['is_required'],
                    $course['status']
                );

                if ($copy_stmt->execute()) {
                    $new_course_id = $copy_stmt->insert_id;

                    // Log activity
                    logActivity(
                        'course_copy',
                        "Copied course {$course['course_code']} from program {$course['program_id']} to program {$target_program_id}",
                        'courses',
                        $new_course_id
                    );

                    $success_count++;
                } else {
                    $errors[] = "Failed to copy course {$course['course_code']}";
                }
            }
        }

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully added {$success_count} course(s) to the program";

            if (!empty($errors)) {
                $_SESSION['warning'] = implode('<br>', $errors);
            }

            header("Location: view.php?id={$target_program_id}");
            exit();
        } else {
            $_SESSION['error'] = "Failed to add courses: " . implode('<br>', $errors);
        }
    }
}

// Log activity
logActivity('program_select_courses', "Accessed course selection for program: {$target_program['program_code']}", 'programs', $target_program_id);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Courses from Other Programs - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            min-height: 100vh;
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
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
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

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            color: var(--dark);
            font-weight: 500;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 1rem;
            background: white;
            color: var(--dark);
            transition: border-color 0.3s ease;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .program-info {
            background: var(--light);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .program-info h3 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .course-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .course-item {
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            margin-bottom: 1rem;
            padding: 1rem;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }

        .course-item:hover {
            border-color: var(--primary);
            background: var(--light);
        }

        .course-item.selected {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }

        .course-checkbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 20px;
            height: 20px;
            accent-color: var(--success);
            cursor: pointer;
        }

        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .course-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9rem;
            background: rgba(37, 99, 235, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .course-source {
            font-size: 0.85rem;
            color: var(--gray);
            background: rgba(100, 116, 139, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .course-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .course-details {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
            flex-wrap: wrap;
        }

        .course-details span {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .course-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-top: 0.5rem;
            max-height: 3em;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
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

        .btn-success:hover {
            background: #0da271;
            transform: translateY(-2px);
        }

        .no-data {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--light-gray);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .stats-summary {
            display: flex;
            justify-content: space-between;
            background: var(--light);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--light);
            border-radius: 6px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .stats-summary {
                flex-direction: column;
                gap: 1rem;
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
            <a href="view.php?id=<?php echo $target_program_id; ?>">
                <?php echo htmlspecialchars($target_program['program_code']); ?>
            </a>
            <i class="fas fa-chevron-right"></i>
            <span>Add Courses from Other Programs</span>
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

        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_SESSION['warning']); ?>
                <?php unset($_SESSION['warning']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Add Courses from Other Programs</h1>
            <p class="page-subtitle">
                Select courses from existing programs to add to
                <strong><?php echo htmlspecialchars($target_program['name']); ?> (<?php echo htmlspecialchars($target_program['program_code']); ?>)</strong>
            </p>
        </div>

        <!-- Program Selection Form (GET method) - SEPARATE FORM -->
        <form method="GET" action="" id="program-form">
            <input type="hidden" name="target_program_id" value="<?php echo $target_program_id; ?>">

            <div class="content-grid">
                <!-- Left Column: Program Selection -->
                <div>
                    <div class="section-card">
                        <div class="section-header">
                            <h2>Select Source Program</h2>
                        </div>
                        <div class="section-content">
                            <div class="form-group">
                                <label for="source_program_id">
                                    <i class="fas fa-filter"></i> Filter by Program
                                </label>
                                <select name="source_program_id" id="source_program_id" class="form-select" onchange="document.getElementById('program-form').submit()">
                                    <option value="">-- Select a program --</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>"
                                            <?php echo $program['id'] == $source_program_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="program-info">
                                <h3>Target Program</h3>
                                <p><strong><?php echo htmlspecialchars($target_program['name']); ?></strong></p>
                                <p><small><?php echo htmlspecialchars($target_program['program_code']); ?></small></p>
                            </div>

                            <div class="program-info">
                                <h3>Instructions</h3>
                                <ol style="padding-left: 1.5rem; margin: 0;">
                                    <li>Select a source program from the dropdown</li>
                                    <li>Check the courses you want to add</li>
                                    <li>Click "Add Selected Courses"</li>
                                    <li>Courses will be copied to your target program</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Course Selection Display Area -->
                <div>
                    <?php if ($source_program_id): ?>
                        <!-- Course selection will be displayed here via the separate POST form below -->
                        <div id="course-selection-area">
                            <!-- This will be filled by the POST form below -->
                        </div>
                    <?php else: ?>
                        <div class="section-card">
                            <div class="section-header">
                                <h2>Select Courses</h2>
                            </div>
                            <div class="section-content">
                                <div class="no-data">
                                    <i class="fas fa-hand-pointer"></i>
                                    <h3>Select a program to begin</h3>
                                    <p>Choose a source program from the dropdown to view its courses</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Course Selection Form (POST method) - SEPARATE FORM -->
        <?php if ($source_program_id && !empty($courses)): ?>
            <form method="POST" action="" id="courses-form">
                <input type="hidden" name="target_program_id" value="<?php echo $target_program_id; ?>">
                <input type="hidden" name="source_program_id" value="<?php echo $source_program_id; ?>">

                <div style="margin-top: 2rem;">
                    <div class="section-card">
                        <div class="section-header">
                            <h2>Select Courses from
                                <?php
                                $selected_program_name = "";
                                foreach ($programs as $program) {
                                    if ($program['id'] == $source_program_id) {
                                        $selected_program_name = $program['program_code'] . ' - ' . $program['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($selected_program_name);
                                ?>
                            </h2>
                        </div>
                        <div class="section-content">
                            <div class="stats-summary">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo count($courses); ?></div>
                                    <div class="stat-label">Total Courses</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">
                                        <?php
                                        $required_count = array_filter($courses, function ($c) {
                                            return $c['is_required'] == 1;
                                        });
                                        echo count($required_count);
                                        ?>
                                    </div>
                                    <div class="stat-label">Required Courses</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">
                                        <?php
                                        $elective_count = array_filter($courses, function ($c) {
                                            return $c['is_required'] == 0;
                                        });
                                        echo count($elective_count);
                                        ?>
                                    </div>
                                    <div class="stat-label">Elective Courses</div>
                                </div>
                            </div>

                            <div class="select-all">
                                <input type="checkbox" id="select-all-courses" onchange="toggleAllCourses(this)">
                                <label for="select-all-courses"><strong>Select/Deselect All Courses</strong></label>
                            </div>

                            <div class="course-list">
                                <?php foreach ($courses as $index => $course): ?>
                                    <div class="course-item" onclick="toggleCourse(<?php echo $course['id']; ?>)">
                                        <input type="checkbox"
                                            name="selected_courses[]"
                                            value="<?php echo $course['id']; ?>"
                                            id="course_<?php echo $course['id']; ?>"
                                            class="course-checkbox"
                                            onchange="updateCourseSelection(this)">

                                        <div class="course-header">
                                            <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                            <div class="course-source">
                                                <i class="fas fa-external-link-alt"></i>
                                                From: <?php echo htmlspecialchars($course['source_program_code']); ?>
                                            </div>
                                        </div>

                                        <div class="course-title">
                                            <?php echo htmlspecialchars($course['title']); ?>
                                            <?php if ($course['is_required']): ?>
                                                <span style="color: var(--danger); font-size: 0.8rem; margin-left: 0.5rem;">
                                                    <i class="fas fa-star"></i> Required
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--success); font-size: 0.8rem; margin-left: 0.5rem;">
                                                    <i class="fas fa-star"></i> Elective
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="course-details">
                                            <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                            <span><i class="fas fa-signal"></i> <?php echo ucfirst($course['level']); ?></span>
                                            <span>
                                                <i class="fas fa-layer-group"></i>
                                                Order: <?php echo $course['order_number']; ?>
                                            </span>
                                        </div>

                                        <?php if ($course['description']): ?>
                                            <div class="course-description">
                                                <?php echo htmlspecialchars(substr($course['description'], 0, 150)); ?>
                                                <?php if (strlen($course['description']) > 150): ?>...<?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="actions">
                                <a href="view.php?id=<?php echo $target_program_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" name="add_courses" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Add Selected Courses
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php elseif ($source_program_id && empty($courses)): ?>
            <div style="margin-top: 2rem;">
                <div class="section-card">
                    <div class="section-header">
                        <h2>No Courses Found</h2>
                    </div>
                    <div class="section-content">
                        <div class="no-data">
                            <i class="fas fa-book"></i>
                            <h3>No courses available</h3>
                            <p>The selected program doesn't have any active courses</p>
                            <a href="view.php?id=<?php echo $target_program_id; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-arrow-left"></i> Back to Program
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleAllCourses(checkbox) {
            const courseCheckboxes = document.querySelectorAll('.course-checkbox');
            courseCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                updateCourseSelection(cb);
            });
        }

        function toggleCourse(courseId) {
            const checkbox = document.getElementById('course_' + courseId);
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                updateCourseSelection(checkbox);
            }
        }

        function updateCourseSelection(checkbox) {
            const courseItem = checkbox.closest('.course-item');
            if (checkbox.checked) {
                courseItem.classList.add('selected');
            } else {
                courseItem.classList.remove('selected');
            }
        }

        // Show loading when selecting program
        document.getElementById('source_program_id').addEventListener('change', function() {
            // Show loading state in course section
            const courseSection = document.getElementById('course-selection-area');
            if (courseSection) {
                courseSection.innerHTML = `
                    <div class="section-card">
                        <div class="section-header">
                            <h2>Loading Courses...</h2>
                        </div>
                        <div class="section-content">
                            <div class="loading">
                                <div class="loading-spinner"></div>
                                <p>Loading courses from selected program...</p>
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        // Show confirmation before submitting
        document.getElementById('courses-form')?.addEventListener('submit', function(e) {
            const selectedCourses = document.querySelectorAll('.course-checkbox:checked');
            if (selectedCourses.length === 0) {
                e.preventDefault();
                alert('Please select at least one course to add.');
                return false;
            }

            return confirm(`Are you sure you want to add ${selectedCourses.length} course(s) to this program?`);
        });
    </script>
</body>

</html>