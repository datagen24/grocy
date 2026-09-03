# 20. Container infrastructure

**Goal:** Three production images and a deploy tree, built by Nix, that an operator can
run without trusting anything that is not in this repository.
**Depends on:** [ADR-0013](../adr/0013-nix-built-container-images.md) for the decision
(Proposed). Blocked *for its stated goal* on [10](10-cold-start-statelessness.md) and
pairs with [01](01-file-storage.md) — see *What this cannot fix* below.
**Status:** draft. The scaffolding under [`nix/`](../../nix/README.md) and
[`deploy/`](../../deploy/README.md) landed with this plan and **has never been built**.
Piece 1 is bootstrapping it, and it is also ADR-0013's acceptance prerequisite 1.

## Today

The repository ships a `Dockerfile` and a `docker-compose.yml`, both of which say in
their own headers that they are development and CI artifacts. There is no production
image, no deploy tree, and no manifest — which is exactly what
[ADR-0010](../adr/0010-workload-standard.md) means when it says the main application
image is non-compliant with the standard's fourth property on the day the standard is
accepted.

The gap is not only "no manifests exist". It is that nothing in the repository states
what a running instance needs: which environment variables, which mounts, which uid,
what happens on `SIGTERM`, or what the first request after a cold start does. All of that
is currently knowledge, and knowledge is what a deploy tree is for turning into a file.

Three things the application does today shape every choice below, and all three are
[plan 10](10-cold-start-statelessness.md)'s to fix rather than this plan's:

- `helpers/PrerequisiteChecker.php` refuses to start unless `config.php` exists **inside
  `VICTUAL_DATAPATH`** — the same directory the application writes to.
- `app.php` creates `VICTUAL_DATAPATH/viewcache`, and on every cold start empties it and
  answers the request with a 302 to `/`, because the version/base-URL marker it looks for
  never survives a restart.
- `PrerequisiteChecker` requires `pdo_sqlite` and SQLite ≥ 3.40 on every request
  regardless of `DB_DRIVER`, so a PostgreSQL-only image still carries the SQLite closure.

## What landed with this plan

The framework, not a build. Six things exist that did not:

**A flake that builds three images.** `victual-app` (php-fpm on loopback, holds the
database credential, no document root), `victual-web` (nginx, holds the document root, no
PHP interpreter at all) and `victual-migrate` (the only image carrying the `bin/` CLI
entry points). No base image, no shell, no package manager, uid 65532, empty `/bin`.

**An allowlisted source.** `nix/source.nix` names what goes into an image. `docs/`,
`.devtools/`, `.github/`, `.agents/`, `changelog/`, `branding/`, `.git` and the working
tree's leftovers are absent because they were never added, rather than because a
`.dockerignore` remembered them. The `.dockerignore` [plan 10](10-cold-start-statelessness.md)
owes sweep S25 is still owed for the *development* image; this closes the production half.

**A PHP built from a caller list.** `nix/php.nix` enables exactly the extensions
`PrerequisiteChecker` names plus those traced to a caller, each with the caller in a
comment. nixpkgs' stock PHP enables roughly three times as many.

**A deploy tree.** `deploy/podman/victual.yaml` is a Kubernetes pod — migrate as an
initContainer, then php-fpm and nginx — with probes, limits, a security context and a
stop signal. It is a Kubernetes object rather than a compose file so that `podman kube
play` on a laptop and k3s in the cluster agree about what loopback means, and so there is
one manifest instead of two.

**Assertions instead of greps.** `nix flake check` asserts that the image config declares
a non-root uid, that the runtime closure contains no shell or foreign interpreter, that
the web tier's document root contains no PHP, that the serving images do not carry the
`bin/` CLI entry points, that every file the request path opens by `__DIR__`-relative
path is present, that the entrypoint's config seed is actually installed in an image
layer, and that the image tag matches `version.json`. ADR-0010's open question 2
leans towards "start with the cheap greps"; these are the cheap greps, made about the
artifact rather than about the file that describes it.

**One temporary shim, with its deletion condition written into it.**
`nix/runtime/entrypoint.php` creates the scratch directory and seeds the near-empty
`config.php` that `PrerequisiteChecker` demands, then `pcntl_exec`s the real command. It
is PHP rather than shell because the images contain no shell and adding one so six lines
of setup can run would put a shell in every production container for the life of the
deployment. Its header names plan 10 as the thing that deletes it.

## What the first review corrected

The scaffolding was reviewed on 2026-09-03, before any of it had been built, and six
defects came back. They are recorded here rather than silently fixed, because the shape
of the set is an argument about this plan's sequencing:

