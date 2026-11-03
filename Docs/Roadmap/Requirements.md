# Deployer v3 Requirements

This document states what Deployer v3 must achieve. It is derived from the [Deployer v3 roadmap](./Deployer_v3.md) and reviewed against the current recipe-driven implementation on `1.x-dev`. It is not an implementation status report.

## Status and source interpretation

- A checked decision in the roadmap records a resolved decision; it does not prove that the corresponding code, migration, or editor exists.
- The roadmap's recipe migration checklist is explicitly unverified until each v3 behavior and its data migration are verified.
- The initial source review found no v3 implementation. A first contract subset is now present and tested (see REQ-003 and REQ-008 coverage); no executable production pipeline has been implemented. The current [Build action](../../Actions/Build.php), [Deploy action](../../Actions/Deploy.php), and [recipe selectors](../../DataTypes/BuildRecipeDataType.php) remain recipe-oriented.
- `NOT_VERIFIED`, `PARTIAL`, `VERIFIED`, and `BLOCKED` in the coverage table refer to evidence for the requirement, not completion of a related task.

## Goals and scope

Deployer v3 replaces procedural `deployer/deployer` recipes with developer-maintained, UXON-configurable build and deployment pipeline prototypes. Designers choose supported templates; they do not construct arbitrary stages, dependencies, or conditions. The initial families are build and deployment pipelines. The migration covers existing projects, build variants, hosts, and historical build/deployment records, subject to the explicitly approved removal of `path_rel_to_releases` / `relative_deploy_path`.

## Requirements

Each requirement has testable acceptance criteria and a source reference.

### Architecture and execution

#### REQ-001 — Purpose-specific pipeline templates

Provide build and deployment pipeline prototypes for supported workflows, with developer-owned implementation and designer-configurable settings. Present them as **Build Pipeline** and **Deployment Pipeline**. Do not provide a generic pipeline editor or arbitrary stage/condition composition.

