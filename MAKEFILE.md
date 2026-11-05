# Make commands

Run `make` or `make help` to list this project's commands. GNU Make is required;
on Windows install Git Bash at its standard location, or pass `BASH="C:/path to/bash.exe"`.
Python 3.10+ is used for portable help, source archives and guarded cleanup. Override
executables with `PYTHON`, `PHP`, `COMPOSER`, `NPM`, `NODE`, `DOTNET`, `PWSH`,
`GIT`, or `GITLEAKS`; quote an executable path containing spaces, for example
`make help PYTHON="C:/Python 3/python.exe"`. Values identify one executable,
not an executable plus arguments.

Use `make install` (or `install-dev` where available) before running the real
`test`, `lint`, and `build` targets. `make check` runs the project's existing
quality gate (Entity-GUI retains its existing prerequisite check; use `make quality` there). `make -n <target>` prints commands without running that target.
Development servers, database changes, deployments, version bumps, and publication
remain explicit targets and are never run by the default help target.

`make package` / `make source-package` archives **committed HEAD sources** to
`SOURCE_ZIP` (default `dist/source.zip`); it does not include uncommitted changes.
Use the project's named production/WordPress distribution target for installable
artifacts. The helper checks configured output and cleanup paths against the
repository boundary and refuses tracked files, repository metadata and symlink
escapes. Cleanup never removes configuration or application data intentionally.

## Project workflow

`make build` / `make zip` packages the current plugin version through the existing platform-specific builder; version changes require the explicit `make bump` command. The output directory is guarded before the builder can remove old output; tracked sources and paths outside the repository are refused. `make wordpress-org-package` remains the preferred audited WordPress directory edition. `make lint` runs the complete repository source checks, and `make check` runs the existing lint/test/build gate. No services are started by installation or help.
