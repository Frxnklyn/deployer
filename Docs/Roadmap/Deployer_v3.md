Version 3 of `axenox.Deployer` replaces procedural recipes with configurable build and deployment pipeline prototypes.

## Goals

- Remove the `deployer/deployer` dependency.
- Provide proven, purpose-specific pipeline templates that can be configured without extensive build or deployment knowledge.
- Use UXON pipeline prototypes with autosuggest, documentation, defaults and validation to simplify configuration and extension, including deployment through DevOps services.
- Store the selected build pipeline prototype and its UXON configuration on each build variant using `prototype_file` and `config_uxon`. Composer-specific settings belong to the `ComposerInstall` prototype, not as separate build-variant attributes.
- Introduce operating system adapters for Windows/Unix differences on the build server and hosts.
- Remove obsolete legacy recipes and their logic.
- Preserve existing configuration where possible and provide migration for changed settings.

## Key changes

- Rename recipes to **pipelines** in the v3 architecture, configuration editors and documentation. Use **Build Pipeline** and **Deployment Pipeline** as designer-facing names. Legacy recipe labels and values remain identifiable during migration.
- Replace procedural recipes with UXON pipeline prototype classes. Developers implement supported workflows; app designers select a template and configure its documented settings. Use LogBooks to collect execution evidence.
	- UXON annotations provide autosuggest, documentation and presets in configuration editors.
	- Replace the static enums `BuildRecipeDataType` and `DeploymentRecipeDataType` with cross-app pipeline prototype discovery. Proposed folders are `BuildPipelines` and `DeploymentPipelines`; final namespace and selector conventions remain to be decided.
- Provide configurable templates, **not a general-purpose pipeline editor**. Users do not assemble arbitrary stages, dependencies or conditions. New workflows are added by developers as pipeline prototypes.
- Remove the external PHP deployer library. Its procedural recipe format has proven hard to extend and maintain for our needs.
- Each host owns its deployment pipeline prototype selection and UXON configuration, allowing different deployment strategies within one project. Move the legacy project-level recipe selection to the hosts; migrate existing host deployment settings and paths into pipeline UXON properties. The host editor provides a prototype dropdown and a UXON editor for the selected prototype.
- Each build variant owns its build pipeline prototype selection and UXON configuration. Replace its separate `composer_json` and `composer_auth` attributes with `prototype_file` and `config_uxon`; migrate the project-level build recipe selection to the applicable build variants. The build-variant editor provides a prototype selector and a UXON editor for the selected prototype.

### Pipeline terminology

- **Pipeline prototype:** a developer-maintained PHP class implementing a known workflow, analogous to a DevOps pipeline template.
- **Pipeline configuration:** the selected prototype and its UXON settings, analogous to a configured pipeline definition. It is not the entire host configuration or build variant.
- **Pipeline run:** one execution, tracked by a build or deployment record with states and logs.

The initial pipeline types are build and deployment. Other purpose-specific types may be added later; a generic workflow engine or stage editor is not part of v3.

Legacy build recipes to be removed:

- `Clone current installation` - never was really used
- `Custom build recipe` - replaced by app-owned build pipeline prototypes

Legacy deployment recipes to be removed:

- All SSH based logic. It never worked reliably
	- `Build-ZIP + SSH install` - this never worked really well, so quickly stopped development here
	- `Build-ZIP + SSH install + wait fix` - this was just an experiment
- `Custom Deployment Recipe` - not needed anymore

## Legacy structures migration

### Recipe-to-pipeline migration checklist

This inventory covers all four build and nine deployment options currently exposed by [BuildRecipeDataType](../../DataTypes/BuildRecipeDataType.php) and [DeploymentRecipeDataType](../../DataTypes/DeploymentRecipeDataType.php). Legacy recipe names, source paths and configuration values are retained here to make migration traceable. The inventory is based on the implementations and their selection logic in the Build and Deploy actions, not on live execution. A selectable option is not necessarily a working implementation. Mark the Done column with `[x]` only after its v3 change and configuration migration have been verified.

#### Build recipes

| Current recipe | Legacy value | Current implementation | Required v3 change | Done |
| --- | --- | --- | --- | --- |
| Build via Composer | `ComposerInstall` | [ComposerInstall.php](../../Recipes/Build/ComposerInstall.php) uses the shared Composer build tasks to install dependencies, prepare installer scripts, create a `.tar.gz` archive, stage the package BOM and remove the temporary vendor folder. | Replace procedural tasks with a Composer build pipeline prototype. Store the selected prototype in the build variant's `prototype_file` and its settings in `config_uxon`. Expose `composer_json` and `composer_auth` as UXON properties of `ComposerInstall` only; they are no longer separate build-variant attributes. Preserve Composer configuration, authentication, timeout, archive contents, lock file and BOM capture. Run commands through `CliCommandRunner` and move platform-specific archive and cleanup operations to OS adapters. Migrate the legacy project recipe selection and Composer settings to each applicable build variant. | [ ] |
| Build via Composer + asset FIX | `ComposerInstallAssetFix` | Uses the same Composer recipe. [Build.php](../../Actions/Build.php) injects asset-packagist latest-cache repositories using asset packages from the last successful build's lock file. There is no separate recipe file. | Preserve the workaround as a documented, optional Composer prototype setting rather than a separate procedural recipe. Migrate this value with the workaround enabled and define behavior when no previous successful lock file exists. | [ ] |
| Clone current installation | `CloneLocal` | [CloneLocal.php](../../Recipes/Build/CloneLocal.php) archives selected files from the running installation and stages its package BOM without installing dependencies. | Remove the recipe and clone-only tasks. Detect projects still selecting it and require an explicit replacement with a Composer build configuration; do not silently treat a local installation copy as an equivalent Composer build. | [ ] |
| Custom build recipe | `CustomBuild` | [Build.php](../../Actions/Build.php) loads the project's `build_recipe_custom_path` instead of a bundled recipe. | Remove arbitrary procedural recipe-path selection. Port custom workflows still in use to app-owned build pipeline prototypes, then migrate their project configuration to those prototypes. Flag paths without a known replacement for manual migration. | [ ] |

