<?php
// admin/login.php - Admin login page (FIXED)
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit();
}

// Include configuration
require_once '../config/config.php';

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            $db = getDB();

            // Check login attempts
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM admin_login_attempts 
                WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND success = 0
            ");
            $stmt->execute([$ip]);
            $attempts = $stmt->fetchColumn();

            if ($attempts >= 5) {
                $error = 'Too many failed attempts. Please try again after 15 minutes.';
            } else {
                // Get admin user
                $stmt = $db->prepare("
                    SELECT id, username, password, full_name, role, status 
                    FROM portal_admins 
                    WHERE username = ?
                ");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                // Debug: Check if admin exists
                if (!$admin) {
                    error_log("Login failed: Username '$username' not found");
                    $error = 'Invalid username or password';
                }
                // Check if account is active
                elseif ($admin['status'] !== 'active') {
                    $error = 'Your account is inactive. Please contact support.';
                }
                // Verify password
                else {
                    // Check if password needs rehashing (for old MySQL PASSWORD() hashes)
                    $password_valid = false;

                    // Try PHP password_verify first
                    if (password_verify($password, $admin['password'])) {
                        $password_valid = true;
                    }
                    // Fallback for MySQL PASSWORD() hash (if any old records exist)
                    elseif (function_exists('mysql_old_password_check')) {
                        // This is a fallback - ideally all passwords should be hashed with password_hash()
                        $password_valid = false;
                    }

                    if ($password_valid) {
                        // Login successful
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_name'] = $admin['full_name'];
                        $_SESSION['admin_role'] = $admin['role'];

                        // Log successful login
                        $stmt = $db->prepare("
                            INSERT INTO admin_login_attempts (username, success, ip_address, user_agent)
                            VALUES (?, 1, ?, ?)
                        ");
                        $stmt->execute([$username, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '']);

                        // Update last login time
                        $stmt = $db->prepare("
                            UPDATE portal_admins SET last_login = NOW() WHERE id = ?
                        ");
                        $stmt->execute([$admin['id']]);

                        header("Location: index.php");
                        exit();
                    } else {
                        // Log failed attempt
                        $stmt = $db->prepare("
                            INSERT INTO admin_login_attempts (username, success, ip_address, user_agent)
                            VALUES (?, 0, ?, ?)
                        ");
                        $stmt->execute([$username, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '']);

                        error_log("Login failed: Password verification failed for user '$username'");
                        $error = 'Invalid username or password';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#2c3e50">
    <title>Admin Login - MyResultChecker</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') repeat-x bottom;
            background-size: cover;
            pointer-events: none;
            z-index: 0;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .logo-icon i {
            font-size: 40px;
            color: white;
        }

        .logo-section h1 {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .logo-section p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            padding: 35px 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h2 {
            color: #2c3e50;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #7f8c8d;
            font-size: 0.85rem;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            color: #3498db;
            font-size: 18px;
            z-index: 1;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid #ecf0f1;
            border-radius: 14px;
            font-size: 15px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .input-wrapper input::placeholder {
            color: #bdc3c7;
            font-size: 14px;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            background: none;
            border: none;
            cursor: pointer;
            color: #7f8c8d;
            font-size: 18px;
            padding: 5px;
            z-index: 1;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #3498db;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            color: #555;
        }

        .checkbox-label input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #3498db;
        }

        .forgot-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s ease;
        }

        .forgot-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            animation: slideDown 0.3s ease;
        }

        .alert i {
            font-size: 18px;
        }

        .alert-error {
            background: #fef2f2;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .alert-success {
            background: #e8f8f5;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }

        .login-footer a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s ease;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .back-link {
            text-align: center;
            margin-top: 25px;
        }

        .back-link a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .back-link a:hover {
            color: white;
            transform: translateX(-3px);
        }

        /* Debug info (only visible in development) */
        .debug-info {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 12px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 11px;
            font-family: monospace;
            display: none;
        }

        @media (max-width: 480px) {
            body {
                padding: 15px;
            }

            .login-card {
                padding: 25px 20px;
            }

            .logo-icon {
                width: 65px;
                height: 65px;
            }

            .logo-icon i {
                font-size: 32px;
            }

            .logo-section h1 {
                font-size: 1.5rem;
            }

            .login-header h2 {
                font-size: 1.3rem;
            }

            .input-wrapper input {
                padding: 14px 14px 14px 45px;
                font-size: 14px;
            }

            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
            }

            .login-btn {
                padding: 14px;
                font-size: 15px;
            }
        }

        @media (max-width: 360px) {
            .login-card {
                padding: 20px 16px;
            }

            .alert {
                padding: 12px 14px;
                font-size: 0.8rem;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .login-btn {
                min-height: 52px;
            }

            .input-wrapper input {
                min-height: 52px;
            }

            .password-toggle {
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        .login-btn.loading {
            opacity: 0.7;
            cursor: wait;
        }

        .login-btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h1>MyResultChecker</h1>
            <p>Admin Portal</p>
        </div>

        <div class="login-card">
            <div class="login-header">
                <h2>Welcome Back!</h2>
                <p>Please login to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text"
                            id="username"
                            name="username"
                            placeholder="Username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            autocomplete="username"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password"
                            id="password"
                            name="password"
                            placeholder="Password"
                            autocomplete="current-password"
                            required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="remember-forgot">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </button>
            </form>

            <div class="login-footer">
                <a href="../index.php">
                    <i class="fas fa-arrow-left"></i> Back to Result Checker
                </a>
            </div>
        </div>

        <div class="back-link">
            <a href="../index.php">
                <i class="fas fa-home"></i> Return to Home
            </a>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const remember = document.getElementById('remember').checked;
            const username = document.getElementById('username').value;

            if (remember) {
                localStorage.setItem('remembered_username', username);
            } else {
                localStorage.removeItem('remembered_username');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const rememberedUsername = localStorage.getItem('remembered_username');
            if (rememberedUsername) {
                document.getElementById('username').value = rememberedUsername;
                document.getElementById('remember').checked = true;
            }

            if (!document.getElementById('username').value) {
                document.getElementById('username').focus();
            } else {
                document.getElementById('password').focus();
            }
        });

        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');

        loginForm.addEventListener('submit', function() {
            loginBtn.classList.add('loading');
            loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Logging in...</span>';
            loginBtn.disabled = true;
        });

        let submitted = false;
        loginForm.addEventListener('submit', function(e) {
            if (submitted) {
                e.preventDefault();
                return false;
            }
            submitted = true;
            return true;
        });

        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loginForm.dispatchEvent(new Event('submit'));
            }
        });

        const buttons = document.querySelectorAll('button, .login-btn');
        buttons.forEach(button => {
            button.addEventListener('touchstart', function() {
                this.style.opacity = '0.8';
            });
            button.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });
    </script>
</body>

</html>