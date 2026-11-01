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

## Scanner Configuration

Deployer defines two independent scanner maps in `Config/axenox.Deployer.config.json`:

- `AUDIT_BUILD.SCANNERS`: used by standalone `AuditBuild` calls, including regular background scans.
- `BUILD.AUDIT.SCANNERS`: passed by `Build` to its audit immediately after a successful build.

Both maps include license, Composer advisory, npm advisory, OSV and Trivy scanners by default.
Only the post-build map additionally includes `PackagistOutdatedScanner` and
`NpmOutdatedScanner`. These report packages behind the latest registry release even though
the build just fetched its dependencies. Regular scans omit these checks because missing
updates are expected for existing installations and saved builds.

Each map uses PackageManager's named scanner configuration format: an entry contains
`class` plus any scanner-specific options. These maps replace, rather than extend,
PackageManager's `AUDIT.SCANNERS`; changes to that option do not affect Deployer scans.
An explicit `scanners` property on `AuditBuild` replaces `AUDIT_BUILD.SCANNERS` for that
action instance. An empty map disables all scanners. Customize either Deployer map
through the installation's Deployer configuration overrides.

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

The Composer recipe reuses the built project's `vendor/licenses.json` when present.
Only when that file is absent does it generate the inventory from `composer.lock`,
the packages' `includes.json` files and optional `config/Licenses.config.json` before
removing its temporary vendor folder. The local-clone recipe uses the same precedence
for the local installation included in its archive. Both recipes stage the inventory
as `package_bom.json` beside the project's `composer.lock`. Existing inventories are
read without modification; their presence bypasses manifest generation.

Fallback generation does not depend on Composer installation hooks,
database installation or remote license-text discovery. It recovers missing declarations
from installed `package.json` manifests, then reads local license files before vendor cleanup.
Native npm/Bower names are also checked under Asset Packagist installation paths. Bundled
packages without a standalone directory remain in the inventory without filesystem warnings.
Previously, the fallback collected declarations but did not discover installed terms, causing
most packages to be marked as missing text even when their license files were present.
Also, a missing generated
license file silently left `package_bom` empty even when the build succeeded.
Bundled dependencies retain the owning Composer package's locked URN.

