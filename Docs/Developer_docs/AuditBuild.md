# Auditing Saved Builds

Use `axenox.Deployer.AuditBuild` on one or more `axenox.Deployer.build` rows
to collect advisories for existing builds, including already deployed builds.
The action scans each build's saved `composer_lock` and `package_bom`, not the current installation.
No deployment status filter is applied: the caller selects the builds to audit.

`axenox.Deployer.Build` automatically calls this action for the newly created build
after a successful build and the final save of its `composer_lock` and `package_bom`. Failed builds
are not audited. The audit uses its own action transaction and normal authorization.
Its summary or error is streamed to the caller and appended to the build log.
Audit errors are also recorded in the workbench log; the build remains successful.

For example, add this button to a build table:

```json
{
    "widget_type": "Button",
    "caption": "Audit builds",
    "action_alias": "axenox.Deployer.AuditBuild"
}
```

Input must contain build UIDs. A DataCollector reads missing `composer_lock` and
`package_bom` values. Builds with neither artifact are skipped; either artifact
can be scanned on its own. Malformed artifacts and scanner failures propagate as
errors; they do not trigger an audit of
the current installation instead.

## Package Snapshot

The Composer recipe captures the built project's `vendor/licenses.json` before
removing its temporary vendor folder. The local-clone recipe captures the local
inventory included in its archive. Both recipes copy the original JSON unchanged
to `package_bom.json` beside the project's `composer.lock`.

After the recipe finishes, the Build action reads the staged inventory and uses
JsonBOM's `minimal` format for the database value:
`{"packages":[{"name":"example/library","version":"v1.2.3","ecosystem":"composer"}]}`.
Ecosystems are lowercase and Composer asset aliases become native npm/Bower names;
versions remain unchanged. Git sources also retain `source.type` and `source.reference`,
allowing OSV to query locked commits from the BOM alone, including Bower packages.
Repository URLs, license texts and other unrelated metadata are omitted.
Unversioned packages remain in the inventory without an invented version.

Like `composer.lock`, the staged BOM is read and saved by Build, not by the recipe.
Build saves the converted JSON in `package_bom` and removes the staged file during
cleanup. Stale staged files are removed before the recipe starts. Older projects
without a generated `vendor/licenses.json` retain lock-only auditing. Custom
recipes must copy their license inventory to `package_bom.json`; they do not need
to reduce it to minimal JSON or access the database. Existing builds
are not backfilled from the current installation.

## Persistence

For each build, the action calls `axenox.PackageManager.Audit` through the normal
action lifecycle with a fresh task containing only that build's saved artifacts.
The package BOM is passed as Audit's `sbom` parameter. Its findings
are saved through DataSheets as `axenox.Deployer.advisory` records. Their persisted
UIDs are linked to the build using `build` and `advisory` on
`axenox.Deployer.build_advisory`. Both writes share the action's transaction.
The returned DataSheet contains the persisted build-advisory links; the result
message contains the summary. The action logbook contains each build's scanner
status, hints and findings table, followed by the summary.

The advisory mapping preserves `level`, `name`, `type`, `package`, `ecosystem`, `details_url`,
`description`, `remediation`, `versions_affected`, `version_fixed` and `public_id`.
Audit's deterministic `ID` is not used as a database UID. Each link's `version`
stores the finding's `VERSION_INSTALLED`, including an empty value when unknown.
Installed versions are build-specific and are not copied to shared advisories.
Scanner provenance is not persisted. A zero-finding audit creates no records. Scanner hints remain visible:
zero findings alone do not prove that all scanners completed.

`ecosystem` is required and uses `axenox.PackageManager.PackageEcosystem`.
The migration creates a non-nullable column with a `composer` default and
backfills existing advisories as Composer, the only previously supported ecosystem.
New findings retain their lowercase registry identity. OS packages and other
unestablished registries explicitly use `unknown`, not an inferred Composer identity.

There is no custom deduplication or deletion of previous findings. Repeated audits
create new advisories and links until deduplication is configured in the model.
A future `PreventDuplicatesBehavior` on advisories should use duplicate **update**
handling so every submitted finding returns its existing or newly created UID.
Its matching criteria must include `ecosystem` as well as `package` and advisory identity;
package names are not unique across registries.
Duplicate **ignore** handling that drops rows cannot supply all required links.
Deduplicating build links can be configured separately on the build/advisory pair.
Previously saved links remain even when a later scan no longer reports a finding.

## Currently Deployed Advisories

The Security Advisories table uses the read-only `axenox.Deployer.advisory_stats`
object backed by `axxdep_advisory_stats`. Each row represents one advisory and
deployment stage. The `advisory` relation uses 1-to-1 cardinality and the physical
column `advisory_oid`; `stage` relates `stage_oid` to the deployment stage.

`count_hosts` counts distinct affected hosts. `seen_first_on` is the minimum
`created_on` and `seen_last_on` the maximum `modified_on` of their active
deployments. `versions` lists distinct installed package versions from the
build-advisory links, comma-separated and sorted. Historical versions that are
no longer deployed are excluded. Opening a row shows the related advisory.

Both this view and `axenox.Deployer.currently_deployed` use the SQL view
`axxdep_currently_deployed`: the latest successful deployment per host, excluding
hosts whose `decommissioned_on` is at or before the current database timestamp.
The static SQL files in `Install/Sql/MySQL/Views` recreate both views in dependency
order on every installation. The statistics object does not inherit the data
source's base object because the grouped view has no deployment UID or audit
columns.

## Prerequisites

PackageManager must be installed, and the advisory objects and their backing
tables must be installed and writable. Scanner configuration, executable and
network requirements follow the [PackageManager audit guide](../../packagemanager/Docs/Audit.md).
AuditBuild never installs scanner prerequisites automatically. Normal action
authorization and DataSheet behaviors apply.

## Tests

From the containing installation:

```console
php vendor/bin/phpunit -c vendor/axenox/deployer/phpunit.xml.dist --testsuite unit
```

Standalone installations use `php vendor/bin/phpunit -c phpunit.xml.dist`.
Unit tests cover minimal package snapshots, prototype defaults, and model-aligned
advisory and installed-version link mappings without a database or mocked Workbench.
Application-level validation requires a persisted
metamodel and backing tables: select multiple builds, audit them, verify each
advisory link, then repeat with the desired duplicate behavior. Also check empty
locks, clean scans, scanner failure and transaction rollback in that environment.