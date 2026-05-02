<?php
// This script should be run daily via cron job
header('Content-Type: application/json');
require_once 'cors.php';
include 'db_connect.php';

// Calculate attendance for all members (last 90 days)
$update_query = "
    UPDATE members m
    SET 
        attendance_percentage = (
            SELECT 
                ROUND(COUNT(a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0), 2)
            FROM services s
            LEFT JOIN attendance a ON a.service_id = s.id AND a.member_id = m.id
            WHERE s.service_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ),
        last_attendance_date = (
            SELECT MAX(DATE(attendance_time))
            FROM attendance
            WHERE member_id = m.id
        )
    WHERE m.is_active = 1
";

$conn->query($update_query);

// Update statuses based on rules
$status_query = "
    UPDATE members 
    SET status = CASE
        WHEN attendance_percentage >= 70 THEN 'active'
        WHEN attendance_percentage < 40 AND attendance_percentage > 0 THEN 'inactive'
        WHEN last_attendance_date <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) THEN 'revalidation'
        WHEN last_attendance_date <= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) THEN 'not_available'
        ELSE status
    END
    WHERE is_active = 1
";

$conn->query($status_query);

// Also update the members table to add status column if not exists
$alter_query = "
    ALTER TABLE members 
    ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'inactive', 'not_available', 'revalidation', 'deceased', 'pending') DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `last_attendance_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `attendance_percentage` DECIMAL(5,2) DEFAULT 0.00
";

$conn->query($alter_query);

echo json_encode(['success' => true, 'message' => 'Member statuses updated successfully']);
$conn->close();
