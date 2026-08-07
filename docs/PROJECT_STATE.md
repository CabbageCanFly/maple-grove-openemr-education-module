# Maple Grove OpenEMR Module — Project State

Last updated: 2026-08-07

## Goal

Build an OpenEMR 7.0.2 custom module that turns the Maple Grove educational clinic environment into a useful student/instructor analytics platform without forking OpenEMR or rewriting core application behavior.

The broader project may eventually include:

- education activity analytics;
- student/team task assignments;
- task-completion tracking;
- education-specific events;
- instructor-facing summaries;
- links to related Maple Grove tools and documentation.

## Current Phase

The **activity analytics MVP is implemented**.

The project has moved well beyond the original dashboard placeholder. Current work is focused on final validation, documentation, and reproducible deployment before the module is shared with the class/shared OpenEMR environment.

## Verified Functionality

### Core module

- OpenEMR 7.0.2 runs locally through Docker Compose.
- The repository is bind-mounted into OpenEMR's custom-module directory for local development.
- The module can be registered, installed, enabled, and configured through OpenEMR.
- An **Education Dashboard** menu item appears under the Modules menu.
- The generic custom-module skeleton configuration gate was removed.
- Maple Grove-specific labels/settings are used throughout.
- The module has been repeatedly copied to and tested on disposable AWS OpenEMR clones.

### Education-user management

The module has a `mod_maple_grove_education_users` table and a **Manage Education Users** page.

Per OpenEMR user:

- `track_activity` / **Track as Student**
- `can_view_analytics` / **Can View Analytics**

Current management-page UX:

- users sorted username A-Z;
- search by username/full name;
- bulk **Select Visible Students**;
- bulk **Clear Visible Students**;
- bulk selection changes student tracking only;
- analytics permission is intentionally not bulk-granted;
- Save controls are available at both the top and bottom of the long table.

### Role/view behavior

Current MVP behavior:

- a tracked student can receive a personal analytics view;
- a user with **Can View Analytics** can receive cohort analytics;
- existing OpenEMR admin-style access is also accepted for analytics management/viewing;
- patient names/links require the relevant OpenEMR patient-demographics ACL.

The broad admin fallback is convenient for the class MVP but is not intended as the final least-privilege security design.

### Historical OpenEMR audit analytics

The analytics use OpenEMR's existing `log` table, so useful activity can be shown for periods that predate module installation.

The module does not copy or rewrite OpenEMR audit records.

Meaningful activity currently focuses on:

- successful login/logout;
- patient chart access;
- clinical record insert/update/delete/replace activity;
- scheduling insert/update/delete activity;
- e-sign;
- print.

High-volume administrative/security polling and other noisy rows are excluded from the meaningful view.

A raw/all-successful-audit scope remains available for diagnostics/exploration.

### Patient Chart Sessions

Repeated patient-chart reads are normalized into approximate **Patient Chart Sessions**.

Current interpretation:

- underlying events are successful `patient-record-select` / `patient-access` rows;
- rows are grouped by student + patient;
- reads less than approximately 30 minutes apart are treated as one chart-viewing period;
- the metric is not a login session;
- the metric is not a count of unique patients;
- the metric is a heuristic intended to reduce repetitive audit noise.

The frontend includes a short explanation because this derived activity type is not a native OpenEMR concept.

### Education Dashboard

Cohort dashboard metrics currently include:

- tracked students;
- active students;
- meaningful activities / audit rows depending on selected scope;
- successful logins;
- patient chart sessions;
- clinical changes;
- scheduling changes;
- education module events;
- recent activity.

Date support includes:

- Today;
- Last 7 Days;
- Last 30 Days;
- All Available History;
- Custom start/end dates.

Performance-related dashboard work completed:

- duplicate personal queries are skipped when an admin/cohort view is being rendered;
- cohort results are cached in the OpenEMR session for roughly 60 seconds;
- recent activity preview is bounded;
- recent activity sampling avoids allowing one highly active student to crowd out every other student;
- loading feedback is shown during filter/navigation transitions.

### Activity Explorer

The separate Activity Explorer supports:

- meaningful or raw successful audit scope;
- preset date ranges;
- exact custom start/end dates;
- multi-select students;
- student search by username/full name;
- selected students floating to the top of the picker;
- clear-students control without resetting every other filter;
- multi-select activity categories;
- patient name/ID filtering;
- 25/50/100 requested rows;
- cursor/older-newer browsing;
- full student names beneath usernames;
- patient names plus IDs when allowed;
- links that open the patient through OpenEMR's internal tab/navigation model rather than a separate browser tab.

