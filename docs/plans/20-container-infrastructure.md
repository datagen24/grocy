# 20. Container infrastructure

**Goal:** Three production images built by Nix and a deploy tree that describes how to run
them, so that what ships is the transitive closure of what the process needs and nothing
else.
**Depends on:** [ADR-0013](../adr/0013-nix-built-container-images.md) for the decision
(Proposed). [10](10-cold-start-statelessness.md) has landed and supplies most of what this
plan used to have to work around.
**Status:** draft. The flake under [`nix/`](../../nix/README.md) and the manifest under
[`deploy/`](../../deploy/README.md) landed with this plan and **have never been built or
applied**. Piece 1 is the first build, and it is also ADR-0013's acceptance gate.

## Today

`master` builds a production image from the `Dockerfile` and CI asserts four things about
it: not root, a baked and unwritable view cache, no `.git` or `data/`, and a live
read-only container serving a page. That image landed with
[10](10-cold-start-statelessness.md) while this plan's first draft was in review, and it
is a genuinely good artifact.

What it does not do is what ADR-0013 argues for and this plan builds: a dependency graph
pinned by hash rather than by `apt-get update`, an image with no shell and no package
manager, an allowlisted source rather than a `.dockerignore` denylist, credential-separated
workloads, and an answer for the non-PHP workloads coming — the MCP sidecar,
[18](18-mqtt-state-publication.md)'s publisher, [ADR-0011](../adr/0011-label-namespace.md)'s
drainer — none of which inherits a Debian-and-Apache answer.

The two images are not meant to coexist indefinitely. ADR-0013's decision item 3 retires
the `Dockerfile`'s `production` target when the record is accepted, and its open question 5
is about when.

## What plan 10 changed for this plan

More than it looks, and all of it simplifying. The first draft of this plan carried
workarounds for three things that no longer exist:

- **`VICTUAL_VIEWCACHE_PATH` and `bin/victual-warm-cache` exist**, so the view cache is
  baked into the image at build time and mounted read-only. The draft's container
  entrypoint used to create that directory on every start; it no longer does.
- **The cold-start 302 is gone.** The first request after a scale-up is answered, not
  redirected. Scale-to-zero stops being a thing not to turn on.
- **`PrerequisiteChecker::checkDatabaseRequirements()` is driver-aware**, so the serving
  images drop `pdo_sqlite` and the SQLite closure behind it. Only `victual-migrate` keeps
  it, for `bin/victual-db-import`.
- **`SchemaVersionMiddleware` answers 503 until the schema matches**, which makes the
  migrate initContainer's failure mode correct rather than merely tidy: a pod whose
  migration has not finished is not ready, instead of serving errors.

It also set one constraint that decided the layout. `bin/victual-warm-cache`'s own comment
says compiled Blade file names hash the **absolute path** of the views directory, and calls
that load-bearing. Warm at one path and serve from another and every page is a 500 against
a read-only cache. So the application is served from its store path rather than copied to
`/app`, and the cache is baked inside the derivation that owns the tree — the two paths are
then the same by construction rather than by anyone keeping them in step. The `/app` copy
the first draft used would have produced exactly that failure.

## What landed with this plan

**A flake that builds three images.** `victual-app` (php-fpm on loopback, holds the
database credential, no document root), `victual-web` (nginx, holds the document root, no
PHP interpreter at all) and `victual-migrate` (the only one whose PHP carries
`pdo_sqlite`, and the only one that should hold a role able to run DDL). No base image, no
shell, no package manager, uid 65532, empty `/bin`.

**An allowlisted source.** `nix/source.nix` names what goes in; `docs/`, `.devtools/`,
`.github/`, `.agents/` and the working tree's leftovers are absent because they were never
added rather than because a denylist remembered them.

**A PHP built from a caller list.** `nix/php.nix` enables exactly the extensions
`PrerequisiteChecker` names plus those traced to a caller, each with the caller in a
comment. nixpkgs' stock PHP enables roughly three times as many.

**A baked view cache**, warmed by the application's own warmer inside its derivation, with
`VICTUAL_BASE_PATH` as a build input exactly as it is for the Dockerfile.

