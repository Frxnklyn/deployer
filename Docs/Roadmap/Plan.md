# Deployer v3 Implementation Plan

This is the working implementation plan for [Deployer v3 requirements](./Requirements.md). The [roadmap](./Deployer_v3.md) remains the source record of the original proposals and decisions. This plan distinguishes agreed architecture from illustrative implementation detail and unresolved human decisions.

## Baseline and progress rules

### Commit handoff and remaining acceptance (2026-11-05)

The native source changes are being committed by topic on
`refactoring/deployer-v3`, paired with PackageManager `fork-1.x-dev` pushed to the
fork's `1.x-dev`. This is a source handoff, not a v3 release approval.
The deployment-side checklist is [Pipelines/Deploy/TODO.md](../../Pipelines/Deploy/TODO.md);
the paired repository has `Docs/NativeDeploymentTODO.md`.

Outstanding work includes actual operator-owned target installation/recovery,
Windows/Unix/PHP and real HTTPS/client rollout, on-demand lifecycle/release/concurrency
decisions, authorization/bootstrap review, migration and `trash_*` cutover,
local-package/vendor-map parity, lifecycle/LogBook/editor acceptance and compatible
dependency release pinning. Composer audit requires a suitable executable; saved
minimal BOMs do not establish license completeness. All broad requirement statuses
remain conservative.

Pre-commit checks: changed PHP lint passed for 71 Deployer files and 11
PackageManager files; both whitespace checks passed. PackageManager affected HTTP
scanner group passed 65/335, excluding the previously recorded default scanner
registration mismatch. The focused Deployer export/action group passed 14/131
after commenting optional build-directory web rules.

**Known failing acceptance:** `DeployLicensesConfigTest` reproduces 5 failures out
of 6 tests, plus 4 warnings. The wrapper now emits Composer-style definitions
rather than the older canonical shape; invalid JSON raises `JsonException` rather
than `UxonSyntaxError`; the test expecting rejection of recorded package evidence
also no longer raises `UxonMapError`. Review the intended evidence/configuration
boundary as well as output and exception compatibility before calling this resolved.
These failures are recorded, not silently excluded or declared harmless.
The complete 221-test suite was started with rollback-only persistence checks
enabled, reproduced these failures, then was stopped after more than seven minutes
without a final summary. It is not recorded as passed. The isolated failing class
completed separately with the result above. Re-run the complete suite after resolving
the license boundary/compatibility work; no real target-install test was enabled.

Source handoff commits (this supersedes historical "Local, uncommitted" evidence
labels below without changing their acceptance status):

| Repository | Commit | Content |
|---|---|---|
| Deployer | `cf3d962` | Factory/selectors, editors/models and configuration migrations |
| Deployer | `688026c` | Native build/install/OTA workflows, historical exports, recipe/dependency removal and fixtures |
| Deployer | `2a25bea` | Audit finding identity/persistence compatibility |
| Deployer | `f83457d` | Operator, migration and troubleshooting documentation |
| PackageManager | `c5275c5` | Verified Windows native trust for HTTP audit scanners |
| PackageManager | `90af876` | Paired native OTA, callback, root and push integration |
| PackageManager | `e8cb63b` | Remaining rollout and acceptance checklist |

The roadmap/requirements and deployment TODO are committed separately from runtime
changes. No database dump, local credentials, build archive or installer artifact is
included. Unrelated dirty Core files on another branch are intentionally excluded.

- The initial reviewed code was legacy recipe execution. Build and Deploy now require native variant/host selections; absent selections fail with migration instructions instead of executing recipes. The package no longer requires `deployer/deployer`; shared application lock/vendor contents are not changed. Native OTA requires the paired local PackageManager code and managed native host layout. After a private database/configuration backup, normal InstallApp converted the local build variant and host on 2026-11-05; no target deployment was performed. This is not full v3 release acceptance. See TASK-004–TASK-012 and TASK-019 for evidence and limits.
- Do not infer completion from legacy functionality or from a checked roadmap decision. Tasks were initialized `OPEN` with `[ ]`; current progress is recorded below.
- A task is complete only after its criteria have been met and its test/review evidence is recorded. Evidence must verify behavior, not merely code presence.
- Minor implementation details may be documented as deviations. A change to an approved contract, scope, persistence, compatibility, or security behavior requires explicit human approval before implementation.
- Decision IDs in dependencies indicate prerequisites requiring recorded human decisions; they are not silently satisfied by this plan.

### Source discrepancies and verified legacy issues

| Item | Evidence and planning treatment |
|---|---|
| Composer authentication alias | The current build-variant model/action uses `composer_auth_json`; the roadmap proposes Composer UXON property `composer_auth`. Confirm target name and value representation before writing the migration (DEC-19). |
| Polling prototype shape | The migration checklist permits a pull setting/preset or specialized prototype, while the configuration example says polling should extend `LocalBldUpdaterPull` without changes. Do not select one silently (DEC-14). |
| On-demand state rules | The roadmap repeats the same `>= 62 && < 90` condition and does not reconcile it with “new run for each new instance” or reuse of state `60`. Define the full transition/identity contract first (DEC-01, DEC-10, DEC-11). |
| Legacy custom-deploy selection | The roadmap identifies that current `Deploy.php` checks the build enum's `CUSTOM_BUILD` instead of `DeploymentRecipeDataType::CUSTOM_DEPLOY`; migration must find `CustomDeploy` configuration independently of that broken execution branch. |
| Legacy updater polling registration | The roadmap records that `LocalBldUpdaterCron` registers `LocalBldUpdaterPull` as its task name. V3 must preserve the scheduling intent without carrying this name mismatch forward. |
| Legacy facade correlation | Current log callbacks select the latest host deployment and OTA publication uses a filename derived from build/host, not a stored attempt artifact identity. This is insufficient for concurrent on-demand attempts and is not acceptable as proof of v3 correlation. |

## A. Technical implementation strategy

### Approved architecture

1. Replace procedural recipes with developer-maintained, UXON-configurable prototypes in `axenox\Deployer\Pipelines\Build` and `axenox\Deployer\Pipelines\Deploy`; shared helpers belong in `axenox\Deployer\Common\Pipelines`. Present the designer-facing families as **Build Pipeline** and **Deployment Pipeline**. The initial scope is purpose-specific templates, not a general workflow engine or stage editor.
2. Store the selected build prototype and UXON on each build variant as `prototype_file` and `config_uxon`. Store the selected deployment prototype and pipeline-only UXON on each host, reusing its existing `deploy_config`. Hosts in one project may choose different deployment workflows.
3. Keep model ownership at the action boundary. Build/Deploy actions resolve model values and connections, pass host attributes through public setters, instantiate a pipeline, provide a run-specific task, stream yielded text, consume the generator fully, obtain its structured return using `getReturn()`, and persist results/state. Pipelines do not query host/build-variant models or write run records directly.
4. Keep host attributes authoritative. No UXON property may duplicate or override a host attribute. The current `deploy_config.default_app_config` map becomes the pipeline UXON property `config`, in place, preserving values and merge behavior.
5. Use task subtypes for workflows with different inputs. Generic deployment does not require an artifact or `.phx`; release deployment explicitly requires a build artifact; configuration-only deployment can carry no release.
6. Use Core `CliCommandRunner` for CLI operations where its autoload/runtime is available. The reviewed Core class exposes `runCliCommand()` and `runCliCommandIntoArray()`. The authorized bounded manual installer bundles its own bootstrap/argv/filesystem/archive code, loads only the extracted runtime and then uses its Core runner where available. This is not a comprehensive `.phx`/OS adapter contract; the remaining matrix/adapter decisions stay open.
7. Remove the external PHP deployer library and obsolete recipe machinery only after supported replacement paths and migrations pass their acceptance tests.

### Conceptual pipeline and generator contract

The following is illustrative pseudocode for the agreed contract, not an existing API or a complete PHP implementation. `PipelineInterface` extends Core's `iCanBeConvertedToUxon` as specified by the roadmap. Result construction/accessors and intermediate lifecycle reporting still need decisions.

// Generated by GitHub Copilot - Nov-03-2026
```php
interface PipelineInterface
{
    /** @return \Generator<string, string, mixed, PipelineResultInterface> */
    public function run(PipelineTaskInterface $task): \Generator;
}

interface BuildPipelineInterface extends PipelineInterface {}
interface DeploymentPipelineInterface extends PipelineInterface {}

final class ComposerInstall implements BuildPipelineInterface
{
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof BuildTaskInterface) {
            throw new \InvalidArgumentException('Unsupported task for Composer build.');
        }

        yield "Preparing Composer build\n";
        // Execute validated build steps and yield output as text.
        return new BuildResult(status: 99);
    }
}

$generator = $pipeline->run($task);
foreach ($generator as $text) {
    $resultStream->write($text);
}
$pipelineResult = $generator->getReturn();
```

Family interfaces inherit the common `run(PipelineTaskInterface $task): \Generator` signature unchanged; implementation parameters must not be narrowed. Reject invalid task types before side effects. Yield text only; structured workflow outcomes belong in result objects. A deployment result may indicate that a package is published/awaiting installation rather than that host installation is complete. A final return value alone cannot report intermediate transitions, so do not derive states from streamed text; implement an action-owned lifecycle reporter only after its contract is approved.

### Tasks, results, factory, and selectors

The task/result names below follow the roadmap. Their exact constructors/accessors are not final APIs.

