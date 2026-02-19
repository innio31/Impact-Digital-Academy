<?php
// includes/config.php

// Prevent duplicate definitions
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $host = str_replace('www.', '', $host);
    
    // Use the document root instead of script path
    $script_name = dirname($_SERVER['SCRIPT_NAME']);
    
    // For InfinityFree, try without the script path
    define('BASE_URL', $protocol . $host . '/');
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/../');
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . 'public/');
}

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', PUBLIC_PATH . 'uploads/');
}

if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . 'public/assets/');
}

// Database Configuration - InfinityFree settings
if (!defined('DB_HOST')) {
    // InfinityFree database configuration
    define('DB_HOST', 'sql113.infinityfree.com'); // Your InfinityFree MySQL host
    define('DB_NAME', 'if0_40714435_impact_digital_academy'); // Your database name
    define('DB_USER', 'if0_40714435'); // Your database username
    define('DB_PASS', 'Impact2026'); // Your database password
}

// SMTP Email Configuration - For Google Workspace/Email
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com'); // or your email host
    define('SMTP_PORT', 587);
    define('SMTP_USERNAME', 'admin@impactdigitalacademy.com.ng'); // Your Google Workspace email
    define('SMTP_PASSWORD', 'fnth kudu rdys uodn'); // Use App Password, not regular password
    define('SMTP_FROM_EMAIL', 'noreply@impactdigitalacademy.com.ng');
    define('SMTP_FROM_NAME', 'Impact Digital Academy');
    define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'
    define('SMTP_DEBUG', 0); // 0 = off, 1 = client messages, 2 = client and server messages
}

// Session Configuration
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800); // 30 minutes
}

if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'IDA_PORTAL_SESSION');
}

// File Upload Configuration
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 5242880); // 5MB
}

if (!defined('ALLOWED_IMAGE_TYPES')) {
    define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
}

if (!defined('ALLOWED_DOC_TYPES')) {
    define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx']);
}

if (!defined('ALLOWED_VIDEO_TYPES')) {
    define('ALLOWED_VIDEO_TYPES', ['mp4', 'mov', 'avi', 'wmv']);
}

// Security - UPDATE THIS KEY!
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'your-secure-key-here-change-this-for-production');
}

if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'ida_csrf_token');
}

// IMPORTANT: Set your actual InfinityFree domain here
// Check what $_SERVER['HTTP_HOST'] shows when you access your site
if (!defined('ALLOWED_HOSTS')) {
    // Common InfinityFree domains - ADD YOUR ACTUAL DOMAIN HERE
    define('ALLOWED_HOSTS', [
        'impactdigitalacademy.epizy.com',  // Change this to your actual domain
        'www.impactdigitalacademy.epizy.com',
        // If you have a custom domain, add it here too
        // 'yourcustomdomain.com',
        // 'www.yourcustomdomain.com',
        // For local development:
        'localhost',
        '127.0.0.1'
    ]);
}

// FOR DEBUGGING: Uncomment these lines temporarily to see your actual host
// die("Current host: " . $_SERVER['HTTP_HOST']);

// Academic Settings
if (!defined('DEFAULT_TIMEZONE')) {
    define('DEFAULT_TIMEZONE', 'Africa/Lagos');
}

if (!defined('ACADEMIC_YEAR')) {
    define('ACADEMIC_YEAR', '2024/2025');
}

if (!defined('MAX_STUDENTS_PER_CLASS')) {
    define('MAX_STUDENTS_PER_CLASS', 30);
}

// Debug Mode - Set to true temporarily to debug the host issue
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', true); // Set to true temporarily, then false for production
}

// Error Reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set default timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// MODIFIED HOST VALIDATION - More flexible for debugging
if (!defined('SKIP_HOST_VALIDATION')) {
    define('SKIP_HOST_VALIDATION', false); // Set to true temporarily if needed
}

// Only validate host if SKIP_HOST_VALIDATION is false
if (!SKIP_HOST_VALIDATION && !DEBUG_MODE) {
    // In production, validate the host
    if (!in_array($_SERVER['HTTP_HOST'], ALLOWED_HOSTS)) {
        // For debugging, show what host we're getting
        if (DEBUG_MODE) {
            die('Invalid host access. Your host is: ' . $_SERVER['HTTP_HOST'] . 
                '<br>Allowed hosts are: ' . implode(', ', ALLOWED_HOSTS));
        } else {
            die('Invalid host access.');
        }
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Include other core files
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

// Optional: Add a function to check and update allowed hosts dynamically
function validateCurrentHost() {
    $currentHost = $_SERVER['HTTP_HOST'];
    $allowedHosts = defined('ALLOWED_HOSTS') ? ALLOWED_HOSTS : [];
    
    // Remove www. prefix for comparison
    $cleanHost = str_replace('www.', '', $currentHost);
    $cleanAllowedHosts = array_map(function($host) {
        return str_replace('www.', '', $host);
    }, $allowedHosts);
    
    return in_array($cleanHost, $cleanAllowedHosts);
}
?>