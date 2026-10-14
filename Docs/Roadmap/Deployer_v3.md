Version 3 of `axenox.Deployer` replaces procedural recipes with configurable build and deployment pipeline prototypes.

## Goals

- Remove the `deployer/deployer` dependency.
- Provide proven, purpose-specific pipeline templates that can be configured without extensive build or deployment knowledge.
- Use UXON pipeline prototypes with autosuggest, documentation, defaults and validation to simplify configuration and extension, including deployment through DevOps services.
- Keep build variants unchanged as Composer JSON and related Composer settings.
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
- Move deployment pipeline selection and configuration from the project to each host, allowing different deployment strategies within one project. Build variants continue to supply Composer settings.

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

## Legacy recipe-to-pipeline migration checklist

This inventory covers all four build and nine deployment options currently exposed by [BuildRecipeDataType](../../DataTypes/BuildRecipeDataType.php) and [DeploymentRecipeDataType](../../DataTypes/DeploymentRecipeDataType.php). Legacy recipe names, source paths and configuration values are retained here to make migration traceable. The inventory is based on the implementations and their selection logic in the Build and Deploy actions, not on live execution. A selectable option is not necessarily a working implementation. Mark the Done column with `[x]` only after its v3 change and configuration migration have been verified.

### Build recipes

| Current recipe | Legacy value | Current implementation | Required v3 change | Done |
| --- | --- | --- | --- | --- |
| Build via Composer | `ComposerInstall` | [ComposerInstall.php](../../Recipes/Build/ComposerInstall.php) uses the shared Composer build tasks to install dependencies, prepare installer scripts, create a `.tar.gz` archive, stage the package BOM and remove the temporary vendor folder. | Replace procedural tasks with a Composer build pipeline prototype. Preserve Composer configuration, authentication, timeout, archive contents, lock file and BOM capture. Run commands through `CliCommandRunner` and move platform-specific archive and cleanup operations to OS adapters. Migrate the legacy recipe value to pipeline configuration, retaining existing build variant settings. | [ ] |
| Build via Composer + asset FIX | `ComposerInstallAssetFix` | Uses the same Composer recipe. [Build.php](../../Actions/Build.php) injects asset-packagist latest-cache repositories using asset packages from the last successful build's lock file. There is no separate recipe file. | Preserve the workaround as a documented, optional Composer prototype setting rather than a separate procedural recipe. Migrate this value with the workaround enabled and define behavior when no previous successful lock file exists. | [ ] |
| Clone current installation | `CloneLocal` | [CloneLocal.php](../../Recipes/Build/CloneLocal.php) archives selected files from the running installation and stages its package BOM without installing dependencies. | Remove the recipe and clone-only tasks. Detect projects still selecting it and require an explicit replacement with a Composer build configuration; do not silently treat a local installation copy as an equivalent Composer build. | [ ] |
| Custom build recipe | `CustomBuild` | [Build.php](../../Actions/Build.php) loads the project's `build_recipe_custom_path` instead of a bundled recipe. | Remove arbitrary procedural recipe-path selection. Port custom workflows still in use to app-owned build pipeline prototypes, then migrate their project configuration to those prototypes. Flag paths without a known replacement for manual migration. | [ ] |

### Deployment recipes

All retained self-extractor recipes currently share [SelfDeployment.php](../../Recipes/SelfDeployment.php) and the embedded [SelfExtractingDeployment.php](../../Recipes/SelfExtractingPHP/SelfExtractingDeployment.php) installer. Extract reusable package generation and host installation logic without making `.phx` a requirement of the generic deployment pipeline interface. Preserve host configuration, local vendors, shared/copied directories, installer execution, deployment log reporting and critical-error recovery. Move release retention (`keep_releases`, including its deployment-config override) into the deployment pipeline prototype. Replace Windows-specific package concatenation and other shell assumptions with portable PHP operations or OS adapters.

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

## Open items and decisions

The items below are unresolved requirements, not additional approved features. Recommendations are starting points for decisions. Mark an item `[x]` once its answer and acceptance criteria have been recorded in this roadmap; this does not mean the implementation is complete.

### Architecture and configuration