The internal patient link behavior currently uses OpenEMR navigation rather than an external/new-tab link and should be preserved during future refactors.

### Module events

`mod_maple_grove_education_events` stores explicit module events.

Current explicit event use includes dashboard access. The table is intentionally generic enough to support future task/event types with:

- OpenEMR user ID;
- username;
- event type;
- optional task ID;
- optional patient ID;
- optional encounter ID;
- metadata;
- timestamp.

## Repository

Repository:

`https://github.com/CabbageCanFly/maple-grove-openemr-education-module.git`

Current analytics development branch:

`feat/activity-analytics-mvp`

`main` was the stable proof-of-concept baseline before the analytics work. Merge strategy should be decided after final AWS validation.

## Target OpenEMR Environment

Primary compatibility target:

- OpenEMR 7.0.2;
- Docker image `openemr/openemr:7.0.2`;
- AWS Marketplace-based class environment.

Local development currently uses MariaDB 10.11.

Local ports:

- HTTP: `http://localhost:8302`
- HTTPS: `https://localhost:9302`

Local demo login:

- username: `admin`
- password: `pass`

Synthetic/demo patient data only.

## Supported Development Environment

Currently tested:

- Windows 10;
- WSL;
- Docker Desktop with WSL integration.

Expected but not formally validated by this project:

- macOS + Docker Desktop;
- Linux + Docker Engine/Compose;
- other Bash-compatible environments.

## Local Docker Architecture

The Compose stack contains:

- MariaDB;
- OpenEMR 7.0.2;
- persistent database/site/log volumes;
- a bind mount from the repository into the OpenEMR custom-module area.

Important path detail:

- local development currently uses the shorter in-container module folder  
  `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module`;
- AWS proof deployments use  
  `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module`.

Do not assume the local and AWS in-container folder names are identical when writing diagnostic/lint commands.

## Database Schema

`table.sql` creates the module-owned tables:

### `mod_maple_grove_education_users`

Purpose:

- maps OpenEMR users to education tracking/viewing settings.

Important fields:

- OpenEMR user ID;
- username;
- education role;
- track activity;
- can view analytics;
- created/updated timestamps.

### `mod_maple_grove_education_events`

Purpose:

- stores explicit events generated by this module.

Important fields:

- OpenEMR user ID;
- username;
- event type;
- task/patient/encounter references;
- metadata;
- timestamp.

## OpenEMR Audit Performance Indexes

OpenEMR's original test-clone `log` table had very limited indexing for this analytics workload.

The module now ensures these indexes exist during installation:

```text
idx_maple_grove_log_user_date          (user, date)
idx_maple_grove_log_user_event_date    (user, event, date)
idx_maple_grove_log_date_user          (date, user)
idx_maple_grove_log_user_patient_date  (user, patient_id, date)
```

Implementation notes:

- the indexes are on OpenEMR's core `log` table;
- audit records themselves are not modified;
- `table.sql` checks `information_schema.statistics`;
- each Maple Grove index is created only if its index name is missing;
- this makes install/reinstall safe when the same Maple Grove indexes already exist;
- future developers should not casually remove or rename these indexes without retesting the larger AWS audit workload.

Fresh-clone installation should always be used to confirm that the install-time index setup still works.

## Performance History and Lessons

The AWS test environment contained hundreds of thousands of audit rows, which exposed performance problems that were not obvious with small/local data.

Important improvements:

- added targeted audit indexes;
- reduced duplicate queries;
- bounded recent feeds;
- filtered by tracked usernames before normalization;
- avoided unnecessary joins to patient data;
- resolved patient names only for rows actually displayed;
- added short-lived dashboard caching;
- separated summary browsing from the detailed explorer.

On the test AWS clone, these changes reduced dashboard waits from the earlier minute-scale behavior to a much more usable range.

Future optimization should be driven by real target data and `EXPLAIN`, not by adding broad indexes blindly.

## Important Database Compatibility Lesson

A newer local MariaDB accepted a Patient Chart Session query using:

- `WITH` common table expressions;
- `LAG()`;
- window `OVER()` expressions.

The AWS database rejected that syntax.

The code was changed back to a compatibility-safe implementation that:

- applies useful filtering in SQL;
- scans bounded chunks;
- performs session normalization in PHP where necessary.

Therefore:

**Do not assume local MariaDB 10.11 SQL features are available on the AWS OpenEMR database.**

Any future SQL refactor should be tested on a disposable AWS clone before deployment.

## Activity Explorer Pagination Limitation

