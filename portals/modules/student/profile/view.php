<?php
// modules/student/profile/view.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();
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

// Get enrolled classes
$enrolled_classes = [];
$sql = "SELECT e.*, cb.batch_code, c.title as course_title, p.name as program_name
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        WHERE e.student_id = ? AND e.status = 'active'
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $enrolled_classes = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get academic stats
$stats = [
    'total_classes' => count($enrolled_classes),
    'completed_classes' => 0,
    'average_grade' => 0,
    'certificates' => 0
];

// Get completed classes
$completed_sql = "SELECT COUNT(*) as count FROM enrollments 
                 WHERE student_id = ? AND status = 'completed'";
$completed_stmt = $conn->prepare($completed_sql);
$completed_stmt->bind_param("i", $user_id);
$completed_stmt->execute();
$completed_result = $completed_stmt->get_result();
if ($completed_row = $completed_result->fetch_assoc()) {
    $stats['completed_classes'] = $completed_row['count'];
}
$completed_stmt->close();

// Get average grade
$grade_sql = "SELECT AVG(percentage) as avg_grade FROM gradebook 
              WHERE student_id = ? AND published = 1";
$grade_stmt = $conn->prepare($grade_sql);
$grade_stmt->bind_param("i", $user_id);
$grade_stmt->execute();
$grade_result = $grade_stmt->get_result();
if ($grade_row = $grade_result->fetch_assoc()) {
    $stats['average_grade'] = round($grade_row['avg_grade'] ?? 0, 1);
}
$grade_stmt->close();

// Get certificates
$cert_sql = "SELECT COUNT(*) as count FROM enrollments 
             WHERE student_id = ? AND certificate_issued = 1";
$cert_stmt = $conn->prepare($cert_sql);
$cert_stmt->bind_param("i", $user_id);
$cert_stmt->execute();
$cert_result = $cert_stmt->get_result();
if ($cert_row = $cert_result->fetch_assoc()) {
    $stats['certificates'] = $cert_row['count'];
}
$cert_stmt->close();

