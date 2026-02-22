<?php
// modules/auth/login.php

// Start session
session_start();

// Get error from session if exists
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if (isset($_SESSION['login_email'])) {
    $email = $_SESSION['login_email'];
    unset($_SESSION['login_email']);
}

// Include configuration
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if already logged in
if (isLoggedIn()) {
    redirectToDashboard();
}

// Initialize variables
$email = '';
$error = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid form submission. Please try again.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    // Get and sanitize inputs
    $email = sanitize($_POST['email']);
    $password = $_POST['password']; // Don't sanitize password

    // Validate inputs
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Attempt login
        $login_result = loginUser($email, $password);

        if ($login_result['success']) {
            // Store email in session for convenience
            $_SESSION['login_email'] = $email;

            // Send login notification email (non-blocking - don't wait for result)
            // We'll log the user's ID from the login result
            if (isset($login_result['user_id'])) {
                sendLoginNotificationEmail($login_result['user_id']);
            } else {
                // If user_id not in login result, get it from session
                startSession();
                if (isset($_SESSION['user_id'])) {
                    sendLoginNotificationEmail($_SESSION['user_id']);
                }
            }

            // Redirect based on role
            redirectToDashboard();
        }
    }

    // If there's an error, store it in session and redirect back
    if ($error) {
        $_SESSION['login_error'] = $error;
        $_SESSION['login_email'] = $email;
        redirect(BASE_URL . 'index.php#login');
    }
}

// If we get here, show login form
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../../images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --light: #f8fafc;
            --dark: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-box {
            background: white;
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .logo-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        h1 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .subtitle {
            color: #6c757d;
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Password field wrapper */
        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1rem;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .password-wrapper input {
            padding-right: 40px;
        }

        .btn-primary {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .links {
            margin-top: 1.5rem;
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .links a {
            color: var(--primary);
            text-decoration: none;
            display: block;
            margin-bottom: 0.5rem;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .back-home {
            text-align: center;
            margin-top: 1rem;
        }

        .back-home a {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-home a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-box">
            <div class="logo-header">
                <div class="logo-header">
                    <div class="logo">
                        <img src="<?php echo BASE_URL; ?>public/images/logo_official.jpg" alt="Impact Digital Academy" style="height: 60px;">
                    </div>
                    <h1>Portal Login</h1>
                    <p class="subtitle">Sign in to access your dashboard</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert" style="background-color: #d1fae5; color: #065f46; border-color: #a7f3d0;">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                            placeholder="Enter your email" required
                            value="<?php echo htmlspecialchars($email); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-control"
                                placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </form>

                <div class="links">
                    <a href="<?php echo BASE_URL; ?>modules/auth/forgot_password.php">
                        <i class="fas fa-key"></i> Forgot Password?
                    </a>
                    <a href="<?php echo BASE_URL; ?>index.php#apply">
                        <i class="fas fa-user-plus"></i> Don't have an account? Apply Now
                    </a>
                </div>
            </div>

            <div class="back-home">
                <a href="<?php echo BASE_URL; ?>">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>

        <script>
            // Focus on email field
            document.getElementById('email')?.focus();

            // Clear form on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            // Password visibility toggle
            document.addEventListener('DOMContentLoaded', function() {
                const togglePassword = document.getElementById('togglePassword');
                const passwordInput = document.getElementById('password');
                const passwordIcon = togglePassword.querySelector('i');

                togglePassword.addEventListener('click', function() {
                    // Toggle the type attribute
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);

                    // Toggle the eye icon
                    if (type === 'text') {
                        passwordIcon.classList.remove('fa-eye');
                        passwordIcon.classList.add('fa-eye-slash');
                        togglePassword.setAttribute('aria-label', 'Hide password');
                    } else {
                        passwordIcon.classList.remove('fa-eye-slash');
                        passwordIcon.classList.add('fa-eye');
                        togglePassword.setAttribute('aria-label', 'Show password');
                    }

                    // Focus back on password field after toggle
                    passwordInput.focus();
                });

                // Add keyboard support for accessibility
                togglePassword.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        togglePassword.click();
                    }
                });
            });
        </script>
</body>

</html>