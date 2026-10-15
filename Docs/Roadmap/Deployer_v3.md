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
	- Replace the static enums `BuildRecipeDataType` and `DeploymentRecipeDataType` with cross-app pipeline prototype discovery. Build prototypes live in `Pipelines/Build` and deployment prototypes in `Pipelines/Deploy`, using the corresponding app namespaces. Exact stored selector format and editor integration remain to be defined.
- Provide configurable templates, **not a general-purpose pipeline editor**. Users do not assemble arbitrary stages, dependencies or conditions. New workflows are added by developers as pipeline prototypes.
- Remove the external PHP deployer library. Its procedural recipe format has proven hard to extend and maintain for our needs.
- Each host owns its deployment pipeline prototype selection and UXON configuration, allowing different deployment strategies within one project. Move the legacy project-level recipe selection to the hosts. Pass all host attributes to pipeline instances through public setters, never through UXON properties. Pipeline UXON contains only settings without a corresponding host attribute. The host editor provides a prototype dropdown and a UXON editor for the selected prototype.
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

All retained self-extractor recipes currently share [SelfDeployment.php](../../Recipes/SelfDeployment.php) and the embedded [SelfExtractingDeployment.php](../../Recipes/SelfExtractingPHP/SelfExtractingDeployment.php) installer. Extract reusable package generation and host installation logic without making `.phx` a requirement of the generic deployment pipeline interface. Preserve host configuration, local vendors, shared/copied directories, installer execution, deployment log reporting and critical-error recovery. Pass all host attributes, including installation paths and the PHP executable, through public setters. Define release retention, local vendors and other pipeline-only settings as UXON properties only where no corresponding host attribute exists. Preserve the effective legacy `keep_releases` value, including its deployment-config override, during migration. Replace Windows-specific package concatenation and other shell assumptions with portable PHP operations or OS adapters.

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
| `path_to_installation_root` | Installation root from host `path_abs_to_api` through `setHostPathToInstallationRoot()`; no UXON equivalent. | Retain the root as a host attribute. |
| `relative_deploy_path` | Removed with host `path_rel_to_releases`, without substitution. | Intentional v3 breaking change and exception to configuration preservation. Do not migrate the value or preserve legacy relative-path layouts or empty-relative-path web-server behavior. |
| `php_path` | Host `php_cli` through `setHostPhpExecutable()`; no UXON equivalent. | Retain the host attribute; this is independent of build-server PHP selection. |
| `keep_releases`, `deploy_config.keep_releases` | Pipeline UXON `keep_releases` in the existing host `deploy_config`. | Keep the existing key and location. Preserve `deploy_config.keep_releases ?? keep_releases`, whose recipe default is `4`; do not keep duplicate sources. |
| `shared_dirs`, `copy_dirs` | Deployment UXON lists. | Preserve defaults and validate overlap/path boundaries. |
| `shared_files` | Candidate deployment UXON list, default `[]`, only for prototypes implementing file sharing. | Legacy SSH network tasks consume it, but retained self-deployment does not embed or install shared files. Do not silently advertise parity; implement and test before exposing it on self-extractor prototypes. |
| `config_dir` | Fixed `config` convention initially. | The self-extractor independently hard-codes `config`. Making it configurable requires updating every consumer, not just migrating this unused-on-that-path variable. |
| `deploy_config.local_vendors` | Deployment UXON `local_vendors`. | Preserve both local-app retention and vendor-directory copying behavior. |
| `deploy_config.local_packages` | Existing deployment UXON `local_packages`, unchanged. | Preserve listed packages by full Composer name (`vendor/package`), copying them unchanged from the previous release in place of the deployed copies, even when their vendor is included in the deployment. This is existing behavior, not a new feature or a transformation of `local_vendors`. |
| `deploy_config.default_app_config` | Pipeline UXON property `config` in the existing host `deploy_config`. | Rename only the property to clarify that these values override host application configuration. Preserve the filename map, values and merge behavior; do not move them into a separate payload. |
| `deploy_config` (other keys) | Existing host UXON used to configure the selected deployment pipeline prototype. | Keep supported keys such as `keep_releases` and `local_vendors` in place. Report unknown/custom keys for manual mapping rather than dropping them. |
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

