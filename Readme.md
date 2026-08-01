# Maple Grove OpenEMR Education Module

A custom OpenEMR module for the Maple Grove educational clinic environment.

The module is intended to add education-focused tools directly inside OpenEMR, including a dashboard for student participation, assigned task progress, completion tracking, and instructor-facing analytics.

## Current Status

This project is in its initial proof-of-concept stage.

Implemented:

- local OpenEMR 7.0.2 development environment using Docker Compose;
- live bind mount from this repository into OpenEMR's custom-module directory;
- module registration, installation, and enablement through OpenEMR;
- an **Education Dashboard** link in the OpenEMR Modules menu;
- a styled placeholder dashboard that opens inside an OpenEMR tab;
- a Maple Grove-specific configuration section;
- removal of the skeleton's dummy configuration requirement.

Not implemented yet:

- student or team task assignments;
- education-specific activity logging;
- completion calculations;
- instructor analytics;
- authentication or authorization rules for dashboard roles;
- production deployment to the shared AWS OpenEMR environment.

## Compatibility Target

The current compatibility target is:

- OpenEMR 7.0.2;
- Docker image `openemr/openemr:7.0.2`;
- MariaDB 10.11;
- Windows 10 with WSL and Docker Desktop for local development.

The module should be tested on a disposable clone before deployment to the shared AWS class environment.

## Local Development

### Requirements

- Windows 10 with WSL;
- Docker Desktop with WSL integration enabled;
- Git;
- a web browser.

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

The repository is mounted directly into OpenEMR, so PHP and template edits made in WSL are immediately visible inside the container.

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

## Repository Structure

```text
.
├── dev/
│   └── compose.yaml
├── docs/
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
- `docs/PROJECT_STATE.md` — current decisions, progress, and handoff context.

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
