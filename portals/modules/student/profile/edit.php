<?php
// modules/student/profile/edit.php

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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = clean_input($_POST['first_name'] ?? '');
    $last_name = clean_input($_POST['last_name'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $date_of_birth = clean_input($_POST['date_of_birth'] ?? null);
    $gender = clean_input($_POST['gender'] ?? null);
    $address = clean_input($_POST['address'] ?? '');
    $city = clean_input($_POST['city'] ?? '');
    $state = clean_input($_POST['state'] ?? '');
    $country = clean_input($_POST['country'] ?? 'Nigeria');
    $bio = clean_input($_POST['bio'] ?? '');
    $current_job_title = clean_input($_POST['current_job_title'] ?? '');
    $current_company = clean_input($_POST['current_company'] ?? '');
    $qualifications = clean_input($_POST['qualifications'] ?? '');
    $linkedin_url = clean_input($_POST['linkedin_url'] ?? '');
    $github_url = clean_input($_POST['github_url'] ?? '');
    $website = clean_input($_POST['website'] ?? '');

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($phone)) {
        $error = 'First name, last name, and phone are required.';
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Update users table
            $sql = "UPDATE users SET 
                    first_name = ?, 
                    last_name = ?, 
                    phone = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $first_name, $last_name, $phone, $user_id);
            $stmt->execute();
            $stmt->close();

            // Check if profile exists
            $check_sql = "SELECT id FROM user_profiles WHERE user_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $user_id);
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
                        current_job_title = ?, 
                        current_company = ?, 
                        qualifications = ?, 
                        linkedin_url = ?, 
                        github_url = ?, 
                        website = ?,
                        updated_at = NOW()
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "sssssssssssssi",
                    $date_of_birth,
                    $gender,
                    $address,
                    $city,
                    $state,
                    $country,
                    $bio,
                    $current_job_title,
                    $current_company,
                    $qualifications,
                    $linkedin_url,
                    $github_url,
                    $website,
                    $user_id
                );
            } else {
                // Insert new profile
                $sql = "INSERT INTO user_profiles (
                        user_id, date_of_birth, gender, address, city, state, 
                        country, bio, current_job_title, current_company, 
                        qualifications, linkedin_url, github_url, website
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "isssssssssssss",
                    $user_id,
                    $date_of_birth,
                    $gender,
                    $address,
                    $city,
                    $state,
                    $country,
                    $bio,
                    $current_job_title,
                    $current_company,
                    $qualifications,
                    $linkedin_url,
                    $github_url,
                    $website
                );
            }

            $stmt->execute();
            $stmt->close();

            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                $file_size = $_FILES['profile_image']['size'];

                if (in_array($file_type, $allowed_types) && $file_size <= 5 * 1024 * 1024) { // 5MB max
                    $upload_dir = __DIR__ . '/../../../assets/uploads/profile_images/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                    $filepath = $upload_dir . $filename;

                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $filepath)) {
                        // Update profile image in database
                        $relative_path = 'assets/uploads/profile_images/' . $filename;
                        $update_sql = "UPDATE users SET profile_image = ? WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("si", $relative_path, $user_id);
                        $update_stmt->execute();
                        $update_stmt->close();

                        // Delete old profile image if exists
                        if (!empty($user_details['profile_image']) && file_exists(__DIR__ . '/../../../' . $user_details['profile_image'])) {
                            unlink(__DIR__ . '/../../../' . $user_details['profile_image']);
                        }
                    }
                }
            }

            $conn->commit();
            $success = 'Profile updated successfully!';

            // Refresh user details
            $sql = "SELECT u.*, up.* FROM users u 
                    LEFT JOIN user_profiles up ON u.id = up.user_id 
                    WHERE u.id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_details = $result->fetch_assoc();
            $stmt->close();

            // Update session
            $_SESSION['user_name'] = $user_details['first_name'] . ' ' . $user_details['last_name'];

            // Log activity
            logActivity($user_id, 'profile_update', 'Student updated their profile', $_SERVER['REMOTE_ADDR']);
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error updating profile: ' . $e->getMessage();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Student Portal</title>
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

        /* Alert Banners */
        .alert-banner {
            padding: 1rem 1.5rem;
            margin: 0 1.5rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert-danger {
            background-color: rgba(230, 57, 70, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--dark);
        }

        /* Profile Container */
        .profile-container {
            padding: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Profile Form */
        .profile-form-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .profile-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .profile-header h1 {
            color: var(--dark);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-header p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Profile Image Section */
        .profile-image-section {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border);
        }

        .profile-image-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .profile-image {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .profile-image-placeholder {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .image-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid white;
            transition: var(--transition);
        }

        .image-upload-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .image-upload-input {
            display: none;
        }

        /* Form Styling */
        .form-section {
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: var(--transition);
            background-color: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            color: var(--gray);
            cursor: not-allowed;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        select.form-control {
            cursor: pointer;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .form-actions .btn {
            min-width: 140px;
            justify-content: center;
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
            .profile-container {
                padding: 1rem;
            }

            .profile-form-card {
                padding: 1.5rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .top-actions {
                display: none;
            }
        }

        /* Validation styles */
        .is-invalid {
            border-color: var(--danger) !important;
        }

        .invalid-feedback {
            color: var(--danger);
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: none;
        }

        .is-invalid+.invalid-feedback {
            display: block;
        }
    </style>
</head>

<body>
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>Edit Profile</h1>
                <p>Update your personal information and preferences</p>
            </div>
            <div class="top-actions">
                <a href="<?php echo BASE_URL; ?>modules/student/profile/view.php" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Profile
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Banners -->
        <?php if ($success): ?>
            <div class="alert-banner alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success!</strong> <?php echo $success; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-banner alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error!</strong> <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Container -->
        <div class="profile-container">
            <div class="profile-form-card">
                <form action="" method="POST" enctype="multipart/form-data" id="profileForm">
                    <!-- Profile Image Section -->
                    <div class="profile-image-section">
                        <div class="profile-image-container">
                            <?php if (!empty($user_details['profile_image'])): ?>
                                <img src="<?php echo BASE_URL . $user_details['profile_image']; ?>"
                                    alt="Profile Image"
                                    class="profile-image"
                                    id="profileImagePreview">
                            <?php else: ?>
                                <div class="profile-image-placeholder" id="profileImagePreview">
                                    <?php echo strtoupper(substr($user_details['first_name'], 0, 1) . substr($user_details['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>

                            <div class="image-upload-btn" onclick="document.getElementById('profileImageInput').click()">
                                <i class="fas fa-camera"></i>
                            </div>
                        </div>

                        <input type="file"
                            name="profile_image"
                            id="profileImageInput"
                            accept="image/*"
                            class="image-upload-input"
                            onchange="previewImage(this)">

                        <p class="form-text">
                            <i class="fas fa-info-circle"></i>
                            Click the camera icon to upload a new profile image (max 5MB, JPG/PNG/GIF)
                        </p>
                    </div>

                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user-circle"></i> Personal Information
                        </h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">
                                    First Name <span class="required">*</span>
                                </label>
                                <input type="text"
                                    id="first_name"
                                    name="first_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['first_name'] ?? ''); ?>"
                                    required>
                                <div class="invalid-feedback">Please enter your first name</div>
                            </div>

                            <div class="form-group">
                                <label for="last_name">
                                    Last Name <span class="required">*</span>
                                </label>
                                <input type="text"
                                    id="last_name"
                                    name="last_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['last_name'] ?? ''); ?>"
                                    required>
                                <div class="invalid-feedback">Please enter your last name</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">
                                    Phone Number <span class="required">*</span>
                                </label>
                                <input type="tel"
                                    id="phone"
                                    name="phone"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['phone'] ?? ''); ?>"
                                    required>
                                <div class="invalid-feedback">Please enter a valid phone number</div>
                            </div>

                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email"
                                    id="email"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['email'] ?? ''); ?>"
                                    disabled>
                                <p class="form-text">Email cannot be changed. Contact support if needed.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date"
                                    id="date_of_birth"
                                    name="date_of_birth"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['date_of_birth'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo ($user_details['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($user_details['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($user_details['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    <option value="prefer_not_to_say" <?php echo ($user_details['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : ''; ?>>Prefer not to say</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-home"></i> Address Information
                        </h3>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address"
                                name="address"
                                class="form-control"
                                rows="2"><?php echo htmlspecialchars($user_details['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text"
                                    id="city"
                                    name="city"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['city'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="state">State/Province</label>
                                <input type="text"
                                    id="state"
                                    name="state"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['state'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="country">Country</label>
                            <input type="text"
                                id="country"
                                name="country"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user_details['country'] ?? 'Nigeria'); ?>">
                        </div>
                    </div>

                    <!-- Professional Information -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-briefcase"></i> Professional Information
                        </h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="current_job_title">Current Job Title</label>
                                <input type="text"
                                    id="current_job_title"
                                    name="current_job_title"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['current_job_title'] ?? ''); ?>"
                                    placeholder="e.g., Software Developer">
                            </div>

                            <div class="form-group">
                                <label for="current_company">Current Company</label>
                                <input type="text"
                                    id="current_company"
                                    name="current_company"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['current_company'] ?? ''); ?>"
                                    placeholder="e.g., Google Inc.">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="qualifications">Qualifications</label>
                            <textarea id="qualifications"
                                name="qualifications"
                                class="form-control"
                                rows="3"
                                placeholder="List your educational and professional qualifications"><?php echo htmlspecialchars($user_details['qualifications'] ?? ''); ?></textarea>
                            <p class="form-text">Separate qualifications with commas or new lines</p>
                        </div>
                    </div>

                    <!-- Social Media & Links -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-share-alt"></i> Social Media & Links
                        </h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="linkedin_url">
                                    <i class="fab fa-linkedin"></i> LinkedIn Profile
                                </label>
                                <input type="url"
                                    id="linkedin_url"
                                    name="linkedin_url"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['linkedin_url'] ?? ''); ?>"
                                    placeholder="https://linkedin.com/in/yourprofile">
                            </div>

                            <div class="form-group">
                                <label for="github_url">
                                    <i class="fab fa-github"></i> GitHub Profile
                                </label>
                                <input type="url"
                                    id="github_url"
                                    name="github_url"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($user_details['github_url'] ?? ''); ?>"
                                    placeholder="https://github.com/yourusername">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="website">
                                <i class="fas fa-globe"></i> Personal Website/Portfolio
                            </label>
                            <input type="url"
                                id="website"
                                name="website"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user_details['website'] ?? ''); ?>"
                                placeholder="https://yourwebsite.com">
                        </div>
                    </div>

                    <!-- Bio/About Me -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-info-circle"></i> Bio/About Me
                        </h3>

                        <div class="form-group">
                            <label for="bio">Tell us about yourself</label>
                            <textarea id="bio"
                                name="bio"
                                class="form-control"
                                rows="4"
                                placeholder="Tell us about yourself, your interests, career goals, etc."><?php echo htmlspecialchars($user_details['bio'] ?? ''); ?></textarea>
                            <p class="form-text">Share a brief introduction about yourself (max 500 characters)</p>
                            <div id="bioCounter" class="form-text" style="text-align: right;"></div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button type="reset" class="btn btn-secondary" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                        <a href="<?php echo BASE_URL; ?>modules/student/profile/view.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>

            <!-- Footer -->
            <div class="dashboard-footer">
                <div class="system-status">
                    <div class="status-indicator"></div>
                    <span>System Status: Operational</span>
                </div>
                <div>
                    <span>Last Updated: <?php echo date('F j, Y, g:i a'); ?></span>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        // Image preview function
        function previewImage(input) {
            const preview = document.getElementById('profileImagePreview');
            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                if (preview.tagName === 'IMG') {
                    preview.src = e.target.result;
                } else {
                    // Convert placeholder to img
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'profile-image';
                    img.id = 'profileImagePreview';
                    img.alt = 'Profile Image';
                    preview.parentNode.replaceChild(img, preview);
                }
            }

            if (file) {
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }

                // Validate file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    alert('Only JPG, PNG, and GIF files are allowed');
                    input.value = '';
                    return;
                }

                reader.readAsDataURL(file);
            }
        }

        // Bio character counter
        const bioTextarea = document.getElementById('bio');
        const bioCounter = document.getElementById('bioCounter');

        function updateBioCounter() {
            const maxLength = 500;
            const currentLength = bioTextarea.value.length;
            const remaining = maxLength - currentLength;

            bioCounter.textContent = `${currentLength} / ${maxLength} characters`;

            if (remaining < 0) {
                bioCounter.style.color = 'var(--danger)';
                bioTextarea.classList.add('is-invalid');
            } else if (remaining < 50) {
                bioCounter.style.color = 'var(--warning)';
                bioTextarea.classList.remove('is-invalid');
            } else {
                bioCounter.style.color = 'var(--gray)';
                bioTextarea.classList.remove('is-invalid');
            }
        }

        if (bioTextarea && bioCounter) {
            bioTextarea.addEventListener('input', updateBioCounter);
            updateBioCounter(); // Initial count
        }

        // Form validation
        const form = document.getElementById('profileForm');
        const requiredFields = form.querySelectorAll('[required]');

        // Add validation on blur
        requiredFields.forEach(field => {
            field.addEventListener('blur', function() {
                validateField(this);
            });
        });

        function validateField(field) {
            const value = field.value.trim();
            const errorDiv = field.nextElementSibling;

            if (!value) {
                field.classList.add('is-invalid');
                return false;
            }

            // Phone validation
            if (field.id === 'phone') {
                const phoneRegex = /^[\+]?[0-9\s\-\(\)]{10,}$/;
                if (!phoneRegex.test(value)) {
                    field.classList.add('is-invalid');
                    if (errorDiv) errorDiv.textContent = 'Please enter a valid phone number';
                    return false;
                }
            }

            field.classList.remove('is-invalid');
            return true;
        }

        // Form submission validation
        form.addEventListener('submit', function(e) {
            let isValid = true;

            // Validate all required fields
            requiredFields.forEach(field => {
                if (!validateField(field)) {
                    isValid = false;
                }
            });

            // Validate bio length
            if (bioTextarea && bioTextarea.value.length > 500) {
                bioTextarea.classList.add('is-invalid');
                isValid = false;
                alert('Bio must be 500 characters or less');
            }

            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = form.querySelector('.is-invalid');
                if (firstError) {
                    firstError.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    firstError.focus();
                }
                return false;
            }

            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            return true;
        });

        // Reset form confirmation
        document.getElementById('resetBtn').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to reset all form fields? Any unsaved changes will be lost.')) {
                e.preventDefault();
            }
        });

        // Auto-save draft (optional feature)
        let autoSaveTimeout;

        function autoSaveDraft() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Implement auto-save functionality here
                console.log('Auto-saving draft...');
            }, 3000);
        }

        // Listen for changes in form fields
        const formFields = form.querySelectorAll('input, textarea, select');
        formFields.forEach(field => {
            field.addEventListener('input', autoSaveDraft);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                form.querySelector('button[type="submit"]').click();
            }

            // Esc to cancel
            if (e.key === 'Escape') {
                if (confirm('Are you sure you want to cancel? Unsaved changes will be lost.')) {
                    window.location.href = '<?php echo BASE_URL; ?>modules/student/profile/view.php';
                }
            }
        });

        // Initialize date picker max date (must be at least 16 years old)
        const dobInput = document.getElementById('date_of_birth');
        if (dobInput) {
            const today = new Date();
            const minDate = new Date(today.getFullYear() - 100, today.getMonth(), today.getDate()); // 100 years ago
            const maxDate = new Date(today.getFullYear() - 16, today.getMonth(), today.getDate()); // At least 16 years old

            dobInput.min = minDate.toISOString().split('T')[0];
            dobInput.max = maxDate.toISOString().split('T')[0];
        }

        // URL validation helper
        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        // Validate URLs on blur
        const urlFields = form.querySelectorAll('input[type="url"]');
        urlFields.forEach(field => {
            field.addEventListener('blur', function() {
                const value = this.value.trim();
                if (value && !isValidUrl(value)) {
                    this.classList.add('is-invalid');
                    const errorDiv = this.nextElementSibling;
                    if (errorDiv && errorDiv.classList.contains('invalid-feedback')) {
                        errorDiv.textContent = 'Please enter a valid URL (e.g., https://example.com)';
                        errorDiv.style.display = 'block';
                    }
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        });
    </script>
</body>

</html>