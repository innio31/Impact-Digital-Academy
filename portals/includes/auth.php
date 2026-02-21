<?php
// includes/auth.php

/**
 * Login user
 */
function loginUser($email, $password)
{
    $conn = getDBConnection();

    // Debug output
    if (DEBUG_MODE) {
        echo "<pre style='background: #f0f0f0; padding: 10px;'>";
        echo "=== DEBUG LOGIN ===\n";
        echo "Email: " . htmlspecialchars($email) . "\n";
        echo "Password: " . htmlspecialchars($password) . "\n";
    }

    $email = escapeSQL($email);

    // Get user with status check
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if (DEBUG_MODE) {
        echo "SQL: " . htmlspecialchars($sql) . "\n";
        echo "Found rows: " . $result->num_rows . "\n";
    }

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (DEBUG_MODE) {
            echo "User found:\n";
            print_r($user);
            echo "\n";
        }

        // Check user status
        if ($user['status'] !== 'active') {
            if (DEBUG_MODE) {
                echo "User status is: " . $user['status'] . " (not active)\n";
                echo "</pre>";
            }
            return [
                'success' => false,
                'message' => 'Your account is ' . $user['status'] . '. Please contact administrator.'
            ];
        }

        // Verify password
        if (DEBUG_MODE) {
            echo "Testing password verification...\n";
            echo "Input password: " . $password . "\n";
            echo "Stored hash: " . $user['password'] . "\n";
        }

        $password_verified = password_verify($password, $user['password']);

        if (DEBUG_MODE) {
            echo "password_verify result: " . ($password_verified ? 'TRUE' : 'FALSE') . "\n";
        }

        if ($password_verified) {
            // Update last login
            $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['login_time'] = time();

            // Log activity
            logActivity('login', 'User logged in successfully');

            if (DEBUG_MODE) {
                echo "✓ Login successful!\n";
                echo "</pre>";
            }

            return [
                'success' => true,
                'user' => $user
            ];
        } else {
            if (DEBUG_MODE) {
                echo "✗ Password verification failed\n";
                echo "</pre>";
            }
        }
    } else {
        if (DEBUG_MODE) {
            echo "✗ No user found with email: " . htmlspecialchars($email) . "\n";
            echo "</pre>";
        }
    }

    // Log failed attempt
    logActivity('login_failed', 'Failed login attempt for email: ' . $email);

    return [
        'success' => false,
        'message' => 'Invalid email or password'
    ];
}

/**
 * Register new user (applicant/student only)
 */
