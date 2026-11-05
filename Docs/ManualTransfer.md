# Native manual transfer and standalone installation

Select `axenox/deployer/Pipelines/Deploy/ManualTransfer.php` on a host's **Deployment Pipeline** field. Missing or invalid selections fail explicitly; neither Build nor Deploy executes legacy recipes. Review the [pipeline migration preflight](PipelineMigration.md), backups, target layout and paired PackageManager prerequisites before installing the updated migrations/model through the normal release process. Tests do not perform live data conversion or target deployment.

## Build-server setup and commands (operator-run)

These commands are instructions, **not commands executed for readiness validation**. Back up the build server's database/configuration first. From the existing application's root, install/update Deployer's additive migrations and models using the normal PackageManager action:

```powershell
php vendor\bin\action axenox.PackageManager:InstallApp axenox.Deployer
```

This changes that application's schema/model. Check the installer output and migration/model results, not just the CLI exit code: the PackageManager CLI wrapper may catch per-app failures. Do not import individual JSON files or execute the SQL manually as a substitute. Refresh the model/UI after installation.

1. Select `axenox/deployer/Pipelines/Build/ComposerInstall.php` in the build variant's **Build Pipeline** (`prototype_file`). Put native settings in **Pipeline Configuration** (`config_uxon`), not legacy `composer_json` / `composer_auth_json`. A starting example, to adapt to the project's approved versions/repositories:

   ```json
   {
       "composer_json": {
           "require": {"exface/core": "^1.0", "axenox/packagemanager": "^1.0"},
           "minimum-stability": "dev",
           "prefer-stable": true,
           "repositories": [
               {"type": "composer", "url": "https://asset-packagist.org"}
           ]
       },
       "composer_timeout": 1200,
       "asset_packagist_cache_fix": false
   }
   ```

   Add the application packages and private repositories you actually need. Supply repository authentication in native `composer_auth` or server `COMPOSER.AUTH`; never embed credentials in repository URLs/manifests. A usable target build must contain Core, PackageManager and all their dependencies, including asset packages. Keep host-install Composer hooks out of this input: root scripts/plugins run as trusted build-time code. The pipeline adds PackageManager hooks to the packaged manifest only after the build.
2. The build server needs an existing `composer.phar` in its installation root, `tar` on PATH, working CLI process execution, writable project/build directories and disk space. **The currently reviewed Core manifest requires PHP ^8.3**, despite the dependency-free bootstrap's lower PHP 8.1 minimum. Match target PHP/extensions to the actual built dependencies. For a different build PHP version, configure `PHP_VERSION_PATHS` in `config\axenox.Deployer.config.json` and pass `--php <configured-version>`; the current version uses the running `PHP_BINARY`. Composer operation timeout defaults to 1200 seconds, source entries to `vendor`, `composer.json`, `composer.lock`, `composer.phar`. The optional asset-cache workaround uses the latest successful same-variant lock; missing/invalid history fails before building. See [migration and asset guidance](PipelineMigration.md).
3. Select the manual pipeline on the intended host and replace/adapt its `deploy_config` using the bounded settings below. Host `path_abs_to_api` is the **native installation root**, not the future `current` directory or a legacy API file. Set host `php_cli` to a PHP executable (no shell flags), and `operating_system` to the appropriate host OS. No SSH connection is needed for manual preparation. Existing legacy layouts/settings are not converted or adopted.
4. Build, then prepare using the **generated build name** from the build record/log (`version+YYYYmmddHHMMSS`), not the version alone:

   ```powershell
   php vendor\bin\action axenox.Deployer:Build <project-alias> <version> <variant-name-or-UID>
   php vendor\bin\action axenox.Deployer:Deploy '<generated-build-name>' '<host-name>'
   ```

   Replace angle-bracket placeholders before execution. Build arguments are **project, version, variant**; Deploy arguments are **build, host**. Check build status 99 and its archive before preparation. Native Deploy returns status 60 and a private package path/hash, **not a completed deployment**. Continue with the transfer/installer procedure below; do not execute legacy deploy recipes on this native host.

For manual/Azure/push, if Deploy reports an invalid host installation root, correct the host's
`path_abs_to_api`, not its pipeline UXON. A Windows host requires a drive-qualified
absolute filesystem path such as `C:\Apps\MyApplication`; `/my-application` is not
a valid Windows installation root. Linux/Unix hosts require an absolute Unix
path. Confirm the actual native layout before changing this field: pointing it
at a legacy installation does not migrate that installation. This validation
fails before package preparation and does not explain an unrelated build audit
error. Build status 99 means the archive was built, not that its audit completed
or that the host was installed.

