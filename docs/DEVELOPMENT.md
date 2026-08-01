# Local Development

## Requirements

- Windows 10 with WSL;
- Docker Desktop with WSL integration enabled;
- Git;
- a web browser.

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

## Check the module mount

```bash
docker compose -f dev/compose.yaml exec openemr sh -lc '''
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module

ls -la "$MODULE"

test -f "$MODULE/info.txt" &&
test -f "$MODULE/openemr.bootstrap.php" &&
echo "Module mounted successfully."
'''
```

## Activate the module

In OpenEMR:

```text
Modules
-> Manage Modules
-> Unregistered
-> Register
-> Install
-> Enable
```

Log out and back in if the module's menu entry does not appear immediately.

## Stop the environment

```bash
docker compose -f dev/compose.yaml down
```

This stops and removes the containers but preserves named volumes.

## Delete the entire local test environment

Only use this when a completely clean OpenEMR installation is required:

```bash
docker compose -f dev/compose.yaml down -v
```

The `-v` option permanently deletes the local OpenEMR database and site volumes.

## Important files

- `dev/compose.yaml` — local OpenEMR development environment
- `info.txt` — module metadata displayed in OpenEMR
- `openemr.bootstrap.php` — module startup entry point
- `src/Bootstrap.php` — event hooks, menu registration, settings, and module behaviour
- `table.sql` — module-owned database schema
- `composer.json` — PHP namespace and package configuration
- `docs/PROJECT_STATE.md` — current status and handoff context
- `docs/DEVELOPMENT.md` — local development commands