#### Deployment recipes

All retained self-extractor recipes currently share [SelfDeployment.php](../../Recipes/SelfDeployment.php) and the embedded [SelfExtractingDeployment.php](../../Recipes/SelfExtractingPHP/SelfExtractingDeployment.php) installer. Extract reusable package generation and host installation logic without making `.phx` a requirement of the generic deployment pipeline interface. Preserve host configuration, local vendors, shared/copied directories, installer execution, deployment log reporting and critical-error recovery. Define release retention, local vendors and applicable deployment paths as properties of the selected pipeline prototype, with their configured values stored in the host's pipeline UXON. Preserve the effective legacy `keep_releases` value, including its deployment-config override, during migration. Replace Windows-specific package concatenation and other shell assumptions with portable PHP operations or OS adapters.

For every retained option, migrate the project's legacy deployment recipe selection to pipeline configuration on each host before allowing hosts to choose different strategies. Removed options need an explicit replacement or a clear migration error; a custom script or SSH workflow cannot be converted automatically without knowing its behavior.

| Current recipe | Legacy value | Current implementation | Required v3 change | Done |
| --- | --- | --- | --- | --- |
| Self-extractor + Updater push | `LocalBldUpdaterPush` | [LocalBldUpdaterPush.php](../../Recipes/Deploy/LocalBldUpdaterPush.php) generates a `.phx` file and POSTs it as multipart data to the host's PackageManager UpdaterFacade. Upload errors currently print manual-transfer instructions; TLS verification is disabled. | Create a push deployment pipeline prototype using shared self-extractor generation. Preserve connection/authentication configuration and response logs. Enable certificate verification with configurable trust settings and make upload failures explicit in deployment status while retaining documented manual recovery. | [ ] |
| Self-extractor + Updater pull | `LocalBldUpdaterPull` | [LocalBldUpdaterPull.php](../../Recipes/Deploy/LocalBldUpdaterPull.php) generates a `.phx` file for the host to download through the Deployer facade when SelfUpdate is invoked. | Create a pull deployment pipeline prototype. Preserve publication, download availability and host log/status callbacks. Decide whether facade response handling should use `OnDemandDeploymentInterface`, while keeping ordinary pull behavior distinct from creating a fresh deployment on every request. | [ ] |
| Self-extractor + Updater scheduler | `LocalBldUpdaterCron` | [LocalBldUpdaterCron.php](../../Recipes/Deploy/LocalBldUpdaterCron.php) prepares the same downloadable package as pull and prints scheduled-task instructions; it does not configure a scheduler. It incorrectly registers `LocalBldUpdaterPull` as its task name. | Model scheduled polling as a documented pull prototype setting/preset or a specialized prototype. Migrate this value without losing its scheduled-operation intent. Eliminate the task-name mismatch and document that scheduling SelfUpdate remains a host-side responsibility. | [ ] |
| Self-extractor + SSH upload | `LocalBldSshSelfExtractor` | [LocalBldSshSelfExtractor.php](../../Recipes/Deploy/LocalBldSshSelfExtractor.php) generates a self-deploying `.phx`, uploads and executes it through SSH, then removes temporary files. | Remove under the decision to drop all SSH logic, including its upload/execution helpers and SSH configuration when no longer referenced. Require affected hosts to select a supported push, pull or manual-transfer replacement. | [ ] |
| Self-extractor + manual transfer | `LocalBldUsbSelfExtractor` | [LocalBldUsbSelfExtractor.php](../../Recipes/Deploy/LocalBldUsbSelfExtractor.php) generates a `.phx` file and prints manual transfer, execution and rollback instructions; it performs no transfer itself. | Create a manual-transfer deployment pipeline prototype. Preserve downloadable artifacts, host-tailored configuration and OS-appropriate execution/recovery instructions. Keep package preparation distinct from confirmed host installation. | [ ] |
| Self-extractor + manual upload to Microsoft Azure | `LocalBldAzureAppServiceManualInstall` | [LocalBldAzureAppServiceManualInstall.php](../../Recipes/Deploy/LocalBldAzureAppServiceManualInstall.php) generates a `.phx` and prints instructions for ZIP upload through Azure Kudu `zipdeploy`, followed by manual execution in the Azure portal. | Preserve as an Azure manual deployment pipeline prototype or specialization of manual transfer. Keep Azure-specific packaging/upload guidance separate from generic self-extractor logic and make operator commands appropriate for their OS. The current recipe does not automate Azure upload or installation. | [ ] |
| Build-ZIP + SSH install | `LocalBldSshInstall` | [LocalBldSshInstall.php](../../Recipes/Deploy/LocalBldSshInstall.php) uploads a self-extracting archive via SSH, then orchestrates remote permissions, copied/shared directories, symlinks, installers and release cleanup as separate tasks. Despite its label, the build archive is `.tar.gz`, not ZIP. | Remove the recipe and remote orchestration tasks that have no retained consumers. Require an explicit supported replacement for existing configurations; retain necessary host installation behavior in the shared self-deploying installer, not the SSH task framework. | [ ] |
| Build-ZIP + SSH install + wait fix | `LocalBldSshInstallWait` | [LocalBldSshInstallWait.php](../../Recipes/Deploy/LocalBldSshInstallWait.php) follows the SSH install sequence with additional `network:wait` tasks between remote operations. | Remove the experimental recipe and wait-only network support along with the SSH workflow. Detect this legacy value during migration and require a supported replacement. | [ ] |
| Custom Deployment Recipe | `CustomDeploy` | Intended to load `deployment_recipe_custom_path`. However, [Deploy.php](../../Actions/Deploy.php) checks `BuildRecipeDataType::CUSTOM_BUILD` instead of `DeploymentRecipeDataType::CUSTOM_DEPLOY`, so this option falls through to a nonexistent bundled `CustomDeploy.php`. | Remove arbitrary procedural recipe-path selection. Port custom workflows still in use to app-owned deployment pipeline prototypes and migrate their host configuration explicitly. Account for the existing selector mismatch when identifying legacy custom configurations. | [ ] |

