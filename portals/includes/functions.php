<?php
// includes/functions.php

// Load email functions from separate file
require_once __DIR__ . '/email_functions.php';

// ===== SESSION FUNCTIONS =====

/**
 * Start session if not already started
 */
function startSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn()
{
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require authentication
 */
function requireAuth()
{
    startSession();

    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        $_SESSION['error'] = 'Please login to access this page.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }
}

/**
 * Require admin authentication
 * Redirects non-admin users to login page
 */
function requireAdmin()
{
    startSession();

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        $_SESSION['error'] = 'Please login to access this page.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    // Check if user is admin
    if ($_SESSION['user_role'] !== 'admin') {
        $_SESSION['error'] = 'Access denied. Admin privileges required.';
        redirect(BASE_URL . 'index.php');
    }

    return true;
}

/**
 * Check if user has specific role
 */
function hasRole($role)
{
    startSession();
    if (!isLoggedIn()) return false;
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Require specific role
 */
function requireRole($role)
{
    startSession();

    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        $_SESSION['error'] = 'Please login to access this page.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    if (!hasRole($role)) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        redirect(BASE_URL . 'dashboard.php');
    }
}

/**
 * Check if current user is admin
 */
function isAdmin()
{
    return hasRole('admin');
}

/**
 * Check if current user is instructor
 */
function isInstructor()
{
    return hasRole('instructor');
}

/**
 * Check if current user is student
 */
function isStudent()
{
    return hasRole('student');
}

/**
 * Check if current user is applicant
 */
function isApplicant()
{
    return hasRole('applicant');
}

/**
 * Get current user info
 */
function getCurrentUser()
{
    startSession();

    if (!isLoggedIn()) {
        return null;
    }

    $user_id = $_SESSION['user_id'];
    return getUserById($user_id);
}

/**
 * Get user role
 */
function getUserRole()
{
    startSession();
    return $_SESSION['user_role'] ?? null;
}

/**
 * Check if current user is active (not suspended or pending)
 */
function isUserActive()
{
    $user = getCurrentUser();
    return $user && $user['status'] === 'active';
}

/**
 * Check session timeout
 */
function checkSessionTimeout()
{
    startSession();

    if (!isset($_SESSION['login_time'])) {
        return true; // No session started
    }

    if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
        logoutUser();
        $_SESSION['error'] = 'Session expired. Please login again.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    // Update last activity
    $_SESSION['login_time'] = time();
    return true;
}

// ===== DATABASE FUNCTIONS =====

/**
 * Database Connection with local fallback
 */
function getDBConnection()
{
    static $conn = null;

    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if ($conn->connect_error) {
                error_log("Database Connection Error: " . $conn->connect_error);

                // Try local fallback if remote fails (only if not already localhost)
                if (DB_HOST !== 'localhost' && DB_HOST !== '127.0.0.1') {
                    error_log("Attempting local database fallback...");
                    $conn = new mysqli('localhost', 'root', '', DB_NAME);

                    if ($conn->connect_error) {
                        throw new Exception("Both remote and local database connections failed.");
                    }

                    error_log("Using local database fallback");
                } else {
                    throw new Exception("Database connection failed: " . $conn->connect_error);
                }
            }

            $conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            error_log("Database Error: " . $e->getMessage());

            // Show development error
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                die("
                    <div style='padding: 2rem; background: #fee; border: 2px solid #f00; border-radius: 8px; margin: 2rem;'>
                        <h2>Database Connection Error</h2>
                        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                        <p><strong>Host:</strong> " . htmlspecialchars(DB_HOST) . "</p>
                        <p><strong>Database:</strong> " . htmlspecialchars(DB_NAME) . "</p>
                        <hr>
                        <h3>Troubleshooting:</h3>
                        <ol>
                            <li>Check if MySQL is running in XAMPP</li>
                            <li>Verify database name is '" . htmlspecialchars(DB_NAME) . "'</li>
                            <li>Check phpMyAdmin to confirm database exists</li>
                        </ol>
                    </div>
                ");
            } else {
                die("Unable to connect to database. Please try again later.");
            }
        }
    }

    return $conn;
}

/**
 * Fetch all rows from database
 */
function fetchAll($conn, $query, $params = [])
{
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Fetch single row from database
 */
function fetchSingle($conn, $query, $params = [])
{
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Escape SQL parameters (Use prepared statements instead when possible)
 * WARNING: This is not safe for preventing SQL injection on its own
 */
function escapeSQL($data)
{
    $conn = getDBConnection();
    return $conn->real_escape_string($data);
}

/**
 * Escape string for database query
 */
function db_escape($conn, $string)
{
    return mysqli_real_escape_string($conn, $string);
}

// ===== INPUT VALIDATION & SANITIZATION =====

/**
 * Sanitize input data for HTML display
 * Note: Use prepared statements for SQL, not this function
 */
function sanitize($data)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize($value);
        }
        return $data;
    }

    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize input data
 */
function sanitize_input($data)
{
    if ($data === null) {
        return '';
    }

    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

/**
 * Sanitize array of inputs
 */
function sanitize_array($data)
{
    if (!is_array($data)) {
        return sanitize_input($data);
    }

    $sanitized = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitize_array($value);
        } else {
            $sanitized[$key] = sanitize_input($value);
        }
    }
    return $sanitized;
}

/**
 * Clean input data - sanitize user input
 */
function clean_input($data)
{
    if (empty($data)) {
        return '';
    }

    // If it's an array, clean each element recursively
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = clean_input($value);
        }
        return $data;
    }

    // Trim whitespace
    $data = trim($data);

    // Remove slashes (for compatibility with old code that might add them)
    $data = stripslashes($data);

    // Convert special characters to HTML entities
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $data;
}

/**
 * Sanitize string for database (prevent SQL injection)
 */
function sanitize_string($conn, $string)
{
    if ($conn && !empty($string)) {
        return mysqli_real_escape_string($conn, $string);
    }
    return $string;
}

/**
 * Validate email format
 */
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate email address
 */
function validate_email($email)
{
    $email = trim($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    return false;
}

/**
 * Validate Nigerian phone number
 */
function validateNGPhone($phone)
{
    $phone = preg_replace('/\D/', '', $phone);

    // Check if it starts with 0 or 234
    if (preg_match('/^(0|234)[7-9][0-1][0-9]{8}$/', $phone)) {
        return $phone;
    }

    return false;
}

/**
 * Validate phone number (basic validation)
 */
function validate_phone($phone)
{
    $phone = trim($phone);
    // Remove all non-digit characters except + at start
    $clean_phone = preg_replace('/[^\d+]/', '', $phone);

    // Check if phone has at least 8 digits (minimum for international numbers)
    if (preg_match('/^\+?[0-9]{8,15}$/', $clean_phone)) {
        return $clean_phone;
    }
    return false;
}

/**
 * Validate URL
 */
function validate_url($url, $require_https = false)
{
    $url = trim($url);

    // Add http:// if no protocol specified
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
    }

    if (filter_var($url, FILTER_VALIDATE_URL)) {
        if ($require_https && !str_starts_with($url, 'https://')) {
            return false;
        }
        return $url;
    }
    return false;
}

/**
 * Validate and format date
 */
function validate_date($date, $format = 'Y-m-d')
{
    $date = trim($date);
    if (empty($date)) {
        return null;
    }

    $d = DateTime::createFromFormat($format, $date);
    if ($d && $d->format($format) === $date) {
        return $date;
    }
    return false;
}

/**
 * Check if string is JSON
 */
function is_json($string)
{
    if (!is_string($string)) {
        return false;
    }

    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

// ===== SECURITY FUNCTIONS =====

/**
 * Generate CSRF token
 */
function generateCSRFToken()
{
    startSession();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token)
{
    startSession();
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
        return false;
    }
    return true;
}

/**
 * Generate CSRF token (alternative)
 */
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token (alternative)
 */
function validate_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $token);
}

// ===== USER MANAGEMENT FUNCTIONS =====

/**
 * Get user by ID
 */
