<?php
require_once '../includes/functions.php';

// If already logged in, redirect to quiz
if (isStudentLoggedIn()) {
    header('Location: quiz.php');
    exit;
}

$error = '';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if (studentLogin($username, $password)) {
        // Update last activity
        $student_id = $_SESSION['student_id'];
        $conn->query("UPDATE students SET last_activity = NOW() WHERE id = $student_id");
        header('Location: quiz.php');
        exit;
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
    <title>Student Login - Quiz System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
        }

        h2 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
            font-size: 32px;
        }

        .input-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #fcc;
        }

        .demo-credentials {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 14px;
        }

        .demo-credentials h3 {
            color: #555;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .demo-credentials p {
            color: #666;
            margin-bottom: 5px;
        }

        .demo-credentials span {
            font-weight: bold;
            color: #667eea;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Student Login</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Enter your username">
            </div>

            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Enter your password">
            </div>

            <button type="submit" name="login">Login to Quiz</button>
        </form>

        <!-- div class="demo-credentials">
            <h3>📝 Demo Credentials:</h3>
            <p><span>student1</span> / pass123</p>
            <p><span>student2</span> / pass123</p>
            <p><span>student3</span> / pass123</p>
        </div> -->
    </div>
</body>

</html>