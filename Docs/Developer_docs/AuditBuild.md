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

Legacy recipes (`Recipes/Build.php`, run through `vendor/bin/dep`) and the native
Composer build prototype (`Pipelines/Build/ComposerInstall.php`) share one capture helper,
`Common/Pipelines/ComposerBuildBom`, built on the installed PackageManager BOM API
(`BOMPackage` array constructor, `LicenseBOM::setLicenses()`/`addPackage()` and the
local enrichers). `Build::buildRecipeBom()` delegates to `ComposerBuildBom::captureInstallation()`;
the native prototype additionally applies its archive entry policy and requires `composer.lock`.
A legacy trace ending in `Recipes\Build.php` and `Build::buildRecipeBom()` is therefore the
legacy recipe path, not a selected native pipeline.

While the vendor folder still exists, the capture merges, in order: an existing
`vendor/licenses.json`, every locked production and development package, each installed
package's `includes.json`, optional `config/Licenses.config.json` and the project's
`licenses_config` captured at build start (`project_licenses_config` in recipes; custom
recipes can pass `get('project_licenses_config', null)` as the second argument). Locked
names/versions/sources are then re-applied, so configuration can select licenses or terms
but cannot change installed versions. Local `package.json` declarations, license files and
texts are enriched afterwards, including when configuration is absent or blank.
Configuration may be a package list or an object with `packages` and shared `licenses`,
either keyed by license name or a list of named definitions. Legacy `license_text`/
`license_file` properties and named definitions with `text`/`file` are supported.
Configured files are resolved relative to `vendor`, even for packages without an installed
directory; a missing selected file cannot borrow a different license's generic terms.
Alternative license declarations and terms survive selection overrides. Result-only
`source` and `text_available` properties are rejected in project configuration; recorded
inventory evidence is preserved without inventing text.
Unnamed packages, invalid JSON or a project without a vendor folder and
either `composer.lock` or `vendor/licenses.json` fail the build rather than producing an empty BOM.
No remote license queries or installation Workbench are needed. Later audits use the saved
snapshot; changing project settings does not rewrite historical builds. Host-specific overrides
remain deployment-time configuration.

Both recipes stage the full inventory as `package_bom.json` beside the project's
`composer.lock`. After the recipe finishes, the Build action rebuilds it with
`ComposerBuildBom::fromInventory()` (the installed `LicenseBOM` has no data constructor) and
stores a compact saved-build snapshot through `ComposerBuildBom::buildSnapshot()`.
This preserves the legacy saved-build shape independently of the installed JsonBOM's
identity-only `minimal` format (which the native prototype uses):
```json
{
    "packages": [
        {
            "name": "example/library",
            "version": "v1.2.3",
            "ecosystem": "composer",
            "urn": "composer:example/library:v1.2.3",
            "source": {"type": "git", "reference": "abc123"},
            "license_used": "MIT",
            "licenses": [{"name": "MIT", "source": "local", "text_available": true}]
        },
        {
            "name": "example-widget",
            "version": "2.0.0",
            "ecosystem": "npm",
            "urn": "npm:example-widget:2.0.0",
            "bundled_in": "composer:example/library:v1.2.3",
            "licenses": []
        }
    ]
}
```
Composer asset aliases become native npm/Bower names; versions remain unchanged and
unversioned packages are not given an invented version. Git sources retain `source.type`
and `source.reference` so OSV can query locked commits. URNs retain an empty version
segment for unversioned packages, and bundled packages retain their owner's locked URN.
License selections and per-license availability/source evidence remain in the compact
value; inline terms and repository URLs are omitted. The installed PackageManager's
LicenseScanner requires the full inventory with actual `license_text`, not merely
`text_available` evidence. Availability flags must not be substituted with fabricated
terms to make a license audit pass.

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
Its required `bom_urn` stores a supplied nonempty `BOM_URN`. Current PackageManager
Audit output does not include that column, so the action derives the same normalized
`ecosystem:package:version` identity from `ECOSYSTEM`, `PACKAGE` and
`VERSION_INSTALLED` using the shared `ComposerBuildBom::packageUrn()` helper.
The final segment remains empty for unknown versions. Version spelling and case
are preserved, so unknown, known and case-distinct versions retain separate assignments.
Installed versions are build-specific and are not copied to shared advisories.
Scanner provenance is not persisted. A zero-finding audit creates no records. Scanner hints remain visible:
zero findings alone do not prove that all scanners completed.

PHP HTTP scanners share a verified Windows-native certificate trust policy when
no administrator CA configuration exists. See [audit certificate troubleshooting](../Troubleshooting_build.md#post-build-audit-certificate-errors-on-windows).
The saved native build `4.1.1+20261105152529` was re-audited on 2026-11-05:
205 advisory links persisted and were read back with valid nonempty BOM identities;
build status stayed 99. npm and OSV completed, while Composer audit was skipped
because a suitable Composer executable was not on PATH. Trivy was not applicable.
This verifies transport and persistence, not license completeness or a clean
security assessment. The original build archive and log were not rewritten.

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