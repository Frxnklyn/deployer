# 01 - Dependency removal

## 1. Goal

Remove `deployer/deployer` from `axenox/deployer` as an early, independently releasable V3 milestone. Replace only the procedural build and deployment execution that requires the library; do not wait for the full V3 pipeline factory, prototype discovery, UXON editors, SQL migration or On-Demand Deployment.

The Composer requirement is removed only after the supported in-package execution paths have native replacements and the dependency-free test gates pass. Composer and `axenox/packagemanager` remain required.

## 2. Scope / non-goals

- In scope: complete call-site inventory, replacement gates, removal of Deployer bootstrap and procedural execution, regression and compatibility tests.
- Out of scope: removing Composer or `axenox/packagemanager`; introducing a Composer-free build path; inventing a generic workflow engine; removing supported workflow behavior without explicit migration or failure reporting.
- The new pipeline contracts and feature parity are owned by [02 Pipeline Architecture](02_Pipeline_Architecture.md), [03 Build Pipelines](03_Build_Pipelines.md), [04 Deployment Pipelines](04_Deployment_Pipelines.md), and [05 UXON and Migration](05_Uxon_and_Migration.md).

## 3. Current state and dependency inventory

The initial inventory was taken from the branch baseline before replacement. The table records the original dependency and the current treatment, so a deleted implementation is not mistaken for a completed V3 feature.

| Surface | Existing evidence / dependency | Required treatment |
|---|---|---|
| `composer.json` | Baseline required `deployer/deployer: ^6.5`, alongside `exface/core` and `axenox/packagemanager`. There is no package `composer.lock` in this repository. | The direct Deployer requirement is now removed. Composer, Core and PackageManager remain declared. |
| `Actions/Build.php` | Baseline generated `build.php`, loaded Deployer's common recipe and invoked `vendor/bin/dep`; recipes used `task()`, `get()`, `set()` and `runLocally()`. | Now invokes `Common/Execution/ComposerBuildExecutor.php` with argument-array processes; Composer install, PackageManager hooks, archive, lock and BOM remain. |
| Build recipe values | `ComposerInstall`, `ComposerInstallAssetFix`, `CloneLocal`, `CustomBuild`; the last two loaded/called procedural recipe files. | Both Composer values use the native executor; `CloneLocal` and `CustomBuild` now fail with a migration message. Enum/model values remain for compatibility; no SQL migration was added. |
| `Actions/Deploy.php` | Baseline generated `deploy.php`, prepared SSH files and invoked `vendor/bin/dep`. The custom selection path had a `CustomBuild`/`CustomDeploy` mismatch. | Now maps saved recipe values to `LegacyDeploymentExecutor`; unsupported SSH/custom/unknown selections fail explicitly. The saved enum and history columns remain unchanged. |
| Deployment recipe values | Nine options: updater push/pull/polling, USB manual transfer, Azure manual transfer, three SSH variants and `CustomDeploy`. | Native execution retains push, pull, polling, USB/manual and Azure/manual. SSH variants and arbitrary custom recipes fail explicitly rather than executing PHP recipe files. |
| `Recipes/` | Deployer task registration, shared configuration, local/remote shell helpers and recipe files. | The procedural task graph and legacy recipe entry-point files have been removed; the Actions/executors reject unsupported saved values. `SelfExtractingPHP/SelfExtractingDeployment.php` remains as the standalone `.phx` installer template; it contains the release/install behavior, not the Deployer engine. |
| `DataTypes/*RecipeDataType.php`, `Model/` | Enums and metamodel still select/store legacy recipe values. | Intentionally retained for transitional data compatibility. Prototype selection/editors and data migration remain in Roadmaps 02/05 and are not prerequisites for this milestone. |
| `Facades/DeployerFacade.php`, PackageManager callbacks | OTA download/log API and PackageManager self-update integration; no Deployer recipe-engine calls found. | Kept unchanged. The native pull artifact retains the `{build}_{hostAlias}.phx` name consumed by the facade. |
| `DeployTool.php`, `DataConnectors/DeployerSshConnector.php`, tests and docs | Standalone downloader and project-owned SSH connector are not `deployer/deployer` APIs. Documentation preserves historical references. | No external library bootstrap/call remains in these surfaces. The custom SSH connector is no longer invoked by the deployment Action. |

Repository-wide source inventory found no additional in-package namespaces/classes from the external library, generated recipe execution entry points or hidden helper consumers outside the surfaces above. The only other known direct dependency is in the separate local ExFace host project's `composer.json` (`C:\wamp64\www\exface\exface\composer.json`); it is outside this package worktree and was not changed. Its presence does not make `axenox/deployer` depend on the library, but it does mean the local host installation may still install it independently.

### Legacy call-site ledger