| # | Defect | Where |
|---|---|---|
| 1 | `victual.openapi.json` was not in the source allowlist. `BaseApiController::GetOpenApispec()` and `UserfieldsService` read it on the request path; without it every generic entity request answers 500. | `nix/source.nix` |
| 2 | `migrations/` and `db/` were stripped from the serving images. `SystemController::Root` still calls `MigrateDatabase()`, and `GetMigrationFiles()` opens a `FilesystemIterator` that throws on a missing directory — `/` answered 500 instead of its 302. | `nix/approot.nix` |
| 3 | The config stub the entrypoint seeds was declared in the overlay and installed in no image, so the migrate initContainer exited 1 on a fresh data directory and the pod never started. | `nix/config-seed.nix` (new) |
| 4 | nginx's temporary paths were nested under `/tmp/nginx`. nginx creates the leaves it is configured with but not their parents, and an image layer creating the parent is hidden by the volume mount. | `nix/runtime/nginx-conf.nix` |
| 5 | `GET /` ended in a 403. `try_files`' directory candidate matches the document root, and `nix/webroot.nix` deliberately removes the `index.php` nginx then looks for. | `nix/runtime/nginx-conf.nix` |
| 6 | The app container's `tcpSocket` probes could never pass: the kubelet resolves a TCP probe to the **pod IP**, while php-fpm binds loopback only. A healthy pool would have been restarted on every failure threshold. | `deploy/podman/victual.yaml` |

All six are fixed. Five of the six were invisible to reading and visible the moment
something ran, which is the case for piece 1 being a gate rather than a formality — and
defect 2 in particular is the one that would have been most expensive to find later,
because it only shows on the request path rather than at build time.

Two claims this plan made are downgraded rather than defended as a result. The migrate
image is not "the only image carrying DDL" — `migrations/` and `db/` ship everywhere
until plan 10 lands, and what separates the workloads is the credential each holds, not
the bytes each carries. And the checks in `nix/checks.nix` are now written to catch
these specific regressions, which is an admission that the first set was checking the
easy properties.

## Proposed change

### Piece 1 — bootstrap (this is also ADR-0013's acceptance gate)

Fill in `nix/hashes.nix`, commit the `flake.lock` that `nix flake update` produces, and
get the pod serving. On a Mac this needs a Linux builder; `nix/README.md` documents the
podman-hosted one, which needs nothing installed on the host.

Expect this to be where the reading-not-running shows. The likeliest failures, in
descending order of probability: an extension attribute named wrongly in `nix/php.nix`
(fails at evaluation, with the name); `composer validate` or the vendor fetch tripping on
the two VCS forks; `fetchYarnDeps` and the one git-resolved entry in `yarn.lock`;
`fixup-yarn-lock` having been folded into `yarnConfigHook` in the pinned nixpkgs. All
four are cheap to diagnose and none of them is a design problem.

### Piece 2 — measure, then trim

The extension list was derived by reading. Boot the image, walk every top-level page and
exercise the API, and compare `get_loaded_extensions()` against what was actually
touched. Remove what nothing loaded; add, with a caller named in the comment, whatever
turns out to be missing. Record the closure (`nix path-info -rSh .#image-app`) and the
image size beside the Debian image's, which is prerequisite 2 of ADR-0013 and the
replacement for a claim that record currently declines to make.

### Piece 3 — CI

`nix flake check` on `master`, and an image build on tags. Not on every pull request —
see ADR-0013 open question 2 — so the fast feedback loop the `tests` workflow deliberately
keeps stays fast.

### Piece 4 — the plan 10 seam

When [10](10-cold-start-statelessness.md) lands, three things here change together and
should change in one pull request:

- `nix/runtime/entrypoint.php` is deleted, and both images' `Entrypoint` becomes the
  command itself.
- The image builds the baked view cache: `bin/victual-warm-cache` runs as a derivation
  and its output is a read-only path pointed at by `VICTUAL_VIEWCACHE_PATH`. 10's Q2
  response also requires the warmer to pre-generate the HTMLPurifier definition cache,
  without which the first JSON write request hits a read-only serializer path.
- `pdo_sqlite` comes out of the serving images once the prerequisite check is
  driver-aware, and stays only in `victual-migrate`, which needs it for
  `bin/victual-db-import`.
- `migrations/` and `db/` can finally come out of the serving images, which is what the
  first review established they cannot do today. The schema-version check plan 10's Q6
  specifies has to read metadata generated at build time rather than counting files in
  a directory, or this seam does not open.

The `/data` `emptyDir` disappears at the same time, which is the point at which the pod
has no writable mount at all.

### Piece 5 — the k3s manifests

