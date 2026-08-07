# Local Development

## Requirements

- Docker Desktop or Docker Engine with Docker Compose;
- Git;
- a Bash-compatible shell;
- a web browser.

The project has been tested with Windows 10, WSL, and Docker Desktop.

On Windows, enable Docker Desktop's WSL integration before starting the environment.

The workflow is intended to be portable to macOS/Linux, but those host environments have not been formally validated by this project.

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

Local demo login:

```text
admin / pass
```

A browser warning for the local development certificate is expected.

## Local Module Path

The current local development container uses:

```text
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module
```

This is intentionally shorter than the repository/AWS deployment folder name.

Do not copy AWS diagnostic paths blindly into local Docker commands.

## Check the Module Mount

```bash
docker compose -f dev/compose.yaml exec openemr sh -lc '
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module

ls -la "$MODULE"

test -f "$MODULE/info.txt" &&
test -f "$MODULE/openemr.bootstrap.php" &&
test -f "$MODULE/public/education-dashboard.php" &&
test -f "$MODULE/public/manage-education-users.php" &&
test -f "$MODULE/public/activity-explorer.php" &&
echo "Module mounted successfully."
'
```

The repository is bind-mounted into the container, so normal PHP/template edits made on the host should appear immediately without rebuilding or `docker cp`.

## Activate the Module

For a new local database/install:

```text
Modules
-> Manage Modules
-> Unregistered
-> Register
-> Install
-> Enable
```

Then:

```text
Administration
-> Config
-> Maple Grove Education
```

Enable the Education Dashboard menu item and save.

Log out/in if the menu entry does not appear immediately.

The dashboard should be available at:

```text
Modules
-> Education Dashboard
```

## What Install Does

The module's `table.sql` currently creates:

```text
mod_maple_grove_education_users
mod_maple_grove_education_events
```

It also checks for and creates the Maple Grove performance indexes on OpenEMR's `log` table when missing:

```text
idx_maple_grove_log_user_date
idx_maple_grove_log_user_event_date
idx_maple_grove_log_date_user
idx_maple_grove_log_user_patient_date
```

Because this happens during module installation:

- editing `table.sql` does not apply the change immediately;
- copying/pulling code does not execute `table.sql`;
- use a disposable environment when testing install/schema behavior.

Ordinary PHP changes do **not** require reinstalling the module.

## PHP Syntax Checks

The host does not need a separate PHP installation. Use PHP inside the OpenEMR container.

Dashboard:

```bash
docker compose -f dev/compose.yaml exec openemr \
php -l \
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module/public/education-dashboard.php
```

Manage Education Users:

```bash
docker compose -f dev/compose.yaml exec openemr \
php -l \
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module/public/manage-education-users.php
```

Activity Explorer:

```bash
docker compose -f dev/compose.yaml exec openemr \
php -l \
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module/public/activity-explorer.php
```

Shared analytics helper:

```bash
docker compose -f dev/compose.yaml exec openemr \
php -l \
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module/src/EducationAnalytics.php
```

## Analytics Test Checklist

After a meaningful analytics change, test:

### Dashboard

- Today / Last 7 Days / Last 30 Days;
- Custom start/end dates;
- meaningful activity scope;
- raw successful-audit scope;
- cohort/admin view;
- tracked-student personal view;
- recent activity preview;
- loading indicator;
- return navigation from management/explorer pages.

### Manage Education Users

- username A-Z ordering;
- search by username/full name;
- individual **Track as Student** changes;
- individual **Can View Analytics** changes;
- **Select Visible Students**;
- **Clear Visible Students**;
- top Save button;
- bottom Save button;
- saving does not accidentally bulk-grant analytics permission.

### Activity Explorer

- custom dates;
- multiple selected students;
- student search;
- selected students appear at top of picker;
- clear only selected students;
- multiple activity types;
- patient name/ID search;
- 25/50/100 requested rows;
- Older/Newer activity;
- patient chart sessions;
- full names beneath usernames;
- patient names/IDs;
- patient link opens an internal OpenEMR patient Dashboard tab.

## Patient Chart Session Semantics