The new on-demand deployment pipeline prototype has no legacy recipe equivalent and is specified separately below. After all rows are complete, remove the static recipe enums and obsolete task/config generation from the actions, enable cross-app pipeline prototype discovery, and drop `deployer/deployer` only after no retained build or deployment path depends on it.

### Recipe variable inventory

The inventory includes explicit recipe `get()`/`set()` keys, interpolation keys and values injected by Build/Deploy, including references in recipe examples/comments. PHP locals that merely hold the same value are grouped with their source. Embedded-installer settings are included separately below. This is a source review, not proof that a legacy option worked on every retained path.

| Legacy variable(s) | Proposed v3 destination | Migration/behavior notes |
| --- | --- | --- |
| `source_files` | Composer build UXON list. | Replace shell-string parsing with validated archive entries; preserve the four default entries. |
| `composer_timeout` | Composer build UXON integer in seconds. | Legacy default is `1200`; it also bounds archive/cleanup commands. Define separate operation limits only if required. |
| `php_executable` | Build task/runtime resolution from selected PHP version. | Do not add a second source competing with existing `php_version`. |
| `time_zone` | Action-owned naming policy or existing workbench configuration; exact source remains open. | Recipe fallback uses `Europe/Berlin`, but normal Build names use the action's PHP timezone. Do not claim a pipeline property controls names already generated by the action. |
| `customer_specific_version` | Build task version. | Only needed by legacy recipe fallback naming; do not preserve as a second version setting. |
| `release_name`, CLI `build` | Action-generated build name / explicit deployment task build reference. | Eliminate scanning the archive directory to guess the newest build. On-demand release selection needs its own agreed policy. |
| `archiv_name` | Build artifact metadata/result. | Preserve historical filenames; new names are generated, not supplied as shell fragments. |
| `builds_archives_path` | Managed artifact storage and isolated run workspace. | Derived from existing project storage configuration; not an arbitrary per-host archive location. |
| `path_to_installation_root`, `relative_deploy_path` | Deployment UXON properties. | Migrate host `path_abs_to_api` and `path_rel_to_releases`; preserve the empty-relative-path web-server mode. |
| `php_path` | Deployment UXON property. | Migrate host `php_cli`; this is independent of build-server PHP selection. |
| `keep_releases`, `deploy_config.keep_releases` | One deployment UXON property. | Preserve `deploy_config.keep_releases ?? keep_releases`, whose recipe default is `4`; do not keep duplicate sources. |
| `shared_dirs`, `copy_dirs` | Deployment UXON lists. | Preserve defaults and validate overlap/path boundaries. |
| `shared_files` | Candidate deployment UXON list, default `[]`, only for prototypes implementing file sharing. | Legacy SSH network tasks consume it, but retained self-deployment does not embed or install shared files. Do not silently advertise parity; implement and test before exposing it on self-extractor prototypes. |
| `config_dir` | Fixed `config` convention initially. | The self-extractor independently hard-codes `config`. Making it configurable requires updating every consumer, not just migrating this unused-on-that-path variable. |
| `deploy_config.local_vendors` | Deployment UXON `local_vendors`. | Preserve both local-app retention and vendor-directory copying behavior. |
| `deploy_config.default_app_config` | Separate host application-configuration payload. | Preserve filename map and merge behavior, without turning credentials into pipeline properties. |
| `deploy_config` (other keys) | Explicit migration review. | The retained installer reads the three keys listed above; report unknown/custom keys for manual mapping rather than dropping them. |
| `connection_config` (`url`, `user`, `password`) | Existing host connection, resolved at execution. | Keep URL/authentication in connection configuration; redact secrets. Proposed TLS trust is a pipeline transport setting, with verification always enabled. |
| `stage`, `host_short` | Host/task metadata. | Stage and host alias are not deployment-control options; do not use an editable alias as attempt correlation. |
| `host_deploy_path`, `deploy_path`, `release_path`, `target_dir`, `previous_release` | Derived installation/run paths. | Compute from validated configuration and run identity; the previous release is discovered at runtime. |
| `current_copy_dir` | Internal directory-loop value. | Not a UXON property. |
| `self_extractor_filename`, `temp_php` | Unique attempt-owned artifact/workspace paths. | Legacy build-plus-host naming must not cause concurrent attempts to overwrite one another. |
| `self_extractor_extension` | Self-extractor prototype format constant `.phx`. | Not a generic deployment interface property. |
| `path_script_createphpdeployment`, `path_script_createphparchive` | Developer-owned package-generation code. | No arbitrary installer source-path setting; the archive-only source belongs to removed SSH orchestration. |
| `host_ssh_config`, `ssh_multiplexing` | Remove with SSH recipes. | Existing connection records still require an explicit replacement/migration report where used. |
| `path_to_installation_root_cygwin`, `target_dir_cygwin`, `cygwin_path_previous_release`, `symlink_prefix`, `bin/symlink` | Remove legacy command/path helpers; use OS adapter operations. | Do not expose Cygwin paths or executable shell templates in UXON. |
| `use_atomic_symlink`, `use_relative_symlink` | OS adapter capability/activation policy, pending OS contract. | Legacy SSH flags are not honored by the retained PHP self-extractor. Atomic activation is a safeguard, not an option to disable casually. |
| `git_tty`, `allow_anonymous_stats` | Remove external Deployer-library settings. | No pipeline UXON equivalent is needed. |

