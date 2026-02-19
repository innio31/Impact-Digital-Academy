-- Safe update script for notifications table
-- Checks for existing columns and indexes before adding

-- 1. Add sender_id column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'sender_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `sender_id` INT(11) DEFAULT NULL AFTER `user_id`',
    'SELECT "Column sender_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add related_id column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'related_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `related_id` INT(11) DEFAULT NULL AFTER `type`',
    'SELECT "Column related_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add read_at column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'read_at'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `read_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_read`',
    'SELECT "Column read_at already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add action_url column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'action_url'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `action_url` VARCHAR(255) DEFAULT NULL AFTER `related_id`',
    'SELECT "Column action_url already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Add priority column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'priority'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `priority` ENUM(\'low\', \'normal\', \'high\') DEFAULT \'normal\' AFTER `action_url`',
    'SELECT "Column priority already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Add data column if it doesn't exist
SET @column_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'data'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `notifications` ADD COLUMN `data` TEXT DEFAULT NULL AFTER `priority`',
    'SELECT "Column data already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Check and add idx_related_id index if it doesn't exist
SET @index_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND INDEX_NAME = 'idx_related_id'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_related_id` (`related_id`)',
    'SELECT "Index idx_related_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 8. Check and add idx_sender_id index if it doesn't exist
SET @index_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND INDEX_NAME = 'idx_sender_id'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_sender_id` (`sender_id`)',
    'SELECT "Index idx_sender_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 9. Check and add idx_read_at index if it doesn't exist
SET @index_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND INDEX_NAME = 'idx_read_at'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_read_at` (`read_at`)',
    'SELECT "Index idx_read_at already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 10. Update existing notifications to set read_at for already read notifications
UPDATE `notifications` 
SET `read_at` = NOW() 
WHERE `is_read` = 1 AND `read_at` IS NULL;

-- 11. Add composite index for better query performance (check if exists first)
SET @index_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND INDEX_NAME = 'idx_user_read'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_user_read` (`user_id`, `is_read`, `created_at`)',
    'SELECT "Index idx_user_read already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Display table structure after update
DESCRIBE notifications;

-- Show indexes after update
SHOW INDEX FROM notifications;

-- Show summary of changes
SELECT 
    'Table updated successfully' as message,
    (SELECT COUNT(*) FROM notifications) as total_notifications,
    (SELECT COUNT(*) FROM notifications WHERE is_read = 0) as unread_notifications;