**A deploy tree.** `deploy/podman/victual.yaml` is a Kubernetes pod — migrate as an
initContainer, then php-fpm and nginx — with probes, limits, a security context and a stop
signal. A Kubernetes object rather than a compose file so that `podman kube play` on a
laptop and k3s in the cluster agree about what loopback means.

**Assertions instead of greps.** `nix flake check` asserts a non-root uid, no shell or
foreign interpreter in the runtime closure, no PHP in the web tier's document root, that
every file the request path opens by `__DIR__`-relative path is present, that the view
cache is actually warm, that the entrypoint's seed path is installed, that the web tier's
closure does not contain the application, and that the image tag matches `version.json`.

## What the first review corrected

The scaffolding was reviewed before any of it had been built, and six defects came back.
They are recorded rather than silently fixed, because the shape of the set is an argument
about this plan's sequencing:

| # | Defect | Where |
|---|---|---|
| 1 | `victual.openapi.json` was not in the source allowlist. `BaseApiController::GetOpenApispec()` and `UserfieldsService` read it on the request path; without it every generic entity request answers 500. | `nix/source.nix` |
| 2 | `migrations/` and `db/` were stripped from the serving images. `GetMigrationFiles()` opens a `FilesystemIterator` that throws on a missing directory — and since plan 10 the schema gate enumerates it on every request, not just on `/`. | `nix/app.nix` |
| 3 | The config stub the entrypoint seeds was declared in the overlay and installed in no image, so the migrate initContainer exited 1 on a fresh data directory and the pod never started. | `nix/config-seed.nix` |
| 4 | nginx's temporary paths were nested under `/tmp/nginx`. nginx creates the leaves it is configured with but not their parents, and an image layer creating the parent is hidden by the volume mount. | `nix/runtime/nginx-conf.nix` |
| 5 | `GET /` ended in a 403. `try_files`' directory candidate matches the document root, and `nix/webroot.nix` deliberately removes the `index.php` nginx then looks for. | `nix/runtime/nginx-conf.nix` |
| 6 | The app container's `tcpSocket` probes could never pass: the kubelet resolves a TCP probe to the **pod IP**, while php-fpm binds loopback only. A healthy pool would have been restarted on every failure threshold. | `deploy/podman/victual.yaml` |

All six are fixed, and `nix/checks.nix` now targets each specifically. Five of the six were
invisible to reading and visible the moment something ran, which is the case for piece 1
being a gate rather than a formality.

One claim was downgraded rather than defended: the migrate image is not "the only image
carrying DDL". `migrations/` and `db/` ship everywhere, and what separates these workloads
is the credential each holds. The store-path layout makes that permanent rather than
temporary — a trimmed second application root would warm a view cache the serving image
could not use.

## Proposed change

### Piece 1 — bootstrap (this is also ADR-0013's acceptance gate)

Fill in `nix/hashes.nix`, commit the `flake.lock` that `nix flake update` produces, and get
the pod serving. On a Mac this needs a Linux builder; `nix/README.md` documents the
podman-hosted one, which needs nothing installed on the host.

Expect this to be where reading-not-running shows. Likeliest failures, in descending order:
an extension attribute named wrongly in `nix/php.nix` (fails at evaluation, with the name);
`composer validate` or the vendor fetch tripping on the two VCS forks; `fetchYarnDeps` and
the one git-resolved entry in `yarn.lock`; `fixup-yarn-lock` having been folded into
`yarnConfigHook` in the pinned nixpkgs; and the view-cache warmer needing something in the
build sandbox it does not have.

### Piece 2 — measure, then trim

The extension list was derived by reading. Boot the image, walk every top-level page and
exercise the API, and compare `get_loaded_extensions()` against what was actually touched.
Record the closure (`nix path-info -rSh .#image-app`) and the image size **beside
`victual:production`'s**, which is ADR-0013's prerequisite 2 and the replacement for a
claim that record declines to make.

### Piece 3 — retire the Dockerfile's production target

ADR-0013's decision item 3, and not before it is accepted. The `images` CI job asserts five
things; four have stronger `nix flake check` equivalents and the fifth — booting a
container and fetching a URL — does not. That job's read-only boot test moves onto the Nix
images in the same change, or the retirement loses coverage the fork currently has.