Additional values outside the recipe variable registry also need explicit treatment:

- Build/Deploy action `timeout` currently defaults to `900` seconds, shorter than the recipe's `1200`-second Composer timeout. Define how orchestration deadlines and pipeline operation limits interact; migrating only `composer_timeout` cannot preserve the intended effective limit.
- Embedded-installer PHP `memory_limit` is `2G`, `max_execution_time` is unlimited (`0`), and archive extraction selects a platform-specific `tar` command. Propose `php_memory_limit` for the retained installer; settle finite installation/preparation deadlines in the lifecycle contract. Executable discovery and extraction belong to OS adapters, not arbitrary UXON command templates.
- Embedded names `releases`, `shared`, `current`, `exface` and `config`, installer script `post-install-cmd`, web-server configuration generation and permissions are prototype behavior/conventions. Any future configurability requires coordinated consumer changes and acceptance tests; do not expose raw `chmod` values or shell scripts by copying implementation locals.
- SSH upload's `900`-second limit, the wait recipe's network delays and archive-only `500M` execution setting disappear with their removed workflows. They are not defaults for retained prototypes.
- Azure service name and operator credentials are currently instruction placeholders, not stored recipe variables. Only the service name is proposed as specialized UXON above; credentials remain external.
- The Composer asset-cache workaround is currently selected by the recipe enum in Build, not by a recipe variable. Its proposed boolean property replaces that selector distinction within the `ComposerInstall` UXON configuration; it does not require a separate build-variant attribute.

For migration acceptance, account for every configured legacy key and preserve its effective value where supported. Report unknown/custom settings and unsupported capabilities explicitly. These examples do not resolve general override precedence, prototype schemas or on-demand lifecycle decisions, and no open item is marked resolved solely by adding them.

## New pipeline prototypes

To test the new structure, we will implement the following additions. 

### On-demand deployment

The new deployment pipeline prototype `Self-Extractor + on-demand deployment` publishes a release like the Updater pull pipeline, but creates another deployment run on request to the Deployer facade instead of answering that there is no update. The exact release-selection policy remains an open decision below.

This pipeline enables containerized environments to download and install the selected release whenever an instance starts. Each new instance needs its own deployment run, even when it uses the same build artifact. When a new release is published, subsequent instance requests should use it according to the release-selection policy. The pipeline owns package preparation and host installation logic; actions track the deployment runs.

This pipeline prototype needs to implement the proposed `OnDemandDeploymentInterface`, which extends the PSR request handler interface. This optional capability lets the facade delegate response creation to the pipeline. Whether the ordinary Updater pull pipeline should use it too remains an open decision.

A new deployment run is intended for each new instance request, including repeated runs for the same build. The distinction between a new instance request, a retry and reuse of an already-published run must be settled in the state and identity rules below.

- if there is a deployment in state 60 - return that phx
- if there is a deployment in state (>= 62 and <90) start a new deployment for the same build
- if there is a deployment in state (>= 62 and <90) start a new deployment for the same build

When a new deployment run is started, the pipeline waits until its package is ready and returns the `.phx` file as a download response. It cannot wait for host installation to finish before providing the package required for that installation.

## Example pipeline UXONs and recipe variable migration

These examples propose the configurable surface of v3; they are not configurations supported by the current implementation. Prototype names, selectors and property names below are illustrative until the discovery and schema decisions are approved. `prototype` represents the separately selected prototype (stored in `prototype_file` for build variants); the remaining properties represent its UXON settings (stored in `config_uxon`). This does not prescribe storing the selector inside the UXON editor's value.

The examples cover every retained workflow and the new on-demand workflow. Asset-fix and scheduled polling are shown as settings of Composer and pull prototypes, respectively; Azure is shown as a specialized manual-transfer prototype. These are proposals, not resolutions of the corresponding open decisions. Removed clone, SSH and arbitrary custom-script recipes intentionally have no new example. An app-owned replacement prototype must document its own UXON.

### Configuration boundaries

Most persistent recipe options should become documented, typed UXON properties, but not every recipe variable is an option:

- Pipeline UXON defines repeatable workflow settings: timeouts, archive contents, installation paths, copied/shared directories, release retention and local vendors.
- Build-variant `config_uxon` supplies prototype settings, including Composer JSON/authentication for `ComposerInstall`. Build tasks supply run-specific values such as the target PHP version, version, comment and notes; do not duplicate those in pipeline UXON.
- Deployment tasks supply the selected build artifact, host identity, attempt identity and resolved host connection/application configuration. Do not let pipeline UXON override the action-owned run identity or status.
- Connections and secret references remain outside the pipeline UXON. In particular, do not copy exported connection passwords or application secrets into examples or editor presets.
- Generated artifact/workspace paths and OS-specific command strings remain internal. Configuring a supported workflow is not configuring arbitrary shell commands or installer source files.

Omitted properties in the examples use the proposed prototype defaults below. Each example is independent, not an instruction to merge it with another JSON block. Deployment examples use generic Linux paths; a Windows host can use `"path_to_installation_root": "C:/Applications/example"` and its own PHP executable. Paths are interpreted by the selected host OS adapter, not by the build server's OS.

### Composer build pipeline

Replaces the `ComposerInstall` recipe. Each build variant stores its selected build prototype in `prototype_file` and the selected prototype's configuration in `config_uxon`. The Composer prototype exposes `composer_json` and `composer_auth` as UXON properties; these are not build-variant attributes and are not required by non-Composer build prototypes.

