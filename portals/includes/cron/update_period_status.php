<?php
// includes/cron/update_period_status.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$conn = getDBConnection();

// Update status based on dates
$update_query = "
    UPDATE academic_periods 
    SET status = CASE 
        WHEN start_date > CURDATE() THEN 'upcoming'
        WHEN CURDATE() BETWEEN start_date AND end_date THEN 'active'
        WHEN CURDATE() > end_date THEN 'completed'
        ELSE status
    END
    WHERE status NOT IN ('cancelled');
";

if ($conn->query($update_query)) {
    logActivity("Academic period statuses updated via cron");
    echo "Academic period statuses updated successfully.\n";
} else {
    echo "Error updating period statuses: " . $conn->error . "\n";
}