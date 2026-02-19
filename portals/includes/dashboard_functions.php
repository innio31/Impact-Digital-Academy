<?php
// includes/dashboard_functions.php

/**
 * Get comprehensive dashboard statistics
 */
function getDashboardStats() {
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
function getRecentActivities($limit = 10) {
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
function getUpcomingClasses($limit = 5) {
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
function getPendingApplications($limit = 5) {
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
function getUnreadNotifications($user_id) {
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
function checkSystemAlerts() {
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
function getEnrollmentTrends($period = 'month') {
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
function getRevenueTrends($period = 'month') {
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
function getOverdueInvoices($limit = 10) {
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

/**
 * Format time ago
 */
function timeAgo($timestamp) {
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
?>