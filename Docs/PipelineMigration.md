# Native pipeline migration and cutover

Build and Deploy no longer dispatch recipe tasks: a missing pipeline selection
fails explicitly with migration instructions.
Do not delete packages from the shared application lock/vendor tree manually.
Existing builds and deployment history must remain readable. Fixture tests do
not apply these conversions to live app records. The local development instance
was separately upgraded through normal InstallApp on 2026-11-05 after a private
database/configuration backup; its converted selections, configuration and
history were verified as recorded in [TASK-005](Roadmap/Plan.md).
This is not target deployment or evidence that other installations were migrated.

## Composer build variants

Migration `20261105_1245_01_MIGRATE_composer_build_pipelines.sql` runs through the
normal application installer, after the additive pipeline columns. Back up the
database and configuration before installing. This **changes future execution**
for eligible variants from the project recipe to native Composer; inspect and
approve the eligibility rules before running InstallApp:

- Project `ComposerInstall` becomes
  `axenox/deployer/Pipelines/Build/ComposerInstall.php` on each unselected variant.
- `ComposerInstallAssetFix` selects the same prototype with
  `asset_packagist_cache_fix=true`.
- Nonempty Composer JSON/auth objects become `composer_json` / `composer_auth`
  inside `config_uxon`. Null/blank authentication and empty objects do not create
  empty settings. Existing legacy fields are not dropped or modified.
- A selected prototype is never replaced. A nonempty target `config_uxon`
  without a selection is a conflict, not permission to overwrite it.
- Invalid/non-object JSON, clone/custom/unknown recipes and conflicting target
  settings remain unchanged and require manual review.
- Build/deployment records, history, host settings and relative-path values are
  not modified by this build-only migration.

The first `CREATE VIEW` statement in the migration defines its identifier-only
report. A DBA can install **only that report definition** for preflight, without
executing the subsequent backup/INSERT/UPDATE statements. Query it before
approving data conversion:

```sql
SELECT HEX(oid) AS variant_uid, HEX(project_oid) AS project_uid,
       legacy_recipe, migration_status
FROM axxdep_build_pipeline_migration
ORDER BY project_oid, oid;
```

`READY` is eligible, not already migrated. `ALREADY_SELECTED` means the row has a
prototype; it does **not** prove that the selected pipeline works or its settings
are valid. Other report values require correction or an explicit replacement.
Normal installation also returns unresolved rows. Do not paste credentials,
native UXON or backup-table contents into reports.

The backup table stores original target values and the exact generated output.
It can contain authentication; restrict access like the variant table. Repeated
UP runs do not overwrite later edits. DOWN restores only unchanged migration
output; designer-edited native settings are retained. The backup table remains
for recovery/re-upgrade. Do not delete it casually or treat it as nonsensitive.
No automatic downgrade of running builds or artifacts is attempted.

### Asset-packagist cache workaround

The native prototype now supports `asset_packagist_cache_fix=true`. The Build
action selects the latest successful build **of the same variant** and passes
its stored lock as runtime input. Only asset package identities from that lock
produce cache repositories, ahead of the original repositories. Named
repositories and disabled defaults are preserved; duplicate asset identities
are deduplicated.

Without a previous successful valid lock, the operation fails explicitly before
creating a build workspace or running Composer. It never picks an archive from
another variant or silently disables the requested workaround. The conservative
missing-history policy is implemented and tested; broader release policy still
requires the roadmap decision review. For a first build, an operator must
explicitly choose an ordinary Composer configuration or supply valid history
through the supported action/model workflow.

The resulting build record contains the effective manifest, including cache
repositories. Stored prototype UXON is unchanged, and an explicit per-run
Composer replacement still replaces the whole manifest before applying the
requested workaround.

## Deployment hosts

Migration `20261105_1500_01_MIGRATE_host_deployment_pipelines.sql` maps eligible
unselected hosts using the project's legacy recipe:

| Legacy recipe | Native prototype |
|---|---|
| `LocalBldUsbSelfExtractor` | `ManualTransfer` |
| `LocalBldUpdaterPull` | `UpdaterPull` |
| `LocalBldUpdaterCron` | `UpdaterPolling` |
| `LocalBldUpdaterPush` | `UpdaterPush` |
| `LocalBldAzureAppServiceManualInstall` | `AzureManualTransfer`, only with an explicit `app_service_name` |