Requested rows (`25`, `50`, `100`) are a target, not a strict guarantee for every normalized audit query.

Reason:

- many raw audit rows may collapse into one displayed meaningful activity;
- some activity types require PHP-side grouping/deduplication;
- scans are intentionally bounded so one browser request cannot read an unbounded amount of audit history.

A page may therefore contain fewer rows even when older matching raw rows still exist.

The UI can warn when a bounded scan limit is reached.

Possible future solutions if exact page filling becomes important:

1. keep a purpose-built normalized activity table;
2. periodically materialize/chart-session summaries;
3. use a background aggregation process;
4. redesign pagination around normalized activity identifiers.

For the current class MVP, bounded browsing is accepted as a tradeoff between completeness and server responsiveness.

## Module Installation Workflow

Inside OpenEMR:

1. `Modules -> Manage Modules`
2. `Unregistered`
3. Register
4. Install
5. Enable
6. `Administration -> Config -> Maple Grove Education`
7. enable the Education Dashboard menu item
8. save
9. log out/in if needed

The **Install** step executes `table.sql`, which creates module tables and ensures the audit-performance indexes exist.

Ordinary PHP code updates do not require reinstalling the module.

## AWS Proof Deployment

Current disposable deployment:

1. SSH to the EC2 host.
2. Clone/pull the repository.
3. Copy the repository into the running OpenEMR container with `docker cp`.
4. Register/install/enable/configure for a fresh installation.
5. For later code-only updates, repeat `git pull` + `docker cp`.

Current AWS target path:

```text
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

The example container used during testing is:

```text
lightsail_openemr_1
```

Always confirm the actual running container name first.

This remains a proof deployment. Container recreation can remove files copied into the container writable layer.

## Current Architectural Decisions

- Build a separate OpenEMR module rather than forking OpenEMR.
- Keep Synthea/importer code in its separate repository.
- Keep OpenEMR core files unchanged.
- Read historical behavior from OpenEMR's audit log rather than trying to duplicate every user action.
- Store explicit education settings/events in module-owned tables.
- Use OpenEMR ACL checks for patient data and current MVP access decisions.
- Keep the dashboard under the Modules menu.
- Use native OpenEMR patient navigation for patient links.
- Use `docker cp` for disposable AWS validation only.
- Prefer an eventual maintained image or persistent module mount for long-term deployment.
- Preserve compatibility with the AWS database even when local development can use newer SQL.

## Safety and Privacy

- Synthetic/demo patient data only.
- Do not commit credentials, database dumps, certificates, tokens, keys, or environment secrets.
- Do not expose patient names to users who lack the appropriate OpenEMR ACL.
- Do not rewrite/delete OpenEMR audit history for analytics convenience.
- Test database/schema/deployment changes on disposable clones before the shared server.
- Analytics are educational signals, not authoritative clinical productivity/performance measures.

## Remaining / Future Work

### Before class/shared deployment

1. Finish a fresh second-clone install test.
2. Confirm install-time `idx_maple_grove_*` creation on a database that does not already have them.
3. Test Dashboard, Manage Education Users, and Activity Explorer after a fresh install.
4. Verify student vs analytics-viewer behavior.
5. Verify native patient links.
6. Decide whether `feat/activity-analytics-mvp` is ready to merge into `main`.

### Possible later improvements

- dedicated instructor/analytics ACL;
- task assignment/completion workflows;
- richer explicit module events;
- normalized/materialized activity table for exact pagination;
- additional performance profiling with real target usage;
- permanent module packaging/deployment;
- documentation/screenshots for end users.

## Handoff Notes

When debugging future issues, check these first:

1. **Is the code actually inside the running AWS container?**  
   `git pull` on the EC2 host alone does not update the already-running OpenEMR container.

2. **Did a schema/index change require module install/reinstall?**  
   PHP edits do not; `table.sql` changes do not execute merely because files were copied.

3. **Which module path is being used?**  
   Local and AWS folder names currently differ.

4. **Is the AWS database older than local MariaDB?**  
   Avoid unverified CTE/window-function syntax.

5. **Is the audit query hitting enough indexes?**  
   Check `SHOW INDEX FROM log` and use `EXPLAIN` before adding more indexes.

6. **Is one active student crowding a recent preview?**  
   Preserve the bounded/per-student recent-activity logic.

7. **Are Patient Chart Sessions being interpreted correctly?**  
   They are grouped patient-chart audit reads, not literal login sessions.

8. **Are patient links opening inside OpenEMR?**  
   Preserve the native internal-tab behavior instead of reverting to browser-tab links.
