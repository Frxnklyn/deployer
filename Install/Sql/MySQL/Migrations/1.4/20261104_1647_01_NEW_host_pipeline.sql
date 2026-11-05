-- UP

-- Additive opt-in only: no legacy selections, settings or relative paths are migrated.
SET @sql = (
    SELECT CASE
        WHEN SUM(COLUMN_NAME = 'prototype_file') > 0 THEN 'SELECT 1'
        WHEN SUM(COLUMN_NAME = 'trash_prototype_file') > 0 THEN
            'ALTER TABLE `axxdep_host` CHANGE COLUMN `trash_prototype_file` `prototype_file` VARCHAR(512) NULL'
        ELSE 'ALTER TABLE `axxdep_host` ADD COLUMN `prototype_file` VARCHAR(512) NULL'
    END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_host'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Preserve opt-in choices on rollback; deploy_config remains the only settings container.
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `axxdep_host` CHANGE COLUMN `prototype_file` `trash_prototype_file` VARCHAR(512) NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_host' AND COLUMN_NAME = 'prototype_file'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