Current `source_files` variable becomes a list of workspace-relative archive entries instead of a shell fragment. Required deployment files must be validated before archiving; authentication files and temporary credentials must not be included. Keep the archived Composer lock and BOM consistent with the installed dependencies.

```json
{
	"composer_timeout": 1200,
	"source_files": ["vendor", "composer.json", "composer.lock", "composer.phar"],
	"asset_packagist_cache_fix": false,
	"composer_json": {
		"require": {},
		"repositories": []
	},
	"composer_auth": {}
}
```

This is the `config_uxon` for the `ComposerInstall` prototype; the build variant stores its selection separately in `prototype_file`. The build PHP executable is resolved from the existing selected PHP version, not from an independently configured executable in this UXON. Composer JSON/authentication are configured through the `ComposerInstall` prototype's UXON properties. Installer-script preparation, lock/BOM capture and temporary-vendor cleanup are required prototype behavior, not switches allowing incomplete builds.

#### Composer with asset-packagist cache workaround

Replaces `ComposerInstallAssetFix` using the same proposed prototype:

```json
{
	"prototype_file": "\\axenox\\Deployer\\BuildPipelines\\ComposerInstall",
	"config_uxon": {
		"composer_timeout": 1200,
		"source_files": ["vendor", "composer.json", "composer.lock", "composer.phar"],
		"asset_packagist_cache_fix": true
	}
}
```

The workaround uses asset packages from the previous successful build's lock file. The no-previous-lock behavior still needs a decision; this example does not imply silently disabling the requested workaround.

### Shared self-extractor deployment properties

Push, pull, manual transfer, Azure manual transfer and on-demand prototypes share the applicable installation properties. A shared implementation should avoid duplicated property logic without making self-extractors mandatory for other deployment prototypes.

| Proposed property | Purpose and proposed default | Legacy source |
| --- | --- | --- |
| `path_to_installation_root` | Required absolute host installation root. | Host `path_abs_to_api`. |
| `relative_deploy_path` | Deployment directory below that root; allow an empty string for the existing root/web-server-config mode. No assumed migration value. | Host `path_rel_to_releases`. |
| `php_path` | Host PHP executable, default `php`; resolve/validate as an executable, not a free-form command. | Host `php_cli`. |
| `keep_releases` | Host release retention, default `4`; preserve the effective legacy override. Validation and protection of active/previous releases must follow the agreed retention rules. | `deploy_config.keep_releases`, otherwise recipe `keep_releases`. |
| `shared_dirs` | Directories persisted across releases, default `["backup", "data", "logs", "translations"]`. | Recipe `shared_dirs`. |
| `copy_dirs` | Directories copied from the previous release, default `["config"]`. | Recipe `copy_dirs`. |
| `local_vendors` | Vendor namespaces whose local apps must survive replacement, default `[]`. | `deploy_config.local_vendors`. |
| `php_memory_limit` | Host installer PHP memory limit, proposed default `2G`. | Hard-coded installer and execution instructions. |

Directory entries must be relative, bounded to the intended installation paths and non-conflicting. Do not allow traversal, accidental sharing of an entire release or a directory listed as both copied and shared. The installer currently fixes `config`, `releases`, `shared`, `current` and the public `exface` link; those names remain prototype conventions in these examples, not additional path knobs with unverified support.

`default_app_config` remains application configuration delivered to the installed workbench, separate from deployment-control properties. Its transport/storage and secret resolution still need a contract; do not hide it in an untyped replacement `deploy_config` property. Migration must preserve its existing filename-to-configuration map and merge behavior, including the effective installation name.

### Self-extractor with Updater push

Replaces `LocalBldUpdaterPush`. The host's existing connection reference supplies the Updater URL and authentication. Proposed `tls_ca_file` selects a build-server trust bundle; omitting it uses the system trust store. Certificate verification is mandatory, not an opt-out setting.

```json
{
	"path_to_installation_root": "/srv/example",
	"relative_deploy_path": "application",
	"php_path": "/usr/bin/php",
	"keep_releases": 4,
	"shared_dirs": ["backup", "data", "logs", "translations"],
	"copy_dirs": ["config"],
	"local_vendors": ["examplevendor"],
	"php_memory_limit": "2G",
	"tls_ca_file": "/etc/ssl/certs/example-ca.pem"
}
```

Upload errors must produce a failed preparation/transfer outcome with response evidence and manual recovery instructions. Successful upload alone must not be confused with confirmed host installation.

### Self-extractor with Updater pull

Replaces `LocalBldUpdaterPull`. Publication uses the existing authenticated facade/host relationship; download URLs and callback credentials are derived, not configured as duplicate UXON strings.

```json
{
	"path_to_installation_root": "/srv/example",
	"relative_deploy_path": "application",
	"php_path": "/usr/bin/php",
	"keep_releases": 4,
	"shared_dirs": ["backup", "data", "logs", "translations"],
	"copy_dirs": ["config"],
	"local_vendors": [],
	"php_memory_limit": "2G",
	"scheduled_polling": false
}
```

#### Updater pull with scheduled polling

Replaces `LocalBldUpdaterCron`, preserving scheduled-operation intent without pretending that the pipeline installs a scheduler:

```json
{
	"path_to_installation_root": "/srv/example",
	"relative_deploy_path": "application",
	"php_path": "/usr/bin/php",
	"keep_releases": 4,
	"scheduled_polling": true
}
```

`scheduled_polling` selects host-side scheduling guidance/preset metadata; it does not change the package into an on-demand deployment. The actual schedule and invocation of PackageManager SelfUpdate remain the host operator's responsibility. No cron expression is inferred from the legacy recipe because it defines none.

