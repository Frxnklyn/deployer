# Troubleshooting builds

## Native pipeline command failures

The web console runs actions inside the web SAPI. Native builds resolve CLI PHP
through Core's `CliCommandRunner::findPhpExecutable()` for the runtime version;
they must not execute the web server or CGI binary from `PHP_BINARY`.
Other versions still use `PHP_VERSION_PATHS`. CLI execution keeps the running
interpreter rather than selecting an arbitrary PHP from PATH.

Composer output can include credentials and is not forwarded to the build log.
Failures retain their exit code and provide fixed hints for recognized TLS,
repository-authentication and dependency-resolution errors; unknown diagnostics
remain suppressed. Check the variant's native `composer_auth` and the server's
`COMPOSER.AUTH` for authentication failures. For certificate errors, configure a
trusted CA bundle in the selected CLI PHP configuration; never disable TLS
verification. A successful build does not imply a successful vulnerability audit
or target installation.

## Post-build audit certificate errors on Windows

The PHP HTTP audit clients use Windows-managed certificate trust through
`CURLSSLOPT_NATIVE_CA` when supported by PHP/cURL and no explicit CA configuration
is present. Certificate and hostname verification remain enabled. This covers
npm advisories, OSV, registry version checks and Trivy downloads, not Composer's
separate command-line transport. Explicit PHP CA settings, `SSL_CERT_FILE`,
`SSL_CERT_DIR` and client `verify` options take precedence.

On other platforms, unsupported PHP/cURL builds, or installations with explicit
trust configuration, configure a valid trusted CA bundle for the executing SAPI.
The web console uses web PHP, whose configuration can differ from CLI PHP.
Do not disable verification or trust an arbitrary downloaded certificate.

A saved build can be audited again with `axenox.Deployer.AuditBuild`; its archive
does not need to be rebuilt. A successful audit means the configured scanners
that were applicable ran and their findings were saved, not that no issues exist.
Check scanner hints for skipped tools. Composer audit requires a discoverable
Composer executable version 2.7 or later. Native minimal BOMs contain identities,
not license terms, so license findings can report missing evidence; do not infer
an actual license violation or manufacture terms from availability flags.
Re-auditing does not rewrite the original build log.

## Authentication errors with git

### remote: HTTP Basic: Access denied

As of 2021 HTTP basic auth is not supported anymore in GitHub and GitLab. Instead configure token-based authentication in the native `composer_auth` setting of your build variant (the legacy editor called this `auth.json`):

```
{
	"github-oauth": {
		"github.com": "your-token-here"
	},
	"gitlab-token":{
		"git1.yourdomain.com": "your-token-here"
	}
}

```

**NOTE:** according to the composer docs, GitLab domains must also be listed in the root `composer.json` as follows:

```
{
	"config": {
		"gitlab-domains":[
			"git1.yourdomain.com"
		]
	}
}

```

## Composer cache errors

Native builds use a private, per-run Composer home/cache in their isolated
workspace and clean it up after success or failure. They do not reuse the legacy
`data/deployer/<project_alias>/.composer` cache. Clearing that legacy cache will
not fix a native build failure; check the reported cause first.