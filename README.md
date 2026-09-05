# Victual

<img alt="Victual logo" height="50" src="public/img/logo.svg" />

Victual is a self-hosted groceries and household management application, maintained by
Steven Peterson. It is a hard fork of [grocy](https://github.com/grocy/grocy), created by
Bernd Bestel, and retains grocy's stock, shopping list, recipe, meal planning, and chore
features.

## Project goals

This fork adapts grocy to a household running an immutable, scale-to-zero application on
k3s. Its goals are:

- Keep household data and uploaded files in PostgreSQL, independent of application
  containers and their packaging, with a single database backup.
- Run without a persistent application volume, with migrations performed during deployment.
- Publish household state to Home Assistant without polling the application.
- Support pantry locations and product groupings that reflect how the household stores
  and buys things.
- Provide APIs for household assistants and first-party clients, with explicit permissions
  and reliable stock history.

The motivation includes a difficult migration from an abandoned Home Assistant add-on.
Keeping data in a network database makes access and backups independent of whoever
packages the application. Victual will continue to diverge from upstream to support these
goals.

## Current state

As of 2026-09-05, the Nix-built application has been deployed and serves requests.
Infrastructure and feature work remain in progress; there is no regular release schedule.

| Area | State |
|---|---|
| PostgreSQL and database file storage | Implemented, including import tools and database comparison tests. |
| Stateless runtime | Implemented: explicit migration command, database-backed state, and read-only application filesystem. |
| MQTT and InfluxDB | State publication and event delivery implemented. Some Home Assistant checks remain outstanding. |
| Production containers | Three Nix-built images and a working pod manifest. Deployment automation, credential separation, and some signal checks remain. |
| Hardening | API error handling, authentication fixes, write transactions, and frontend sink fixes implemented. Contract snapshots and cleanup remain. |
| Household features | Category minimums, shopping-list improvements, nested locations, barcode sources, and medication tracking are planned. |
| Assistants and clients | MCP and first-party client work are planned. |

PostgreSQL is the chosen runtime under [ADR-0008](docs/adr/0008-postgresql-only-runtime-engine.md).
SQLite still works in the current tree; its runtime retirement has not landed. Until then,
changes must retain the existing dual-engine checks.

Opaque labels and human-confirmed observation proposals have accepted ADRs but are not
implemented. The [plan index](docs/plans/README.md) records delivery status and remaining
dependencies; the [ADR index](docs/adr/README.md) records decisions and proposals.

## Getting started

- [Installation and usage](docs/usage.md): checkout setup, configuration, imports, MQTT,
  and user-facing features.
- [Deployment](deploy/README.md): pod bootstrap and configuration.
- [Nix builds](nix/README.md): production image builds and local loading.
- [PostgreSQL](db/pgsql/README.md): database setup, import, and engine-specific details.
- [Local development](.agents/skills/run-app/SKILL.md): boot a demo instance.

Production images are built from `flake.nix`. The root `Dockerfile` builds the development
and CI image. Upstream grocy images do not contain Victual's changes.

## Working in this repository

Read these in order before making changes:

1. [AGENTS.md](AGENTS.md): repository rules and development entry points.
2. [Constitution](docs/constitution.md): standing principles.
3. [ADR index](docs/adr/README.md): decisions in force and proposed changes.
4. [Plan index](docs/plans/README.md): work status and dependencies.

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for contributions, including AI-assisted
work, and [documentation conventions](docs/documentation.md) for writing and editing.

## Support and attribution

Report Victual issues in this repository. Report security issues according to
[SECURITY.md](.github/SECURITY.md); authenticated permission bugs are in scope.
For upstream grocy, use [grocy's website](https://grocy.info) and support resources.
There is no public Victual demo; [grocy's demo](https://demo.grocy.info) shows the shared
feature set.

Most of the original application is Bernd Bestel's work. Code originating from grocy is
MIT-licensed; this fork's changes and additions use BSD 3-Clause. See [LICENSE.md](LICENSE.md)
for both licenses.

## Screenshots

Victual running its demo dataset:

![Stock overview](.github/publication_assets/stock.png)
![Shopping list](.github/publication_assets/shoppinglist.png)
![Meal plan](.github/publication_assets/mealplan.png)
![Chores overview](.github/publication_assets/chores.png)
