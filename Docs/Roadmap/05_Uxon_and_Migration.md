# 05 - UXON and migration

## 1. Goal

Persist discoverable pipeline prototype selections and their documented UXON settings on the correct owner (build variant or host), provide appropriate editors/validation, and safely migrate existing configuration and history.

## 2. Scope / non-goals

- In scope: `prototype_file`, `config_uxon`, host `deploy_config`, editors, value ownership/precedence, SQL migration, data preservation, unsupported legacy configurations, approved breaking changes and `trash_*` rules.
- Do not create a second host configuration store, duplicate host attributes in UXON, add arbitrary pipeline composition, or discard unknown values silently.
- Contract details are defined in [02 Pipeline Architecture](02_Pipeline_Architecture.md); recipe semantics in [03 Build Pipelines](03_Build_Pipelines.md) and [04 Deployment Pipelines](04_Deployment_Pipelines.md).

## 3. Current state

Current build recipe selection and Composer-specific settings are represented through legacy project/build configuration and `BuildRecipeDataType`; deployment recipe selection and settings use host/project configuration and `DeploymentRecipeDataType`. `Model/01_DATATYPE.json` registers both legacy enum prototypes. Hosts already have `deploy_config`; the previous roadmap explicitly says to reuse it.

Recorded decisions, not implementation status:

- Each host owns its deployment selection/configuration; hosts in one project may choose different pipelines. This architecture decision is marked `[x]` in the original roadmap, but editor/migration implementation remains open.
- Build variants own selected prototype and settings using `prototype_file` and `config_uxon`.
- Host attributes stay authoritative and are passed to pipelines through setters, not duplicated in UXON.
- V3 preserves existing data except the expressly approved removal of `path_rel_to_releases` / `relative_deploy_path`.

## 4. Planned technical changes

### Selectors and editors

- Use `PipelinePrototypeSelector` / `PipelinePrototypeSelectorInterface`; proposed stored selector value is an app-qualified prototype file path. Exact serialization and PHP-class compatibility remain open.
- Discovery metaobjects: `axenox.Deployer.pipeline_prototype_build`, `axenox.Deployer.pipeline_prototype_deploy`, analogous to `exface.Core.COMMUNICATION_MESSAGE_PROTOTYPE`.
- Build variant fields: selected `prototype_file`; selected prototype's UXON in `config_uxon`. The build editor offers cross-app prototype discovery and that prototype's documented properties/defaults/autosuggest/validation.
- Host: selected deployment pipeline and settings stay in existing `deploy_config`. The host editor offers discovery plus a UXON editor. Do not wrap `deploy_config` in a duplicate UXON layer.
- Switching prototypes must not silently erase stored values. Show incompatible/unknown settings for deliberate handling; distinguish prototype-only UXON from authoritative host attributes.
- `ComposerInstall` alone owns `composer_json` and `composer_auth`. Non-Composer build prototypes need not expose those fields.
- Pipeline UXON may contain settings only when no corresponding host attribute exists. Host path, PHP executable, OS, project/host identity and connection remain host/task inputs supplied through setters; no duplicate UXON override.

### Proposed configuration and preservation rules

| Existing value / source | V3 location / migration |
|---|---|
| Project-level build recipe | Migrate selection to each applicable build variant's `prototype_file`; preserve per-variant differences. |
| Build variant `composer_json`, `composer_auth` | Merge non-empty values into Composer `config_uxon` only. Do not add `{}`, empty string or `NULL`; redact credentials. |
| Build `source_files`, `composer_timeout` | Composer prototype UXON. Preserve the legacy defaults and values; validate entries. |
| `ComposerInstallAssetFix` enum selection | Composer `asset_packagist_cache_fix: true`; resolve no-prior-lock behavior explicitly. |
| Project-level deployment recipe | Migrate selection to each applicable host so hosts can subsequently differ. |
| Host `deploy_config` | Retain as deployment-pipeline UXON container; keep supported `keep_releases`, `local_vendors`, `local_packages` in place. |
| `deploy_config.default_app_config` | Rename only the nested property to `config`; preserve filename-to-config map, values and merge behavior. |
| Effective retention | Preserve `deploy_config.keep_releases ?? keep_releases`, with recipe fallback `4`; do not leave duplicate sources. |
| `deploy_config.local_packages` | Keep full Composer package names and existing behavior, independent from `local_vendors`. |
| Host `path_abs_to_api`, `path_rel_to_releases`, `php_cli`, OS, connection | Preserve authoritative host attributes except approved path break; do not copy host attributes to pipeline UXON. |
| `relative_deploy_path` / `path_rel_to_releases` | Approved v3 breaking change: remove with no substitution, value migration, legacy-layout guarantee or empty-relative-path behavior. Still rename dropped DB structures to `trash_*`. |
| Unknown/custom settings | Preserve for report/inspection; require explicit mapping or operator decision. Do not silently drop. |
| Projects, variants, hosts, builds, deployments, history | Preserve all records and relationships unless explicitly included in the approved breaking change. |

