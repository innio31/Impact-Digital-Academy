<?php
// modules/admin/profile/edit.php

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

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'Nigeria');
    $bio = trim($_POST['bio'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $github_url = trim($_POST['github_url'] ?? '');
    $current_job_title = trim($_POST['current_job_title'] ?? '');
    $current_company = trim($_POST['current_company'] ?? '');

    // Handle profile picture upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = BASE_PATH . 'public/uploads/profiles/';

        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($file_extension, $allowed_extensions)) {
            // Generate unique filename
            $new_filename = 'admin_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                // Resize image if needed
                resizeImage($upload_path, 400, 400);

                $profile_image = 'public/uploads/profiles/' . $new_filename;

                // Delete old profile image if exists
                $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc() && $row['profile_image']) {
                    $old_image = BASE_PATH . $row['profile_image'];
                    if (file_exists($old_image) && is_file($old_image)) {
                        unlink($old_image);
                    }
                }
                $stmt->close();
            }
        }
    }

    // Handle password change if provided
    if (!empty($_POST['current_password']) && !empty($_POST['new_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 8) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    // Update password
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    $stmt->execute();
                    $stmt->close();

                    $success_message = 'Password updated successfully!';
                } else {
                    $error_message = 'New password must be at least 8 characters long.';
                }
            } else {
                $error_message = 'New password and confirmation do not match.';
            }
        } else {
            $error_message = 'Current password is incorrect.';
        }
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Update users table
        if ($profile_image) {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, profile_image = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $profile_image, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssi", $first_name, $last_name, $phone, $user_id);
        }
        $stmt->execute();
        $stmt->close();

        // Check if user profile exists
        $stmt = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $profile_exists = $result->num_rows > 0;
        $stmt->close();

        if ($profile_exists) {
            // Update existing profile
            $stmt = $conn->prepare("UPDATE user_profiles SET date_of_birth = ?, gender = ?, address = ?, city = ?, state = ?, country = ?, bio = ?, website = ?, linkedin_url = ?, github_url = ?, current_job_title = ?, current_company = ? WHERE user_id = ?");
            $stmt->bind_param("ssssssssssssi", $date_of_birth, $gender, $address, $city, $state, $country, $bio, $website, $linkedin_url, $github_url, $current_job_title, $current_company, $user_id);
        } else {
            // Insert new profile
            $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, date_of_birth, gender, address, city, state, country, bio, website, linkedin_url, github_url, current_job_title, current_company) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssssss", $user_id, $date_of_birth, $gender, $address, $city, $state, $country, $bio, $website, $linkedin_url, $github_url, $current_job_title, $current_company);
        }
        $stmt->execute();
        $stmt->close();

        // Update session
        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
        if ($profile_image) {
            $_SESSION['profile_image'] = $profile_image;
        }

        // Log activity
        logActivity($user_id, 'profile_update', 'Admin updated their profile', $_SERVER['REMOTE_ADDR']);

        $conn->commit();
        $success_message = $success_message ? $success_message . ' Profile updated successfully!' : 'Profile updated successfully!';
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = 'An error occurred while updating your profile. Please try again.';
        error_log("Profile update error: " . $e->getMessage());
    }
}

// Fetch current user data
$user_data = [];
$profile_data = [];