### Piece 4 — the k3s manifests

A `Deployment`, a `Service`, and the ConfigMap/Secret shapes `deploy/README.md` describes
in prose. After piece 1: a manifest written before the pod has run once is a guess with
YAML syntax.

`replicas` stays at 1 until something measures whether a second helps a household-sized
instance. Plan 10's migration lock means correctness is no longer the constraint.

### Piece 5 — the rest of the family

The MCP sidecar ([02](02-mcp-endpoint.md) / the [interface spec](../mcp-interface-spec.md)),
[18](18-mqtt-state-publication.md)'s publisher and
[ADR-0011](../adr/0011-label-namespace.md)'s print drainer are each an image in this flake,
taking uid, labels, checks and manifest shape from `nix/images/lib.nix` rather than
restating them. The MCP sidecar is a separate repository by 02-Q1's recorded response, so
what it takes from here is the pattern rather than the code — which is an argument for
keeping `images/lib.nix` small enough to copy.

## What this cannot fix

- **File attachments still need a real volume.** `FilesService` writes under
  `VICTUAL_DATAPATH`, which in this pod is memory that vanishes on restart.
  [01](01-file-storage.md) moves file storage into the database; until then a deployment
  using attachments mounts something durable at `/data` and accepts that it is a
  deliberate exception.
- **One writable mount remains, and it is not the view cache.**
  `PrerequisiteChecker::checkForConfigFile()` still refuses to start unless `config.php`
  exists inside `VICTUAL_DATAPATH`, so the entrypoint seeds it. That check predates
  environment configuration and now has nothing left to check; removing it is what deletes
  the entrypoint and the `/data` mount together, and it is not this plan's to do.
- **CI and production still build different images.** The differential suite runs in the
  Debian `dev` image. Piece 2 narrows the gap; only pieces 3 and the tag build close it.

## Verification

Nothing below can be done by reading, which is the whole reason this section is long.

1. **The three images build, twice.** On two machines or architectures, producing the same
   store paths. A hash in `nix/hashes.nix` that only holds where it was produced is not a
   pin — ADR-0013 prerequisite 4.
2. **`nix flake check` passes**, particularly `image-has-no-shell` and
   `web-tier-carries-no-application`. A failure is recorded and ADR-0013's claim amended;
   the check is not relaxed to make it pass.
3. **The pod serves.** `podman kube play` against a throwaway PostgreSQL: `/login` renders
   and `GET /api/stock` with an API key returns JSON.
4. **The read-only root filesystem holds under real use.** Browse every top-level page,
   perform one create, one edit and one delete through the API, and request one thumbnail.
   Any `EROFS` is a finding to fix, not to work around. This is the one thing the Docker
   `images` job does that `nix flake check` cannot, and piece 3 must not land without it.
5. **The baked view cache is the one being used.** Confirm no file is written under the
   cache directory during check 4, and that no page falls back to compiling. This is the
   check for the absolute-path hash: a cache warmed for the wrong path fails here and
   nowhere earlier.
6. **The extension list is right, and minimal.** `get_loaded_extensions()` from inside the
   running app image against what any request actually used. Both directions are findings.
7. **The web tier really has no PHP.** No interpreter in its closure, no `.php` under the
   document root, and a crafted traversal (`/css/../index.php`, an encoded one) 404s rather
   than serving source.
8. **The credential split is real.** `victual-app` given a role with no DDL rights still
   serves; `victual-migrate` is the only image whose environment carries one that can
   migrate.
9. **Signals do what the manifest says.** `podman kube down` drains in-flight requests
   rather than truncating them; then repeat with `SIGTERM` so the cost of a cluster too old
   for `lifecycle.stopSignal` is a known quantity.
10. **A migration failure keeps the pod down.** Point the initContainer at a database it
    cannot migrate and confirm the pod never becomes ready.

## Sequencing

**Independent of the feature roadmap.** It touches no PHP the application runs.

Against [10](10-cold-start-statelessness.md) the dependency is now settled in one
direction: 10 landed, and this plan is built on what it supplies rather than working around
what it had not yet done.

