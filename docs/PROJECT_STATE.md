# Maple Grove OpenEMR Module — Project State

Last updated: 2026-08-01

## Goal

Build an OpenEMR 7.0.2 custom module that turns the Maple Grove clinic environment into a functional educational platform.

The planned module may eventually provide:

- an Education Dashboard inside OpenEMR;
- student and team task assignments;
- task-completion tracking;
- education-specific activity events;
- instructor analytics;
- links to related Maple Grove tools and documentation.

## Current Phase

The initial module proof of concept is complete.

Verified functionality:

- OpenEMR 7.0.2 runs locally through Docker Compose.
- This repository is bind-mounted into OpenEMR's custom-module directory.
- The module can be registered, installed, enabled, and configured.
- An `Education Dashboard` menu item appears under the Modules menu.
- The dashboard opens as a styled page inside an OpenEMR tab.
- The generic skeleton configuration gate has been removed.
- The visible module and configuration labels have been changed to Maple Grove terminology.
- The module was successfully copied to and tested on a disposable AWS OpenEMR clone.

No activity analytics, educational task model, or permanent AWS deployment mechanism has been implemented yet.

## Repository

Repository name:

`maple-grove-openemr-education-module`

Stable branch after the current proof-of-concept merge:

`main`

Planned next development branch:

`feat/activity-analytics-mvp`

## Target OpenEMR Environment

Primary compatibility target:

- OpenEMR 7.0.2;
- Docker image `openemr/openemr:7.0.2`;
- MariaDB 10.11;
- the current AWS Marketplace-based class environment.

Local development ports:

- HTTP: `http://localhost:8302`
- HTTPS: `https://localhost:9302`

Local demo login:

- username: `admin`
- password: `pass`

Never use real patient information in this development environment.

## Supported Development Environment

The project uses Docker Compose and Unix-style shell commands.

Currently tested:

- Windows 10;
- WSL;
- Docker Desktop with WSL integration.

Expected to be compatible:

- macOS with Docker Desktop;
- Linux with Docker Engine and Docker Compose;
- other Bash-compatible Unix-like environments.

macOS and native Linux have not yet been formally tested by this project.

## Local Docker Architecture

The development Compose stack contains:

- MariaDB;
- OpenEMR 7.0.2;
- persistent database, site, and log volumes;
- a bind mount from this Git repository into OpenEMR's custom-module folder.

The important mount is:

```yaml
- ..:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

This means edits made in the host repository are immediately visible inside the OpenEMR container.

## Module Installation Workflow

Inside OpenEMR:

1. Log in as an administrator.
2. Open `Modules -> Manage Modules`.
3. Open the `Unregistered` tab.
4. Register the module.
5. Install the module.
6. Enable the module.
7. Open `Administration -> Config -> Maple Grove Education`.
8. Enable the Education Dashboard menu item.
9. Log out and back in if the menu entry does not immediately appear.

## AWS Proof Deployment

The module was successfully tested on a disposable clone of the AWS OpenEMR environment.

Current temporary process:

1. SSH into the EC2 host.
2. Clone this repository.
3. Use `docker cp` to copy the module into the running OpenEMR container.
4. Register, install, enable, and configure the module in the OpenEMR website.

This proves that the module works in the target environment, but it is not a permanent deployment design. The copied module can be lost if the OpenEMR container is deleted and recreated.

See `docs/AWS_TEST_DEPLOYMENT.md` for the exact procedure.

## Current Decisions

- Build a separate OpenEMR module rather than forking all of OpenEMR.
- Keep the existing Synthea/importer repository separate.
- Develop locally against the same OpenEMR 7.0.2 version used by the class environment.
- Use a bind mount during local development.
- Use `docker cp` only for disposable AWS testing.
- Later package the module into a derived Docker image or persistent mount for production deployment.
- Start with a native OpenEMR page before considering a separate Python dashboard service.
- Store educational events in module-owned tables rather than modifying core OpenEMR tables unnecessarily.
- Keep the dashboard under the Modules menu during early development.
- Consider a future top-level `Education` menu when multiple education pages exist.
- Initially allow all logged-in class users to see the education section.
- Add role-based restrictions later for instructor analytics and assignment management.

## Proposed Activity Analytics MVP

The first analytics version should track explicit events created by this module rather than attempting to infer every OpenEMR action.

Candidate events:

- education dashboard opened;
- task viewed;
- task started;
- task completed.

Candidate dashboard metrics:

- active students today;
- total education events today;
- tasks started;
- tasks completed;
- recent activity;
- event counts by student and event type.

Proposed module-owned event fields:

- user ID;
- username;
- event type;
- task ID, when applicable;
- patient or encounter reference, when applicable;
- timestamp;
- optional metadata.

## Safety and Privacy

- Use synthetic patient data only.
- Do not commit credentials, database dumps, certificates, tokens, or environment secrets.
- Do not manually modify OpenEMR core files for the final solution.
- Test deployment changes on a disposable AWS clone before the shared class server.
- Avoid exposing student analytics to users who do not need instructor-level access once role-based views are introduced.

## Immediate Next Steps

1. Merge `feat/education-dashboard-placeholder` into `main`.
2. Create `feat/activity-analytics-mvp`.
3. Design a small module-owned education event table.
4. Record a `dashboard_opened` event for the logged-in OpenEMR user.
5. Display recent events and simple counts on the dashboard.
6. Add a first instructor-oriented summary while keeping the prototype understandable and small.
7. Design a persistent AWS deployment method after the analytics MVP is proven.
