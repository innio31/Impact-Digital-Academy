<?php
// cron/send_payment_reminders.php
/**
 * Daily cron job to send payment reminders
 * Sends reminders 7 days, 3 days, and 1 day before due date
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance_functions.php';

$conn = getDBConnection();
echo "[" . date('Y-m-d H:i:s') . "] Starting payment reminders...\n";

$reminder_sent = 0;

// Reminder 7 days before due date
$sql = "SELECT i.*, u.email, u.first_name, u.last_name, 
               cb.batch_code, c.title as course_title
        FROM invoices i
        JOIN users u ON u.id = i.student_id
        JOIN class_batches cb ON cb.id = i.class_id
        JOIN courses c ON c.id = cb.course_id
        WHERE i.status = 'pending' 
        AND i.due_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND (i.last_reminder_sent IS NULL OR i.last_reminder_sent < DATE_SUB(CURDATE(), INTERVAL 6 DAY))";

$result = $conn->query($sql);
while ($invoice = $result->fetch_assoc()) {
    sendPaymentReminder($invoice, 7);
    $reminder_sent++;
}

// Reminder 3 days before due date
$sql = "SELECT i.*, u.email, u.first_name, u.last_name, 
               cb.batch_code, c.title as course_title
        FROM invoices i
        JOIN users u ON u.id = i.student_id
        JOIN class_batches cb ON cb.id = i.class_id
        JOIN courses c ON c.id = cb.course_id
        WHERE i.status = 'pending' 
        AND i.due_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        AND (i.last_reminder_sent IS NULL OR i.last_reminder_sent < DATE_SUB(CURDATE(), INTERVAL 2 DAY))";

$result = $conn->query($sql);
while ($invoice = $result->fetch_assoc()) {
    sendPaymentReminder($invoice, 3);
    $reminder_sent++;
}

// Reminder 1 day before due date
$sql = "SELECT i.*, u.email, u.first_name, u.last_name, 
               cb.batch_code, c.title as course_title
        FROM invoices i
        JOIN users u ON u.id = i.student_id
        JOIN class_batches cb ON cb.id = i.class_id
        JOIN courses c ON c.id = cb.course_id
        WHERE i.status = 'pending' 
        AND i.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND (i.last_reminder_sent IS NULL OR i.last_reminder_sent < CURDATE())";

$result = $conn->query($sql);
while ($invoice = $result->fetch_assoc()) {
    sendPaymentReminder($invoice, 1);
    $reminder_sent++;
}

// Update last reminder sent date
if ($reminder_sent > 0) {
    $sql = "UPDATE invoices SET last_reminder_sent = CURDATE() 
            WHERE due_date IN (
                DATE_ADD(CURDATE(), INTERVAL 7 DAY),
                DATE_ADD(CURDATE(), INTERVAL 3 DAY),
                DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ) AND status = 'pending'";
    $conn->query($sql);
}

echo "Sent $reminder_sent payment reminders\n";

// Log completion
logActivity("Payment reminders sent via cron: $reminder_sent reminders");
echo "[" . date('Y-m-d H:i:s') . "] Payment reminders completed.\n";

/**
 * Send payment reminder to student
 */
function sendPaymentReminder($invoice, $days_left) {
    $title = "Payment Reminder: Invoice #{$invoice['invoice_number']}";
    
    $message = "Dear {$invoice['first_name']},\n\n";
    $message .= "This is a reminder that your invoice #{$invoice['invoice_number']} ";
    $message .= "for {$invoice['course_title']} (Batch: {$invoice['batch_code']}) ";
    $message .= "is due in $days_left day" . ($days_left == 1 ? "" : "s") . ".\n\n";
    $message .= "Amount Due: " . formatCurrency($invoice['amount']) . "\n";
    $message .= "Due Date: " . date('F d, Y', strtotime($invoice['due_date'])) . "\n\n";
    $message .= "Please make payment to avoid late fees or account suspension.\n\n";
    $message .= "You can make payment through your student dashboard.\n\n";
    $message .= "Thank you,\n" . getSetting('site_name', 'Impact Digital Academy');
    
    // Send notification
    sendNotification($invoice['student_id'], $title, $message, 'system');
    
    // In production, also send email
    // sendEmail($invoice['email'], $title, $message);
}
?>