### Self-extractor with manual transfer

Replaces `LocalBldUsbSelfExtractor`. This prototype prepares a downloadable package and OS-appropriate transfer, execution and recovery instructions; it does not perform transfer or claim installation success.

```json
{
	"path_to_installation_root": "/srv/example",
	"relative_deploy_path": "application",
	"php_path": "/usr/bin/php",
	"keep_releases": 4,
	"shared_dirs": ["backup", "data", "logs", "translations"],
	"copy_dirs": ["config"],
	"local_vendors": ["examplevendor"],
	"php_memory_limit": "2G"
}
```

### Self-extractor with Azure manual transfer

Replaces `LocalBldAzureAppServiceManualInstall`. Proposed `app_service_name` replaces the unresolved name placeholder in the legacy Kudu instructions; credentials are still obtained separately. The paths below illustrate an App Service with persistent `/home` storage and require validation against the selected hosting layout.

```json
{
	"path_to_installation_root": "/home/site",
	"relative_deploy_path": "application",
	"php_path": "php",
	"keep_releases": 4,
	"shared_dirs": ["backup", "data", "logs", "translations"],
	"copy_dirs": ["config"],
	"local_vendors": [],
	"php_memory_limit": "2G",
	"app_service_name": "example-service"
}
```

The specialization provides ZIP/Kudu guidance and manual host execution instructions. It does not imply automated Azure deployment. Operator-side commands must match the operator's OS, which can differ from both the build server and host OS. Nonstandard Azure cloud domains would need an explicit endpoint design; do not assume this name alone supports every Azure environment.

### Self-extractor with on-demand deployment

New prototype without a legacy equivalent:

```json
{
	"prototype": "\\axenox\\Deployer\\DeploymentPipelines\\OnDemandDeployment",
	"path_to_installation_root": "/srv/example",
	"relative_deploy_path": "application",
	"php_path": "/usr/bin/php",
	"keep_releases": 4,
	"shared_dirs": ["backup", "data", "logs", "translations"],
	"copy_dirs": ["config"],
	"local_vendors": [],
	"php_memory_limit": "2G"
}
```

This demonstrates installation configuration only. Desired-build selection, first-instance behavior, request/retry identity, preparation wait limits and callback correlation remain unresolved. Do not add an apparently working `latest_build`, retry key or attempt ID property before those contracts are settled. Selected builds and attempt IDs belong to run/policy resolution, not fixed installer UXON. Persistent versus disposable container directories must also be decided before treating this example as a production container configuration.

## Open items and decisions

The items below are unresolved requirements, not additional approved features. Recommendations are starting points for decisions. Mark an item `[x]` once its answer and acceptance criteria have been recorded in this roadmap; this does not mean the implementation is complete.

### Architecture and configuration

| Open item | What is unclear or missing | Decision to record | Resolved |
| --- | --- | --- | --- |
| Prototype contracts and ownership | Ownership is settled: pipelines own artifact creation/deployment logic; actions own build/deployment records and persist states reported by pipelines. Pipelines stream text through generators. A final result object containing a state is proposed; exact contracts and intermediate state reporting remain open. | Define the execution inputs, `run()` signature, final result shape and intermediate state-reporting mechanism without mixing structured objects into the text stream. Specify transaction and exception handling. Keep HTTP handling and self-extractor generation optional; decide whether ordinary pull uses the HTTP capability too. See the ownership and streaming contract below. | [ ] |
| Host deployment pipeline ownership | Resolved: each host stores its deployment pipeline prototype selection and UXON configuration. Pipeline-specific settings such as `keep_releases`, `local_vendors` and deployment paths belong in that UXON, not in a project-wide deployment configuration. | The host editor must offer a discovered deployment prototype dropdown and a matching UXON editor. Deployment execution must use that host's selected prototype and settings, allowing hosts in the same project to use different pipelines. See Host deployment configuration below. | [x] |
| Configuration location and precedence | Resolved: each build variant stores its selected build prototype in `prototype_file` and its prototype settings in `config_uxon`; each host owns its deployment pipeline configuration. General override rules remain open. | Specify precedence among prototype defaults, stored UXON and run-specific task values. Distinguish pipeline settings from connection secrets and application configuration sent to hosts. Define migration precedence for legacy host fields and deployment-config overrides, without keeping duplicate sources for the same pipeline property. | [ ] |
| Prototype discovery and editor integration | `BuildPipelines` and `DeploymentPipelines` are proposed folder names, but canonical paths, selector format and UXON schemas are not defined. The host and build-variant prototype dropdowns and prototype-aware UXON editors are required; their exact integration remains open. | Choose consistent folder/namespace conventions, discovery/factory behavior and app-qualified selectors. Define the `prototype_file` selector format and `config_uxon` schema, template choices, editor schemas, presets, validation and diagnostics for missing prototypes. Define how changing the selected prototype handles existing UXON without silent data loss. Decide whether asset-fix, scheduler and Azure variants are classes or settings/presets. Editors expose supported settings, not arbitrary stage composition. | [ ] |
| Migration and mixed-version operation | Automatic migration is a goal rather than a defined procedure. Project-to-host and project-to-build-variant migration, removed custom/SSH values and old PackageManager clients need explicit treatment. | Define an idempotent migration with a preview/report, precedence for existing host and build-variant settings, and manual intervention for unsupported recipes. Preserve historical records and explain recovery if upgrading fails. Record minimum Core/PackageManager/PHP versions and whether existing download/log URLs and configuration remain supported during rollout. | [ ] |
| OS adapters and standalone bootstrap | A generated self-extractor runs before the new release's autoloader is available, and a fresh container may have no existing Core installation. `CliCommandRunner` cannot simply be assumed available at that point. | Define the supported build-server/host OS and PHP matrix, adapter operations, selection and prerequisites. Decide how a standalone `.phx` receives the required adapter/runner code without loading the installation it is replacing. Include archive extraction, symlink permissions, executable discovery and web-server configuration. | [ ] |

