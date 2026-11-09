# Native UpdaterPush


Select `axenox\Deployer\Pipelines\Deploy\UpdaterPush.php`. This uses the
same native standalone package as UpdaterPull, without `deployer/deployer`.
All ManualTransfer preservation/configuration settings remain supported.
Only the action supplies host attributes and the host's HTTP data connection;
never put a URL, username, password, connection or host attribute in pipeline UXON.
The connection URL is the **installation base URL**, not `/api/updater`.
Authentication is retained from the existing UrlDataConnector HTTP connector.

Push still requires an OS-compatible bound host installation root. The
[pull/polling deferred-root exception](ManualTransfer.md#deferred-explicit-target-root-pullpolling-only)
does not apply: automatic execution of a deferred package fails before target
changes or completion receipt creation. There is no HTTP or automatic root
override. Reprepare a correctly bound package instead.

Transport always requires HTTPS, certificate/hostname verification, no redirects,
a 10-second connection limit and a bounded total deadline (default 900 seconds;
`deadline_seconds`: 10–1800). Optional `trusted_ca_bundle` names a readable local
PEM CA bundle. It cannot disable verification.

## Target opt-in

Install the paired PackageManager code (Deployer is not needed on the target).
The existing Basic authentication middleware authenticates the request. The
separate `/api/updater/native-push` route additionally requires these trusted
PackageManager application configuration options:

```json
{
  "UPDATER.NATIVE_PUSH.ENABLED": true,
  "UPDATER.NATIVE_PUSH.ALLOWED_USERS": ["dedicated-deployment-user"],
  "UPDATER.NATIVE_PUSH.STAGING_PATH": "C:\\private\\native-updates",
  "UPDATER.NATIVE_PUSH.PHP_EXECUTABLE": "C:\\php\\php.exe"
}
```

Defaults are disabled/deny. Provision an existing dedicated private staging
directory **outside the web installation/document root**, owned by the web worker.
Do not use a directory containing unrelated files. Unix mode is 0700 (files 0600);
Windows uses a protected owner-only DACL, rejecting links/reparse-point ancestors.
The target never accepts executable paths, staging paths or settings from HTTP.
An allowlisted account can execute uploaded PHP: treat it as an installation
administrator, not a general application account.

The receiver bounds input to 512 MiB and receive/execute time to 600 seconds.
Configure reverse-proxy/web-server request size, read and worker timeouts to enforce
the same bounds (a blocked server-side request-body read cannot be preempted by PSR-7).
Only existing managed native layouts are supported. Legacy/unmanaged installations
are intentionally rejected, not adopted or migrated automatically.

## Protocol and truthful outcomes

POST raw `application/vnd.axenox.native-ota` with a positive `Content-Length`,
`X-Deployer-Deployment` (safe attempt ID), `X-Deployer-SHA256` (lowercase SHA-256),
and `X-Deployer-Filename: ota-<sha256(attempt-id)>.phx`. No encoded/chunked input.
An attempt directory is atomically reserved; replay is rejected rather than rerun.

The receiver streams and hashes bytes before starting PHP with an argv array.
`EXFACE_NATIVE_OTA_RECEIPT` names a nonexistent private sidecar. The standalone
bootstrap reserves it before side effects and writes, only after successful
normal installation and cleanup:

```json
{"protocol":"axenox.Deployer.native-ota/1","deployment_id":"attempt-id","sha256":"artifact-hash","status":99}
```

The receiver requires exit 0 **and** the independently read matching sidecar;
stdout (even “success”) is not evidence. It responds with that JSON over
authenticated HTTPS. A matching structured status 60 response means upload only;
it never marks an installation complete. Errors, missing/mismatched receipts,
timeouts and nonzero exits produce status 90, never success-shaped fallback.

## Recovery

The source retains the attempt's private `.native-deployments/ota-*.phx`, logs
its SHA-256 and private path, and never publishes a file-facade link. On target
failure, inspect the matching private `<staging>/<sha256(attempt-id)>` directory.
Installer stdout/stderr and credentials are not returned or logged by this protocol.
After stopping traffic, jobs and installer workers, use the same verified package
with `--recover` for filesystem recovery. Database changes are **not** rolled back;
verified database/files backups and operator review remain essential.
Do not blindly retry/delete an attempt reservation after an HTTP timeout: the
server might still be running. Reconcile its private receipt and installed state
first. Successful attempts retain the small receipt but remove the uploaded package.

Tests use in-memory HTTP and disposable fixture PHP subprocesses only; no live
deployments, schema imports, remote calls or real application installation.