// Generated by GitHub Copilot - Nov-03-2026
```php
interface PipelineTaskInterface {}

interface BuildTaskInterface extends PipelineTaskInterface
{
    public function getComment(): ?string;
    public function getNotes(): ?string;
}

interface DeploymentTaskInterface extends PipelineTaskInterface {}
interface ReleaseDeploymentTaskInterface extends DeploymentTaskInterface {}
interface ConfigurationDeploymentTaskInterface extends DeploymentTaskInterface {}

interface PipelineResultInterface
{
    public function getStatus(): int;
}

final class BuildResult implements PipelineResultInterface
{
    public function __construct(private int $status) {}
    public function getStatus(): int { return $this->status; }
}

final class DeploymentResult implements PipelineResultInterface
{
    public function __construct(private int $status) {}
    public function getStatus(): int { return $this->status; }
}
```

The two specialized deployment task interfaces express whether a selected build is required, not arbitrary stages or a selected transport. Workflow-specific artifact/result data belongs in specialized result types. Keep artifacts optional for generic deployment results.

The [selector](../../Common/Selectors/PipelinePrototypeSelector.php) and [factory](../../Factories/PipelineFactory.php) now implement the roadmap's Core-style construction entry point. The factory accepts app-qualified `vendor/app/Pipelines/Build|Deploy/Prototype.php` paths or corresponding class names, resolves file classes through Core, checks concrete family membership before construction, delegates to the selected app with the workbench constructor argument, and imports a copy of the UXON. The build discovery metaobject `axenox.Deployer.pipeline_prototype_build` inherits Core's PHP-file metadata and lists app-root `Pipelines/Build/*.php` files. Build variants have nullable `prototype_file` / `config_uxon` fields and an `InputUxon` editor linked to their selected prototype. This is explicitly a configuration preview: the legacy Composer fields and execution remain active, and selection changes do not clear UXON. Deployment discovery/editor, nested discovery, explicit switch/reset UX, host-key exclusion and production cutover remain pending. The following uses the existing factory method; production prototypes and migrated stored configuration are not yet available.

// Generated by GitHub Copilot - Nov-03-2026
```php
$prototype = \axenox\Deployer\Factories\PipelineFactory::createPipelineFromUxon(
    $workbench,
    $selectedPrototypePath,
    $storedPrototypeUxon
);
```

Only usable prototypes belong in family namespaces; abstract helpers belong in `Common\Pipelines`. The selector may accept a path or a class name, while the roadmap says paths are stored in the database; precise app-qualified encoding still requires a decision. UI integration discovers compatible prototypes and edits only that prototype's documented UXON. Preserve user configuration when a prototype changes, or require an explicit operator choice before discarding incompatible keys.

The following UXON snippets are illustrative representations of the roadmap's proposed storage boundaries, not complete model schemas or verified selector APIs. The Composer property name `composer_auth` remains subject to DEC-19. Host attributes such as installation path, PHP executable, OS, and connection are intentionally absent from the host UXON.

// Generated by GitHub Copilot - Nov-03-2026
```json
{
  "prototype_file": "\\axenox\\Deployer\\Pipelines\\Build\\ComposerInstall",
  "config_uxon": {
    "composer_timeout": 1200,
    "source_files": ["vendor", "composer.json", "composer.lock", "composer.phar"],
    "asset_packagist_cache_fix": false,
    "composer_json": {"require": {}, "repositories": []},
    "composer_auth": {}
  }
}
```

// Generated by GitHub Copilot - Nov-03-2026
```json
{
  "deploy_config": {
    "keep_releases": 4,
    "shared_dirs": ["backup", "data", "logs", "translations"],
    "copy_dirs": ["config"],
    "local_vendors": [],
    "local_packages": [],
    "config": {}
  }
}
```

The empty maps above illustrate shape only; migration must omit empty/null transformed properties. Do not put actual secrets in examples or editor presets.

### UXON ownership and action integration

Build:

- The Build action resolves the variant's `prototype_file` and `config_uxon`, creates run-specific task data (target PHP version, version, comment, notes, run identity), invokes the build prototype, streams text, collects evidence in LogBooks, and persists the structured result.
- `ComposerInstall` alone owns `composer_json` and `composer_auth` UXON properties. Do not create a competing build executable property; resolve build PHP from the existing selected PHP version.
- Do not turn action-owned build metadata into duplicate UXON values. Define the precedence between prototype defaults, stored UXON, and run task after the open decision is resolved.

Deploy:

- The Deploy action resolves the deployment prototype and UXON per host, creates an action-owned deployment record, builds the workflow-specific task, resolves host values/connections, calls public pipeline setters, streams output, collects evidence in LogBooks, and persists structured results.
- Every host attribute used by a pipeline (including root path, PHP executable, OS, project/host identity, and connection reference) must be passed through setters or the appropriate connection interface. UXON contains only pipeline settings without a corresponding host attribute.
- Pipelines that actively contact a host use the proposed `DeploymentViaConnectionInterface::setConnection(DataConnectionInterface)`; this must not move connection credentials into UXON.
- Reuse `deploy_config`; do not introduce a second pipeline-configuration field or a duplicate UXON wrapper. Keep per-run identity/status action-owned.
- The facade uses the host's selected pipeline configuration for download/on-demand behavior, but the download/callback attempt identity and authorization must be decided before that flow is implemented.

### Operating system adapters and safe command execution

Separate build-server OS from host OS. Adapters should own validated path interpretation, archive creation/extraction, executable discovery, portable file operations, symlink/activation operations, and any supported web-server configuration. Do not expose Cygwin paths, raw shell fragments, arbitrary installer source paths, or raw permissions as UXON settings. Prefer portable PHP operations over shell commands where appropriate; use the Core `CliCommandRunner` for CLI tools with explicit executable, argument, timeout, and exit-code handling where its runtime is available.

The `.phx` self-extractor runs before the new release autoloader is installed. Its dependency on the command runner, adapter, and bootstrap authorization must therefore be solved explicitly. Do not claim that invoking Core's runner is sufficient for standalone code until the bootstrap boundary is agreed and tested.

### Build and deployment prototype migration

Build prototype:

- Replace procedural Composer tasks with a Composer build pipeline. Preserve Composer JSON/authentication, timeout, archive contents, installer preparation, lock capture, BOM capture, and temporary-vendor cleanup.
- Migrate the asset-Packagist variant into a Composer setting. Decide the no-previous-lock behavior before implementation.
- Remove `CloneLocal` and `CustomBuild`; identify affected projects, require explicit replacement, and report unknown custom paths for manual porting to app-owned prototypes.

Deployment prototypes:

- Extract reusable package generation and host installation logic from [SelfDeployment](../../Recipes/SelfDeployment.php) and the embedded installer without requiring `.phx` for every deployment family.
- Preserve recipe-derived class names where practical so operators can recognize migrated workflows, without retaining obsolete recipe execution behavior.
- Preserve host configuration, local vendors/packages, shared/copied directories, install/log behavior, recovery boundaries, and the effective retention precedence where applicable. Validate relative directory paths, traversal, overlap, and boundaries before operating.
- Implement updater push/pull, scheduled polling, manual transfer, and Azure manual transfer with their actual automation boundaries. Push success means transferred, not installed. Pull publication/download and host callbacks are distinct from on-demand new-run semantics. Polling does not configure a scheduler. Manual/Azure preparation is not completed installation.
- Polling instructions must state that scheduling remains a host responsibility: either configure `axenox.PackageManager.SelfUpdate` in `Administration > BG Processing > Scheduler` or run `vendor/bin/action axenox.PackageManager:SelfUpdate` from an OS scheduler. First-time automated pull instructions must not print credentials into command history, logs, or generated artifacts.
- Add the new on-demand prototype only after release selection, state, identity, retry, concurrency, callback, and HTTP behavior are explicitly decided.
- Remove all SSH-only recipe logic and custom recipe-path selection. Do not auto-convert opaque SSH/custom workflows.

### Legacy configuration migration inventory

Every listed legacy option is accounted for below. The destination describes the roadmap's intended v3 treatment; it is not proof of implementation.

| Legacy recipe | Legacy value | Planned v3 treatment |
|---|---|---|
| Build via Composer | `ComposerInstall` | Composer build prototype in each applicable variant; migrate non-empty Composer JSON/auth data to its UXON; preserve archive, timeout, lock/BOM and cleanup behavior. |
| Build via Composer + asset FIX | `ComposerInstallAssetFix` | Same Composer prototype with `asset_packagist_cache_fix: true`; decide missing prior-lock behavior. |
| Clone current installation | `CloneLocal` | Remove clone-only behavior; flag for explicit replacement; do not silently substitute Composer. |
| Custom build recipe | `CustomBuild` | Remove arbitrary project recipe path; report and manually port active custom workflows to app-owned prototypes. |
| Self-extractor + Updater push | `LocalBldUpdaterPush` | Push prototype; retain connection configuration and response evidence; verified TLS; explicit failed transfer plus manual recovery. |
| Self-extractor + Updater pull | `LocalBldUpdaterPull` | Pull prototype; retain publication/download and callbacks; do not conflate ordinary pull with on-demand run creation. |
| Self-extractor + Updater scheduler | `LocalBldUpdaterCron` | Preserve scheduled-operation intent and manual scheduler instructions; fix legacy wrong task name. Preset-versus-specialized form remains unresolved. |
| Self-extractor + SSH upload | `LocalBldSshSelfExtractor` | Remove SSH upload/execution; require explicit supported replacement. |
| Self-extractor + manual transfer | `LocalBldUsbSelfExtractor` | Manual-transfer prototype; downloadable host-tailored artifact and OS-appropriate instructions; do not claim installation. |
| Self-extractor + manual upload to Microsoft Azure | `LocalBldAzureAppServiceManualInstall` | Azure manual-transfer specialization; Kudu/portal guidance only; credentials remain external; validate hosting layout and operator OS. |
| Build-ZIP + SSH install | `LocalBldSshInstall` | Remove SSH orchestration; explicit replacement; preserve necessary installation behavior only in shared supported installer logic. Legacy archive is `.tar.gz`, notwithstanding label. |
| Build-ZIP + SSH install + wait fix | `LocalBldSshInstallWait` | Remove experimental SSH/wait workflow; explicit replacement required. |
| Custom Deployment Recipe | `CustomDeploy` | Remove arbitrary recipe-path loading; report/port known workflows; migration must account for the current Deploy selector bug that checks the build enum. |

