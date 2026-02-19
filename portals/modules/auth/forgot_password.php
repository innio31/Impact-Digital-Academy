<?php
// modules/auth/forgot_password.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirectToDashboard();
}

// Handle form submission
$message = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!isValidEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            require_once __DIR__ . '/../../includes/auth.php';
            $result = requestPasswordReset($email);
            
            if ($result['success']) {
                $success = true;
                $message = $result['message'];
                
                // Store email for display
                $_SESSION['reset_email'] = $email;
                
                // In production, you would email the reset link
                // For now, we'll show a demo message with the token
                if (DEBUG_MODE) {
                    $message .= "<br><small><strong>Development Mode:</strong> Reset token: " . 
                                htmlspecialchars($result['token']) . "</small>";
                }
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .forgot-container {
            max-width: 500px;
            width: 100%;
        }

        .forgot-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: var(--dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
        }

        .forgot-header h1 {
            color: var(--dark);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .forgot-header p {
            color: #64748b;
            font-size: 1.1rem;
        }

        .forgot-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }

        .forgot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
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
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-control.invalid {
            border-color: var(--danger);
        }

        .error-message {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .instructions {
            background: #f8fafc;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0 8px 8px 0;
        }

        .instructions p {
            margin-bottom: 0.5rem;
            color: #475569;
        }

        .instructions ul {
            margin-left: 1.5rem;
            color: #64748b;
        }

        .instructions li {
            margin-bottom: 0.25rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.2);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            margin-top: 1rem;
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .success-message {
            text-align: center;
            padding: 2rem;
        }

        .success-icon {
            font-size: 4rem;
            color: var(--success);
            margin-bottom: 1.5rem;
        }

        .success-message h3 {
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .success-message p {
            color: #64748b;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .back-link {
            text-align: center;
            margin-top: 2rem;
            color: #64748b;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .forgot-card {
                padding: 2rem;
            }

            .forgot-header h1 {
                font-size: 1.8rem;
            }

            .logo {
                font-size: 1.5rem;
            }
            
            .logo-icon {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-header">
            <div class="logo">
                <div class="logo-icon">IDA</div>
                <span>Impact Digital Academy</span>
            </div>
            <h1>Reset Your Password</h1>
            <p>Enter your email to receive password reset instructions</p>
        </div>

        <div class="forgot-card">
            <?php if ($success): ?>
                <div class="success-message">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Check Your Email</h3>
                    <p><?php echo $message; ?></p>
                    
                    <div class="alert alert-info">
                        <strong>Email Sent To:</strong> 
                        <?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?><br><br>
                        <strong>What to do next:</strong>
                        <ul>
                            <li>Check your email inbox</li>
                            <li>Click the password reset link</li>
                            <li>Create a new password</li>
                        </ul>
                    </div>
                    
                    <a href="<?php echo BASE_URL; ?>modules/auth/login.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="instructions">
                    <p><strong>Instructions:</strong></p>
                    <ul>
                        <li>Enter the email address associated with your account</li>
                        <li>We'll send you a password reset link</li>
                        <li>The link will expire in 1 hour</li>
                    </ul>
                </div>

                <form method="POST" id="forgotForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               required
                               placeholder="your.email@example.com">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Reset Instructions
                    </button>
                </form>

                <div class="back-link">
                    <a href="<?php echo BASE_URL; ?>modules/auth/login.php">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Form validation
        document.getElementById('forgotForm')?.addEventListener('submit', function(e) {
            const emailField = document.getElementById('email');
            const email = emailField.value.trim();
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (!email) {
                e.preventDefault();
                emailField.classList.add('invalid');
                showError(emailField, 'Please enter your email address');
                return;
            }
            
            if (!emailPattern.test(email)) {
                e.preventDefault();
                emailField.classList.add('invalid');
                showError(emailField, 'Please enter a valid email address');
                return;
            }
            
            // Clear any existing errors
            emailField.classList.remove('invalid');
            clearError(emailField);
        });
        
        function showError(element, message) {
            let errorDiv = element.nextElementSibling;
            if (!errorDiv || !errorDiv.classList.contains('error-message')) {
                errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                element.parentNode.appendChild(errorDiv);
            }
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        }
        
        function clearError(element) {
            const errorDiv = element.nextElementSibling;
            if (errorDiv && errorDiv.classList.contains('error-message')) {
                errorDiv.remove();
            }
        }
        
        // Clear error when user starts typing
        document.getElementById('email')?.addEventListener('input', function() {
            this.classList.remove('invalid');
            clearError(this);
        });
    </script>
</body>
</html>