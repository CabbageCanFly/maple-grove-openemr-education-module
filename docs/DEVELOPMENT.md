# Local Development

## Requirements

- Docker Desktop or Docker Engine with Docker Compose;
- Git;
- a Bash-compatible shell;
- a web browser.

The project has been tested with Windows 10, WSL, and Docker Desktop. The same Docker Compose workflow is intended to work on macOS and Linux.

On Windows, enable Docker Desktop's WSL integration before starting the environment.

## Start OpenEMR

From the repository root:

```bash
docker compose -f dev/compose.yaml up -d
```

Watch startup:

```bash
docker compose -f dev/compose.yaml logs -f openemr
```

Press `Ctrl+C` to leave the log viewer without stopping the containers.

Open:

```text
https://localhost:9302
```

Local development login:

```text
admin / pass
```

A browser warning for the local development certificate is expected.

## Check the Module Mount

```bash
docker compose -f dev/compose.yaml exec openemr sh -lc '
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module

ls -la "$MODULE"

test -f "$MODULE/info.txt" &&
test -f "$MODULE/openemr.bootstrap.php" &&
echo "Module mounted successfully."
'
```

The repository is bind-mounted into the container, so edits made on the host should appear immediately without rebuilding the image.

## Activate the Module

In OpenEMR:

```text
Modules
-> Manage Modules
-> Unregistered
-> Register
-> Install
-> Enable
```

Then open:

```text
Administration
-> Config
-> Maple Grove Education
```

Enable the Education Dashboard menu item and save.

Log out and back in if the menu entry does not appear immediately.

The page should be available at:

```text
Modules
-> Education Dashboard
```

## Restart OpenEMR

```bash
docker compose -f dev/compose.yaml restart openemr
```

Use this to verify that the module remains available after a normal container restart.

## Stop the Environment

```bash
docker compose -f dev/compose.yaml down
```

This stops and removes the containers but preserves named volumes.

## Delete the Entire Local Test Environment

Only use this when a completely clean OpenEMR installation is required:

```bash
docker compose -f dev/compose.yaml down -v
```

The `-v` option permanently deletes the local OpenEMR database and site volumes.

## Important Files

- `dev/compose.yaml` — local OpenEMR development environment
- `info.txt` — module metadata displayed during registration
- `openemr.bootstrap.php` — module startup entry point
- `src/Bootstrap.php` — event hooks, menu registration, settings, and module behaviour
- `src/GlobalConfig.php` — module configuration options
- `public/education-dashboard.php` — current dashboard page
- `table.sql` — module-owned database schema
- `composer.json` — PHP namespace and package configuration
- `docs/PROJECT_STATE.md` — current status and handoff context
- `docs/AWS_TEST_DEPLOYMENT.md` — disposable AWS proof-deployment steps

## Platform Notes

The commands in this guide use relative repository paths and Docker Compose, so they are not inherently tied to WSL.

Platform-specific differences may include:

- Docker Desktop file-sharing permissions on macOS;
- Docker Desktop WSL integration on Windows;
- whether `docker` requires `sudo` on a Linux host;
- the browser command used to open `https://localhost:9302`.

The OpenEMR and MariaDB containers themselves remain the same across these host environments.