- **Acceptance criteria:** Designers can choose only discovered prototypes of the relevant family; configuration exposes only documented supported properties. No editor feature allows users to assemble arbitrary stages or shell workflows.
- **Priority:** MUST
- **Source:** [Goals and Key changes](./Deployer_v3.md#goals), [Technical details — Object oriented approach](./Deployer_v3.md#object-oriented-approach)

#### REQ-002 — Prototype discovery, selectors, factory, and editors

Replace static recipe enums with cross-app discovery and editor support for prototype selection and that prototype's UXON properties.

- **Acceptance criteria:** Build and deployment selectors list only compatible prototypes from their respective namespaces/metaobjects; invalid, missing, or wrong-family selections fail explicitly; editors show the selected prototype's defaults, documentation, autosuggest, presets, and validation; changing a prototype does not silently discard existing UXON.
- **Priority:** MUST
- **Source:** [Key changes](./Deployer_v3.md#key-changes), [Selectors and Factories](./Deployer_v3.md#selectors)
- **Decision caveat:** Exact stored path format, discovery rules, UXON schema integration, and prototype-switch behavior remain undecided.

#### REQ-003 — Pipeline execution, tasks, results, and ownership

Use the approved generator-based execution contract. Actions own and persist build/deployment records; pipelines execute work, yield text, and return structured results.

- **Acceptance criteria:** `PipelineInterface::run(PipelineTaskInterface $task): \Generator` is the unchanged signature on family interfaces and implementations; yielded values are text; after full consumption, `getReturn()` is a `PipelineResultInterface` with status and applicable outcome data; unsupported task types are rejected before side effects; intermediate state changes are not inferred from console text; actions persist state transitions.
- **Priority:** MUST
- **Source:** [Interfaces](./Deployer_v3.md#interfaces), [Ownership and streaming contract](./Deployer_v3.md#ownership-and-streaming-contract)
- **Decision caveat:** Detailed task/result accessors, lifecycle reporting, transaction boundaries, and exception behavior remain open.

#### REQ-004 — Build variant ownership

Each build variant owns its pipeline selector in `prototype_file` and pipeline UXON in `config_uxon`. Run-specific values remain on the build task; Composer-only configuration is not a universal build-variant setting.

- **Acceptance criteria:** Build resolves the selected variant's prototype and UXON; different variants can use different prototypes; Composer JSON/authentication are available only on the Composer prototype; no duplicate host/runtime attributes are introduced into pipeline UXON.
- **Priority:** MUST
- **Source:** [Build variant configuration](./Deployer_v3.md#build-variant-configuration), [Ownership and streaming contract](./Deployer_v3.md#ownership-and-streaming-contract)

#### REQ-005 — Composer build behavior

Replace the Composer recipe with a Composer build prototype while preserving effective build behavior and evidence.

- **Acceptance criteria:** A successful build installs dependencies using the selected PHP version and configured Composer data; preserves authentication, timeout behavior, required archive contents, lock file, installer-script preparation, package BOM capture, and temporary vendor cleanup; archives only validated workspace-relative entries; excludes authentication files and temporary credentials; the BOM and lock file describe the dependencies actually installed; commands use the Core CLI runner where available and platform-specific archive/cleanup work uses OS adapters.
- **Priority:** MUST
- **Source:** [Build recipes](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [Composer build pipeline](./Deployer_v3.md#composer-build-pipeline)
- **Decision caveat:** Relationship between the existing Build/Deploy action timeout and the Composer timeout must be defined. The current model/action uses `composer_auth_json`, while the roadmap proposes a `composer_auth` UXON property; confirm this source-to-target mapping before migration.

#### REQ-006 — Composer asset-cache workaround

Preserve `ComposerInstallAssetFix` as an optional documented Composer-prototype setting, not a separate pipeline selection.

- **Acceptance criteria:** Migration enables `asset_packagist_cache_fix` for legacy variants that selected the workaround; the Composer pipeline uses asset packages from the previous successful build's lock file; behavior when no previous successful lock file exists is explicitly decided and tested, not silently treated as disabled.
- **Priority:** MUST for affected variants
- **Source:** [Build recipes](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [Composer with asset-packagist cache workaround](./Deployer_v3.md#composer-with-asset-packagist-cache-workaround)
- **Decision caveat:** No-previous-lock behavior is pending.

#### REQ-007 — Host deployment ownership and configuration boundary

Each host owns the deployment prototype and pipeline-only settings, so hosts in one project may use different deployment strategies. The existing `deploy_config` remains the UXON container. All host attributes are resolved by actions and passed through public setters; host attributes may not be duplicated or overridden in UXON.

- **Acceptance criteria:** Deploy and facade flows resolve pipeline selection/configuration from the host; installation root, PHP executable, operating system, project/host data, and connection reference are provided through their appropriate public API; UXON contains only settings without corresponding host attributes; no second configuration store or duplicate wrapper is introduced.
- **Priority:** MUST
- **Source:** [Host deployment configuration](./Deployer_v3.md#host-deployment-configuration), [Host attributes](./Deployer_v3.md#host-attributes)

#### REQ-008 — Deployment task families and artifact independence

The generic deployment contract must support release deployment and configuration-only changes without imposing a build artifact or `.phx` file on every deployment.

- **Acceptance criteria:** Common deployment tasks require neither a build artifact nor self-extractor; release tasks explicitly require their selected build artifact; configuration-only tasks can be represented without transporting a release; specialized tasks do not narrow the common `run()` parameter.
- **Priority:** MUST
- **Source:** [Deployment task interfaces](./Deployer_v3.md#deploymenttaskinterface), [Ownership and streaming contract](./Deployer_v3.md#ownership-and-streaming-contract)

### Workflow and compatibility

#### REQ-009 — Retained deployment workflows

Provide supported pipeline prototypes for Updater push, Updater pull, scheduled polling, manual transfer, and Azure manual transfer. Preserve their actual boundaries: polling does not install a scheduler; manual transfer does not perform installation; Azure Kudu guidance does not automate upload or installation.

- **Acceptance criteria:** Each retained legacy selection has an explicit supported prototype/configuration mapping; push reports transfer errors as failures while retaining recovery guidance; pull keeps publication/download and callbacks; polling instructions identify the host-side scheduler action; manual and Azure flows provide appropriate operator instructions and never report installation success based only on package preparation or upload.
- **Priority:** MUST for supported workflows
- **Source:** [Deployment recipes](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [New pipeline prototypes](./Deployer_v3.md#new-pipeline-prototypes)
- **Decision caveat:** Whether polling is a pull preset or a specialized subclass is inconsistent in the roadmap; Azure specialization and endpoint scope need confirmation.

#### REQ-010 — Shared self-extractor installation behavior

For retained self-extractor workflows, preserve the effective installation behavior and existing host configuration while keeping `.phx` optional for other deployment pipelines.

- **Acceptance criteria:** Where applicable, installation preserves config overrides, release retention, shared/copy directories, local vendor behavior, local Composer package exclusions, installer execution, deployment log reporting, critical-error recovery, and historical filename behavior; `default_app_config` becomes `config` without changing the map, values, or merge behavior; keep the effective retention precedence `deploy_config.keep_releases ?? keep_releases` with fallback `4`; directory names are validated as safe, bounded, and non-conflicting.
- **Priority:** MUST
- **Source:** [Deployment recipes](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [Shared self-extractor deployment properties](./Deployer_v3.md#shared-self-extractor-deployment-properties)
- **Decision caveat:** Retained self-extractor parity for legacy `shared_files` is not established; do not expose it on those prototypes without implementation and tests.

#### REQ-011 — Cross-platform execution

Introduce distinct OS handling for the build server and deployment host, including standalone bootstrap needs.

- **Acceptance criteria:** Supported build/host OS and PHP combinations are documented and tested; platform path, archive, extraction, executable-discovery, symlink, and relevant web-server operations are handled by OS adapters; host OS does not get confused with build-server OS; commands are not supplied as arbitrary UXON shell strings; standalone package behavior does not assume the replacing installation's autoloader is available.
- **Priority:** MUST
- **Source:** [Goals](./Deployer_v3.md#goals), [OS adapters and standalone bootstrap](./Deployer_v3.md#architecture-and-configuration)
- **Decision caveat:** OS/PHP matrix, adapter contracts, and standalone packaging of required runner/adapter code remain open.

#### REQ-012 — Complete data migration and explicit unsupported-config reporting

Migrate existing Deployer data while preserving records and supported configuration. Require SQL migrations for moved attributes and recipe selections; never silently drop unsupported settings.

- **Acceptance criteria:** Projects, variants, hosts, builds, and deployments remain present; project-level build and deployment selections are mapped into affected build variants and hosts; non-empty moved settings are migrated while empty/null values do not create empty UXON properties; host `deploy_config` is reused; supported values are preserved; unknown/custom keys and unsupported recipe behavior are reported for manual mapping; deleted columns/tables follow the `trash_*` rule; upgrade and recovery behavior is documented.
- **Priority:** MUST
- **Source:** [Migration](./Deployer_v3.md#migration), [Migration and mixed-version decision table](./Deployer_v3.md#architecture-and-configuration)
- **Decision caveat:** Existing-target precedence, idempotency/preview procedure, and mixed-version support remain undefined.

#### REQ-013 — Approved breaking change: remove relative release path

Remove `path_rel_to_releases` and its recipe equivalent `relative_deploy_path` without replacement, value migration, legacy-layout guarantees, or preservation of empty-relative-path web-server behavior. This is an approved v3 major-version breaking change, not an unresolved compatibility defect.

- **Acceptance criteria:** The host attribute and corresponding recipe setting are removed; the old database column is renamed using the `trash_*` migration rule; no code or migration silently reintroduces or applies the old value; upgrade/release documentation calls out the breaking change.
- **Priority:** MUST
- **Source:** [Host attributes](./Deployer_v3.md#host-attributes), [Migration](./Deployer_v3.md#migration)

#### REQ-014 — Security and data integrity

Protect credentials, pipeline inputs, artifacts, and host execution throughout configuration, transfer, bootstrap, and logging.

- **Acceptance criteria:** TLS certificate verification is enabled; trust customization does not disable verification; connection credentials remain in connection configuration; secrets and sensitive `config` values are redacted; packages containing sensitive overrides are protected; host PHP is treated as an executable and validated, not interpolated as a free-form command; archive entries and installation directories are validated against traversal and path escape; raw shell scripts/commands are not exposed as UXON settings; authorization and least-privilege rules for bootstrap, downloads, callbacks, and host execution are documented and tested.
- **Priority:** MUST
- **Source:** [Deployment recipes](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [Configuration boundaries](./Deployer_v3.md#configuration-boundaries), [Credentials, artifact trust and execution permissions](./Deployer_v3.md#missing-safeguards-and-completion-criteria)
- **Decision caveat:** Secret references, bootstrap authorization, artifact integrity/signature policy, and execution permissions are still undecided.

#### REQ-015 — On-demand deployment lifecycle

Support container/instance startup requests that can obtain a package for a selected release while the host installation is still pending. The deployment run belongs to the action's persistent record and is distinct from the download response.

- **Acceptance criteria:** A package is returned only after it is ready; the HTTP request does not wait for host installation completion; a new instance can get its own deployment run even when reusing immutable build data; release choice, no-history behavior, retries, identity, state transitions, and request timeouts follow a recorded decision and dedicated tests; ordinary Updater pull behavior is not accidentally converted into a new-attempt-on-every-request flow.
- **Priority:** DECISION-GATED
- **Source:** [On-demand deployment](./Deployer_v3.md#on-demand-deployment), [On-demand deployment semantics](./Deployer_v3.md#on-demand-deployment-semantics)
- **Decision caveat:** State machine, target release, identity/correlation, concurrency/retry, HTTP wait protocol, and container rollout/persistence are pending.

#### REQ-016 — Observable run status and remote callbacks

Persist explicit lifecycle outcomes and preserve useful evidence for local, remote, and failed operations.

- **Acceptance criteria:** Final status comes from structured pipeline results and remote callback data, not text matching; actions persist intermediate and terminal states through a defined reporting mechanism and use LogBooks to collect execution evidence; transfer, download, install, and callback outcomes are distinguishable; command exit-code mapping, callback correlation/retry, log buffering, redaction, retention, and logging failure behavior are specified; failure evidence remains available without preventing cleanup or rollback.
- **Priority:** MUST
- **Source:** [Interfaces](./Deployer_v3.md#interfaces), [Ownership and streaming contract](./Deployer_v3.md#ownership-and-streaming-contract), [Logs, exit codes and remote failures](./Deployer_v3.md#missing-safeguards-and-completion-criteria)
- **Decision caveat:** Exact lifecycle reporter and remote callback protocol remain open.

#### REQ-017 — Safe activation, failure handling, and rollback

Define when deployment is activated and what recovery is guaranteed for files, configuration, and databases.

- **Acceptance criteria:** Critical/non-critical failure classes, activation ordering, readiness/health checks, preservation of a prior working release, rollback limits, expected downtime, and operator recovery boundaries are documented and tested; a symlink switch is not claimed to undo irreversible database changes.
- **Priority:** MUST
- **Source:** [Critical failure, rollback and downtime](./Deployer_v3.md#missing-safeguards-and-completion-criteria)
- **Decision caveat:** Activation and rollback guarantees are pending.

#### REQ-018 — Reproducible builds and safe artifact lifecycle

Make build/deployment artifacts and workspaces reproducible, isolated, and safely cleanable.

- **Acceptance criteria:** Lock/BOM and archive contents match the actual build; each attempt has isolated temporary state and unique artifact ownership; failed or completed workspaces are safely cleaned without affecting concurrent jobs or in-use downloads; artifact, attempt, host-package, and log retention policies are distinct and protect active/promoted artifacts.
- **Priority:** MUST
- **Source:** [Recipe variable inventory](./Deployer_v3.md#recipe-variable-inventory), [Concurrent requests, artifacts and retries](./Deployer_v3.md#on-demand-deployment-semantics), [Reproducibility and cleanup](./Deployer_v3.md#missing-safeguards-and-completion-criteria)
- **Decision caveat:** Deadline interaction and retention values/policies remain open.

#### REQ-019 — Operator instructions

Make generated deployment instructions accurate to the selected workflow and the operator/host OS.

- **Acceptance criteria:** Instructions match actual automation; only commands for the expected OS are shown; manual transfer is distinguished from completed installation; polling instructions identify manual scheduler setup; automated pull includes first-time setup; rollback instructions are clear and do not promise unsupported recovery; Azure guidance reflects manual Kudu and portal steps rather than claiming automation.
- **Priority:** MUST
- **Source:** [Pipeline instructions](./Deployer_v3.md#pipeline-instructions), [New pipeline prototypes](./Deployer_v3.md#new-pipeline-prototypes)

#### REQ-020 — Compatibility and controlled cutover

Preserve supported legacy data and integration behavior while retiring legacy recipe execution only after verified replacements.

- **Acceptance criteria:** Existing URLs/clients and supported callback flows have a documented compatibility decision; unsupported clone/custom/SSH configurations receive an explicit migration outcome; the legacy enums, recipe execution/generation, unused SSH task framework, and `deployer/deployer` dependency are removed only after retained paths and migrations pass acceptance; cutover and recovery are documented.
- **Priority:** MUST
- **Source:** [Recipe-to-pipeline migration checklist](./Deployer_v3.md#recipe-to-pipeline-migration-checklist), [Migration](./Deployer_v3.md#migration)
- **Decision caveat:** Minimum Core/PackageManager/PHP versions, old-client support, URL compatibility, and upgrade recovery remain undecided.

#### REQ-021 — Verification and release acceptance

Verify contracts, migrations, end-to-end behavior, and supported platform combinations before v3 release.

- **Acceptance criteria:** Focused PHPUnit tests cover interfaces, configuration validation/migration, state transitions, failure paths, and security-sensitive boundaries; integration tests cover build/install/download/callbacks on supported OS combinations, concurrent startup, repeated/interrupted downloads, attribution, failed installation, and older-client behavior; final release acceptance confirms all required migration inventory entries, no unresolved release blocker, and evidence for each mandatory requirement.
- **Priority:** MUST
- **Source:** [Verification and cutover](./Deployer_v3.md#missing-safeguards-and-completion-criteria)

## Explicit non-goals

- A general-purpose pipeline engine, user-assembled stages, arbitrary dependencies/conditions, or arbitrary shell/installer source configuration.
- Automatic conversion of unknown custom scripts or SSH workflows whose behavior is not known.
- Treating package preparation, successful upload, or successful download as proof that installation completed.
- Requiring `.phx` or a release artifact for every deployment workflow.
- Preserving the legacy `path_rel_to_releases` / `relative_deploy_path` layout or its web-server behavior.
- Having a pipeline directly read host/build-variant metamodel records or directly own/persist run records.
- Automatically configuring host schedulers for the polling workflow.

## Requirement coverage and verification tracking

Verification status is initialized conservatively. `NOT_VERIFIED` means this extraction did not establish the v3 acceptance evidence. It does not imply the legacy behavior is absent. `BLOCKED` is reserved for acceptance that cannot be settled until a human decision is recorded.

| ID | Requirement | Priority | Verification evidence | Status |
|---|---|---|---|---|
| REQ-001 | Purpose-specific pipeline templates | MUST | No v3 prototype/editor evidence found; verify supported selection and absence of arbitrary stage editing. | NOT_VERIFIED |
| REQ-002 | Discovery, factory, selectors, and editors | MUST | No v3 selector/factory/editor evidence found; exact discovery/serialization contract pending. | BLOCKED |
| REQ-003 | Generator, task/result, and action ownership contract | MUST | [Pipeline contract tests](../../Tests/PHPUnit/Unit/PipelineContractsTest.php): PHP 8.3.36 / PHPUnit 10.5.63, 4 tests and 56 assertions pass. Verifies declared UXON inheritance, unchanged common run signature, status results, and generator-consumer mechanics using a mock. Production task rejection, text-only output enforcement, action persistence, and intermediate reporting remain unimplemented/unverified. | PARTIAL |
| REQ-004 | Build variant pipeline ownership | MUST | Current model/action are recipe-oriented; no v3 selector/config fields verified. | NOT_VERIFIED |
| REQ-005 | Composer build parity and safety | MUST | Legacy Composer recipe exists; v3 pipeline and acceptance tests not verified. | NOT_VERIFIED |
| REQ-006 | Asset-cache workaround setting and migration | MUST for affected variants | Legacy recipe enum exists; absent-lock behavior pending and migration not verified. | BLOCKED |
| REQ-007 | Host pipeline ownership and setter boundary | MUST | Current project-level recipe/config model is legacy; v3 host ownership not verified. | NOT_VERIFIED |
| REQ-008 | Release/configuration task distinction | MUST | [DeploymentTaskInterface](../../Interfaces/Pipelines/DeploymentTaskInterface.php) is artifact-independent; [contract tests](../../Tests/PHPUnit/Unit/PipelineContractsTest.php) verify this common boundary. Specialized release/configuration task inputs and executable workflows remain unimplemented/unverified. | PARTIAL |
| REQ-009 | Retained deployment workflow prototypes | MUST | Legacy recipes exist; no v3 prototypes verified; polling/Azure design remains ambiguous. | BLOCKED |
| REQ-010 | Shared self-extractor behavior and configuration | MUST | Legacy installer is present; v3 parity and validated path behavior not verified. | NOT_VERIFIED |
| REQ-011 | Cross-platform execution and standalone bootstrap | MUST | Current recipe includes shell/platform assumptions; v3 OS/bootstrap contract pending. | BLOCKED |
| REQ-012 | Complete migration and unsupported-config reporting | MUST | No v3 migration evidence found; migration procedure and precedence pending. | BLOCKED |
| REQ-013 | Approved relative-path breaking change | MUST | Roadmap explicitly approves removal; SQL migration/execution not verified. | NOT_VERIFIED |
| REQ-014 | Security and data integrity | MUST | Legacy push code disables TLS verification per roadmap; v3 controls/bootstrap decisions unverified. | BLOCKED |
| REQ-015 | On-demand deployment lifecycle | DECISION-GATED | Existing facade has legacy pending/redeploy flow; v3 state/identity/release rules pending. | BLOCKED |
| REQ-016 | Structured status and remote evidence | MUST | Legacy facade callbacks update the latest host deployment; v3 correlation/reporting contract pending. | BLOCKED |
| REQ-017 | Activation, failure, and rollback | MUST | Existing installer/recovery instructions are not a complete agreed rollback contract. | BLOCKED |
| REQ-018 | Reproducible and isolated artifact lifecycle | MUST | Legacy names/workspaces are not evidence of v3 isolation; retention and retry rules pending. | BLOCKED |
| REQ-019 | Accurate operator instructions | MUST | Legacy instructions exist; v3 per-OS/prototype guidance not verified. | NOT_VERIFIED |
| REQ-020 | Compatibility and controlled cutover | MUST | No v3 cutover or migration evidence found; client/version compatibility pending. | BLOCKED |
| REQ-021 | Verification and release acceptance | MUST | Existing tests are not v3 pipeline acceptance evidence; required v3 matrix not verified. | NOT_VERIFIED |

## Open questions and missing decisions

These are intentionally not resolved by this requirements extraction.

1. What is the complete deployment state machine, including preparation, publication, download, installation, failure, timeout, and abandoned attempts?
2. Which build/release is authoritative for a host, and what happens for first install, missing artifact, failed installation, promotion, or deliberate rollback?
3. What identifies a deployment attempt and, where needed, a container instance? How are authentication, callbacks, retries, and older host-UID-only clients handled?
4. How are concurrent attempts, workspaces, packages, immutable build reuse, interrupted downloads, retry/idempotency, and artifact ownership isolated?
5. Is preparation synchronous with a bounded wait or asynchronous with a prepare/poll protocol? What are cancellation, cache, and proxy-timeout semantics?
6. Which paths persist across container restarts/replicas, how are replicas rolled out, and who serializes database migrations?
7. What are activation/readiness conditions, critical failure classes, database rollback limits, and manual recovery boundaries?
8. How are secrets referenced and redacted; how are bootstrap/download/callback requests authorized; are checksums/signatures required; what least-privilege policy applies?
9. What lifecycle reporter, result accessors, exception behavior, callback buffering/retry, log retention/redaction, and command exit-code mapping are required?
10. How do prototype defaults, stored UXON, and task values override each other? What wins when target config already exists or old/new keys conflict?
11. What selector serialization, discovery rules, editor schema behavior, and prototype-switch preservation rules are used?
12. What OS/PHP matrix and standalone packaging strategy make adapters and command execution available before the target installation's autoloader?
13. What does asset-cache workaround do if no previous successful lock file exists?
14. Is polling represented by a pull preset or specialized prototype? Does it extend pull with no changes? What Azure specialization and cloud endpoints are in scope?
15. Is legacy `shared_files` behavior required on any retained self-extractor prototype?
16. What are operation deadlines, build/archive/attempt/log retention, and cleanup rules?
17. What minimum Core, PackageManager, and PHP versions are required, and which legacy URLs/clients remain supported?
18. What is the idempotent migration, preview/report, existing-target precedence, and recovery procedure?
19. Should the existing `composer_auth_json` field migrate to the proposed `composer_auth` Composer prototype property, or should the target property retain the existing alias? Confirm the stored value format as well as the name.
20. Should ordinary Updater pull implement the optional `OnDemandDeploymentInterface`, or must that interface remain exclusive to the new on-demand workflow?

## Source review references

- [Roadmap](./Deployer_v3.md)
- [Build action](../../Actions/Build.php)
- [Deploy action](../../Actions/Deploy.php)
- [Deployer facade](../../Facades/DeployerFacade.php)
- [Build recipe selector](../../DataTypes/BuildRecipeDataType.php)
- [Deployment recipe selector](../../DataTypes/DeploymentRecipeDataType.php)
- [Build variant model](../../Model/axenox.Deployer.build_variant/04_ATTRIBUTE.json)
- [Build model](../../Model/axenox.Deployer.build/04_ATTRIBUTE.json)
- [Project model](../../Model/axenox.Deployer.project/04_ATTRIBUTE.json)
- [Host model](../../Model/axenox.Deployer.host/04_ATTRIBUTE.json)
- [Composer recipe](../../Recipes/Build/ComposerInstall.php)
- [Shared legacy deployment implementation](../../Recipes/SelfDeployment.php)