After the recipe finishes, the Build action reads the staged inventory and uses
JsonBOM's `minimal` format for the database value:
```json
{
    "packages": [
        {
            "name": "example/library",
            "version": "v1.2.3",
            "ecosystem": "composer",
            "urn": "composer:example/library:v1.2.3",
            "license": ["MIT"],
            "license_used": "MIT",
            "license_text_source": {"MIT": "embedded"}
        },
        {
            "name": "example-widget",
            "version": "2.0.0",
            "ecosystem": "npm",
            "urn": "npm:example-widget:2.0.0",
            "bundled_in": "composer:example/library:v1.2.3",
            "license": ["MIT"],
            "license_used": "MIT",
            "license_text_source": {"MIT": "missing"}
        }
    ]
}
```
Ecosystems are lowercase and Composer asset aliases become native npm/Bower names;
versions remain unchanged. Multiple installed versions have separate URNs. Bundle ownership
is retained as `bundled_in`, not inferred from a versionless supplement.
Git sources also retain `source.type` and `source.reference`,
allowing OSV to query locked commits from the BOM alone, including Bower packages.
Repository URLs, license texts and other unrelated metadata are omitted.
License declarations and effective selections are retained. `license_text_source` uses
the constants `embedded`, `local`, `link` and `missing` described in
[PackageManager's JSON format reference](../../../packagemanager/Docs/License_management/Generated_license_BOM.md#json-export-format).
Shared definitions in the staged full inventory are resolved before compaction; their
source evidence survives, but their license text is not stored in the compact value.
Unversioned packages remain in the inventory without an invented version.

Like `composer.lock`, the staged BOM is read and saved by Build, not by the recipe.
Build saves the converted JSON in `package_bom` and removes the staged file during
cleanup. Stale staged files are removed before the recipe starts. Custom
recipes must copy their license inventory to `package_bom.json`; they do not need
to reduce it to minimal JSON or access the database. Existing builds
are not backfilled from the current installation.

## Persistence

For each build, the action calls `axenox.PackageManager.Audit` through the normal
action lifecycle with a fresh task containing only that build's saved artifacts.
The package BOM is passed as Audit's `sbom` parameter. Its findings
are saved through DataSheets as `axenox.Deployer.advisory` records. Their persisted
UIDs are linked to the build using `build` and `advisory` on
`axenox.Deployer.build_advisory`, with one assignment per `bom_urn`.
Both writes share the action's transaction.
The returned DataSheet contains the persisted build-advisory links; the result
message contains the summary. The action logbook contains each build's scanner
status, hints and findings table, followed by the summary.

The advisory mapping preserves `level`, `name`, `type`, `package`, `ecosystem`, `details_url`,
`description`, `remediation`, `versions_affected`, `version_fixed` and `public_id`.
Audit's deterministic `ID` is not used as a database UID. Each link's `version`
stores the finding's `VERSION_INSTALLED`, including an empty value when unknown.
Its required `bom_urn` stores `BOM_URN`: the normalized `ecosystem:package:version`
identity, with an empty final segment for unknown versions. Version spelling and case
are preserved, so unknown, known and case-distinct versions retain separate assignments.
Installed versions are build-specific and are not copied to shared advisories.
Scanner provenance is not persisted. A zero-finding audit creates no records. Scanner hints remain visible:
zero findings alone do not prove that all scanners completed.

`ecosystem` is required and uses `axenox.PackageManager.PackageEcosystem`.
The migration creates a non-nullable column with a `composer` default and
backfills existing advisories as Composer, the only previously supported ecosystem.
New findings retain their lowercase registry identity. OS packages and other
unestablished registries explicitly use `unknown`, not an inferred Composer identity.

Advisory uniqueness belongs to the advisory model's `PreventDuplicatesBehavior`.
Its current comparison attributes are **name, package, ecosystem and type**. The action
does not mirror that key, group findings by it or perform its own duplicate lookup.
Change the model behavior's comparison attributes to change advisory uniqueness without
changing AuditBuild. Any normalization needed for matching also belongs to that policy,
not an additional lookup in the action. PackageManager's existing cross-source and
title-based merging remains unchanged.

Each finding is saved individually through a DataSheet. The duplicate behavior creates
an advisory or updates a matching one with the supplied contents, returning its persisted
UID in either case. Descriptions, remediation, severity, version ranges, fixes, public IDs
and URLs are current advisory contents, not an accumulating historical record. When several
processed findings match the same advisory, the last processed finding supplies its contents.
A later audit can therefore replace descriptions or lower a previously reported severity.

AuditBuild uses each returned advisory UID directly to save that finding's build link;
there is no separate advisory-key-to-UID map. The link model's current duplicate **update**
criteria are **build, advisory and bom_urn**. Links are also saved individually to avoid
the framework batch matcher's case-insensitive comparison; the database's binary URN
column distinguishes version case during repeat detection. The action returns each
persisted link UID only once, without reimplementing the link model's uniqueness key.
Duplicate handling must return UIDs, so use **update**, not **ignore**, for these models.
Previously saved links remain even when a later scan no longer reports a finding.

The migration backfills URNs from each existing link's stored version and related
advisory package/ecosystem, normalizing asset aliases without inventing a version.
It cannot recover versions already lost through earlier build/advisory-only duplicate
updates. Re-audit the saved artifacts to restore those assignments. Archived BOMs
are not reconstructed from the current installation. Public IDs and fixes use text
columns to accommodate scanner results containing several values. Downgrading refuses
to truncate these contents and preserves populated URNs as `trash_bom_urn`.

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
advisory and per-URN link mappings without a database or mocked Workbench.

The opt-in integration checks require an installed Deployer model, writable MySQL
tables and at least one existing build. From the containing installation, in PowerShell:

```powershell
$env:EXFACE_DEPLOYER_PERSISTENCE_TEST = '1'
php vendor/bin/phpunit -c vendor/axenox/deployer/phpunit.xml.dist --testsuite integration --filter AuditBuildPersistenceTest
Remove-Item Env:EXFACE_DEPLOYER_PERSISTENCE_TEST
```

The persistence check uses randomized advisory names, never changes the selected
build and rolls back all fixture writes. It checks multiple versions, unknown versions,
case-distinct versions, separate ecosystems, replacement contents and stable UIDs on repeat
audits. It also changes the advisory behavior's comparison attributes in memory to verify
that the same action follows a different uniqueness policy without persisting that model
change. The migration check creates and removes randomly named isolated fixture tables;
its database account needs DDL permission. Each test runs in a separate process to isolate
Workbench global state. No scanners are called by these checks.

Full application validation still requires selecting multiple builds and auditing their
saved artifacts, including clean scans, scanner failures and action transaction rollback.