/*
 * Save build package inventories and build-specific installed versions.
 *
 * DOWN preserves populated columns with a trash_ prefix.
 *
 * @author GitHub Copilot
 */
-- UP

-- Archive the minimal package inventory, including non-Composer packages.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `axxdep_build` ADD `package_bom` LONGTEXT COLLATE utf8mb3_general_ci NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build' AND COLUMN_NAME = 'package_bom'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Installed versions belong to the build link, not the shared advisory.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `axxdep_build_advisory` ADD `version` VARCHAR(100) NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'version'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

-- Preserve archived inventories if this migration is removed.
SET @populated = 0;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'SELECT COUNT(*) INTO @populated FROM `axxdep_build` WHERE `package_bom` IS NOT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build' AND COLUMN_NAME = 'package_bom'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        IF(@populated > 0,
            'ALTER TABLE `axxdep_build` CHANGE `package_bom` `trash_package_bom` LONGTEXT COLLATE utf8mb3_general_ci NULL',
            'ALTER TABLE `axxdep_build` DROP COLUMN `package_bom`'),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build' AND COLUMN_NAME = 'package_bom'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Preserve installed-version evidence if links already contain it.
SET @populated = 0;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'SELECT COUNT(*) INTO @populated FROM `axxdep_build_advisory` WHERE `version` IS NOT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'version'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        IF(@populated > 0,
            'ALTER TABLE `axxdep_build_advisory` CHANGE `version` `trash_version` VARCHAR(100) NULL',
            'ALTER TABLE `axxdep_build_advisory` DROP COLUMN `version`'),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'version'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;