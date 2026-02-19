<?php
// modules/admin/profile/view.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

$user_id = intval($_GET['id']);
$current_admin_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Fetch user data
$user_data = [];
$profile_data = [];
$financial_status = [];

$stmt = $conn->prepare("
    SELECT 
        u.*, 
        up.*,
        (SELECT COUNT(*) FROM enrollments WHERE student_id = u.id) as total_classes,
        (SELECT COUNT(*) FROM applications WHERE user_id = u.id) as total_applications
    FROM users u 
    LEFT JOIN user_profiles up ON u.id = up.user_id 
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

$row = $result->fetch_assoc();
$stmt->close();

$user_data = [
    'id' => $row['id'],
    'email' => $row['email'],
    'first_name' => $row['first_name'],
    'last_name' => $row['last_name'],
    'phone' => $row['phone'],
    'profile_image' => $row['profile_image'],
    'role' => $row['role'],
    'status' => $row['status'],
    'email_verified_at' => $row['email_verified_at'],
    'last_login' => $row['last_login'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
    'total_classes' => $row['total_classes'] ?? 0,
    'total_applications' => $row['total_applications'] ?? 0
];

$profile_data = [
    'date_of_birth' => $row['date_of_birth'],
    'gender' => $row['gender'],
    'address' => $row['address'],
    'city' => $row['city'],
    'state' => $row['state'],
    'country' => $row['country'],
    'bio' => $row['bio'],
    'website' => $row['website'],
    'linkedin_url' => $row['linkedin_url'],
    'github_url' => $row['github_url'],
    'qualifications' => $row['qualifications'],
    'experience_years' => $row['experience_years'],
    'current_job_title' => $row['current_job_title'],
    'current_company' => $row['current_company']
];

// Fetch financial data if user is a student
if ($user_data['role'] === 'student') {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            COALESCE(SUM(amount), 0) as total_paid,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments
        FROM financial_transactions 
        WHERE student_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $financial_data = $result->fetch_assoc();
    $stmt->close();

    $financial_status = [
        'total_transactions' => $financial_data['total_transactions'] ?? 0,
        'total_paid' => $financial_data['total_paid'] ?? 0,
        'pending_payments' => $financial_data['pending_payments'] ?? 0
    ];
}

// Fetch recent activity
$activities = [];
$stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($activity = $result->fetch_assoc()) {
    $activities[] = $activity;
}
$stmt->close();

// Fetch enrollments if student
$enrollments = [];
if ($user_data['role'] === 'student') {
    $stmt = $conn->prepare("
        SELECT 
            e.*,
            cb.batch_code,
            cb.name as class_name,
            c.title as course_title,
            p.name as program_name,
            CONCAT(i.first_name, ' ', i.last_name) as instructor_name
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN users i ON cb.instructor_id = i.id
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($enrollment = $result->fetch_assoc()) {
        $enrollments[] = $enrollment;
    }
    $stmt->close();
}

// Fetch classes if instructor
$instructor_classes = [];
if ($user_data['role'] === 'instructor') {
    $stmt = $conn->prepare("
        SELECT 
            cb.*,
            c.title as course_title,
            p.name as program_name,
            COUNT(e.id) as total_students
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
        WHERE cb.instructor_id = ?
        GROUP BY cb.id
        ORDER BY cb.start_date DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($class = $result->fetch_assoc()) {
        $instructor_classes[] = $class;
    }
    $stmt->close();
}

// Close database connection
$conn->close();

// Log activity
logActivity($current_admin_id, 'view_profile', 'Admin viewed user profile: ' . $user_data['email'], $_SERVER['REMOTE_ADDR']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Profile - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/admin.css">
    <style>
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .profile-header {
            background: linear-gradient(135deg, #4361ee, #3a56d4);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #7209b7, #f72585);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            color: white;
        }

        .profile-info h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }

        .profile-info p {
            margin: 0.5rem 0;
            opacity: 0.9;
        }

        .profile-info .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .profile-actions {
            margin-left: auto;
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: white;
            color: #4361ee;
        }

        .btn-primary:hover {
            background-color: #f8f9fa;
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .btn-danger {
            background-color: rgba(230, 57, 70, 0.1);
            color: #e63946;
        }

        .btn-danger:hover {
            background-color: rgba(230, 57, 70, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card.primary {
            border-top: 4px solid #4361ee;
        }

        .stat-card.success {
            border-top: 4px solid #4cc9f0;
        }

        .stat-card.warning {
            border-top: 4px solid #f72585;
        }

        .stat-card.accent {
            border-top: 4px solid #7209b7;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #212529;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background-color: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #212529;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: #4361ee;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-item {
            margin-bottom: 1.5rem;
        }

        .info-label {
            display: block;
            font-weight: 500;
            color: #212529;
            margin-bottom: 0.5rem;
        }

        .info-value {
            color: #6c757d;
            font-size: 1.1rem;
        }

        .info-value.empty {
            color: #adb5bd;
            font-style: italic;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4361ee;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background-color: #4361ee;
            color: white;
            transform: translateY(-2px);
        }

        .activity-list,
        .enrollment-list,
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item,
        .enrollment-item,
        .class-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .activity-item:hover,
        .enrollment-item:hover,
        .class-item:hover {
            background-color: #e9ecef;
        }

        .activity-icon,
        .enrollment-icon,
        .class-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background-color: #4361ee;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content,
        .enrollment-content,
        .class-content {
            flex: 1;
        }

        .activity-title,
        .enrollment-title,
        .class-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .activity-description,
        .enrollment-description,
        .class-description {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .activity-time,
        .enrollment-time,
        .class-time {
            font-size: 0.75rem;
            color: #adb5bd;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .status-active {
            background-color: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: #f72585;
        }

        .status-completed {
            background-color: rgba(42, 157, 143, 0.1);
            color: #2a9d8f;
        }

        .status-suspended {
            background-color: rgba(230, 57, 70, 0.1);
            color: #e63946;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <!-- Include sidebar and topbar from main admin layout -->
    <?php include __DIR__ . '/../../shared/admin_layout.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>View Profile</h1>
                <p>User details and information</p>
            </div>

            <div class="top-actions">
                <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
            </div>
        </div>

        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <img src="<?php echo BASE_URL . htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Picture">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($user_data['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h1>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email']); ?></p>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user_data['phone'] ?? 'Not provided'); ?></p>
                    <span class="badge">
                        <i class="fas fa-user-tag"></i>
                        <?php echo ucfirst($user_data['role']); ?>
                    </span>
                    <span class="badge">
                        <i class="fas fa-circle" style="color: 
                            <?php echo $user_data['status'] === 'active' ? '#4cc9f0' : ($user_data['status'] === 'pending' ? '#f72585' : ($user_data['status'] === 'suspended' ? '#e63946' : '#6c757d')); ?>; 
                            font-size: 0.75rem;">
                        </i>
                        <?php echo ucfirst($user_data['status']); ?>
                    </span>
                </div>

                <div class="profile-actions">
                    <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?action=edit&id=<?php echo $user_data['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit User
                    </a>
                    <a href="mailto:<?php echo htmlspecialchars($user_data['email']); ?>" class="btn btn-secondary">
                        <i class="fas fa-envelope"></i> Send Email
                    </a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo ($user_data['role'] === 'admin' ? 'Administrator' : ($user_data['role'] === 'instructor' ? 'Instructor' : ($user_data['role'] === 'student' ? 'Student' : 'Applicant'))); ?>
                    </div>
                    <div class="stat-label">User Role</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo date('M j, Y', strtotime($user_data['created_at'])); ?>
                    </div>
                    <div class="stat-label">Joined Date</div>
                </div>

                <?php if ($user_data['last_login']): ?>
                    <div class="stat-card accent">
                        <div class="stat-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="stat-value">
                            <?php echo date('M j', strtotime($user_data['last_login'])); ?>
                        </div>
                        <div class="stat-label">Last Login</div>
                    </div>
                <?php endif; ?>

                <?php if ($user_data['role'] === 'student'): ?>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                        <div class="stat-value">
                            <?php echo $user_data['total_classes']; ?>
                        </div>
                        <div class="stat-label">Enrolled Classes</div>
                    </div>

                    <?php if (!empty($financial_status)): ?>
                        <div class="stat-card accent">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-value">
                                ₦<?php echo number_format($financial_status['total_paid'], 2); ?>
                            </div>
                            <div class="stat-label">Total Paid</div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($user_data['role'] === 'instructor'): ?>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value">
                            <?php echo count($instructor_classes); ?>
                        </div>
                        <div class="stat-label">Classes Assigned</div>
                    </div>
                <?php endif; ?>

                <?php if ($user_data['role'] === 'applicant'): ?>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-value">
                            <?php echo $user_data['total_applications']; ?>
                        </div>
                        <div class="stat-label">Applications</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Personal Information -->
                    <div class="content-card">
                        <h2 class="card-title">
                            <i class="fas fa-user-circle"></i> Personal Information
                        </h2>

                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Full Name</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?>
                                </span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Email Address</span>
                                <span class="info-value"><?php echo htmlspecialchars($user_data['email']); ?></span>
                                <?php if ($user_data['email_verified_at']): ?>
                                    <span class="status-badge status-active">Verified</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">Unverified</span>
                                <?php endif; ?>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Phone Number</span>
                                <span class="info-value <?php echo empty($user_data['phone']) ? 'empty' : ''; ?>">
                                    <?php echo htmlspecialchars($user_data['phone'] ?: 'Not provided'); ?>
                                </span>
                            </div>

                            <?php if (!empty($profile_data['date_of_birth'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value">
                                        <?php echo date('F j, Y', strtotime($profile_data['date_of_birth'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['gender'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Gender</span>
                                    <span class="info-value">
                                        <?php echo ucfirst($profile_data['gender']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['address'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Address</span>
                                    <span class="info-value">
                                        <?php echo htmlspecialchars($profile_data['address']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['city']) || !empty($profile_data['state']) || !empty($profile_data['country'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Location</span>
                                    <span class="info-value">
                                        <?php
                                        $location_parts = [];
                                        if (!empty($profile_data['city'])) $location_parts[] = $profile_data['city'];
                                        if (!empty($profile_data['state'])) $location_parts[] = $profile_data['state'];
                                        if (!empty($profile_data['country'])) $location_parts[] = $profile_data['country'];
                                        echo htmlspecialchars(implode(', ', $location_parts));
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['current_job_title'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Job Title</span>
                                    <span class="info-value">
                                        <?php echo htmlspecialchars($profile_data['current_job_title']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['current_company'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Company</span>
                                    <span class="info-value">
                                        <?php echo htmlspecialchars($profile_data['current_company']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['experience_years'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Experience</span>
                                    <span class="info-value">
                                        <?php echo $profile_data['experience_years']; ?> years
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($profile_data['bio'])): ?>
                            <div class="info-item">
                                <span class="info-label">Bio / About</span>
                                <div class="info-value" style="white-space: pre-wrap; margin-top: 0.5rem;">
                                    <?php echo htmlspecialchars($profile_data['bio']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile_data['qualifications'])): ?>
                            <div class="info-item">
                                <span class="info-label">Qualifications</span>
                                <div class="info-value" style="white-space: pre-wrap; margin-top: 0.5rem;">
                                    <?php echo htmlspecialchars($profile_data['qualifications']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile_data['website']) || !empty($profile_data['linkedin_url']) || !empty($profile_data['github_url'])): ?>
                            <div class="info-item">
                                <span class="info-label">Social Links</span>
                                <div class="social-links">
                                    <?php if (!empty($profile_data['website'])): ?>
                                        <a href="<?php echo htmlspecialchars($profile_data['website']); ?>" target="_blank" class="social-link" title="Website">
                                            <i class="fas fa-globe"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($profile_data['linkedin_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($profile_data['linkedin_url']); ?>" target="_blank" class="social-link" title="LinkedIn">
                                            <i class="fab fa-linkedin-in"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($profile_data['github_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($profile_data['github_url']); ?>" target="_blank" class="social-link" title="GitHub">
                                            <i class="fab fa-github"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Activity -->
                    <div class="content-card">
                        <h2 class="card-title">
                            <i class="fas fa-history"></i> Recent Activity
                        </h2>

                        <div class="activity-list">
                            <?php if (!empty($activities)): ?>
                                <?php foreach ($activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php
                                            $icons = [
                                                'login' => 'fa-sign-in-alt',
                                                'logout' => 'fa-sign-out-alt',
                                                'profile_update' => 'fa-user-edit',
                                                'password_change' => 'fa-key',
                                                'dashboard_access' => 'fa-tachometer-alt',
                                                'enrollment' => 'fa-user-plus',
                                                'payment' => 'fa-credit-card',
                                                'submission' => 'fa-file-upload'
                                            ];
                                            $icon = $icons[$activity['action']] ?? 'fa-history';
                                            ?>
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo ucwords(str_replace('_', ' ', $activity['action'])); ?>
                                            </div>
                                            <div class="activity-description">
                                                <?php echo htmlspecialchars($activity['description'] ?? ''); ?>
                                            </div>
                                            <div class="activity-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('F j, Y g:i A', strtotime($activity['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Account Information -->
                    <div class="content-card">
                        <h2 class="card-title">
                            <i class="fas fa-cog"></i> Account Information
                        </h2>

                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Account ID</span>
                                <span class="info-value">ID-<?php echo str_pad($user_data['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">User Role</span>
                                <span class="info-value">
                                    <?php echo ucfirst($user_data['role']); ?>
                                    <?php if ($user_data['role'] === 'admin'): ?>
                                        <span class="status-badge status-active">
                                            <i class="fas fa-crown"></i> Administrator
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Account Status</span>
                                <span class="info-value">
                                    <?php echo ucfirst($user_data['status']); ?>
                                    <?php if ($user_data['status'] === 'active'): ?>
                                        <span class="status-badge status-active">Active</span>
                                    <?php elseif ($user_data['status'] === 'pending'): ?>
                                        <span class="status-badge status-pending">Pending</span>
                                    <?php elseif ($user_data['status'] === 'suspended'): ?>
                                        <span class="status-badge status-suspended">Suspended</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Created On</span>
                                <span class="info-value">
                                    <?php echo date('F j, Y', strtotime($user_data['created_at'])); ?>
                                </span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Last Updated</span>
                                <span class="info-value">
                                    <?php echo date('F j, Y', strtotime($user_data['updated_at'])); ?>
                                </span>
                            </div>

                            <?php if ($user_data['last_login']): ?>
                                <div class="info-item">
                                    <span class="info-label">Last Login</span>
                                    <span class="info-value">
                                        <?php echo date('F j, Y g:i A', strtotime($user_data['last_login'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #dee2e6;">
                            <div class="info-label">Quick Actions</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?action=edit&id=<?php echo $user_data['id']; ?>"
                                    class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                    <i class="fas fa-edit"></i> Edit User
                                </a>

                                <?php if ($user_data['role'] === 'student'): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/view.php?id=<?php echo $user_data['id']; ?>"
                                        class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                        <i class="fas fa-money-bill-wave"></i> View Finances
                                    </a>
                                <?php endif; ?>

                                <?php if ($user_data['role'] === 'instructor'): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/instructors/assign.php?id=<?php echo $user_data['id']; ?>"
                                        class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                        <i class="fas fa-chalkboard-teacher"></i> Manage Classes
                                    </a>
                                <?php endif; ?>

                                <a href="mailto:<?php echo htmlspecialchars($user_data['email']); ?>"
                                    class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                    <i class="fas fa-envelope"></i> Send Email
                                </a>

                                <?php if ($user_data['status'] === 'active'): ?>
                                    <button onclick="suspendUser(<?php echo $user_data['id']; ?>)"
                                        class="btn btn-danger" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                        <i class="fas fa-ban"></i> Suspend
                                    </button>
                                <?php elseif ($user_data['status'] === 'suspended'): ?>
                                    <button onclick="activateUser(<?php echo $user_data['id']; ?>)"
                                        class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                        <i class="fas fa-check"></i> Activate
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Enrollments or Classes -->
                    <?php if ($user_data['role'] === 'student' && !empty($enrollments)): ?>
                        <div class="content-card">
                            <h2 class="card-title">
                                <i class="fas fa-chalkboard"></i> Recent Enrollments
                            </h2>

                            <div class="enrollment-list">
                                <?php foreach ($enrollments as $enrollment): ?>
                                    <div class="enrollment-item">
                                        <div class="enrollment-icon">
                                            <i class="fas fa-graduation-cap"></i>
                                        </div>
                                        <div class="enrollment-content">
                                            <div class="enrollment-title">
                                                <?php echo htmlspecialchars($enrollment['course_title']); ?>
                                                <span class="status-badge <?php echo 'status-' . $enrollment['status']; ?>">
                                                    <?php echo ucfirst($enrollment['status']); ?>
                                                </span>
                                            </div>
                                            <div class="enrollment-description">
                                                <?php echo htmlspecialchars($enrollment['program_name']); ?> •
                                                <?php echo htmlspecialchars($enrollment['batch_code']); ?>
                                            </div>
                                            <div class="enrollment-time">
                                                Enrolled: <?php echo date('M j, Y', strtotime($enrollment['enrollment_date'])); ?>
                                                <?php if ($enrollment['instructor_name']): ?>
                                                    • Instructor: <?php echo htmlspecialchars($enrollment['instructor_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top: 1.5rem; text-align: center;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/enrollments/?student_id=<?php echo $user_data['id']; ?>"
                                    class="btn btn-secondary" style="font-size: 0.875rem;">
                                    <i class="fas fa-list"></i> View All Enrollments
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($user_data['role'] === 'instructor' && !empty($instructor_classes)): ?>
                        <div class="content-card">
                            <h2 class="card-title">
                                <i class="fas fa-chalkboard-teacher"></i> Assigned Classes
                            </h2>

                            <div class="class-list">
                                <?php foreach ($instructor_classes as $class): ?>
                                    <div class="class-item">
                                        <div class="class-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div class="class-content">
                                            <div class="class-title">
                                                <?php echo htmlspecialchars($class['course_title']); ?>
                                                <span class="status-badge <?php echo 'status-' . $class['status']; ?>">
                                                    <?php echo ucfirst($class['status']); ?>
                                                </span>
                                            </div>
                                            <div class="class-description">
                                                <?php echo htmlspecialchars($class['program_name']); ?> •
                                                <?php echo htmlspecialchars($class['batch_code']); ?>
                                            </div>
                                            <div class="class-time">
                                                <?php echo $class['total_students']; ?> students •
                                                Starts: <?php echo date('M j, Y', strtotime($class['start_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top: 1.5rem; text-align: center;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/?instructor_id=<?php echo $user_data['id']; ?>"
                                    class="btn btn-secondary" style="font-size: 0.875rem;">
                                    <i class="fas fa-list"></i> View All Classes
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Financial Summary for Students -->
                    <?php if ($user_data['role'] === 'student' && !empty($financial_status)): ?>
                        <div class="content-card">
                            <h2 class="card-title">
                                <i class="fas fa-money-bill-wave"></i> Financial Summary
                            </h2>

                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Total Transactions</span>
                                    <span class="info-value">
                                        <?php echo $financial_status['total_transactions']; ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">Total Paid</span>
                                    <span class="info-value" style="font-weight: 600; color: #4cc9f0;">
                                        ₦<?php echo number_format($financial_status['total_paid'], 2); ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">Pending Payments</span>
                                    <span class="info-value">
                                        <?php echo $financial_status['pending_payments']; ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top: 1.5rem; text-align: center;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/view.php?id=<?php echo $user_data['id']; ?>"
                                    class="btn btn-secondary" style="font-size: 0.875rem;">
                                    <i class="fas fa-chart-bar"></i> View Financial Details
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function suspendUser(userId) {
            if (confirm('Are you sure you want to suspend this user? They will not be able to access the system.')) {
                // Implement AJAX call to suspend user
                fetch('<?php echo BASE_URL; ?>modules/admin/users/manage.php?action=suspend&id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('User suspended successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to suspend user'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            }
        }

        function activateUser(userId) {
            if (confirm('Are you sure you want to activate this user?')) {
                // Implement AJAX call to activate user
                fetch('<?php echo BASE_URL; ?>modules/admin/users/manage.php?action=activate&id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('User activated successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to activate user'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            }
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipElements = document.querySelectorAll('[title]');
            tooltipElements.forEach(el => {
                el.addEventListener('mouseenter', showTooltip);
                el.addEventListener('mouseleave', hideTooltip);
            });
        });

        function showTooltip(event) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = event.target.title;
            document.body.appendChild(tooltip);

            const rect = event.target.getBoundingClientRect();
            tooltip.style.position = 'fixed';
            tooltip.style.top = (rect.bottom + 5) + 'px';
            tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';

            event.target.dataset.tooltipId = 'tooltip-' + Date.now();
        }

        function hideTooltip(event) {
            const tooltips = document.querySelectorAll('.tooltip');
            tooltips.forEach(tooltip => tooltip.remove());
        }

        // Format phone numbers for better display
        document.addEventListener('DOMContentLoaded', function() {
            const phoneElements = document.querySelectorAll('.info-value');
            phoneElements.forEach(el => {
                const text = el.textContent.trim();
                if (text.match(/^[0-9]{11}$/)) {
                    el.textContent = text.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
                }
            });
        });
    </script>
</body>

</html>