-- UP

-- Restore rollback-preserved values instead of overwriting them on reinstallation.
SET @sql = (
    SELECT CASE
        WHEN SUM(COLUMN_NAME = 'prototype_file') > 0 THEN 'SELECT 1'
        WHEN SUM(COLUMN_NAME = 'trash_prototype_file') > 0 THEN
            'ALTER TABLE `axxdep_build_variant` CHANGE COLUMN `trash_prototype_file` `prototype_file` VARCHAR(512) NULL'
        ELSE 'ALTER TABLE `axxdep_build_variant` ADD COLUMN `prototype_file` VARCHAR(512) NULL'
    END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_build_variant'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN SUM(COLUMN_NAME = 'config_uxon') > 0 THEN 'SELECT 1'
        WHEN SUM(COLUMN_NAME = 'trash_config_uxon') > 0 THEN
            'ALTER TABLE `axxdep_build_variant` CHANGE COLUMN `trash_config_uxon` `config_uxon` LONGTEXT NULL'
        ELSE 'ALTER TABLE `axxdep_build_variant` ADD COLUMN `config_uxon` LONGTEXT NULL'
    END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_build_variant'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Keep saved pipeline settings, including any sensitive configuration, on rollback.
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `axxdep_build_variant` CHANGE COLUMN `prototype_file` `trash_prototype_file` VARCHAR(512) NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_build_variant' AND COLUMN_NAME = 'prototype_file'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `axxdep_build_variant` CHANGE COLUMN `config_uxon` `trash_config_uxon` LONGTEXT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'axxdep_build_variant' AND COLUMN_NAME = 'config_uxon'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
