-- Keep the latest successful deployments on hosts still in service.
CREATE OR REPLACE VIEW axxdep_currently_deployed AS
SELECT d1.*
FROM axxdep_deployment d1
INNER JOIN axxdep_host h1
    ON h1.oid = d1.host_oid
    AND (
        h1.decommissioned_on IS NULL
        OR h1.decommissioned_on > CURRENT_TIMESTAMP
    )
LEFT JOIN axxdep_deployment d2
    ON d1.host_oid = d2.host_oid
    AND d2.`status` = 99
    AND d1.started_on < d2.started_on
WHERE d1.`status` = 99
    AND d2.oid IS NULL;