A `Deployment`, a `Service`, and the ConfigMap/Secret shapes `deploy/README.md` currently
describes in prose. Deliberately after piece 1: a manifest written before the pod has run
once is a guess with YAML syntax.

`replicas` stays at 1 until plan 10's migration lock exists. Two pods starting together
against an empty database race in `DatabaseMigrationService`, and the loser's user sees a
500. An initContainer at one replica does not hit it; raising the count before the lock
does.

### Piece 6 — the rest of the family

The MCP sidecar ([02](02-mcp-endpoint.md) / the [interface spec](../mcp-interface-spec.md)),
plan [18](18-mqtt-state-publication.md)'s publisher and [ADR-0011](../adr/0011-label-namespace.md)'s
print drainer are each an image in this flake, taking uid, labels, checks and manifest
shape from `nix/images/lib.nix` rather than restating them. The MCP sidecar is a separate
repository by 02-Q1's recorded response, so what it takes from here is the pattern rather
than the code — which is an argument for keeping `images/lib.nix` small enough to copy.

## What this cannot fix, and should not pretend to

**The pod still has a writable mount.** `/data` is an in-memory `emptyDir`, so there is no
durable state outside PostgreSQL and no persistent volume — but that is not the same as
plan 10's "no writable path at all", and this plan should not be read as having delivered
it. The root filesystem *is* read-only; the scratch directory is a mount beside it.

**A cold-start request still gets a 302.** `app.php`'s hash marker never survives a
restart. Scale-to-zero remains a thing not to turn on. This is 10's first item.

**File attachments still need a volume.** `FilesService` writes to
`$VICTUAL_DATAPATH/storage`, which in this pod is memory that vanishes on restart. A
deployment that uses attachments needs a real volume mounted there until
[01](01-file-storage.md) moves file storage into the database — a deliberate, documented
exception rather than an oversight.

**CI and production build different images from the same tree.** The differential suite
runs in the Debian image. "It passed CI" does not prove the Nix image has the extension
the code needs. Piece 2 narrows this; only piece 3's tag build closes it, and only for
tags.

## Verification

Nothing below can be done by reading, which is the whole reason this section is long.

1. **The three images build, twice.** `nix build .#image-app .#image-web .#image-migrate`
   on two machines (or two architectures) produces the same store paths. A hash in
   `nix/hashes.nix` that only holds on the machine that produced it is not a pin —
   ADR-0013 prerequisite 4.
2. **`nix flake check` passes.** Particularly `image-has-no-shell`. If nixpkgs' PHP drags
   a shell into its runtime closure, the finding is recorded and ADR-0013's claim is
   amended; the check is not relaxed to make it pass.
3. **The pod serves.** `podman kube play deploy/podman/victual.yaml` against a throwaway
   PostgreSQL: `/login` renders, and `GET /api/stock` with an API key returns JSON.
4. **The read-only root filesystem holds under real use.** With
   `readOnlyRootFilesystem: true`, browse every top-level page and perform one create,
   one edit and one delete through the API. Any `EROFS` is a finding to fix, not to work
   around — this is plan 10's verification check 4, run early, and it is what catches a
   template the deployment never rendered and the HTMLPurifier serializer path.
5. **The extension list is right, and minimal.** `get_loaded_extensions()` from inside the
   running app image against the list of extensions any request actually used. Both
   directions are findings: an extension nothing used comes out, and an extension the
   code wanted and did not have would have shown up as a fatal in check 4.
6. **The web tier really has no PHP.** From inside `victual-web`, confirm there is no
   interpreter in the closure and no `.php` under the document root. Then request a URL
   crafted to trip the `.php` location block (`/css/../index.php`, a path with an encoded
   traversal) and confirm it 404s rather than serving source.
7. **The credential split is real.** `victual-app` given a role with no DDL rights still
   serves; `victual-migrate` is the only image whose environment carries a role that can
   migrate. This is the check that turns ADR-0010's third property from a shape into a
   fact.
8. **Signals do what the manifest says.** `podman kube down` (and, in the cluster, a
   rolling restart) drains in-flight requests rather than truncating them. Then repeat
   with `SIGTERM` to see the difference, so the cost of a cluster too old for
   `lifecycle.stopSignal` is a known quantity rather than a suspicion.
9. **A migration failure keeps the pod down.** Point the initContainer at a database it
   cannot migrate and confirm the pod never becomes ready — the failure mode this whole
   arrangement exists to produce instead of "starts, then fails per request".

## Sequencing

**Independent of the feature roadmap and of every hardening plan except 10.** Nothing in
01–09 blocks on it and it blocks nothing. It touches no PHP the application runs: the
only code it adds is `nix/runtime/entrypoint.php`, which the application never loads.