### On-demand deployment semantics

The current [DeployerFacade](../../Facades/DeployerFacade.php) already exposes `?redeploy=true`, selects pending deployments in the range `60 <= status < 90`, sets status `62` before the response stream is consumed, and intends to reuse the last successful deployment when no pending one exists. The new design must specify which behavior is retained and which is replaced.

| Open item | What is unclear or missing | Decision to record | Resolved |
| --- | --- | --- | --- |
| Complete state machine | The on-demand section repeats the `>= 62 and < 90` rule. It does not cover preparation (the Deploy action uses `50`), failure (`90`), success (`99`), no history or abandoned attempts. Returning a pending deployment at `60` also needs reconciliation with "a new deployment every time". | Replace the duplicate rules with a complete transition table. Define publication versus download versus installation completion, timeout/abandonment states and whether reuse at `60` is an explicit exception to one attempt per request. | [ ] |
| Desired release versus last successful release | "Latest release" and "last successful deployment" are not equivalent. If a newly published build is being installed, fails or has not yet succeeded on this host, selecting the last successful attempt can return an older build. | Define an authoritative desired/published build for each host or project, how variants are selected, and whether failed installation changes that target. Decide first-container behavior, promotion, deliberate rollback and what happens when the target build's artifact is missing. | [ ] |
| Container instance identity and callback correlation | Several instances can share one configured host. Current log callbacks select its latest pending deployment rather than addressing a specific attempt, so overlapping requests/logs need a new correlation contract. | Keep the host as deployment policy and give each attempt a unique ID; decide whether instance IDs are also needed. Bind authenticated downloads and callbacks to the correct attempt. Specify backward compatibility for clients that send only the host UID. | [ ] |
| Concurrent requests, artifacts and retries | Generated `.phx` names currently depend on build and host, and the Deploy action generates a project-level `deploy.php`. Fresh attempts for the same host/build must not overwrite configuration, mix logs or delete artifacts still being downloaded. Client retries must not accidentally create unlimited installations. | Define isolation/locking, unique workspace and artifact ownership, and atomic publication. Distinguish a new instance request from retrying the same attempt, including interrupted downloads, retry keys and whether immutable build data can be reused safely. | [ ] |
| Waiting and HTTP behavior | "Waits for it to finish" could mean package preparation or completed host installation. Waiting for installation before returning its package would be circular. Preparation may also outlast HTTP/proxy limits. | Wait only until the download artifact is ready. Choose bounded synchronous preparation or an asynchronous prepare/poll protocol supported by the boot client. Specify timeout, cancellation, no-release/error responses, cache policy, retry guidance and the future meaning of `?redeploy=true`. | [ ] |
| Container persistence and rollout | Rebuilding deployment records does not define what survives container restart, how all replicas receive a new release, or how concurrent instances run database installers. | Specify persistent/shared versus disposable paths, startup readiness and failure behavior. Decide whether updates apply only at startup or trigger an external rollout. Define who serializes database migrations across replicas and how incompatible application/database versions are handled. | [ ] |

### Missing safeguards and completion criteria

| Open item | What is unclear or missing | Requirement or decision to record | Resolved |
| --- | --- | --- | --- |
| Critical failure, rollback and downtime | Retaining existing installation logic does not define exactly when a release is safe to activate. Switching a symlink back cannot necessarily undo a partially applied database migration. | Define critical versus non-critical failures, activation order, health/readiness checks and rollback guarantees for files, configuration and databases. Keep the previous working release until success is confirmed, and state manual recovery boundaries and expected downtime. | [ ] |
| Credentials, artifact trust and execution permissions | UXON can expose credentials; `.phx` files contain executable code and host-tailored configuration. TLS is disabled in the current push recipe, and containers may start with no stored authentication state. | Define secret references/redaction, host and attempt authorization, bootstrap credentials and least-privilege execution. Require verified TLS and decide whether checksums or signatures are needed. Protect stored packages/config files and ensure generated execution never incorporates unvalidated operator input. | [ ] |
| Logs, exit codes and remote failures | Existing paths infer some failures from console text. Text streaming through pipeline generators is required; LogBooks also need to preserve remote installation evidence even when a callback cannot reach the build server. | Specify structured statuses and exit-code handling separately from streamed text rather than success inferred solely from message text. Correlate logs with attempts, define buffering/retry of remote callbacks and ensure logging failures do not prevent cleanup or rollback. Decide retention/redaction policy. | [ ] |
| Reproducibility and cleanup | Preserving Composer install alone does not define lock-file policy, failed-build isolation or artifact retention. On-demand attempts can accumulate indefinitely even if host release retention is bounded. | Define how dependencies are resolved/pinned and how archived lock files/BOMs describe the actual build. Set separate retention rules for build archives, host-tailored packages, attempts and logs. Protect promoted/in-use artifacts and clean failed workspaces without affecting concurrent jobs. | [ ] |
| Verification and cutover | Prototype skeletons and documentation alone do not establish behavior parity or a release gate. | Add focused PHPUnit coverage for pipeline contracts, configuration migration and state transitions, plus end-to-end build/install/download tests on supported OS combinations. Include simultaneous container startup, repeated/interrupted downloads, callback attribution, failed installation and older-client compatibility. Remove legacy execution and the dependency only after replacement pipelines pass their acceptance checks. | [ ] |

Implementation should start once the configuration owners, lifecycle contracts and on-demand state/identity rules are settled. These choices determine the model migration and interfaces; leaving them until after prototype implementation risks incompatible contracts and unsafe concurrent deployments.

