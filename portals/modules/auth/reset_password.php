<?php
// modules/auth/reset_password.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirectToDashboard();
}

// Get token from query parameters only (no user_id needed)
$token = $_GET['token'] ?? '';

// Check if token is valid (we need to get email from token first)
$is_valid_token = false;
$token_email = '';

if ($token) {
    // Get email associated with this token
    $conn = getDBConnection();
    $sql = "SELECT email FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $token_email = $row['email'];

        // Now validate the token with the email
        // We need to get user_id from email for the validation function
        $user_sql = "SELECT id FROM users WHERE email = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("s", $token_email);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();

        if ($user_row = $user_result->fetch_assoc()) {
            $user_id = $user_row['id'];
            $is_valid_token = validateResetToken($token, $user_id);
        }
    }
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
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $submitted_token = $_POST['token'] ?? '';
        $submitted_email = $_POST['email'] ?? '';

        // Validate required fields
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please enter and confirm your new password.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Get user_id from email
            $conn = getDBConnection();
            $user_sql = "SELECT id FROM users WHERE email = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("s", $submitted_email);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();

            if ($user_row = $user_result->fetch_assoc()) {
                $submitted_user_id = $user_row['id'];
                $result = completePasswordReset($submitted_user_id, $submitted_token, $new_password);

                if ($result['success']) {
                    $success = true;
                    $message = $result['message'];

                    // Redirect to login page after a delay
                    header("Refresh: 5; url=" . BASE_URL . "modules/auth/login.php");
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = 'User not found.';
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
    <title>Reset Password - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../../images/favicon.ico">

    <style>
        /* Your existing styles remain exactly the same */
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

        .reset-container {
            max-width: 500px;
            width: 100%;
        }

        .reset-header {
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

        .reset-header h1 {
            color: var(--dark);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .reset-header p {
            color: #64748b;
            font-size: 1.1rem;
        }

        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }

        .reset-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--accent), var(--success));
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

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
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

        .password-strength {
            margin-top: 0.5rem;
        }

        .strength-bar {
            height: 5px;
            background: #e2e8f0;
            border-radius: 3px;
            margin-bottom: 0.5rem;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            background: var(--danger);
        }

        .strength-fill.weak {
            width: 33%;
            background: var(--danger);
        }

        .strength-fill.medium {
            width: 66%;
            background: var(--accent);
        }

        .strength-fill.strong {
            width: 100%;
            background: var(--success);
        }

        .strength-text {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            justify-content: space-between;
        }

        .requirements {
            background: #f8fafc;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0 8px 8px 0;
            font-size: 0.9rem;
            color: #475569;
        }

        .requirements ul {
            margin-left: 1.5rem;
            margin-top: 0.5rem;
        }

        .requirements li {
            margin-bottom: 0.25rem;
        }

        .requirements li.valid {
            color: var(--success);
        }

        .requirements li.invalid {
            color: var(--danger);
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
            .reset-card {
                padding: 2rem;
            }

            .reset-header h1 {
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
    <div class="reset-container">
        <div class="reset-header">
            <div class="logo">
                <div class="logo-icon">IDA</div>
                <span>Impact Digital Academy</span>
            </div>
            <h1>Create New Password</h1>
            <p>Enter and confirm your new password</p>
        </div>

        <div class="reset-card">
            <?php if (!$is_valid_token && !$success): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Invalid or Expired Link</strong>
                    <p style="margin-top: 0.5rem;">This password reset link is invalid or has expired. Please request a new password reset link.</p>
                </div>

                <div class="back-link">
                    <a href="<?php echo BASE_URL; ?>modules/auth/forgot_password.php">
                        <i class="fas fa-redo"></i> Request New Reset Link
                    </a>
                </div>

                <div class="back-link">
                    <a href="<?php echo BASE_URL; ?>modules/auth/login.php">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            <?php elseif ($success): ?>
                <div class="success-message">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Password Reset Successful!</h3>
                    <p><?php echo htmlspecialchars($message); ?></p>
                    <p>You will be redirected to the login page in 5 seconds.</p>

                    <div class="alert alert-info">
                        <strong>What to do next:</strong>
                        <ul>
                            <li>Use your new password to log in</li>
                            <li>Consider updating your password regularly</li>
                            <li>Enable two-factor authentication if available</li>
                        </ul>
                    </div>

                    <a href="<?php echo BASE_URL; ?>modules/auth/login.php" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt"></i> Go to Login Now
                    </a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="requirements">
                    <strong>Password Requirements:</strong>
                    <ul>
                        <li id="req-length" class="invalid">At least 8 characters</li>
                        <li id="req-uppercase" class="invalid">One uppercase letter</li>
                        <li id="req-lowercase" class="invalid">One lowercase letter</li>
                        <li id="req-number" class="invalid">One number</li>
                        <li id="req-special" class="invalid">One special character</li>
                    </ul>
                </div>

                <form method="POST" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($token_email); ?>">

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control"
                            required minlength="8"
                            placeholder="Enter new password">

                        <div class="password-strength">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strength-fill"></div>
                            </div>
                            <div class="strength-text">
                                <span>Password Strength:</span>
                                <span id="strength-text">Weak</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                            required
                            placeholder="Confirm new password">
                        <div class="error-message" id="confirm-error" style="display: none;">
                            <i class="fas fa-exclamation-circle"></i> Passwords do not match
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Reset Password
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
        // Password strength checker
        const passwordInput = document.getElementById('new_password');
        const confirmInput = document.getElementById('confirm_password');
        const strengthFill = document.getElementById('strength-fill');
        const strengthText = document.getElementById('strength-text');

        // Password requirements elements
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqSpecial = document.getElementById('req-special');

        function checkPasswordStrength(password) {
            let score = 0;

            // Length requirement
            if (password.length >= 8) {
                score++;
                reqLength.classList.remove('invalid');
                reqLength.classList.add('valid');
            } else {
                reqLength.classList.remove('valid');
                reqLength.classList.add('invalid');
            }

            // Uppercase requirement
            if (/[A-Z]/.test(password)) {
                score++;
                reqUppercase.classList.remove('invalid');
                reqUppercase.classList.add('valid');
            } else {
                reqUppercase.classList.remove('valid');
                reqUppercase.classList.add('invalid');
            }

            // Lowercase requirement
            if (/[a-z]/.test(password)) {
                score++;
                reqLowercase.classList.remove('invalid');
                reqLowercase.classList.add('valid');
            } else {
                reqLowercase.classList.remove('valid');
                reqLowercase.classList.add('invalid');
            }

            // Number requirement
            if (/\d/.test(password)) {
                score++;
                reqNumber.classList.remove('invalid');
                reqNumber.classList.add('valid');
            } else {
                reqNumber.classList.remove('valid');
                reqNumber.classList.add('invalid');
            }

            // Special character requirement
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
                score++;
                reqSpecial.classList.remove('invalid');
                reqSpecial.classList.add('valid');
            } else {
                reqSpecial.classList.remove('valid');
                reqSpecial.classList.add('invalid');
            }

            // Update strength indicator
            switch (score) {
                case 0:
                case 1:
                case 2:
                    strengthFill.className = 'strength-fill weak';
                    strengthText.textContent = 'Weak';
                    break;
                case 3:
                case 4:
                    strengthFill.className = 'strength-fill medium';
                    strengthText.textContent = 'Medium';
                    break;
                case 5:
                    strengthFill.className = 'strength-fill strong';
                    strengthText.textContent = 'Strong';
                    break;
            }

            return score;
        }

        passwordInput.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            validatePasswordMatch();
        });

        function validatePasswordMatch() {
            const password = passwordInput.value;
            const confirm = confirmInput.value;
            const confirmError = document.getElementById('confirm-error');

            if (confirm && password !== confirm) {
                confirmInput.classList.add('invalid');
                confirmError.style.display = 'flex';
                return false;
            } else {
                confirmInput.classList.remove('invalid');
                confirmError.style.display = 'none';
                return true;
            }
        }

        confirmInput.addEventListener('input', validatePasswordMatch);

        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const confirm = confirmInput.value;

            // Check password strength
            const strength = checkPasswordStrength(password);

            if (strength < 3) {
                e.preventDefault();
                alert('Please use a stronger password. Your password should meet at least 4 of the requirements.');
                return;
            }

            // Check password match
            if (!validatePasswordMatch()) {
                e.preventDefault();
                return;
            }

            // Additional validation
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return;
            }
        });

        // Initialize strength check
        checkPasswordStrength(passwordInput.value);
    </script>
</body>

</html>