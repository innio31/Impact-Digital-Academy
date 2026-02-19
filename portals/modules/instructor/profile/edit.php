<?php
// modules/instructor/profile/edit.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = "Invalid form submission. Please try again.";
    } else {
        // Basic validation
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');

        // Profile fields
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $gender = sanitize_input($_POST['gender'] ?? '');
        $address = sanitize_input($_POST['address'] ?? '');
        $city = sanitize_input($_POST['city'] ?? '');
        $state = sanitize_input($_POST['state'] ?? '');
        $country = sanitize_input($_POST['country'] ?? 'Nigeria');
        $bio = sanitize_input($_POST['bio'] ?? '');
        $website = sanitize_input($_POST['website'] ?? '');
        $linkedin_url = sanitize_input($_POST['linkedin_url'] ?? '');
        $github_url = sanitize_input($_POST['github_url'] ?? '');
        $qualifications = sanitize_input($_POST['qualifications'] ?? '');
        $experience_years = intval($_POST['experience_years'] ?? 0);
        $current_job_title = sanitize_input($_POST['current_job_title'] ?? '');
        $current_company = sanitize_input($_POST['current_company'] ?? '');

        try {
            // Start transaction
            $conn->begin_transaction();

            // Update users table
            $sql = "UPDATE users SET 
                first_name = ?, 
                last_name = ?, 
                phone = ?, 
                email = ?,
                updated_at = NOW()
                WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $email, $instructor_id);
            $stmt->execute();
            $stmt->close();

            // Check if profile exists
            $check_sql = "SELECT id FROM user_profiles WHERE user_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $instructor_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_stmt->close();

            if ($check_result->num_rows > 0) {
                // Update existing profile
                $sql = "UPDATE user_profiles SET 
                    date_of_birth = ?, 
                    gender = ?, 
                    address = ?, 
                    city = ?, 
                    state = ?, 
                    country = ?, 
                    bio = ?, 
                    website = ?, 
                    linkedin_url = ?, 
                    github_url = ?, 
                    qualifications = ?, 
                    experience_years = ?, 
                    current_job_title = ?, 
                    current_company = ?,
                    updated_at = NOW()
                    WHERE user_id = ?";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssssssssssissi",
                    $date_of_birth,
                    $gender,
                    $address,
                    $city,
                    $state,
                    $country,
                    $bio,
                    $website,
                    $linkedin_url,
                    $github_url,
                    $qualifications,
                    $experience_years,
                    $current_job_title,
                    $current_company,
                    $instructor_id
                );
            } else {
                // Insert new profile
                $sql = "INSERT INTO user_profiles (
                    user_id, date_of_birth, gender, address, city, state, 
                    country, bio, website, linkedin_url, github_url, 
                    qualifications, experience_years, current_job_title, 
                    current_company
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "isssssssssssiss",
                    $instructor_id,
                    $date_of_birth,
                    $gender,
                    $address,
                    $city,
                    $state,
                    $country,
                    $bio,
                    $website,
                    $linkedin_url,
                    $github_url,
                    $qualifications,
                    $experience_years,
                    $current_job_title,
                    $current_company
                );
            }

            $stmt->execute();
            $stmt->close();

            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../../public/uploads/profiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                $file_size = $_FILES['profile_image']['size'];

                if (in_array($file_type, $allowed_types) && $file_size <= 5 * 1024 * 1024) { // 5MB max
                    $file_ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $filename = 'instructor_' . $instructor_id . '_' . time() . '.' . $file_ext;
                    $filepath = $upload_dir . $filename;

                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $filepath)) {
                        // Update profile image path in database
                        $relative_path = 'uploads/profiles/' . $filename;
                        $update_sql = "UPDATE users SET profile_image = ? WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("si", $relative_path, $instructor_id);
                        $update_stmt->execute();
                        $update_stmt->close();

                        // Delete old profile image if exists
                        $get_old_sql = "SELECT profile_image FROM users WHERE id = ?";
                        $get_old_stmt = $conn->prepare($get_old_sql);
                        $get_old_stmt->bind_param("i", $instructor_id);
                        $get_old_stmt->execute();
                        $get_old_result = $get_old_stmt->get_result();
                        if ($row = $get_old_result->fetch_assoc()) {
                            if ($row['profile_image'] && file_exists('../../../public/' . $row['profile_image'])) {
                                unlink('../../../public/' . $row['profile_image']);
                            }
                        }
                        $get_old_stmt->close();
                    }
                }
            }

            // Handle password change if provided
            if (!empty($_POST['current_password']) && !empty($_POST['new_password'])) {
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];

                // Get current password hash
                $pass_sql = "SELECT password FROM users WHERE id = ?";
                $pass_stmt = $conn->prepare($pass_sql);
                $pass_stmt->bind_param("i", $instructor_id);
                $pass_stmt->execute();
                $pass_result = $pass_stmt->get_result();

                if ($row = $pass_result->fetch_assoc()) {
                    if (password_verify($current_password, $row['password'])) {
                        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_pass_sql = "UPDATE users SET password = ? WHERE id = ?";
                        $update_pass_stmt = $conn->prepare($update_pass_sql);
                        $update_pass_stmt->bind_param("si", $new_password_hash, $instructor_id);
                        $update_pass_stmt->execute();
                        $update_pass_stmt->close();
                    } else {
                        $error_message = "Current password is incorrect.";
                    }
                }
                $pass_stmt->close();
            }

            $conn->commit();
            $success_message = "Profile updated successfully!";

            // Update session variables
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;

            // Log the activity
            logActivity('profile_update', 'Instructor updated their profile');
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error updating profile: " . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        }
    }
}

