<?php
// modules/auth/impersonate.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$conn = getDBConnection();

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Invalid token');
}

// Validate token
$token_sql = "SELECT it.*, u.* 
              FROM impersonation_tokens it
              JOIN users u ON it.user_id = u.id
              WHERE it.token = ? AND it.is_used = 0 AND it.expires_at > NOW()";
$token_stmt = $conn->prepare($token_sql);
$token_stmt->bind_param('s', $token);
$token_stmt->execute();
$result = $token_stmt->get_result();
$token_data = $result->fetch_assoc();

if (!$token_data) {
    die('Invalid or expired token');
}

// Store current admin session (if not already stored)
if (!isset($_SESSION['original_user_id'])) {
    $_SESSION['original_user_id'] = $_SESSION['user_id'] ?? null;
    $_SESSION['original_user_role'] = $_SESSION['user_role'] ?? null;
    $_SESSION['original_user_name'] = $_SESSION['user_name'] ?? null;
}

// Switch to user session
$_SESSION['user_id'] = $token_data['user_id'];
$_SESSION['user_role'] = $token_data['role'];
$_SESSION['user_name'] = $token_data['first_name'] . ' ' . $token_data['last_name'];

// Mark token as used
$update_sql = "UPDATE impersonation_tokens SET is_used = 1, used_at = NOW() WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param('i', $token_data['id']);
$update_stmt->execute();

// Log impersonation
logActivity(
    $_SESSION['original_user_id'],
    'user_impersonation_start',
    "Started impersonating user #{$token_data['user_id']}",
    'users',
    $token_data['user_id']
);

// Generate redirect URL based on role
if ($token_data['role'] === 'admin') {
    $redirect_url = BASE_URL . 'modules/admin/dashboard.php';
} elseif ($token_data['role'] === 'instructor') {
    $redirect_url = BASE_URL . 'modules/instructor/dashboard.php';
} elseif ($token_data['role'] === 'student') {
    $redirect_url = BASE_URL . 'modules/student/dashboard.php';
} else {
    $redirect_url = BASE_URL . 'index.php';
}

// Close connection
$conn->close();

// Instead of redirecting, show a page that auto-redirects and can be opened in a new tab
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impersonating User - <?php echo htmlspecialchars($token_data['first_name'] . ' ' . $token_data['last_name']); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }

        .container {
            background: white;
            border-radius: 15px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
        }

        h1 {
            color: #333;
            margin-bottom: 1rem;
        }

        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 2rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5a67d8;
        }

        .info {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            text-align: left;
        }

        .info p {
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }

        .alert {
            background: #feebc8;
            color: #9c4221;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Impersonating User</h1>

        <div class="info">
            <p><strong>User:</strong> <?php echo htmlspecialchars($token_data['first_name'] . ' ' . $token_data['last_name']); ?></p>
            <p><strong>Role:</strong> <?php echo ucfirst($token_data['role']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($token_data['email']); ?></p>
        </div>

        <div class="alert">
            <strong>Note:</strong> You are now logged in as this user. Your admin session is preserved.
        </div>

        <div class="spinner"></div>

        <p>Redirecting to <?php echo ucfirst($token_data['role']); ?> Dashboard...</p>

        <a href="<?php echo $redirect_url; ?>" class="btn">Go to Dashboard Now</a>
    </div>

    <script>
        // Auto-redirect after 3 seconds
        setTimeout(function() {
            window.location.href = "<?php echo $redirect_url; ?>";
        }, 3000);
    </script>
</body>

</html>