/*
 * Store shared license configuration on the project.
 * Author: GitHub Copilot
 */
-- UP

-- Allow projects without a shared license configuration.
ALTER TABLE `axxdep_project`
    ADD COLUMN `licenses_config` LONGTEXT NULL;

-- DOWN

-- Preserve configured license terms when reverting the schema.
ALTER TABLE `axxdep_project`
    CHANGE COLUMN `licenses_config` `trash_licenses_config` LONGTEXT NULL;