$stmt = $conn->prepare("SELECT u.*, up.* FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_data = [
        'email' => $row['email'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'phone' => $row['phone'],
        'profile_image' => $row['profile_image'],
        'role' => $row['role'],
        'status' => $row['status'],
        'last_login' => $row['last_login'],
        'created_at' => $row['created_at']
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
        'current_job_title' => $row['current_job_title'],
        'current_company' => $row['current_company']
    ];
}
$stmt->close();

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        .change-avatar-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .change-avatar-btn:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: scale(1.1);
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

        .profile-tabs {
            display: flex;
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .tab-btn {
            flex: 1;
            padding: 1rem;
            text-align: center;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 500;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab-btn:hover {
            background-color: #f8f9fa;
            color: #4361ee;
        }

        .tab-btn.active {
            color: #4361ee;
            border-bottom-color: #4361ee;
            background-color: rgba(67, 97, 238, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-card {
            background-color: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #212529;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dee2e6;
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
            background-color: #4361ee;
            color: white;
        }

        .btn-primary:hover {
            background-color: #3a56d4;
        }

        .btn-secondary {
            background-color: #f8f9fa;
            color: #212529;
        }

        .btn-secondary:hover {
            background-color: #e9ecef;
        }

        .password-strength {
            height: 4px;
            background-color: #dee2e6;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-weak {
            background-color: #e63946;
        }

        .strength-medium {
            background-color: #f4a261;
        }

        .strength-strong {
            background-color: #2a9d8f;
        }

        .message {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .message-success {
            background-color: rgba(76, 201, 240, 0.1);
            border: 1px solid rgba(76, 201, 240, 0.3);
            color: #4cc9f0;
        }

        .message-error {
            background-color: rgba(230, 57, 70, 0.1);
            border: 1px solid rgba(230, 57, 70, 0.3);
            color: #e63946;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 8px;
        }

        .activity-icon {
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

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <!-- Include sidebar and topbar from main admin layout -->

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>Edit Profile</h1>
                <p>Update your personal information and account settings</p>
            </div>
        </div>

        <div class="profile-container">
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="message message-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="message message-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar" id="avatarContainer">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <img src="<?php echo BASE_URL . htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Picture">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($user_data['first_name'] ?? 'A', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <button class="change-avatar-btn" onclick="document.getElementById('profile_image').click()">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h1>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email']); ?></p>
                    <p><i class="fas fa-user-shield"></i> <?php echo ucfirst($user_data['role']); ?></p>
                    <span class="badge">
                        <i class="fas fa-circle" style="color: #4cc9f0; font-size: 0.75rem;"></i>
                        <?php echo ucfirst($user_data['status']); ?>
                    </span>
                </div>
            </div>

            <!-- Profile Tabs -->
            <div class="profile-tabs">
                <button class="tab-btn active" onclick="switchTab('personal')">Personal Information</button>
                <button class="tab-btn" onclick="switchTab('security')">Security</button>
                <button class="tab-btn" onclick="switchTab('activity')">Recent Activity</button>
            </div>

            <!-- Personal Information Tab -->
            <div id="personalTab" class="tab-content active">
                <form method="POST" enctype="multipart/form-data" class="form-card">
                    <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;" onchange="previewImage(this)">

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
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" id="email" class="form-control"
                                value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" readonly>
                            <small style="color: #6c757d;">Email cannot be changed</small>
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
                                <option value="male" <?php echo ($profile_data['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($profile_data['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo ($profile_data['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <textarea id="address" name="address" class="form-control form-textarea"><?php echo htmlspecialchars($profile_data['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="city">City</label>
                            <input type="text" id="city" name="city" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['city'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="state">State/Province</label>
                            <input type="text" id="state" name="state" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['state'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="country">Country</label>
                            <input type="text" id="country" name="country" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['country'] ?? 'Nigeria'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="bio">Bio / About Me</label>
                        <textarea id="bio" name="bio" class="form-control form-textarea"
                            placeholder="Tell us about yourself..."><?php echo htmlspecialchars($profile_data['bio'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="website">Website</label>
                            <input type="url" id="website" name="website" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['website'] ?? ''); ?>"
                                placeholder="https://example.com">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="linkedin_url">LinkedIn Profile</label>
                            <input type="url" id="linkedin_url" name="linkedin_url" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['linkedin_url'] ?? ''); ?>"
                                placeholder="https://linkedin.com/in/username">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="github_url">GitHub Profile</label>
                            <input type="url" id="github_url" name="github_url" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['github_url'] ?? ''); ?>"
                                placeholder="https://github.com/username">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="current_job_title">Job Title</label>
                            <input type="text" id="current_job_title" name="current_job_title" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['current_job_title'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="current_company">Company</label>
                            <input type="text" id="current_company" name="current_company" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['current_company'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Tab -->
            <div id="securityTab" class="tab-content">
                <form method="POST" class="form-card">
                    <h3 style="margin-bottom: 1.5rem; color: #212529;">Change Password</h3>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password *</label>
                            <input type="password" id="current_password" name="current_password" class="form-control">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password *</label>
                            <input type="password" id="new_password" name="new_password" class="form-control"
                                onkeyup="checkPasswordStrength(this.value)">
                            <div class="password-strength">
                                <div class="strength-bar" id="passwordStrength"></div>
                            </div>
                            <small style="color: #6c757d;">Password must be at least 8 characters long</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                onkeyup="checkPasswordMatch()">
                            <small id="passwordMatch" style="color: #6c757d;"></small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Activity Tab -->
            <div id="activityTab" class="tab-content">
                <div class="form-card">
                    <h3 style="margin-bottom: 1.5rem; color: #212529;">Recent Activity</h3>

                    <div class="activity-list">
                        <?php
                        // Fetch recent activity for this user
                        $conn = getDBConnection();
                        $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $activities = $stmt->get_result();

                        if ($activities->num_rows > 0):
                            while ($activity = $activities->fetch_assoc()):
                        ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php
                                        $icons = [
                                            'login' => 'fa-sign-in-alt',
                                            'logout' => 'fa-sign-out-alt',
                                            'profile_update' => 'fa-user-edit',
                                            'password_change' => 'fa-key',
                                            'dashboard_access' => 'fa-tachometer-alt'
                                        ];
                                        $icon = $icons[$activity['action']] ?? 'fa-history';
                                        ?>
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo ucwords(str_replace('_', ' ', $activity['action'])); ?>
                                        </div>
                                        <div class="activity-description" style="font-size: 0.875rem; color: #6c757d; margin-bottom: 0.25rem;">
                                            <?php echo htmlspecialchars($activity['description'] ?? ''); ?>
                                        </div>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('F j, Y g:i A', strtotime($activity['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 3rem; color: #6c757d;">
                                <i class="fas fa-history" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No recent activity found</p>
                            </div>
                        <?php endif; ?>

                        <?php $stmt->close();
                        $conn->close(); ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');

            // Activate selected button
            event.target.classList.add('active');
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const avatarContainer = document.getElementById('avatarContainer');
                    avatarContainer.innerHTML = `
                        <img src="${e.target.result}" alt="Profile Picture">
                        <button class="change-avatar-btn" onclick="document.getElementById('profile_image').click()">
                            <i class="fas fa-camera"></i>
                        </button>
                    `;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;
            let color = '#e63946';

            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            switch (strength) {
                case 1:
                    color = '#e63946';
                    break;
                case 2:
                    color = '#f4a261';
                    break;
                case 3:
                case 4:
                    color = '#2a9d8f';
                    break;
            }

            strengthBar.style.width = (strength * 25) + '%';
            strengthBar.style.backgroundColor = color;
        }

        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('passwordMatch');

            if (!confirm) {
                matchText.textContent = '';
                matchText.style.color = '#6c757d';
            } else if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = '#2a9d8f';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = '#e63946';
            }
        }

        // Initialize date picker
        if (document.getElementById('date_of_birth')) {
            flatpickr("#date_of_birth", {
                dateFormat: "Y-m-d",
                maxDate: "today"
            });
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
    </script>
</body>

</html>