Pull/polling preparation has the explicitly approved exception below: it may
publish without a usable root binding, but cannot install without an explicit
independently validated directory. It does not repair or adopt the saved path.

### Windows build-server process identities

The private `.native-deployments` directory uses a protected, non-inherited DACL:
only its owner and Windows `LocalSystem` (`S-1-5-18`) have access. This allows a
local owner's CLI and an Apache service running as LocalSystem to prepare/read
the same packages without granting access to Users, Everyone or web visitors.
Repeated preparation preserves the existing owner instead of replacing its access
with the current process identity. Existing owner-only directories must be updated
once by their owner using the updated preparation code before LocalSystem can use
them. Other custom service identities need explicit operator-managed permissions;
this code does not automatically grant them access or change service accounts.
Apache/IIS static-delivery denial remains in place. LocalSystem is privileged;
using this existing service account is not a recommendation to switch services to it.

The Deploy dialog and `action axenox.Deployer:Deploy` both use the host's selected
native pipeline. No separate v3 dialog is required. The Deployer Console page now
starts only the action list, not the removed external `dep` CLI; import updated
pages through the normal PackageManager installation and reload the page.

### Historical executable package location

Native executable-producing Deploy actions again publish the operator-facing file
in the existing project's `builds` directory as `<build-name>_<host-alias>.phx`.
The alias uses the existing `Deploy::getHostAlias()` normalization (spaces become
underscores; other non-alphanumeric/non-underscore characters are removed).
For example, build `4.2.1+20261105154438` on `My Local Dev One` produces
`builds\4.2.1+20261105154438_My_Local_Dev_One.phx`. The action log reports this path.
Repeated preparation atomically replaces this convenience copy with the latest
package for that build/host, as in the historical workflow.

An immutable, deployment-owned copy remains in `.native-deployments` for the
authenticated OTA route, so publishing again does not replace an earlier attempt's
download. The readable build/host filename is not an attempt identifier.
The package still uses the native bootstrap, without `deployer/deployer`.

Packages can contain host secrets. Exports are staged with private file permissions
and retain them on publication; build-directory permissions and TAR archive delivery
are not changed. Automatic Apache/IIS rule generation in the build directory is
currently commented out at the operator's request. To enable it, uncomment the
optional block in `NativeDeploymentPackage::protect()`'s build-export branch;
review existing rules first. The retained block checks conflicts and writes
`.htaccess` / `web.config` with PHX denial. Existing files are neither removed nor
modified while generation is disabled. Web access protection is operator-owned;
without equivalent server rules, exported packages may be publicly accessible.
Internal `.native-deployments` protections remain enabled.
Copy/transfer the file privately or use the authenticated OTA route; do not expose
the historical filename as an unauthenticated URL. Status 60 still means published,
not installed. Failure cleanup removes only matching artifacts owned by that attempt,
never a newer replacement with a different hash.

The host `deploy_config` is the settings container. A runnable bounded configuration is:

```json
{
    "config": {
        "System.config.json": {
            "DEBUG.ENABLED": false,
            "METAMODEL.CONNECTOR_CONFIG": {
                "host": "127.0.0.1",
                "port": 3306,
                "dbase": "my_isolated_application",
                "user": "my_application_user",
                "password": "<operator-supplied-secret>",
                "charset": "utf8mb4"
            },
            "SERVER.INSTALLATION_NAME": "my-application",
            "INSTALLER.SERVER_INSTALLER.CLASS": "\\exface\\Core\\CommonLogic\\AppInstallers\\ApacheServerInstaller",
            "INSTALLER.SCHEDULERINSTALLER.DISABLED": true
        }
    },
    "keep_releases": 4,
    "shared_dirs": ["backup", "data", "logs", "translations"],
    "copy_dirs": ["config"],
    "local_vendors": ["client"],
    "local_packages": [],
    "php_memory_limit": "2G"
}
```

The non-config settings match native defaults, except `local_vendors` defaults to empty. `config` also defaults to empty. Use `config`, not legacy `default_app_config`. SQL conversion maps eligible selections/settings, but never adopts a legacy target layout. Project license defaults and host overrides are merged by package/license identity. The native license adapter preserves Composer-style definitions accepted by the installed PackageManager without unavailable legacy BOM serialization APIs.

