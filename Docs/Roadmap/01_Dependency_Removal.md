# 01 - Dependency removal

## 1. Goal

Remove the external `deployer/deployer` package and every supported reliance on its runtime, procedural recipe functions, helpers and configuration. This is the first roadmap's priority, but deleting the Composer requirement is the **last implementation step** after replacement paths and acceptance tests pass.

## 2. Scope / non-goals

- In scope: complete call-site inventory, replacement gates, removal of Deployer bootstrap and procedural execution, regression and compatibility tests.
- Out of scope: removing Composer or `axenox/packagemanager`; introducing a Composer-free build path; inventing a generic workflow engine; removing supported workflow behavior without explicit migration or failure reporting.
- The new pipeline contracts and feature parity are owned by [02 Pipeline Architecture](02_Pipeline_Architecture.md), [03 Build Pipelines](03_Build_Pipelines.md), [04 Deployment Pipelines](04_Deployment_Pipelines.md), and [05 UXON and Migration](05_Uxon_and_Migration.md).

## 3. Current state

Reviewed implementation on the branch baseline:

| Surface | Existing evidence / dependency | Required treatment |
|---|---|---|
| `composer.json` | Requires `deployer/deployer: ^6.5`; also requires `exface/core` and `axenox/packagemanager`. | Keep Composer, Core and PackageManager. Remove only the Deployer requirement after all gates below pass. |
| `Actions/Build.php` | Requires Deployer recipe helpers (`vendor/deployer/deployer/recipe/common.php`) and selects/runs recipe tasks. Uses `BuildRecipeDataType`, including custom build and Asset-Packagist selection. | Replace execution with Build pipeline factory, task, generator/result processing, preserving behavior from Roadmap 03. |
| `Actions/Deploy.php` | Uses recipe selection and creates/runs Deployer deployment tasks. Contains the `CustomDeploy` selection mismatch: it checks `BuildRecipeDataType::CUSTOM_BUILD` rather than `DeploymentRecipeDataType::CUSTOM_DEPLOY`. | Replace with pipeline execution, preserve useful status/output handling, explicitly detect/report legacy custom deployment configuration. |
| `Recipes/Build.php`, `Recipes/Deploy.php`, `Recipes/Config.php` | Deployer namespaces, task registration, `get`/`set`, `run` and recipe configuration. | Remove procedural task graph after its supported behaviors have replacement implementations and tests. |
| `Recipes/Build/ComposerInstall.php` | Registered `ComposerInstall` task delegates Composer build operations. | Preserve Composer as the build tool; move the supported workflow to the Composer build prototype. |
| `Recipes/Build/CloneLocal.php` | Registered `CloneLocal` recipe. | Remove only with an explicit unsupported-configuration report and replacement instructions; do not silently treat it as equivalent to a Composer build. |
| `Recipes/Deploy/*.php` | Nine selectable legacy deployment options. Some tasks call SSH, Cygwin or shell commands; selectable does not prove a path works reliably. | Migrate retained supported paths; detect and report retired SSH/custom configurations. Full mapping is in Roadmap 04. |
| `Recipes/SelfDeployment.php`, `SelfExtractor.php`, `Network.php`, `RemoteWindows.php`, `SelfExtractingPHP/*` | Shared packaging/install code plus Deployer task registration and platform-specific commands. | Retain required behavior in pipeline/installer/OS adapters; remove Deployer-specific scaffolding only after consumers are replaced. |
| `DataTypes/BuildRecipeDataType.php`, `DataTypes/DeploymentRecipeDataType.php`, `Model/01_DATATYPE.json` | Static recipe enums are still used and registered in metamodel metadata. | Remove only after pipeline prototype discovery and UXON editors replace all selectors. |
| `Facades/DeployerFacade.php` | Exposes current download/on-demand behavior and delegates to Deploy; not itself necessarily a Deployer-library call site. | Keep necessary facade behavior; update its integration only under Roadmaps 02/04/06. Do not remove PackageManager updater integrations. |

The legacy source includes `task()` registrations and `run()` calls across recipes. `Actions/Build.php` directly includes the Deployer common recipe. These are concrete blocker examples, not an exhaustive substitute for the completion inventory below. The unmodified original requirements and migration policy remain in [Deployer_v3.md](Deployer_v3.md).

## 4. Planned technical changes

1. Inventory all direct and indirect references: namespaces/classes/functions, bootstrap includes, command invocations, composer configuration, recipe inheritance, task names, model selectors, tests and documentation.
2. For each supported legacy behavior, identify the replacement pipeline, shared helper or operating-system adapter and its owning Action. Track explicit exceptions and unsupported options with migration/report behavior.
3. Replace Build/Deploy procedural orchestration with the common contract and result flow. Actions continue owning records and persisting run states; pipeline implementations do the work.
4. Remove Deployer task registration, Deployer-specific command assumptions and the package bootstrap when no retained path needs them.
5. Remove the dependency declaration only after the final checklist is evidence-checked. Composer and PackageManager remain dependencies.