function getUserById($user_id)
{
    $conn = getDBConnection();
    $user_id = (int)$user_id;

    $sql = "SELECT u.*, up.* FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            WHERE u.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Generate random password
 */
function generateRandomPassword($length = 12)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $password;
}

/**
 * Generate random string
 */
function generate_random_string($length = 10)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $characters_length = strlen($characters);
    $random_string = '';

    for ($i = 0; $i < $length; $i++) {
        $random_string .= $characters[random_int(0, $characters_length - 1)];
    }

    return $random_string;
}

// ===== REDIRECTION & NAVIGATION FUNCTIONS =====

/**
 * Redirect to URL
 */
function redirect($url)
{
    if (!headers_sent()) {
        header("Location: " . $url);
        exit();
    } else {
        echo "<script>window.location.href='$url';</script>";
        exit();
    }
}

/**
 * Redirect to appropriate dashboard based on user role
 */
function redirectToDashboard()
{
    startSession();

    if (!isLoggedIn()) {
        return;
    }

    $role = $_SESSION['user_role'] ?? 'guest';
    $dashboard_url = '';

    switch ($role) {
        case 'admin':
            $dashboard_url = 'modules/admin/dashboard.php';
            break;
        case 'instructor':
            $dashboard_url = 'modules/instructor/dashboard.php';
            break;
        case 'student':
            $dashboard_url = 'modules/student/dashboard.php';
            break;
        case 'applicant':
            $dashboard_url = 'modules/applicant/dashboard.php';
            break;
        default:
            $dashboard_url = 'index.php';
    }

    redirect(BASE_URL . $dashboard_url);
}

// ===== FORMATTING FUNCTIONS =====

/**
 * Format date for display
 */
function formatDate($date, $format = 'Y-m-d H:i:s')
{
    if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00') return '';

    try {
        $dateTime = new DateTime($date);
        return $dateTime->format($format);
    } catch (Exception $e) {
        error_log("Date format error: " . $e->getMessage());
        return '';
    }
}

/**
 * Format date for display (alternative)
 */
function format_date($date_string, $format = 'F j, Y')
{
    if (empty($date_string)) {
        return 'Not set';
    }

    try {
        $date = new DateTime($date_string);
        return $date->format($format);
    } catch (Exception $e) {
        return $date_string;
    }
}

/**
 * Format time for display
 */
function format_time($time_string, $format = 'g:i a')
{
    if (empty($time_string)) {
        return 'Not set';
    }

    try {
        $time = new DateTime($time_string);
        return $time->format($format);
    } catch (Exception $e) {
        return $time_string;
    }
}

/**
 * Format currency
 */
function formatCurrency($amount)
{
    return '₦' . number_format($amount, 2);
}

/**
 * Format currency (alternative)
 */
function format_currency($amount, $currency = 'NGN')
{
    $formatted = number_format($amount, 2);

    switch ($currency) {
        case 'NGN':
            return '₦' . $formatted;
        case 'USD':
            return '$' . $formatted;
        case 'EUR':
            return '€' . $formatted;
        case 'GBP':
            return '£' . $formatted;
        default:
            return $formatted . ' ' . $currency;
    }
}

/**
 * Format phone number for display
 */
function formatPhone($phone)
{
    $phone = preg_replace('/\D/', '', $phone);

    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        return '(+234) ' . substr($phone, 1, 3) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7, 4);
    } elseif (strlen($phone) == 13 && substr($phone, 0, 3) == '234') {
        return '(+234) ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9, 4);
    }

    return $phone;
}

/**
 * Format file size
 */
function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Format time ago
 */
function time_ago($datetime)
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return $diff . ' second' . ($diff == 1 ? '' : 's') . ' ago';
    }

    $diff = floor($diff / 60);
    if ($diff < 60) {
        return $diff . ' minute' . ($diff == 1 ? '' : 's') . ' ago';
    }

    $diff = floor($diff / 60);
    if ($diff < 24) {
        return $diff . ' hour' . ($diff == 1 ? '' : 's') . ' ago';
    }

    $diff = floor($diff / 24);
    if ($diff < 7) {
        return $diff . ' day' . ($diff == 1 ? '' : 's') . ' ago';
    }

    $diff = floor($diff / 7);
    if ($diff < 4) {
        return $diff . ' week' . ($diff == 1 ? '' : 's') . ' ago';
    }

    return date('F j, Y', $time);
}

/**
 * Time ago format (alternative)
 */
function timeAgo($timestamp)
{
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

/**
 * Limit string length
 */
function str_limit($string, $limit = 100, $end = '...')
{
    if (mb_strlen($string) <= $limit) {
        return $string;
    }

    return mb_substr($string, 0, $limit) . $end;
}

/**
 * Truncate text to specified length
 */
function truncate_text($text, $length = 100, $suffix = '...')
{
    if (strlen($text) <= $length) {
        return $text;
    }

    $text = substr($text, 0, $length);
    $text = substr($text, 0, strrpos($text, ' '));
    return $text . $suffix;
}

// ===== LOGGING & NOTIFICATION FUNCTIONS =====

/**
 * Log activity
 */
function logActivity($action, $description = null, $table_name = null, $record_id = null)
{
    $conn = getDBConnection();

    $user_id = null;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }

    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $old_data = null;

    // Truncate table_name if it's too long (column is likely varchar(50))
    if ($table_name && strlen($table_name) > 50) {
        $table_name = substr($table_name, 0, 50);
    }

    $sql = "INSERT INTO activity_logs (user_id, user_ip, user_agent, action, description, table_name, record_id, old_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssssis", $user_id, $user_ip, $user_agent, $action, $description, $table_name, $record_id, $old_data);
    return $stmt->execute();
}

/**
 * Send notification
 */
function sendNotification($user_id, $title, $message, $type = 'system', $related_id = null)
{
    $conn = getDBConnection();

    $sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssi", $user_id, $title, $message, $type, $related_id);
    return $stmt->execute();
}

/**
 * Get unread notifications count
 */
function getUnreadNotificationsCount($user_id)
{
    $conn = getDBConnection();

    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['count'] ?? 0;
}

/**
 * Send assignment notification to students
 */
function sendAssignmentNotification($assignment_id, $conn)
{
    // Get assignment details
    $sql = "SELECT a.*, cb.batch_code, c.title as course_title, 
                   c.course_code, u.email as instructor_email,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM assignments a 
            JOIN class_batches cb ON a.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            JOIN users u ON cb.instructor_id = u.id 
            WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignment = $result->fetch_assoc();
    $stmt->close();

    if (!$assignment) return false;

    // Get enrolled students
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name 
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            WHERE e.class_id = ? AND e.status = 'active'
            AND u.email IS NOT NULL AND u.email != ''";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment['class_id']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $notification_count = 0;
    $email_count = 0;

    foreach ($students as $student) {
        // Create notification in database
        $sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                VALUES (?, ?, ?, 'assignment', ?, NOW())";

        $stmt = $conn->prepare($sql);

        $title = "New Assignment: " . $assignment['title'];
        $message = "A new assignment has been posted for " . $assignment['course_title'] .
            ". Due date: " . date('F j, Y', strtotime($assignment['due_date']));

        $stmt->bind_param("issi", $student['id'], $title, $message, $assignment_id);

        if ($stmt->execute()) {
            $notification_count++;
        }
        $stmt->close();
    }

    // Send email notifications (function moved to email_functions.php)
    if (count($students) > 0) {
        $email_count = sendAssignmentNotificationEmail($assignment_id, $conn, $assignment);
    }

    logActivity(
        'assignment_notified',
        "Sent assignment notification #{$assignment_id} to {$notification_count} students. " .
            "Emails sent: {$email_count}"
    );

    return $notification_count > 0;
}

/**
 * Update due date notifications
 */
