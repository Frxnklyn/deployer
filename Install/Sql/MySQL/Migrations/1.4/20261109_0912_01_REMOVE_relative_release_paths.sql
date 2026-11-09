-- UP

-- This approved breaking change preserves the old value only as opaque rollback evidence.
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        CONCAT('ALTER TABLE `axxdep_host` CHANGE COLUMN `path_rel_to_releases` `trash_path_rel_to_releases` VARCHAR(100) NOT NULL DEFAULT ', QUOTE('')),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'axxdep_host'
        AND COLUMN_NAME = 'path_rel_to_releases'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Restore the legacy column name only when rolling the application back.
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        CONCAT('ALTER TABLE `axxdep_host` CHANGE COLUMN `trash_path_rel_to_releases` `path_rel_to_releases` VARCHAR(100) NOT NULL DEFAULT ', QUOTE('')),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'axxdep_host'
        AND COLUMN_NAME = 'trash_path_rel_to_releases'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
