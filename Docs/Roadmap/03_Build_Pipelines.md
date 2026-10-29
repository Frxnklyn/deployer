# 03 - Build pipelines

## 1. Goal

Replace the four selectable legacy build recipe options with supported build pipeline prototypes while preserving Composer-based dependency installation, archives, lockfiles, package BOMs and build records.

## 2. Scope / non-goals

- In scope: `ComposerInstall`, `ComposerInstallAssetFix`, `CloneLocal`, `CustomBuild`; build artifacts and temporary workspace; Composer settings/timeouts; CLI execution; BOM/audit/lockfile behavior; tests and compatibility.
- Composer remains the build tool. There are no new Composer-free build variants.
- Prototype/task/result contracts are owned by [02 Pipeline Architecture](02_Pipeline_Architecture.md); prototype UXON storage and SQL migration are owned by [05 UXON and Migration](05_Uxon_and_Migration.md).
- The `deployer/deployer` requirement remains until all pipeline paths pass their gates in [01 Dependency Removal](01_Dependency_Removal.md).

## 3. Current state

`Actions/Build.php` currently selects `BuildRecipeDataType`, uses the procedural `Recipes/Build.php`, and explicitly loads `vendor/deployer/deployer/recipe/common.php`. `Recipes/Build/ComposerInstall.php` registers a Deployer task and executes the current Composer build workflow; `Recipes/Build/CloneLocal.php` archives the running installation without resolving dependencies. `ComposerInstallAssetFix` is selected through the enum and injects Asset-Packagist latest-cache repositories based on a prior successful build's lockfile; it has no separate recipe file. `CustomBuild` loads the project-configured `build_recipe_custom_path`.

The current recipe inventory is not evidence that every selectable option works in production. Existing files/selection and required target are listed below:

| Legacy recipe | Existing implementation | V3 treatment |
|---|---|---|
| `ComposerInstall` | `Recipes/Build/ComposerInstall.php` and shared tasks in `Recipes/Build.php` | Replace with Composer build prototype. |
| `ComposerInstallAssetFix` | `Actions/Build.php` cache-repository injection; no dedicated recipe | Same Composer prototype with explicit `asset_packagist_cache_fix` setting. |
| `CloneLocal` | `Recipes/Build/CloneLocal.php` archives selected local files and BOM | Retire; require an explicit Composer build replacement, never silently pretend a clone is equivalent. |
| `CustomBuild` | Action loads configured `build_recipe_custom_path` | Retire arbitrary procedural file selection; port known custom workflows to app-owned prototype and report unknown paths for manual handling. |

## 4. Planned technical changes

### Composer prototype

- Select prototype on each build variant through `prototype_file`; store settings in `config_uxon`.
- Expose `composer_json` and `composer_auth` only as UXON properties of the Composer prototype, never as separate build-variant attributes.
- Preserve Composer authentication, repository configuration, Composer timeout, selected PHP version, archive inputs, installer-script preparation, lockfile, package BOM and cleanup behavior.
- Build PHP executable is resolved from the existing selected PHP version, not duplicated in pipeline UXON.
- Use Core `CliCommandRunner` for CLI operations. Define how the action's current `900` second timeout relates to recipe `composer_timeout` (`1200` seconds); do not assume migration of one value preserves the other.
- `source_files` becomes a validated list of workspace-relative archive entries (legacy defaults: `vendor`, `composer.json`, `composer.lock`, `composer.phar`). Exclude credentials/authentication files and temporary secrets.
- Preserve artifact names/history. Derive artifact and isolated workspace paths from existing project storage and action run identity; do not guess the newest build by scanning a directory.
- Keep archived `composer.lock` and BOM consistent with installed packages. Capture the BOM before the existing temporary vendor cleanup; preserve current audit behavior.

Illustrative Composer prototype UXON (defaults and precedence still require the contract in Roadmap 05):

```json
{
  "composer_timeout": 1200,
  "source_files": ["vendor", "composer.json", "composer.lock", "composer.phar"],
  "asset_packagist_cache_fix": false,
  "composer_json": {"require": {}, "repositories": []},
  "composer_auth": {}
}
```

`composer_auth` should be omitted from persisted UXON when it is empty; never place secrets in logs, examples, fixtures or the archive.

### Asset-Packagist cache workaround

Make the prior enum distinction an optional Composer-prototype setting. Preserve the behavior that reuses asset-package information from the last successful build's lockfile. Decide and test what happens when there is no prior successful lockfile; do not silently disable the requested workaround.

### Clone and custom options

- Do not add a Composer-free build prototype to replace `CloneLocal`.
- Provide a preflight/migration report naming affected projects and the selected unsupported value; require an operator to choose/configure Composer or another explicitly supported app-owned prototype.
- For custom build paths, port only known custom workflows into app-owned prototypes. Unknown paths must be surfaced, not executed or dropped silently.