The example's `config` values are **operator configuration, not native defaults**. Provision the intended target database/user yourself and supply the full connector object: overrides replace whole top-level values, so a partial `METAMODEL.CONNECTOR_CONFIG` does not merge missing fields. Never leave Core's default database credentials as your target. `System.config.json` is read before Core starts; Core app-only settings may instead belong in `exface.Core.config.json`. A private standalone root has no reliable CLI web-server auto-detection: explicitly select the correct existing Core server installer (`ApacheServerInstaller`, `IISServerInstaller`, or `NginxServerInstaller` in the same namespace). This writes application-level server files, not virtual hosts. Keep the scheduler installer disabled for isolated testing and when scheduling is operator-managed; otherwise Core can register real OS tasks pointing into the candidate release **before activation**. Configure routing and any production scheduling separately through the stable `current` path.

Host root, PHP executable and OS originate only from host attributes through action setters. Neither preparation nor installation reads `path_rel_to_releases`, `relative_deploy_path`, or a relative release-root setting. The explicit CLI root must match the host root recorded in the manifest; UXON cannot change it.

## What preparation produces

Deploy creates a run and passes a release task to `ManualTransfer`. `NativeDeploymentPackage` snapshots and inspects the successful build, then publishes a unique private `.tar.gz` containing:

- `build.tar.gz` and its SHA-256 in `manifest.json`;
- `manifest.json` with artifact/deployment/host/build identities, host inputs and settings;
- `installer.php`, `runtime-install.php`, and the three required native PHP helper files;
- `README.txt` with host-tailored transfer, installation and filesystem-recovery instructions.

This is a runnable CLI installation package, **not** an executable `.phx` self-extractor. No old installation, repository autoloader or external `deployer/deployer` library is required by the bootstrap. The extracted build must itself contain a working Core/PackageManager runtime and its `vendor/autoload.php`.

The action persists status **60 (prepared/published, awaiting manual work)**, private artifact path and outer SHA-256. It does not set `completed_on` or status 99. Standalone success is local operator evidence only: no deployment-record update or callback protocol is invented here.

## Operator procedure

1. Retrieve the package as the producing account or over an independently authenticated administrative channel. Packages/configuration can contain secrets; never publish them through the file facade or another public URL.
2. Verify its **outer SHA-256 against the independently obtained deployment log**, before extracting or executing any bundled PHP. Windows: `Get-FileHash -Algorithm SHA256 <package-path>`; Unix: `sha256sum <package-path>`. A payload hash in an untrusted manifest alone is not authentication.
3. Extract the verified outer package into a private, non-served staging directory. Never extract `build.tar.gz` directly over a live installation.
4. Arrange downtime: stop application traffic, queues, schedulers and all installer workers. Take independently verified database and filesystem backups. Database migrations may run **before activation**, against the configured database.
5. Create the intended absolute host root as a normal directory owned by the installation account. Root ancestors must not be links/junctions. For example, from the extracted outer package:

   ```powershell
   & 'C:\path\to\php.exe' installer.php --root 'C:\private\my-installation'
   ```

   Use the host's configured PHP executable. The running CLI interpreter (`PHP_BINARY`), not executable text from the manifest, launches child processes. Require PHP >=8.1 CLI, zlib, `proc_open`, sufficient disk space for full releases/snapshots, and on Windows PowerShell/.NET with junction and private DACL support. The tested host is Windows/PHP 8.3.36. Unix code exists but has **not** been executed as platform acceptance.
6. Require exit 0 and the explicit application-installation/activation success message. Keep operator evidence separately. Configure/verify web routing to `root\current`; this installer does not change virtual hosts, permissions for another service account, service configuration, or create a legacy `exface` alias. Exercise application health checks before restoring traffic.
7. Remove the transferred secret-bearing staging copy when no longer needed. Source packages, unused shared stores and releases retained with `keep_releases=-1` require authorized operator cleanup; never remove active/immediate previous releases.

The private `.native-installer` directory has an owner-only Windows DACL (0700/0600 on Unix) and Apache/IIS deny files. Releases remain private to that account; application services must have an independently approved access arrangement, typically running as that account. **Exclude both `.native-installer` and `.native-deployments` from every direct HTTP/file-serving route, indexing and unauthorized backups, including nginx configurations.** Access through the deliberate `current` application route requires operator review; web-server behavior is not configured or verified by these tests.

