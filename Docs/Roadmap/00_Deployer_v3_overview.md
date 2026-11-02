# Deployer V3 roadmap

This page is an additional navigation and progress overview for the V3 work. The existing [Deployer_v3.md](Deployer_v3.md) has not been changed. Detailed requirements, existing behavior, open decisions and verification plans live in the new linked roadmaps; consult the original for any text not yet redistributed.

## 1. Goal

Replace the procedural recipes with supported, purpose-specific build and deployment pipeline prototypes and remove the external `deployer/deployer` library only after every supported execution path has been replaced and verified.

Composer remains the build and dependency-management tool. `axenox/packagemanager` remains a dependency. V3 does not add Composer-free build variants, a general-purpose workflow editor, or features outside the previous V3 scope. Pipeline prototypes are developer-maintained templates; users select and configure them.

## 2. Scope boundaries / non-goals

- Preserve the existing V3 requirements and all existing data except the expressly approved breaking changes.
- Keep pipeline settings typed, documented and validated. Do not expose arbitrary stage composition, shell commands or installer source paths.
- Do not remove `deployer/deployer` while a supported build or deployment path still uses it.
- Do not replace Composer with a new build tool or remove PackageManager.
- Do not treat prose, a proposed design, a selectable legacy recipe or a test plan as implementation evidence.

## 3. Current state

The initial source-review findings below describe the pre-change baseline. The current transitional implementation status is summarized after them; it does not mark the full V3 architecture as complete.

- The baseline `composer.json` required `deployer/deployer` (`^6.5`), `exface/core` and `axenox/packagemanager`. The package requirement has now been removed; Composer and PackageManager remain.
- `Actions/Build.php`, `Actions/Deploy.php`, and the procedural recipe files use Deployer task functions and/or the Deployer runtime. The Build action explicitly loads `vendor/deployer/deployer/recipe/common.php`.
- The Actions now use native PHP executors for the retained Composer and updater/manual deployment selections. Procedural task implementations were removed; original linked recipe entry points now fail explicitly, and the standalone `.phx` installer template remains.
- Build and deployment recipe enums remain in `DataTypes/BuildRecipeDataType.php` and `DataTypes/DeploymentRecipeDataType.php`; model metadata references both enums to preserve legacy saved values.
- The existing roadmap records host ownership of the deployment pipeline as decided (`[x]`). That is an architecture decision, not an implemented feature. Other roadmap checklists remain open.
- The new native executor tests pass (9 tests, 43 assertions), including a real offline Composer install and mocked updater push success/failure. Host-level PackageManager callback/download, Azure/manual installation, rollback and Windows/Unix integration tests remain pending.
- The package's own Composer manifest is valid, but isolated dependency resolution could not be verified because the manifest has no configured private repositories for `exface/core` and `axenox/packagemanager`. The local ExFace host project also declares `deployer/deployer` separately; it is outside this package worktree.

## 4. Teil-Roadmaps / planned technical changes

| # | Roadmap | Primary scope | Status |
|---|---|---|---|
| 01 | [Dependency Removal](01_Dependency_Removal.md) | Early standalone milestone: native compatibility executors and removal from this package manifest; private dependency resolution and host integration gates remain. | [~] In progress |
| 02 | [Pipeline Architecture](02_Pipeline_Architecture.md) | Contracts, tasks/results, selectors, discovery, factory, ownership and execution reporting. | [ ] Not started |
| 03 | [Build Pipelines](03_Build_Pipelines.md) | All four legacy build options; Composer workflow, lock/BOM and artifacts. | [ ] Not started |
| 04 | [Deployment Pipelines](04_Deployment_Pipelines.md) | All nine legacy deployment options, self-extractor behavior, host execution and rollback. | [ ] Not started |
| 05 | [UXON and Migration](05_Uxon_and_Migration.md) | Prototype configuration, editors, SQL migration, data preservation and approved breaking changes. | [ ] Not started |
| 06 | [On-Demand and Stabilization](06_OnDemand_and_Stabilization.md) | On-demand semantics, concurrency, security, OS/bootstrap, end-to-end stabilization. | [ ] Not started |