Legacy configuration-variable treatment:

| Existing value/behavior | Intended destination or rule |
|---|---|
| `source_files` | Composer UXON list of validated workspace-relative archive entries; preserve defaults, exclude secrets/temporary credentials. |
| `composer_timeout` | Composer UXON seconds (legacy `1200`); reconcile with action timeout (`900` currently). |
| `php_executable` / `php_version` | Build task and existing selected PHP version; no duplicate UXON executable. |
| `time_zone`, `customer_specific_version`, `release_name`, CLI `build` | Action/workbench naming and task-owned version/build reference; do not scan archives to guess newest build. Exact timezone source remains open. |
| `archiv_name`, `builds_archives_path` | Result/artifact metadata and managed project artifact storage with isolated workspace; preserve historical filenames. |
| `path_to_installation_root`, `path_abs_to_api`; `php_path`, `php_cli`; `operating_system`; `data_connection` | Existing host attributes/connection passed by public setters or connection interface; never duplicate as pipeline UXON. |
| `relative_deploy_path`, `path_rel_to_releases` | Approved breaking removal, no replacement or data migration; use `trash_*` for removed DB columns. |
| `keep_releases`, `deploy_config.keep_releases` | Existing host `deploy_config`; preserve `deploy_config.keep_releases ?? keep_releases`, fallback `4`, without duplicate source. |
| `shared_dirs`, `copy_dirs` | UXON lists; roadmap-proposed defaults are `["backup", "data", "logs", "translations"]` and `["config"]`, respectively. Validate bounds, traversal, and overlap. |
| `shared_files` | Candidate only for prototypes that implement/test it; not legacy parity on retained self-extractor paths. |
| `config_dir` | Fixed `config` convention initially; no configurable property unless all consumers are changed and tested. Installer conventions also include `releases`, `shared`, `current`, `exface`, and `post-install-cmd`; these are not automatic UXON knobs. |
| `deploy_config.local_vendors` | Keep its local-app retention and vendor-copy meaning. |
| `deploy_config.local_packages` | Keep key, full `vendor/package` names, and behavior: copy listed packages unchanged from previous release even if their vendor is deployed. It is not equivalent to `local_vendors`. |
| `deploy_config.default_app_config` | Rename nested key to required UXON property `config` inside existing host UXON, preserving its per-file map, values, and merge behavior for remote application overrides (including centrally controlled settings); redact/protect sensitive values. |
| Other/custom `deploy_config` keys | Preserve supported keys; report unknown/unmapped keys for explicit manual mapping, not silent loss. |
| `connection_config` URL/user/password | Keep in existing connection configuration; redact credentials. Verified TLS is mandatory; configurable trust must not disable verification. |
| `stage`, `host_short` | Host/task metadata only; never use editable alias as attempt correlation identity. |
| `host_deploy_path`, `deploy_path`, `release_path`, `target_dir`, `previous_release` | Derive from validated host data and run identity; discover previous release at runtime. |
| `current_copy_dir` | Internal loop value, not UXON. |
| `self_extractor_filename`, `temp_php` | Unique attempt-owned paths; never derive shared filenames solely from build and host. |
| `self_extractor_extension` | `.phx` convention of applicable prototype, not common deployment interface. |
| Installer/generator source paths | Developer-owned code; no arbitrary UXON source-path setting. |
| `host_ssh_config`, `ssh_multiplexing`, Cygwin path/symlink helpers | Remove with SSH recipes; use OS adapters for retained operations. |
| `use_atomic_symlink`, `use_relative_symlink` | OS/activation policy is undecided; atomic activation is a safeguard, not a casual UXON toggle. |
| `git_tty`, `allow_anonymous_stats` | Remove with the external Deployer library; no UXON equivalent. |
| Installer memory/time and archive extraction | `php_memory_limit` is proposed as `2G`; legacy `max_execution_time` is unlimited (`0`). Finite install/preparation deadlines and platform extraction behavior must be decided. SSH upload's `900`-second timeout and archive-only `500M` execution limit disappear with removed workflows; do not carry them forward as retained defaults. |
| `app_service_name` and Azure operator credentials | Service name is proposed UXON; credentials stay external. Nonstandard Azure cloud endpoints are unresolved. |

### Migration mechanics

1. Inventory source attributes and configured values before changing object definitions.
2. Add SQL migrations for moved attributes and project-level recipe selection. Migrate each selection to the applicable build variants/hosts, not one project-wide runtime value.
3. Reuse host `deploy_config`; rename only `default_app_config` to `config`; preserve supported keys and values. Apply target-configuration precedence only after DEC-03 is decided.
4. Move only non-empty/non-null transformed settings into UXON; do not write `{}`, empty strings, or null values.
5. Preserve projects, variants, hosts, builds, deployments, and historical records. Report unsupported clone/custom/SSH options and unknown custom UXON keys for manual intervention.
6. Remove `path_rel_to_releases` without value migration and rename the deleted database column to `trash_*`.
7. Rename other deleted tables/columns to `trash_*` under repository SQL migration rules. Provide idempotent migration, preview/report, and documented recovery once their behavior is decided.

## B. Implementation work packages and progress

### First decision block: foundation for a Composer build

Status: **PARTIALLY APPROVED**. The human approved preserving complete per-run Composer JSON replacement on 2026-11-03 (DEC-03). Other proposals below remain PENDING unless explicitly recorded as approved in the decision table.

Work on this block should not require deciding the entire on-demand deployment lifecycle first. Final shared contracts must still accommodate that lifecycle; no new state numbers, attempt identity scheme, persistence model, or remote callback API are approved here.

| Topic | Existing evidence / agreed boundary | Recommendation for human review | Verification required after approval |
|---|---|---|---|
| Configuration precedence (DEC-03) | Host attributes and action-owned identity/status cannot be overridden by UXON. The current [Build action](../../Actions/Build.php) allows a per-run Composer JSON replacement in `getComposerJson()`. | Prototype defaults supply absent settings; explicit stored UXON supplies configured values. Permit temporary run overrides only for explicitly supported fields, initially the existing Composer JSON override; keep version, PHP selection, comment, and notes as task inputs. Do not mutate stored UXON or introduce a generic task-to-UXON merge. Validate explicit values rather than using truthiness to ignore invalid input. Migration conflicts and prototype-switch preservation remain separate open questions. | Tests distinguish absent, explicit empty, and invalid values; unsupported overrides fail clearly; stored UXON is unchanged; host attributes and run identity cannot be overridden; ordinary builds retain stored values. |
| Composer JSON replacement (DEC-03) | Current `getComposerJson()` chooses the supplied document instead of merging it into the variant document. | Preserve full-document replacement for an explicitly supplied valid Composer JSON object for this build only. Do not introduce recursive merging of package constraints, repositories, or lists. Validation must diagnose invalid JSON or an unsuitable structure; it must not silently fall back to saved settings. | A saved dependency/repository absent from the replacement is not silently retained; invalid documents fail before dependency installation; both override and no-override builds retain the expected effective config evidence without exposing credentials. |
| Prototype selector storage (DEC-02) | The roadmap already specifies paths in the database and accepts paths or class names at the selector boundary. Core's `PrototypeSelectorTrait` derives the app from the first two path components; `FileSelectorTrait` recognizes `.php` suffixes; `FileSelectorInterface` uses `/` as its normalized separator. | Reuse that Core convention with an app-qualified path such as `axenox/Deployer/Pipelines/Build/ComposerInstall.php` rather than an absolute OS path or a path beginning with `vendor/`. Continue accepting a qualified class name at the factory boundary; normalize storage to a path after validation. Confirm the conversion against discovery metadata and factory resolution before approving the exact encoding. Do not reopen the already agreed path-versus-class storage decision. | Selector/discovery/factory round-trip tests on both supported OS families; cross-app paths resolve the intended app; missing, abstract, wrong-family, traversal, and outside-app paths are rejected; class input maps to the same usable prototype. |
| Intermediate state reporting (DEC-09) | The common `run(PipelineTaskInterface $task): \Generator` signature and text-only stream are agreed; actions persist records. A final result cannot report progress while the generator is running. | Use an action-provided lifecycle reporter, separate from yielded text, passed as execution context through the task. The reporter exposes only supported reporting operations, not a mutable data sheet or unrestricted persistence handle. Keep final status on the generator's result. The exact interface/accessors, exception behavior, transactions, and remote callbacks remain PENDING. | Reporter calls reach the owning action; changing output text cannot change status; unsupported task types fail before work; reporting/persistence failures are explicit; pipeline tests need no run-record writes. |
| First build task/result (DEC-09) | The roadmap names build task comment/notes, selected PHP version and version as task inputs, and a build result with status and applicable artifact data. | Define only the data needed by the first Composer build: resolved build name/version, selected PHP executable resolved by the action, comment/notes, isolated workspace/output context, and execution reporter. Return explicit status and produced artifact metadata with lock/BOM evidence needed by the action. Keep credential configuration on the Composer prototype, not a generic task API. Constructors, exact accessors/types, metadata representation, and cleanup ownership still need review before interfaces are implemented. | Contract tests assert task-family validation, text-only output, `getReturn()` result, artifact/lock/BOM consistency, isolated output, and action-owned persistence. Generic deployment tasks/results must still work without an artifact. |

