<?php
// publish_article.php - Fixed version
session_start();
require_once 'config.php';
require_once 'notify-subscribers.php';

// Simple authentication (you should implement proper admin authentication)
$is_authenticated = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // CHANGE THESE TO YOUR ADMIN CREDENTIALS
    if ($username === 'admin' && $password === 'innioluwa') {
        $_SESSION['admin_logged_in'] = true;
        $is_authenticated = true;
    } else {
        $error = "Invalid credentials";
    }
}

if (isset($_POST['logout'])) {
    $_SESSION['admin_logged_in'] = false;
    $is_authenticated = false;
    header('Location: publish_article.php');
    exit;
}

// Handle article publishing
$notification_result = null;
if ($is_authenticated && isset($_POST['publish'])) {
    $title = $_POST['title'] ?? '';
    $url = $_POST['url'] ?? '';
    $excerpt = $_POST['excerpt'] ?? '';
    $notify = isset($_POST['send_notifications']);
    $test_mode = isset($_POST['test_mode']);
    $test_email = $_POST['test_email'] ?? '';

    if ($notify && !empty($title) && !empty($url)) {
        $notifier = new ArticleNotifier();
        $notification_result = $notifier->notifyNewArticle($title, $url, $excerpt, $test_mode, $test_email);
    }
}

// Get notification stats
$stats = null;
if ($is_authenticated) {
    $notifier = new ArticleNotifier();
    $stats = $notifier->getStats(30);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publish Article - Impact Digital</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0ebe2;
            font-family: 'Segoe UI', Arial, sans-serif;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #008080;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #1d2b32;
        }

        input[type="text"],
        input[type="url"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0d9cc;
            border-radius: 10px;
            font-size: 16px;
        }

        textarea {
            height: 150px;
        }

        .checkbox-group {
            background: #f9f7f3;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .btn {
            background: #008080;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 10px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-secondary {
            background: #f59e0b;
        }

        .btn-test {
            background: #6c757d;
        }

        .btn-analytics {
            background: #4a6da8;
        }

        .result {
            margin-top: 30px;
            padding: 20px;
            border-radius: 10px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .result.error {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .stats {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .login-form {
            max-width: 400px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 20px;
        }

        .nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .stat-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #008080;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>

<body>

    <?php if (!$is_authenticated): ?>
        <!-- Login Form -->
        <div class="login-form">
            <h2 style="color: #008080; margin-bottom: 20px;">Admin Login</h2>
            <?php if (isset($error)): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" name="login" class="btn">Login</button>
            </form>
        </div>
    <?php else: ?>
        <!-- Admin Dashboard -->
        <div class="container">
            <div class="nav">
                <h1>📝 Publish New Article</h1>
                <div>
                    <a href="email-analytics.php" class="btn btn-analytics" style="margin-right: 10px;">
                        <i class="fas fa-chart-line"></i> Analytics
                    </a>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="logout" class="btn btn-secondary">Logout</button>
                    </form>
                </div>
            </div>

            <!-- Statistics -->
            <?php if ($stats): ?>
                <div class="stats">
                    <h3 style="color: #008080; margin-bottom: 15px;">📊 Last 30 Days Overview</h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?= number_format($stats['total_subscribers'] ?? 0) ?></div>
                            <div class="stat-label">Active Subscribers</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= number_format($stats['successful'] ?? 0) ?></div>
                            <div class="stat-label">Emails Sent</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= number_format($stats['failed'] ?? 0) ?></div>
                            <div class="stat-label">Failed</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Publish Form -->
            <form method="POST">
                <div class="form-group">
                    <label>Article Title *</label>
                    <input type="text" name="title" required placeholder="e.g., The Classroom Is Not a Prison">
                </div>

                <div class="form-group">
                    <label>Article URL *</label>
                    <input type="url" name="url" required placeholder="https://impactdigitalacademy.com.ng/articles/classroom-not-prison.html">
                </div>

                <div class="form-group">
                    <label>Article Excerpt (first paragraph or summary)</label>
                    <textarea name="excerpt" placeholder="Enter a brief excerpt from the article..."></textarea>
                </div>

                <div class="checkbox-group">
                    <h3 style="margin-bottom: 15px; color: #008080;">📧 Email Notifications</h3>

                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="send_notifications" value="1" checked>
                            <span>Send notifications to all active subscribers</span>
                        </label>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="test_mode" value="1" id="testMode">
                            <span>Test mode (send only to specific email)</span>
                        </label>
                    </div>

                    <div id="testEmailField" style="display: none;">
                        <label>Test Email Address</label>
                        <input type="email" name="test_email" placeholder="Enter test email">
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" name="publish" class="btn">📢 Publish & Send</button>
                    <button type="submit" name="publish" class="btn btn-test" onclick="this.form.test_mode.value='1'; return true;">🔬 Test Mode</button>
                </div>
            </form>

            <!-- Results -->
            <?php if ($notification_result): ?>
                <div class="result <?= !empty($notification_result['errors']) ? 'error' : '' ?>">
                    <h3>Notification Results:</h3>
                    <p><?= htmlspecialchars($notification_result['message'] ?? '') ?></p>
                    <?php if (isset($notification_result['campaign_id'])): ?>
                        <p>Campaign ID: <code><?= htmlspecialchars($notification_result['campaign_id']) ?></code></p>
                    <?php endif; ?>
                    <p>📨 Sent: <?= $notification_result['emails_sent'] ?? 0 ?> / <?= $notification_result['total_subscribers'] ?? 0 ?></p>
                    <?php if (($notification_result['emails_failed'] ?? 0) > 0): ?>
                        <p>❌ Failed: <?= $notification_result['emails_failed'] ?></p>
                    <?php endif; ?>
                    <?php if (!empty($notification_result['errors'])): ?>
                        <div style="margin-top: 10px; max-height: 200px; overflow-y: auto;">
                            <strong>Errors:</strong>
                            <ul style="margin-left: 20px;">
                                <?php foreach (array_slice($notification_result['errors'], 0, 5) as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($notification_result['errors']) > 5): ?>
                                    <li>... and <?= count($notification_result['errors']) - 5 ?> more errors</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($notification_result['campaign_id'])): ?>
                        <div style="margin-top: 15px;">
                            <a href="email-analytics.php?campaign=<?= urlencode($notification_result['campaign_id']) ?>" class="btn btn-analytics" style="background: #4a6da8;">
                                <i class="fas fa-chart-line"></i> View Campaign Analytics
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Quick Tips -->
            <div style="margin-top: 30px; padding: 20px; background: #f0f0f0; border-radius: 10px;">
                <h4 style="color: #008080;">💡 Quick Tips</h4>
                <ul style="margin-left: 20px;">
                    <li>Always test with your own email first using Test Mode</li>
                    <li>Make sure the article URL is correct before publishing</li>
                    <li>Write a compelling excerpt to increase open rates</li>
                    <li>Best time to send: Tuesday-Thursday, 9am-11am</li>
                    <li>Check <a href="email-analytics.php">Analytics Dashboard</a> to monitor open rates</li>
                </ul>
            </div>
        </div>

        <script>
            // Show/hide test email field
            document.getElementById('testMode')?.addEventListener('change', function() {
                document.getElementById('testEmailField').style.display = this.checked ? 'block' : 'none';
            });
        </script>
    <?php endif; ?>
</body>

</html>