function updateDueDateNotifications($assignment_id, $new_due_date, $conn)
{
    // Get assignment details
    $sql = "SELECT a.title, c.title as course_title 
        FROM assignments a 
        JOIN courses c ON a.course_id = c.id 
        WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignment = $result->fetch_assoc();
    $stmt->close();

    if (!$assignment) return false;

    // Get enrolled students
    $sql = "SELECT u.id FROM enrollments e 
        JOIN users u ON e.student_id = u.id 
        WHERE e.class_id = (SELECT class_id FROM assignments WHERE id = ?) 
        AND e.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $notification_count = 0;

    foreach ($students as $student) {
        // Create due date update notification
        $sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
            VALUES (?, ?, ?, 'announcement', ?, NOW())";

        $stmt = $conn->prepare($sql);

        $title = "Assignment Due Date Updated: " . $assignment['title'];
        $message = "The due date for " . $assignment['title'] . " has been updated to " .
            date('F j, Y g:i A', strtotime($new_due_date)) .
            ". Please check the assignment details.";

        $stmt->bind_param("issi", $student['id'], $title, $message, $assignment_id);

        if ($stmt->execute()) {
            $notification_count++;
        }
        $stmt->close();
    }

    logActivity('due_date_updated', "Updated due date notifications for assignment #{$assignment_id}", $assignment_id);

    return $notification_count > 0;
}

// Corrected function
function sendAnnouncementNotification($announcement_id, $conn)
{
    // Get announcement details (same as before)
    $sql = "SELECT a.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   cb.batch_code,
                   c.title as course_title
            FROM announcements a 
            JOIN users u ON u.id = a.author_id
            LEFT JOIN class_batches cb ON a.class_id = cb.id
            LEFT JOIN courses c ON cb.course_id = c.id
            WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $announcement = $result->fetch_assoc();
    $stmt->close();

    if (!$announcement) return false;

    // Determine who should receive the notification (same as before)
    $recipients = [];

    if ($announcement['class_id']) {
        $sql = "SELECT DISTINCT u.id 
                FROM class_batches cb
                JOIN users u ON u.id = cb.instructor_id
                WHERE cb.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $announcement['class_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['id'];
        }
        $stmt->close();
    } else {
        $sql = "SELECT id FROM users WHERE role = 'instructor' AND status = 'active'";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['id'];
        }
    }

    $notification_count = 0;
    foreach ($recipients as $recipient_id) {
        $title = "New Announcement: " . $announcement['title'];
        $message = $announcement['author_name'] . " posted a new announcement";
        if ($announcement['batch_code']) {
            $message .= " for " . $announcement['course_title'] . " (" . $announcement['batch_code'] . ")";
        }

        $sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                VALUES (?, ?, ?, 'announcement', ?, NOW())";

        $stmt = $conn->prepare($sql);
        // CORRECTED: Changed "isssi" to "issi" - only 4 parameters now
        $stmt->bind_param("issi", $recipient_id, $title, $message, $announcement_id);

        if ($stmt->execute()) {
            $notification_count++;
        }
        $stmt->close();
    }

    logActivity('announcement_notified', "Sent announcement notification #{$announcement_id} to {$notification_count} instructors");

    return $notification_count > 0;
}

// ===== DASHBOARD & STATISTICS FUNCTIONS =====

/**
 * Get comprehensive dashboard statistics
 */
function getDashboardStats()
{
    $conn = getDBConnection();

    $stats = [];

    // Total users
    $sql = "SELECT COUNT(*) as total FROM users WHERE status = 'active'";
    $result = $conn->query($sql);
    $stats['total_users'] = $result->fetch_assoc()['total'] ?? 0;

    // Active classes
    $sql = "SELECT COUNT(*) as total FROM class_batches WHERE status = 'ongoing'";
    $result = $conn->query($sql);
    $stats['active_classes'] = $result->fetch_assoc()['total'] ?? 0;

    // Pending applications
    $sql = "SELECT COUNT(*) as total FROM applications WHERE status = 'pending'";
    $result = $conn->query($sql);
    $stats['pending_applications'] = $result->fetch_assoc()['total'] ?? 0;

    // Total students
    $sql = "SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'";
    $result = $conn->query($sql);
    $stats['total_students'] = $result->fetch_assoc()['total'] ?? 0;

    // Total instructors
    $sql = "SELECT COUNT(*) as total FROM users WHERE role = 'instructor' AND status = 'active'";
    $result = $conn->query($sql);
    $stats['total_instructors'] = $result->fetch_assoc()['total'] ?? 0;

    // Today's enrollments
    $sql = "SELECT COUNT(*) as total FROM enrollments WHERE DATE(created_at) = CURDATE()";
    $result = $conn->query($sql);
    $stats['today_enrollments'] = $result->fetch_assoc()['total'] ?? 0;

    // Completion rate
    $sql = "SELECT 
                COUNT(*) as total_enrollments,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM enrollments";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $stats['completion_rate'] = ($row['total_enrollments'] > 0)
        ? round(($row['completed'] / $row['total_enrollments']) * 100, 1)
        : 0;

    return $stats;
}

/**
 * Get recent activities
 */
function getRecentActivities($limit = 10)
{
    $conn = getDBConnection();

    $sql = "SELECT al.*, u.first_name, u.last_name 
            FROM activity_logs al 
            LEFT JOIN users u ON u.id = al.user_id 
            ORDER BY al.created_at DESC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $activities = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['first_name']) {
            $row['user_name'] = $row['first_name'] . ' ' . $row['last_name'];
        } else {
            $row['user_name'] = 'System';
        }
        $activities[] = $row;
    }

    return $activities;
}

/**
 * Get upcoming classes
 */
function getUpcomingClasses($limit = 5)
{
    $conn = getDBConnection();

    $sql = "SELECT cb.*, c.title as course_title, 
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM class_batches cb
            JOIN courses c ON c.id = cb.course_id
            JOIN users u ON u.id = cb.instructor_id
            WHERE cb.start_date >= CURDATE() 
            AND cb.status = 'scheduled'
            ORDER BY cb.start_date ASC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get pending applications
 */
function getPendingApplications($limit = 5)
{
    $conn = getDBConnection();

    $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as applicant_name,
                   u.email, p.name as program_name
            FROM applications a
            JOIN users u ON u.id = a.user_id
            LEFT JOIN programs p ON p.id = a.program_id
            WHERE a.status = 'pending'
            ORDER BY a.created_at DESC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get unread notifications for user
 */
function getUnreadNotifications($user_id)
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM notifications 
            WHERE user_id = ? AND is_read = 0 
            ORDER BY created_at DESC 
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Check for system alerts
 */
function checkSystemAlerts()
{
    $conn = getDBConnection();
    $alerts = [];

    // Check for server disk space (mock)
    $free_space = disk_free_space("/");
    $total_space = disk_total_space("/");
    $percent_free = ($free_space / $total_space) * 100;

    if ($percent_free < 10) {
        $alerts[] = "Server disk space is low (" . round($percent_free, 1) . "% free)";
    }

    // Check for overdue payments
    $sql = "SELECT COUNT(*) as count FROM invoices 
            WHERE status = 'pending' AND due_date < CURDATE()";
    $result = $conn->query($sql);
    $overdue = $result->fetch_assoc()['count'] ?? 0;

    if ($overdue > 10) {
        $alerts[] = "High number of overdue payments: " . $overdue . " invoices";
    }

    // Check for suspended students
    $sql = "SELECT COUNT(*) as count FROM student_financial_status 
            WHERE is_suspended = 1";
    $result = $conn->query($sql);
    $suspended = $result->fetch_assoc()['count'] ?? 0;

    if ($suspended > 5) {
        $alerts[] = $suspended . " students are suspended due to payment issues";
    }

    return $alerts;
}

/**
 * Get enrollment trends
 */
function getEnrollmentTrends($period = 'month')
{
    $conn = getDBConnection();

    switch ($period) {
        case 'week':
            $interval = '7 DAY';
            $format = '%a'; // Day name
            break;
        case 'month':
            $interval = '30 DAY';
            $format = '%b %e'; // Month Day
            break;
        case 'year':
            $interval = '365 DAY';
            $format = '%b %Y'; // Month Year
            break;
        default:
            $interval = '30 DAY';
            $format = '%b %e';
    }

    $sql = "SELECT 
                DATE_FORMAT(created_at, ?) as label,
                COUNT(*) as count
            FROM enrollments 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
            GROUP BY DATE_FORMAT(created_at, ?)
            ORDER BY created_at";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $format, $format);
    $stmt->execute();
    $result = $stmt->get_result();

    $trends = ['labels' => [], 'data' => []];
    while ($row = $result->fetch_assoc()) {
        $trends['labels'][] = $row['label'];
        $trends['data'][] = $row['count'];
    }

    return $trends;
}

/**
 * Get revenue trends
 */
function getRevenueTrends($period = 'month')
{
    $conn = getDBConnection();

    switch ($period) {
        case 'week':
            $interval = '7 DAY';
            $format = '%a';
            break;
        case 'month':
            $interval = '30 DAY';
            $format = '%b %e';
            break;
        case 'year':
            $interval = '365 DAY';
            $format = '%b %Y';
            break;
        default:
            $interval = '30 DAY';
            $format = '%b %e';
    }

    $sql = "SELECT 
                DATE_FORMAT(ft.created_at, ?) as label,
                SUM(ft.amount) as total
            FROM financial_transactions ft
            WHERE ft.status = 'completed'
            AND ft.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
            GROUP BY DATE_FORMAT(ft.created_at, ?)
            ORDER BY ft.created_at";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $format, $format);
    $stmt->execute();
    $result = $stmt->get_result();

    $trends = ['labels' => [], 'data' => []];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $trends['labels'][] = $row['label'];
        $trends['data'][] = $row['total'];
        $total += $row['total'];
    }

    // Calculate percent change from previous period
    $previous_sql = "SELECT SUM(amount) as total 
                     FROM financial_transactions 
                     WHERE status = 'completed' 
                     AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL " . ($interval == '30 DAY' ? '60' : ($interval == '7 DAY' ? '14' : '730')) . " DAY) 
                     AND DATE_SUB(NOW(), INTERVAL $interval)";

    $prev_result = $conn->query($previous_sql);
    $prev_total = $prev_result->fetch_assoc()['total'] ?? 0;

    if ($prev_total > 0) {
        $trends['percent_change'] = round((($total - $prev_total) / $prev_total) * 100, 1);
    } else {
        $trends['percent_change'] = $total > 0 ? 100 : 0;
    }

    return $trends;
}