Review exit criteria:

1. A human explicitly approves or changes each proposed rule; record that approval in the relevant DEC outcome, not merely in this draft.
2. Record exact task/result/reporter API shapes and review PHP variance and failure handling before TASK-002 begins.
3. Keep migration precedence, unsupported settings, client compatibility, OS/bootstrap, credentials, and deployment lifecycle questions open where this block does not answer them.
4. Implement tests together with each approved contract. Do not mark any requirement `VERIFIED` merely because this decision block is approved.

### Work packages and order

- **Phase 0 — Human decisions and contracts:** settle lifecycle, selector/config, migration, OS/bootstrap, compatibility, security, and retention contracts before committing to affected public APIs.
- **Phase 1 — Architecture foundation:** define pipeline/task/result/reporting boundaries, discovery/selectors/factory, and OS/bootstrap contract.
- **Phase 2 — Model, editor, migration:** add ownership/configuration on build variants and hosts; migrate all legacy selections/settings with diagnostics.
- **Phase 3 — Core pipelines and action integration:** implement Composer and shared deployment behavior plus retained push/pull/poll/manual/Azure prototypes; integrate actions and structured status.
- **Phase 4 — On-demand, security, and reliability:** implement the agreed on-demand state/identity flow, callback authorization, concurrency, cleanup, rollback, and trust controls.
- **Phase 5 — Verification and cutover:** focused/integration/migration/compatibility tests; operator docs; remove legacy paths/dependency only after evidence and release acceptance.

Tasks were initialized `OPEN` at extraction time; pending decisions are listed in Dependencies and DEC tables. TASK-002 has started only the already approved contract subset, not the pending lifecycle/task details. Its decision dependencies still prevent completion. The phase notes identify work that cannot safely pass a decision gate.

Commit labels such as `DEV task 01` identify implementation increments, not completion of the numbered plan tasks. Commit `fccdcf9` contains TASK-001 decision drafts and the first TASK-002 contract subset; neither task is complete. The Commit column lists actual commits and explicitly distinguishes uncommitted changes. Future commit messages should reference the plan task ID to avoid ambiguity.

