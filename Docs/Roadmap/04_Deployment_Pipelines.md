# 04 - Deployment pipelines

## 1. Goal

Replace or explicitly retire all nine legacy deployment recipe options with supported deployment pipeline prototypes, preserving supported deployment, self-extractor, host configuration, reporting and recovery behavior without requiring `deployer/deployer`.

## 2. Scope / non-goals

- In scope: nine legacy options, shared self-extractor/installer, Updater push/pull/polling, manual and Azure guidance, SSH/custom retirement, host execution, status/error reporting, release management and rollback.
- A selected legacy option is not evidence of a working implementation. Do not promise parity where the existing code does not perform it.
- Generic pipeline contracts are in Roadmap 02; on-demand-specific lifecycle/concurrency is owned by Roadmap 06; configuration/migrations are owned by Roadmap 05.
- No generic stage editor, arbitrary command UXON, new transport, or unsupported deployment feature is implied.

## 3. Current state

`Actions/Deploy.php` selects `DeploymentRecipeDataType` and invokes Deployer tasks. The `CustomDeploy` path has a selector mismatch: it checks `BuildRecipeDataType::CUSTOM_BUILD` rather than `DeploymentRecipeDataType::CUSTOM_DEPLOY`, and can fall through to a nonexistent `CustomDeploy.php`.

Retained self-extractor recipes currently share `Recipes/SelfDeployment.php` and `Recipes/SelfExtractingPHP/SelfExtractingDeployment.php`. Extract reusable package/host installation behavior without requiring `.phx` for every generic deployment. Preserve host config, local vendors/packages, shared/copied directories, installer, log reporting and critical-error recovery. Keep host attributes (including installation path and PHP executable) on the host and pass through setters. Preserve effective retention precedence `deploy_config.keep_releases ?? keep_releases`, default `4`.

### Complete legacy mapping (nine options)

| Current option / value | Existing behavior and known issue | V3 handling |
|---|---|---|
| Self-extractor + Updater push / `LocalBldUpdaterPush` | Generates `.phx`, POSTs multipart to PackageManager UpdaterFacade; TLS verification currently disabled; upload errors print manual recovery. | Push prototype; preserve connection/auth and response evidence; always verify TLS with configurable trust; report upload failure as failed transfer, not installed. |
| Self-extractor + Updater pull / `LocalBldUpdaterPull` | Publishes `.phx` for host download via Deployer facade on SelfUpdate. | Pull prototype; preserve publication, download and host status/log callbacks. Whether to use `OnDemandDeploymentInterface` remains open (Roadmap 02/06). |
| Self-extractor + Updater scheduler / `LocalBldUpdaterCron` | Same downloadable package as pull, prints manual scheduling instructions; does not install a scheduler; currently registers `LocalBldUpdaterPull` task name. | Preserve intent as documented pull preset/specialization; correct task identity; state scheduling remains operator responsibility. |
| Self-extractor + SSH upload / `LocalBldSshSelfExtractor` | Generates self-deploying `.phx`, uploads/executes by SSH and removes temporary files. | Remove under all-SSH retirement; report hosts needing explicit supported push, pull or manual replacement. Remove SSH helpers/config only when no remaining consumers. |
| Self-extractor + manual transfer / `LocalBldUsbSelfExtractor` | Prepares `.phx`, prints transfer/execution/rollback instructions; does not transfer or install. | Manual-transfer prototype; downloadable artifact and OS-appropriate instructions; never claim installation success from preparation alone. |
| Self-extractor + Azure manual / `LocalBldAzureAppServiceManualInstall` | Prepares `.phx`, prints Kudu `zipdeploy` and portal execution guidance; upload/install are not automated. | Azure manual specialization or manual prototype; retain distinction and placeholders; `app_service_name` proposed setting, credentials stay external. Validate persistent `/home` assumptions and cloud endpoint scope. |
| Build-ZIP + SSH install / `LocalBldSshInstall` | SSH upload and remote permission, copy/shared, symlink, installer and release-cleanup tasks. Despite name, archive is `.tar.gz`. | Remove SSH orchestration tasks; preserve required installation behavior only in supported shared installer/pipeline. Require explicit replacement for configured hosts. |
| Build-ZIP + SSH install + wait / `LocalBldSshInstallWait` | SSH install sequence plus `network:wait` delays. | Remove experimental wait-only/SSH support; detect config and require replacement. |
| Custom Deployment Recipe / `CustomDeploy` | Intended to load `deployment_recipe_custom_path`, but selector mismatch above can cause nonexistent recipe fallback. | Remove arbitrary procedural path; port known workflow to app-owned prototype; detect/report legacy custom values and account for mismatch. |

