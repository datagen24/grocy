# Building the container images

This tree builds three production images with Nix. It is not the `Dockerfile` at the
repository root and does not replace it: that one is the development and CI image —
Debian, a compiler toolchain, git, composer, a shell — and it exists so a contributor
can run the differential suite from a clean checkout. These are the other thing.

The decision is [ADR-0013](../docs/adr/0013-nix-built-container-images.md); the work and
what remains unproved is [plan 20](../docs/plans/20-container-infrastructure.md).

**Nothing here has been built yet.** It was written against nixpkgs' PHP, Composer,
yarn and `dockerTools` interfaces as they stand on `nixos-unstable`, but no build has
run, so treat the first `nix build` as part of the work rather than as a formality. Plan
20's verification section is the list of what the first build has to establish.

## What gets built

| Output | What it is | Ships |
|---|---|---|
| `.#image-app` | php-fpm on loopback:9000 | PHP 8.5 + the extensions in `php.nix`, the application at `/app` |
| `.#image-web` | nginx on :8080 | nginx, `public/` and the yarn-built `packages/`, no PHP at all |
| `.#image-migrate` | `bin/victual-migrate`, a Job | PHP CLI, the application, and the `bin/` CLI entry points |

All three run as uid 65532, contain no shell and no package manager, and are built from
`scratch` — there is no base image and therefore no base image's CVEs.

The split is not decoration. The web tier holds the document root and no credential; the
app tier holds the credential and no document root; the migrate tier is the only one
that should ever hold a role able to run DDL. That is ADR-0010's "its own credential,
its own database role, least privilege for the one job it does".

**What separates them is the credential, not the bytes.** `migrations/` and `db/` ship in
every image, because `SystemController::Root` still calls `MigrateDatabase()` and
`GetMigrationFiles()` throws on a missing directory. The first draft of this tree
stripped them and turned `/` into a 500; plan 10 is what makes stripping them possible.

## Building it

### The awkward fact about macOS

Container images are Linux artifacts. On an Apple Silicon Mac, Nix builds
`aarch64-darwin` by default and **cannot build `aarch64-linux` without a Linux builder**.
This flake evaluates fine on a Mac — the devShell and the application derivation are
there — but `nix build .#image-app` will fail with "a 'aarch64-linux' with features {}
is required to build" unless one of the following is true.

**Option A — build inside podman.** No change to the host, and podman is already the
stated bootstrap tool:

```sh
podman run --rm -v "$PWD:/src:ro,Z" -w /src \
  docker.io/nixos/nix:latest \
  nix --extra-experimental-features 'nix-command flakes' \
      build .#image-app --print-out-paths
```

The catch is that the result lives inside the throwaway container, so in practice you
want the streamer to write straight out:

```sh
podman run --rm -v "$PWD:/src:ro,Z" -w /src docker.io/nixos/nix:latest \
  sh -c 'nix --extra-experimental-features "nix-command flakes" build .#image-app \
           --no-link --print-out-paths | xargs -I{} {}' \
  | podman load
```

**Option B — a persistent Linux builder.** `nix-darwin`'s `nix.linux-builder.enable`
gives a small NixOS VM registered as a remote builder, after which
`nix build .#image-app` works from the Mac shell with no ceremony and, unlike option A,
keeps its store between builds. This is the better answer once the images are being
rebuilt regularly; option A is the better answer for finding out whether any of this
works at all.

### On a Linux host

```sh
nix build .#image-app          # then: ./result | podman load
nix run  .#load                # builds all three and loads them
nix flake check                # the assertions in nix/checks.nix
```

`nix run .#load` honours `CONTAINER_ENGINE`; set it to `docker` if that is what you run.

### Bootstrapping the hashes

Two derivations fetch from the network and therefore need a content hash:
the Composer vendor tree and the yarn offline mirror. Both start as `lib.fakeHash` in
[`hashes.nix`](hashes.nix), which means **the first build of each fails on purpose**:

```
error: hash mismatch in fixed-output derivation '/nix/store/…-victual-composer-vendor-4.6.0.drv':
         specified: sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
            got:    sha256-h9Xk…=
```

Paste the `got:` value into `hashes.nix` and build again. `nix build .#app` gives you
`composerVendor`; `nix build .#frontend` gives you `yarnOfflineCache`. They change only
when `composer.lock` or `yarn.lock` changes — and a changed lockfile with an unchanged
hash here is a build failure rather than a silently stale dependency set, which is the
property being bought.

There is no `flake.lock` in the repository yet, for the same reason: it cannot be
written by hand. `nix flake update` produces it on the first build, and **it should be
committed** — it is the pin that makes "reproducible" mean anything, and it is a
supply-chain input that belongs under review like any other.

## The tree

```
flake.nix              outputs; the only file a consumer needs to read
nix/
  overlay.nix          everything hangs off pkgs.victual
  hashes.nix           the two fixed-output hashes, in one place
  source.nix           what goes into an image — an allowlist, not an ignore file
  php.nix              PHP 8.5 with exactly the extensions the tree calls
  app.nix              the application + Composer dependencies
  frontend.nix         yarn → public/packages
  webroot.nix          the static tree the web tier serves
  healthcheck.nix      /opt/victual/healthcheck, what the pod's exec probe runs
  config-seed.nix      /etc/victual/config.php, what the entrypoint copies in
  checks.nix           what `nix flake check` proves
  runtime/
    php-ini.nix        production php.ini, applied to every SAPI
    fpm-conf.nix       the php-fpm pool
    nginx-conf.nix     the static tier's configuration
    entrypoint.php     scratch-directory setup, then exec — deleted by plan 10
    config.php         the near-empty config.php the image seeds
  images/
    lib.nix            uid, labels, the shared half of the OCI config
    app.nix / web.nix / migrate.nix
    load.nix           `nix run .#load`
```

## Three things worth knowing before you change something here

**The application is served from its store path, and that is not an aesthetic choice.**
`bin/victual-warm-cache` compiles Blade templates whose compiled file names hash the
*absolute path* of the views directory — the warmer's own comment says so and calls it
load-bearing. Warm at one path and serve from another and every page is a 500 against a
read-only cache. Baking the cache inside `nix/app.nix`, the derivation that owns the tree,
makes the warm path and the serve path the same one by construction. An earlier draft
copied the application to `/app` and would have produced exactly that failure.

The same constraint is why there is one application derivation for all three images rather
than a trimmed one for the serving tier: a second root is a second path, and its cache
would not match.

**nginx names the app's store path without depending on it.** `SCRIPT_FILENAME` is a path
in the *other* container, so `nix/runtime/nginx-conf.nix` wraps it in
`builtins.unsafeDiscardStringContext` — keeping the text and dropping the dependency edge.
Without that, the web image would carry every PHP file it exists in order not to have.
`nix/checks.nix` asserts it.

**Everything the request path opens by `__DIR__`-relative path has to be in the
allowlist, and `nix/checks.nix` check 4 is the list of what that turned out to mean.**
The first review found three files missing from it — `victual.openapi.json`,
`migrations/`, `db/pgsql/baseline` — each of which fails at request time rather than at
build time. Adding a `file_get_contents(__DIR__ . '/…')` to the application is therefore
a change to `nix/source.nix` as well.

**The application is copied to `/app`, not symlinked.** PHP resolves `__DIR__` through
the realpath cache, so a symlinked `public/index.php` reports its store path and
`require_once __DIR__ . '/../app.php'` then looks inside a store path that has no
`app.php` in it. The copy costs a few megabytes in one layer and removes the whole
class of surprise.