| Task | Requirements | Description | Dependencies | Done | Status | Evidence / Deviation | Commit |
|---|---|---|---|---|---|---|---|
| TASK-001 | REQ-002, REQ-003, REQ-005, REQ-006, REQ-009, REQ-011, REQ-012, REQ-014–REQ-018, REQ-020 | **Goal:** Record human decisions for all decision-gated contracts. **Complete when:** each applicable DEC row has a decision, acceptance rules, and named compatibility/security consequences; no implementation assumption is hidden in the decision record. | Human review of DEC-01–DEC-20 | [ ] | BLOCKED | Composer replacement rule approved and recorded in DEC-03, 2026-11-03; abstract UXON base removed at human request. Remaining decisions still require review; approved subsets may proceed. This task tracks decisions, not the first implementation commit. | `fccdcf9` (decision draft only); approval/cleanup local |
| TASK-002 | REQ-003, REQ-008, REQ-016 | **Goal:** Define and implement common/family pipeline, task, and result APIs plus intermediate lifecycle reporting. **Complete when:** PHP signatures/variance match the agreed generator contract, unsupported tasks fail before side effects, result retrieval is tested after generator completion, and lifecycle transitions reach action-owned persistence. | TASK-001; DEC-01, DEC-09 | [ ] | IN_PROGRESS | Concrete BuildTask/BuildResult and generic DeploymentTask/release-only ReleaseDeploymentTask execute through real actions. PreparedDeploymentResult carries private artifact/hash/instructions and status 60, not installation success. Host setters and text/getReturn/result-state persistence are tested. No AbstractPipeline/speculative interfaces. Lifecycle reporting, LogBooks and remote persistence remain pending; broader legacy BOM API incompatibilities remain unchanged. | `fccdcf9` (initial contracts); additions local, uncommitted |
| TASK-003 | REQ-001, REQ-002 | **Goal:** Add family selectors, cross-app discovery, and `PipelineFactory`. **Complete when:** valid build/deploy prototypes are discovered and instantiated from stored UXON; missing, invalid, and wrong-family selectors fail clearly; serialization/factory tests pass. | TASK-001; TASK-002; DEC-02, DEC-03 | [ ] | IN_PROGRESS | Factory tests cover class/path inputs, both families, cross-app construction and invalid/missing prototypes. Read-only build/deploy discovery inherits Core PHP-file metadata, storing PATHNAME_RELATIVE. BuildVariantPipelineTest/HostPipelineTest verify real relations and prototype-bound editors via transactional imports; deploy tests enforce host-key exclusion and family checks. Live discovery returned ComposerInstall.php and ManualTransfer.php, and the Core file selector factory instantiated both production classes without execution. Nested discovery, unusable-file filtering and broader browser acceptance remain pending. | Local, uncommitted |
| TASK-004 | REQ-002, REQ-004, REQ-007 | **Goal:** Add build-variant and host prototype/config model/editor support. **Complete when:** designers select compatible prototypes and edit schema-backed UXON; host attributes remain authoritative; prototype switching never silently loses configuration; editor/model tests pass. | TASK-003; DEC-02, DEC-03 | [ ] | IN_PROGRESS | Optional variant/host prototype pickers bind InputUxon to selected prototypes; host deploy_config is reused. Normal local InstallApp on 2026-11-05 imported the current models; browser inspection confirmed saved ComposerInstall and UpdaterPull selections and native configuration editors. Both stored pipelines instantiated without execution. Post-install BuildVariantPipelineTest/HostPipelineTest: 6 tests / 167 assertions, passing. Non-fatal InputUxon mode warnings appeared; broader switch/reset and compatibility acceptance remain pending. | Local, uncommitted |
| TASK-005 | REQ-004, REQ-007, REQ-012, REQ-013, REQ-020 | **Goal:** Add SQL migrations for ownership changes and approved removal. **Complete when:** build selection/config is variant-owned, deployment selection is host-owned, records/history survive, non-empty settings migrate, empty settings do not create properties, default_app_config becomes config, and removed relative-path columns use trash_* without value migration. | TASK-001; TASK-004; DEC-03–DEC-05, DEC-19 | [ ] | IN_PROGRESS | Conversion fixtures verify supported mapping, auth/config preservation, asset flag, config rename, retention, invalid/conflicting reports and repeat UP/DOWN. On 2026-11-05, identifier-only preflight found one READY variant and one READY host; private full database/config backup preceded normal InstallApp. Both conversions recorded successfully and reports now show ALREADY_SELECTED. Stored Composer manifest/auth were compared with JSON types/list order preserved, ignoring object-key ordering only; factory construction passed. Hash comparisons preserved project data, all non-converted variant/host fields, 11 builds and 5 deployment records exactly. Post-install editor/migration suite: 6 tests / 167 assertions. Host root/PHP/connection/layout remain unchanged and target readiness is not established; no Build/Deploy was run. Relative-column trash_* removal remains pending. Operator preflight: [PipelineMigration](../PipelineMigration.md). | Local, uncommitted |
| TASK-006 | REQ-005, REQ-006, REQ-010, REQ-011, REQ-018 | **Goal:** Implement the Composer build prototype and platform-safe build operations. **Complete when:** Composer configuration/auth, timeout, archive list, lock/BOM, installer prep, cleanup, selected PHP, and asset workaround match approved criteria; absent-lock behavior and security exclusions are tested. | TASK-001, TASK-002, TASK-008; DEC-06, DEC-12, DEC-19 | [ ] | IN_PROGRESS | Native ComposerInstall and AssetPackagistCache use action-supplied same-variant history, preserve repository configuration and fail before workspace creation on missing/invalid history. On 2026-11-05, real native CLI build 4.0.1+20261105140013 for the migrated local variant completed Composer downloads, BOM and archive creation: persisted status 99, lock 118989 bytes, BOM 12054 bytes, archive 16044554 bytes / 8554 entries; required runtime entries present and private workspace cleaned. Archive SHA-256 d5856661d02c0ae8c396693ef4f122e49d9300b86289da50f2897dc96666e93c. Subsequent npm audit failed cURL60 (trusted CA configuration), not build creation. Focused Composer/action regressions 17/188 and shared safety/removal 6/841 pass; fixed TLS/auth/dependency hints never forward raw command output. Missing-history policy approval, real asset-fix acceptance and Unix matrix remain pending. | Local, uncommitted |
| TASK-007 | REQ-003, REQ-004, REQ-005, REQ-006, REQ-016 | **Goal:** Integrate Build action with variant prototype, task, generator, and result. **Complete when:** action-owned build states/logs are persisted from structured reporting; execution evidence is collected in LogBooks; generated metadata remains task-owned; no recipe CLI generation remains on this path; action tests pass. | TASK-002, TASK-003, TASK-006 | [ ] | IN_PROGRESS | Build resolves variant UXON, supplies PHP/history, consumes text/getReturn and persists evidence/status; recipe CLI dispatch is removed and missing/invalid selections fail without fallback. Fixed web-SAPI runtime selection: use existing Core CliCommandRunner::findPhpExecutable(), never raw web PHP_BINARY. Configured alternate PHP paths stay unchanged. Regression simulates an unusable web-server binary; running CLI PHP remains selected even without PHP on PATH. Composer/action regressions 17 tests / 188 assertions pass. Real CLI build 4.0.1+20261105140013 persisted status 99 and lock/BOM/archive (TASK-006); the browser invocation itself has not been rerun. npm audit still fails cURL60. LogBooks and lifecycle reporting remain pending. | Local, uncommitted |
| TASK-008 | REQ-010, REQ-011, REQ-014, REQ-017, REQ-018 | **Goal:** Define and implement OS adapters and standalone package bootstrap. **Complete when:** supported OS/PHP matrix passes path/archive/executable/activation tests; generated package works without assuming the replaced installation's autoloader; command and input boundaries are validated. | TASK-001; DEC-07, DEC-08, DEC-13 | [ ] | IN_PROGRESS | NativeHostInstallerTest executes copied real manual artifacts through standalone php -n processes on Windows/PHP 8.3.36, without repository/old autoload or external deployer. Private DACL, independent host archive parser/extraction, explicit absolute root, junction activation, main/worker locks and interrupted-switch recovery verified. New-runtime Core runner and dependency-free fallback both exercised. Fake new-runtime installers only; Unix/matrix and production database installation remain unverified. | Local, uncommitted |
| TASK-009 | REQ-007, REQ-008, REQ-010, REQ-014, REQ-017, REQ-018 | **Goal:** Extract shared self-extractor packaging/installation behavior. **Complete when:** required config, retention, local vendors/packages, shared/copy directories, logging, installer execution, and failure recovery meet approved acceptance; paths are bounded and non-conflicting; generic deployment remains artifact/`.phx` independent. | TASK-002, TASK-008; DEC-09, DEC-10 | [ ] | IN_PROGRESS | NativeHostInstaller now snapshots shared stores, rejects links/concurrent mutation, journals cleanup and defaults to backup/data/logs/translations. Retention defaults 4, supports >=2/-1, protects current/immediate previous. Installer/manual group: 43 / 399. Vendor-name lists preserved; local_packages overlays build-present non-executable assets only. Differing PHP, Composer/autoload metadata and absent packages explicitly require rebuild; map forms/nested copy constraints and full legacy parity remain open. No legacy adoption or DB rollback claimed. | Local, uncommitted |
| TASK-010 | REQ-009, REQ-014, REQ-016, REQ-019 | **Goal:** Implement updater push, pull, and scheduled-polling prototypes. **Complete when:** transfer failure and evidence are explicit, verified TLS is used, pull publication/download/callbacks are preserved, polling instructions document manual scheduler configuration, and no transport result falsely marks install complete. | TASK-003, TASK-008, TASK-009; DEC-14, DEC-20 | [ ] | IN_PROGRESS | Native pull/polling publish attempt-owned executable packages; polling prints scheduler instructions only. Native push preserves action-resolved HTTP authentication, requires verified HTTPS/no redirects/bounded streaming and explicit target account opt-in. Exit 0 plus matching private completion receipt, not stdout/upload, is needed for 99. Failure 90 retains private recovery package; upload-only 60 remains nonterminal. Push fixture group: 30 / 93. Bootstrap receipt and failed-install cases pass. Facade/client tests cover old-client 426, hashes, attempt callbacks, download-only and optimistic stale-write rejection. Paired PackageManager required; real target/HTTPS/platform rollout remains unverified. See [UpdaterPush](../UpdaterPush.md). | Local, uncommitted; includes PackageManager |
| TASK-011 | REQ-009, REQ-014, REQ-019 | **Goal:** Implement manual-transfer and Azure manual-transfer prototypes. **Complete when:** generated artifacts are downloadable and host-tailored; operator instructions match OS; Kudu and portal steps are accurate; preparation is not reported as completed installation; Azure scope is tested and documented. | TASK-003, TASK-008, TASK-009; DEC-14 | [ ] | IN_PROGRESS | ManualTransfer includes standalone installation/recovery. AzureManualTransfer prepares executable .phx and Windows/Unix workstation ZIP/Kudu/portal instructions; validates the public app name before preparation and never contacts Azure. Both remain status 60; native manual attempts reject OTA download/callback instead of legacy lookup/false completion. Azure/facade group: 11 / 122. Private operator transfer and no-DB-rollback limits documented in [PipelineMigration](../PipelineMigration.md). Real Azure/host acceptance remains unverified. | Local, uncommitted |
| TASK-012 | REQ-003, REQ-007, REQ-008, REQ-009, REQ-016 | **Goal:** Integrate Deploy action with per-host prototypes, setter-supplied host values, tasks, generator results, and action-owned records. **Complete when:** hosts in one project can select different pipelines; transfer/publication/completion states come from structured results; execution evidence is collected in LogBooks; no selected workflow is inferred from recipe names or console substrings. | TASK-002, TASK-003, TASK-005, TASK-009–TASK-011 | [ ] | IN_PROGRESS | Deploy uses host settings, excludes host aliases/connection from UXON, supplies host setters/push connection and persists structured results. Missing selections fail 90 without legacy fallback. Per-host identities, generic/release tasks, contracts and failed-persistence cleanup tested. On 2026-11-05, diagnosis found My Local Dev One's Windows OS with /local_host_pui_one root; it originally failed preparation independently of build 4.0.2+20261105140841 status 99 and its failed npm audit. Approved TASK-021 / DEV-003 now permits pull/polling preparation with explicit deferred binding, not installation. Strict manual/Azure/push root failures retain the safe hint; original Deploy/manual regressions were 36 / 289. Target path/layout remains unchanged; no real target deployment run. LogBooks, full remote/concurrency acceptance and lifecycle reporting remain pending. | Local, uncommitted |
| TASK-013 | REQ-014, REQ-016, REQ-017 | **Goal:** Implement approved transport, secret, callback, and operational security controls. **Complete when:** TLS verification, authorization, redaction, executable validation, artifact protection, least privilege, error mapping, and callback failure handling meet recorded decisions and security tests. | TASK-001, TASK-008–TASK-012; DEC-08, DEC-09 | [ ] | IN_PROGRESS | Native manual tests verify private current-account DACLs, no public artifact URLs, configuration/exception redaction, host-key exclusion, executable/path checks, unsafe tar/PAX/junction rejection and hash-checked artifact discard. Other HTTP/file routes must be excluded by operators. TLS, callback/bootstrap authorization and complete host security remain pending. | Local, uncommitted |
| TASK-014 | REQ-015–REQ-018 | **Goal:** Implement on-demand release selection, per-attempt run creation, download response, and host callback flow. **Complete when:** the approved state machine, desired release selection, attempt/instance identity, retry/idempotency, concurrency/locking, bounded HTTP protocol, artifact ownership, and rollout behavior pass scenario tests. | TASK-001–TASK-003, TASK-008, TASK-009, TASK-012, TASK-013; DEC-01, DEC-08–DEC-11, DEC-16–DEC-17, DEC-20 | [ ] | OPEN | Planned evidence: state-machine, parallel-request, retry, repeated/interrupted-download, callback-attribution, timeout, and missing-release integration tests; not run. | — |
| TASK-015 | REQ-012, REQ-013, REQ-020 | **Goal:** Implement migration preview/reporting, idempotency, and upgrade recovery. **Complete when:** all 13 legacy recipe values and inventoried configuration keys are accounted for; unsupported/custom settings are reported; data/history and intentional break are verified on representative fixtures; rerun/recovery behavior is documented. | TASK-001, TASK-005, TASK-007, TASK-012; DEC-04, DEC-05, DEC-18, DEC-19 | [ ] | IN_PROGRESS | Build/host identifier-only reporting views, exact-output rollback backups and guarded rerun conversion are fixture-tested. Known retained recipes map; clone/custom/SSH/unknown values and unsupported/conflicting keys remain explicit manual-review rows. Whole isolated migration/editor suite: 6 / 167. Existing metadata is transactionally upserted/rolled back, not duplicated. Actual release upgrade/compatibility approval and trash_* relative-column removal remain pending. | Local, uncommitted |
| TASK-016 | REQ-003, REQ-005, REQ-006, REQ-008, REQ-010, REQ-014, REQ-016–REQ-018, REQ-021 | **Goal:** Add focused unit/contract tests. **Complete when:** contracts, invalid inputs, config validation, Composer edge cases, status/result behavior, path safety, redaction, failures, retention, and approved state transitions have repeatable tests with documented commands and evidence. | TASK-002, TASK-006–TASK-014 | [ ] | IN_PROGRESS | PHP 8.3.36 / PHPUnit 10.5.63 (2026-11-05): combined native/build/installer/push/pull/Azure/factory/config/removal group passes 154 tests / 1814 assertions, no warnings. Final expanded runtime/model dependency guard separately passes 2 / 814 (overlaps, not additive). All 71 changed/new PHP files across both packages lint; both diff whitespace checks pass. External Deployer class autoload is prohibited in tests. No real target deployment, network dependency download, live conversion or permanent model import. Production/platform/on-demand acceptance remains pending. | `fccdcf9` (initial contracts); additions local, uncommitted, including PackageManager |
| TASK-017 | REQ-009–REQ-021 | **Goal:** Add integration and migration tests across supported workflows/platforms. **Complete when:** end-to-end build/install/download/callback, simultaneous startup, retry/interrupted download, correct attempt attribution, failed install/rollback boundaries, migration fixtures, and older-client behavior pass on the approved matrix. | TASK-010–TASK-016; DEC-07–DEC-18 | [ ] | IN_PROGRESS | Build/Deploy action tests use real local commands/packages and in-memory records, including native Composer build → manual package, multiple host identities, invalid-selected failure and persistence cleanup. HostPipelineTest/BuildVariantPipelineTest pass 4 / 48 using random tables dropped in finally and rolled-back metadata imports. Host installation/bootstrap, Unix/browser/platform matrix, remote concurrency and compatibility acceptance remain pending. | Local, uncommitted |
| TASK-018 | REQ-012, REQ-019, REQ-020, REQ-021 | **Goal:** Update operator and upgrade documentation. **Complete when:** each workflow's automation boundary, OS-specific instructions, first-time setup, manual scheduler, rollback limits, breaking change, unsupported-config handling, prerequisites, and recovery steps match tested behavior. | TASK-010, TASK-011, TASK-015, TASK-017 | [ ] | IN_PROGRESS | Docs/ManualTransfer.md gives normal InstallApp migration/model setup, variant/host UXON, actual Build(project, version, variant)/Deploy(build, host) commands, private transfer and standalone install/recovery. Read-only APIs identified System startup config, explicit server installer, scheduler side effects and Core PHP ^8.3. Dependency graph exposed php-64bit as a platform requirement; mirror classifier corrected/tested without installation. RealNativeInstallationTest repaired with external workspace, explicit disposable MySQL host/port/admin, disabled scheduling, correct entrypoint/startup and cleanup. Default exclusion/guards tested; real test not executed. Real host/production, Unix and full migration acceptance pending. | Local, uncommitted |
| TASK-019 | REQ-001, REQ-009, REQ-012, REQ-020, REQ-021 | **Goal:** Remove obsolete recipe execution, static recipe enums, SSH-only code, and `deployer/deployer`. **Complete when:** repository-wide references and runtime paths show no retained consumer; all replacement/migration and integration tests pass; the dependency removal is validated in install/build workflows. | TASK-007, TASK-012, TASK-015–TASK-018 | [ ] | IN_PROGRESS | Removed 20 recipe/embedded-installer files, Build/Deploy recipe dispatch/generation, SSH connector/helpers and manifest requirement. HTTP connector is now an explicit dependency. Old datatype names are non-enum StringDataType shims only for pre-import metadata; exports/pickers use native configuration and report missing selection. LegacyDependencyRemovalTest scans retained PHP/manifest; test bootstrap rejects external Deployer class loading. Native Deploy/log/license/removal group: 18 / 738. Composer validate passes (pre-existing publication warnings). Shared app lock/vendor intentionally untouched. Full production upgrade/platform acceptance and known local-package parity limits remain pending; do not mark all v3 acceptance complete. | Local, uncommitted; paired PackageManager required |
| TASK-021 | REQ-007, REQ-009, REQ-014, REQ-018, REQ-019 | **Goal:** Restore native pull/polling preparation without a usable root while requiring explicit independently validated target binding at execution. **Complete when:** original Windows/root mismatch publishes 60, deferred manifest omits unsafe root, missing/invalid roots cannot write target state, bound packages reject overrides, explicit fixture install/recovery and PM argv pass, automatic push remains fail-closed, and operator docs/evidence are updated. | TASK-010, TASK-012 bounded existing paths; DEV-003 | [x] | DONE | Human explicitly approved preparation-only exception on 2026-11-05. Roadmap host-root/ownership/pull/streaming sections reviewed: required installation root and published/awaiting preparation are compatible with this exception; no explicit prohibition found. Deferred marker removes unusable root; standalone --root/--recover and paired PM --native-root validate before target state/receipts. Windows PHP 8.3.36 / PHPUnit 10.5.63: `php vendor\bin\phpunit -c vendor\axenox\deployer\phpunit.xml.dist --filter 'NativeRootTest\|NativeOtaTest\|DeployNativePipelineTest\|ManualTransferTest\|NativeHostInstallerTest\|NativeUpdaterPushTest\|NativeOtaDownloaderTest\|NativePushTransportTest\|NativeOtaFacadeTest\|NativePushFacadeTest'` from application root: 111 tests / 881 assertions, 14m07s; subsequent expanded NativeRootTest 3 / 24 (overlaps, not additive). Original mismatch prepares 60 in fixture Deploy; missing/invalid roots leave targets untouched; explicit fixture install/interrupted-journal recovery, strict bound root, literal PM argv/legacy guard, deferred automatic-push failure and existing hashes/statuses/receipts pass. 72 changed/new PHP files across both packages lint; tracked diffs pass --check with cr-at-eol; fixture trees cleaned. Operator docs/requirement evidence updated. No target deployment/config guessing/library restoration/commits/roadmap edits. Optional real build 4.0.2+20261105140841 publication not executed; actual target validation remains operator-owned. Only this bounded repair is DONE, not broad v3 acceptance. | Local, uncommitted; paired PackageManager |
| TASK-020 | REQ-001–REQ-021 | **Goal:** Complete final v3 release acceptance. **Complete when:** every MUST requirement is `VERIFIED`, decision-gated requirements have approved decisions and verification, migration checks pass, no release-blocking DEC is pending, and test reports/known deviations are reviewed and approved. | TASK-001–TASK-019 | [ ] | OPEN | Planned evidence: signed release checklist mapping every REQ/TASK to test, migration, review, or approved deviation; pending. | — |