New self-extractor on-demand deployment has **no legacy equivalent**; its requirements and risks are tracked in Roadmap 06.

## 4. Planned technical changes

### Shared installer and host properties

Applicable self-extractor settings in the existing host `deploy_config` UXON:

| Property | Proposed default / behavior | Legacy source |
|---|---|---|
| `config` | Remote application-config override map, preserve values/merge behavior; redact and protect secrets. | Rename `default_app_config` only. |
| `keep_releases` | Default `4`; validate retention and protect active/previous releases. | Preserve `deploy_config.keep_releases ?? keep_releases`. |
| `shared_dirs` | `["backup","data","logs","translations"]`; relative bounded entries. | Recipe list. |
| `copy_dirs` | `["config"]`; relative bounded entries. | Recipe list. |
| `local_vendors` | `[]`; preserve local applications and vendor copying behavior. | Host `deploy_config`. |
| `local_packages` | `[]`; full Composer `vendor/package`, preserve package contents from previous release even if vendor is deployed. | Existing host UXON, unchanged. |
| `php_memory_limit` | Proposed `2G`. | Embedded installer/execution instruction. |

Validate paths against traversal and conflicts; do not permit copying/sharing an entire release or overlapping copy/shared paths. `config`, `releases`, `shared`, `current` and public `exface` remain current installer conventions, not unverified knobs.

Host properties (`path_abs_to_api`, `php_cli`, `operating_system`, `project__alias`, `data_connection`) are authoritative host attributes and passed via public setters. Do not duplicate them as UXON properties. Active-connection prototypes may implement `DeploymentViaConnectionInterface::setConnection(DataConnectionInterface)`. Removed `path_rel_to_releases` / `relative_deploy_path` are an approved breaking change without value/layout migration.

### Other legacy values and technical notes

| Legacy value | Target / treatment |
|---|---|
| `timeout` (Action default 900s) | Define orchestration deadline against Composer `1200s`, packaging and installation limits; do not silently truncate behavior. |
| Embedded installer memory `2G`, execution limit `0` | Propose `php_memory_limit=2G`; decide finite safe deadlines in Roadmap 06. |
| Archive-only extractor memory `500M`; SSH upload `900s`; wait-recipe delays | Disappear with removed workflows; do not make defaults for retained prototypes. |
| Host/build paths and release paths | Derive from validated host attributes/run identity. No free-form shell/path templates. |
| `self_extractor_extension` | `.phx` format constant for applicable prototypes, not generic interface requirement. |
| `path_script_createphpdeployment`, `path_script_createphparchive` | Developer-owned implementation; no arbitrary source-path setting. |
| `host_ssh_config`, `ssh_multiplexing`, Cygwin path helpers, symlink executable names | Remove with SSH implementation when unused; replace retained portable operations through OS adapters, not UXON command strings. |
| `use_atomic_symlink`, `use_relative_symlink` | OS adapter activation policy pending; SSH flags are not retained behavior. Atomic activation is a safeguard, not an easy-disable option. |
| `shared_files` | Candidate default `[]` only for a prototype implementing it. Existing SSH Network recipe support is not parity in retained self-deployment; implement/test before exposing. |
| `config_dir` | Fixed `config` convention unless every consumer is coordinated and tested. |
| `connection_config` URL/user/password | Keep in connection; redact. TLS trust may be a transport setting but verification remains enabled. |
| `stage`, `host_short` | Host/task metadata, not user-editable attempt correlation. |
| `host_deploy_path`, `deploy_path`, `release_path`, `target_dir`, `previous_release` | Derive from validated configuration and run identity. |
| `self_extractor_filename`, `temp_php` | Unique attempt-owned paths to avoid concurrent overwrite. |
| `git_tty`, `allow_anonymous_stats` | Deployer-library settings; no pipeline UXON equivalent. |

