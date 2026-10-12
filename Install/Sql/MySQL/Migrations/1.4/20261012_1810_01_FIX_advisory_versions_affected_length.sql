/*
 * Preserve affected-version evidence up to 500 characters.
 *
 * Rollback refuses longer values rather than truncating advisory evidence.
 *
 * @author GitHub Copilot
 */
-- UP

-- Allow combined affected-version ranges without truncating them at 100.
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `versions_affected` VARCHAR(500) DEFAULT NULL;

-- DOWN

-- Force strict conversion so rollback cannot truncate longer version ranges.
SET @advisory_versions_affected_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `versions_affected` VARCHAR(100) DEFAULT NULL;
SET SESSION sql_mode = @advisory_versions_affected_sql_mode;