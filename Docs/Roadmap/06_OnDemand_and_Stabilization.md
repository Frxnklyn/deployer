# 06 - On-demand deployment and stabilization

## 1. Goal

Define safe on-demand/container deployment semantics and the release-level stability gates for lifecycle, identity, concurrency, HTTP behavior, security, OS/bootstrap, logging, reproducibility and cutover.

## 2. Scope / non-goals

- In scope: new on-demand prototype (no legacy equivalent), deployment attempts/identities, state machine, containers, callbacks/downloads, concurrent requests/retries, security/TLS/permissions, OS adapters/standalone bootstrap, logs/exit codes/cleanup and final E2E tests.
- No production implementation should guess unresolved lifecycle rules. No feature expansion into generic workflow authoring.
- Common pipeline contracts are owned by [02 Pipeline Architecture](02_Pipeline_Architecture.md); retained deployment transport behavior by [04 Deployment Pipelines](04_Deployment_Pipelines.md); persisted UXON/migration by [05 UXON and Migration](05_Uxon_and_Migration.md).

## 3. Current state

The current `DeployerFacade` exposes `?redeploy=true`, selects pending deployments with `60 <= status < 90`, sets status `62` before response streaming completes, and intends to reuse the last successful deployment if no pending one exists. The Deploy action uses preparation status `50`, failure `90`, success `99`. The old on-demand proposal contains duplicated state rules and does not settle release selection, retries or client identity.

The proposed on-demand prototype publishes a release like Updater pull but requests a distinct deployment attempt for a new instance and returns a `.phx` when ready. It must not wait for host installation to complete before returning the package. `OnDemandDeploymentInterface` is proposed to extend PSR request handling; whether ordinary pull also uses it remains open. This new prototype has no legacy recipe mapping.

## 4. Planned technical changes

### Lifecycle and identity

Define one explicit state-transition table covering preparation, publication, download response, installation, success (`99`), failure (`90`), cancellation, abandoned attempts and no-history behavior. Reconcile current `50`, `60`, `62`, `90`, `99` meanings. The action owns/persists deployment records; pipelines report state through the explicit reporting contract from Roadmap 02.

Separate host deployment policy from each attempt identity. Give each attempt an unambiguous correlation ID; decide if a container instance ID is also required. Bind downloads and host log/status callbacks to the correct authenticated attempt. Do not correlate by editable `host_short` or “latest pending” when concurrent instances are possible.

Define an authoritative desired/published build, how build variants are selected, first-instance behavior, rollback/promotion and missing artifact behavior. “Latest release” and “last successful deployment” are not equivalent.

### HTTP, artifacts, concurrency and containers

- Choose bounded synchronous preparation or an asynchronous prepare/poll contract supported by the boot client; define preparation timeout, cancellation, no-release/error responses, cache headers, retry guidance and future `?redeploy=true` meaning.
- Wait only until package readiness and download response, not until installation completes.
- Distinguish new instance request from retry/re-download of an existing attempt; prevent retry storms and unbounded attempt creation.
- Use attempt-isolated workspace, generated `.phx`, configuration and logs. Publish artifacts atomically and protect in-use/downloaded artifacts from cleanup.
- Define locking/coordination for concurrent requests, repeated builds, interrupted downloads and callback retries.
- Decide container persistent/shared versus disposable paths, startup readiness, crash behavior, rollout/update policy across replicas and database migration serialization/compatibility.

### Security and execution safeguards

- Keep connection credentials in connection configuration; keep app configuration overrides in pipeline UXON `config` only as documented. Redact secrets in logs/errors and protect packages containing sensitive configuration.
- Define secret references/storage, authorization of bootstrap/download/callbacks, least-privilege execution and integrity verification (checksum/signature decision).
- Always use verified TLS; decide trust customization without allowing verification to be disabled.
- Never interpolate unvalidated operator input into shell commands or installer code. No user-supplied shell templates.
- Define critical vs non-critical failure, activation order, readiness/health checks, retention protection, filesystem/config/database rollback boundaries and manual recovery/downtime expectations. Symlink rollback alone cannot reverse database changes.

### OS adapters, bootstrap and runtime

- Specify supported build-server/host OS and PHP version matrix, adapter selection, portable file/archive/symlink/executable operations and prerequisites.
- Build commands use Core `CliCommandRunner`, but the standalone `.phx` runs before the replacement release autoloader exists and a fresh container may have no Core. Define how it contains/loads only the necessary bootstrap/adapter safely.
- Include archive extraction, executable discovery, symlink permissions, web-server configuration and Windows/Unix differences. No assumption that the build server's OS is the host OS.
- Define finite deadlines; embedded installer currently uses memory limit `2G`, unlimited `max_execution_time=0` and platform-specific `tar`. Do not retain unlimited runtime without an explicit decision.

### Logs, reproducibility, cleanup and compatibility

- Generator yields text only; final structured result carries status. Define remote callback buffering/retry and ensure logging failures do not skip cleanup/rollback.
- Correlate logs to attempts; define redaction and retention.
- Pin/reproduce dependencies and ensure archive lockfile/BOM reflect actual installed dependencies.
- Define separate retention for build archives, host-tailored packages, attempts and logs; protect promoted or downloading artifacts; clean failed workspaces without affecting concurrent jobs.
- Define minimum Core/PackageManager/PHP versions and compatibility for old URLs/clients/mixed-version operation.

## 5. Work packages

