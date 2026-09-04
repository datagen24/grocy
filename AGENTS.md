# Working in this repository

Victual is a hard fork of [grocy](https://github.com/grocy/grocy) serving one household,
governed by a documentation corpus that is taken seriously. Read in this order before
changing anything: this file, then [docs/constitution.md](docs/constitution.md) (standing
principles), then the [ADR index](docs/adr/README.md) (decisions in force), then the
[plans status table](docs/plans/README.md) (what work exists and what gates it).

## Ground rules

- **Decisions live in ADRs.** Do not contradict an Accepted ADR; do not treat a Proposed
  one as accepted. Accepting or rejecting an ADR is its own pull request carrying
  bookkeeping only — status line, index row, supersede pointers — never substantive edits.
  A plan that makes a new architectural decision on its way to shipping leaves an ADR
  behind.
- **The dual-engine discipline is live** until [ADR-0008](docs/adr/0008-postgresql-only-runtime-engine.md)
  is accepted: every view exists on both engines and is proved equivalent, migrations
  from 0256 on are portable or marked pairs per
  [ADR-0004](docs/adr/0004-engine-specific-migrations.md), `check-migrations.php` guards
  the marker discipline, and the differential harness in `.devtools/pgsql/` is the proof.
- **The wire contract is the invariant** ([ADR-0005](docs/adr/0005-wire-contract-is-the-invariant.md)).
  Response shapes do not change casually; two accepted exceptions are documented there.
- **No state in process memory** between requests ([ADR-0007](docs/adr/0007-auth-state-outlives-the-process.md)) —
  Postgres, Redis, or a table. APCu only for pure caches.
- **Security posture:** authenticated issues are in scope
  ([ADR-0006](docs/adr/0006-authenticated-issues-in-scope.md)). Sweep findings are
  tracked by S-number in the plans README; do not introduce user-configurable outbound
  URLs (the tree currently has no SSRF surface — keep it that way).
- **Frontend sinks, two rules.** A string that came out of the DOM reaches jQuery through
  `$(document).find(sel)`, never `$(sel)` — `$()` parses a string beginning with `<` as
  HTML. And markup is built as nodes (`$("<option>").text(value)`), never by concatenating
  a value into a string that is then handed to `.html()` or `.append()`. Both are checked
  on every pull request by `.devtools/frontend/s29-payload.js` in the `frontend-security`
  job; [plan 21](docs/plans/21-frontend-sink-discipline.md) is why.

## Documentation conventions

- Plans carry numbered **Open questions**; review answers go inline as `> **Response:**`
  blocks under the question, so question and answer read together.
- A landed plan gains an **Executed** section recording what actually shipped, including
  divergence from the plan above it. The plan body stays in its original present tense;
  the status table in the plans README is the authority on what is real.
- ADR format and lifecycle are specified in [docs/adr/README.md](docs/adr/README.md).
  Acceptance prerequisites are gates; the accepting PR says how each was met.
- Measurements quoted in records name the date and the working copy they were taken
  against, and say how to reproduce them.

## Conventions

- Branches: `claude/<topic>-<suffix>` for agent work, `scp/<topic>` for the maintainer.
  PRs are small and single-purpose; lifecycle PRs (ADR acceptance) never mix with
  substantive change.
- Commit messages: imperative mood, optionally prefixed (`docs:`, `chore:`, `fix:`) as
  the log already does.
- The default branch is `master`; do not push to it directly.

## Running things

- Boot the app locally (PHP built-in server, SQLite demo mode — legitimate until
  ADR-0008 lands): [.agents/skills/run-app/SKILL.md](.agents/skills/run-app/SKILL.md).
- PostgreSQL work: baseline DDL in `db/pgsql/baseline/`, differential test phases in
  `.devtools/pgsql/` (see its README), CI runs both engines against `postgres:16`.
- Business logic lives in `services/`; routes in `routes.php`; permissions are the 30
  constants in `controllers/Users/User.php` resolved through `user_permissions_resolved`.
- Container images: two answers currently exist and that is temporary. The root
  `Dockerfile` builds the `dev` image the suite runs in and a `production` image that
  [10](docs/plans/10-cold-start-statelessness.md) landed, which the `images` CI job
  asserts. [ADR-0013](docs/adr/0013-nix-built-container-images.md) (Proposed) proposes
  building production images with Nix instead — `flake.nix` and `nix/`, see
  [nix/README.md](nix/README.md) — and **supersedes the `production` target if accepted**,
  never the `dev` one. The work and the manifests are
  [plan 20](docs/plans/20-container-infrastructure.md) and [deploy/](deploy/README.md);
  **none of the Nix has been built**, so treat a first build as part of the work.
