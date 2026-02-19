<?php
// cron/check_overdue_payments.php
/**
 * Daily cron job to check for overdue payments
 * and apply late fees/auto-suspension
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance_functions.php';

$conn = getDBConnection();
echo "[" . date('Y-m-d H:i:s') . "] Starting overdue payments check...\n";

// Check for overdue invoices and apply late fees
$overdue_count = checkOverduePayments();
echo "Applied late fees to $overdue_count overdue invoices\n";

// Check for students who need auto-suspension (21 days overdue)
$today = date('Y-m-d');
$sql = "SELECT sfs.*, u.email, u.first_name, u.last_name, cb.batch_code
        FROM student_financial_status sfs
        JOIN users u ON u.id = sfs.student_id
        JOIN class_batches cb ON cb.id = sfs.class_id
        WHERE sfs.balance > 0 
        AND sfs.next_payment_due IS NOT NULL 
        AND sfs.next_payment_due < DATE_SUB(?, INTERVAL 21 DAY)
        AND sfs.is_suspended = 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

$suspended_count = 0;
while ($student = $result->fetch_assoc()) {
    // Suspend student
    $sql = "UPDATE student_financial_status 
            SET is_suspended = 1, suspended_at = NOW(),
                suspension_reason = 'Automatic suspension due to overdue payment (21+ days)'
            WHERE student_id = ? AND class_id = ?";
    
    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param("ii", $student['student_id'], $student['class_id']);
    $stmt2->execute();
    
    // Update enrollment status
    $sql = "UPDATE enrollments 
            SET status = 'suspended' 
            WHERE student_id = ? AND class_id = ?";
    
    $stmt3 = $conn->prepare($sql);
    $stmt3->bind_param("ii", $student['student_id'], $student['class_id']);
    $stmt3->execute();
    
    // Send suspension notification
    sendSuspensionNotification($student['student_id'], $student['class_id']);
    
    $suspended_count++;
}

echo "Auto-suspended $suspended_count students for overdue payments\n";

// Log completion
logActivity("Overdue payments check completed via cron: $overdue_count late fees, $suspended_count suspensions");
echo "[" . date('Y-m-d H:i:s') . "] Overdue payments check completed.\n";
?>