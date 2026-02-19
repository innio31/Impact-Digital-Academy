<?php
// cron/backup_database.php
/**
 * Daily database backup cron job
 * Creates compressed database backup
 */

require_once __DIR__ . '/../includes/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting database backup...\n";

$backup_dir = dirname(__DIR__) . '/backups/';
$date = date('Y-m-d');
$filename = "impact_academy_backup_{$date}.sql.gz";
$filepath = $backup_dir . $filename;

// Create backups directory if it doesn't exist
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Database credentials
$db_host = DB_HOST;
$db_user = DB_USER;
$db_pass = DB_PASS;
$db_name = DB_NAME;

// Create backup command
$command = "mysqldump --host={$db_host} --user={$db_user} --password={$db_pass} ";
$command .= "--single-transaction --routines --triggers {$db_name} | gzip > {$filepath}";

// Execute backup
system($command, $return_var);

if ($return_var === 0) {
    $file_size = filesize($filepath);
    $file_size_mb = round($file_size / 1048576, 2);
    
    echo "Backup created successfully: $filename ($file_size_mb MB)\n";
    
    // Delete backups older than 30 days
    $old_backups = glob($backup_dir . "impact_academy_backup_*.sql.gz");
    $deleted_count = 0;
    
    foreach ($old_backups as $old_backup) {
        $backup_age = time() - filemtime($old_backup);
        if ($backup_age > 2592000) { // 30 days in seconds
            if (unlink($old_backup)) {
                $deleted_count++;
                echo "Deleted old backup: " . basename($old_backup) . "\n";
            }
        }
    }
    
    echo "Cleaned up $deleted_count old backups\n";
    
    // Log completion
    logActivity("Database backup completed via cron: $filename ($file_size_mb MB)");
} else {
    echo "Backup failed with return code: $return_var\n";
    logActivity("Database backup failed via cron");
}

echo "[" . date('Y-m-d H:i:s') . "] Database backup completed.\n";
?>