/**
 * Get overdue invoices
 */
function getOverdueInvoices($limit = 10)
{
    $conn = getDBConnection();

    $sql = "SELECT i.*, u.first_name, u.last_name,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM invoices i
            JOIN users u ON u.id = i.student_id
            WHERE i.status = 'pending'
            AND i.due_date < CURDATE()
            ORDER BY i.due_date ASC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

// ===== PERMISSION & ACCESS CONTROL FUNCTIONS =====

/**
 * Check if user can access module
 */
function canAccessModule($module)
{
    $role = getUserRole();

    // Define module access rules
    $accessRules = [
        'admin' => ['admin', 'dashboard', 'settings', 'users', 'courses', 'applications'],
        'instructor' => ['instructor', 'dashboard', 'courses', 'students'],
        'student' => ['student', 'dashboard', 'courses', 'profile'],
        'applicant' => ['applicant', 'dashboard', 'profile']
    ];

    return isset($accessRules[$role]) && in_array($module, $accessRules[$role]);
}

/**
 * Require module access
 */
function requireModuleAccess($module)
{
    startSession();

    if (!isLoggedIn()) {
        $_SESSION['error'] = 'Please login to access this page.';
        redirect(BASE_URL . 'modules/auth/login.php');
    }

    if (!canAccessModule($module)) {
        $_SESSION['error'] = 'You do not have permission to access this module.';
        redirectToDashboard();
    }
}

/**
 * Check if user can access specific class
 */
function canAccessClass($class_id)
{
    $user = getCurrentUser();
    $conn = getDBConnection();

    // Admin can access everything
    if (isAdmin()) {
        return true;
    }

    // Instructor can access their own classes
    if (isInstructor()) {
        $sql = "SELECT COUNT(*) as count FROM class_batches 
                WHERE id = ? AND instructor_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $class_id, $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }

    // Student can access enrolled classes
    if (isStudent()) {
        $sql = "SELECT COUNT(*) as count FROM enrollments 
                WHERE class_id = ? AND student_id = ? AND status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $class_id, $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }

    return false;
}

/**
 * Check if user has permission for specific action
 */
function hasPermission($permission)
{
    $user = getCurrentUser();
    if (!$user) return false;

    // Define permission matrix
    $permissions = [
        'admin' => [
            'manage_users',
            'manage_classes',
            'manage_courses',
            'manage_finance',
            'view_reports',
            'manage_settings',
            'review_applications',
            'assign_instructors',
            'view_all_content'
        ],
        'instructor' => [
            'manage_class_content',
            'grade_assignments',
            'view_student_progress',
            'post_announcements',
            'manage_discussions',
            'view_class_finance'
        ],
        'student' => [
            'submit_assignments',
            'view_grades',
            'access_course_materials',
            'participate_discussions',
            'view_own_progress',
            'make_payments'
        ],
        'applicant' => [
            'submit_application',
            'view_application_status',
            'edit_profile'
        ]
    ];

    $role = $user['role'];
    return isset($permissions[$role]) && in_array($permission, $permissions[$role]);
}

/**
 * Require specific permission
 */
function requirePermission($permission)
{
    if (!hasPermission($permission)) {
        $_SESSION['error'] = 'You do not have permission to perform this action.';
        redirectToDashboard();
    }
}

// ===== COURSE & CLASS MANAGEMENT FUNCTIONS =====

/**
 * Get user's enrolled classes
 */
function getUserEnrolledClasses($user_id = null)
{
    if (!$user_id) {
        $user = getCurrentUser();
        if (!$user) return [];
        $user_id = $user['id'];
    }

    $conn = getDBConnection();
    $sql = "SELECT e.*, cb.*, c.title as course_title, c.course_code,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
                   p.name as program_name
            FROM enrollments e
            JOIN class_batches cb ON cb.id = e.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            JOIN users u ON u.id = cb.instructor_id
            WHERE e.student_id = ? AND e.status = 'active'
            ORDER BY cb.start_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get user's assigned classes (for instructors)
 */
function getInstructorClasses($instructor_id = null)
{
    if (!$instructor_id) {
        $user = getCurrentUser();
        if (!$user) return [];
        $instructor_id = $user['id'];
    }

    $conn = getDBConnection();
    $sql = "SELECT cb.*, c.title as course_title, c.course_code,
                   p.name as program_name,
                   COUNT(e.id) as student_count
            FROM class_batches cb
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            LEFT JOIN enrollments e ON e.class_id = cb.id AND e.status = 'active'
            WHERE cb.instructor_id = ? AND cb.status IN ('scheduled', 'ongoing')
            GROUP BY cb.id
            ORDER BY cb.start_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get program by ID
 */
function getProgram($program_id)
{
    $conn = getDBConnection();
    $sql = "SELECT * FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Get class by ID
 */
function getClass($class_id)
{
    $conn = getDBConnection();
    $sql = "SELECT cb.*, c.title as course_title, c.course_code,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
                   p.name as program_name, p.program_code
            FROM class_batches cb
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            JOIN users u ON u.id = cb.instructor_id
            WHERE cb.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Get course by ID
 */
function getCourse($course_id)
{
    $conn = getDBConnection();
    $sql = "SELECT c.*, p.name as program_name 
            FROM courses c
            JOIN programs p ON p.program_code = c.program_id
            WHERE c.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Copy courses from one program to another
 */
function copyCoursesBetweenPrograms($source_program_id, $target_program_id, $course_ids = [])
{
    global $conn;

    $result = [
        'success_count' => 0,
        'errors' => []
    ];

    // Build WHERE clause
    $where_clause = "WHERE program_id = ? AND status = 'active'";
    $params = [$source_program_id];
    $types = "i";

    if (!empty($course_ids)) {
        $placeholders = str_repeat('?,', count($course_ids) - 1) . '?';
        $where_clause .= " AND id IN ($placeholders)";
        $params = array_merge($params, $course_ids);
        $types .= str_repeat('i', count($course_ids));
    }

    // Fetch courses to copy
    $sql = "SELECT * FROM courses $where_clause ORDER BY order_number";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($courses as $course) {
        // Check if course already exists in target program
        $check_sql = "SELECT id FROM courses WHERE program_id = ? AND course_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("is", $target_program_id, $course['course_code']);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0) {
            $result['errors'][] = "Course {$course['course_code']} already exists in target program";
            continue;
        }

        // Insert course into target program
        $insert_sql = "INSERT INTO courses (program_id, course_code, title, description, duration_hours, 
                        level, order_number, is_required, status, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param(
            "isssisiss",
            $target_program_id,
            $course['course_code'],
            $course['title'],
            $course['description'],
            $course['duration_hours'],
            $course['level'],
            $course['order_number'],
            $course['is_required'],
            $course['status']
        );

        if ($insert_stmt->execute()) {
            $result['success_count']++;

            // Log activity
            logActivity(
                'course_copy',
                "Copied course {$course['course_code']} from program {$source_program_id} to program {$target_program_id}",
                'courses',
                $insert_stmt->insert_id
            );
        } else {
            $result['errors'][] = "Failed to copy course {$course['course_code']}: " . $insert_stmt->error;
        }
    }

    return $result;
}

/**
 * Get student's program progress
 */
function getStudentProgramProgress($student_id)
{
    global $conn;

    $progress = [
        'program' => null,
        'percentage' => 0,
        'completed_courses' => 0,
        'total_courses' => 0,
        'gpa' => 0,
        'current_period' => null
    ];

    // Get student's program
    $sql = "SELECT p.* FROM enrollments e
            JOIN class_batches cb ON e.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            WHERE e.student_id = ? AND e.status = 'active'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $progress['program'] = $result->fetch_assoc();
        $stmt->close();

        // Calculate progress
        $progress_sql = "SELECT 
            (SELECT COUNT(*) FROM program_requirements pr 
             WHERE pr.program_id = ?) as total_courses,
            (SELECT COUNT(DISTINCT c.id) FROM enrollments e
             JOIN class_batches cb ON e.class_id = cb.id
             JOIN courses c ON cb.course_id = c.id
             JOIN program_requirements pr ON c.id = pr.course_id
             WHERE e.student_id = ? AND pr.program_id = ?
             AND e.status = 'completed') as completed_courses";

        $progress_stmt = $conn->prepare($progress_sql);
        $progress_stmt->bind_param("iii", $progress['program']['id'], $student_id, $progress['program']['id']);
        $progress_stmt->execute();
        $progress_result = $progress_stmt->get_result();

        if ($progress_row = $progress_result->fetch_assoc()) {
            $progress['total_courses'] = $progress_row['total_courses'];
            $progress['completed_courses'] = $progress_row['completed_courses'];

            if ($progress_row['total_courses'] > 0) {
                $progress['percentage'] = ($progress_row['completed_courses'] / $progress_row['total_courses']) * 100;
            }
        }
        $progress_stmt->close();
    }

    return $progress;
}

/**
 * Check if student can register for a course
 */
function canRegisterForCourse($student_id, $course_id)
{
    global $conn;

    // Check if already enrolled or completed
    $check_sql = "SELECT COUNT(*) as count FROM enrollments e
                  JOIN class_batches cb ON e.class_id = cb.id
                  WHERE e.student_id = ? AND cb.course_id = ?
                  AND e.status IN ('active', 'completed')";

    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("ii", $student_id, $course_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result['count'] > 0) {
        return false; // Already enrolled or completed
    }

    // Check prerequisites
    $prereq_sql = "SELECT prerequisite_course_id FROM courses WHERE id = ?";
    $stmt = $conn->prepare($prereq_sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $prereq_result = $stmt->get_result();

    if ($prereq_row = $prereq_result->fetch_assoc()) {
        if (!empty($prereq_row['prerequisite_course_id'])) {
            // Check if prerequisite is completed
            $prereq_check = "SELECT COUNT(*) as count FROM enrollments e
                            JOIN class_batches cb ON e.class_id = cb.id
                            WHERE e.student_id = ? AND cb.course_id = ?
                            AND e.status = 'completed'";

            $check_stmt = $conn->prepare($prereq_check);
            $check_stmt->bind_param("ii", $student_id, $prereq_row['prerequisite_course_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($check_result['count'] == 0) {
                return false; // Prerequisite not met
            }
        }
    }
    $stmt->close();

    return true;
}

/**
 * Check course payment status
 */
function checkCoursePaymentStatus($student_id, $class_id)
{
    global $conn;

    $sql = "SELECT 
                sfs.*,
                ap.payment_deadline,
                ap.start_date,
                DATEDIFF(CURDATE(), ap.start_date) as days_since_start
            FROM student_financial_status sfs
            JOIN enrollments e ON sfs.class_id = e.class_id AND sfs.student_id = e.student_id
            JOIN class_batches cb ON e.class_id = cb.id
            JOIN academic_periods ap ON 
                (e.program_type = 'onsite' AND e.term_id = ap.id) OR 
                (e.program_type = 'online' AND e.block_id = ap.id)
            WHERE sfs.student_id = ? 
            AND sfs.class_id = ?
            AND e.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        return ['allowed' => false, 'reason' => 'No financial record found'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    $days_since_start = $row['days_since_start'] ?? 0;
    $payment_deadline = $row['payment_deadline'] ?? null;
    $balance = $row['balance'] ?? 0;

    // Allow access for first 2 weeks (14 days)
    if ($days_since_start <= 14) {
        return ['allowed' => true, 'warning' => 'Payment due by ' . $payment_deadline];
    }

    // After 2 weeks, check if paid
    if ($balance <= 0) {
        return ['allowed' => true];
    } else {
        return [
            'allowed' => false,
            'reason' => 'Course fee not paid. Payment was due by ' . $payment_deadline,
            'deadline' => $payment_deadline
        ];
    }
}

// ===== GRADING FUNCTIONS =====

/**
 * Calculate grade letter based on percentage
 */
function calculateGradeLetter($percentage)
{
    if ($percentage >= 90) return 'A';
    if ($percentage >= 80) return 'B';
    if ($percentage >= 70) return 'C';
    if ($percentage >= 60) return 'D';
    return 'F';
}

/**
 * Get grade points based on grade letter
 */
function getGradePoints($grade_letter)
{
    $grade_points = [
        'A' => 4.0,
        'B' => 3.0,
        'C' => 2.0,
        'D' => 1.0,
        'F' => 0.0,
        'A+' => 4.0,
        'A-' => 3.7,
        'B+' => 3.3,
        'B-' => 2.7,
        'C+' => 2.3,
        'C-' => 1.7,
        'D+' => 1.3,
        'D-' => 0.7
    ];

    return $grade_points[strtoupper($grade_letter)] ?? 0.0;
}

/**
 * Calculate weighted average
 */
function calculateWeightedAverage($grades)
{
    $total_weight = 0;
    $weighted_sum = 0;

    foreach ($grades as $grade) {
        $weight = $grade['weight'] ?? 1.0;
        $total_weight += $weight;
        $weighted_sum += $grade['score'] * $weight;
    }

    return $total_weight > 0 ? $weighted_sum / $total_weight : 0;
}

/**
 * Format grade for display
 */
function formatGrade($score, $max_score, $show_percentage = true)
{
    $formatted = round($score, 1) . '/' . round($max_score, 1);

    if ($show_percentage && $max_score > 0) {
        $percentage = ($score / $max_score) * 100;
        $formatted .= ' (' . round($percentage, 1) . '%)';
    }

    return $formatted;
}

/**
 * Get grade color based on percentage
 */
function getGradeColorClass($percentage)
{
    if ($percentage >= 90) return 'grade-a';
    if ($percentage >= 80) return 'grade-b';
    if ($percentage >= 70) return 'grade-c';
    if ($percentage >= 60) return 'grade-d';
    return 'grade-f';
}

// ===== FILE UPLOAD & IMAGE FUNCTIONS =====

/**
 * Handle file upload
 */
function handleFileUpload($file, $destination_dir, $allowed_types = [])
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error: " . ($file['error'] ?? 'No file uploaded'));
    }

    // Check file size
    if (defined('MAX_FILE_SIZE') && $file['size'] > MAX_FILE_SIZE) {
        throw new Exception("File size exceeds maximum limit of " . (MAX_FILE_SIZE / 1048576) . "MB.");
    }

    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!empty($allowed_types) && !in_array($file_ext, $allowed_types)) {
        throw new Exception("File type '$file_ext' not allowed. Allowed types: " . implode(', ', $allowed_types));
    }

    // Create directory if it doesn't exist
    if (!is_dir($destination_dir)) {
        mkdir($destination_dir, 0755, true);
    }

    // Generate unique filename
    $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
    $destination = rtrim($destination_dir, '/') . '/' . $unique_name;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return [
            'path' => $destination,
            'name' => $file['name'],
            'type' => $file_ext,
            'size' => $file['size'],
            'unique_name' => $unique_name
        ];
    }

    throw new Exception("Failed to move uploaded file.");
}

/**
 * Sanitize and validate file upload
 */
function validate_upload($file, $allowed_types = [], $max_size = 5242880)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Check file size
    if ($file['size'] > $max_size) {
        return false;
    }

    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!empty($allowed_types) && !in_array($mime_type, $allowed_types)) {
        return false;
    }

    // Sanitize filename
    $filename = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', basename($file['name']));
    $filename = time() . '_' . $filename;

    return [
        'tmp_name' => $file['tmp_name'],
        'name' => $filename,
        'size' => $file['size'],
        'type' => $mime_type,
        'extension' => pathinfo($filename, PATHINFO_EXTENSION)
    ];
}

/**
 * Validate uploaded image
 */
function validateImageUpload($file, $maxSize = 5242880, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
{
    $errors = [];
    $data = [];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];

        $errors[] = $uploadErrors[$file['error']] ?? 'Unknown upload error';
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        $errors[] = 'File is too large. Maximum size is ' . formatBytes($maxSize);
    }

    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = 'Invalid file type. Allowed types: ' . implode(', ', array_map(function ($type) {
            return str_replace('image/', '', $type);
        }, $allowedTypes));
    }

    // Verify image is valid
    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        $errors[] = 'Uploaded file is not a valid image';
    }

    if (empty($errors)) {
        $data = [
            'mime_type' => $mimeType,
            'image_info' => $imageInfo,
            'file_size' => $file['size'],
            'original_name' => $file['name']
        ];

        return [true, 'Image is valid', $data];
    }

    return [false, implode(', ', $errors), null];
}

