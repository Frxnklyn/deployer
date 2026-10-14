/*
 * New column to set a date when a host was decommissioned
 *
 * @author Ralf Mulansky
 */
-- UP

ALTER TABLE `axxdep_host`
ADD `decommissioned_on` date NULL;

-- DOWN
-- do not delete the column to not delete data