It pairs with [01](01-file-storage.md): 01 removes `data/storage`, and together with
removing the `config.php` existence check that gives a pod with no writable mount at all.

## Effort

Medium, front-loaded. The framework is written; piece 1 is a day of first-build friction
that could be an hour or three. Piece 2 is an afternoon of walking the application with a
notebook. Piece 3 is small but must not be rushed — it removes a CI job that asserts real
things. Pieces 4 and 5 are small and per-workload respectively.

## Executed — piece 1, 2026-09-04

The flake had never been built. It builds now: `nix flake check` passes its 34 assertions,
and `.#image-app`, `.#image-web` and `.#image-migrate` build and load — 284 MB, 205 MB and
291 MB against the `Dockerfile` production image's 819 MB.

Built on macOS through [`nix/build-in-podman.sh`](../../nix/build-in-podman.sh), added with
this piece: it is this document's own option A, made repeatable, and the three things it
does differently from the README's one-liner are each a failure the one-liner produced. See
[nix/README.md](../../nix/README.md), "The awkward fact about macOS".

**Five defects, and the shape of the set is the argument for this piece having been a gate
rather than a formality.** Every one was invisible to reading and immediate on running;
three of them contradicted [ADR-0013](../adr/0013-nix-built-container-images.md)'s claim
that these images carry no shell.

| # | Defect | Where |
|---|---|---|
| 1 | `opcache` is not a nixpkgs extension attribute — it is compiled into `php85`, and `php -m` reports it without being listed. Listing it failed evaluation with "undefined variable 'opcache'". | `nix/php.nix` |
| 2 | `checks` came from `callPackage`, so it carried `override`/`overrideDerivation`; `nix flake check` rejects a non-derivation under `checks` before running any of them. | `flake.nix` |
| 3 | PEAR's `bin/pear`, `peardev` and `pecl` are shell scripts, so the app image's closure contained **bash** — and a package manager, which the decision says it does not have. | `nix/php.nix` |
| 4 | PHP's `PROG_SENDMAIL=${system-sendmail}/bin/sendmail` configure flag names a shell script. Nothing in this tree sends mail. | `nix/php.nix` |
| 5 | `gd` reached bash twice more — through libavif's `gdk-pixbuf-thumbnailer-avif`, and through `libxpm → gzip`. | `nix/overlay.nix` |

Two things worth carrying forward from fixing them:

- **`pearSupport = false` is the obvious lever for 3 and it is the wrong one.** nixpkgs'
  `php/generic.nix` adds `libxml2.dev` to PHP's build inputs *only when pearSupport is
  true*, so disabling it silently builds a PHP without libxml2 — and `dom`, `simplexml` and
  `xmlwriter`, which htmlpurifier and gettext need, are then built against something else.
  It was tried, and simplexml failed its own test suite. Deleting the three scripts after
  the install keeps libxml2 and removes exactly what pulled bash in.
- **Defect 5's fix, `gd.override { withXorg = false; }`, is what the `Dockerfile` already
  did** — `docker-php-ext-configure gd --with-freetype --with-jpeg`, and nothing else. The
  two images disagreed about what `gd` needs and only one of them said so out loud. This
  makes the Nix build match the Dockerfile rather than the reverse, because the
  Dockerfile's narrower list is the one that has been serving the application.

The cost is that PHP and its extensions are built from source rather than substituted from
cache.nixos.org, since the derivation is no longer the one Hydra built. That is minutes on
a laptop, a cached layer thereafter, and the honest price of an image whose closure matches
what the record claims about it.

**What this piece did *not* establish, and why ADR-0013 stays Proposed.** The images build;
the pod does not run. `deploy/podman/victual.yaml` fails under `podman kube play` because
`fsGroup` is not honoured for `emptyDir` volumes, so uid 65532 cannot write `/data` and the
migrate initContainer exits 1 — which is the failure
[deploy/README.md](../../deploy/README.md) predicts by name, one paragraph before a reader
reaches it. Two documentation defects in the same bootstrap were found on the way. All of
it is [issue #49](https://github.com/datagen24/victual/issues/49). The verification section
above is still the list; this closes the build half of it.
