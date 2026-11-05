# Build&Deploy server for ExFace workbenches

- Configure the build-server
	- [Windows Server](Build_server_setup/Windows_Server_as_build_server.md)
- Configure a host (target) server
	- [Native manual transfer, standalone installation and OTA pull](ManualTransfer.md)
	- [Native updater push and target opt-in](UpdaterPush.md)
	- [Polling and Azure manual-transfer guidance](PipelineMigration.md)
	- SSH deployment is removed in v3; existing SSH hosts require an explicit supported replacement.
- [Troubleshooting builds](Troubleshooting_build.md)
- [Auditing saved builds](AuditBuild.md)
- [Native pipeline migration and cutover](PipelineMigration.md)
