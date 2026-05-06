<?php
// api/dashboard/stats.php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

// Enable error reporting for debugging (remove after fix)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $user_id = validateToken();

    if (!$user_id) {
        // validateToken already sends error response, but just in case
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    $stats = [];

    // Total students
    $query = "SELECT COUNT(*) as total FROM students WHERE school_id = 1 AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['students'] = $result ? (int)$result['total'] : 0;

    // Total staff (users with role 'staff' or 'admin')
    $query = "SELECT COUNT(*) as total FROM users WHERE school_id = 1 AND role IN ('staff', 'admin') AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['staff'] = $result ? (int)$result['total'] : 0;

    // Total parents
    $query = "SELECT COUNT(*) as total FROM users WHERE school_id = 1 AND role = 'parent' AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['parents'] = $result ? (int)$result['total'] : 0;

    // Current session and term
    $query = "SELECT s.name as session_name, t.name as term_name 
              FROM sessions s 
              LEFT JOIN terms t ON s.id = t.session_id AND t.is_current = 1
              WHERE s.is_current = 1 
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $current_term = $stmt->fetch(PDO::FETCH_ASSOC);

    $stats['current_term'] = $current_term ?: null;

    sendSuccess($stats);
} catch (Exception $e) {
    // Log the error to a file
    error_log("Stats API Error: " . $e->getMessage());

    // Send error response
    sendError("Failed to fetch dashboard stats: " . $e->getMessage(), 500);
}
