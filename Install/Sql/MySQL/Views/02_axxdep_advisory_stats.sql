-- Summarize exposure using only currently active deployments per stage.
CREATE OR REPLACE VIEW axxdep_advisory_stats AS
SELECT
    ba.advisory_id AS advisory_oid,
    h.stage_oid,
    COUNT(DISTINCT d.host_oid) AS count_hosts,
    MIN(d.created_on) AS seen_first_on,
    MAX(d.modified_on) AS seen_last_on,
    GROUP_CONCAT(DISTINCT ba.version ORDER BY ba.version SEPARATOR ', ') AS versions
FROM axxdep_currently_deployed d
INNER JOIN axxdep_host h ON h.oid = d.host_oid
INNER JOIN axxdep_build_advisory ba ON ba.build_id = d.build_oid
GROUP BY ba.advisory_id, h.stage_oid;