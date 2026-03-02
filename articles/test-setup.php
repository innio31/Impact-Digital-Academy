<?php
// test-setup.php - Run this to verify your configuration
require_once 'config.php';

echo "<!DOCTYPE html><html><head><title>Setup Test</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;} .pass{color:green;} .fail{color:red;} pre{background:#fff;padding:10px;border-radius:5px;}</style>";
echo "</head><body>";
echo "<h1>Impact Digital - Setup Test</h1>";

// Test 1: Constants
echo "<h2>1. Checking Constants</h2>";
$constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'SMTP_HOST', 'SMTP_USER', 'SITE_URL'];
foreach ($constants as $const) {
    if (defined($const)) {
        echo "✓ $const is defined<br>";
    } else {
        echo "✗ $const is NOT defined<br>";
    }
}

// Test 2: Database Connection
echo "<h2>2. Testing Database Connection</h2>";
try {
    $db = new Database();
    $conn = $db->getConnection();
    if ($conn) {
        echo "✓ Database connection successful<br>";

        // Check tables
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables found: " . implode(', ', $tables) . "<br>";
    } else {
        echo "✗ Database connection failed<br>";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
}

// Test 3: PHPMailer
echo "<h2>3. Checking PHPMailer</h2>";
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "✓ PHPMailer loaded successfully<br>";

    // Test mailer setup
    $mailer = new MailSender();
    if (empty($mailer->getError())) {
        echo "✓ Mailer configured successfully<br>";
    } else {
        echo "✗ Mailer error: " . $mailer->getError() . "<br>";
    }
} else {
    echo "✗ PHPMailer not found. Check paths:<br>";
    $paths = [
        __DIR__ . '/phpmailer/src/PHPMailer.php',
        __DIR__ . '/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/libs/phpmailer/src/PHPMailer.php'
    ];
    foreach ($paths as $path) {
        echo "  " . ($path) . ": " . (file_exists($path) ? "✓ exists" : "✗ missing") . "<br>";
    }
}

// Test 4: File Permissions
echo "<h2>4. Checking File Permissions</h2>";
$files = ['config.php', 'subscribe.php', 'comments.php', 'education-inside-you.html'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✓ $file exists (permissions: " . substr(sprintf('%o', fileperms($file)), -4) . ")<br>";
    } else {
        echo "✗ $file missing<br>";
    }
}

echo "<h2>5. Current Configuration Values</h2>";
echo "<pre>";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "SMTP_HOST: " . SMTP_HOST . "\n";
echo "SMTP_PORT: " . SMTP_PORT . "\n";
echo "SMTP_USER: " . SMTP_USER . "\n";
echo "SMTP_FROM: " . SMTP_FROM . "\n";
echo "SITE_URL: " . SITE_URL . "\n";
echo "</pre>";

echo "</body></html>";
