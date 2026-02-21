<?php
// modules/auth/forgot-password.php

// Start session
session_start();

// Include configuration
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Initialize variables
$message = '';
$error = '';
$email = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid form submission. Please try again.';
        redirect(BASE_URL . 'modules/auth/forgot_password.php');
    }

    $email = sanitize($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $conn = getDBConnection();

        // Check if user exists and is active
        $sql = "SELECT id, first_name, email FROM users WHERE email = ? AND status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+2 hours'));

            // Save token to database
            $sql = "INSERT INTO password_resets (email, token, expires_at, created_at) 
                    VALUES (?, ?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE 
                    token = VALUES(token), 
                    expires_at = VALUES(expires_at),
                    used = 0,
                    created_at = NOW()";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $email, $token, $expires_at);

            if ($stmt->execute()) {
                // Send reset email
                $reset_url = BASE_URL . "modules/auth/reset_password.php?token=" . urlencode($token);

                $subject = "Password Reset Request - Impact Digital Academy";

                // HTML email template
                $body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
                        .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                        .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                        .warning { color: #dc2626; font-weight: bold; }
                        .reset-link { background: #f1f5f9; padding: 15px; border-radius: 5px; word-break: break-all; font-family: monospace; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1 style='margin: 0;'>Password Reset Request</h1>
                        </div>
                        
                        <div class='content'>
                            <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                            
                            <p>We received a request to reset your password for your Impact Digital Academy account. Click the button below to reset it:</p>
                            
                            <p style='text-align: center; margin: 30px 0;'>
                                <a href='{$reset_url}' class='button'>Reset Your Password</a>
                            </p>
                            
                            <p>If the button doesn't work, copy and paste this link into your browser:</p>
                            <div class='reset-link'>{$reset_url}</div>
                            
                            <p class='warning' style='margin-top: 20px;'>⚠ This link will expire in 2 hours.</p>
                            
                            <p>If you didn't request this password reset, please ignore this email.</p>
                        </div>
                        
                        <div class='footer'>
                            <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                            <p>This is an automated message, please do not reply.</p>
                        </div>
                    </div>
                </body>
                </html>";

                if (sendEmail($email, $subject, $body)) {
                    $message = "Password reset link has been sent to your email address.";
                } else {
                    $error = "Failed to send email. Please try again later.";
                }
            } else {
                $error = "An error occurred. Please try again.";
            }
        } else {
            // Don't reveal if email exists or not for security
            $message = "If your email exists in our system, you will receive a reset link.";
        }
        $stmt->close();
    }

    // If there's an error, store it in session and redirect back
    if ($error) {
        $_SESSION['forgot_error'] = $error;
        $_SESSION['forgot_email'] = $email;
        redirect(BASE_URL . 'modules/auth/forgot_password.php');
    }

    // If there's a message, store it in session and redirect back
    if ($message) {
        $_SESSION['forgot_success'] = $message;
        redirect(BASE_URL . 'modules/auth/forgot_password.php');
    }
}

// Get messages from session if they exist
if (isset($_SESSION['forgot_success'])) {
    $message = $_SESSION['forgot_success'];
    unset($_SESSION['forgot_success']);
}

if (isset($_SESSION['forgot_error'])) {
    $error = $_SESSION['forgot_error'];
    unset($_SESSION['forgot_error']);
}

if (isset($_SESSION['forgot_email'])) {
    $email = $_SESSION['forgot_email'];
    unset($_SESSION['forgot_email']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Impact Digital Academy</title>
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

        .container {
            width: 100%;
            max-width: 400px;
        }

        .card {
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

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .info-text {
            background-color: #f8fafc;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            color: var(--dark);
            font-size: 0.95rem;
            line-height: 1.6;
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

        .btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            margin-bottom: 1rem;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: #f8fafc;
            transform: translateY(-2px);
        }

        .footer-links {
            margin-top: 1.5rem;
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .security-note {
            margin-top: 1.5rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
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

        /* Loading state */
        .btn-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .btn-loading:after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 2px solid white;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
        }

        .btn-outline.btn-loading:after {
            border: 2px solid var(--primary);
            border-top-color: transparent;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 576px) {
            .card {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="logo-header">
                <div class="logo">
                    <img src="<?php echo BASE_URL; ?>public/images/logo_official.jpg" alt="Impact Digital Academy" style="height: 60px;">
                </div>
                <h1>Forgot Password?</h1>
                <p class="subtitle">Enter your email to reset your password</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="info-text">
                <i class="fas fa-info-circle" style="color: var(--primary);"></i>
                We'll send a password reset link to your email address. The link will expire in 2 hours for security reasons.
            </div>

            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope" style="margin-right: 5px; color: var(--primary);"></i>
                        Email Address
                    </label>
                    <input type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="Enter your email address"
                        required
                        autofocus
                        value="<?php echo htmlspecialchars($email); ?>">
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-paper-plane"></i>
                    Send Reset Link
                </button>

                <a href="<?php echo BASE_URL; ?>modules/auth/login.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Login
                </a>
            </form>

            <div class="footer-links">
                <a href="<?php echo BASE_URL; ?>">
                    <i class="fas fa-home"></i> Return to Homepage
                </a>
            </div>

            <div class="security-note">
                <i class="fas fa-shield-alt"></i>
                This is a secure area. Your information is protected.
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

        // Form submission with loading state
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const email = document.getElementById('email').value.trim();

            // Only show loading if email is not empty
            if (email) {
                submitBtn.innerHTML = 'Sending...';
                submitBtn.classList.add('btn-loading');
                // Don't prevent default - let the form submit normally
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);
    </script>
</body>

</html>