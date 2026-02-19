<?php
// modules/student/program/requirements.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$user_id = $_SESSION['user_id'];

// Get user details
$user_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id 
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($user_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

// Get student's primary program (assuming one main program per student for now)
$program = null;
$program_progress = [];
$program_requirements = [];
$completed_courses = [];

// Get student's current program from enrollments
$sql = "SELECT p.*, e.id as enrollment_id, e.status as enrollment_status
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        WHERE e.student_id = ? AND e.status = 'active'
        GROUP BY p.id
        ORDER BY e.enrollment_date DESC
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $program = $result->fetch_assoc();
}
$stmt->close();

// If no program found, redirect to dashboard
if (!$program) {
    header('Location: ' . BASE_URL . 'modules/student/dashboard.php?message=no_program');
    exit();
}

// Get program requirements
$requirements = [];
$sql = "SELECT pr.*, c.course_code, c.title as course_title, c.description as course_description,
               c.duration_hours, c.level, c.is_required,
               (SELECT COUNT(*) FROM enrollments e2 
                JOIN class_batches cb2 ON e2.class_id = cb2.id 
                WHERE e2.student_id = ? AND cb2.course_id = c.id AND e2.status = 'completed') as completed
        FROM program_requirements pr
        JOIN courses c ON pr.course_id = c.id
        LEFT JOIN program_requirements_meta prm ON pr.program_id = prm.program_id
        WHERE pr.program_id = ?
        ORDER BY pr.course_type DESC, c.order_number ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $program['id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $requirements[] = $row;
    }
}
$stmt->close();

// Get program requirements meta
$program_meta = [];
$sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program['id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $program_meta = $result->fetch_assoc();
}
$stmt->close();

// Calculate progress
$total_courses = count($requirements);
$completed_courses = array_filter($requirements, function ($course) {
    return $course['completed'] > 0;
});
$completed_count = count($completed_courses);
$completion_percentage = $total_courses > 0 ? ($completed_count / $total_courses) * 100 : 0;

// Count core vs elective courses
$core_courses = array_filter($requirements, function ($course) {
    return $course['course_type'] === 'core';
});
$elective_courses = array_filter($requirements, function ($course) {
    return $course['course_type'] === 'elective';
});

// Get grades for completed courses
$grades = [];
$sql = "SELECT g.*, c.course_code, c.title as course_title,
               ROUND(AVG(g.percentage), 2) as average_grade
        FROM gradebook g
        JOIN assignments a ON g.assignment_id = a.id
        JOIN class_batches cb ON a.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN enrollments e ON g.enrollment_id = e.id
        WHERE g.student_id = ? AND e.status = 'completed'
        AND c.program_id = ?
        GROUP BY c.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $program['id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $grades[$row['course_id']] = $row;
    }
}
$stmt->close();

// Calculate GPA
$total_points = 0;
$total_credits = 0;
foreach ($grades as $grade) {
    // Simple GPA calculation (you might want to adjust this)
    $percentage = $grade['average_grade'];
    if ($percentage >= 70) $grade_points = 5.0;
    elseif ($percentage >= 60) $grade_points = 4.0;
    elseif ($percentage >= 50) $grade_points = 3.0;
    elseif ($percentage >= 45) $grade_points = 2.0;
    elseif ($percentage >= 40) $grade_points = 1.0;
    else $grade_points = 0.0;

    $total_points += $grade_points;
    $total_credits++;
}
$gpa = $total_credits > 0 ? $total_points / $total_credits : 0;

