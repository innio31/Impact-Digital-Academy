<?php
// test-email-config.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

echo "<h2>Testing Email Configuration</h2>";

// Test configuration constants
$config_checks = [
    'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : 'NOT SET',
    'SMTP_PORT' => defined('SMTP_PORT') ? SMTP_PORT : 'NOT SET',
    'SMTP_USERNAME' => defined('SMTP_USERNAME') ? 'SET (hidden)' : 'NOT SET',
    'SMTP_FROM_EMAIL' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'NOT SET',
    'SMTP_FROM_NAME' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NOT SET',
];

echo "<h3>Configuration Check:</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";
foreach ($config_checks as $key => $value) {
    $status = $value !== 'NOT SET' ? '✅' : '❌';
    echo "<tr><td>{$key}</td><td>{$value}</td><td>{$status}</td></tr>";
}
echo "</table>";

// Test PHPMailer installation
echo "<h3>PHPMailer Check:</h3>";
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "✅ PHPMailer is installed";
} else {
    echo "❌ PHPMailer is NOT installed";
    echo "<p>Install with: <code>composer require phpmailer/phpmailer</code></p>";
}
?>