### Build value inventory

| Legacy key/value | Proposed location / handling |
|---|---|
| `source_files` | Composer UXON list; validate relative entries and required contents, preserve four legacy defaults. |
| `composer_timeout` | Composer UXON integer; legacy value `1200` seconds. |
| `composer_json`, `composer_auth` | Composer prototype UXON only; migrate non-empty values, omit empty/null values, redact auth. |
| `php_executable` | Resolve from selected `php_version`; no independent property. |
| `time_zone` | Action/workbench naming policy; exact source remains open. Existing recipe fallback `Europe/Berlin` can differ from Build's PHP timezone. |
| `build:os` | OS-adapter selection from build-server environment, not a user-editable shell property. |
| `timeout` | Action orchestration deadline currently `900` seconds. Define relationship with Composer/operation timeout. |
| `release_name`, CLI `build` | Action-generated build name / explicit deployment task build reference. Remove directory scan guessing in on-demand release selection. |
| `archiv_name` | Artifact metadata/result; preserve historical filenames. New names are generated, not shell fragments. |
| `builds_archives_path` | Existing managed artifact storage and isolated workspaces; not arbitrary host paths. |
| `composer.lock`, package BOM | Preserve exact dependency inventory corresponding to the installed/archive contents; keep audit integration. |

Other deployment-specific keys and embedded-installer values are mapped in Roadmap 04. Cross-object persistence and empty-value rules are in Roadmap 05.

## 5. Work packages

- [ ] Create baseline fixtures for each legacy build selector, Composer settings and previous-successful lockfile cases.
- [ ] Define action timeout versus Composer/operation timeout, PHP version resolution and build OS adapter support.
- [ ] Specify artifact naming, workspace isolation, cleanup and retry behavior without scanning for newest file.
- [ ] Implement Composer prototype using Roadmap 02's stable contract; retain Composer as required build tool.
- [ ] Implement validated archive-entry handling, installer preparation, lockfile/BOM capture and temporary vendor cleanup.
- [ ] Preserve Composer authentication while preventing credential inclusion in archives, output and exception details.
- [ ] Implement optional Asset-Packagist cache fix and decide no-previous-lock behavior.
- [ ] Add explicit handling/report for `CloneLocal` and `CustomBuild` values and known/unknown custom paths.
- [ ] Verify Action/UI/migration handoff for build-variant prototype selection with Roadmap 05.
- [ ] Remove procedural build tasks and their Deployer-specific helpers only after equivalent acceptance checks pass.

## 6. Dependencies

- Roadmap 02 defines prototype, task/result and action ownership.
- Roadmap 05 supplies per-variant `prototype_file` / `config_uxon`, composer settings migration and editor integration.
- Roadmap 01 tracks the final removal of Deployer bootstrap/task execution after this workflow passes.
- Roadmap 06 defines cross-platform CLI/OS adapter and reproducibility/retention stabilization.

## 7. Open decisions

- Behavior with no previous successful lockfile when the Asset-Packagist fix is enabled.
- Effective deadline when the existing Action timeout (900s) is shorter than Composer timeout (1200s), and whether operation-specific timeouts are necessary.
- Source of build timezone and exact supported PHP/OS matrix.
- Build archive retention and cleanup policy, especially for failed/concurrent builds.
- Porting scope for any active custom build recipes and exact operator workflow for retired CloneLocal projects.
- Whether archive defaults differ by supported app/project and how required deployable files are validated.

## 8. Acceptance criteria

- [ ] Every one of the four legacy selectors has a retained implementation or explicit actionable retirement/migration outcome.
- [ ] Composer remains the build tool and no Composer-free replacement is introduced.
- [ ] Composer configuration/auth, timeout, selected PHP, archive, installer script, lockfile, BOM, audit and cleanup behavior are covered by tests.
- [ ] Asset workaround preserves intended behavior and explicitly handles the no-prior-lock case.
- [ ] Artifacts are isolated, correctly named, reproducible from their lock/BOM and do not contain credentials.
- [ ] Unsupported custom/clone recipes are not silently run, discarded or misrepresented.
- [ ] The build pipeline has no dependency on Deployer runtime calls once replaced.

### Test plan (run at the end)

Run focused PHPUnit tests for Composer prototype configuration, timeout mapping, archive contents, lockfile/BOM consistency, audit snapshot and cleanup. Use fixtures for both Asset-Packagist modes, including no prior successful lockfile, and test CloneLocal/CustomBuild migration reports. On each supported build-server OS, execute a real Composer build in a disposable workbench; inspect the archive and confirm no auth file or secret is present, then deploy the selected artifact via the deployment tests in Roadmap 04. Record PHP/Composer versions and exact commands.
