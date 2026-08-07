# Maple Grove OpenEMR Education Module

A custom OpenEMR module for the Maple Grove educational clinic environment.

The module adds education-focused activity analytics directly inside OpenEMR. It uses OpenEMR's existing audit history together with small module-owned tables so instructors can review student activity while tracked students can review their own activity.

## Quick Install on an Existing OpenEMR Docker Host

On the OpenEMR server:

```bash
cd ~

git clone https://github.com/CabbageCanFly/maple-grove-openemr-education-module.git

cd maple-grove-openemr-education-module

sudo docker cp \
  . \
  lightsail_openemr_1:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

If the OpenEMR container is not named `lightsail_openemr_1`, check it first with:

```bash
sudo docker ps --format '{{.Names}}'
```

Then in OpenEMR:

1. **Modules -> Manage Modules -> Unregistered**
2. **Register -> Install -> Enable**
3. **Administration -> Config -> Maple Grove Education**
4. Enable **Education Dashboard menu item** and click **Save**
5. Log out/in if the menu item does not appear immediately

Open the module from:

```text
Modules -> Education Dashboard
```

For detailed AWS/test deployment and verification steps, see [`docs/AWS_TEST_DEPLOYMENT.md`](docs/AWS_TEST_DEPLOYMENT.md).

## Current Status

The activity-analytics MVP is implemented and has been tested locally and on disposable AWS clones of the class OpenEMR environment.

Implemented:

- local OpenEMR 7.0.2 development through Docker Compose;
- live module bind mount for local development;
- module registration, installation, enablement, and configuration through OpenEMR;
- an **Education Dashboard** under the OpenEMR Modules menu;
- instructor/admin cohort analytics and tracked-student personal analytics;
- module-managed education-user tracking;
- explicit **Track as Student** and **Can View Analytics** settings;
- a **Manage Education Users** page with search and bulk student-selection controls;
- a separate **Activity Explorer** with date, student, activity-type, patient, and page-size filters;
- historical analytics from OpenEMR's existing `log` audit table;
- meaningful-activity normalization, including approximate patient chart sessions;
- patient-name display and native OpenEMR patient-tab navigation;
- custom date ranges;
- multi-select student and activity-type filtering;
- full student names alongside usernames;
- loading indicators and bounded/cursor-style activity browsing;
- module-owned education-user and education-event tables;
- automatic install-time creation of analytics performance indexes on OpenEMR's audit `log` table;
- successful proof deployment to disposable AWS OpenEMR clones.

Still outside the current MVP:

- student/team task assignment workflows;
- task completion models;
- richer education-specific event types beyond the current module events;
- a dedicated instructor role/ACL model stricter than the current admin/viewer rules;
- guaranteed exact 25/50/100 normalized rows for every possible audit filter;
- permanent AWS packaging that survives OpenEMR container recreation without another deployment step.

## Main Pages

### Education Dashboard

Available from:

```text
Modules -> Education Dashboard
```

The dashboard summarizes the selected date range and audit scope.

Current cohort metrics include:

- tracked students;
- active students;
- meaningful activities or successful audit rows;
- successful logins;
- patient chart sessions;
- clinical record changes;
- scheduling changes;
- module events;
- recent student activity.

Tracked students without cohort-view permission receive a personal view instead of the cohort view.

### Manage Education Users

Administrators/analytics managers can decide which OpenEMR users participate in education analytics.

Per user:

- **Track as Student** — include the user in tracked education analytics;
- **Can View Analytics** — allow the user to view cohort analytics.

The page also includes:

- username A-Z ordering;
- name/username search;
- bulk **Select Visible Students** and **Clear Visible Students** actions;
- Save controls at both the top and bottom of the long user table.

Bulk student selection intentionally does not bulk-enable **Can View Analytics**.

### Activity Explorer

The Activity Explorer is the detailed browsing page for audit activity.

Filters include:

- preset or custom start/end dates;
- one or more students;
- one or more activity categories;
- patient name or ID;
- meaningful vs raw successful-audit scope;
- 25, 50, or 100 requested rows.

The student picker supports search-as-you-type, selected-first ordering, and clearing only the selected students.

## Data Sources

The analytics intentionally combine two sources.

### OpenEMR audit history

OpenEMR's existing `log` table provides historical activity that existed before this module was installed.

The module does not rewrite or duplicate that audit history.

Examples used by the analytics include:

- login/logout;
- patient chart access;
- clinical record insert/update/delete activity;
- scheduling changes;
- e-sign activity;
- print activity.

### Module-owned data

`table.sql` creates:

- `mod_maple_grove_education_users`
- `mod_maple_grove_education_events`

The first stores education tracking/viewer settings. The second stores explicit module events such as dashboard access and provides a place for future education-specific event types.

## Patient Chart Sessions

A **Patient Chart Session** is a heuristic activity period, not an OpenEMR login session and not a unique-patient count.

It groups successful OpenEMR `patient-record-select` / `patient-access` audit events for the same student and patient. Reads that occur less than approximately 30 minutes apart are treated as the same chart-viewing period.

This reduces hundreds of repetitive chart-read audit rows into a more understandable activity signal.

Because it is derived from audit rows, it should be interpreted as an approximation of chart-viewing activity rather than a clinical workflow fact.

## Permissions

The MVP uses a combination of OpenEMR ACLs and module-managed education settings.

- tracked students can view their own analytics;
- users with **Can View Analytics** can view cohort analytics;
- existing OpenEMR admin-style access is also accepted for management/viewing in the current MVP;
- patient names/links are only shown when the viewer has the relevant OpenEMR patient-demographics access.

A future production-quality version should replace the broad admin fallback with a more explicit instructor/analytics ACL design.

## Performance and Database Indexes

Analytics queries can touch a large OpenEMR audit table, so `table.sql` includes install-time checks that create the following indexes when they are missing:

```text
idx_maple_grove_log_user_date
idx_maple_grove_log_user_event_date
idx_maple_grove_log_date_user
idx_maple_grove_log_user_patient_date
```

These indexes are on OpenEMR's existing `log` table. They do not change the audit records themselves.

The installer checks `information_schema.statistics` first so an existing index with the same Maple Grove name is not created twice.

This matters on larger AWS datasets; without suitable indexes, date/user/activity queries can become significantly slower.

## Compatibility Target

Primary target:

- OpenEMR 7.0.2;
- Docker image `openemr/openemr:7.0.2`;
- Docker Compose;
- the AWS Marketplace-based OpenEMR class environment.

Local development currently uses MariaDB 10.11.

Important compatibility lesson: the AWS database runtime may support older SQL syntax than the local MariaDB container. Analytics code should avoid assuming that CTEs or SQL window functions such as `WITH`, `LAG()`, or `OVER()` are available. Patient chart-session normalization therefore uses compatibility-safe SQL plus PHP processing.

Development has been tested with Windows 10, WSL, and Docker Desktop. The same Docker workflow is intended to be portable to macOS and Linux, although those hosts have not been formally validated by this project.

## Local Development

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for the full local workflow.

Start from the repository root:

```bash
docker compose -f dev/compose.yaml up -d
```

Open:

```text
https://localhost:9302
```

Local demo login:

```text
admin / pass
```

## Module Installation

Inside OpenEMR:

1. Log in as an administrator.
2. Open **Modules -> Manage Modules**.
3. Open **Unregistered**.
4. Register the module.
5. Install the module.
6. Enable the module.
7. Open **Administration -> Config -> Maple Grove Education**.
8. Enable the Education Dashboard menu item.
9. Log out/in if the menu entry does not appear immediately.

The **Install** step runs `table.sql`, which creates the module tables and ensures the Maple Grove audit-performance indexes exist.

## AWS Test Deployment

Disposable AWS deployment currently uses:

1. SSH to the EC2 host;
2. clone/pull this repository;
3. `docker cp` the module into the running OpenEMR container;
4. register/install/enable/configure the module in OpenEMR.

For ordinary PHP/code updates after installation, use `git pull` followed by `docker cp`; a module reinstall is not normally required.

See [`docs/AWS_TEST_DEPLOYMENT.md`](docs/AWS_TEST_DEPLOYMENT.md).

The current `docker cp` method is suitable for proof deployment, but copied files live in the running container's writable layer and can be lost if the OpenEMR container is deleted and recreated. A maintained image or persistent module mount remains the preferred long-term deployment design.

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
│   ├── activity-explorer.php
│   ├── education-dashboard.php
│   └── manage-education-users.php
├── src/
│   ├── Bootstrap.php
│   ├── EducationAnalytics.php
│   └── GlobalConfig.php
├── composer.json
├── info.txt
├── openemr.bootstrap.php
├── table.sql
└── README.md
```

