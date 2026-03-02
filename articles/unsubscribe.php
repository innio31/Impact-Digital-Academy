<?php
// unsubscribe.php - Allow subscribers to opt out
require_once 'config.php';

$email = isset($_GET['email']) ? filter_var($_GET['email'], FILTER_SANITIZE_EMAIL) : '';
$unsubscribed = false;
$error = '';

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        $stmt = $db->prepare("UPDATE subscribers SET status = 'unsubscribed' WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $unsubscribed = true;
        } else {
            $error = "Email not found in our subscribers list.";
        }
    } catch (Exception $e) {
        $error = "An error occurred. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe - Impact Digital</title>
    <style>
        body {
            background: #f0ebe2;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            max-width: 500px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #008080;
        }

        .success {
            color: #155724;
            background: #d4edda;
            padding: 15px;
            border-radius: 10px;
        }

        .error {
            color: #721c24;
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
        }

        .btn {
            display: inline-block;
            background: #008080;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <div class="card">
        <?php if ($unsubscribed): ?>
            <div class="success">
                <h2>✅ Successfully Unsubscribed</h2>
                <p>You have been removed from our email list. You will no longer receive notifications about new articles.</p>
            </div>
        <?php elseif ($error): ?>
            <div class="error">
                <h2>❌ Error</h2>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php else: ?>
            <h1>Unsubscribe</h1>
            <p>To unsubscribe from our newsletter, please provide your email address:</p>
            <form method="GET">
                <input type="email" name="email" placeholder="Your email address" required style="width: 100%; padding: 10px; margin: 20px 0; border: 2px solid #e0d9cc; border-radius: 5px;">
                <button type="submit" class="btn">Unsubscribe</button>
            </form>
        <?php endif; ?>

        <p style="margin-top: 30px;"><a href="https://impactdigitalacademy.com.ng" style="color: #666;">← Back to Home</a></p>
    </div>
</body>

</html>