Preserve embedded installer conventions/behavior: `post-install-cmd`, web-server config generation, permissions, archive extraction, release/shared symlinks, local package/vendor handling and critical failure recovery. Do not copy PHP locals into UXON just because they occur in code. Azure name/credentials are placeholders today; credentials remain external.

### Operator instructions

Review instructions printed at the end of every legacy recipe. They must match pipeline behavior, show only host-OS-appropriate commands, make rollback actionable and distinguish prepare/upload/download from confirmed installation. Automated pull must provide first-time instructions. The legacy example (credentials are placeholders; never place real secrets in docs) is:

```text
curl -v -L -u "<ota_user>:<ota_password>" -o install.phx "https://powerui.mydomain.com/api/proxy/ota/<projekt>/<host_uid>"
php -d memory_limit=2G install.phx
```

## 5. Work packages

- [ ] Build tests and fixture for each of the nine selectors and document which are actually operable today.
- [ ] Extract/test shared package generation and standalone installer behavior without making `.phx` universal.
- [ ] Implement and test Updater push including TLS verification, response evidence, failure state and recovery guidance.
- [ ] Implement and test pull publication, download, host callback and polling instructions; fix polling task-name mapping.
- [ ] Implement and test manual/Azure package instructions; confirm commands target correct operator/host OS and avoid claims of automated installation.
- [ ] Define and test local vendors/packages, `config`, shared/copy dirs, retention and installer memory/timeout.
- [ ] Replace retained deployment paths with typed pipeline implementations using Roadmap 02 contracts.
- [ ] Provide explicit migration report/replacement for SSH and custom deployment configs; resolve `CustomDeploy` selector mismatch in legacy detection.
- [ ] Remove SSH, Deployer procedural tasks, Cygwin and wait support only when retained consumers are removed and their replacements pass.
- [ ] Verify status, logs, critical failure cleanup, active release protection, rollback limits and manual recovery.

## 6. Dependencies

- Roadmap 02 owns task/result interfaces, connection capability, state reporting and facade integration contract.
- Roadmap 05 owns host pipeline selection, UXON properties, SQL movement and unsupported-value migrations.
- Roadmap 06 owns on-demand deployment semantics, trust/correlation, bootstrap and OS matrix.
- Roadmap 01 owns final Deployer requirement removal after all supported transports pass.

## 7. Open decisions

- Whether Azure is a manual-transfer specialization or distinct prototype; supported cloud endpoints and `/home` persistence.
- Whether ordinary pull uses the optional `OnDemandDeploymentInterface`.
- TLS trust configuration shape (certificate verification itself is mandatory).
- Exact critical/non-critical installer errors, activation point, health checks, database migration rollback and downtime.
- Finite preparation/installation deadlines and relationship to Action/Composer limits.
- Whether retained non-SSH paths need shared-file support.
- Which custom/SSH settings are active and how operators remediate them.
- Logging callback retry/retention and local package/vendor edge cases.

## 8. Acceptance criteria

- [ ] All nine legacy options are explicitly mapped above and have tested replacement or explicit retirement/migration handling.
- [ ] Only supported behavior is claimed. Manual/Azure preparation is not reported as successful host installation; push upload is not host install confirmation.
- [ ] TLS verification cannot silently be disabled.
- [ ] Host attributes remain authoritative and installer settings/path lists are validated.
- [ ] Release activation, retention, shared/copy handling, critical errors and rollback/recovery are covered.
- [ ] SSH and arbitrary custom procedural execution are removed only after no retained supported path depends on them.

### Test plan (run at the end)

Run focused PHPUnit tests for all retained transports, shared installer, path validation, status/results, TLS errors, callbacks and rollback/cleanup. For every retained prototype, deploy to disposable Windows and Unix hosts where supported; verify artifact integrity, host-side logs, release/current link, shared/copy/local packages, retention and recoverable failure. Test manual and Azure instructions as generated output without executing real production commands. Confirm every retired SSH/custom selector is detected and receives explicit replacement guidance. Record exact commands and host/PHP/OS versions.