## Approach

### 1. Analyze existing code

- Analyze the legacy recipe files and map retained workflows to pipeline templates.

### 2. Plan the new classes

- Analyze shared behavior and differences between legacy recipes, then plan purpose-specific pipeline prototype classes.
- Define `BuildPipelineInterface` and `DeploymentPipelineInterface` using the agreed ownership and streaming model. Keep self-extractor and HTTP capabilities separate from the generic deployment contract.
- Decide whether abstract base classes reduce meaningful duplication.
- Create pipeline prototype skeletons for every retained workflow, then implement and verify behavior before cutover.
- Write class documentation with template purpose, supported settings, prerequisites and examples.
- Define release retention, local vendors and deployment paths as UXON properties of the applicable deployment pipeline prototypes. Store their values with the selected prototype on each host; migrate legacy host fields and deployment configuration into that UXON.


## Technical details of the new architecture 

### Object oriented approach

- Implement workflows as object-oriented pipeline prototypes instead of procedural recipes.
- Follow UXON prototype principles and expose only supported configuration settings.
- Keep internal workflow stages in developer-maintained code; do not introduce a general-purpose pipeline editor.

### Host deployment configuration

Each host owns a deployment pipeline configuration consisting of a prototype selector and its UXON settings. The prototype class remains developer-maintained code supplied by an app; ownership here means the host stores the selected prototype and its configured values. Hosts in the same project may select different prototypes and settings.

The host editor provides:

- A deployment pipeline prototype dropdown populated through prototype discovery.
- A UXON editor using the selected prototype's property definitions, defaults, documentation, autosuggest and validation.

Deployment-specific settings currently stored on hosts, including `keep_releases`, `local_vendors` and deployment paths, become UXON properties of the applicable pipeline prototypes. Their values remain host-owned, but are consolidated into pipeline configuration rather than maintained in parallel host fields. Only prototypes that need a setting expose it; for example, release retention is relevant to release-directory strategies, not every deployment pipeline.

Host identity, project membership and connection references remain distinct from pipeline-specific settings. Application configuration destined for the installed workbench must also remain distinguishable from the settings that control deployment itself. Exact model attribute names and legacy-to-UXON mappings remain part of migration planning.

The Deploy action resolves the selected prototype and UXON from each target host. Long-term deployment settings belong in that host's pipeline configuration; the deployment task supplies values for the particular run. The same host-specific pipeline selection applies when the facade handles a download/on-demand request.

### Build variant configuration

Each build variant owns its build pipeline configuration, so variants in the same project can select different build prototypes and settings. Store the selected prototype in `prototype_file` and its UXON settings in `config_uxon`; migrate the legacy project-level build recipe selection and variant settings into this configuration. The build-variant editor provides a prototype selector and an editor for the selected prototype's documented UXON properties. `ComposerInstall` alone defines the `composer_json` and `composer_auth` properties; non-Composer prototypes do not need to expose them.

The Build action resolves the selected prototype and UXON from the build variant. Run-specific values remain on the build task; define their precedence relative to prototype defaults and stored UXON as part of the open configuration contract.

### Interfaces

`Build` and `Deploy` actions shall instantiate pipeline prototypes and tasks for them, pass the task to the `run()` method of the prototype, stream text output and then analyze the result object.

The task object contains all the things the user has defined for this particular run. Long-term configs are part of the build and deployment UXONs.

#### PipelinePrototypeInterface

- extends iCanBeConvertedToUxon
- `run($task) : Generator` - should return a result object, that has `getStatus() : int` to tell the action, what status we have reached 

#### BuildPipelineInterface

- `run(BuildTask $task) : Generator` - should return a `BuildResult` object

#### DeploymentPipelineInterface

- `run(DeploymentTask $task) : Generator` - should return a `DeploymentResult` object. 

#### BuildTaskInterface

- `getComment()`
- `getNotes()`

#### DeploymentTaskInterface



### Ownership and streaming contract

The following ownership decisions are agreed:

- Each build variant owns the selected build pipeline prototype and its UXON configuration through `prototype_file` and `config_uxon`. `composer_json` and `composer_auth` are UXON properties of the `ComposerInstall` prototype, not separate build-variant attributes.
- A build pipeline owns the logic that creates a deployable artifact.
- A deployment pipeline owns the logic that deploys a build artifact. It controls both build-server-side deployment preparation/transfer and host-side installation logic, typically delivered in a self-extractor. The generic contract must also allow strategies without a self-extractor.
- Each host owns the deployment pipeline prototype selection and UXON values, including applicable deployment paths, retention and local vendor settings. The prototype defines those properties; actions execute it using the host's configuration.
- The Build and Deploy actions own their respective metaobject records, which track pipeline runs. Pipelines report which states are reached and when; the actions persist those transitions. Pipelines do not directly maintain these records.
- Pipeline execution remains a generator yielding text so actions can stream output as work happens.

The proposed `run()` contract is a text-yielding generator whose final PHP `return` value is a result object containing the reached state and relevant outcome data, such as the created artifact. Calling a generator method returns a `Generator`, not that result object directly. After fully consuming it, the action retrieves the result through `Generator::getReturn()`. For deployment preparation, the returned state may be published/awaiting installation rather than deployment completed.

A final result alone cannot report intermediate transitions while the generator is still running. The remaining decision is how pipelines signal those transitions independently of text output, for example through an action-provided lifecycle reporter. Host-side transitions must also reach the action-owned persistence boundary through the download/log callback flow after the build-server generator has finished. Define the exact input/result types, reporting API and exception behavior before finalizing the interfaces; do not infer lifecycle state from streamed message text.

### Running CLI commands

Use the `CliCommandRunner` from the core to run commands. It works well on all our server environments. 