/**
 * Resize an image to specified dimensions
 */
function resizeImage($filePath, $maxWidth, $maxHeight, $outputPath = null)
{
    if (!file_exists($filePath)) {
        return false;
    }

    // Get original image info
    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) {
        return false;
    }

    list($originalWidth, $originalHeight, $imageType) = $imageInfo;

    // Calculate new dimensions while maintaining aspect ratio
    $ratio = $originalWidth / $originalHeight;

    if ($maxWidth / $maxHeight > $ratio) {
        $newWidth = $maxHeight * $ratio;
        $newHeight = $maxHeight;
    } else {
        $newWidth = $maxWidth;
        $newHeight = $maxWidth / $ratio;
    }

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Load original image based on type
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $originalImage = imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $originalImage = imagecreatefrompng($filePath);
            // Preserve transparency for PNG
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            break;
        case IMAGETYPE_GIF:
            $originalImage = imagecreatefromgif($filePath);
            // Preserve transparency for GIF
            $transparentIndex = imagecolortransparent($originalImage);
            if ($transparentIndex >= 0) {
                $transparentColor = imagecolorsforindex($originalImage, $transparentIndex);
                $transparentIndex = imagecolorallocate($newImage, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue']);
                imagefill($newImage, 0, 0, $transparentIndex);
                imagecolortransparent($newImage, $transparentIndex);
            }
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $originalImage = imagecreatefromwebp($filePath);
            } else {
                return false; // WebP not supported
            }
            break;
        default:
            return false; // Unsupported image type
    }

    if (!isset($originalImage) || !$originalImage) {
        return false;
    }

    // Resize image
    imagecopyresampled($newImage, $originalImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

    // Determine output path
    $output = $outputPath ?: $filePath;

    // Save resized image
    $result = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($newImage, $output, 85); // 85% quality
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($newImage, $output, 6); // Compression level 6
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($newImage, $output);
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagewebp')) {
                $result = imagewebp($newImage, $output, 85); // 85% quality
            }
            break;
    }

    // Clean up
    imagedestroy($originalImage);
    imagedestroy($newImage);

    return $result;
}