It reuses `deploy_config`, renames `default_app_config` to `config`, preserves
other known keys, and materializes effective `keep_releases` (override or default
4). Both old and new config keys, unknown keys, invalid/non-object JSON,
SSH/custom/unknown recipes and Azure without an application name are reported,
not silently converted. Existing selections are untouched. Host root, PHP,
connection, project, relative-path values and run history are not rewritten.
No target is contacted by this migration.

Preflight uses only the first `CREATE VIEW` definition, just like the build
migration; review its identifier/reason rows before approving conversion:

```sql
SELECT HEX(oid) AS host_uid, HEX(project_oid) AS project_uid,
       legacy_recipe, target_prototype, migration_status
FROM axxdep_host_pipeline_migration
ORDER BY project_oid, oid;
```

Backups may contain application secrets; restrict database access. Repeat
UP/DOWN/re-upgrade compares exact generated values and preserves later designer
edits. Backup evidence remains on rollback. Fixture tests verify conversion and
recovery; this does not prove a particular target is ready.

The native installation layout is an intentional v3 change, not an automatic
adoption of arbitrary legacy trees. Native OTA additionally requires the paired
updated PackageManager client and trusted HTTPS; an old client is explicitly
rejected. Before installing this migration, review the
[native installation and OTA instructions](ManualTransfer.md), provision a
supported managed layout, and plan target/client upgrades. Do not use a selection
change as evidence that an existing legacy installation can be adopted in place.

`UpdaterPolling` is a pull specialization. It prints host-owned scheduler setup
instructions; it does not start jobs, register a scheduler or run a polling loop.
The host must use the updated native OTA client and trusted HTTPS source.
Successful publication is status 60, not installation success.

### Azure manual transfer

`AzureManualTransfer` prepares an executable native package and operator
instructions, not an Azure API operation. Set `app_service_name` to the Azure
application name and `operator_os` to `Windows` (default) or `Unix`, independently
of the target host OS. Host root/PHP/OS still come from host attributes.
Application names are validated; URL, credentials and executable command
fragments cannot be supplied in that name.

Instructions create a ZIP around the prepared `.phx`, upload it using the Azure
Kudu `zipdeploy` endpoint with a credential prompt, and execute it manually in the
portal. Verify SHA-256 and protect both files: they can contain application
secrets. Check persistent writable storage, routing to the native `current`
release, PHP CLI, symlink permissions, and App Service run-from-package/read-only
settings before upload. Never put the `.phx` in a publicly served directory.

Preparing or uploading is not installation. This prototype remains at status
60, performs no network requests and emits no automatic callback. Manual native
attempts reject OTA downloads/callbacks instead of accidentally serving an old
legacy build/host artifact or acknowledging unperformed installation.
Operator review is required for activation and filesystem recovery; database
changes are not rolled back.

## Dependency removal and remaining release acceptance

The package manifest no longer requires `deployer/deployer`. Recipe files,
recipe task generation/dispatch and SSH execution/configuration helpers are
removed. Native retained paths do not call `vendor\bin\dep`; tests explicitly
block external `Deployer\` class autoloading even if the old library remains in
the shared application's vendor tree. `exface/urldataconnector` is now an explicit
package requirement because native push resolves the host's HTTP connector.

The shared application lock/vendor directory is intentionally not rewritten or
deleted during source changes. During the normal approved application dependency
update, Composer removes the old package if no other root/transitive consumer
requires it. Review Composer's proposed dependency changes before accepting them;
do not use `composer remove` in the shared application to bypass its other
consumers. The paired PackageManager changes must be delivered together; there
is no published minimum-version claim for these uncommitted local sources.

Old recipe datatype class names remain only as non-enum StringDataType
compatibility shims so pre-import metadata stays readable. New exported metadata
uses Core StringDataType and no longer offers recipe choices. Legacy source
columns remain migration evidence, not execution configuration; new projects use
the neutral `NativePipeline` value. No history is deleted.

This is not final v3 release acceptance. In particular:

- Existing unmanaged target layouts require operator provisioning/migration, not
  silent in-place adoption.
- `local_vendors` supports vendor-name lists. `local_packages` preserves
  build-present non-executable assets; differing PHP, Composer/autoload metadata,
  or absent packages require rebuilding explicitly. Legacy map forms and
  unsupported keys must be reviewed, not discarded.
- Native retention keeps the current and immediate previous release; use at
  least 2 or -1. Incompatible values fail instead of silently pruning differently.
- Actual target deployment, supported OS/PHP matrix, production HTTPS/proxy
  behavior and the pending on-demand/state-machine contracts require separate
  approval/acceptance in the [implementation plan](Roadmap/Plan.md).