## 5. Work packages

- [ ] **Inventory:** enumerate every Composer package/API/runtime reference and every Deployer `task`, `run`, `get`, `set`, recipe include, command and configuration value in the repository.
- [ ] **Trace:** map each call site to a supported user-visible behavior and its replacement roadmap/work package.
- [ ] **Define gate:** document legacy-option migration/error handling, supported PHP/OS versions and how mixed-version installations behave.
- [ ] **Replace Build paths:** complete Roadmap 03, including Composer install, Asset-Packagist fix, archive creation, BOM/lock and custom/clone behavior.
- [ ] **Replace Deploy paths:** complete supported options and removal reporting in Roadmap 04; preserve required updater and PackageManager behavior.
- [ ] **Replace shared task helpers:** provide portable operations via pipelines/adapters; identify and remove unused Deployer-only utilities.
- [ ] **Replace selectors and metadata:** complete prototype selection/discovery and editors in Roadmaps 02/05 before deleting recipe enum registrations.
- [ ] **Migrate stored selections/settings:** complete the SQL and data-preservation gates in Roadmap 05; verify old unsupported values fail clearly, never silently.
- [ ] **Search gate:** repository-wide search finds no runtime dependency on Deployer symbols/functions, bootstrap files, recipe files or undeclared Deployer command paths. Any remaining mention must be historical documentation or a test asserting absence.
- [ ] **Test gate:** all focused and end-to-end criteria below pass on supported environments; no retained workflow depends on the package.
- [ ] **Final manifest change:** remove `deployer/deployer` from `composer.json`, update the lockfile through the repository's normal dependency workflow, then repeat the search and tests.

## 6. Dependencies

- Roadmap 02 defines the only replacement execution contracts and ownership boundary.
- Roadmaps 03 and 04 replace the supported build and deployment behavior.
- Roadmap 05 migrates selectors, UXON settings and saved records before legacy recipe metadata is removed.
- Roadmap 06 defines on-demand lifecycle and cross-platform stabilization gates; a release depending on on-demand deployment cannot claim final readiness until those gates pass.
- Do not duplicate their design details here; link to the owning roadmap and track each dependency as incomplete until verified.

## 7. Open decisions

- Exact complete list of indirect Deployer API consumers and any callers outside the app repository.
- Minimum Core, PackageManager, PHP and OS versions for replacement implementations.
- Mixed-version behavior while a workbench, remote updater client or stored configuration is upgraded at a different time.
- Which retired SSH/custom/CloneLocal configurations are still in active use and the required migration/report experience.
- Whether any data or operational practices rely on Deployer-specific environment variables or command output.

All items above remain open unless separately resolved with documented evidence. The fact that a replacement is planned does not resolve them.

## 8. Acceptance criteria

- [ ] All supported behavior has a tested replacement; retired options produce an actionable migration result or explicit failure.
- [ ] Composer build workflow and `axenox/packagemanager` behavior remain available.
- [ ] No Composer-free build option or generic workflow editor was introduced.
- [ ] Composer metadata and lockfile no longer require Deployer after the final gated removal.
- [ ] Build/Deploy Actions and facades do not load or invoke Deployer runtime APIs; legacy recipe classes and task definitions have no production consumer.
- [ ] Record migration preserves project, host, variant and run history as scoped by Roadmap 05.
- [ ] Upgrade fails clearly before execution if an unsupported configuration would otherwise be silently skipped.

### Test plan (run at the end)

1. Before removal, run a repository search for Deployer references and review each match against the inventory; distinguish historical docs/test assertions from runtime use.
2. Run focused unit tests for Build, Deploy, Composer configuration, recipe migration, task/result handling and DeployerFacade callbacks.
3. Run a clean Composer install/update test with only declared runtime dependencies; verify Composer and `axenox/packagemanager` resolve and no Deployer package is installed or loaded.
4. On Windows and Unix build-server fixtures, create a Composer build, verify the archive, lockfile, package BOM and cleanup, then deploy through each retained transport and exercise upgrade rollback/recovery.
5. Run legacy-configuration fixtures for every recipe enum value. Confirm retained values map correctly and CloneLocal/custom/SSH values are reported with the expected action, not silently mapped.
6. After removing the requirement, repeat a case-sensitive and case-insensitive repository search for Deployer APIs, bootstrap paths and task functions, then rerun the full focused and end-to-end suites. Capture commands, versions and logs with secrets redacted.