/**
 * Create thumbnail from image
 */
function createThumbnail($filePath, $thumbWidth, $thumbHeight, $outputPath)
{
    return resizeImage($filePath, $thumbWidth, $thumbHeight, $outputPath);
}

// ===== FILE MANAGEMENT FUNCTIONS =====

/**
 * Get MIME type from extension
 */
function getMimeType($extension)
{
    $mime_types = [
        'txt' => 'text/plain',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar'
    ];

    return $mime_types[$extension] ?? 'application/octet-stream';
}

/**
 * Get file icon based on extension
 */
function getFileIcon($extension)
{
    $extension = strtolower($extension);

    $icon_map = [
        // Documents
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'txt' => 'fas fa-file-alt',
        'rtf' => 'fas fa-file-alt',

        // Spreadsheets
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'csv' => 'fas fa-file-csv',

        // Presentations
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',

        // Images
        'jpg' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'gif' => 'fas fa-file-image',
        'bmp' => 'fas fa-file-image',
        'webp' => 'fas fa-file-image',

        // Archives
        'zip' => 'fas fa-file-archive',
        'rar' => 'fas fa-file-archive',
        '7z' => 'fas fa-file-archive',
        'tar' => 'fas fa-file-archive',
        'gz' => 'fas fa-file-archive',

        // Code
        'php' => 'fas fa-file-code',
        'html' => 'fas fa-file-code',
        'css' => 'fas fa-file-code',
        'js' => 'fas fa-file-code',
        'json' => 'fas fa-file-code',
        'xml' => 'fas fa-file-code',

        // Audio/Video
        'mp3' => 'fas fa-file-audio',
        'wav' => 'fas fa-file-audio',
        'mp4' => 'fas fa-file-video',
        'avi' => 'fas fa-file-video',
        'mov' => 'fas fa-file-video',
    ];

    return $icon_map[$extension] ?? 'fas fa-file';
}

/**
 * Generate a unique filename while preserving extension
 */
function generateUniqueFilename($originalName, $prefix = '')
{
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $timestamp = time();
    $random = bin2hex(random_bytes(8));

    return $prefix . $timestamp . '_' . $random . '.' . strtolower($extension);
}

/**
 * Sanitize filename
 */
function sanitizeFilename($filename)
{
    // Remove path information and just get the filename
    $filename = basename($filename);

    // Replace spaces with underscores
    $filename = str_replace(' ', '_', $filename);

    // Remove any non-alphanumeric, dash, underscore, or dot characters
    $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $filename);

    // Remove multiple dots
    $filename = preg_replace('/\.+/', '.', $filename);

    return $filename;
}

// ===== MESSAGING FUNCTIONS =====

/**
 * Send internal message
 */
function sendInternalMessage($sender_id, $receiver_id, $message, $subject = null, $message_type = 'user_message', $related_data = [])
{
    $conn = getDBConnection();

    $sql = "INSERT INTO internal_messages (sender_id, receiver_id, subject, message, message_type, related_transaction_id, related_invoice_id, related_class_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $related_transaction_id = $related_data['transaction_id'] ?? null;
    $related_invoice_id = $related_data['invoice_id'] ?? null;
    $related_class_id = $related_data['class_id'] ?? null;

    $stmt->bind_param(
        "iisssiii",
        $sender_id,
        $receiver_id,
        $subject,
        $message,
        $message_type,
        $related_transaction_id,
        $related_invoice_id,
        $related_class_id
    );

    if ($stmt->execute()) {
        $message_id = $conn->insert_id;

        // Send notification
        sendNotification(
            $receiver_id,
            $subject ?? 'New Message',
            'You have received a new message',
            'message',
            $message_id
        );

        return $message_id;
    }

    return false;
}