// Log activity
logActivity($user_id, 'profile_view', 'Student viewed profile', $_SERVER['REMOTE_ADDR']);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Student Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
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
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
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
            min-height: 100vh;
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: var(--transition);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        /* Top Bar */
        .top-bar {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Profile Container */
        .profile-container {
            padding: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Profile Header */
        .profile-header-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }
        }

        .profile-image-container {
            position: relative;
        }

        .profile-image {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .profile-image-placeholder {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h1 {
            color: var(--dark);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-info .title {
            color: var(--primary);
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .contact-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Social Links */
        .social-links {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .social-link:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        /* Profile Stats */
        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card.success {
            border-top-color: var(--success);
        }

        .stat-card.warning {
            border-top-color: var(--warning);
        }

        .stat-card.accent {
            border-top-color: var(--secondary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .section-card h3 {
            color: var(--primary);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-card h3 i {
            font-size: 1rem;
        }

        .info-grid {
            display: grid;
            gap: 1rem;
        }

        .info-item {
            display: grid;
            gap: 0.25rem;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .info-value {
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Enrolled Classes */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .class-item {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .class-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .class-item h4 {
            margin-bottom: 0.25rem;
            color: var(--dark);
            font-size: 1rem;
            font-weight: 600;
        }

        .class-item p {
            color: var(--gray);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: block;
            opacity: 0.5;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Bio/About Me */
        .bio-content {
            line-height: 1.8;
            color: var(--dark);
            font-size: 0.875rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--success);
        }

        /* Additional Information */
        .additional-info {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        /* Footer */
        .dashboard-footer {
            background-color: white;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 2rem;
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 576px) {
            .profile-stats-grid {
                grid-template-columns: 1fr;
            }

            .contact-info {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>My Profile</h1>
                <p>View and manage your personal information and academic progress</p>
            </div>
            <div class="top-actions">
                <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" class="btn btn-primary">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </a>
            </div>
        </div>

        <!-- Profile Container -->
        <div class="profile-container">
            <!-- Profile Header Card -->
            <div class="profile-header-card">
                <div class="profile-header">
                    <div class="profile-image-container">
                        <?php if (!empty($user_details['profile_image'])): ?>
                            <img src="<?php echo BASE_URL . $user_details['profile_image']; ?>"
                                alt="Profile Image"
                                class="profile-image">
                        <?php else: ?>
                            <div class="profile-image-placeholder">
                                <?php echo strtoupper(substr($user_details['first_name'], 0, 1) . substr($user_details['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="profile-info">
                        <h1><?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></h1>
                        <div class="title">
                            <i class="fas fa-user-graduate"></i>
                            Student • Impact Digital Academy
                        </div>

                        <?php if (!empty($user_details['current_job_title'])): ?>
                            <div class="title" style="color: var(--secondary);">
                                <i class="fas fa-briefcase"></i>
                                <?php echo htmlspecialchars($user_details['current_job_title']); ?>
                                <?php if (!empty($user_details['current_company'])): ?>
                                    at <?php echo htmlspecialchars($user_details['current_company']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="contact-info">
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($user_details['email']); ?>
                            </div>
                            <?php if (!empty($user_details['phone'])): ?>
                                <div class="contact-item">
                                    <i class="fas fa-phone"></i>
                                    <?php echo htmlspecialchars($user_details['phone']); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Social Links -->
                        <?php if (!empty($user_details['linkedin_url']) || !empty($user_details['github_url']) || !empty($user_details['website'])): ?>
                            <div class="social-links">
                                <?php if (!empty($user_details['linkedin_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($user_details['linkedin_url']); ?>"
                                        target="_blank"
                                        class="social-link"
                                        title="LinkedIn">
                                        <i class="fab fa-linkedin"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($user_details['github_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($user_details['github_url']); ?>"
                                        target="_blank"
                                        class="social-link"
                                        title="GitHub">
                                        <i class="fab fa-github"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($user_details['website'])): ?>
                                    <a href="<?php echo htmlspecialchars($user_details['website']); ?>"
                                        target="_blank"
                                        class="social-link"
                                        title="Website">
                                        <i class="fas fa-globe"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Profile Stats -->
            <div class="profile-stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_classes']; ?></div>
                    <div class="stat-label">Enrolled Classes</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['completed_classes']; ?></div>
                    <div class="stat-label">Completed Classes</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $stats['average_grade']; ?>%</div>
                    <div class="stat-label">Average Grade</div>
                </div>

                <div class="stat-card accent">
                    <div class="stat-value"><?php echo $stats['certificates']; ?></div>
                    <div class="stat-label">Certificates</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" class="btn btn-primary">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/profile/certificates.php" class="btn btn-secondary">
                    <i class="fas fa-certificate"></i> View Certificates
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-secondary">
                    <i class="fas fa-chalkboard"></i> Browse Classes
                </a>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Personal Information -->
                <div class="section-card">
                    <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></div>
                        </div>

                        <?php if (!empty($user_details['date_of_birth'])): ?>
                            <div class="info-item">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($user_details['date_of_birth'])); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($user_details['gender'])): ?>
                            <div class="info-item">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?php echo ucfirst(htmlspecialchars($user_details['gender'])); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($user_details['email'])): ?>
                            <div class="info-item">
                                <div class="info-label">Email Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_details['email']); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($user_details['phone'])): ?>
                            <div class="info-item">
                                <div class="info-label">Phone Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_details['phone']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="section-card">
                    <h3><i class="fas fa-home"></i> Address Information</h3>
                    <div class="info-grid">
                        <?php if (!empty($user_details['address'])): ?>
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_details['address']); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="info-item">
                            <div class="info-label">City</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_details['city'] ?? 'Not specified'); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">State</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_details['state'] ?? 'Not specified'); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Country</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_details['country'] ?? 'Nigeria'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Bio/About Me -->
                <?php if (!empty($user_details['bio'])): ?>
                    <div class="section-card">
                        <h3><i class="fas fa-info-circle"></i> About Me</h3>
                        <div class="bio-content">
                            <?php echo nl2br(htmlspecialchars($user_details['bio'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Professional Information -->
                <div class="section-card">
                    <h3><i class="fas fa-briefcase"></i> Professional Information</h3>
                    <div class="info-grid">
                        <?php if (!empty($user_details['current_job_title'])): ?>
                            <div class="info-item">
                                <div class="info-label">Current Position</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($user_details['current_job_title']); ?>
                                    <?php if (!empty($user_details['current_company'])): ?>
                                        at <?php echo htmlspecialchars($user_details['current_company']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($user_details['qualifications'])): ?>
                            <div class="info-item">
                                <div class="info-label">Qualifications</div>
                                <div class="info-value" style="line-height: 1.6;">
                                    <?php echo nl2br(htmlspecialchars($user_details['qualifications'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Enrolled Classes -->
                <div class="section-card">
                    <h3><i class="fas fa-chalkboard"></i> Currently Enrolled Classes</h3>
                    <?php if (!empty($enrolled_classes)): ?>
                        <div class="class-list">
                            <?php foreach ($enrolled_classes as $class): ?>
                                <div class="class-item">
                                    <h4><?php echo htmlspecialchars($class['course_title']); ?></h4>
                                    <p><?php echo htmlspecialchars($class['program_name']); ?> • <?php echo htmlspecialchars($class['batch_code']); ?></p>
                                    <div style="margin-top: 0.5rem;">
                                        <a href="<?php echo BASE_URL; ?>modules/student/classes/<?php echo $class['class_id']; ?>/home.php"
                                            class="btn btn-sm btn-primary">
                                            <i class="fas fa-external-link-alt"></i> Enter Class
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <p>Not enrolled in any classes yet</p>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-search"></i> Browse Classes
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="section-card additional-info">
                <h3><i class="fas fa-cog"></i> Additional Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Account Created</div>
                        <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user_details['created_at'])); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user_details['updated_at'])); ?></div>
                    </div>

                    <?php if (!empty($user_details['last_login'])): ?>
                        <div class="info-item">
                            <div class="info-label">Last Login</div>
                            <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user_details['last_login'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($user_details['status'])): ?>
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value">
                                <span class="status-badge">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                    <?php echo ucfirst(htmlspecialchars($user_details['status'])); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer -->
            <div class="dashboard-footer">
                <div class="system-status">
                    <div class="status-indicator"></div>
                    <span>System Status: Operational</span>
                </div>
                <div>
                    <span>Last Updated: <?php echo date('F j, Y, g:i a'); ?></span>
                    <?php if ($stats['average_grade'] > 0): ?>
                        <span style="margin-left: 1rem; color: var(--success); font-weight: 600;">
                            <i class="fas fa-chart-line"></i>
                            GPA: <?php echo $stats['average_grade']; ?>%
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        // Initialize any interactive elements
        document.addEventListener('DOMContentLoaded', function() {
            // Add any profile-specific JavaScript here

            // Example: Confirm before leaving edit page if unsaved changes
            const editBtn = document.querySelector('a[href*="edit.php"]');
            if (editBtn) {
                editBtn.addEventListener('click', function(e) {
                    // Add any confirmation logic if needed
                });
            }
        });

        // Print profile function
        function printProfile() {
            window.print();
        }

        // Export profile data
        function exportProfileData() {
            // Implement export functionality
            alert('Export functionality would be implemented here');
        }
    </script>
</body>

</html>