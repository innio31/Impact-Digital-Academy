<?php
// cron/block_progression_check.php
/**
 * Monthly cron job to check and update block progression
 * Checks if students should progress to next block based on payments
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance_functions.php';

$conn = getDBConnection();
echo "[" . date('Y-m-d H:i:s') . "] Starting block progression check...\n";

// Get current academic periods
$sql = "SELECT * FROM academic_periods 
        WHERE status = 'active' 
        AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)";

$result = $conn->query($sql);
$periods_ending = $result->fetch_all(MYSQLI_ASSOC);

$students_progressed = 0;

foreach ($periods_ending as $period) {
    echo "Checking period: {$period['period_name']} (Ends: {$period['end_date']})\n";
    
    if ($period['program_type'] == 'online' && $period['period_type'] == 'block') {
        // For online blocks, check if students have paid for current block
        $sql = "SELECT sfs.*, cb.block_number, u.email, u.first_name, u.last_name
                FROM student_financial_status sfs
                JOIN class_batches cb ON cb.id = sfs.class_id
                JOIN users u ON u.id = sfs.student_id
                WHERE cb.block_number = ?
                AND cb.academic_year = ?
                AND sfs.current_block = ?
                AND sfs.is_suspended = 0";
        
        $next_block = $period['period_number'] + 1;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $period['period_number'], $period['academic_year'], $period['period_number']);
        $stmt->execute();
        $result2 = $stmt->get_result();
        
        while ($student = $result2->fetch_assoc()) {
            // Check if student has paid for current block
            $block_paid = false;
            if ($student['current_block'] == 1 && $student['block1_paid']) {
                $block_paid = true;
            } elseif ($student['current_block'] == 2 && $student['block2_paid']) {
                $block_paid = true;
            }
            
            if ($block_paid) {
                // Student can progress to next block
                if ($next_block <= 2) { // Only blocks 1 and 2 for payment tracking
                    $sql = "UPDATE student_financial_status 
                            SET current_block = ?
                            WHERE student_id = ? AND class_id = ?";
                    
                    $stmt2 = $conn->prepare($sql);
                    $stmt2->bind_param("iii", $next_block, $student['student_id'], $student['class_id']);
                    $stmt2->execute();
                    
                    // Send progression notification
                    sendProgressionNotification($student['student_id'], $student['class_id'], $next_block);
                    
                    $students_progressed++;
                    echo "Student {$student['student_id']} progressed to block $next_block\n";
                } else {
                    echo "Student {$student['student_id']} completed all blocks\n";
                }
            } else {
                // Student hasn't paid for current block
                sendPaymentWarningNotification($student['student_id'], $student['class_id'], $period['period_number']);
                echo "Student {$student['student_id']} hasn't paid for block {$period['period_number']}\n";
            }
        }
    }
}

echo "Progressed $students_progressed students to next block\n";

// Log completion
logActivity("Block progression check completed via cron: $students_progressed students progressed");
echo "[" . date('Y-m-d H:i:s') . "] Block progression check completed.\n";

/**
 * Send progression notification
 */
function sendProgressionNotification($student_id, $class_id, $next_block) {
    $title = "Block Progression Update";
    $message = "You have successfully progressed to Block $next_block. ";
    $message .= "Please check your dashboard for upcoming classes and payments.";
    sendNotification($student_id, $title, $message, 'system');
}

/**
 * Send payment warning notification
 */
function sendPaymentWarningNotification($student_id, $class_id, $current_block) {
    $title = "Payment Required for Block Progression";
    $message = "You need to complete payment for Block $current_block to progress to the next block. ";
    $message .= "Please make payment immediately to avoid disruption in your studies.";
    sendNotification($student_id, $title, $message, 'system');
}
?>