### Real archive acceptance follow-up

| Task | Requirements | Description | Dependencies | Done | Status | Evidence / Deviation | Commit |
|---|---|---|---|---|---|---|---|
| TASK-022 | REQ-005, REQ-009, REQ-018 | **Goal:** Accept safe real dependency documentation filenames during package inspection. **Complete when:** spaces/parentheses extract correctly, unsafe paths remain rejected and actual OTA publication persists 60. | TASK-021 | [x] | DONE | Real publication exposed three ordinary dependency documentation filenames rejected by the archive allowlist. Added spaces/parentheses with segment-boundary space rejection, preserving traversal/link/device/credential/collision checks. Actual Deploy for 4.0.2+20261105140841 now published a private attempt-owned .phx, persisted status 60 and SHA-256 5e4da2e393009cd4fba32112d1488dbacec59638de1e09494d4563a67b6c717e verified. This supersedes TASK-021's earlier no-real-publication note. No target installer executed or host settings changed. ManualTransferTest/NativeOtaTest/DeployNativePipelineTest: 48 tests / 445 assertions passed; changed PHP lint and whitespace checks passed. | Local, uncommitted |

### Post-build audit repair

| Task | Requirements | Description | Dependencies | Done | Status | Evidence / Deviation | Commit |
|---|---|---|---|---|---|---|---|
| TASK-023 | REQ-005, REQ-016, REQ-019 | **Goal:** Restore verified HTTP audit transport and findings persistence after native builds. **Complete when:** supported Windows PHP uses managed trust without disabling verification, explicit CA settings remain authoritative, current PackageManager findings without BOM_URN persist with version-specific identities, and a real saved build audits successfully. | TASK-006, TASK-007; paired PackageManager HTTP scanner changes | [x] | DONE | 2026-11-05: configured scanners re-audited saved 4.1.1+20261105152529; 205 advisory links saved and independently read back with nonempty valid BOM identities, build remains 99, no rebuild/target installation. npm/OSV/registry scans completed; Composer audit skipped without discoverable Composer >=2.7, Trivy not applicable. Shared Windows native trust retains TLS verification and explicit CA precedence. Current Audit output lacks BOM_URN; the Deployer adapter reuses snapshot identity normalization, preserving explicit URNs and unknown/case-distinct versions. Affected PM scanner regressions 65 tests / 335 assertions pass; excluded pre-existing testDefaultScannerRegistration expects outdated scanners in PM defaults, contrary to current config. Deployer audit/BOM unit checks 19/91 and rollback-only persistence/audit/BOM checks 16/111 pass (overlapping, not additive). Minimal native BOM license evidence remains incomplete: findings are not a clean-audit claim. Browser rerun, external CLI tool prerequisites and full platform acceptance remain unverified. | Local, uncommitted; paired PackageManager |

### Windows web-console publication repair

| Task | Requirements | Description | Dependencies | Done | Status | Evidence / Deviation | Commit |
|---|---|---|---|---|---|---|---|
| TASK-024 | REQ-009, REQ-014, REQ-019 | **Goal:** Permit private native package preparation from both the owner CLI and the existing LocalSystem Apache service, and remove stale recipe CLI startup from the console. **Complete when:** owner and LocalSystem alone have protected directory access, web preparation publishes with persisted status 60/hash, and reloaded console starts only native action discovery. | TASK-021, TASK-022 | [x] | DONE | 2026-11-05: 4.2.1+20261105154438 archive validation and CLI preparation passed; web failed because existing .native-deployments DACL allowed only the interactive owner, whereas wampapache64 runs LocalSystem. Protection now preserves the directory owner and allows only that owner plus S-1-5-18; no Users/Everyone/inherited grants, no service-account change or target mutation. Applied protection to the existing owner-only directory as its owner. Actual browser Deploy now published ota-efadd906c2f601216b51429cd0efb2cff1ce5e3c226a4b6ce1e44f925dd42d48.phx; status60 and SHA-256 c19584b39aa060ac438442025090642e82d2921542de2ba36d8b2e7b5a660f25 independently read back/verified. No host installer ran; deferred explicit root remains required for installation. ManualTransfer/NativeOta/Deploy regressions 48/445 and dependency/console regressions 3/824 pass; PHP lint/whitespace pass. Normal InstallApp updated exactly one page, no metamodel changes/migrations; browser reload verified no dep startup. Custom non-System service identities and other platforms remain operator/integration acceptance work. | Local, uncommitted |