function registerUser($data)
{
    $conn = getDBConnection();

    // Validate required fields
    $required = ['email', 'password', 'first_name', 'last_name'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }

    // Validate email
    if (!isValidEmail($data['email'])) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    // Check if email already exists
    $check_sql = "SELECT id FROM users WHERE email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $data['email']);
    $check_stmt->execute();

    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    // Hash password
    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert into users table - always role = 'applicant' for new registrations
        $user_sql = "INSERT INTO users (email, password, first_name, last_name, phone, role, status) 
                     VALUES (?, ?, ?, ?, ?, 'applicant', 'pending')";
        $user_stmt = $conn->prepare($user_sql);

        // Extract values to avoid reference issues
        $email = $data['email'];
        $first_name = $data['first_name'];
        $last_name = $data['last_name'];
        $phone = !empty($data['phone']) ? $data['phone'] : '';

        $user_stmt->bind_param(
            "sssss",
            $email,
            $hashed_password,
            $first_name,
            $last_name,
            $phone
        );
        $user_stmt->execute();

        $user_id = $conn->insert_id;

        // Insert into user_profiles table
        $profile_sql = "INSERT INTO user_profiles (user_id, date_of_birth, gender, address, city, state, country) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $profile_stmt = $conn->prepare($profile_sql);

        // Extract values for profile
        $date_of_birth = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        $gender = !empty($data['gender']) ? $data['gender'] : null;
        $address = !empty($data['address']) ? $data['address'] : null;
        $city = !empty($data['city']) ? $data['city'] : null;
        $state = !empty($data['state']) ? $data['state'] : null;
        $country = !empty($data['country']) ? $data['country'] : 'Nigeria';

        $profile_stmt->bind_param(
            "issssss",
            $user_id,
            $date_of_birth,
            $gender,
            $address,
            $city,
            $state,
            $country
        );
        $profile_stmt->execute();

        // Determine the program type and school fields to store
        // For school-based programs, we'll store the school info in the existing fields
        $program_id = !empty($data['program_id']) ? $data['program_id'] : null;
        $program_type = $data['program_type'] ?? 'online';
        $school_id = !empty($data['school_id']) ? $data['school_id'] : null;

        // Store program type info in the existing fields
        // For now, we'll use program_id for the program and store other info in existing text fields
        $motivation = !empty($data['motivation']) ? $data['motivation'] : null;

        // Combine qualifications, experience, and program preferences into the existing fields
        $qualifications = !empty($data['qualifications']) ? $data['qualifications'] : '';
        $experience = !empty($data['experience']) ? $data['experience'] : '';

        // Add program type and school info to qualifications or experience for reference
        if ($program_type === 'school' && $school_id) {
            $school_info = "\n\n[School Program - School ID: " . $school_id . "]";
            if (!empty($data['school_name'])) {
                $school_info .= " - " . $data['school_name'];
            }
            if (!empty($data['preferred_school_term'])) {
                $school_info .= " - Preferred Term: " . $data['preferred_school_term'];
            }
            $qualifications .= $school_info;
        } elseif ($program_type === 'onsite' && !empty($data['preferred_term'])) {
            $qualifications .= "\n\n[Onsite Program - Preferred Term: " . $data['preferred_term'] . "]";
        } elseif ($program_type === 'online' && !empty($data['preferred_block'])) {
            $qualifications .= "\n\n[Online Program - Preferred Block: " . $data['preferred_block'] . "]";
        }

        // Add learning mode preference to qualifications
        if (!empty($data['learning_mode_preference'])) {
            $qualifications .= "\n[Learning Mode: " . $data['learning_mode_preference'] . "]";
        }

        // Insert application record - only using columns that exist in your table
        // Based on your SQL dump, the applications table has: user_id, applying_as, program_id, motivation, qualifications, experience, status
        $app_sql = "INSERT INTO applications (user_id, applying_as, program_id, motivation, qualifications, experience, status) 
                    VALUES (?, 'student', ?, ?, ?, ?, 'pending')";
        $app_stmt = $conn->prepare($app_sql);

        $app_stmt->bind_param(
            "iisss",
            $user_id,
            $program_id,
            $motivation,
            $qualifications,
            $experience
        );

        $app_stmt->execute();

        // Commit transaction
        $conn->commit();

        // Log activity
        logActivity('registration', 'New student registered: ' . $data['email'], 'users', $user_id);

        // Send notification to admin
        $admin_sql = "SELECT id FROM users WHERE role = 'admin' LIMIT 1";
        $admin_result = $conn->query($admin_sql);
        if ($admin_row = $admin_result->fetch_assoc()) {
            sendNotification(
                $admin_row['id'],
                'New Student Application Received',
                "A new student application has been submitted by " . $data['first_name'] . " " . $data['last_name'],
                'system',
                $user_id
            );
        }

        // After successful registration, send confirmation email
        try {
            $application_data = [
                'program_type' => $data['program_type'] ?? 'online',
                'program_id' => $program_id,
                'school_name' => $data['school_name'] ?? '',
                'preferred_term' => $data['preferred_term'] ?? '',
                'preferred_block' => $data['preferred_block'] ?? '',
                'preferred_school_term' => $data['preferred_school_term'] ?? ''
            ];
            sendApplicationConfirmationEmail($user_id, $application_data);
        } catch (Exception $e) {
            error_log("Failed to send confirmation email: " . $e->getMessage());
            // Don't fail the registration if email fails
        }

        return [
            'success' => true,
            'user_id' => $user_id,
            'message' => 'Registration successful. Please wait for admin approval.'
        ];
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Registration Error: " . $e->getMessage());
        error_log("Error details: " . $e->getTraceAsString());

        return [
            'success' => false,
            'message' => 'Registration failed. Please try again. Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Logout user
 */
function logoutUser()
{
    if (isLoggedIn()) {
        logActivity('logout', 'User logged out');
    }

    // Clear session
    session_unset();
    session_destroy();

    // Start new session for flash messages
    session_start();

    return true;
}

/**
 * Check if email is verified
 */
function isEmailVerified($user_id)
{
    $conn = getDBConnection();

    $sql = "SELECT email_verified_at FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    return !empty($user['email_verified_at']);
}

/**
 * Update password
 */
function updatePassword($user_id, $current_password, $new_password)
{
    $conn = getDBConnection();

    // Get current password hash
    $sql = "SELECT password FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    // Update to new password
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $update_sql = "UPDATE users SET password = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("si", $new_hash, $user_id);

    if ($update_stmt->execute()) {
        logActivity('password_change', 'User changed password', 'users', $user_id);
        return ['success' => true, 'message' => 'Password updated successfully'];
    }

    return ['success' => false, 'message' => 'Failed to update password'];
}

/**
 * Reset password request
 */
function requestPasswordReset($email)
{
    $conn = getDBConnection();

    // Check if email exists
    $sql = "SELECT id FROM users WHERE email = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'Email not found'];
    }

    $user = $result->fetch_assoc();

    // Generate reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store token in session or database (simplified version)
    $_SESSION['reset_token'] = $token;
    $_SESSION['reset_user_id'] = $user['id'];
    $_SESSION['reset_expires'] = $expires;

    // In a real application, you would:
    // 1. Store token in database
    // 2. Send email with reset link

    logActivity('password_reset_request', 'Password reset requested', 'users', $user['id']);

    return [
        'success' => true,
        'message' => 'Password reset instructions sent to your email',
        'token' => $token // For demo only - don't return in production
    ];
}

/**
 * Validate reset token
 */
function validateResetToken($token, $user_id)
{
    if (
        !isset($_SESSION['reset_token']) ||
        !isset($_SESSION['reset_user_id']) ||
        !isset($_SESSION['reset_expires'])
    ) {
        return false;
    }

    if (!hash_equals($_SESSION['reset_token'], $token)) {
        return false;
    }

    if ($_SESSION['reset_user_id'] != $user_id) {
        return false;
    }

    if (strtotime($_SESSION['reset_expires']) < time()) {
        return false;
    }

    return true;
}

/**
 * Complete password reset
 */
function completePasswordReset($user_id, $token, $new_password)
{
    if (!validateResetToken($token, $user_id)) {
        return ['success' => false, 'message' => 'Invalid or expired reset token'];
    }

    $conn = getDBConnection();

    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $sql = "UPDATE users SET password = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_hash, $user_id);

    if ($stmt->execute()) {
        // Clear reset session
        unset($_SESSION['reset_token']);
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['reset_expires']);

        logActivity('password_reset_complete', 'Password reset completed', 'users', $user_id);

        return ['success' => true, 'message' => 'Password reset successful'];
    }

    return ['success' => false, 'message' => 'Failed to reset password'];
}