## Installation, configuration and activation

- The root is fixed. Layout is `root\.native-installer\releases\<random-attempt>` with `root\current` as a managed junction on Windows or symlink on Unix. A first install and subsequent native updates are supported. Existing ordinary `current` directories, unmanaged links, linked data paths and legacy layouts are rejected, not adopted or overwritten.
- A persistent local lock serializes attempts. A private payload snapshot is checked against SHA-256. The same raw tar parser independently inspects it on the host and extracts regular files/directories directly, without delegating extraction semantics to Phar or an external tar executable. It rejects traversal, absolute/backslash paths, links/devices, credential filenames, case collisions, conflicting parents, Windows aliases/reserved names, malformed checksums and truncation. Bounded POSIX path/timestamp metadata is supported; GNU/global extensions are not. Limits remain 20 GiB and 200,000 entries. This is not malware scanning: project builds/installers must be trusted.
- `config` is always copied from the old managed release when present. Safe **top-level** `copy_dirs` are copied as complete directory snapshots (old directory replaces the new build directory); links/special files fail closed. Existing config JSON must be an object. Host overrides use legacy-style **top-level `array_replace`**, preserving unspecified keys and array values; this is not a recursive merge. Overrides also apply to empty/new config objects.
<!-- Generated by GitHub Copilot - Nov-05-2026 -->
- `shared_dirs` defaults to `backup`, `data`, `logs`, `translations`. Only safe, non-overlapping **top-level** directory names are supported; they cannot overlap copied directories or `config`. Content lives in private `root\.native-installer\shared\<name>` stores, with managed release junctions/symlinks. First install seeds missing stores from the build. Upgrades preserve the existing store, ignoring replacement build content. A prior ordinary directory in a managed native release can seed a new store without modifying that previous directory; this is not legacy-layout adoption.
- Application installers receive **private snapshots**, not live shared links. Preserved snapshots and stores must remain unchanged through installation; mutation causes an explicit pre-activation failure rather than exposing active/previous data to installer writes. Shared links are created only after successful installation. Links redirected away from the exact managed store, nested links/reparse points, and special files fail closed. Applications may write shared data after activation, but operators must stop traffic/workers during installation. There is no concurrent shared-data merge, installer-time live shared mutation, or filesystem rollback of normal application writes.
- `local_vendors` copies absent packages from the old vendor into the candidate **before** application installation, without replacing packages already supplied by the new build. Core/PackageManager/Composer vendor names are protected. New-runtime PackageManager app discovery then installs these local apps. Composer autoload metadata for arbitrary new PHP packages is not regenerated: use this only for compatible locally discovered apps.
- `local_packages` accepts full `vendor/package` names and overlays the previous package **even when the new build includes it**. This bounded implementation preserves non-executable assets only: root `composer.json`, `composer.lock`, `vendor/autoload.php`, all `vendor/composer` metadata, package manifests and executable/autoload-relevant files must match exactly. Each package must exist in both releases, identify itself correctly, and appear in the production lock. Only JSON/CSS/maps/documentation/images/fonts without PHP opening tags may differ. Missing packages, code/dependency/autoload changes or protected runtime packages fail explicitly; rebuild those packages with Composer instead. The installer does not execute Composer, resolve dependencies, download code or regenerate autoload metadata.
- Application installation loads **only the new autoloader**. Where available its Core argv runner starts a fresh worker per app; otherwise the bundled argv runner does so. Each worker uses the new PackageManager bootstrap and throwing `InstallApp::installApp` generator, Core first then discovered apps, and stops its workbench. This deliberately avoids the legacy Composer hook/CLI wrapper that can swallow per-app failures. Nonzero exits, exceptions, error output yielded by the installer, or missing completion receipts prevent activation. Output/command diagnostics are suppressed to avoid leaking credentials.
- Activation occurs only after application installers complete. The old activation link is saved, the new link renamed into `current`, and a durable journal records the commit. This is a downtime operation, **not a zero-downtime/atomic cross-platform routing promise**. No health/readiness policy or database success protocol is invented.
- `keep_releases` defaults to `4`, accepts integers of at least `2`, or `-1` to retain all releases. Successful commits are tracked in a private release-history ledger; pruning runs **after commit** and always preserves active and immediate previous releases. Failed candidate installation never prunes successful releases. Random release names and timestamps are not ordering evidence. Untracked releases with bounded retention, altered activation/history, linked release roots or cleanup failures require explicit operator review, not guessed deletion. Interrupted post-commit cleanup is resumed by `--recover` without reverting activation. Shared stores are not pruned with release directories.

