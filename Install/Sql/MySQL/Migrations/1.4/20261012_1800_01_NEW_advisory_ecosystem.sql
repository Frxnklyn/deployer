/*
 * Distinguish advisory packages from different ecosystems.
 *
 * Existing advisories belong to the historical Composer ecosystem.
 * DOWN preserves populated ecosystem evidence with a trash_ prefix.
 *
 * @author GitHub Copilot
 */
-- UP

-- Store the lowercase registry identity separately from the package name.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `axxdep_advisory` ADD `ecosystem` VARCHAR(50) NOT NULL DEFAULT ''composer''',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_advisory' AND COLUMN_NAME = 'ecosystem'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fill missing values if an earlier nullable version was already installed.
UPDATE `axxdep_advisory`
SET `ecosystem` = 'composer'
WHERE `ecosystem` IS NULL OR TRIM(`ecosystem`) = '';

-- Keep required identity and the legacy default consistent on repeated runs.
ALTER TABLE `axxdep_advisory`
    MODIFY `ecosystem` VARCHAR(50) NOT NULL DEFAULT 'composer';

-- DOWN

-- Never discard ecosystem evidence from previously audited builds.
SET @populated = 0;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'SELECT COUNT(*) INTO @populated FROM `axxdep_advisory` WHERE `ecosystem` IS NOT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_advisory' AND COLUMN_NAME = 'ecosystem'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        IF(@populated > 0,
            'ALTER TABLE `axxdep_advisory` CHANGE `ecosystem` `trash_ecosystem` VARCHAR(50) NULL',
            'ALTER TABLE `axxdep_advisory` DROP COLUMN `ecosystem`'),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_advisory' AND COLUMN_NAME = 'ecosystem'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;