## Pipeline configuration

The chapters shows how the new pipeline prototypes will be configured. It includes UXON examples and property lists per prototype.

### Configuration boundaries

Most persistent recipe options should become documented, typed UXON properties, but not every recipe variable is an option:

- Pipeline UXON defines repeatable workflow settings only where no corresponding host attribute exists, such as timeouts, archive contents, copied/shared directories, release retention and local vendors. All host attributes, including the installation root, PHP executable and operating system, are supplied through public setters and must not have duplicate UXON properties.
- This UXON configuration is build the actions like `\axenox\Deployer\Actions\Build` and `Deploy` from their input and from the metamodel entities these actions access. The actions then instantiate the corresponding prototype via `PipelineFactory`.
- Build-variant `config_uxon` supplies prototype settings, including Composer JSON/authentication for `ComposerInstall`. Build tasks supply run-specific values such as the target PHP version, version, comment and notes; do not duplicate those in pipeline UXON.
- Deployment tasks supply run-specific inputs and attempt identity; only release deployment tasks require a selected build artifact. Host attributes and resolved connections are supplied through setters. The existing host `deploy_config` UXON configures the selected pipeline prototype, including its `config` property for remote application-configuration overrides. Do not let pipeline UXON override the action-owned run identity or status.
- Host connection credentials remain in connection configuration, not duplicated in pipeline UXON. The `config` property contains remote application-configuration overrides and may contain sensitive values; redact these from logs and diagnostics and protect generated packages containing them. Secret storage/reference details remain part of the open security decision. Do not copy connection passwords or application secrets into examples or editor presets.
- Generated artifact/workspace paths and OS-specific command strings remain internal. Configuring a supported workflow is not configuring arbitrary shell commands or installer source files.

