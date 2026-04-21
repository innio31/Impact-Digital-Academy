<!-- backend/api/auth.php -->
<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($request_method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if ($action === 'login') {
        login($db, $data);
    } elseif ($action === 'logout') {
        logout($db, $data);
    } elseif ($action === 'change-password') {
        changePassword($db, $data);
    } elseif ($action === 'reset-password') {
        resetPassword($db, $data);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($request_method === 'GET' && $action === 'verify') {
    verifyToken();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

function login($db, $data)
{
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $device_token = $data['device_token'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Email and password required']);
        return;
    }

    // First check if user exists in users table
    $query = "SELECT u.* FROM users u WHERE u.email = :email AND u.is_active = 1";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (password_verify($password, $user['password_hash'])) {
            // Update last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $user['id']);
            $update_stmt->execute();

            // Store device token for push notifications if provided
            if ($device_token) {
                $token_query = "UPDATE users SET device_token = :token WHERE id = :id";
                $token_stmt = $db->prepare($token_query);
                $token_stmt->bindParam(':token', $device_token);
                $token_stmt->bindParam(':id', $user['id']);
                $token_stmt->execute();
            }

            // Generate JWT token
            $token_data = [
                'id' => $user['id'],
                'email' => $user['email'],
                'user_type' => $user['user_type'],
                'school_id' => $user['school_id'] ?? null
            ];
            $token = Auth::generateToken($token_data);

            // Get additional user data based on role
            $user_data = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'user_type' => $user['user_type'],
                'profile_image' => $user['profile_image']
            ];

            if ($user['user_type'] === 'staff') {
                $staff_query = "SELECT staff_number, qualification, specialization 
                               FROM staff WHERE user_id = :user_id";
                $staff_stmt = $db->prepare($staff_query);
                $staff_stmt->bindParam(':user_id', $user['id']);
                $staff_stmt->execute();
                if ($staff_stmt->rowCount() > 0) {
                    $staff_data = $staff_stmt->fetch(PDO::FETCH_ASSOC);
                    $user_data = array_merge($user_data, $staff_data);
                }
            } elseif ($user['user_type'] === 'parent') {
                $parent_query = "SELECT relationship, occupation FROM parents WHERE user_id = :user_id";
                $parent_stmt = $db->prepare($parent_query);
                $parent_stmt->bindParam(':user_id', $user['id']);
                $parent_stmt->execute();
                if ($parent_stmt->rowCount() > 0) {
                    $parent_data = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                    $user_data = array_merge($user_data, $parent_data);
                }
            }

            echo json_encode([
                'success' => true,
                'token' => $token,
                'user' => $user_data
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found or inactive']);
    }
}

function logout($db, $data)
{
    // Get token from headers
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $auth_header);

    if ($token) {
        // You could blacklist the token here if implementing token blacklisting
        // For now, just return success
        echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No token provided']);
    }
}

function changePassword($db, $data)
{
    // Authenticate user first
    $user_data = Auth::authenticate();

    $current_password = $data['current_password'] ?? '';
    $new_password = $data['new_password'] ?? '';

    if (empty($current_password) || empty($new_password)) {
        echo json_encode(['success' => false, 'error' => 'Current password and new password are required']);
        return;
    }

    if (strlen($new_password) < 6) {
        echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters']);
        return;
    }

    $query = "SELECT password_hash FROM users WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $user_data['user_id']);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($current_password, $user['password_hash'])) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = "UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id";
        $update_stmt = $db->prepare($update);
        $update_stmt->bindParam(':hash', $new_hash);
        $update_stmt->bindParam(':id', $user_data['user_id']);

        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update password']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
    }
}

function resetPassword($db, $data)
{
    $email = $data['email'] ?? '';

    if (empty($email)) {
        echo json_encode(['success' => false, 'error' => 'Email address is required']);
        return;
    }

    // Check if user exists
    $query = "SELECT id, first_name, last_name FROM users WHERE email = :email AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Generate reset token
        $reset_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store reset token in database (create password_resets table if not exists)
        $insert_query = "INSERT INTO password_resets (email, token, expires_at) 
                         VALUES (:email, :token, :expires_at)
                         ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':email', $email);
        $insert_stmt->bindParam(':token', $reset_token);
        $insert_stmt->bindParam(':expires_at', $expires_at);
        $insert_stmt->execute();

        // In a real application, you would send an email here
        // For development, we'll return the reset link
        $reset_link = "http://your-app-url/reset-password?token=" . $reset_token . "&email=" . urlencode($email);

        echo json_encode([
            'success' => true,
            'message' => 'Password reset instructions sent to your email',
            'reset_link' => $reset_link // Remove this in production
        ]);

        // TODO: Send email with reset link
        // mail($email, "Password Reset", "Click here to reset your password: " . $reset_link);

    } else {
        // For security, don't reveal if email exists or not
        echo json_encode([
            'success' => true,
            'message' => 'If the email exists in our system, you will receive reset instructions'
        ]);
    }
}

function verifyToken()
{
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $auth_header);

    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'No token provided']);
        return;
    }

    $user_data = Auth::validateToken($token);

    if ($user_data) {
        echo json_encode([
            'success' => true,
            'valid' => true,
            'user' => [
                'id' => $user_data['user_id'],
                'email' => $user_data['email'],
                'user_type' => $user_data['user_type']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'valid' => false, 'error' => 'Invalid or expired token']);
    }
}

// Note: The Auth class should be in config/database.php
// If not, add this at the top of the file or create a separate auth class file
?>