### Historical executable package location

| Task | Requirements | Description | Dependencies | Done | Status | Evidence / Deviation | Commit |
|---|---|---|---|---|---|---|---|
| TASK-025 | REQ-009, REQ-014, REQ-018, REQ-019 | **Goal:** Restore the operator-facing builds directory and historical build/host PHX filename without restoring external Deployer. **Complete when:** real web Deploy produces the historical filename, hash/status match, immutable authenticated downloads remain attempt-specific, repeated exports/failed persistence clean up safely, and direct static PHX delivery is blocked. | TASK-022, TASK-024 | [x] | DONE | Human explicitly requested historical destination without restoring dependency, 2026-11-05. Action passes normalized host alias; native publication creates a private staged/atomically replaced builds copy and retains immutable attempt copy for authenticated OTA. Real web Deploy produced builds/4.2.1+20261105154438_My_Local_Dev_One.phx; status60/export/immutable hashes read back and matched d82f14ddab38ef551e85070d44a891ed10f4013ad48694239275368b78779d06. Direct unauthenticated HTTP HEAD returns403. Build directory ACL/TAR delivery unchanged; conflicting existing server rules fail explicitly. Export/action/facade/dependency checks 26 tests / 1050 assertions pass; export/standalone OTA checks 12 / 161 pass (overlapping, not additive); changed PHP lint and whitespace pass. Repeated export retains immutable attempts and old failure cleanup cannot delete a newer different-hash copy; persistence failure removes both owned artifacts. This supersedes earlier notes that only private attempt paths are published to the operator. No target installation or dependency restoration. | Local, uncommitted |

### Phases currently blocked by decisions

Follow-up to TASK-025 (2026-11-05, explicit operator request): automatic
build-directory `.htaccess` / `web.config` generation is now commented out, not
deleted. The optional block in `NativeDeploymentPackage::protect()` retains the
conflict check and writes, with reactivation instructions. Existing protection
files are left unchanged; internal private-directory protection remains enabled.
Export HTTP protection now belongs to the web-server operator; the earlier HTTP403
is evidence for the existing local rules only, not a guarantee for new directories.

Broad v3 work packages remain incomplete; TASK-021 records only the bounded approved repair. These are decision gates, not claims that implementation has started:

- **Pipeline/API and editor contract:** selector serialization, discovery/editor schema behavior, precedence, task/result details, lifecycle reporting, and exception boundaries block final public contracts and model/editor work (TASK-002–TASK-005).
- **OS/bootstrap and self-extractor:** supported OS/PHP matrix and standalone bootstrap availability block adapter packaging and retained installer implementation (TASK-008–TASK-011).
- **On-demand lifecycle and callbacks:** state machine, release choice, attempt/instance identity, retries/concurrency, HTTP wait protocol, callback authorization, and container rollout block TASK-013–TASK-014.
- **Migration and cutover:** existing-target precedence, mixed-version support, minimum dependency versions, recovery, retention, and full test acceptance block safe data conversion and removal of legacy recipes/dependency (TASK-005, TASK-015, TASK-017–TASK-020).

## D. Open decisions and blockers

No pending item below is resolved by an example or proposed API. Recorded roadmap decisions remain authoritative even when implementation is not verified. `DEC-01`–`DEC-20` are referenced by task dependencies.

| Decision ID | Question | Affected requirements / tasks | Known options and consequences | Current decision | Blocks implementation? |
|---|---|---|---|---|---|
| DEC-01 | What is the complete run state machine, including preparation, publish, download, install, failure, abandoned attempts, timeout, and the repeated `>=62 && <90` roadmap rule? | REQ-003, REQ-015–REQ-017; TASK-001, TASK-002, TASK-014 | Reuse existing numeric states only after defining transitions, or introduce separately represented lifecycle states; returning a pending state-60 artifact must be reconciled with per-instance attempts. | PENDING | Yes: lifecycle implementation |
| DEC-02 | How are prototype selector values serialized, discovered, validated, and resolved across apps? | REQ-002–REQ-004, REQ-007; TASK-001, TASK-003–TASK-005 | Choose the exact app-qualified path encoding and normalization in line with Core discovery/factory conventions; class names remain accepted selector inputs, not an alternative database storage policy. Wrong-family/missing-prototype diagnostics are required. | Partial: selector/factory/metaobject approach and database path storage are agreed; Core-style class/path construction is implemented and tested. Exact persisted encoding, discovery and editor rules remain PENDING; implementation of accepted input syntax does not approve storage policy. | Remaining discovery/model/editor policy; factory core can proceed. |
| DEC-03 | How do defaults, stored UXON, run task values, and pre-existing target config take precedence? | REQ-002, REQ-004–REQ-007, REQ-012; TASK-001, TASK-003–TASK-005, TASK-007 | Preserve approved configuration ownership; define remaining migration conflict/prototype-switch behavior separately. | Human approved 2026-11-03: use stored Composer config normally; explicitly supplied composer_json replaces the whole document for one build, without merge or stored-config mutation. No generic runtime overrides introduced. Effective config/lock evidence and secret protection remain required. Host ownership and default_app_config → config remain agreed. Other default/merge rules, migration conflicts, and prototype switching remain PENDING. | Only remaining schema/editor/migration rules; approved Composer replacement is not blocked. |
| DEC-04 | What is the migration preview, idempotency, conflict, and rerun behavior? | REQ-012, REQ-020; TASK-001, TASK-005, TASK-015 | SQL migration with report and recovery is proposed; existing target UXON may conflict with legacy values, and precedence is not set. | Partial: SQL migration for moved attributes/selections, preserved records, and `trash_*` handling are required; preview/idempotency/conflict/recovery details are PENDING. | Yes: production migration |
| DEC-05 | What compatibility window applies to old URLs, clients, and mixed-version installations? | REQ-012, REQ-020–REQ-021; TASK-001, TASK-005, TASK-015, TASK-017–TASK-020 | Support/backward-compatibility limits determine rollout and whether callback/API adapters are needed. | PENDING | Yes: safe cutover |
| DEC-06 | What happens when the asset-packagist workaround has no previous successful lock file? | REQ-006; TASK-001, TASK-006 | Fail clearly, use another explicitly approved source, or another defined behavior; never silently disable the requested workaround. | PENDING | Yes: affected Composer path |
| DEC-07 | What build-server/host OS and PHP matrix is supported, and what code is bundled for standalone `.phx` bootstrap? | REQ-011, REQ-014, REQ-017–REQ-018; TASK-001, TASK-008–TASK-011, TASK-017 | Core runner is available only when bootstrapped; bundle minimal reviewed code versus another approved bootstrap. Must cover path/extraction/symlink/executable/web-server behavior. | Partial: human authorized the bounded runnable manual installer on 2026-11-04, including safe implementation choices and explicit unsupported-setting errors. Native helpers are bundled; new-runtime Core runner is used where available. Windows/PHP 8.3.36 fixture evidence only. Full OS/PHP/adapter matrix and .phx self-execution remain PENDING. | Remaining matrix/adapter scope only; bounded manual slice implemented. |
| DEC-08 | How are downloads, callbacks, bootstrap, secrets, and artifacts authorized/trusted? | REQ-014–REQ-016; TASK-001, TASK-013–TASK-014 | Keep connection secrets external, redact sensitive UXON, require verified TLS; secret references, attempt auth, signatures/checksums, and least privilege still need agreement. | Partial: connection credentials stay in connection config; verified TLS, UXON redaction, and package protection for sensitive config are required. Bootstrap/attempt authorization, secret references, integrity policy, and least privilege are PENDING. | Yes: production transport/on-demand |
| DEC-09 | What are task/result accessors, lifecycle reporter, exception/transaction behavior, callback retry, exit-code mapping, log retention, and logging-failure rules? | REQ-003, REQ-016–REQ-017; TASK-001–TASK-003, TASK-009–TASK-014 | Reporter separate from text stream is required in principle; exact callback/reporting API and durable failure evidence are open. | Partial: common generator signature, text-only yields, structured result via `getReturn()`, and action-owned records are agreed. Intermediate reporter, accessors, exception/transaction and remote-log behavior are PENDING. | Yes: final interfaces and status |
| DEC-10 | How are attempts, workspaces, artifacts, retries, downloads, cleanup, and retention isolated under concurrency? | REQ-015–REQ-018; TASK-001, TASK-008–TASK-009, TASK-013–TASK-017 | Unique attempt-owned artifact/workspace and immutable build reuse are proposed; lock/idempotency/retry policy and retention values remain undecided. | PENDING | Yes: on-demand production behavior |
| DEC-11 | Which build is the desired/published release for a host? | REQ-015; TASK-001, TASK-014 | “Latest build” and “last successful deployment” differ; decide variant selection, first-install, promotion, failed-install, missing artifact, and rollback behavior. | PENDING | Yes: on-demand release selection |
| DEC-12 | How do orchestration timeout, Composer operation timeout, installer memory, and finite execution deadlines interact? | REQ-005, REQ-011, REQ-018; TASK-001, TASK-006, TASK-008–TASK-009 | Legacy Build/Deploy timeout is 900 seconds while Composer recipe uses 1200; embedded installer uses `2G` and unlimited execution. Define bounded scopes; do not copy unlimited defaults silently. | Native opt-in: composer_timeout defaults to 1200 seconds per Composer/tar operation. There is no outer recipe process, so legacy action timeout does not wrap this path. Overall worker/installer deadlines remain PENDING. | Yes: broader timeout policy |
| Native Composer increment limits | Which guarantees remain outside native fixture acceptance? | TASK-006–TASK-008 | Composer scripts/plugins are trusted project code; native tar must be installed. Symlinks and recognizable credential files/URLs/configured secret bytes cause explicit failure rather than packaging. Publication reserves the destination exclusively but is not atomic-visible to external filesystem readers during the copy. | Asset workaround and SQL conversion are now fixture-tested. BOM compatibility helpers remain independent of deleted recipes; Deploy license merging uses the shared current-API adapter. Real dependency-install/platform matrix, global secret references, concurrent reader/retention policy and release rollout remain unverified. | Broader policy/acceptance pending |
| DEC-13 | Which bootstrap prerequisites, executable/path rules, symlink permissions, and web-server operations are supported? | REQ-011, REQ-014, REQ-017; TASK-001, TASK-008–TASK-009 | Adapter capabilities and deployment privilege vary by host; define capability detection/failure behavior and supported recovery. | Partial: host OS/PHP/root values remain host-owned and are supplied through setters; adapter capabilities, bootstrap prerequisites, and support matrix are PENDING. | Yes: supported host installation |
| DEC-14 | Is polling a pull preset or specialized prototype, and what exact Azure specialization/endpoints are supported? | REQ-009, REQ-019; TASK-001, TASK-010–TASK-011 | Roadmap permits a specialization. Implemented UpdaterPolling inherits pull execution and adds manual scheduler instructions; AzureManualTransfer prepares public-cloud Kudu guidance only. Neither registers jobs nor contacts Azure. | Implemented bounded specialization under implementation request; nonstandard Azure domains/layout and final rollout acceptance remain PENDING. | Remaining Azure scope/production policy only. |
| DEC-15 | Is legacy `shared_files` behavior required for any retained self-extractor workflow? | REQ-009–REQ-010; TASK-001, TASK-009–TASK-011 | Legacy SSH tasks use it but retained self-deployment does not; expose only where implemented and tested. | PENDING | Blocks parity claims only |
| DEC-16 | What activation/readiness guarantees and rollback boundaries apply to files, configuration, and database migrations? | REQ-017; TASK-001, TASK-008–TASK-009, TASK-013–TASK-014, TASK-017 | Preserve prior release until success, but symlink rollback cannot undo a database migration; define manual boundaries and downtime. | PENDING | Yes: production deployment |
| DEC-17 | What are artifact, host-package, attempt, workspace, and log retention rules? | REQ-016, REQ-018; TASK-001, TASK-009, TASK-013–TASK-017 | Host release retention is separate from build, attempt, and log retention; active/in-use artifacts must be protected. | PENDING | Yes: cleanup and operations |
| DEC-18 | What minimum Core/PackageManager/PHP versions and release cutover/recovery procedure apply? | REQ-012, REQ-020–REQ-021; TASK-001, TASK-015, TASK-017–TASK-020 | Version requirements and migration rollback determine support and dependency removal timing. | PENDING | Yes: release/cutover |
| DEC-19 | How does the current `composer_auth_json` attribute map to the proposed Composer UXON property `composer_auth`, and what exact JSON/value representation is migrated? | REQ-005, REQ-012; TASK-001, TASK-005–TASK-006, TASK-015 | Preserve the current stored value while confirming whether the target property name is the roadmap's `composer_auth` or a different approved alias; do not silently drop, rename, or reinterpret credentials. | PENDING | Yes: Composer config migration |
| DEC-20 | Should ordinary Updater pull implement the optional `OnDemandDeploymentInterface`? | REQ-009, REQ-015; TASK-001, TASK-010, TASK-014 | Keep ordinary pull publication/download behavior distinct from a new deployment for each on-demand request; sharing the HTTP response capability may reuse code but must not accidentally change attempt creation or release selection. | PENDING | Yes: pull/on-demand facade integration |