Omitted properties in the examples use the proposed prototype defaults below. Each example is independent, not an instruction to merge it with another JSON block. Installation paths and the PHP executable are host attributes, not properties in these JSON examples. A Windows host can use `C:/Applications/example` as its installation root and its own PHP executable; actions pass both through setters. Paths are interpreted by the selected host OS adapter, not by the build server's OS.

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
	"prototype_file": "\\axenox\\Deployer\\Pipelines\\Build\\ComposerInstall",
	"config_uxon": {
		"composer_timeout": 1200,
		"source_files": ["vendor", "composer.json", "composer.lock", "composer.phar"],
		"asset_packagist_cache_fix": true
	}
}
```

The workaround uses asset packages from the previous successful build's lock file. The no-previous-lock behavior still needs a decision; this example does not imply silently disabling the requested workaround.

### Shared deployment pipeline properties 

All deployment pipelines will need some common properties as they are instantiated for a `host` metaobject row. However, the pipeline prototypes themselves should not know the hosts model - they get all information they need from deployment actions. 

#### Host attributes

Every host will still have some global properties, which are needed for deployments too: 

| Host attribute | Purpose and proposed default | Pipeline API |
| --- | --- | --- |
| `path_abs_to_api` | Required absolute host installation root. | Method `setHostPathToInstallationRoot()` for all deployment pipelines
| `path_rel_to_releases` | Obsolete deployment directory below the installation root; removed in v3. | Removed without substitution or value migration; intentional major-version breaking change. |
| `php_cli` | Host PHP executable, default `php`; resolve/validate as an executable, not a free-form command. | Method `setHostPhpExecutable()` for all deployment recipes 
| `operating_system` | Host operating system | Public setter on deployment pipelines; build-server OS resolution is separate. |
| `project__alias` | Project subfolder in the deployer data folder. Pipelines will search for deployable artifacts there | Public methods to set artifact paths 
| `data_connection` | UID of the data connection for pipelines, that contact the host actively | Additional DeploymentViaConnectionInterface for corresponding pipelines. The interface shall add `setConnection(DataConnectionInterface)`.

All host attributes must be passed to pipeline instances through public setters, never through UXON properties. The table above is not an exception list: the same rule applies to every host attribute used by a pipeline. Actions resolve these values from the host model; pipeline prototypes do not read that model themselves. A setting may be exposed as a UXON property only if no corresponding host attribute exists. UXON must not provide an alternative value for, or override, a host attribute. The existing `deploy_config` attribute is the pipeline UXON container: its nested keys are prototype properties, not separate host attributes. Actions use that UXON to configure the prototype rather than introducing another configuration store or wrapping it in a duplicate UXON property.

### Shared self-extractor deployment properties

Push, pull, manual transfer, Azure manual transfer and on-demand prototypes share the applicable installation properties. A shared implementation should avoid duplicated property logic without making self-extractors mandatory for other deployment prototypes.

| Proposed property | Purpose and proposed default | Legacy source |
| --- | --- | --- |
| `config` | Required UXON property containing remote application-configuration overrides per config file, including centrally controlled critical settings. It remains inside the existing host `deploy_config` UXON used to configure the pipeline. | Rename `deploy_config.default_app_config` to `deploy_config.config`, preserving its filename-to-configuration map, values and merge behavior. Sensitive values require redaction and package protection. |
| `keep_releases` | Frequently used config Host release retention, default `4`; preserve the effective legacy override. Validation and protection of active/previous releases must follow the agreed retention rules. | `deploy_config.keep_releases`, otherwise recipe `keep_releases`. |
| `shared_dirs` | Directories persisted across releases, default `["backup", "data", "logs", "translations"]`. No real need to change this, but a visible config option is good. | Recipe `shared_dirs`. |
| `copy_dirs` | Directories copied from the previous release, default `["config"]`. No real need to change this, but a visible config option is good.  | Recipe `copy_dirs`. |
| `local_vendors` | Vendor namespaces whose local apps must survive replacement, default `[]`. Use almost every time. | `deploy_config.local_vendors`. |
| `local_packages` | Existing list of full Composer package names (`vendor/package`) to exclude from replacement, default `[]`. Copy these packages unchanged from the previous release instead of using the deployed copies, even when their vendor is included in the deployment. | `deploy_config.local_packages`; retain the key, values and existing behavior unchanged. |
| `php_memory_limit` | Host installer PHP memory limit, proposed default `2G`. | Hard-coded installer and execution instructions. |

Directory entries must be relative, bounded to the intended installation paths and non-conflicting. Do not allow traversal, accidental sharing of an entire release or a directory listed as both copied and shared. The installer currently fixes `config`, `releases`, `shared`, `current` and the public `exface` link; those names remain prototype conventions in these examples, not additional path knobs with unverified support.

### Self-extractor with Updater push

Replaces `LocalBldUpdaterPush`. The host's existing connection reference supplies the Updater URL and authentication. 

```json
{
	"keep_releases": 4,
	"local_vendors": ["examplevendor"],
	"php_memory_limit": "2G"
}
```

Upload errors must produce a failed preparation/transfer outcome with response evidence and manual recovery instructions. Successful upload alone must not be confused with confirmed host installation.

### Self-extractor with Updater pull

Replaces `LocalBldUpdaterPull`. Publication uses the existing authenticated facade/host relationship; download URLs and callback credentials are derived, not configured as duplicate UXON strings.

Typical configuration:

```json
{
	"keep_releases": 4,
	"local_vendors": ["client"],
	"php_memory_limit": "2G"
}
```

### Self-extractor with Updater polling

Replaces `LocalBldUpdaterCron` recipe, preserving scheduled-operation intent without pretending that the pipeline installs a scheduler. Currently its just a visual aid to distinguish between automatically and manually updated projects/hosts. It should extend `LocalBldUpdaterPull` class without changes.

The instructions printed in the end of the deployment must state, that the polling must be configured manually:

- by adding a background task in `Administration > BG Processing > Scheduler` to run `axenox.PackageManager.SelfUpdate` action or 
- by setting up a scheduler/CRON job on OS level running the action via CLI: `vendor/bin/action axenox.PackageManager:SelfUpdate`

### Self-extractor with manual transfer

Replaces `LocalBldUsbSelfExtractor`. This prototype prepares a downloadable package and OS-appropriate transfer, execution and recovery instructions; it does not perform transfer or claim installation success.

```json
{
	"keep_releases": 4,
	"local_vendors": ["examplevendor"],
	"php_memory_limit": "2G"
}
```

### Self-extractor with Azure manual transfer

Replaces `LocalBldAzureAppServiceManualInstall`. Proposed `app_service_name` replaces the unresolved name placeholder in the legacy Kudu instructions; credentials are still obtained separately. The paths below illustrate an App Service with persistent `/home` storage and require validation against the selected hosting layout.

```json
{
	"keep_releases": 4,
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
	"keep_releases": 4,
	"local_vendors": [],
	"php_memory_limit": "2G"
}
```

This demonstrates installation configuration only. Desired-build selection, first-instance behavior, request/retry identity, preparation wait limits and callback correlation remain unresolved. Do not add an apparently working `latest_build`, retry key or attempt ID property before those contracts are settled. Selected builds and attempt IDs belong to run/policy resolution, not fixed installer UXON. Persistent versus disposable container directories must also be decided before treating this example as a production container configuration.

## Pipeline instructions

All recipes printed further instructions in the command line when they finished. This is important as it helps deployment mangers understand, if they need to take manual steps and how.

Review current instructions.

- Makes sure they fit the pipeline logic
- Since we know, what OS the host runs, only print proposed commands for the expected OS.
- Make sure rollback instructions are easy to follow.
- Make sure automated pipelines (e.g. updater pull) include first-time instructions. Currently we use:
	```
	curl -v -L -u "<ota_user>:<ota_password>" -o install.phx "https://powerui.mydomain.com/api/proxy/ota/<projekt>/<host_uid>"
