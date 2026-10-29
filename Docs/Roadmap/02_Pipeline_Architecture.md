# 02 - Pipeline architecture

## 1. Goal

Define a small object-oriented contract for developer-maintained, discoverable build and deployment prototypes. Actions instantiate and run them, stream text output, persist lifecycle state and retain ownership of their metamodel records.

## 2. Scope / non-goals

- In scope: pipeline/task/result contracts, selectors, factory, discovery, class layout, lifecycle reporting and host/build-variant ownership.
- Not in scope: arbitrary stages, dependency graphs, condition builders, a general-purpose workflow editor, or implementation of individual Composer/deployment behavior.
- UXON storage, editors and migration details are owned primarily by [05 UXON and Migration](05_Uxon_and_Migration.md).

## 3. Current state

The current system selects static recipe enum values and invokes procedural Deployer tasks. The model metadata registers `BuildRecipeDataType` and `DeploymentRecipeDataType`. The old roadmap proposes prototype classes and actions, but none is evidence that the interfaces or discovery mechanisms already exist.

The previous roadmap records **host ownership of deployment selection/configuration as decided** (`[x]`): each host may select its own prototype; actions supply host attributes via public setters. This is a decision, not an implemented or tested feature. Other architecture checklist items remain open.

## 4. Planned technical changes

### Namespaces and discovery

- Usable prototypes: `axenox\Deployer\Pipelines`, `axenox\Deployer\Pipelines\Build`, `axenox\Deployer\Pipelines\Deploy`.
- Shared abstract implementations/helpers: `axenox\Deployer\Common\Pipelines`.
- Add `axenox\Deployer\Common\Selectors\PipelinePrototypeSelector` and `PipelinePrototypeSelectorInterface`. A selector may accept a file path or a PHP class; paths are proposed for database storage.
- Add `axenox.Deployer.pipeline_prototype_build` and `axenox.Deployer.pipeline_prototype_deploy` discovery metaobjects, modeled after `exface.Core.COMMUNICATION_MESSAGE_PROTOTYPE`.
- Add `axenox\Deployer\Factories\PipelineFactory`, including `createPipelineFromUxon($workbench, $selectorOrString, $uxon)` and any justified convenience methods.
- Validate family, discoverability, existence and instantiability; provide actionable errors for missing or wrong-family selectors.

### Contracts

`PipelineInterface`:

- Extends `iCanBeConvertedToUxon`.
- Declares exactly `run(PipelineTaskInterface $task): \Generator`.
- The generator yields text only; after complete consumption its PHP return is a `PipelineResultInterface`, retrieved through `Generator::getReturn()`.
- Family interfaces inherit the same parameter type. Do not narrow the method declaration to a specialized task type. Implementations validate and reject unsupported task types before doing work.

`BuildPipelineInterface` extends `PipelineInterface` as a family marker. Its implementations accept build tasks and return a `BuildResult` implementing `PipelineResultInterface`.

`DeploymentPipelineInterface` extends `PipelineInterface` as a family marker. Its implementations accept the task appropriate to their workflow and return a `DeploymentResult`. The common deployment interface/task does not require an artifact or `.phx`.

Task types:

- `PipelineTaskInterface`: common run-specific input; exact shared accessors remain open.
- `BuildTaskInterface extends PipelineTaskInterface`: includes `getComment()` and `getNotes()`.
- `DeploymentTaskInterface extends PipelineTaskInterface`: no required build artifact.
- `ReleaseDeploymentTaskInterface extends DeploymentTaskInterface`: selected build artifact for release installation.
- `ConfigurationDeploymentTaskInterface extends DeploymentTaskInterface`: configuration-only changes, without release transport. This is a supported distinction, not a decision about a specific future transport.

Result types:

- `PipelineResultInterface` includes `getStatus(): int`.
- `BuildResult` and `DeploymentResult` implement it; detailed accessors remain to be defined.
- Artifact metadata is required only for workflows which produce or transport an artifact.