Checkboxes describe verified implementation work only. At initial decomposition, all work packages and acceptance criteria remain unchecked. The one previously checked host-ownership decision is retained in Roadmap 02 as a decision; it does not change this implementation status.

## 5. Work order and dependencies

1. **Early milestone: Roadmap 01.** The in-package inventory, native compatibility executors, focused tests and package-manifest removal are implemented. Verify remaining private Composer resolution and host integrations; this milestone does not wait for the full V3 pipeline work.
2. Resolve the common contract and execution/state reporting in Roadmap 02 before coding pipeline families. Confirm what is shared and how Actions remain the persistence boundary.
3. Implement and verify the replacement Build and Deployment pipelines in Roadmaps 03 and 04. They depend on the interfaces and factory in Roadmap 02.
4. Define and implement UXON editors and idempotent data migrations in Roadmap 05 after prototype selectors/configuration ownership are sufficiently settled. Migration depends on the selected contracts and supported prototype options in Roadmaps 02–04.
5. Resolve lifecycle, identity, concurrency, bootstrap and security decisions in Roadmap 06 before accepting an on-demand/container deployment implementation.
6. Roadmap 01's package-level removal gate is separate from the full V3 release gates. Roadmaps 02–05 still replace the transitional compatibility mapping with the final pipeline architecture and migrate metadata; Roadmap 06 remains needed for On-Demand Deployment. None of those future features was added as a prerequisite to removing the external recipe engine.

Roadmaps 03–05 are prerequisites to completing the full V3 build/deployment architecture and metadata migration, not to the early dependency-removal milestone. Roadmap 06 provides lifecycle and stabilization gates for the new On-Demand Deployment and final release. These ordering notes do not authorize omitting or silently deferring any existing requirement.

## 6. Cross-roadmap requirements and decisions

- **Build:** Composer remains mandatory for the Composer build pipeline. Preserve Composer settings, timeouts, lockfiles, archive contents, package BOM and the Asset-Packagist workaround. Clone and custom recipes require explicit handling, not silent substitution.
- **Deployment:** Retain supported push, pull/polling, manual and Azure manual paths; remove SSH and custom procedural execution only with a migration/reporting path for existing configurations.
- **Configuration:** Build variants select `prototype_file` and store prototype settings in `config_uxon`. Hosts keep their existing `deploy_config` UXON as pipeline configuration. Host attributes remain authoritative and are supplied through setters.
- **Data:** Preserve projects, variants, hosts, build/deployment history and applicable configuration. SQL-migrate moved attributes/selections. Rename removed database structures to `trash_*`. The approved breaking removal of `path_rel_to_releases` / `relative_deploy_path` is not value-migrated.
- **Status:** A `[x]` on an architecture decision means a decision was recorded, not that code, migration or tests are complete.

## 7. Open decisions

The detail and current status of all inherited open questions remain in Roadmaps 02, 05 and 06. In particular: task/result and intermediate-state reporting; prototype discovery and UXON precedence; migration conflicts and unsupported legacy settings; OS adapters and a standalone self-extractor bootstrap; on-demand release selection, identity, state transitions, retries, persistence and rollouts; critical-failure/rollback guarantees; secret handling and artifact trust; logging, retention and compatibility.

No unresolved item is marked resolved by this document. Keep partial outcomes and their open acceptance criteria in the relevant detailed roadmap.

## 8. Acceptance criteria

- [ ] Each roadmap has its own test/verification plan at the end and all internal relative links resolve.
- [ ] Every original requirement, decision, open question, recipe mapping and legacy configuration entry is assigned to a detailed roadmap; the unchanged original remains available for audit.
- [ ] All four build and nine deployment recipe options are accounted for.
- [ ] The actual implementation status is evidence-based; documentation alone does not close work packages.
- [ ] Dependency removal is the final gated step, not the first implementation step.

### Test plan (run at the end)

Validate all relative Markdown links and verify no original requirements are lost. Run the focused PHPUnit suites described by Roadmaps 01–06, then run supported Windows/Unix end-to-end build, deployment, migration and on-demand scenarios. Record exact commands, environment/version matrix and results before marking completion.
