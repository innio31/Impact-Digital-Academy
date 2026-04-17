<?php
session_start();
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    // Check both hashed password and plain text (for compatibility)
    $loginSuccess = false;

    if ($admin) {
        // Check if password matches hashed version
        if (password_verify($password, $admin['password'])) {
            $loginSuccess = true;
        }
        // Check if plain text matches (for backward compatibility)
        elseif ($password === $admin['password']) {
            $loginSuccess = true;
            // Optional: Update to hashed version for security
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashed, $admin['id']]);
        }
    }

    if ($loginSuccess) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        // Store full name - use existing or fallback to username
        $_SESSION['admin_fullname'] = !empty($admin['full_name']) ? $admin['full_name'] : $admin['username'];
        header('Location: admin.php');
        exit();
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Faith Tabernacle Security</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            border-top: 5px solid #cc0000;
        }

        h2 {
            color: #cc0000;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .input-group {
            position: relative;
            margin: 1rem 0;
        }

        .input-group input {
            width: 100%;
            padding: 12px;
            padding-right: 45px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .input-group input:focus {
            outline: none;
            border-color: #cc0000;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 1.2rem;
            color: #666;
            background: none;
            border: none;
            padding: 0;
        }

        .toggle-password:hover {
            color: #cc0000;
        }

        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: #cc0000;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 1rem;
            transition: background 0.3s;
        }

        button[type="submit"]:hover {
            background: #990000;
        }

        .error {
            color: red;
            text-align: center;
            margin-top: 1rem;
            padding: 8px;
            background: #ffe0e0;
            border-radius: 4px;
        }

        .info {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.8rem;
            color: #666;
            background: #f5f5f5;
            padding: 8px;
            border-radius: 4px;
        }

        .logo {
            text-align: center;
            margin-bottom: 1rem;
        }

        .logo-icon {
            background: #cc0000;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }

        .logo-icon span {
            font-size: 2rem;
            color: white;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="logo">
            <div class="logo-icon">
                <span>⚔️</span>
            </div>
        </div>
        <h2>Admin Login</h2>
        <form method="POST">
            <div class="input-group">
                <input type="text" name="username" placeholder="Username" required autocomplete="off">
            </div>
            <div class="input-group">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="toggle-password" onclick="togglePassword()">
                    👁️
                </button>
            </div>
            <button type="submit">Login</button>
        </form>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-password');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.innerHTML = '🙈';
            } else {
                passwordInput.type = 'password';
                toggleBtn.innerHTML = '👁️';
            }
        }
    </script>
</body>

</html>