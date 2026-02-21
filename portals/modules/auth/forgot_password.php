<?php
// modules/auth/forgot-password.php
require_once '../../includes/config.php';

// Initialize variables
$message = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Impact Digital Academy</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../../images/favicon.ico">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .card-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .card-header p {
            font-size: 16px;
            opacity: 0.9;
        }

        .card-body {
            padding: 40px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .info-text {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        input[type="email"] {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        input[type="email"]::placeholder {
            color: #aaa;
            font-size: 14px;
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 15px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.1);
        }

        .btn-outline:active {
            transform: translateY(0);
        }

        .footer-links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .footer-links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .security-note {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 12px;
        }

        .security-note i {
            margin-right: 5px;
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

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 576px) {
            .card-body {
                padding: 25px;
            }

            .card-header {
                padding: 25px;
            }

            .card-header h1 {
                font-size: 24px;
            }

            input[type="email"] {
                padding: 12px 12px 12px 40px;
                font-size: 15px;
            }

            .btn {
                padding: 12px;
            }
        }
    </style>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1><i class="fas fa-lock" style="margin-right: 10px;"></i>Forgot Password?</h1>
                <p>Enter your email to reset your password</p>
            </div>

            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="info-text">
                    <i class="fas fa-info-circle" style="margin-right: 8px; color: #667eea;"></i>
                    We'll send a password reset link to your email address. The link will expire in 2 hours for security reasons.
                </div>

                <form method="POST" action="" id="resetForm">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope" style="margin-right: 5px; color: #667eea;"></i>
                            Email Address
                        </label>
                        <div class="input-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                placeholder="Enter your email address"
                                required
                                autofocus
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane" style="margin-right: 8px;"></i>
                        Send Reset Link
                    </button>

                    <a href="<?php echo BASE_URL; ?>modules/auth/login.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>
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
        </div>
    </div>

    <script>
        // Simple form submission - FIXED VERSION
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const email = document.getElementById('email').value.trim();

            // Only show loading if email is not empty
            if (email) {
                // Change button text and add loading class
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