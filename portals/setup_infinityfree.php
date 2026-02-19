<?php
// setup-infinityfree.php
echo "Impact Digital Academy - InfinityFree Setup\n";
echo "===========================================\n\n";

// Get configuration from user
echo "Enter your InfinityFree details:\n";

echo "impactdigitalacademy.xo.je";
$domain = trim(fgets(STDIN));

echo "Your vPanel Password: Impact2026";
$dbPass = trim(fgets(STDIN));

// Generate app key
$appKey = 'base64:' . base64_encode(random_bytes(32));

// Create .env content for InfinityFree
$envContent = <<<ENV
# Impact Digital Academy - InfinityFree Configuration
# Generated on {$date}

APP_ENV=production
APP_DEBUG=false
APP_URL=https://{$domain}
APP_NAME="Impact Digital Academy"
APP_KEY={$appKey}
APP_TIMEZONE=Africa/Lagos

DB_HOST=sql113.infinityfree.com
DB_PORT=3306
DB_DATABASE=if0_40714435_impact_digital_academy
DB_USERNAME=if0_40714435
DB_PASSWORD={$dbPass}
DB_CHARSET=utf8mb4

CSRF_ENABLED=true
XSS_PROTECTION=true

SESSION_DRIVER=file
SESSION_LIFETIME=120

# Disable email for now (InfinityFree restrictions)
MAIL_DRIVER=log
EMAIL_VERIFICATION=false

REGISTRATION_OPEN=true
MAINTENANCE_MODE=false

UPLOAD_MAX_SIZE=10M
MAX_LOGIN_ATTEMPTS=5
ENV;

// Write .env file
file_put_contents(__DIR__ . '/.env', $envContent);

echo "\n✅ .env file created successfully!\n";
echo "📁 File saved at: " . __DIR__ . "/.env\n";
echo "🔑 App Key: {$appKey}\n\n";

echo "Next steps:\n";
echo "1. Upload the database SQL file to phpMyAdmin\n";
echo "2. Access your site: https://{$domain}\n";
echo "3. Login with: admin@impactacademy.edu / Admin@123\n";
?>