Against [10](10-cold-start-statelessness.md) it is complementary rather than dependent,
and the order matters less than it looks. 10 is the plan that makes a pod with no
writable path possible; this is the plan that produces the pod. Doing this one first
means 10's verification checks 1 and 4 have somewhere to run — they want "a fresh
container, first request is an API call" and "read-only root filesystem", and neither of
those exists to be tested against today. Doing 10 first means this plan's shim is never
written. The shim is nine lines and it is already written, so: this one first, and piece 4
is the seam.

Against [01](01-file-storage.md): unchanged from 10's reading of it. 01 removes
`data/storage`; 10 removes everything else writable; only both together give a pod with
no volume, and this plan is what there is to mount the volume onto in the meantime.

## Open questions

1. **Is the two-container split worth its cost?** It is the biggest design decision here
   and it is not free: two images to build, two to publish, two to keep in sync about the
   path `/app`, and a `502` rather than a `500` when they disagree. The alternative is one
   container running nginx and php-fpm under a supervisor, which halves the artifacts and
   puts a process manager plus two daemons in one namespace with one credential.

   *Lean: keep the split.* "One tool, one job" is the constitution's phrasing, and the
   security argument is concrete rather than aesthetic — the tier that parses untrusted
   request bytes first is also the tier with no database credential and no interpreter.
   But it is the question to answer before piece 5 writes manifests around it.

2. **Where does `VICTUAL_DB_PASSWORD` come from — an environment variable or a
   `settingoverrides` file?** `Setting()` supports both, and the file has the higher
   precedence. An environment variable is the k8s convention and is visible in
   `/proc/self/environ` to the process and to anything that can read the pod spec; a file
   is a `Secret` volume, readable only by the container and rotatable without a restart —
   except that this application reads its settings once per request, so rotation is not
   actually free.

   *Lean: the environment variable, and say so in one place.* The file path is better on
   paper and worse in practice here, because it lives under `VICTUAL_DATAPATH` — the one
   directory this deployment is trying to stop needing — so choosing it would couple the
   secret's shape to a mount that piece 4 is meant to delete.

3. **Should `victual-migrate` be a separate image at all, or the app image with a
   different `Cmd`?** They differ by three directories. A single image is one build, one
   push and one thing to keep patched; two images are what makes "the request path does
   not carry the CLI entry points" true rather than merely conventional. Note what it
   does *not* prove, after the first review corrected this plan: `migrations/` and `db/`
   are in every image, because the request path still reads them.

   *Lean: keep them separate,* on the ADR-0010 argument that a workload's identity is its
   credential and its blast radius, not its bytes. But the honest counter is that the
   corpus is read-only text and its presence in the app image is not itself exploitable,
   so this is defence in depth rather than a closed hole, and it should be argued as such.

4. **Which extensions actually come out after piece 2?** Named as a question rather than
   left as a task because the answer changes the record: `nix/php.nix`'s comments claim a
   caller for each extension, and any that turn out to have none make that file's
   organising claim false until it is corrected.

5. **Does the `nginx` tier survive contact with the ingress?** In k3s the ingress already
   terminates TLS, sets forwarded headers and can serve static assets itself. If it does,
   `victual-web` is a second hop that exists to hold a document root, and serving
   `public/` from the ingress would remove a whole image.

   *Lean: keep it for now* — the ingress is the operator's, per ADR-0010's boundary, and
   an application that only works behind one particular ingress configuration is worse
   than one extra container. Revisit if the hop shows up in a measurement.

6. **What is the story for `sweep S25`'s credentials item?** The sweep's finding covers
   the compose file's `victual`/`victual` PostgreSQL credentials as well as the root user
   and the missing `.dockerignore`. This plan removes the root user and the `COPY .` for
   the production path, and `deploy/README.md` keeps `victual`/`victual` for the *laptop
   bootstrap only* — deliberately, since it is a throwaway database on a loopback port.
   Whether that satisfies the finding or merely relocates it is a review call.

   *Lean: it relocates it, and that is fine,* provided the sweep's row says so rather than
   being marked fixed. 10 owns the item; this plan should hand it back with the production
   half done and the dev half untouched.

## Effort

Medium, and unusually front-loaded. The framework is written; piece 1 is a day of
first-build friction that could be an hour or could be three if a nixpkgs interface has
moved. Piece 2 is an afternoon of walking the application with a notebook. Pieces 3 and 5
are small. Piece 4 is not this plan's work at all — it is the shape plan 10's landing
takes here. Piece 6 is per-workload and roughly free once the first one is done, which is
the entire argument for having done this before the family exists.