## E. Deviations and change log

Start with no approved implementation deviations. Future sessions must add a row before or when a deviation is proposed. Changes to approved contracts, scope, persistence, compatibility, or security require explicit human approval; do not mark such a row approved by inference.

| ID | Task | Planned behavior | Actual change / proposal | Reason | Approval | Impact |
|---|---|---|---|---|---|---|
| DEV-001 | TASK-002 | Additional abstract UXON base with an explicit supported-property list. | Remove the uncommitted base and dependent tests; reuse Core UXON traits in actual prototypes when needed. | Avoid speculative abstraction and duplicate property declarations. Host/UXON separation remains required. | Human requested removal, 2026-11-03. | No production action changed; import/export and host-boundary safeguards still require implementation and tests. |
| DEV-002 | TASK-009, TASK-019 | Preserve legacy local package/vendor configuration and effective retention completely. | Native vendor-name lists and build-present non-executable local assets are supported. Differing PHP, Composer/autoload metadata, absent packages, legacy vendor maps and retention below 2 fail explicitly rather than executing an incompatible runtime. | Standalone installation loads only the newly built runtime; silently overlaying executable/dependency metadata could invalidate it. Current and immediate previous releases remain protected. | Full parity or a compatibility exception requires human approval; no approval is inferred. Dependency removal was explicitly requested, not approval of these parity limits. | Affected hosts require rebuild/config review; REQ-010 and final v3 acceptance remain PARTIAL. No silent settings drop or automatic legacy-layout adoption. |
| DEV-003 | TASK-010, TASK-012, TASK-021 | Host path_abs_to_api is the authoritative absolute installation root, validated before package preparation. | Pull/polling alone may publish status 60 with host.installation_root_binding=deferred_explicit and no installation_root when the action-supplied path is unusable for the host OS. Execution requires explicit existing absolute --root; paired SelfUpdate forwards optional --native-root for verified native OTA only. Valid bindings cannot be overridden; manual/Azure/push remain strict and automatic push rejects deferred packages. | Restores preparation of an existing local build without guessing/correcting host configuration or weakening target checks. Roadmap requires host-root authority and allows published/awaiting results; it does not explicitly prohibit preparation without usable binding. This is a narrow approved contract exception, not an inferred roadmap amendment. | Explicit human approval, 2026-11-05: restore package publication without valid target root if sensible and not forbidden; no actual target install authorized. | Manifest marker is explicit and fail-closed; old invalid root is not carried as usable data. Root validated independently before target lock/journal/receipt; hashes/private attempt ownership/statuses remain unchanged. No legacy adoption, DB rollback, target configuration changes or broad v3 acceptance. Roadmap unchanged. |

## F. Verification and completion

### Checkpoints

1. **Decision checkpoint:** Record DEC outcomes and acceptance rules. Do not finalize interfaces or persistence around pending contracts.
2. **Contract checkpoint:** Unit tests establish generator yield/return behavior, task-family validation, result statuses, lifecycle reporting, factory/discovery, and configuration ownership.
3. **Migration checkpoint:** Test representative old installations, every recipe value, non-empty and empty settings, existing target conflicts, unsupported/custom/SSH reports, retained historical records, and `trash_*` behavior. Verify `path_rel_to_releases` is removed without value migration.
4. **Prototype checkpoint:** Focused tests establish Composer archives/lock/BOM/auth safety and each retained deployment workflow's exact automation boundary, configuration, status, failure, and OS-specific instructions.
5. **Integration checkpoint:** End-to-end tests cover build, package preparation, authenticated download, remote logs/status, callback failure, simultaneous startup, retries, interrupted downloads, attempt attribution, failed installation, rollback limits, and every supported OS/PHP combination.
6. **Security/reliability checkpoint:** Verify TLS certificate validation, authorization, redaction, path and executable validation, artifact protection, cleanup, retention, timeout bounds, concurrent artifact isolation, and behavior when logging or callbacks fail.
7. **Cutover checkpoint:** Confirm old URLs/clients and minimum versions per DEC; remove recipe enums, obsolete recipe execution/SSH helpers, and `deployer/deployer` only after all replacement, migration, compatibility, and integration tests pass.
8. **Release checkpoint:** All mandatory requirements are `VERIFIED`, decision-gated requirements have approved decisions and evidence, no release-blocking decision is pending, deviations are reviewed, and upgrade/recovery/operator documentation matches tested behavior.

### Evidence and status maintenance

- Keep the progress table current in every implementation session: update `Status`, `Done`, and `Evidence / Deviation` as work proceeds.
- Record implementation progress and concise verification evidence in the task table only; do not append per-session implementation narratives or checkpoint reports below it.
- Use only `OPEN`, `IN_PROGRESS`, `BLOCKED`, `DONE`, or `CANCELLED` for task status. Set `[x]` and `DONE` only after the task's completion criteria and evidence are verified.
- Keep requirement verification in `Requirements.md` independent of task status. A task marked `DONE` does not automatically make a requirement `VERIFIED`; record the exact test/review evidence for each requirement and use `PARTIAL`, `BLOCKED`, or `NOT_VERIFIED` where appropriate.
- Record focused test commands/results, migration fixtures/reports, platform/version matrix, and any deviations. Documentation or implementation presence alone is not behavioral evidence.