### Explicit unsupported settings and limits

Nested shared/copied directories, overlapping/case-aliased directories, retention values below `2` except `-1`, protected local vendors/packages, and unknown settings cause explicit errors **before a package is published**. The standalone installer independently revalidates these settings before creating a release. Config overrides must map safe configuration filenames to JSON objects (not lists). Runtime-dependent package compatibility and shared snapshot checks fail before activation. Do not manually delete active/immediate previous releases or edit their ledger.

The bounded installer does not uninstall removed apps, regenerate local-package dependency metadata, support arbitrary local-package code overrides, perform license-document generation parity, automatically adopt legacy layouts, download anything, or configure services/web servers. Real Core database/model installation against a live application is deliberately not exercised. These installer tests alone do not establish full v3 acceptance or authorize external deployer removal.

## Filesystem failure and interruption recovery

Ordinary installation failure removes that attempt's candidate/snapshot and keeps/restores the previous activation. Previous successful releases and configuration remain. A process killed between steps leaves `journal.json`; subsequent installs refuse to proceed.

Stop all installer workers and inspect database/external side effects, then use the same verified package:

```powershell
php installer.php --root 'C:\private\my-installation' --recover
```

Recovery takes the host lock and refuses while a new-runtime worker holds its shared runtime lock. It validates journal-derived managed paths, restores an interrupted pre-commit activation, removes that failed candidate, and finishes cleanup of a committed successful switch without reverting it. Repeated recovery is safe. Unknown or externally changed activation targets fail closed for manual review. Do not edit the journal, remove locks, or recover while descendant processes/services might still write; abrupt kills and power loss require operator verification. The local lock is not a distributed/NFS concurrency protocol.

**Recovery is filesystem activation recovery, not database rollback.** Migrations, copied-release installer writes to external paths, logs or other external systems cannot be undone by restoring a link. Do not resume the old application until its database compatibility is established or an approved database backup/recovery procedure has completed. No native rollback command, database rollback guarantee, callbacks or on-demand state rules are claimed.

## Native OTA pull (opt-in, paired local client)

`axenox/deployer/Pipelines/Deploy/UpdaterPull.php` is a specialization of the
manual pipeline: the same UXON properties, host setters and bounded installer
apply. It creates an executable `.phx` without running `vendor/bin/dep`.
Preparation still returns **60 / published, not installed**. The Deploy action
already supplies release tasks to this specialization; no separate action or
general-purpose workflow engine is introduced.

**Do not switch an existing legacy host to this prototype as an upgrade shortcut.**
The installer still rejects unmanaged/legacy activation links. Shared directories,
local individual packages and pruning remain unsupported; use the documented
native defaults, including `keep_releases=-1`. No existing host selection,
configuration, database or live target is changed by introducing this prototype.

The local PackageManager changes are required on the **receiving host** before
using this experimental protocol. They are source changes, not a released minimum
PackageManager version or an approved mixed-version rollout policy:

- Keep the existing authenticated Deployer OTA URL and host credentials.
  Native downloads require **HTTPS with trusted certificates**; the client's
  certificate-error bypass is rejected. HTTPS redirects cannot downgrade to HTTP.
  The facade also rejects cleartext native downloads/callbacks. TLS-terminating
  proxies must provide the correct request scheme through the application's
  trusted proxy configuration; an arbitrary forwarded header is not a bypass.
- Use the regular PHP/Guzzle download path, not CLI cURL. The updated client
  advertises `X-Deployer-Native-OTA: 1`; older/CLI clients receive HTTP 426 before
  a native package is served or its status changed. Legacy packages keep the old
  download/callback behavior.
- The response supplies `X-Deployer-Deployment` and `X-Deployer-SHA256`. The client
  validates the attempt-derived filename and complete SHA-256 before making a
  native package eligible for execution. This checksum is transported over the
  authenticated TLS channel; it is **not an independent artifact signature**.
- All subsequent native log/status callbacks include the downloaded
  `deployment` identity. The facade filters by that identity **and** the requested
  project/host rather than applying a delayed message to the latest deployment.
  Missing/invalid identity, backwards status updates and updates to a terminal
  native attempt are rejected. Terminal callbacks require an explicit final status.