General precedence remains open: define how prototype defaults, persisted UXON, task/run values and existing-target migration conflicts interact. Preserve any target value with documented precedence; do not invent a precedence by choosing whichever field is convenient.

### SQL migration

- Move attributes and selections with explicit SQL migration scripts. Project-level build recipe/settings must reach applicable variants; deployment selection must reach applicable hosts.
- Preserve host `deploy_config`; update only documented nested values such as `default_app_config` → `config`.
- Migrate transformed non-empty values only; omit empty/null Composer/auth UXON entries.
- Migration must be idempotent and should support preview/reporting, conflict detection, actionable manual intervention and recovery instructions.
- Unsupported values (`CloneLocal`, custom paths, SSH recipes) require explicit report/replacement; preserve data needed to explain the issue.
- Rename removed database tables/columns to `trash_*` per SQL migration rules instead of silently dropping them.
- Do not migrate host attributes into UXON duplicates.

## 5. Work packages

- [ ] Agree selector path serialization, cross-app discovery, family checks and behavior for PHP-class selectors.
- [ ] Define schema/editor behavior for build `config_uxon` and host `deploy_config`.
- [ ] Define default/persisted/task precedence and conflict policy for existing target data and renamed keys.
- [ ] Define non-empty UXON serialization rules, especially `composer_json`, `composer_auth` and unknown properties.
- [ ] Implement prototype selectors/discovery controls and build/host editors; preserve UXON safely when prototype changes.
- [ ] Create idempotent SQL migration for build recipe/settings to build variants.
- [ ] Create idempotent SQL migration for deployment recipe/settings to hosts; preserve host config maps, local package/vendor behavior and effective release retention.
- [ ] Add migration preview/report for unsupported legacy options, custom recipe paths, unknown keys and selector mismatch.
- [ ] Preserve all records/history and verify foreign keys/relationships before/after upgrade.
- [ ] Rename removed columns/tables to `trash_*`; explicitly implement no-value-migration breaking removal for `path_rel_to_releases` / `relative_deploy_path`.
- [ ] Document minimum Core/PackageManager/PHP versions and mixed-version upgrade compatibility, coordinating with Roadmap 06.

## 6. Dependencies

- Roadmap 02 defines selectors, discovery, factory and input/ownership contracts.
- Roadmaps 03 and 04 define the destination prototype configuration and supported/retired legacy values.
- Roadmap 01 depends on removing enum registration only after these migration and editor paths replace it.
- Roadmap 06 defines attempt/callback identity and security fields that must not be stored as arbitrary pipeline UXON.

## 7. Open decisions

- Canonical app-qualified path stored in database and PHP class selector compatibility.
- UXON schema/autosuggest/validation discovery mechanism, unknown-property handling and prototype-switch preservation UX.
- Defaults versus stored UXON versus run inputs precedence; behavior where both old and new fields are populated.
- Exact migration preview/report and rollback mechanism, minimum versions, and mixed-version client/server compatibility.
- Whether any custom setting has a supported automatic mapping; unknown values remain manual, not silently resolved.
- SQL migration/trash naming details per existing project migration conventions.

The ownership decisions above are recorded; the serialization, precedence, migration procedure and editor details remain open. No open decision is resolved solely by specifying a proposed field name.

## 8. Acceptance criteria

- [ ] Build variants store selection/configuration in `prototype_file` / `config_uxon`; hosts reuse `deploy_config`.
- [ ] Composer-only properties are not introduced as universal build settings; host attributes cannot be shadowed in UXON.
- [ ] Discovery/editor only offers valid family prototypes, explains invalid/missing choices, documents fields and validates input.
- [ ] Changing prototypes cannot silently discard incompatible stored settings.
- [ ] SQL migrations are idempotent, preserve records/history, preserve non-empty effective settings and produce actionable reports for unsupported settings.
- [ ] Empty UXON properties are omitted; auth/config secrets are protected/redacted.
- [ ] `trash_*` rules and the approved `path_rel_to_releases` breaking change are implemented exactly, not generalized to other data.
- [ ] Old enum/model metadata is retired only after migration and new editors are verified.

### Test plan (run at the end)

Run migration tests against representative pre-V3 database snapshots containing all four build and nine deployment selectors, populated and empty Composer settings, conflicting old/new config keys, local packages/vendors, release overrides, unsupported custom/SSH values, history and approved breaking-change columns. Apply migration twice and compare records, values, relations and `trash_*` structures; assert idempotency and reports. Test editor selector round-trips, prototype switches with unknown keys, validation, wrong-family paths, secret redaction and host-attribute precedence. Finally upgrade a disposable installation and exercise one build variant and multiple differently configured hosts.
