<?php
// export-campaign.php - Export campaign data to CSV
session_start();
require_once 'config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('Unauthorized');
}

$campaign_id = $_GET['campaign'] ?? '';
if (empty($campaign_id)) {
    die('No campaign specified');
}

$db = (new Database())->getConnection();

// Get campaign info
$campStmt = $db->prepare("SELECT article_title FROM email_campaigns WHERE email_id = ?");
$campStmt->execute([$campaign_id]);
$campaign = $campStmt->fetch();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="campaign_' . $campaign_id . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Opens sheet
fputcsv($output, ['=== OPENS ===']);
fputcsv($output, ['Email', 'First Open', 'Last Open', 'Opens Count', 'IP Address', 'User Agent']);

$openStmt = $db->prepare("
    SELECT * FROM email_tracking 
    WHERE email_id = ? 
    ORDER BY first_open DESC
");
$openStmt->execute([$campaign_id]);

while ($row = $openStmt->fetch()) {
    fputcsv($output, [
        $row['subscriber_email'],
        $row['first_open'],
        $row['last_open'],
        $row['opens_count'],
        $row['ip_address'],
        $row['user_agent']
    ]);
}

// Clicks sheet
fputcsv($output, []);
fputcsv($output, ['=== CLICKS ===']);
fputcsv($output, ['Email', 'Clicked At', 'Clicked Link', 'IP Address', 'User Agent']);

$clickStmt = $db->prepare("
    SELECT * FROM email_clicks 
    WHERE email_id = ? 
    ORDER BY clicked_at DESC
");
$clickStmt->execute([$campaign_id]);

while ($row = $clickStmt->fetch()) {
    fputcsv($output, [
        $row['subscriber_email'],
        $row['clicked_at'],
        $row['clicked_link'],
        $row['ip_address'],
        $row['user_agent']
    ]);
}

fclose($output);
exit;
