# Maple Grove OpenEMR Education Module

A custom OpenEMR module for the Maple Grove educational clinic environment.

The module adds education-focused tools directly inside OpenEMR. Its planned scope includes student and team task tracking, education-specific activity events, progress monitoring, and instructor-facing analytics.

## Current Status

This project has completed its first working proof of concept.

Implemented:

- a local OpenEMR 7.0.2 development environment using Docker Compose;
- a live bind mount from this repository into OpenEMR's custom-module directory;
- module registration, installation, enablement, and configuration through OpenEMR;
- an **Education Dashboard** link in the OpenEMR Modules menu;
- a styled placeholder dashboard that opens inside an OpenEMR tab;
- a Maple Grove-specific configuration section;
- removal of the skeleton's dummy configuration requirement;
- successful test deployment to a disposable clone of the AWS OpenEMR environment.

Not implemented yet:

- student or team task assignments;
- education-specific activity logging;
- completion calculations;
- instructor analytics;
- role-based dashboard views;
- a permanent AWS deployment method that survives container recreation.

## Compatibility Target

The current compatibility target is:

- OpenEMR 7.0.2;
- Docker image `openemr/openemr:7.0.2`;
- MariaDB 10.11;
- Docker Compose;
- a Unix-like command-line environment.

Development has been tested with Windows 10, WSL, and Docker Desktop. The commands are also intended to work on macOS and Linux with Docker and a Bash-compatible shell, although those environments have not yet been formally tested by this project.

## Local Development

### Requirements

- Docker Desktop or Docker Engine with Docker Compose;
- Git;
- a Bash-compatible shell;
- a web browser.

On Windows, WSL with Docker Desktop integration is recommended.

### Start the environment

From the repository root:

```bash
docker compose -f dev/compose.yaml up -d
```

Watch OpenEMR startup:

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
Username: admin
Password: pass
```

A browser certificate warning is expected in the local environment.

### Confirm the module bind mount

```bash
docker compose -f dev/compose.yaml exec openemr sh -lc '
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module

test -f "$MODULE/info.txt" &&
test -f "$MODULE/openemr.bootstrap.php" &&
echo "Module mounted successfully."
'
```

The repository is mounted directly into OpenEMR, so PHP and template edits made on the host are immediately visible inside the container.

## Activate the Module

Inside OpenEMR:

1. Log in as an administrator.
2. Open **Modules → Manage Modules**.
3. Open the **Unregistered** tab.
4. Register the module.
5. Install the module.
6. Enable the module.
7. Open **Administration → Config → Maple Grove Education**.
8. Enable the Education Dashboard menu item.
9. Log out and back in if the menu entry does not appear immediately.

The dashboard should then be available at:

```text
Modules → Education Dashboard
```

## Stop the Environment

```bash
docker compose -f dev/compose.yaml down
```

This removes the containers but preserves the named database and OpenEMR site volumes.

To completely erase the local development environment:

```bash
docker compose -f dev/compose.yaml down -v
```

The `-v` option permanently deletes the local OpenEMR database and site volumes.

## AWS Test Deployment

The module has been successfully tested on a disposable clone of the AWS OpenEMR 7.0.2 environment.

The current proof-deployment method:

1. connects to the EC2 host through SSH;
2. clones this repository;
3. copies the module into the running OpenEMR container with `docker cp`;
4. registers, installs, enables, and configures the module in the OpenEMR website.

See [`docs/AWS_TEST_DEPLOYMENT.md`](docs/AWS_TEST_DEPLOYMENT.md) for the exact steps.

This method is suitable for testing, but it is not the final deployment design. Files copied into a container can be lost if that container is deleted and recreated. A derived Docker image or persistent module mount will be needed for permanent deployment.

## Repository Structure

```text
.
├── dev/
│   └── compose.yaml
├── docs/
│   ├── AWS_TEST_DEPLOYMENT.md
│   ├── DEVELOPMENT.md
│   └── PROJECT_STATE.md
├── public/
│   └── education-dashboard.php
├── src/
│   ├── Bootstrap.php
│   └── GlobalConfig.php
├── composer.json
├── info.txt
├── openemr.bootstrap.php
├── table.sql
└── README.md
```

Important files:

- `dev/compose.yaml` — local OpenEMR and MariaDB development environment;
- `src/Bootstrap.php` — module startup, event hooks, settings, and menu registration;
- `src/GlobalConfig.php` — module configuration options;
- `public/education-dashboard.php` — current placeholder dashboard;
- `info.txt` — module name and version shown during registration;
- `table.sql` — future module-owned database schema;
- `docs/PROJECT_STATE.md` — current decisions, progress, and handoff context;
- `docs/AWS_TEST_DEPLOYMENT.md` — temporary AWS proof-deployment procedure.

## Planned Architecture

The module is expected to provide:

1. **Education Dashboard**
   - student and team participation;
   - assigned task progress;
   - completion rates;
   - recent educational activity.

2. **Task Management**
   - individual and team-based assignments;
   - task status and completion timestamps;
   - links between tasks and synthetic patients or encounters.

3. **Education Event Logging**
   - dashboard access;
   - task starts and completions;
   - meaningful educational workflow events;
   - aggregated instructor reporting.

4. **Instructor Analytics**
   - participation summaries;
   - progress by student or team;
   - task completion trends;
   - overall system usage.

Educational events should be stored in module-owned tables rather than changing OpenEMR core tables unnecessarily.

A future top-level **Education** menu may contain separate student and instructor pages. During the early class prototype, education pages may be visible to all logged-in users. Role-based restrictions can be added later so students see their own work while instructors and administrators see cohort analytics and assignment-management tools.

## Development Principles

- Keep OpenEMR core files unchanged.
- Implement custom behaviour through the module system.
- Use synthetic patient data only.
- Do not commit passwords, certificates, database dumps, tokens, or server secrets.
- Test deployment changes on a disposable AWS clone before the shared server.
- Keep this module separate from the Maple Grove Synthea and OpenEMR importer project.
- Update `docs/PROJECT_STATE.md` whenever a major milestone or architectural decision changes.

## Related Project

The separate Maple Grove Synthea/OpenEMR project generates GTA-focused synthetic patient data and imports supported clinical history into OpenEMR.

This repository focuses on OpenEMR interface customization, educational workflows, activity tracking, and dashboard functionality.

## License and Attribution

This project was initialized from the OpenEMR Custom Module Skeleton and retains its GNU General Public License 3 licensing requirements and applicable attribution notices.

OpenEMR is a separate open-source project and is not maintained by this repository.