- Download-only status **67** is not installed/99 and does not set `completed_on`.
  The executable's nonzero exit is still a failed installation. Preparation and
  download never constitute successful application installation.
- Authenticated OTA serves immutable copies from private `.native-deployments`
  storage, isolated by deployment identity, with `private, no-store` headers.
  The operator-facing copy is also published in `builds` under the historical
  build/host filename described above; it is not a file-facade/public link.
  Configure host-account/download-directory ACLs and exclude the private artifact
  directory from every other HTTP/file route; the existing authentication policy
  still needs deployment-specific authorization/least-privilege review.
- Downloaded packages contain host secrets: use a private receiving directory.
  Native PHP downloads reserve a new filename exclusively. Existing files are
  not overwritten automatically; investigate stale downloads before operator
  cleanup. Re-publication of an existing attempt is rejected. Artifact retention,
  callback retry/idempotency and concurrent-client release acceptance are not
  completed by these local guards.

For manual recovery, execute the same trusted `.phx` with `--recover`, after
stopping installer workers. The package uses private staging beside the `.phx`
(the private receiving directory must be writable) and the
same filesystem journal/lock as manual installation. Recovery is never database
rollback. The package uses the PHP executable with which it is launched; normal
SelfUpdate uses the client's configured PHP executable.

### Deferred explicit target root (pull/polling only)

Preparation still uses Deploy's existing build/host arguments. If the supplied
host root is empty, relative, traversing or incompatible with the selected host
OS (for example Windows with `/local_host_pui_one`), native pull/polling can publish
status **60** without making that path usable. Its manifest omits
`host.installation_root` and records
`host.installation_root_binding: "deferred_explicit"`. No host attribute is
rewritten and no alternative root enters pipeline UXON.

After verifying the private artifact hash and reviewing backups, target database,
layout and traffic/jobs, the operator supplies the independently chosen target:

```powershell
php '<private-package.phx>' --root 'C:\Apps\ExplicitNativeRoot'
php '<private-package.phx>' --root 'C:\Apps\ExplicitNativeRoot' --recover
php vendor\bin\action axenox.PackageManager:SelfUpdate --native-root 'C:\Apps\ExplicitNativeRoot'
```

These are instructions, not executed target commands. The directory must already
exist, be absolute for the execution OS, contain no traversal or linked ancestors,
and not be a filesystem root. Linux/Unix use an existing absolute Unix path.
Missing/invalid root fails before target locks, journals, releases or receipts.
Recovery requires the same deliberately selected root; it restores filesystem
activation only, never a database. There is no automatic legacy-layout adoption.

A valid OS-compatible saved root remains strictly bound; `--root` cannot override
it. Existence of a remote bound root is checked on the receiving host, not guessed
on the build server. Unknown/null/ambiguous binding markers fail closed.
PackageManager forwards `--native-root` as a literal argv value only for a
verified native OTA download; legacy execution is unchanged. No root is inferred
from the current application or download location.

Manual/Azure and automatic push preparation remain strict. Automatic push cannot
install a deferred package or produce a success receipt, even if an explicit root
is supplied. Reprepare a correctly bound package for automatic push. This is the
narrow approved exception DEV-003 in the central plan, not full v3 acceptance.

This is **ordinary explicit pull publication**, not on-demand release selection,
automatic polling registration or legacy-layout migration. Polling is a separate
pull specialization with host-owned scheduler instructions; push has its own
[authenticated target opt-in and completion receipt](UpdaterPush.md).
The Deployer package no longer requires `deployer/deployer`; the shared
application lock/vendor tree is not rewritten automatically. Native fixture
acceptance is not production/platform acceptance.

## Local verification

From the application's `vendor` directory:

```powershell
Remove-Item Env:EXFACE_DEPLOYER_PERSISTENCE_TEST, Env:EXFACE_DEPLOYER_REAL_INSTALL_TEST -ErrorAction SilentlyContinue
php bin\phpunit -c axenox\deployer\phpunit.xml.dist --display-warnings --filter 'AssetPackagistCacheTest|NativeOtaTest|NativeOtaFacadeTest|NativeOtaDownloaderTest|PipelineContractsTest|PipelineFactoryTest|ComposerInstallTest|BuildComposerOverrideTest|BuildNativePipelineTest|DeploymentLogLinksTest|ManualTransferTest|NativeHostInstallerTest|DeployNativePipelineTest|DeploymentLicenseConfigTest|NativeInstallationSafetyTest|AzureManualTransferTest|NativeUpdaterPushTest|NativePushTransportTest|NativePushFacadeTest|LegacyDependencyRemovalTest' axenox\deployer\Tests\PHPUnit
git -C axenox\deployer -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --check
```

