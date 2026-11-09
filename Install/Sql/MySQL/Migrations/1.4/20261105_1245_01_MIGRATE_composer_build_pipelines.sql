-- UP

-- This view is also the preflight report: it contains identifiers/reasons, never credentials.
CREATE OR REPLACE ALGORITHM=TEMPTABLE VIEW `axxdep_build_pipeline_migration` AS
SELECT
    bv.oid,
    bv.project_oid,
    p.build_recipe AS legacy_recipe,
    CASE
        WHEN TRIM(COALESCE(bv.prototype_file, '')) <> '' THEN 'ALREADY_SELECTED'
        WHEN p.build_recipe NOT IN ('ComposerInstall', 'ComposerInstallAssetFix') THEN 'MANUAL_REPLACEMENT_REQUIRED'
        WHEN TRIM(COALESCE(bv.config_uxon, '')) NOT IN ('', '{}') THEN 'TARGET_CONFIG_CONFLICT'
        WHEN JSON_VALID(bv.composer_json) = 0 THEN 'INVALID_COMPOSER_JSON'
        WHEN JSON_TYPE(CAST(bv.composer_json AS JSON)) <> 'OBJECT' THEN 'INVALID_COMPOSER_JSON'
        WHEN TRIM(COALESCE(bv.composer_auth_json, '')) = '' THEN 'READY'
        WHEN JSON_VALID(bv.composer_auth_json) = 0 THEN 'INVALID_COMPOSER_AUTH'
        WHEN JSON_TYPE(CAST(bv.composer_auth_json AS JSON)) <> 'OBJECT' THEN 'INVALID_COMPOSER_AUTH'
        ELSE 'READY'
    END AS migration_status
FROM axxdep_build_variant bv
INNER JOIN axxdep_project p ON p.oid = bv.project_oid;

-- Durable rollback evidence for rows actually converted; the legacy columns stay intact.
-- These values can contain authentication: retain the same restricted DB access as build variants.
CREATE TABLE IF NOT EXISTS `axxdep_build_pipeline_migration_backup` (
    variant_oid BINARY(16) NOT NULL PRIMARY KEY,
    original_prototype_file VARCHAR(512) NULL,
    original_config_uxon LONGTEXT NULL,
    migrated_config_uxon LONGTEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO axxdep_build_pipeline_migration_backup (
    variant_oid, original_prototype_file, original_config_uxon, migrated_config_uxon
)
SELECT
    bv.oid,
    bv.prototype_file,
    bv.config_uxon,
    JSON_MERGE_PATCH(
        IF(JSON_LENGTH(CAST(bv.composer_json AS JSON)) = 0, JSON_OBJECT(),
            JSON_OBJECT('composer_json', CAST(bv.composer_json AS JSON))),
        IF(JSON_LENGTH(CAST(IF(TRIM(COALESCE(bv.composer_auth_json, '')) = '', '{}', bv.composer_auth_json) AS JSON)) = 0,
            JSON_OBJECT(), JSON_OBJECT('composer_auth', CAST(bv.composer_auth_json AS JSON))),
        IF(m.legacy_recipe = 'ComposerInstallAssetFix',
            JSON_OBJECT('asset_packagist_cache_fix', CAST('true' AS JSON)), JSON_OBJECT())
    )
FROM axxdep_build_variant bv
INNER JOIN axxdep_build_pipeline_migration m ON m.oid = bv.oid
LEFT JOIN axxdep_build_pipeline_migration_backup backup ON backup.variant_oid = bv.oid
WHERE m.migration_status = 'READY' AND backup.variant_oid IS NULL;

-- Compare original values as well as eligibility: a rerun never overwrites later designer edits.
UPDATE axxdep_build_variant bv
INNER JOIN axxdep_build_pipeline_migration m ON m.oid = bv.oid
INNER JOIN axxdep_build_pipeline_migration_backup backup ON backup.variant_oid = bv.oid
SET bv.prototype_file = 'axenox/deployer/Pipelines/Build/ComposerInstall.php',
    bv.config_uxon = backup.migrated_config_uxon
WHERE m.migration_status = 'READY'
    AND bv.prototype_file <=> backup.original_prototype_file
    AND bv.config_uxon <=> backup.original_config_uxon;

-- Report unresolved rows explicitly; do not silently map clone/custom/unknown workflows.
SELECT HEX(oid) AS variant_uid, HEX(project_oid) AS project_uid, legacy_recipe, migration_status
FROM axxdep_build_pipeline_migration
WHERE migration_status <> 'ALREADY_SELECTED';

-- DOWN

-- Restore only unchanged migration output; preserve any subsequently edited native configuration.
UPDATE axxdep_build_variant bv
INNER JOIN axxdep_build_pipeline_migration_backup backup ON backup.variant_oid = bv.oid
SET bv.prototype_file = backup.original_prototype_file,
    bv.config_uxon = backup.original_config_uxon
WHERE bv.prototype_file = 'axenox/deployer/Pipelines/Build/ComposerInstall.php'
    AND bv.config_uxon <=> backup.migrated_config_uxon;

-- Keep backup evidence for repeatable re-upgrade and future operator recovery.
DROP VIEW IF EXISTS axxdep_build_pipeline_migration;
