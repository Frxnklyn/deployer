/*
 * Preserve the full advisory severity range and names up to 500 characters.
 *
 * Signed TINYINT clamps levels above 127 in non-strict SQL mode.
 * Names longer than 100 characters lose their duplicate-comparison identity.
 * Existing truncated values must be recovered by re-auditing the builds.
 * Rollback refuses oversized values rather than truncating evidence.
 *
 * @author GitHub Copilot
 */
-- UP

-- Allow normalized vulnerability levels and longer advisory names unchanged.
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `level` smallint NOT NULL,
    MODIFY COLUMN `name` varchar(500) NOT NULL;

-- DOWN

-- Force strict conversion so rollback cannot truncate levels or names.
SET @advisory_level_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `level` tinyint NOT NULL,
    MODIFY COLUMN `name` varchar(100) NOT NULL;
SET SESSION sql_mode = @advisory_level_sql_mode;