`NativeHostInstallerTest` builds actual pipeline artifacts, copies and extracts their outer packages into isolated transport directories, and runs bundled `installer.php` under a separate `php -n` process, without a repository autoloader. A fake **new-runtime** Core runner/PackageManager exercises first install, update, config/local-app preservation, shared content preservation and mutation rejection, build-present local-package asset overrides and code/dependency rejection, bounded retention, failed/early-zero-exit installers, fallback without Core's runner, malicious archives, root/copy/shared junctions, main/worker locks, new-store cleanup and interrupted activation/committed retention recovery. No network, downloads, live hosts or live schema/model are changed. This proves the vertical packaging/bootstrap/application-install invocation/activation path, not production database installer correctness or a comprehensive OS matrix.

`NativeOtaTest` additionally executes copied `.phx` packages under `php -n` and
through the PackageManager process adapter (including paths with spaces), checks
attempt exclusivity, corrupted-payload rejection and unmanaged-layout protection.
`NativeOtaFacadeTest` uses in-memory sheets and response streams;
`NativeOtaDownloaderTest` uses in-memory HTTP responses. These tests are not a real
TLS server/client or production target deployment.

Native fixture scratch directories now live beside, not inside, the Deployer worktree (`vendor\.deployer-test-<label>-<random>`). Cleanup is exact-tree/link-safe, with bounded Windows deletion retries and shutdown cleanup; no OS temporary directory is used by these test fixtures.

### Optional real installation test — reserved for the operator

`RealNativeInstallationTest.php` is excluded from default suites and skips unless explicitly enabled. It remains **unexecuted/unverified** in this readiness pass. It mirrors locally installed dependency code into a unique external scratch tree, disables remote Composer repositories/network/plugins/scripts, builds twice, and exercises actual Core/PackageManager schema/model installation and startup against its own random database/user. It requires a local Composer PHAR, compatible installed dependency manifests/versions, PHP/extensions and a **disposable local MySQL server** with explicit database/user-administration credentials. Offline dependency constraints can fail; no remote download or version bypass is performed. Do not point it at production.

From the application's `vendor` directory, only when you deliberately choose to run it:

```powershell
$env:EXFACE_DEPLOYER_REAL_INSTALL_TEST = '1'
$env:EXFACE_DEPLOYER_REAL_INSTALL_WORKSPACE = 'C:\private\deployer-tests'
$env:EXFACE_DEPLOYER_TEST_MYSQL_HOST = '127.0.0.1'
$env:EXFACE_DEPLOYER_TEST_MYSQL_PORT = '33306' # Your disposable instance's actual port
$env:EXFACE_DEPLOYER_TEST_MYSQL_USER = 'your-test-admin'
# Supply EXFACE_DEPLOYER_TEST_MYSQL_PASSWORD privately if required.
try {
    php bin\phpunit -c axenox\deployer\phpunit.xml.dist --display-warnings axenox\deployer\Tests\PHPUnit\Integration\RealNativeInstallationTest.php
} finally {
    Remove-Item Env:EXFACE_DEPLOYER_REAL_INSTALL_TEST, Env:EXFACE_DEPLOYER_REAL_INSTALL_WORKSPACE, Env:EXFACE_DEPLOYER_TEST_MYSQL_HOST, Env:EXFACE_DEPLOYER_TEST_MYSQL_PORT, Env:EXFACE_DEPLOYER_TEST_MYSQL_USER, Env:EXFACE_DEPLOYER_TEST_MYSQL_PASSWORD -ErrorAction SilentlyContinue
}
```

Create/restrict the workspace yourself first: it must be a normal private, non-served directory outside the **entire live installation**, not inside this repository. The test creates/removes only its own random child, and normally drops its generated database/user in `finally`; Core scheduling is explicitly disabled and the Apache file installer explicitly selected. Forced termination can leave that exact child/database/user behind: inspect the disposable server and remove only test-owned resources after ensuring no workers remain. This opt-in test is not a deployment certification or a substitute for your actual build/host acceptance test.