| Baseline file / function | Deployer API or mechanism | Behavior and affected selection | Replacement / compatibility treatment |
|---|---|---|---|
| `Actions/Build.php::prepareDeployerTask`, `performDeferred` | Generated `build.php`, included `recipe/common.php`, ran `vendor/bin/dep`. | Orchestrated every build selection. | Removed; dispatches Composer selections to `ComposerBuildExecutor`. |
| `Recipes/Build.php::build:create_from_composer` | `task/get/set/runLocally`; Core `CliCommandRunner` for Composer. | Composer install, PackageManager Composer hooks, archive and license BOM. | Composer remains; native executor runs Composer and tar with argument arrays, stages lock/BOM and retains PackageManager hooks. |
| `Recipes/Build/ComposerInstall.php` | Legacy Deployer task registration; file has been removed. | `ComposerInstall`. | Native Composer build is dispatched by the Action; the deleted recipe file is not needed at runtime. |
| `Actions/Build.php::getComposerJsonWithAssetFix` | Composer recipe selection plus prior successful build lock lookup. | `ComposerInstallAssetFix`. | Pure `AssetPackagistFix` maps prior npm/bower lock packages to CDN latest-cache repositories; requires a prior successful lock. |
| `Recipes/Build/CloneLocal.php` | Legacy procedural archive recipe; file has been removed. | `CloneLocal`. | The native executor explicitly rejects the selection and tells the user to migrate to `ComposerInstall`; no silent substitution. |
| Project `build_recipe_custom_path` and custom build recipe inclusion | Arbitrary PHP recipe file loaded by generated build script. | `CustomBuild`. | Path is not executed; clear unsupported-selection error. |
| `Actions/Deploy.php::createDeployPhp`, `createDeployerProjectFolder`, `createDeployerTask`, `performDeferred` | Generated `deploy.php`, common recipe include, `vendor/bin/dep`; staged SSH key/config files. | All deployment selections. | Removed from the active Action path; native executor maps only supported values. |
| `Recipes/SelfDeployment.php::self_deployment:create` (legacy file removed) | Deployer `task/get/set/runLocally`; Windows `copy /b`. | Previously built `{build}_{hostAlias}.phx` for push, pull/polling, USB/manual, Azure and SSH. | `DeploymentPackageBuilder` performs safe placeholder substitution and appends the archive bytes; native executor handles only supported selections. |
| Updater Push recipe | Deployer task chain plus Guzzle multipart POST; TLS verification was disabled and upload exceptions were printed then swallowed. | `LocalBldUpdaterPush`. | Native Guzzle upload verifies TLS and surfaces non-success as deployment failure; mocked HTTP regression test. |
| Updater Pull and Cron recipes | Deployer task chain, local artifact publication and status inferred from task-name text. | `LocalBldUpdaterPull`, `LocalBldUpdaterCron`. | Both publish the expected facade filename and return published status; Pull/Polling share the PackageManager SelfUpdate instructions. |
| USB and Azure manual recipes | Deployer task chain and output instructions; no automated host installation. | `LocalBldUsbSelfExtractor`, `LocalBldAzureAppServiceManualInstall`. | Native artifact generation plus explicit transfer/Azure instructions; does not claim host installation completed. |
| SSH SelfExtractor/Install/InstallWait recipes and `Recipes/Deploy.php` | Deployer remote `run/test/cd`, SSH upload/run, Cygwin path and release task graph. | `LocalBldSshSelfExtractor`, `LocalBldSshInstall`, `LocalBldSshInstallWait`. | Recipe sources and active SSH staging removed; saved selections fail with a migration message. |
| Project `deployment_recipe_custom_path` and custom recipe inclusion | Arbitrary PHP recipe loaded from configuration (including the old `CustomBuild` comparison mismatch). | `CustomDeploy` and custom values. | Custom paths are not loaded; error identifies the unsupported selection. |
| `Recipes/SelfExtractingPHP/SelfExtractingDeployment.php` | No Deployer namespace or recipe API; standalone PHP installer and PackageManager integration. | Release extraction, shared dirs, config merge, local packages/vendors, cleanup and rollback. | Retained as the payload template. Native packaging does not replace its installer behavior. |
| `Facades/DeployerFacade.php`, `DeployTool.php`, model enums, `DataConnectors/DeployerSshConnector.php` | No direct Deployer recipe-engine API. | OTA callbacks/downloads, standalone download, stored selectors and project-owned connector. | Facade and downloader remain; selectors and historical data remain; SSH connector is no longer invoked by this Action. |

The original, unmodified V3 requirements and complete legacy mapping remain in [Deployer_v3.md](Deployer_v3.md) and [04 Deployment Pipelines](04_Deployment_Pipelines.md).

## 4. Planned technical changes

1. **Dependency Inventory:** identify package, class/function, generated file, CLI, bootstrap, helper, action, facade, connector, model, test and documentation references. Separate the package's dependencies from a parent application's direct requirements.
2. **Replacement Implementation:** use the smallest reusable components compatible with later pipeline prototypes: a Composer build executor, a self-extractor builder and an explicit legacy-selection executor. Keep Action persistence/status responsibilities. Do not add a factory, prototype discovery or new UXON model.
3. **Compatibility and Regression Tests:** preserve the two Composer build choices and five supported updater/manual deployment choices; reject retired selections with a clear error. Test artifacts, lock/BOM, PackageManager hook metadata, status results, push upload and error paths.
4. **Final Dependency Removal:** remove the package requirement only after the production search and replacement tests show no supported path depends on the external library. Keep Composer and PackageManager.

