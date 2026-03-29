<?php
// config/config.php - Global configuration for MyResultChecker Portal

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database configuration
require_once __DIR__ . '/database.php';

// Application Settings
define('APP_NAME', 'MyResultChecker');
define('APP_URL', 'https://impactdigitalacademy.com.ng/result-checker'); // Change to your actual domain
define('APP_ENV', 'production'); // 'development' or 'production'

// Security Settings
define('CSRF_PROTECTION', true);
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes in seconds

// PIN Settings
define('PIN_LENGTH', 12);
define('PIN_MAX_USES', 3);
define('PIN_FORMAT_REGEX', '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

// Result Settings
define('MAX_RESULTS_PER_PAGE', 50);
define('RESULT_CACHE_TIME', 3600); // Cache results for 1 hour

// File Upload Settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// Pagination
define('ITEMS_PER_PAGE', 20);

// Email Settings (configure for your SMTP)
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM_EMAIL', 'noreply@myresultchecker.com');
define('SMTP_FROM_NAME', 'MyResultChecker');

// Rate Limiting
define('RATE_LIMIT_REQUESTS', 10); // Max requests per minute
define('RATE_LIMIT_WINDOW', 60); // Window in seconds

// Error Reporting based on environment
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Helper Functions

/**
 * Get database connection (shortcut)
 */
function getDB()
{
    return Database::getInstance()->getConnection();
}

/**
 * Sanitize input data
 */
function sanitize($input)
{
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Redirect to URL
 */
function redirect($url)
{
    header("Location: " . $url);
    exit();
}

/**
 * Show JSON response
 */
function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Log activity
 */
function logActivity($user_id, $user_type, $activity, $details = null)
{
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $db->prepare("
            INSERT INTO portal_activity_logs (user_id, user_type, activity, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $user_type, $activity, $details, $ip, $user_agent]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * Generate unique API key
 */
function generateApiKey()
{
    return bin2hex(random_bytes(32));
}

/**
 * Generate unique school code
 */
function generateSchoolCode($schoolName)
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $schoolName), 0, 3));
    $unique = substr(md5(uniqid()), 0, 4);
    return $prefix . '-' . strtoupper($unique);
}

/**
 * Validate PIN format
 */
function validatePinFormat($pin)
{
    return preg_match(PIN_FORMAT_REGEX, $pin);
}

/**
 * Format PIN for display
 */
function formatPin($pin)
{
    $pin = strtoupper(preg_replace('/[^A-Z0-9]/', '', $pin));
    if (strlen($pin) >= 4) {
        $pin = substr($pin, 0, 4) . '-' . substr($pin, 4);
    }
    if (strlen($pin) >= 9) {
        $pin = substr($pin, 0, 9) . '-' . substr($pin, 9);
    }
    return $pin;
}

/**
 * Check if user is logged in as admin
 */
function isAdminLoggedIn()
{
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

/**
 * Require admin login
 */
function requireAdminLogin()
{
    if (!isAdminLoggedIn()) {
        redirect('/admin/login.php');
    }
}

/**
 * Get flash message
 */
function getFlashMessage()
{
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Set flash message
 */
function setFlashMessage($type, $message)
{
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}