- [ ] Define complete attempt state machine and transition ownership for preparation, published package, download and host installation.
- [ ] Define desired release/variant selection, first-instance, failed install, promotion and rollback policy.
- [ ] Define attempt ID, optional instance identity, callback/download authorization and older host-UID-only client compatibility.
- [ ] Define retry/idempotency behavior, request throttling, workspace isolation, locking, atomic publication and artifact lifecycle.
- [ ] Specify HTTP response/cache behavior, sync-vs-async protocol, preparation deadline, cancellation and error responses.
- [ ] Define container persistence, startup/readiness, replica rollout, crash/restart and database migration serialization.
- [ ] Define threat model, secret storage/references, redaction coverage, least privilege, trust verification and artifact integrity policy.
- [ ] Define activation, health check, critical failure, rollback and downtime guarantees, including database/configuration boundaries.
- [ ] Define remote log/status correlation, buffering/retry, exit-code mapping, cleanup on callback failure and retention.
- [ ] Define supported OS/PHP matrix and adapters plus secure standalone self-extractor bootstrap without relying on the replaced installation.
- [ ] Define reproducible dependency/BOM/lock policy and retention for artifacts, attempts and logs.
- [ ] Verify older-client/mixed-version compatibility or document explicit upgrade requirements.
- [ ] Add full E2E scenarios and document exact versions, commands and results before release/cutover.

## 6. Dependencies

- Roadmap 02 must settle task/result/lifecycle reporting and optional request-handler contract.
- Roadmap 04 supplies shared self-extractor and retained pull behavior.
- Roadmap 05 supplies host/attempt configuration boundary and migration policy.
- Roadmap 03 supplies reproducible build artifacts/lock/BOM consumed by deployments.
- Roadmap 01 must not remove Deployer runtime until retained workflows and all release gates pass.

## 7. Open decisions

All of the following were open or partially decided in the original roadmap and remain so:

| Topic | Question / recorded partial outcome |
|---|---|
| Complete state machine | Transition table must include prep `50`, pending `60`, download-start `62`, failure `90`, success `99`, no history and abandoned attempts. Existing rule reuses state 60 and repeats the `>=62 && <90` rule; reconcile with one attempt per new request. |
| Desired release | Select authoritative desired build/variant, distinguish from last successful, and define first instance, failed install, deliberate rollback, promotion and missing artifact. |
| Attempt identity | Several instances can share one host; decide attempt/instance IDs and compatibility with clients sending only host UID. Authenticate/correlate each callback and download. |
| Concurrency/retry | Avoid overwriting same-build/host `.phx`, shared project `deploy.php`, mixed logs or premature cleanup. Define locking, unique workspaces, atomic publication, interrupted-download retry/idempotency. |
| Waiting/HTTP | Sync bounded prep or async prepare/poll? Define timeout, cancellation, no-release responses, cache policy, retry instructions and `?redeploy=true`; only wait until package is ready. |
| Container/rollout | Define persistent/disposable paths, startup readiness/failure, replica rollout and serial database migrations/version compatibility. |
| Rollback/downtime | Define critical/non-critical failure, activation/health-check sequence, previous release retention, filesystem/config/database rollback and manual recovery. |
| Credentials/artifact trust | Define secret references, redaction, authorization, least privilege, integrity checks. Current recorded requirements: credentials stay in connection config; UXON `config` may be sensitive; verified TLS required. |
| Logs/exit codes | Define exit/result mapping, remote failure evidence, callback buffering/retry, cleanup/rollback despite logging failure, correlation and retention. Current recorded requirement: text stream is not status; structured result/action owns persistence. |
| Reproducibility/cleanup | Define dependency pinning, archive lock/BOM correctness and distinct retention for build artifacts, host packages, attempts/logs; protect active/promoted/in-download artifacts. |
| OS/bootstrap | Define OS/PHP matrix, adapter ops and how a standalone self-extractor works before Core/autoloader exists; `CliCommandRunner` alone does not solve fresh-host bootstrap. |
| Verification/cutover | Define supported OS E2E, simultaneous startups, repeated/interrupted downloads, callback attribution, failed install and older-client coverage; cut over only after acceptance. |

These are not resolved by the planned interface names or sample UXON. Resolve each with explicit behavior and measurable acceptance criteria before implementation depends on it.

## 8. Acceptance criteria

- [ ] The attempt lifecycle is complete, unambiguous and tested; package publication/download is not mistaken for installed/successful deployment.
- [ ] Requests and callbacks are authenticated and correlated to the correct attempt; concurrent attempts do not overwrite or mix state/artifacts/logs.
- [ ] New-request versus retry semantics are idempotent and bounded; cleanup cannot remove in-use artifacts.
- [ ] Release/variant selection and container persistence/rollout behavior are explicit.
- [ ] TLS verification, secret redaction, permissions, input validation and integrity policy are documented and tested.
- [ ] Critical failure, health/readiness, rollback limits and manual recovery are explicit, including database migration caveats.
- [ ] OS matrix, standalone bootstrap and CLI behavior work on each supported platform without using the old installation's dependencies as an assumption.
- [ ] Logs, exit codes, callbacks and retention are reliable under callback failure and concurrent runs.
- [ ] Older-client and mixed-version behavior is either tested or accompanied by a clear upgrade requirement.
- [ ] Full V3 cutover is blocked until these tests and Roadmap 01's dependency-removal gate pass.

### Test plan (run at the end)

Add focused unit tests for state transitions, desired-build selection, retry identity, callback authorization/correlation, HTTP statuses/cache behavior, redaction, path/input validation, artifact locks and retention. In a disposable environment, run E2E tests for first container startup, simultaneous instances, same-build concurrent attempts, repeated request/retry, interrupted download, callback arriving late/out of order, no available build, failed install, rollback, container restart and rollout across replicas. Exercise every supported Windows/Unix and PHP combination, including a fresh host with no previous Core installation. Verify logs/exit codes and cleanup when callbacks fail. Test old-client/mixed-version behavior, then record environment versions, commands and outcomes in the release evidence; do not mark unresolved product decisions as passed merely because a test harness assumed one answer.
