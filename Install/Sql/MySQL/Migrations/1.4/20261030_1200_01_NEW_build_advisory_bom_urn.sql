/*
 * Distinguish affected package releases without duplicating advisories.
 *
 * Existing links retain their stored version, including unknown versions.
 * DOWN preserves populated URNs and refuses lossy evidence narrowing.
 *
 * @author GitHub Copilot
 */
-- UP

-- Binary comparison preserves case-sensitive version segments in BOM URNs.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `axxdep_build_advisory` ADD `bom_urn` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'bom_urn'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Normalize asset aliases exactly as the BOM does, without guessing versions.
UPDATE axxdep_build_advisory ba
INNER JOIN axxdep_advisory a ON a.oid = ba.advisory_id
SET ba.bom_urn = CONCAT(
    LOWER(a.ecosystem), ':',
    CASE
        WHEN a.ecosystem = 'npm' AND LEFT(a.package, 10) = 'npm-asset/'
            THEN CASE WHEN LOCATE('--', SUBSTRING(a.package, 11)) > 0
                THEN CONCAT('@', SUBSTRING_INDEX(SUBSTRING(a.package, 11), '--', 1), '/',
                    SUBSTRING(SUBSTRING(a.package, 11), LOCATE('--', SUBSTRING(a.package, 11)) + 2))
                ELSE SUBSTRING(a.package, 11) END
        WHEN a.ecosystem = 'bower' AND LEFT(a.package, 12) = 'bower-asset/'
            THEN SUBSTRING(a.package, 13)
        ELSE COALESCE(a.package, '')
    END,
    ':', COALESCE(ba.version, '')
)
WHERE ba.bom_urn IS NULL;

-- Shared evidence can contain identifiers and fixes from multiple releases.
ALTER TABLE axxdep_advisory
    MODIFY public_id TEXT NULL,
    MODIFY version_fixed TEXT NULL;

-- DOWN

-- Refuse truncation rather than silently losing aggregated advisory evidence.
SET @previous_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@previous_sql_mode, ''), 'STRICT_ALL_TABLES');
ALTER TABLE axxdep_advisory
    MODIFY public_id VARCHAR(50) NULL,
    MODIFY version_fixed VARCHAR(50) NULL;
SET SESSION sql_mode = @previous_sql_mode;

-- Preserve populated URNs if this migration is removed.
SET @populated = 0;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'SELECT COUNT(*) INTO @populated FROM `axxdep_build_advisory` WHERE `bom_urn` IS NOT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'bom_urn'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        IF(@populated > 0,
            'ALTER TABLE `axxdep_build_advisory` CHANGE `bom_urn` `trash_bom_urn` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            'ALTER TABLE `axxdep_build_advisory` DROP COLUMN `bom_urn`'),
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'axxdep_build_advisory' AND COLUMN_NAME = 'bom_urn'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;