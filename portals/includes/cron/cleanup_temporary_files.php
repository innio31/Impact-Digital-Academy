<?php
// cron/cleanup_temporary_files.php
/**
 * Weekly cron job to clean up temporary files
 * Removes old temporary uploads and expired files
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting temporary files cleanup...\n";

$temp_dir = dirname(__DIR__) . '/public/uploads/temp/';
$deleted_count = 0;

if (is_dir($temp_dir)) {
    $files = scandir($temp_dir);
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $filepath = $temp_dir . $file;
            $file_age = time() - filemtime($filepath);
            
            // Delete files older than 7 days (604800 seconds)
            if ($file_age > 604800) {
                if (unlink($filepath)) {
                    $deleted_count++;
                    echo "Deleted: $file (age: " . round($file_age/86400, 1) . " days)\n";
                }
            }
        }
    }
}

// Clean up old session files (if using file-based sessions)
$session_dir = session_save_path();
if ($session_dir && is_dir($session_dir)) {
    $session_files = glob($session_dir . '/sess_*');
    foreach ($session_files as $session_file) {
        $session_age = time() - filemtime($session_file);
        if ($session_age > 86400) { // 24 hours
            if (unlink($session_file)) {
                $deleted_count++;
            }
        }
    }
}

echo "Cleaned up $deleted_count temporary files\n";

// Log completion
logActivity("Temporary files cleanup completed via cron: $deleted_count files deleted");
echo "[" . date('Y-m-d H:i:s') . "] Temporary files cleanup completed.\n";
?>