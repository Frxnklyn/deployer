The time has come for a new major version of `axenox.Deployer`. 

## Goals

- remove `deployer/deployer` dependency
- simplify configs for hosts and build variants by using UXON prototypes with Autosuggest, docs, etc. 
	- simplify adding new config variables
	- simplify adding new prototypes (e.g. deployment via DevOps)
- introduce operating system adapters as an abstraction over wimdows/Unix differences on build server as well as on hosts. 
- remove obsolete recipes and their logic
- keep compatibility with existing host and build variant configs (or at least make automatic migration possible)

## Key changes

- Recipes will become UXON prototype classes now. This will allow us to quickly extend them, create new ones, use LogBooks to collect debug information, etc. 
	- Additionally, UXON annotations would produce autosuggests and presets in the editors. 
	- we will not need the static Enums `BuildRecipeDataType` and `DeploymentRecipeDataType`. Instead we will scan folders (`Build Recipes` and `DeploymentRecipes`) with UXON prototypes across all apps, just like we do with all other prototypes. This will allow other apps to add recipes. 
- No more dependency on the external PHP deployer library. We did not use much of it and its recipe format has proven to be hard to extend and maintain for us. 
- Deployment recipes to be moved from project to every individual host. This will allow different deployment strategies inside one project. 

Legacy build recipes to be removed:

- `Clone current installation` - never was really used
- `Custom build recipe` - not needed anymore, because recipe prototypes can be added to apps anyway

Legacy deployment recipes to be removed:

- All SSH based logic. It never worked reliably
	- `Build-ZIP + SSH install` - this never worked really well, so quickly stopped development here
	- `Build-ZIP + SSH install + wait fix` - this was just an experiment
- `Custom Deployment Recipe` - not needed anymore

## New prototypes

To test the new structure, we will implement the following additions. 

### On-demand deployment

New deployment prototype `Self-Extractor + on-demand deployment`. This will publish a new release just like the Updater-pull does, but it will re-create the last successful deployment when a request to the Deployer Facade is made (instead of answering, that there is no update). 

This new prototype shall enable containerized environments to pull the latest release every time a container instance is fired up. For example, when we have a container for Google Cloud Platform, it will download and install the newst build for its project when booting up. This will close the deployment. When the container is restarted, it will need to download another build, so we need a new deployment. When we publish a new release, it waits for the first container to pick it up. After this all subsequent requests will create their eployments for that new release. 

This new prototype needs to implement the new `OnDemandDeploymentInterface`, which extends PSR request handler interface. This interface will allow the facade to offload creating the response to the recipe completely. Perhaps, even the existing Updater pull recipe might use this mechanism? 

A new  deployment shall be created every time a request is made - even multiple times for the same build. This is important for container based installations, where a new container instance needs to download the current version every time. 

- if there is a deployment in state 60 - return that phx
- if there is a deployment in state (>= 62 and <90) start a new deployment for the same build
- if there is a deployment in state (>= 62 and <90) start a new deployment for the same build

When a new deployment is started, the prototype waits for it to finish and returns the phx file to the facade as a response for download. 
## Approach

### 1. Analyze existing code

- Scan the existing Recipe files. 

### 2. Plan the new classes

- analyze similarities and differences between existing recipes and plan new classes
- create `BuildRecipeInterface` and `DeploymentRecipeInterface` with public methods common across all recipes. Keep in mind, that the currently prevailing `.phx` method may not be the only one in future. Maybe we need a separate interface for this type of deployment on top of the generic `DeploymentRecipeInterface`? 
- decide, if we need abstract base classes
- create empty prototype classes for every remaining recipe
- write class docblock with usage documentation and examples
- move the existing configuration for the number of versions to keep on a host to the deployment prototypes. After all, it is not a property of the host, but rather of the deployment idea with a switchable current symlink.


## Technical details of the new architecture 

### Object oriented approach

- Use OOP patterns instead of the current procedural recipes
- Stick to UXON prototype principles
### Running CLI commands

Use the `CliCommandRunner` from the core to run commands. It works well on all our server environments. 