/**
 * Get user's unread message count
 */
function getUnreadMessageCount($user_id)
{
    $conn = getDBConnection();

    $sql = "SELECT COUNT(*) as count FROM internal_messages 
            WHERE receiver_id = ? AND is_read = 0 
            AND is_deleted_receiver = 0";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['count'] ?? 0;
}

/**
 * Get user's recent messages
 */
function getRecentMessages($user_id, $limit = 10)
{
    $conn = getDBConnection();

    $sql = "SELECT m.*, 
                   u_sender.first_name as sender_first_name, 
                   u_sender.last_name as sender_last_name,
                   u_receiver.first_name as receiver_first_name,
                   u_receiver.last_name as receiver_last_name
            FROM internal_messages m
            JOIN users u_sender ON u_sender.id = m.sender_id
            JOIN users u_receiver ON u_receiver.id = m.receiver_id
            WHERE (m.receiver_id = ? AND m.is_deleted_receiver = 0) 
               OR (m.sender_id = ? AND m.is_deleted_sender = 0)
            ORDER BY m.created_at DESC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_sender'] = ($row['sender_id'] == $user_id);
        $messages[] = $row;
    }

    return $messages;
}

/**
 * Mark message as read
 */
function markMessageAsRead($message_id, $user_id)
{
    $conn = getDBConnection();

    $sql = "UPDATE internal_messages 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND receiver_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $message_id, $user_id);
    return $stmt->execute();
}

/**
 * Get message thread
 */
function getMessageThread($message_id, $user_id)
{
    $conn = getDBConnection();

    // First get the parent message
    $sql = "SELECT parent_id FROM internal_messages WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $thread_id = $row['parent_id'] ?? $message_id;

    // Get all messages in thread
    $sql = "SELECT m.*, 
                   u_sender.first_name as sender_first_name, 
                   u_sender.last_name as sender_last_name
            FROM internal_messages m
            JOIN users u_sender ON u_sender.id = m.sender_id
            WHERE (m.id = ? OR m.parent_id = ?) 
            AND (m.receiver_id = ? OR m.sender_id = ?)
            AND ((m.receiver_id = ? AND m.is_deleted_receiver = 0) 
              OR (m.sender_id = ? AND m.is_deleted_sender = 0))
            ORDER BY m.created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiii", $thread_id, $thread_id, $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_sender'] = ($row['sender_id'] == $user_id);
        $messages[] = $row;
    }

    return $messages;
}

// ===== MISC UTILITY FUNCTIONS =====

/**
 * Get system setting
 */
function getSetting($key, $default = null)
{
    $conn = getDBConnection();

    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $key);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }

    return $default;
}

/**
 * Set flash message
 */
