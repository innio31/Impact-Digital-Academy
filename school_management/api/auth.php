<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$request_method = $_SERVER["REQUEST_METHOD"];
$path = isset($_GET['action']) ? $_GET['action'] : '';

switch ($request_method) {
    case 'POST':
        if ($path == 'login') {
            login();
        } elseif ($path == 'logout') {
            logout();
        } elseif ($path == 'change-password') {
            changePassword();
        } elseif ($path == 'forgot-password') {
            forgotPassword();
        } elseif ($path == 'reset-password') {
            resetPassword();
        }
        break;
    case 'GET':
        if ($path == 'profile') {
            getProfile();
        } elseif ($path == 'check-session') {
            checkSession();
        }
        break;
    case 'PUT':
        if ($path == 'update-profile') {
            updateProfile();
        }
        break;
}

function login()
{
    global $db;
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email) || !isset($data->password)) {
        sendResponse(false, "Email and password required");
        return;
    }

    $query = "SELECT id, email, password_hash, user_type, first_name, last_name, phone, is_active 
              FROM users WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $data->email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify password (in production, use password_verify)
        if ($data->password === $user['password_hash'] || password_verify($data->password, $user['password_hash'])) {
            if ($user['is_active'] != 1) {
                sendResponse(false, "Account is deactivated");
                return;
            }

            // Get additional data based on user type
            $extra_data = [];
            if ($user['user_type'] == 'staff') {
                $staff_query = "SELECT id, staff_number, qualification, specialization, class_assigned_id 
                               FROM staff WHERE user_id = :user_id";
                $staff_stmt = $db->prepare($staff_query);
                $staff_stmt->bindParam(':user_id', $user['id']);
                $staff_stmt->execute();
                $extra_data = $staff_stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($user['user_type'] == 'parent') {
                $parent_query = "SELECT id, occupation, address, relationship 
                               FROM parents WHERE user_id = :user_id";
                $parent_stmt = $db->prepare($parent_query);
                $parent_stmt->bindParam(':user_id', $user['id']);
                $parent_stmt->execute();
                $extra_data = $parent_stmt->fetch(PDO::FETCH_ASSOC);

                // Get linked students
                if ($extra_data) {
                    $students_query = "SELECT s.id, s.admission_number, s.first_name, s.last_name, s.class_id, 
                                              c.class_name
                                      FROM students s
                                      JOIN student_parents sp ON s.id = sp.student_id
                                      LEFT JOIN classes c ON s.class_id = c.id
                                      WHERE sp.parent_id = :parent_id AND s.is_active = 1";
                    $students_stmt = $db->prepare($students_query);
                    $students_stmt->bindParam(':parent_id', $extra_data['id']);
                    $students_stmt->execute();
                    $extra_data['children'] = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Get school info for staff and admin
            if ($user['user_type'] != 'parent') {
                $school_query = "SELECT id, school_name, school_code, subscription_status 
                               FROM schools WHERE id = 3"; // In production, get from staff record
                $school_stmt = $db->prepare($school_query);
                $school_stmt->execute();
                $extra_data['school'] = $school_stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Update last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $user['id']);
            $update_stmt->execute();

            // Create token (simplified - use JWT library in production)
            $token_payload = json_encode([
                'user_id' => $user['id'],
                'email' => $user['email'],
                'user_type' => $user['user_type'],
                'expires' => time() + (7 * 24 * 60 * 60) // 7 days
            ]);
            $token = base64_encode($token_payload);

            sendResponse(true, "Login successful", [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'phone' => $user['phone'],
                    'user_type' => $user['user_type'],
                    'extra_data' => $extra_data
                ]
            ]);
            return;
        }
    }

    sendResponse(false, "Invalid email or password");
}

function getProfile()
{
    global $db;
    $user = validateToken();

    $query = "SELECT id, email, user_type, first_name, last_name, phone, profile_image, created_at 
              FROM users WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $user['user_id']);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        sendResponse(true, "Profile retrieved", $profile);
    } else {
        sendResponse(false, "User not found");
    }
}

function updateProfile()
{
    global $db;
    $user = validateToken();
    $data = json_decode(file_get_contents("php://input"));

    $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, phone = :phone 
              WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':first_name', $data->first_name);
    $stmt->bindParam(':last_name', $data->last_name);
    $stmt->bindParam(':phone', $data->phone);
    $stmt->bindParam(':id', $user['user_id']);

    if ($stmt->execute()) {
        sendResponse(true, "Profile updated successfully");
    } else {
        sendResponse(false, "Failed to update profile");
    }
}

function changePassword()
{
    global $db;
    $user = validateToken();
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->current_password) || !isset($data->new_password)) {
        sendResponse(false, "Current and new password required");
        return;
    }

    // Verify current password
    $query = "SELECT password_hash FROM users WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $user['user_id']);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data->current_password === $result['password_hash']) {
        $update_query = "UPDATE users SET password_hash = :new_password WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':new_password', $data->new_password);
        $update_stmt->bindParam(':id', $user['user_id']);

        if ($update_stmt->execute()) {
            sendResponse(true, "Password changed successfully");
        } else {
            sendResponse(false, "Failed to change password");
        }
    } else {
        sendResponse(false, "Current password is incorrect");
    }
}

function forgotPassword()
{
    global $db;
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email)) {
        sendResponse(false, "Email required");
        return;
    }

    $query = "SELECT id, email FROM users WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $data->email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $insert_query = "INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':email', $data->email);
        $insert_stmt->bindParam(':token', $token);
        $insert_stmt->bindParam(':expires', $expires);
        $insert_stmt->execute();

        // In production, send email here
        sendResponse(true, "Password reset link sent to your email", ['reset_token' => $token]);
    } else {
        sendResponse(false, "Email not found");
    }
}

function resetPassword()
{
    global $db;
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->token) || !isset($data->new_password)) {
        sendResponse(false, "Token and new password required");
        return;
    }

    $query = "SELECT email FROM password_resets WHERE token = :token AND expires_at > NOW()";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $data->token);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        $update_query = "UPDATE users SET password_hash = :new_password WHERE email = :email";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':new_password', $data->new_password);
        $update_stmt->bindParam(':email', $reset['email']);
        $update_stmt->execute();

        // Delete used token
        $delete_query = "DELETE FROM password_resets WHERE token = :token";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':token', $data->token);
        $delete_stmt->execute();

        sendResponse(true, "Password reset successful");
    } else {
        sendResponse(false, "Invalid or expired token");
    }
}

function logout()
{
    sendResponse(true, "Logged out successfully");
}

function checkSession()
{
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        try {
            $user = validateToken();
            sendResponse(true, "Session valid", ['user_id' => $user['user_id']]);
        } catch (Exception $e) {
            sendResponse(false, "Invalid session");
        }
    } else {
        sendResponse(false, "No session");
    }
}
