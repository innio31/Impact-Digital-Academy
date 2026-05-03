<?php
// api/dashboard/stats.php - Get dashboard statistics
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

$user_id = validateToken();

if (!$user_id) {
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get counts
$stats = [];

// Total students
$query = "SELECT COUNT(*) as total FROM students WHERE school_id = 1 AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['students'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff
$query = "SELECT COUNT(*) as total FROM users WHERE school_id = 1 AND role IN ('staff', 'admin') AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total parents
$query = "SELECT COUNT(*) as total FROM users WHERE school_id = 1 AND role = 'parent' AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['parents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Current session and term
$query = "SELECT s.name as session_name, t.name as term_name 
          FROM sessions s 
          LEFT JOIN terms t ON s.id = t.session_id 
          WHERE s.is_current = 1 AND t.is_current = 1 
          LIMIT 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['current_term'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent activities (mock for now - can be from audit_logs table)
$stats['recent_activities'] = [];

sendSuccess($stats);