function setFlashMessage($type, $message)
{
    startSession();
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get flash message
 */
function getFlashMessage($type)
{
    startSession();
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

/**
 * Generate pagination links
 */
function generatePagination($current_page, $total_pages, $url)
{
    $pagination = '';

    if ($total_pages > 1) {
        $pagination .= '<nav aria-label="Page navigation">';
        $pagination .= '<ul class="pagination">';

        // Previous button
        if ($current_page > 1) {
            $pagination .= '<li class="page-item">';
            $pagination .= '<a class="page-link" href="' . $url . '?page=' . ($current_page - 1) . '">Previous</a>';
            $pagination .= '</li>';
        }

        // Page numbers
        $start = max(1, $current_page - 2);
        $end = min($total_pages, $current_page + 2);

        for ($i = $start; $i <= $end; $i++) {
            $active = $i == $current_page ? ' active' : '';
            $pagination .= '<li class="page-item' . $active . '">';
            $pagination .= '<a class="page-link" href="' . $url . '?page=' . $i . '">' . $i . '</a>';
            $pagination .= '</li>';
        }

        // Next button
        if ($current_page < $total_pages) {
            $pagination .= '<li class="page-item">';
            $pagination .= '<a class="page-link" href="' . $url . '?page=' . ($current_page + 1) . '">Next</a>';
            $pagination .= '</li>';
        }

        $pagination .= '</ul>';
        $pagination .= '</nav>';
    }

    return $pagination;
}

/**
 * Get academic year
 */
function getAcademicYear()
{
    $year = date('Y');
    $month = date('n');

    // Academic year typically runs August-July
    if ($month >= 8) {
        return $year . '/' . ($year + 1);
    } else {
        return ($year - 1) . '/' . $year;
    }
}

function getFileTypeLabel($file_type)
{
    $labels = [
        'pdf' => 'PDF Document',
        'document' => 'Document',
        'presentation' => 'Presentation',
        'spreadsheet' => 'Spreadsheet',
        'video' => 'Video',
        'image' => 'Image',
        'link' => 'External Link',
        'other' => 'Other File'
    ];
    return $labels[$file_type] ?? 'Unknown';
}

// ===== FUNCTIONS THAT CALL EMAIL FUNCTIONS (These now reference email_functions.php) =====

/**
 * Override the existing sendGradeNotification function to include email
 */
function sendGradeNotification($student_id, $assignment_id, $grade, $conn)
{
    // Get assignment details
    $sql = "SELECT a.title, a.total_points, c.title as course_title, 
                   c.course_code, cb.batch_code
            FROM assignments a 
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id 
            WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignment = $result->fetch_assoc();
    $stmt->close();

    if (!$assignment) return false;

    // Get student details
    $sql_student = "SELECT email, first_name, last_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql_student);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    $student = $student_result->fetch_assoc();
    $stmt->close();

    if (!$student) return false;

    $percentage = ($grade / $assignment['total_points']) * 100;
    $grade_letter = calculateGradeLetter($percentage);

    // Create in-app notification
    $sql_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                  VALUES (?, ?, ?, 'grade', ?, NOW())";
    $stmt_notif = $conn->prepare($sql_notif);
    $title = "Grade Posted: " . $assignment['title'];
    $message = "Your grade for " . $assignment['title'] . " has been posted. " .
        "You scored " . $grade . "/" . $assignment['total_points'] .
        " (" . round($percentage, 1) . "%).";
    $stmt_notif->bind_param("issi", $student_id, $title, $message, $assignment_id);
    $notif_result = $stmt_notif->execute();
    $notification_id = $stmt_notif->insert_id;
    $stmt_notif->close();

    // Send email notification (function from email_functions.php)
    $email_result = sendGradeNotificationEmail($student_id, $assignment_id, $grade, $conn);

    if ($notif_result && $email_result) {
        logActivity('grade_notified', "Sent grade notification to student #{$student_id} for assignment #{$assignment_id}", 'notifications', $notification_id);
    }

    return $notif_result || $email_result;
}
// Add this to your functions.php file in the grading functions section

/**
 * Calculate student's GPA for a specific class, including missed assignments/quizzes as zeros
 * 
 * @param int $student_id Student ID
 * @param int $class_id Class ID
 * @return array GPA details including overall GPA, completed items, total items, etc.
 */
function calculateStudentGPA($student_id, $class_id)
{
    $conn = getDBConnection();

    // Get all graded items (assignments and quizzes) for this class
    $sql = "SELECT 
                'assignment' as item_type,
                a.id as item_id,
                a.title as item_title,
                a.total_points as max_score,
                COALESCE(ag.score, 0) as score,
                CASE WHEN ag.id IS NULL THEN 0 ELSE 1 END as submitted,
                a.due_date
            FROM assignments a
            LEFT JOIN assignment_submissions ag ON a.id = ag.assignment_id 
                AND ag.student_id = ? AND ag.status IN ('submitted', 'graded')
            WHERE a.class_id = ? AND a.is_published = 1
            
            UNION ALL
            
            SELECT 
                'quiz' as item_type,
                q.id as item_id,
                q.title as item_title,
                q.total_points as max_score,
                COALESCE(qa.total_score, 0) as score,
                CASE WHEN qa.id IS NULL THEN 0 ELSE 1 END as submitted,
                q.due_date
            FROM quizzes q
            LEFT JOIN quiz_attempts qa ON q.id = qa.quiz_id 
                AND qa.student_id = ? AND qa.status = 'graded'
            WHERE q.class_id = ? AND q.is_published = 1
            
            ORDER BY due_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $student_id, $class_id, $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    $total_points_possible = 0;
    $total_points_earned = 0;
    $completed_count = 0;
    $missed_count = 0;

    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
        $total_points_possible += $row['max_score'];
        $total_points_earned += $row['score'];

        if ($row['submitted']) {
            $completed_count++;
        } else {
            $missed_count++;
        }
    }

    // Calculate percentage
    $percentage = ($total_points_possible > 0)
        ? ($total_points_earned / $total_points_possible) * 100
        : 0;

    // Calculate GPA (4.0 scale)
    $gpa = convertPercentageToGPA($percentage);

    return [
        'student_id' => $student_id,
        'class_id' => $class_id,
        'total_items' => count($items),
        'completed_items' => $completed_count,
        'missed_items' => $missed_count,
        'total_points_possible' => $total_points_possible,
        'total_points_earned' => $total_points_earned,
        'percentage' => round($percentage, 2),
        'gpa' => $gpa,
        'grade_letter' => calculateGradeLetter($percentage),
        'items' => $items
    ];
}

/**
 * Convert percentage to GPA on 4.0 scale
 */
function convertPercentageToGPA($percentage)
{
    if ($percentage >= 93) return 4.0;
    if ($percentage >= 90) return 3.7;
    if ($percentage >= 87) return 3.3;
    if ($percentage >= 83) return 3.0;
    if ($percentage >= 80) return 2.7;
    if ($percentage >= 77) return 2.3;
    if ($percentage >= 73) return 2.0;
    if ($percentage >= 70) return 1.7;
    if ($percentage >= 67) return 1.3;
    if ($percentage >= 63) return 1.0;
    if ($percentage >= 60) return 0.7;
    return 0.0;
}

/**
 * Calculate student's cumulative GPA across all enrolled classes
 */
function calculateCumulativeGPA($student_id)
{
    $conn = getDBConnection();

    // Get all classes the student is enrolled in
    $sql = "SELECT class_id FROM enrollments 
            WHERE student_id = ? AND status IN ('active', 'completed')";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_gpa = 0;
    $class_count = 0;
    $class_details = [];

    while ($row = $result->fetch_assoc()) {
        $gpa_data = calculateStudentGPA($student_id, $row['class_id']);
        $class_details[] = $gpa_data;
        $total_gpa += $gpa_data['gpa'];
        $class_count++;
    }

    $cumulative_gpa = ($class_count > 0) ? $total_gpa / $class_count : 0;

    return [
        'student_id' => $student_id,
        'classes_taken' => $class_count,
        'cumulative_gpa' => round($cumulative_gpa, 2),
        'class_details' => $class_details
    ];
}

/**
 * Update gradebook entries for missed assignments/quizzes (grade as zero)
 * This ensures all items are properly recorded in the gradebook
 */
function updateGradebookWithMissedItems($class_id = null)
{
    $conn = getDBConnection();

    $where_clause = "";
    $params = [];
    $types = "";

    if ($class_id) {
        $where_clause = "WHERE e.class_id = ?";
        $params[] = $class_id;
        $types = "i";
    }

    // Get all enrolled students
    $sql = "SELECT e.student_id, e.class_id, e.enrollment_id 
            FROM enrollments e
            $where_clause
            AND e.status IN ('active', 'completed')";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $enrollments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $updated_count = 0;

    foreach ($enrollments as $enrollment) {
        $student_id = $enrollment['student_id'];
        $class_id = $enrollment['class_id'];

        // Get all assignments for this class
        $assignments_sql = "SELECT id, title, total_points FROM assignments 
                           WHERE class_id = ? AND is_published = 1";
        $stmt = $conn->prepare($assignments_sql);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($assignments as $assignment) {
            // Check if grade exists in gradebook
            $check_sql = "SELECT id FROM gradebook 
                         WHERE student_id = ? AND assignment_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $student_id, $assignment['id']);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();

            if (!$exists) {
                // Insert zero grade for missed assignment
                $insert_sql = "INSERT INTO gradebook 
                              (enrollment_id, assignment_id, student_id, score, max_score, 
                               percentage, grade_letter, weight, published, created_at)
                              SELECT ?, ?, ?, ?, ?, 0, 'F', 1, 1, NOW()
                              WHERE NOT EXISTS (
                                  SELECT 1 FROM gradebook 
                                  WHERE student_id = ? AND assignment_id = ?
                              )";

                $insert_stmt = $conn->prepare($insert_sql);
                $score = 0;
                $max_score = $assignment['total_points'];
                $percentage = 0;

                $insert_stmt->bind_param(
                    "iiidii",
                    $enrollment['enrollment_id'],
                    $assignment['id'],
                    $student_id,
                    $score,
                    $max_score,
                    $student_id,
                    $assignment['id']
                );

                if ($insert_stmt->execute()) {
                    $updated_count++;
                }
            }
        }

        // Similar logic for quizzes
        $quizzes_sql = "SELECT id, title, total_points FROM quizzes 
                       WHERE class_id = ? AND is_published = 1";
        $stmt = $conn->prepare($quizzes_sql);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($quizzes as $quiz) {
            // Check if quiz attempt exists and is graded
            $attempt_sql = "SELECT id, total_score FROM quiz_attempts 
                           WHERE student_id = ? AND quiz_id = ? AND status = 'graded'";
            $attempt_stmt = $conn->prepare($attempt_sql);
            $attempt_stmt->bind_param("ii", $student_id, $quiz['id']);
            $attempt_stmt->execute();
            $attempt = $attempt_stmt->get_result()->fetch_assoc();

            if (!$attempt) {
                // No attempt or not graded - insert zero
                $insert_sql = "INSERT INTO gradebook 
                              (enrollment_id, assignment_id, student_id, score, max_score, 
                               percentage, grade_letter, weight, published, created_at)
                              SELECT ?, NULL, ?, 0, ?, 0, 'F', 1, 1, NOW()
                              WHERE NOT EXISTS (
                                  SELECT 1 FROM gradebook 
                                  WHERE student_id = ? AND assignment_id IS NULL 
                                  AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                              )";

                $insert_stmt = $conn->prepare($insert_sql);
                $max_score = $quiz['total_points'];

                $insert_stmt->bind_param(
                    "iiii",
                    $enrollment['enrollment_id'],
                    $student_id,
                    $max_score,
                    $student_id
                );

                if ($insert_stmt->execute()) {
                    $updated_count++;
                }
            }
        }
    }

    return $updated_count;
}

/**
 * Get comprehensive GPA report for a student
 */
function getStudentGPAReport($student_id)
{
    $conn = getDBConnection();

    // Get cumulative GPA
    $cumulative = calculateCumulativeGPA($student_id);

    // Get detailed class information
    $sql = "SELECT 
                e.class_id,
                cb.batch_code,
                c.title as course_title,
                c.course_code,
                e.status as enrollment_status,
                e.enrollment_date
            FROM enrollments e
            JOIN class_batches cb ON e.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            WHERE e.student_id = ? AND e.status IN ('active', 'completed')
            ORDER BY e.enrollment_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $report = [
        'student_id' => $student_id,
        'cumulative_gpa' => $cumulative['cumulative_gpa'],
        'classes_taken' => $cumulative['classes_taken'],
        'classes' => []
    ];

    foreach ($classes as $class) {
        $gpa_data = calculateStudentGPA($student_id, $class['class_id']);
        $report['classes'][] = array_merge($class, $gpa_data);
    }

    return $report;
}
