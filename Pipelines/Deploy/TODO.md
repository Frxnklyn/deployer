# Deployment pipeline TODO

Status: 2026-11-05. Native build and package publication work locally; this is
not full v3 release acceptance. The central task/decision/evidence record is
[Plan.md](../../Docs/Roadmap/Plan.md), with coverage in
[Requirements.md](../../Docs/Roadmap/Requirements.md).

## Verified local slice

- Composer build through the web console, archive creation and saved-build audit.
- Manual/Azure/pull/polling/push prototypes and bounded standalone installer fixtures.
- Pull publication without a usable saved target root; installation still requires
  an explicitly validated root. No automatic legacy-layout adoption.
- Historical `builds/<build>_<host-alias>.phx` export, retaining immutable internal
  copies for authenticated per-attempt OTA downloads.
- External `deployer/deployer` requirement and recipe execution removed.

## Still to do

- [ ] Operator acceptance on an actual disposable target: first install, update,
  configuration/database/model installation, failure and recovery. Real target
  testing is reserved for the operator; local publication is not installation.
- [ ] Establish supported Windows/Unix/PHP matrix; exercise real HTTPS pull,
  callbacks, polling scheduler setup, push and Azure rollout on intended targets.
- [ ] Decide complete on-demand state machine, authoritative release selection,
  attempt/instance identity, retries/concurrency, timeouts and abandoned attempts
  before implementing the remaining on-demand workflow.
- [ ] Complete callback/download authorization, least privilege and bootstrap trust
  review. SHA-256 over authenticated TLS is not an independent artifact signature.
- [ ] Complete local package/vendor-map parity, removed-app handling, retention
  limits and filesystem-vs-database recovery acceptance; do not imply DB rollback.
- [ ] Review unsupported clone/custom/SSH configurations, migration conflicts,
  reruns/downgrades and preserved historical records across representative installs.
- [ ] Complete approved `trash_*` handling of removed relative-release-path fields,
  with migration/recovery evidence. Do not restore the intentionally removed layout.
- [ ] Finish editor/prototype-switch acceptance and lifecycle reporting/LogBooks.
- [ ] Pin/document compatible PackageManager/Core release versions and validate
  clean Composer resolution without the removed external dependency.
- [ ] Supply Composer >=2.7 on the audit runtime PATH; review missing license
  evidence from minimal native BOMs. A successful audit is not a clean assessment.
- [ ] Decide durable Composer download-cache policy separately; current builds use
  isolated disposable caches.
- [ ] Resolve `DeployLicensesConfigTest`: 5 of 6 tests fail (2026-11-05).
  Review legacy wrapper vs native Composer-style license output and exception types;
  verify the recorded-evidence rejection contract, which no longer throws as expected.
  Do not simply weaken the test or treat every failure as a stale assertion.
  Focused native tests do not replace a green full suite.

## Build-directory web protection

At the operator's explicit request, optional `.htaccess` / `web.config` generation
for build exports is commented out, not deleted, in
`NativeDeploymentPackage::protect()`'s build-export branch. The retained block
documents how to reactivate it and checks existing-rule conflicts. Existing files
are left unchanged. Keep secret-bearing PHX exports inaccessible through web-server
configuration; private internal artifact-directory protection stays enabled.

## Paired repository

Ship the matching PackageManager native download/callback/root/push and Windows
audit-trust changes together. Follow-up items are also recorded in
[PackageManager native deployment TODO](../../../packagemanager/Docs/NativeDeploymentTODO.md)
when the repositories are checked out side by side.