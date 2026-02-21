<?php
// modules/admin/academic/courses/edit.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get course ID from URL
$course_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$course_id) {
    $_SESSION['error'] = 'Course ID is required';
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Fetch course details
$sql = "SELECT c.*, p.name as program_name, p.program_code FROM courses c 
        JOIN programs p ON c.program_id = p.id 
        WHERE c.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
$course = $result->fetch_assoc();

if (!$course) {
    $_SESSION['error'] = 'Course not found';
    header('Location: index.php');
    exit();
}

// Get all active programs for dropdown
$programs_query = "SELECT id, program_code, name FROM programs WHERE status = 'active' ORDER BY program_code";
$programs_result = $conn->query($programs_query);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $program_id = (int)($_POST['program_id'] ?? 0);
    $course_code = trim($_POST['course_code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $duration_hours = (int)($_POST['duration_hours'] ?? 40);
    $level = $_POST['level'] ?? 'beginner';
    $order_number = (int)($_POST['order_number'] ?? 0);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $status = $_POST['status'] ?? 'active';
    $prerequisite_course_id = !empty($_POST['prerequisite_course_id']) ? (int)$_POST['prerequisite_course_id'] : null;

    // Validate
    if (!$program_id) {
        $errors[] = 'Program is required';
    }

    if (empty($course_code)) {
        $errors[] = 'Course code is required';
    } elseif (!preg_match('/^[A-Z0-9\-]{2,20}$/', $course_code)) {
        $errors[] = 'Course code must be 2-20 uppercase letters, numbers, or hyphens';
    }

    if (empty($title)) {
        $errors[] = 'Course title is required';
    } elseif (strlen($title) > 150) {
        $errors[] = 'Course title must be less than 150 characters';
    }

    if ($duration_hours < 1 || $duration_hours > 500) {
        $errors[] = 'Duration must be between 1 and 500 hours';
    }

    // Check if course code already exists in the same program (excluding current course)
    $check_sql = "SELECT id FROM courses WHERE program_id = ? AND course_code = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("isi", $program_id, $course_code, $course_id);
    $check_stmt->execute();

    if ($check_stmt->get_result()->num_rows > 0) {
        $errors[] = 'Course code already exists in this program';
    }

    // If no errors, update database
    if (empty($errors)) {
        try {
            $sql = "UPDATE courses SET 
                    program_id = ?,
                    prerequisite_course_id = ?,
                    course_code = ?,
                    title = ?,
                    description = ?,
                    duration_hours = ?,
                    level = ?,
                    order_number = ?,
                    is_required = ?,
                    status = ?,
                    updated_at = NOW()
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "iisssisissi",
                $program_id,
                $prerequisite_course_id,
                $course_code,
                $title,
                $description,
                $duration_hours,
                $level,
                $order_number,
                $is_required,
                $status,
                $course_id
            );

            if ($stmt->execute()) {
                // Log activity
                logActivity('course_update', "Updated course: $course_code", 'courses', $course_id);

                $_SESSION['success'] = "Course '$title' updated successfully!";

                // Redirect to view page
                header("Location: view.php?id=$course_id");
                exit();
            } else {
                $errors[] = 'Failed to update course. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
// Get all courses from the same program (excluding current course) for prerequisite dropdown
$prerequisite_query = "SELECT id, course_code, title FROM courses 
                      WHERE program_id = ? AND id != ? AND status = 'active' 
                      ORDER BY order_number, course_code";
$prerequisite_stmt = $conn->prepare($prerequisite_query);
$current_program_id = $_POST['program_id'] ?? $course['program_id'];
$prerequisite_stmt->bind_param("ii", $current_program_id, $course_id);
$prerequisite_stmt->execute();
$prerequisites = $prerequisite_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?php echo htmlspecialchars($course['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
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
            max-width: 800px;
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

        .page-title {
            margin-bottom: 2rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: var(--gray);
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-group label.required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-control.error {
            border-color: var(--danger);
            background-color: rgba(239, 68, 68, 0.05);
        }

        .form-help {
            display: block;
            margin-top: 0.5rem;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.95rem;
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
            transform: translateY(-2px);
        }

        .preview-card {
            background: var(--light);
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 1px solid var(--light-gray);
        }

        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .preview-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .preview-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .preview-description {
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .preview-details {
            display: flex;
            gap: 1.5rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-card {
                padding: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/">Courses</a>
            <i class="fas fa-chevron-right"></i>
            <a href="view.php?id=<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['course_code']); ?></a>
            <i class="fas fa-chevron-right"></i>
            <span>Edit</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Edit Course: <?php echo htmlspecialchars($course['title']); ?></h1>
            <p>Update course information and settings</p>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Please fix the following errors:</strong>
                <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="form-card">
            <form method="POST" id="courseForm" onsubmit="return validateForm()">
                <!-- Program Selection -->
                <div class="form-group">
                    <label for="program_id" class="required">Program</label>
                    <select id="program_id" name="program_id" class="form-control" required>
                        <option value="">Select a Program</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['id']; ?>"
                                <?php echo ($_POST['program_id'] ?? $course['program_id']) == $program['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Course Code and Title -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="course_code" class="required">Course Code</label>
                        <input type="text" id="course_code" name="course_code"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['course_code'] ?? $course['course_code']); ?>"
                            placeholder="e.g., DM101-01"
                            maxlength="20"
                            required>
                        <span class="form-help">Use uppercase letters, numbers, and hyphens (e.g., DM101-01)</span>
                    </div>

                    <div class="form-group">
                        <label for="title" class="required">Course Title</label>
                        <input type="text" id="title" name="title"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? $course['title']); ?>"
                            placeholder="e.g., Introduction to Digital Marketing"
                            maxlength="150"
                            required>
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"
                        class="form-control"
                        placeholder="Describe the course content, learning objectives, and outcomes..."
                        rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $course['description']); ?></textarea>
                </div>

                <!-- Duration, Level, and Order -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="duration_hours" class="required">Duration (Hours)</label>
                        <input type="number" id="duration_hours" name="duration_hours"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['duration_hours'] ?? $course['duration_hours']); ?>"
                            min="1" max="500" step="1"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="level" class="required">Level</label>
                        <select id="level" name="level" class="form-control" required>
                            <option value="beginner" <?php echo ($_POST['level'] ?? $course['level']) === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                            <option value="intermediate" <?php echo ($_POST['level'] ?? $course['level']) === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                            <option value="advanced" <?php echo ($_POST['level'] ?? $course['level']) === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="order_number">Order Number</label>
                        <input type="number" id="order_number" name="order_number"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['order_number'] ?? $course['order_number']); ?>"
                            min="0" step="1">
                        <span class="form-help">Determines display order (0 = first)</span>
                    </div>
                </div>

                <!-- Prerequisite Course Selection -->
                <div class="form-group">
                    <label for="prerequisite_course_id">Prerequisite Course</label>
                    <select id="prerequisite_course_id" name="prerequisite_course_id" class="form-control">
                        <option value="">None (No prerequisite)</option>
                        <?php
                        // Get all courses from the same program for prerequisite
                        $prereq_program_id = $_POST['program_id'] ?? $course['program_id'];
                        $prereq_query = "SELECT id, course_code, title FROM courses 
                                        WHERE program_id = ? AND status = 'active' 
                                        AND id != ? 
                                        ORDER BY order_number, course_code";
                        $prereq_stmt = $conn->prepare($prereq_query);
                        $prereq_stmt->bind_param("ii", $prereq_program_id, $course_id);
                        $prereq_stmt->execute();
                        $prereq_courses = $prereq_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                        foreach ($prereq_courses as $prereq):
                        ?>
                            <option value="<?php echo $prereq['id']; ?>"
                                <?php echo ($_POST['prerequisite_course_id'] ?? $course['prerequisite_course_id']) == $prereq['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prereq['course_code'] . ' - ' . $prereq['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-help">Select a course that must be completed before taking this course</span>
                </div>

                <!-- Required Course and Status -->
                <div class="form-row">
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="is_required" name="is_required"
                                value="1" <?php echo ($_POST['is_required'] ?? $course['is_required']) ? 'checked' : ''; ?>>
                            <label for="is_required" style="margin-bottom: 0;">Required Course</label>
                        </div>
                        <span class="form-help">If checked, this course is mandatory for the program</span>
                    </div>

                    <div class="form-group">
                        <label for="status" class="required">Status</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="active" <?php echo ($_POST['status'] ?? $course['status']) === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($_POST['status'] ?? $course['status']) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="form-group">
                    <label>Live Preview</label>
                    <div class="preview-card" id="livePreview">
                        <div class="preview-header">
                            <div class="preview-code" id="previewCode"><?php echo htmlspecialchars($course['course_code']); ?></div>
                            <div>
                                <span class="level-badge" id="previewLevel" style="background: rgba(59, 130, 246, 0.1); color: var(--info); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                                    <?php echo ucfirst($course['level']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="preview-title" id="previewTitle"><?php echo htmlspecialchars($course['title']); ?></div>
                        <div class="preview-description" id="previewDescription"><?php echo htmlspecialchars($course['description']); ?></div>
                        <div class="preview-details">
                            <span><i class="fas fa-clock"></i> <span id="previewDuration"><?php echo $course['duration_hours']; ?></span> hours</span>
                            <span><i class="fas fa-list-ol"></i> Order: <span id="previewOrder"><?php echo $course['order_number']; ?></span></span>
                            <span><i class="fas fa-asterisk"></i> Required: <span id="previewRequired"><?php echo $course['is_required'] ? 'Yes' : 'No'; ?></span></span>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <div>
                        <a href="view.php?id=<?php echo $course['id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <a href="?action=delete&id=<?php echo $course['id']; ?>"
                            class="btn btn-danger"
                            onclick="return confirm('Delete this course? This action cannot be undone.')">
                            <i class="fas fa-trash"></i> Delete Course
                        </a>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Live preview
        const courseCodeInput = document.getElementById('course_code');
        const titleInput = document.getElementById('title');
        const descriptionInput = document.getElementById('description');
        const durationInput = document.getElementById('duration_hours');
        const levelInput = document.getElementById('level');
        const orderInput = document.getElementById('order_number');
        const requiredInput = document.getElementById('is_required');

        const previewCode = document.getElementById('previewCode');
        const previewTitle = document.getElementById('previewTitle');
        const previewDescription = document.getElementById('previewDescription');
        const previewDuration = document.getElementById('previewDuration');
        const previewLevel = document.getElementById('previewLevel');
        const previewOrder = document.getElementById('previewOrder');
        const previewRequired = document.getElementById('previewRequired');

        function updatePreview() {
            previewCode.textContent = courseCodeInput.value || 'COURSE-CODE';
            previewTitle.textContent = titleInput.value || 'Course Title';
            previewDescription.textContent = descriptionInput.value || 'Course description will appear here...';
            previewDuration.textContent = durationInput.value || '40';
            previewOrder.textContent = orderInput.value || '0';
            previewRequired.textContent = requiredInput.checked ? 'Yes' : 'No';

            // Update level badge
            const level = levelInput.value || 'beginner';
            previewLevel.textContent = level.charAt(0).toUpperCase() + level.slice(1);

            // Update level badge color
            const levelColors = {
                'beginner': {
                    bg: 'rgba(59, 130, 246, 0.1)',
                    color: 'var(--info)'
                },
                'intermediate': {
                    bg: 'rgba(245, 158, 11, 0.1)',
                    color: 'var(--warning)'
                },
                'advanced': {
                    bg: 'rgba(239, 68, 68, 0.1)',
                    color: 'var(--danger)'
                }
            };
            previewLevel.style.background = levelColors[level]?.bg || levelColors.beginner.bg;
            previewLevel.style.color = levelColors[level]?.color || levelColors.beginner.color;
        }

        // Add event listeners
        courseCodeInput.addEventListener('input', updatePreview);
        titleInput.addEventListener('input', updatePreview);
        descriptionInput.addEventListener('input', updatePreview);
        durationInput.addEventListener('input', updatePreview);
        levelInput.addEventListener('input', updatePreview);
        orderInput.addEventListener('input', updatePreview);
        requiredInput.addEventListener('change', updatePreview);

        // Form validation
        function validateForm() {
            let isValid = true;

            // Validate course code
            const courseCode = document.getElementById('course_code');
            if (!/^[A-Z0-9\-]{2,20}$/.test(courseCode.value)) {
                courseCode.classList.add('error');
                alert('Course code must be 2-20 uppercase letters, numbers, or hyphens (e.g., DM101-01)');
                isValid = false;
            } else {
                courseCode.classList.remove('error');
            }

            // Validate duration
            const duration = document.getElementById('duration_hours');
            if (parseInt(duration.value) < 1 || parseInt(duration.value) > 500) {
                duration.classList.add('error');
                alert('Duration must be between 1 and 500 hours');
                isValid = false;
            } else {
                duration.classList.remove('error');
            }

            // Validate program selection
            const program = document.getElementById('program_id');
            if (!program.value) {
                program.classList.add('error');
                alert('Please select a program');
                isValid = false;
            } else {
                program.classList.remove('error');
            }

            return isValid;
        }

        // Auto-format course code to uppercase
        document.getElementById('course_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
        });

        // Program change handler for prerequisite courses
        document.getElementById('program_id').addEventListener('change', function() {
            const programId = this.value;
            const courseId = <?php echo $course_id; ?>;
            const prerequisiteSelect = document.getElementById('prerequisite_course_id');

            if (programId) {
                // Fetch courses for the selected program
                fetch(`get_courses_by_program.php?program_id=${programId}&exclude_id=${courseId}`)
                    .then(response => response.json())
                    .then(data => {
                        prerequisiteSelect.innerHTML = '<option value="">None (No prerequisite)</option>';
                        data.forEach(course => {
                            const option = document.createElement('option');
                            option.value = course.id;
                            option.textContent = `${course.course_code} - ${course.title}`;
                            prerequisiteSelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error fetching courses:', error));
            } else {
                prerequisiteSelect.innerHTML = '<option value="">None (No prerequisite)</option>';
            }
        });

        // Initialize preview
        document.addEventListener('DOMContentLoaded', updatePreview);
    </script>
</body>

</html>