## 5. Work packages

- [x] **Dependency Inventory:** mapped the package manifest, actions, recipe files, standalone installer, facade, enums/model, connector, tests and the separate host project's direct dependency.
- [x] **Replacement Implementation:** native build/archive/BOM execution and native `.phx`/updater/manual orchestration are in place; Composer and PackageManager remain.
- [x] **Compatibility mapping:** `ComposerInstall` and `ComposerInstallAssetFix` remain supported. Updater push/pull/polling, USB/manual and Azure/manual remain supported. `CloneLocal`, `CustomBuild`, `CustomDeploy`, SSH and unknown selections fail explicitly.
- [x] **Recipe coverage:** all 4 build values and all 9 deployment values are accounted for (2 build + 5 deployment retained; 2 build + 4 deployment rejected explicitly).
- [x] **Focused regression tests:** 9 native execution tests passed (43 assertions), including a real offline Composer install, archive/lock/BOM behavior, Asset-Packagist repository fix, `.phx` generation, updater push success/failure mocks and retired-recipe errors.
- [x] **Final package manifest change:** removed `deployer/deployer` from this package's `composer.json`. No package lockfile existed to update.
- [x] **Package source search:** no production `deployer/deployer` bootstrap, `vendor/bin/dep`, Deployer namespace, recipe `task()` or `runLocally()` reference remains in PHP implementation.
- [ ] **Dependency resolution in isolation:** `composer validate` passes with pre-existing publish warnings, but `composer update --dry-run` cannot resolve `exface/core` or `axenox/packagemanager` because this package manifest has no configured private repositories. Confirm resolution in the project's normal private Composer environment.
- [ ] **Host integration matrix:** run the updater callback/download, HTTP endpoint upload, actual manual/Azure host installation, PackageManager self-update and rollback on supported Windows/Unix hosts. These environments were not available during this change.
- [ ] **Legacy metadata retirement:** keep selectors and saved values for now; do not remove their enums/model columns or migrate history until Roadmap 05.

## 6. Dependencies

- The transitional executors are intentionally reusable within future Roadmap 02 pipeline prototypes; the complete interfaces/factory/discovery are not required to remove this library.
- Roadmaps 03 and 04 still own the full future pipeline architecture and feature parity, not a prerequisite for this early library-removal milestone.
- Roadmap 05 owns UXON editors, SQL migration and retirement of saved recipe metadata; none is required for the native compatibility mapping.
- Roadmap 06 owns new On-Demand Deployment, concurrency, lifecycle and stabilization; none is required to remove the recipe engine.
- Before claiming release-level cross-platform readiness, complete the remaining host integration matrix below.

## 7. Open decisions

- Whether other installations outside this app repository directly require `deployer/deployer`; the local ExFace host project does, independently.
- Minimum Core, PackageManager, PHP and OS versions for the new native helpers and supported archive command.
- Mixed-version behavior while a workbench, remote updater client or stored configuration is upgraded at a different time.
- Which retired SSH/custom/CloneLocal configurations are still in active use; current executor failure messages identify the selection but no persisted-record migration is performed.
- Whether deployments rely on Deployer-specific environment variables or command output not captured by the in-repository inventory.

All items above remain open unless separately resolved with documented evidence. The fact that a replacement is planned does not resolve them.

## 8. Acceptance criteria

- [x] In-package supported workflow selections have a native implementation; retired options produce explicit errors.
- [x] Composer build workflow and `axenox/packagemanager` Composer hooks remain available in the generated build.
- [x] No Composer-free build option or generic workflow editor was introduced.
- [x] This package's Composer manifest no longer requires Deployer; no package lockfile required updating.
- [x] Build/Deploy Actions no longer create or invoke Deployer recipe scripts; facade and PackageManager integrations remain.
- [ ] Full cross-platform host, updater callback and rollback integration tests pass.
- [x] Unsupported recipe selections fail rather than being silently skipped or reported as successful.
- [ ] Stored record migration and enum retirement are complete; these remain Roadmap 05 work.

### Test plan (run at the end)

1. Search production code for `deployer/deployer`, Deployer namespaces/functions, `vendor/bin/dep`, generated recipe bootstraps and `runLocally`; review any remaining historical docs separately.
2. Run `phpunit Tests/PHPUnit/Unit/NativeExecutionTest.php` with `COMPOSER_PHAR` set. Confirm Composer build artifact, lockfile, BOM, PackageManager hooks, Asset-Packagist fix, all retained deployment recipes, push response and unsupported errors.
3. Run `composer validate` and the project's normal private-repository `composer install/update`; confirm `exface/core` and `axenox/packagemanager` resolve while the package's dependency tree omits Deployer.
4. On Windows and Unix host fixtures, test updater push, pull callback/download, polling, USB/manual and Azure/manual installation, including rollback and status persistence.
5. Test all legacy values. Confirm `CloneLocal`, `CustomBuild`, SSH, `CustomDeploy` and unknown values fail before any false-success state is saved.
6. Only remove saved selectors/model metadata under Roadmap 05 after migrations, then rerun the focused and host integration matrix.