// Log activity
logActivity($user_id, 'view_program_requirements', "Viewed program requirements for {$program['name']}", $_SERVER['REMOTE_ADDR']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Requirements - <?php echo htmlspecialchars($program['name']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --sidebar-bg: #1e293b;
            --sidebar-text: #cbd5e1;
            --sidebar-hover: #334155;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            opacity: 0.9;
            margin-bottom: 1rem;
        }

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
            box-shadow: var(--card-shadow);
            text-align: center;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-card .label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .progress-section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .progress-bar {
            height: 10px;
            background-color: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .requirements-section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
        }

        .requirements-tabs {
            display: flex;
            border-bottom: 2px solid var(--border);
            margin-bottom: 1.5rem;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .course-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .course-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .course-card.completed {
            border-left: 4px solid var(--success);
        }

        .course-card.in-progress {
            border-left: 4px solid var(--info);
        }

        .course-card.not-started {
            border-left: 4px solid var(--gray-light);
        }

        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .course-code {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.875rem;
        }

        .course-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0.5rem 0;
        }

        .course-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .course-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-completed {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-in-progress {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .status-not-started {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .grade-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
            margin-top: 0.5rem;
        }

        .requirement-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .requirement-item {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
        }

        .requirement-item h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .requirement-item ul {
            list-style: none;
        }

        .requirement-item li {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
        }

        .requirement-item li:last-child {
            border-bottom: none;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--gray-light);
            color: white;
        }

        .btn-secondary:hover {
            background-color: var(--gray);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .requirements-tabs {
                flex-direction: column;
            }

            .tab-btn {
                width: 100%;
                text-align: left;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="header">
            <h1><?php echo htmlspecialchars($program['name']); ?></h1>
            <p><?php echo htmlspecialchars($program['program_code']); ?> • <?php echo ucfirst($program['program_type']); ?> Program</p>
            <p><?php echo htmlspecialchars($program['description'] ?? ''); ?></p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="value"><?php echo round($completion_percentage, 1); ?>%</div>
                <div class="label">Program Completion</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $completed_count; ?>/<?php echo $total_courses; ?></div>
                <div class="label">Courses Completed</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo round($gpa, 2); ?></div>
                <div class="label">Current GPA</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo count($core_courses); ?></div>
                <div class="label">Core Courses</div>
            </div>
        </div>

        <div class="progress-section">
            <h2>Program Progress</h2>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.875rem; color: var(--gray);">
                <span>0%</span>
                <span><?php echo round($completion_percentage, 1); ?>% Complete</span>
                <span>100%</span>
            </div>

            <?php if ($program_meta): ?>
                <div style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                    <h3 style="margin-bottom: 0.5rem; font-size: 1rem;">Graduation Requirements</h3>
                    <ul style="list-style: none; color: var(--gray);">
                        <li>• Complete all <?php echo count($core_courses); ?> core courses</li>
                        <?php if ($program_meta['min_electives'] > 0): ?>
                            <li>• Complete at least <?php echo $program_meta['min_electives']; ?> elective courses</li>
                        <?php endif; ?>
                        <?php if ($program_meta['total_credits'] > 0): ?>
                            <li>• Earn <?php echo $program_meta['total_credits']; ?> total credits</li>
                        <?php endif; ?>
                        <li>• Maintain minimum grade of <?php echo $program_meta['min_grade_required'] ?? 'C'; ?></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="requirements-section">
            <div class="requirements-tabs">
                <button class="tab-btn active" onclick="showTab('all')">All Courses</button>
                <button class="tab-btn" onclick="showTab('core')">Core Courses</button>
                <button class="tab-btn" onclick="showTab('elective')">Elective Courses</button>
                <button class="tab-btn" onclick="showTab('completed')">Completed</button>
                <button class="tab-btn" onclick="showTab('pending')">Pending</button>
            </div>

            <div id="tab-all" class="tab-content active">
                <?php if (empty($requirements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>No courses found</h3>
                        <p>There are no courses assigned to this program yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($requirements as $course): ?>
                        <div class="course-card <?php echo $course['completed'] > 0 ? 'completed' : 'not-started'; ?>">
                            <div class="course-header">
                                <div>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h3>
                                    <?php if ($course['course_type'] === 'core'): ?>
                                        <span class="status-badge" style="background-color: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                            Core Course
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background-color: rgba(114, 9, 183, 0.1); color: var(--secondary);">
                                            Elective Course
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($course['completed'] > 0): ?>
                                        <span class="status-badge status-completed">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-not-started">
                                            <i class="fas fa-clock"></i> Not Started
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($course['course_description'] ?? 'No description available.'); ?>
                            </p>

                            <div class="course-meta">
                                <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                <span><i class="fas fa-signal"></i> <?php echo ucfirst($course['level']); ?></span>
                                <?php if ($course['is_required']): ?>
                                    <span><i class="fas fa-asterisk"></i> Required</span>
                                <?php endif; ?>
                            </div>

                            <?php if (isset($grades[$course['course_id']])): ?>
                                <div class="grade-display">
                                    Grade: <?php echo $grades[$course['course_id']]['average_grade']; ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab-core" class="tab-content">
                <?php
                $core_courses = array_filter($requirements, function ($course) {
                    return $course['course_type'] === 'core';
                });

                if (empty($core_courses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>No core courses</h3>
                        <p>No core courses found for this program.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($core_courses as $course): ?>
                        <div class="course-card <?php echo $course['completed'] > 0 ? 'completed' : 'not-started'; ?>">
                            <div class="course-header">
                                <div>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h3>
                                    <span class="status-badge" style="background-color: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                        Core Course
                                    </span>
                                </div>
                                <div>
                                    <?php if ($course['completed'] > 0): ?>
                                        <span class="status-badge status-completed">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-not-started">
                                            <i class="fas fa-clock"></i> Not Started
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($course['course_description'] ?? 'No description available.'); ?>
                            </p>
                            <div class="course-meta">
                                <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                <span><i class="fas fa-signal"></i> <?php echo ucfirst($course['level']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab-elective" class="tab-content">
                <?php
                $elective_courses = array_filter($requirements, function ($course) {
                    return $course['course_type'] === 'elective';
                });

                if (empty($elective_courses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>No elective courses</h3>
                        <p>No elective courses found for this program.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($elective_courses as $course): ?>
                        <div class="course-card <?php echo $course['completed'] > 0 ? 'completed' : 'not-started'; ?>">
                            <div class="course-header">
                                <div>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h3>
                                    <span class="status-badge" style="background-color: rgba(114, 9, 183, 0.1); color: var(--secondary);">
                                        Elective Course
                                    </span>
                                </div>
                                <div>
                                    <?php if ($course['completed'] > 0): ?>
                                        <span class="status-badge status-completed">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-not-started">
                                            <i class="fas fa-clock"></i> Not Started
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($course['course_description'] ?? 'No description available.'); ?>
                            </p>
                            <div class="course-meta">
                                <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                <span><i class="fas fa-signal"></i> <?php echo ucfirst($course['level']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab-completed" class="tab-content">
                <?php
                $completed_courses = array_filter($requirements, function ($course) {
                    return $course['completed'] > 0;
                });

                if (empty($completed_courses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No completed courses</h3>
                        <p>You haven't completed any courses in this program yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($completed_courses as $course): ?>
                        <div class="course-card completed">
                            <div class="course-header">
                                <div>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h3>
                                    <?php if ($course['course_type'] === 'core'): ?>
                                        <span class="status-badge" style="background-color: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                            Core Course
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background-color: rgba(114, 9, 183, 0.1); color: var(--secondary);">
                                            Elective Course
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="status-badge status-completed">
                                        <i class="fas fa-check-circle"></i> Completed
                                    </span>
                                </div>
                            </div>
                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($course['course_description'] ?? 'No description available.'); ?>
                            </p>
                            <?php if (isset($grades[$course['course_id']])): ?>
                                <div class="grade-display">
                                    Grade: <?php echo $grades[$course['course_id']]['average_grade']; ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab-pending" class="tab-content">
                <?php
                $pending_courses = array_filter($requirements, function ($course) {
                    return $course['completed'] === 0;
                });

                if (empty($pending_courses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-double"></i>
                        <h3>All courses completed!</h3>
                        <p>Congratulations! You've completed all courses in this program.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_courses as $course): ?>
                        <div class="course-card not-started">
                            <div class="course-header">
                                <div>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h3>
                                    <?php if ($course['course_type'] === 'core'): ?>
                                        <span class="status-badge" style="background-color: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                            Core Course
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background-color: rgba(114, 9, 183, 0.1); color: var(--secondary);">
                                            Elective Course
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="status-badge status-not-started">
                                        <i class="fas fa-clock"></i> Pending
                                    </span>
                                </div>
                            </div>
                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($course['course_description'] ?? 'No description available.'); ?>
                            </p>
                            <div class="course-meta">
                                <span><i class="fas fa-clock"></i> <?php echo $course['duration_hours']; ?> hours</span>
                                <span><i class="fas fa-signal"></i> <?php echo ucfirst($course['level']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <a href="<?php echo BASE_URL; ?>modules/student/program/register_courses.php" class="btn btn-primary">
                <i class="fas fa-calendar-plus"></i> Register for Courses
            </a>
            <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-secondary">
                <i class="fas fa-chart-line"></i> View Progress Overview
            </a>
            <a href="<?php echo BASE_URL; ?>modules/student/program/graduation.php" class="btn btn-success">
                <i class="fas fa-graduation-cap"></i> Check Graduation Status
            </a>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById('tab-' + tabName).classList.add('active');

            // Activate selected tab button
            event.target.classList.add('active');
        }

        // Initialize tooltips
        document.querySelectorAll('.course-card').forEach(card => {
            card.addEventListener('click', function() {
                const courseId = this.dataset.courseId;
                if (courseId) {
                    window.location.href = `<?php echo BASE_URL; ?>modules/student/courses/view.php?id=${courseId}`;
                }
            });
        });

        // Print functionality
        function printRequirements() {
            window.print();
        }

        // Export functionality
        function exportRequirements() {
            // This would typically make an AJAX call to generate a PDF or CSV
            alert('Export feature coming soon!');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printRequirements();
            }

            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportRequirements();
            }

            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = '<?php echo BASE_URL; ?>modules/student/dashboard.php';
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>