A Patient Chart Session is derived from successful OpenEMR audit rows such as:

```text
patient-record-select
patient-access
```

for the same student and patient.

Repeated reads less than approximately 30 minutes apart are grouped into one chart-viewing period.

This is:

- an analytics heuristic;
- not a login session;
- not a unique-patient count;
- not a guaranteed representation of continuous human attention.

Keep the one-line frontend explanation when modifying the Activity Explorer.

## Activity Explorer Pagination

The explorer requests a target number of normalized activities (25/50/100), but some filters may return fewer rows because many raw audit records can collapse into one normalized activity.

The implementation intentionally bounds how much audit history can be scanned in one HTTP request.

Do not remove scan limits merely to force exact page filling without first measuring the impact on the AWS audit dataset.

If exact normalized pagination becomes a requirement, prefer a summarized/materialized activity table rather than an unbounded request-time scan.

## Database Compatibility

Local MariaDB 10.11 supports SQL features that the AWS OpenEMR database may not.

A previous Patient Chart Session implementation using:

```text
WITH
LAG()
OVER()
```

worked locally but failed on AWS.

Current code intentionally uses older-compatible SQL and performs some normalization in PHP.

When adding SQL:

1. keep syntax conservative;
2. test locally;
3. test on a disposable AWS clone;
4. do not assume local MariaDB feature support equals the AWS target.

## Performance Guidance

The OpenEMR audit `log` table can contain hundreds of thousands of rows.

Existing Maple Grove indexes:

```text
(user, date)
(user, event, date)
(date, user)
(user, patient_id, date)
```

Before adding another index:

1. reproduce the slow query;
2. inspect existing indexes;
3. use `EXPLAIN`;
4. make sure a code/query simplification would not solve the problem first.

Avoid routinely joining the entire `patient_data` table into large audit queries. Current explorer logic resolves patient display information only where needed.

## Restart OpenEMR

```bash
docker compose -f dev/compose.yaml restart openemr
```

A normal restart should preserve the database/site volumes and the host bind mount.

If Docker Desktop becomes unexpectedly extremely slow while CPU/memory appear normal, restarting Docker Desktop has previously resolved the issue. Do not immediately relocate the repository solely because it is on a mounted Windows drive unless the problem is reproducible.

## Stop the Environment

```bash
docker compose -f dev/compose.yaml down
```

This stops/removes containers but preserves named volumes.

## Delete the Entire Local Test Environment

Only use this when a completely clean OpenEMR installation is required:

```bash
docker compose -f dev/compose.yaml down -v
```

The `-v` option permanently deletes the local OpenEMR database and site volumes.

A clean environment is useful for verifying that `table.sql` can create the module schema/indexes from scratch.

## Git Workflow

Current analytics branch:

```text
feat/activity-analytics-mvp
```

Typical workflow:

```bash
git status
git add <changed-files>
git commit -m "..."
git push
```

Avoid `sudo git`.

If repository ownership becomes broken because of earlier `sudo` operations, fix ownership rather than continuing to run Git as root.

## Important Files

- `dev/compose.yaml` — local OpenEMR/MariaDB development environment
- `info.txt` — module metadata
- `openemr.bootstrap.php` — module startup entry point
- `src/Bootstrap.php` — event hooks/menu/module behavior
- `src/GlobalConfig.php` — Maple Grove configuration options
- `src/EducationAnalytics.php` — shared access/audit/normalization helpers
- `public/education-dashboard.php` — summary/personal/cohort analytics
- `public/manage-education-users.php` — education-user management
- `public/activity-explorer.php` — detailed audit explorer
- `table.sql` — module tables + install-time audit index setup
- `docs/PROJECT_STATE.md` — current status/handoff context
- `docs/AWS_TEST_DEPLOYMENT.md` — disposable AWS deployment workflow

## Platform Notes

The commands in this guide use repository-relative paths and Docker Compose.

Host-specific differences may include:

- Docker Desktop file-sharing permissions on macOS;
- Docker Desktop WSL integration on Windows;
- whether `docker` requires `sudo` on Linux;
- browser handling of the local development certificate.

The OpenEMR application target remains OpenEMR 7.0.2.
