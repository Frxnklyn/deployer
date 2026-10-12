# axenox.Deployer

This package is an additional app for the no-code platform for business web apps called ExFace (or Power UI for the paid version). Once the app is installed on the platform, it turns it into a build and deployment server - comparable to Jenkins, but tailored for ExFace. It provides tools and a UI to 

- define projects and hosts, we want to deploy to
- create deployable builds containing the Core and any number of additional apps packaged in a single archive 
- deploy a build to one or more hosts from the project

## The platform

The platform core is available in `vendor/exface/core`. All [core instructions](../../../exface/core/.github/copilot-instructions.md) apply to this package as well!

Always follow our [PHP](../../../exface/Core/Docs/developer_docs/code_conventions/PHP/Conventions.md) guidelines when writing code. 

## Objectives

The ultimate goal is to make the deployment process as simple as possible, so it can even be configured and executed by non-technical team members reliably. 

Additionally the following things are critical too:

- build and deployment processes must be logged in detail to allow immediate or deferred analysis of issues
	- these logs must be visible on the build server, not only on the hosts
- deployments must NEVER break remote installations - critical errors (if the resulting installation will not allow to start the administration UI) must lead to a roll back immediately, non-critical ones must allow the installation to complete
- deployments must cause as little downtime as possible 
- Deployments must work as automated as possible even if the build server cannot actively contact the host - e.g. via pull or even manual build package transfer. See available deployment recipes below. The reason for this requirement is that many business clients have strict IT security policies, that do not allow SSH. 
- Different type of web servers need to be supported:
	- Windows: Apache (WAMP) and IIS
	- Linux: Apache, nginx
	- Azure PHP App Service for linux
	- Google Cloud Run with dockerized Apache
- The workebch configuration of the hosts must be remote controlled from the central build server. This is important to keep track of all installations and turn important options (e.g. security related) on or off centrally. 
- It should be possible to add new types of build an deployment strategies. In the past especially the list of deployment recipes grew constantly as more and more client-side restrictions came up. 

Since we are dealing with a platform to run apps, it is important to distinguish between infrastructure apps, that are part of the deployment and client payload apps, that only exist lokally. The deployer will only control the apps, that are part of the build. Others will either be copied (if configured as `local_vendors` for the host) or uninstalled.

## Actions and recipes

There are basically two main actions: `Build` and `Deploy`. However, their logic depends build and deployment recipes configured for the project. The recipes actually define, how to do the build and how to deploy it. 

The idea of recipes was borrowed from the external `deployphp\deployer` [package](https://github.com/deployphp/deployer). Recipes are currently procedural PHP files, defining tasks (functions), that can be concatenated into a deployment process. Recipes can be composed of other recipes and run via command line. Thus, our actions merely compose the right tasks into a new recipe, save it as a php file an run it. 

However, it turned out, that using the deployer package did not help much with our needs. We ended up writing a lot of recipes ourselves and could not organize them good enough to be easy to maintain. In future we plan to rewrite the recipes in object-oriented style and replace them with UXON prototypes. The external deployer dependency will be dropped. 

### Building via composer 

Currently all builds are done by running a composer install in a separated project folder and packing the resulting files and folders into a zip archive. Other build recipes exist, but did not prove useful so far. 

### Deploying via self-extractor file

All current deployments start with wrapping the build-zip in a self-extracting PHP script (`.phx` file). When being run with PHP on the host, it 

1. separates its own PHP code from the zip binary
2. creates the required folder structure
3. extracts the zip into a new release folder
4. replaces the symlink pointing to the current release (`%installation%/current`) with a symlink to the new release
5. Copies lokal apps, that are not part of the deployment to the new release
6. runs the regular workbench installers via `php composer.phar run-script post-install-cmd`
7. Does some cleanup to remove old versions. 

Using PHP to do all these things has proven very handy as it saved us from writing cmd/bash scripts for all the different platforms. 

Also having a singe `.phx` file makes it possible to use different ways to send it to the host without rewriting the deployment logic. It can be 

- copied manually and executed on the host command line, 
- sent and executed via SSH, 
- pulled by a polling background task on the host, etc. 

### SelfUpdate companion action

Actually the polling is currently the most wide-spread technique as it does not require the host to be accessible from the build server. 

The app `axenox.PackageManager`, that is currently included on all installations, has a companion action `SelfUpdate`. That action is run regularly in the background. It checks a link from the config pointing to the `DeployerFacade` and checks if there are new deployment packages waiting available for its host. If so, the `.phx` file is downloaded and executes. 
 
The `axenox.PackageManager.SelfUpdate` action can be run 
- as a workbench Scheduler job, or
- as a separate scheduled task in the OS - e.g. in CRON or Windows Task Scheduler. 

### Remote controlling config files

Current deployment recipes allow us to deploy config files to the hosts along with the builds. The configs are packed into the `.phx` files too, so a build is not host-specific, but is sent over in a host-tailored package. 

Having a centralized database of all host configs makes it easy to monitor them from the point of view of security. 

### Rollbacks

The current deployment recipes all use symlink-switches, so there are always multiple builds stored on every host. This makes rolbacks possible:

1. Revert the symlink to the previous release
2. Run installers to downgrade DBs, etc. 

Although it was planned to automate this too, we never did it. It turned out, that it is more reliable to make the deployment either undo itself for critical errors, or produce a working core with smaller side-effects on non-critical ones. After this, admins log int to the platform or the remote server and repair things from there. Still, rolbacks via symlink are also done sometimes by hand. 

## Collecting installation logs

As long as a connection between the host and the build server is possible, the host must send all installation script output to the build server. It is the visible in the deployment log. 

This is extremely important in order to enable build managers to quickly analyze the csuse of issues. Most recipes will send every output line immediately to the build server. This makes it possible to determine the exact moment of an issue remotely - event of it caused the installation to break cometely. 