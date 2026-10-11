/*
 * Preserve the full advisory severity range from 100 through 400.
 *
 * Signed TINYINT clamps levels above 127 in non-strict SQL mode.
 * Existing clamped values must be recovered by re-auditing the builds.
 * Rollback refuses out-of-range values rather than truncating evidence.
 *
 * @author GitHub Copilot
 */
-- UP

-- Allow all normalized vulnerability levels to be stored unchanged.
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `level` smallint NOT NULL;

-- DOWN

-- Force strict conversion so rollback cannot silently corrupt severity data.
SET @advisory_level_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');
ALTER TABLE `axxdep_advisory`
    MODIFY COLUMN `level` tinyint NOT NULL;
SET SESSION sql_mode = @advisory_level_sql_mode;