### Ownership and streaming

- Build variant stores selected prototype in `prototype_file` and UXON settings in `config_uxon`. Composer JSON/auth belong to the Composer prototype, not separate variant attributes.
- Host stores selected deployment prototype/settings. Reuse its existing `deploy_config` UXON. Every host attribute remains authoritative on the host and passes through public setters; no duplicate host-attribute UXON properties.
- Actions own build/deployment records and persist state. Pipelines report state transitions and perform the workflow; they do not directly update action-owned records.
- Generator output is text only. Never infer status from console text.
- A final result cannot report progress while the generator runs. Specify an action-provided lifecycle reporter or another explicit channel; host-side status/log callbacks must reach the owning persistence boundary after build-server execution.
- For deployment preparation, the result may mean published/awaiting installation rather than installation completed.

## 5. Work packages

- [ ] Specify shared and family-specific task accessors, validation and incompatibility errors.
- [ ] Specify result accessors, exit/status semantics, artifact metadata and exception behavior.
- [ ] Specify intermediate state reporting and persistence/transaction boundaries without encoding state in text.
- [ ] Confirm when actions consume generators and retrieve `getReturn()`, including generator exceptions and missing/invalid return values.
- [ ] Specify selector serialization, app-qualified paths, discovery rules and wrong-family diagnostics.
- [ ] Implement factory creation/configuration validation and prototype discovery for both families.
- [ ] Define how the host connection is injected; where required, specify `DeploymentViaConnectionInterface::setConnection(DataConnectionInterface)`.
- [ ] Define whether ordinary Updater pull also needs the optional `OnDemandDeploymentInterface`.
- [ ] Keep class names recognizable from recipe names where retained, without keeping procedural tasks.
- [ ] Add a concise developer contract and API examples when the implementation is settled.

## 6. Dependencies

- Roadmap 01 depends on this contract before removing procedural execution.
- Roadmaps 03 and 04 depend on stable family/task/result contracts.
- Roadmap 05 depends on selector serialization and discoverable prototypes to define stored values/editors.
- Roadmap 06 depends on status reporting, tasks, results and HTTP capability contracts.

## 7. Open decisions

- Exact task/result accessors, including build task version/PHP inputs and deployment attempt/host inputs.
- Lifecycle reporter API and boundaries for action transactions and pipeline exceptions.
- Whether lifecycle transitions can be safely reported through an injected reporter, callback or event mechanism.
- Selector's canonical DB path syntax and compatibility handling for PHP class values.
- Factory convenience methods and discovery/validation implementation pattern.
- Whether ordinary pull uses `OnDemandDeploymentInterface`.
- Data-connection injection for active push transports.

The generator/result, ownership and generic task design above are recorded requirements. They do not close the still-open details listed here.

## 8. Acceptance criteria

- [ ] Both family interfaces inherit the identical `run(PipelineTaskInterface $task): \Generator` signature.
- [ ] A test prototype demonstrates yielding multiple text messages and returning a typed result; `getReturn()` is accessible only after exhaustion.
- [ ] Invalid task types, wrong-family selectors, missing prototypes, invalid UXON and generator exceptions fail explicitly and do not produce success-shaped fallback results.
- [ ] Intermediate and final statuses are persisted only by the owning Action, and no status is inferred from output text.
- [ ] Configuration-only deployment is representable without a release artifact or `.phx`.
- [ ] Host attributes are supplied through setters; no UXON duplicate can override them.
- [ ] Selector/discovery tests cover cross-app prototypes, serialization round trips and actionable failures.

### Test plan (run at the end)

Run the focused architecture PHPUnit suite (for example `vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite unit`) after adding contract tests. Include tests for generator return retrieval, task rejection, family discovery, selector round trips, action-owned state updates, exceptions, and a deployment task with no artifact. Then run all package unit tests and the integration tests from Roadmaps 03–06 to verify the contracts against actual build, deploy, migration and facade paths.