Important files:

- `dev/compose.yaml` — local OpenEMR/MariaDB development stack;
- `public/education-dashboard.php` — summary and personal/cohort analytics;
- `public/activity-explorer.php` — detailed audit activity filtering/browsing;
- `public/manage-education-users.php` — tracking/viewer administration;
- `src/EducationAnalytics.php` — shared analytics, access, and audit-normalization helpers;
- `src/Bootstrap.php` — module startup, event hooks, settings, and menu registration;
- `src/GlobalConfig.php` — module configuration options;
- `table.sql` — module schema and install-time audit index setup;
- `docs/PROJECT_STATE.md` — detailed implementation state, decisions, limitations, and handoff notes;
- `docs/DEVELOPMENT.md` — local development workflow;
- `docs/AWS_TEST_DEPLOYMENT.md` — disposable AWS deployment and verification workflow.

## Future Work

Likely next extensions:

1. merge/stabilize the analytics feature branch;
2. complete a fresh-clone installation test on the final AWS-compatible target;
3. tighten instructor/analytics ACL rules;
4. design student/team tasks only if they remain useful to the course;
5. improve normalized-activity pagination if exact page filling becomes a requirement;
6. consider purpose-built summarized activity tables if analytics volume grows substantially;
7. package the module through a persistent mount or maintained OpenEMR image for repeatable deployment.

## Development Principles

- Keep OpenEMR core application files unchanged.
- Implement custom behavior through the module system.
- Use synthetic patient data only.
- Do not commit passwords, certificates, database dumps, tokens, or server secrets.
- Test database/deployment changes on disposable AWS clones before the shared class server.
- Treat OpenEMR's core audit `log` as a read-oriented analytics source; do not rewrite its records.
- Keep module-owned education data in module-owned tables.
- Prefer SQL compatible with the oldest supported target database.
- Keep this module separate from the Maple Grove Synthea/OpenEMR importer project.
- Update `docs/PROJECT_STATE.md` after major architectural or deployment changes.

## Related Project

The separate Maple Grove Synthea/OpenEMR project generates GTA-focused synthetic patient data and imports supported clinical history into OpenEMR.

This repository focuses on OpenEMR interface customization, education-user management, activity analytics, and future education workflows.

## License and Attribution

This project was initialized from the [OpenEMR Custom Module Skeleton](https://github.com/adunsulag/oe-module-custom-skeleton) and retains its GNU General Public License 3 licensing requirements and applicable attribution notices.

[OpenEMR](https://www.open-emr.org/) is a separate open-source project and is not maintained by this repository.