// Fetch current user data
$user_data = [];
$profile_data = [];

$sql = "SELECT u.*, up.* 
        FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $row = $result->fetch_assoc()) {
    $user_data = $row;
    $profile_data = $row; // Profile fields are merged
}
$stmt->close();
$conn->close();

// Get instructor name
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
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
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Same as dashboard */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e3a8a, #1e40af);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e40af;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo-text {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e40af;
            font-weight: bold;
            font-size: 1.2rem;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        .user-details h3 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-nav {
            padding: 1.5rem 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--warning);
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: white;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: absolute;
            bottom: 0;
            width: 100%;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-title p {
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Profile Container */
        .profile-container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .profile-avatar {
            position: relative;
        }

        .avatar-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            background: var(--light);
        }

        .avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid var(--primary);
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid white;
            transition: all 0.3s ease;
        }

        .avatar-upload-btn:hover {
            background: var(--secondary);
            transform: scale(1.1);
        }

        .profile-info h2 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .profile-info p {
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .profile-info .badge {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Tabs */
        .profile-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .tab-btn {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 500;
            color: var(--gray);
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        /* Form Sections */
        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
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
            padding: 0.75rem 1rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-control[readonly] {
            background: var(--light);
            cursor: not-allowed;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* Buttons */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--light-gray);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        /* Password Requirements */
        .password-requirements {
            background: var(--light);
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .requirement.valid {
            color: var(--success);
        }

        .requirement.invalid {
            color: var(--gray);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .sidebar .logo-text,
            .sidebar .user-details,
            .sidebar .nav-label {
                display: none;
            }

            .main-content {
                margin-left: 80px;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">IDA</div>
                <div class="logo-text">Instructor Panel</div>
            </div>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php if (!empty($user_data['profile_image'])): ?>
                    <img src="<?php echo BASE_URL . 'public/' . htmlspecialchars($user_data['profile_image']); ?>"
                        alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($instructor_name); ?></h3>
                <p><i class="fas fa-chalkboard-teacher"></i> Instructor</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-label">Dashboard</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-item">
                <i class="fas fa-chalkboard"></i>
                <span class="nav-label">My Classes</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="nav-item active">
                <i class="fas fa-user"></i>
                <span class="nav-label">My Profile</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/settings.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span class="nav-label">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item"
                onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i>
                <span class="nav-label">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>Edit Profile</h1>
                <p>Manage your personal information and account settings</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <!-- Profile Container -->
        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <img src="<?php echo BASE_URL . 'public/' . htmlspecialchars($user_data['profile_image']); ?>"
                            alt="Profile" class="avatar-img">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($user_data['first_name'] ?? 'I', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <label for="profile_image" class="avatar-upload-btn" title="Change Photo">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user_data['first_name'] ?? '') . ' ' . htmlspecialchars($user_data['last_name'] ?? ''); ?></h2>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email'] ?? ''); ?></p>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user_data['phone'] ?? 'Not set'); ?></p>
                    <div class="badge">Instructor</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="profile-tabs">
                <button class="tab-btn active" onclick="showTab('personal')">Personal Info</button>
                <button class="tab-btn" onclick="showTab('professional')">Professional Info</button>
                <button class="tab-btn" onclick="showTab('account')">Account Settings</button>
            </div>

            <!-- Personal Info Tab -->
            <form id="profileForm" method="POST" enctype="multipart/form-data" class="form-section active" id="personal-tab">
                <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;"
                    onchange="document.getElementById('profileForm').submit();">

                <h3 class="section-title">Personal Information</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="form-control"
                            value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="form-control"
                            value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control"
                            value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                        <div class="form-text">Used for login and notifications</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                            value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['date_of_birth'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gender">Gender</label>
                        <select id="gender" name="gender" class="form-control">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($profile_data['gender'] ?? '') == 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($profile_data['gender'] ?? '') == 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($profile_data['gender'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <h3 class="section-title">Contact Information</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($profile_data['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="city">City</label>
                        <input type="text" id="city" name="city" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['city'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="state">State</label>
                        <input type="text" id="state" name="state" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['state'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="country">Country</label>
                        <input type="text" id="country" name="country" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['country'] ?? 'Nigeria'); ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showTab('professional')">
                        Next: Professional Info <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Personal Info
                    </button>
                </div>
            </form>

            <!-- Professional Info Tab -->
            <form method="POST" enctype="multipart/form-data" class="form-section" id="professional-tab">
                <h3 class="section-title">Professional Information</h3>

                <div class="form-group">
                    <label class="form-label" for="bio">Bio / Introduction</label>
                    <textarea id="bio" name="bio" class="form-control" rows="4"
                        placeholder="Tell students about yourself, your teaching philosophy, etc."><?php echo htmlspecialchars($profile_data['bio'] ?? ''); ?></textarea>
                    <div class="form-text">This will be visible to students in your classes</div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="qualifications">Qualifications</label>
                        <textarea id="qualifications" name="qualifications" class="form-control" rows="3"
                            placeholder="e.g., B.Sc Computer Science, AWS Certified, etc."><?php echo htmlspecialchars($profile_data['qualifications'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="experience_years">Years of Experience</label>
                        <input type="number" id="experience_years" name="experience_years" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['experience_years'] ?? 0); ?>" min="0">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="current_job_title">Current Job Title</label>
                        <input type="text" id="current_job_title" name="current_job_title" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['current_job_title'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="current_company">Current Company/Organization</label>
                        <input type="text" id="current_company" name="current_company" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['current_company'] ?? ''); ?>">
                    </div>
                </div>

                <h3 class="section-title">Online Presence</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="website">Website/Blog</label>
                        <input type="url" id="website" name="website" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['website'] ?? ''); ?>"
                            placeholder="https://yourwebsite.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="linkedin_url">LinkedIn Profile</label>
                        <input type="url" id="linkedin_url" name="linkedin_url" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['linkedin_url'] ?? ''); ?>"
                            placeholder="https://linkedin.com/in/yourprofile">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="github_url">GitHub Profile</label>
                        <input type="url" id="github_url" name="github_url" class="form-control"
                            value="<?php echo htmlspecialchars($profile_data['github_url'] ?? ''); ?>"
                            placeholder="https://github.com/yourusername">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showTab('personal')">
                        <i class="fas fa-arrow-left"></i> Back to Personal
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showTab('account')">
                        Next: Account Settings <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Professional Info
                    </button>
                </div>
            </form>

            <!-- Account Settings Tab -->
            <form method="POST" enctype="multipart/form-data" class="form-section" id="account-tab">
                <h3 class="section-title">Change Password</h3>
                <p class="form-text" style="margin-bottom: 1.5rem;">Leave blank to keep current password</p>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control"
                            oninput="validatePassword()">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                    </div>
                </div>

                <div class="password-requirements" id="passwordRequirements">
                    <div class="requirement invalid" id="req-length">
                        <i class="fas fa-circle"></i>
                        <span>At least 8 characters</span>
                    </div>
                    <div class="requirement invalid" id="req-uppercase">
                        <i class="fas fa-circle"></i>
                        <span>At least one uppercase letter</span>
                    </div>
                    <div class="requirement invalid" id="req-lowercase">
                        <i class="fas fa-circle"></i>
                        <span>At least one lowercase letter</span>
                    </div>
                    <div class="requirement invalid" id="req-number">
                        <i class="fas fa-circle"></i>
                        <span>At least one number</span>
                    </div>
                    <div class="requirement invalid" id="req-special">
                        <i class="fas fa-circle"></i>
                        <span>At least one special character</span>
                    </div>
                </div>

                <h3 class="section-title">Account Information</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Account Status</label>
                        <input type="text" class="form-control" value="<?php echo ucfirst($user_data['status'] ?? 'active'); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Member Since</label>
                        <input type="text" class="form-control"
                            value="<?php echo date('F j, Y', strtotime($user_data['created_at'] ?? 'now')); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Last Login</label>
                        <input type="text" class="form-control"
                            value="<?php echo $user_data['last_login'] ? date('F j, Y g:i a', strtotime($user_data['last_login'])) : 'Never'; ?>" readonly>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showTab('professional')">
                        <i class="fas fa-arrow-left"></i> Back to Professional
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Account Settings
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.form-section').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Activate tab button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                    btn.classList.add('active');
                }
            });
        }

        // Password validation
        function validatePassword() {
            const password = document.getElementById('new_password').value;
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };

            // Update requirement indicators
            document.getElementById('req-length').className = requirements.length ? 'requirement valid' : 'requirement invalid';
            document.getElementById('req-uppercase').className = requirements.uppercase ? 'requirement valid' : 'requirement invalid';
            document.getElementById('req-lowercase').className = requirements.lowercase ? 'requirement valid' : 'requirement invalid';
            document.getElementById('req-number').className = requirements.number ? 'requirement valid' : 'requirement invalid';
            document.getElementById('req-special').className = requirements.special ? 'requirement valid' : 'requirement invalid';

            // Validate password match
            const confirmPassword = document.getElementById('confirm_password').value;
            if (confirmPassword && password !== confirmPassword) {
                document.getElementById('confirm_password').style.borderColor = 'var(--danger)';
            } else {
                document.getElementById('confirm_password').style.borderColor = '';
            }
        }

        // Form submission validation
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            // Check password if provided
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (currentPassword || newPassword || confirmPassword) {
                // All password fields must be filled
                if (!currentPassword || !newPassword || !confirmPassword) {
                    e.preventDefault();
                    alert('Please fill all password fields if you want to change your password.');
                    showTab('account');
                    return;
                }

                // Check password match
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New password and confirmation do not match.');
                    showTab('account');
                    return;
                }

                // Check password strength
                const requirements = {
                    length: newPassword.length >= 8,
                    uppercase: /[A-Z]/.test(newPassword),
                    lowercase: /[a-z]/.test(newPassword),
                    number: /[0-9]/.test(newPassword),
                    special: /[^A-Za-z0-9]/.test(newPassword)
                };

                if (!Object.values(requirements).every(req => req)) {
                    e.preventDefault();
                    alert('Please ensure your new password meets all requirements.');
                    showTab('account');
                    return;
                }
            }
        });

        // Auto-expand textareas
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            validatePassword();
        });

        // Auto-save indicator
        let saveTimeout;
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    console.log('Auto-save ready...');
                }, 2000);
            });
        });
    </script>
</body>

</html>