php -d memory_limit=2G install.phx
	```

## Open items and decisions

The tables below track remaining questions and recorded decisions, not additional approved features. Recommendations are starting points for decisions. The Outcome column records agreed answers, including partial decisions; Pending means no outcome has been recorded yet. Mark an item `[x]` once its full answer and acceptance criteria have been recorded in this roadmap; this does not mean the implementation is complete.

### Architecture and configuration

| Open item | What is unclear or missing | Decision to record | Outcome | Resolved |
| --- | --- | --- | --- | --- |
| Prototype contracts and ownership | Detailed task/result accessors, intermediate state reporting and execution failure handling remain open. | Complete the task/result APIs and intermediate state-reporting mechanism without mixing structured objects into the text stream. Specify transaction boundaries and exception handling. Decide whether ordinary pull uses the optional HTTP capability too. | Pipelines perform the work; actions own records and persist reported states. `PipelineInterface::run(PipelineTaskInterface $task): \Generator` is inherited unchanged by family interfaces. Generators yield text and return `PipelineResultInterface` with `getStatus(): int`, retrieved through `getReturn()`. Reject unsupported tasks before work starts. Specialized tasks support release and configuration-only deployment; generic deployment requires neither an artifact nor `.phx`. | [ ] |
| Host deployment pipeline ownership | No remaining ownership question; editor implementation is still required. | Verify that each host's selected prototype and settings are used, with discovered prototype selection and a matching UXON editor. | Each host owns its deployment pipeline selection and configuration. Actions supply all host attributes through public setters, never duplicate UXON properties. The existing `deploy_config` is the pipeline UXON container; its nested properties are not separate host attributes. Hosts in one project may use different pipelines. | [x] |
| Configuration location and precedence | General precedence among prototype defaults, stored UXON and run-specific task values remains open. | Define override rules for pipeline-only settings and migration precedence when target configuration already exists, including conflicting old/new property names. Do not duplicate host attributes in UXON. | Build variants use `prototype_file` and `config_uxon`; Composer JSON/authentication belong only to the Composer prototype. Reuse host `deploy_config`; rename `default_app_config` to `config`, preserving its map, values and merge behavior. Keep `keep_releases`, `local_vendors` and existing `local_packages` in place. Package-level exclusions remain independent of vendor-level deployment. Preserve the legacy retention override `deploy_config.keep_releases ?? keep_releases`, with fallback `4`. | [ ] |
| Prototype discovery and editor integration | Exact stored path syntax, discovery/factory validation, editor schemas and prototype-switch behavior remain open. | Define app-qualified path serialization, cross-app discovery, wrong-family/missing-prototype diagnostics, schemas for build `config_uxon` and host `deploy_config`, presets and validation. Preserve existing UXON when changing prototypes without silent loss. Settle Azure specialization structure. | Use `Pipelines/Build` and `Pipelines/Deploy` with corresponding app namespaces; helpers live in `Common/Pipelines`. Selector classes, discovery metaobjects and `PipelineFactory::createPipelineFromUxon()` are named below. Selectors accept paths or PHP classes; store paths in the database. Asset-fix is a Composer setting; polling subclasses pull. Editors configure supported templates, not arbitrary stages. | [ ] |
| Migration and mixed-version operation | The executable upgrade procedure, unsupported-recipe handling and mixed-version compatibility remain open. | Define idempotent migration with a preview/report, existing-target precedence and manual intervention for unsupported clone/custom/SSH recipes. Explain upgrade recovery. Record minimum Core/PackageManager/PHP versions and support for existing URLs and older clients. | Preserve projects, variants, hosts and historical runs. Use SQL for moved attributes and recipe selections, omit empty migrated UXON properties and rename removed database structures to `trash_*`. Reuse host UXON and rename only `default_app_config` to `config`. Remove `path_rel_to_releases` and `relative_deploy_path` without substitution, value migration or legacy-layout guarantees: an approved v3 breaking change. | [ ] |
| OS adapters and standalone bootstrap | A generated self-extractor runs before the new release's autoloader is available, and a fresh container may have no existing Core installation. `CliCommandRunner` cannot simply be assumed available at that point. | Define the supported build-server/host OS and PHP matrix, adapter operations, selection and prerequisites. Decide how a standalone `.phx` receives the required adapter/runner code without loading the installation it is replacing. Include archive extraction, symlink permissions, executable discovery and web-server configuration. | Use Core `CliCommandRunner` for commands; standalone provisioning and adapter contracts remain undecided. | [ ] |

### On-demand deployment semantics

The current [DeployerFacade](../../Facades/DeployerFacade.php) already exposes `?redeploy=true`, selects pending deployments in the range `60 <= status < 90`, sets status `62` before the response stream is consumed, and intends to reuse the last successful deployment when no pending one exists. The new design must specify which behavior is retained and which is replaced.

| Open item | What is unclear or missing | Decision to record | Outcome | Resolved |
| --- | --- | --- | --- | --- |
| Complete state machine | The on-demand section repeats the `>= 62 and < 90` rule. It does not cover preparation (the Deploy action uses `50`), failure (`90`), success (`99`), no history or abandoned attempts. Returning a pending deployment at `60` also needs reconciliation with "a new deployment every time". | Replace the duplicate rules with a complete transition table. Define publication versus download versus installation completion, timeout/abandonment states and whether reuse at `60` is an explicit exception to one attempt per request. | Pending. | [ ] |
| Desired release versus last successful release | "Latest release" and "last successful deployment" are not equivalent. If a newly published build is being installed, fails or has not yet succeeded on this host, selecting the last successful attempt can return an older build. | Define an authoritative desired/published build for each host or project, how variants are selected, and whether failed installation changes that target. Decide first-container behavior, promotion, deliberate rollback and what happens when the target build's artifact is missing. | Pending. | [ ] |
| Container instance identity and callback correlation | Several instances can share one configured host. Current log callbacks select its latest pending deployment rather than addressing a specific attempt, so overlapping requests/logs need a new correlation contract. | Keep the host as deployment policy and give each attempt a unique ID; decide whether instance IDs are also needed. Bind authenticated downloads and callbacks to the correct attempt. Specify backward compatibility for clients that send only the host UID. | Pending. | [ ] |
| Concurrent requests, artifacts and retries | Generated `.phx` names currently depend on build and host, and the Deploy action generates a project-level `deploy.php`. Fresh attempts for the same host/build must not overwrite configuration, mix logs or delete artifacts still being downloaded. Client retries must not accidentally create unlimited installations. | Define isolation/locking, unique workspace and artifact ownership, and atomic publication. Distinguish a new instance request from retrying the same attempt, including interrupted downloads, retry keys and whether immutable build data can be reused safely. | Pending. | [ ] |
| Waiting and HTTP behavior | Preparation may outlast HTTP/proxy limits; protocol and deadlines remain open. | Choose bounded synchronous preparation or an asynchronous prepare/poll protocol supported by the boot client. Specify timeout, cancellation, no-release/error responses, cache policy, retry guidance and the future meaning of `?redeploy=true`. | Wait only until the package is ready to download, not until host installation completes. | [ ] |
| Container persistence and rollout | Rebuilding deployment records does not define what survives container restart, how all replicas receive a new release, or how concurrent instances run database installers. | Specify persistent/shared versus disposable paths, startup readiness and failure behavior. Decide whether updates apply only at startup or trigger an external rollout. Define who serializes database migrations across replicas and how incompatible application/database versions are handled. | Pending. | [ ] |

### Missing safeguards and completion criteria

| Open item | What is unclear or missing | Requirement or decision to record | Outcome | Resolved |
| --- | --- | --- | --- | --- |
| Critical failure, rollback and downtime | Retaining existing installation logic does not define exactly when a release is safe to activate. Switching a symlink back cannot necessarily undo a partially applied database migration. | Define critical versus non-critical failures, activation order, health/readiness checks and rollback guarantees for files, configuration and databases. Keep the previous working release until success is confirmed, and state manual recovery boundaries and expected downtime. | Pending. | [ ] |
| Credentials, artifact trust and execution permissions | Secret storage/reference details, bootstrap authorization, package access controls and integrity verification remain open. | Define secret storage/references and redaction coverage, host and attempt authorization, bootstrap credentials and least-privilege execution. Decide whether checksums or signatures are needed. Ensure generated execution never incorporates unvalidated operator input. | Connection credentials remain in connection configuration. Pipeline UXON `config` may contain sensitive application overrides; redact these and protect generated packages. Verified TLS is required. | [ ] |
| Logs, exit codes and remote failures | Intermediate/remote status handling and durable logging remain open; remote evidence must survive callback failures. | Define command exit-code mapping and remote failure reporting. Correlate logs with attempts, define buffering/retry of remote callbacks and ensure logging failures do not prevent cleanup or rollback. Decide retention/redaction policy. | Generators stream text only; final status comes from `PipelineResultInterface`, not inferred console text. Actions persist run states. | [ ] |
| Reproducibility and cleanup | Preserving Composer install alone does not define lock-file policy, failed-build isolation or artifact retention. On-demand attempts can accumulate indefinitely even if host release retention is bounded. | Define how dependencies are resolved/pinned and how archived lock files/BOMs describe the actual build. Set separate retention rules for build archives, host-tailored packages, attempts and logs. Protect promoted/in-use artifacts and clean failed workspaces without affecting concurrent jobs. | Pending. | [ ] |
| Verification and cutover | Prototype skeletons and documentation alone do not establish behavior parity or a release gate. | Add focused PHPUnit coverage for pipeline contracts, configuration migration and state transitions, plus end-to-end build/install/download tests on supported OS combinations. Include simultaneous container startup, repeated/interrupted downloads, callback attribution, failed installation and older-client compatibility. Remove legacy execution and the dependency only after replacement pipelines pass their acceptance checks. | Pending. | [ ] |

Implementation should start once the configuration owners, lifecycle contracts and on-demand state/identity rules are settled. These choices determine the model migration and interfaces; leaving them until after prototype implementation risks incompatible contracts and unsafe concurrent deployments.

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

Every host attribute used by a pipeline remains authoritative on the host and is passed to the pipeline through a public setter. This includes the installation root, PHP executable, operating system and connection reference; none may also be defined as a UXON property. Pipeline UXON contains only settings without a corresponding host attribute. Only prototypes that need such a setting expose it; for example, release retention is relevant to release-directory strategies, not every deployment pipeline.

Host identity, project membership and connection references remain distinct from pipeline-specific settings. Reuse the existing host `deploy_config` UXON as configuration for the selected deployment pipeline prototype. Rename its `default_app_config` property to `config`; other supported properties, including `keep_releases` and `local_vendors`, remain in their existing location. The nested `config` property distinguishes remote application-configuration overrides from settings controlling deployment itself; it is not a separate task payload or configuration store.

The Deploy action resolves the selected prototype and UXON from each target host. Long-term deployment settings belong in that host's pipeline configuration; the deployment task supplies values for the particular run. The same host-specific pipeline selection applies when the facade handles a download/on-demand request.

### Build variant configuration

Each build variant owns its build pipeline configuration, so variants in the same project can select different build prototypes and settings. Store the selected prototype in `prototype_file` and its UXON settings in `config_uxon`; migrate the legacy project-level build recipe selection and variant settings into this configuration. The build-variant editor provides a prototype selector and an editor for the selected prototype's documented UXON properties. `ComposerInstall` alone defines the `composer_json` and `composer_auth` properties; non-Composer prototypes do not need to expose them.

The Build action resolves the selected prototype and UXON from the build variant. Run-specific values remain on the build task; define their precedence relative to prototype defaults and stored UXON as part of the open configuration contract.

### Namespaces

Pipelines shall have their own namespace:

- `axenox\Deployer\Pipelines`
- `axenox\Deployer\Pipelines\Build`
- `axenox\Deployer\Pipelines\Deploy`

Only usable pipeline prototypes live in these namespaces. Abstract classes and helper go in `axenox\Deployer\Common\Pipelines`.

### Selectors

Pipeline prototypes will need selector class `axenox\Deployer\Common\Selectors\PipelinePrototypeSelector` and a corresponding interface `PipelinePrototypeSelectorInterface`. A prototype selector can either be a file path (we are going to store paths in the DB) or a namespaced PHP class. Very similar to `CommonLogic/Selectors/CommunicationMessageSelector.php`.

To make prototypes selectable in the UI we will need a metaobject for every pipeline subfolder:

- `axenox.Deployer.pipeline_prototype_build`
- `axenox.Deployer.pipeline_prototype_deploy`

Theses can be built similarly to `exface.Core.COMMUNICATION_MESSAGE_PROTOTYPE`.

### Factories

The main purpose of the selector classes ist to standardize factory methods. We will have a central `axenox\Deployer\Factories\PipelineFactory` similar to the `AiFactory`.

It shall have the following methods:

- `createPipelineFromUxon($workbench, $selectorOrString, $uxon)`
- Possibly other convenience methods.

### Interfaces

`Build` and `Deploy` actions shall instantiate pipeline prototypes and tasks for them, pass the task to the `run()` method of the prototype, stream text output and then analyze the result object.

The task object contains all the things the user has defined for this particular run. Long-term configs are part of the build and deployment UXONs.

#### PipelineInterface

- Extends `iCanBeConvertedToUxon` and provides the common executable contract for current and future pipeline families.
- Declares `run(PipelineTaskInterface $task): \Generator`.
- The generator yields text only. Its final PHP `return` value implements `PipelineResultInterface`; the action retrieves it through `Generator::getReturn()` after fully consuming the generator.
- All family interfaces and implementations retain this parameter type. Do not narrow the PHP declaration to a specialized task type, which would violate parameter variance. PHPDoc may describe the supported task and generator result more precisely.
- Implementations validate the supported task type and reject incompatible tasks explicitly before performing work.

#### BuildPipelineInterface

- Extends `PipelineInterface` as a build-family marker; inherits `run(PipelineTaskInterface $task): \Generator` unchanged.
- Build prototypes accept build tasks and return a `BuildResult` implementing `PipelineResultInterface` through the generator's final return value.

#### DeploymentPipelineInterface

- Extends `PipelineInterface` as a deployment-family marker; inherits `run(PipelineTaskInterface $task): \Generator` unchanged.
- Deployment prototypes accept the deployment task appropriate to their workflow and return a `DeploymentResult` implementing `PipelineResultInterface` through the generator's final return value.
- Neither this interface nor the common deployment task/result requires a release artifact or a `.phx` file. Release installation and configuration-only changes are both deployment workflows.

#### PipelineTaskInterface

- Common base for run-specific input objects across pipeline families. Detailed shared accessors remain to be defined.
- Specialized task interfaces describe workflow-specific inputs without changing the common `run()` declaration.

#### BuildTaskInterface

- Extends `PipelineTaskInterface`.
- `getComment()`
- `getNotes()`

#### DeploymentTaskInterface

- Extends `PipelineTaskInterface` for deployment run inputs; does not require a build artifact.
- `ReleaseDeploymentTaskInterface` extends it and requires the selected build artifact for release installation.
- `ConfigurationDeploymentTaskInterface` extends it for configuration-only changes without transporting a release.
- These task distinctions allow a future configuration-change deployment prototype without forcing it through release packaging. They do not introduce arbitrary stage composition or define that future prototype's transport and activation behavior.

#### PipelineResultInterface

- Common result contract with `getStatus(): int` so the owning action can persist the reached state.
- `BuildResult` and `DeploymentResult` implement this interface; workflow-specific outcome data belongs in the applicable result, not in the text stream.
- Detailed result accessors remain to be defined. Artifact metadata is required only for workflows that produce or transport an artifact.

### Class names

Pipelines should inherit their class names from recipes to make them easy to recognize after the upgrade.

### Ownership and streaming contract

The following ownership decisions are agreed:

- Each build variant owns the selected build pipeline prototype and its UXON configuration through `prototype_file` and `config_uxon`. `composer_json` and `composer_auth` are UXON properties of the `ComposerInstall` prototype, not separate build-variant attributes.
- A build pipeline owns the logic that creates a deployable artifact.
- A deployment pipeline owns the logic that applies a change to a host. For release deployment, this includes build-server-side preparation/transfer and host-side installation logic, typically delivered in a self-extractor. The generic contract also supports configuration-only changes without transporting a release, and requires neither a build artifact nor a self-extractor.
- Each host owns the deployment pipeline prototype selection and UXON values for pipeline-only settings. Actions pass all host attributes through public setters. Prototypes expose UXON properties only where no corresponding host attribute exists; UXON cannot duplicate or override host attributes.
- The Build and Deploy actions own their respective metaobject records, which track pipeline runs. Pipelines report which states are reached and when; the actions persist those transitions. Pipelines do not directly maintain these records.
- Pipeline execution remains a generator yielding text so actions can stream output as work happens.

The agreed `run(PipelineTaskInterface $task): \Generator` contract is a text-yielding generator whose final PHP `return` value implements `PipelineResultInterface`, containing the reached state and relevant workflow-specific outcome data, such as a created artifact where applicable. Build and deployment family interfaces inherit this signature unchanged; implementations reject unsupported task types before work starts. Calling a generator method returns a `Generator`, not that result object directly. After fully consuming it, the action retrieves the result through `Generator::getReturn()`. For deployment preparation, the returned state may be published/awaiting installation rather than deployment completed.

A final result alone cannot report intermediate transitions while the generator is still running. The remaining decision is how pipelines signal those transitions independently of text output, for example through an action-provided lifecycle reporter. Host-side transitions must also reach the action-owned persistence boundary through the download/log callback flow after the build-server generator has finished. Define the exact input/result types, reporting API and exception behavior before finalizing the interfaces; do not infer lifecycle state from streamed message text.

### Running CLI commands

Use the `CliCommandRunner` from the core to run commands. It works well on all our server environments. 

## Migration

IMPORTANT: When an existing deployer installation is upgraded to version 3, all data MUST be migrated except for explicitly approved breaking changes below.

`path_rel_to_releases` and its recipe equivalent `relative_deploy_path` are an approved exception: remove them without substitution or value migration. V3 does not preserve layouts or empty-relative-path web-server behavior controlled by this setting. This is an intentional major-version breaking change, not an unresolved migration requirement. The removed database column must still follow the `trash_*` rule below.

- All projects, build variants, hosts, builds and deployments must still be there after the upgrade
- Attributes that move from one object ot another MUST get SQL migrations.
- Recipes selected for projects must be migrated to pipeline prototypes in hosts and build variants via SQL
- Reuse host `deploy_config` as deployment pipeline UXON. Rename `default_app_config` to `config` within it while preserving its values and merge behavior; keep other supported properties such as `keep_releases` and `local_vendors` in place.
- Preserve host attributes as the authoritative source and pass their values through setters. Do not migrate installation root, PHP executable or other host attributes into duplicate pipeline UXON properties. Only settings without a corresponding host attribute belong in pipeline UXON.
- Attributes, that are transformed to UXON properties must be migrated via SQL if they have non-empty values: e.g. only add `composer_auth` to the `config_uxon` of a build variant if there really was something configured - no just and empty JSON `{}`, empty string or `NULL`.
- Deleted tables and columns must be renamed to `trash_*` according to our SQL migration rules.