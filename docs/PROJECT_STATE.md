# Maple Grove OpenEMR Module — Project State

Last updated: 2026-08-01

## Goal

Build an OpenEMR 7.0.2 custom module that turns the Maple Grove clinic
environment into a functional educational platform.

The planned module may eventually provide:

- an Education Dashboard inside OpenEMR;
- student and team task assignments;
- task-completion tracking;
- education-specific activity events;
- instructor analytics;
- links to related Maple Grove tools and documentation.

## Current phase

Initial module proof of concept.

The immediate milestone is:

1. run OpenEMR 7.0.2 locally through Docker;
2. mount this repository into OpenEMR's custom-module directory;
3. register, install, and enable the module;
4. add an Education Dashboard menu item;
5. open a styled placeholder page inside an OpenEMR tab.

No analytics backend, student tracking, or production deployment is implemented yet.

## Repository

Repository name:

`maple-grove-openemr-module`

Current development branch:

`feat/education-dashboard-placeholder`

## Target OpenEMR environment

Primary compatibility target:

- OpenEMR 7.0.2;
- Docker image: `openemr/openemr:7.0.2`;
- matches the current AWS Marketplace class environment.

Local development ports:

- HTTP: `http://localhost:8302`
- HTTPS: `https://localhost:9302`

Local demo login:

- username: `admin`
- password: `pass`

Never use real patient information in this development environment.

## Local Docker architecture

The development Compose stack contains:

- MariaDB;
- OpenEMR 7.0.2;
- persistent database, site, and log volumes;
- a bind mount from this Git repository into OpenEMR's custom-module folder.

The important mount is:

```yaml
- ..:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-module
```

This means edits made in the WSL repository are immediately visible inside
the OpenEMR container.

## Module installation workflow

Inside OpenEMR:

1. log in as an administrator;
2. open `Modules -> Manage Modules`;
3. open the `Unregistered` tab;
4. register the module;
5. install the module;
6. enable the module;
7. log out and back in if its menu item does not immediately appear.

## Current decisions

- Build a separate OpenEMR module rather than forking all of OpenEMR.
- Keep the existing Synthea/importer repository separate.
- Develop locally against OpenEMR 7.0.2.
- Use a bind mount during development.
- Later package the module into a derived Docker image for persistent AWS deployment.
- Start with a native OpenEMR placeholder page before adding an external Python dashboard.
- Store educational events in module-owned tables rather than modifying core OpenEMR tables unnecessarily.

## Safety and privacy

- Use synthetic patient data only.
- Do not commit credentials, database dumps, certificates, or environment secrets.
- Do not manually modify OpenEMR core files for the final solution.
- Test deployment changes on a disposable AWS clone before the shared class server.

## Immediate next steps

1. Confirm the repository is visible inside the OpenEMR container.
2. Register, install, and enable the skeleton module.
3. Inspect and rename the skeleton metadata.
4. Replace the skeleton menu entry with `Education Dashboard`.
5. Replace the sample page with a Maple Grove placeholder dashboard.
6. Commit the first visible module milestone.

## Suggested first feature commit

`feat: add Education Dashboard placeholder`