| Open item | What is unclear or missing | Decision to record | Resolved |
| --- | --- | --- | --- |
| Prototype contracts and ownership | Ownership is settled: pipelines own artifact creation/deployment logic; actions own build/deployment records and persist states reported by pipelines. Pipelines stream text through generators. A final result object containing a state is proposed; exact contracts and intermediate state reporting remain open. | Define the execution inputs, `perform()` signature, final result shape and intermediate state-reporting mechanism without mixing structured objects into the text stream. Specify transaction and exception handling. Keep HTTP handling and self-extractor generation optional; decide whether ordinary pull uses the HTTP capability too. See the ownership and streaming contract below. | [ ] |
| Configuration location and precedence | Build variants remain unchanged as Composer JSON and related Composer settings. Pipeline configuration is a separate concern; its storage location and override rules are not yet settled. Deployment pipeline selection moves to hosts. | Decide where build pipeline UXON is stored without redesigning build variants. Specify precedence among pipeline defaults, existing variant settings, host configuration and action overrides; distinguish pipeline settings from connection secrets and application configuration sent to hosts. Decide whether retention may still be overridden in host deployment config. | [ ] |
| Prototype discovery and editor integration | `BuildPipelines` and `DeploymentPipelines` are proposed folder names, but canonical paths, selector format and UXON schemas are not defined. Removing enums alone does not provide autosuggest or validation. | Choose consistent folder/namespace conventions, discovery/factory behavior and app-qualified selectors. Specify model attributes, template choices, editor schemas, presets, validation and diagnostics for missing prototypes. Decide whether asset-fix, scheduler and Azure variants are classes or settings/presets. Editors expose supported settings, not arbitrary stage composition. | [ ] |
| Migration and mixed-version operation | Automatic migration is a goal rather than a defined procedure. Project-to-host copying, removed custom/SSH values and old PackageManager clients need explicit treatment. | Define an idempotent migration with a preview/report, precedence for existing host settings and manual intervention for unsupported recipes. Preserve historical records and explain recovery if upgrading fails. Record minimum Core/PackageManager/PHP versions and whether existing download/log URLs and configuration remain supported during rollout. | [ ] |
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
- Move release retention into the applicable deployment pipeline configuration. It belongs to a deployment strategy with switchable release directories, not to every possible host or pipeline.


## Technical details of the new architecture 

### Object oriented approach

- Implement workflows as object-oriented pipeline prototypes instead of procedural recipes.
- Follow UXON prototype principles and expose only supported configuration settings.
- Keep internal workflow stages in developer-maintained code; do not introduce a general-purpose pipeline editor.

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

#### ComposerBuildTaskInterface

- `getComposerJson()`
- `getComposerAuthJson()`

#### DeploymentTaskInterface



### Ownership and streaming contract

The following ownership decisions are agreed:

- Build variants remain as they are: they represent `composer.json` and related Composer settings. Converting legacy recipes to pipeline prototypes does not require redesigning variants.
- A build pipeline owns the logic that creates a deployable artifact.
- A deployment pipeline owns the logic that deploys a build artifact. It controls both build-server-side deployment preparation/transfer and host-side installation logic, typically delivered in a self-extractor. The generic contract must also allow strategies without a self-extractor.
- The Build and Deploy actions own their respective metaobject records, which track pipeline runs. Pipelines report which states are reached and when; the actions persist those transitions. Pipelines do not directly maintain these records.
- Pipeline execution remains a generator yielding text so actions can stream output as work happens.

The proposed `perform()` contract is a text-yielding generator whose final PHP `return` value is a result object containing the reached state and relevant outcome data, such as the created artifact. Calling a generator method returns a `Generator`, not that result object directly. After fully consuming it, the action retrieves the result through `Generator::getReturn()`. For deployment preparation, the returned state may be published/awaiting installation rather than deployment completed.

A final result alone cannot report intermediate transitions while the generator is still running. The remaining decision is how pipelines signal those transitions independently of text output, for example through an action-provided lifecycle reporter. Host-side transitions must also reach the action-owned persistence boundary through the download/log callback flow after the build-server generator has finished. Define the exact input/result types, reporting API and exception behavior before finalizing the interfaces; do not infer lifecycle state from streamed message text.

### Running CLI commands

Use the `CliCommandRunner` from the core to run commands. It works well on all our server environments. 
