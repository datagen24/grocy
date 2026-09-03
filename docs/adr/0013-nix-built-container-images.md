# ADR-0013: Production images are built by Nix from a flake in this repository

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-03. The decision was made when this was written; nothing has been
  built from it yet, which is what the acceptance prerequisites below are about.
- **Relationship:** supplies the *how* for [ADR-0010](0010-workload-standard.md)'s fourth
  property — a workload "exists in the repository's deploy tree with health probes and
  resource limits, or it does not exist" — and makes its third, non-root with a read-only
  root filesystem, a structural property of the artifact rather than a line somebody
  remembers to add. 0010 is Proposed and this record does not assume otherwise: what
  follows stands on the build-system argument alone, and would be worth doing if 0010
  were rejected tomorrow.
- **Would affect:** [10](../plans/10-cold-start-statelessness.md) (which owns the
  writable-path work these images are blocked on), [01](../plans/01-file-storage.md),
  [02](../plans/02-mcp-endpoint.md) and [18](../plans/18-mqtt-state-publication.md) (whose
  workloads are born into this pattern or outside it),
  [16](../plans/16-project-rename.md) (registry claims).
- **Referenced by:** [plan 20](../plans/20-container-infrastructure.md), [the deploy
  tree](../../deploy/README.md), [nix/README.md](../../nix/README.md).

## Context

The repository has exactly one image and it is honest about not being a production one.
`Dockerfile`'s own header says so: it exists so `.devtools/pgsql/difftest.php` and the
suite built on it can run from a clean checkout, and "production packaging is a separate
concern and deliberately not solved here." What it actually builds is
`php:8.5-cli-bookworm` plus git, unzip, sqlite3, postgresql-client, four `-dev` library
packages, a compiler toolchain, `pecl`, composer, and `COPY . /app` with no
`.dockerignore` — so `.git`, `docs/`, `.devtools/` and whatever is lying around the
working tree all land in the layer. It runs as root. The security sweep rates this Info,
correctly, on the grounds that it is a dev image with a tmpfs database and no published
ports; [plan 10](../plans/10-cold-start-statelessness.md) records that it stops being
correct at the moment anyone bakes a production image from the same file.

So the question is not "should the production image be hardened". It is "what is the
production image built *from*", and it is being asked before there is a wrong answer to
undo.

Three facts about this fork make the usual answer — a second, slimmer Dockerfile —
weaker than it looks.

**The deployment is about to become a family.** The MCP sidecar is settled as its own
container, plan 18 needs a publisher, ADR-0011 replaces a webhook consumer with a
drainer. Each is a separate image, and each is a separate copy of whatever base-image
choice, non-root incantation and dependency-pinning discipline the first one established
by hand. A Dockerfile family shares nothing; each file re-states the conventions, and
they drift silently because nothing compares them.

**"Pinned" is not what a Dockerfile means by pinned.** `FROM php:8.5-cli-bookworm`
followed by `apt-get update` resolves to different bytes on different days, by design.
That is fine for a CI image whose job is to have a working PHP. It is a poor foundation
for an artifact whose whole claim is that what is running is what was reviewed — and
this corpus already treats "measured, not assumed" as a standing rule.

**The attack surface that matters is what is in the image at all.** The fork's threat
model takes authenticated issues seriously ([ADR-0006](0006-authenticated-issues-in-scope.md))
and the security sweep's findings are about what an attacker can reach after they are
inside. A Debian base gives a process that reaches code execution a shell, a package
manager, curl, and a compiler. None of those is a vulnerability; all of them are
capability. Removing them is not achievable by adding `USER victual` to the bottom of the
current file.

## Decision (proposed)

**Production container images are built by Nix, from a flake in this repository, one
image per workload, on no base image.**

Concretely:

1. **`flake.nix` and `nix/` are the production build.** Every image the fork publishes is
   a `dockerTools.streamLayeredImage` output of that flake. The image's contents are the
   transitive closure of what the process actually runs, and nothing else: there is no
   base image, so there is no base image's package set, no shell, no package manager, and
   no build tooling at runtime.

2. **The root `Dockerfile` stays, unchanged in purpose.** It is the development and CI
   image and it is good at that. This record does not deprecate it and does not ask CI to
   stop using it. Two images with two jobs is the correct number; one image pretending to
   do both is how a debugging toolchain ends up in production.

3. **Source is an allowlist.** `nix/source.nix` names what goes in. A new directory in
   the repository is not in the image until someone says so, which is the opposite of
   `COPY .` plus a `.dockerignore` nobody updates.

4. **Every image runs as uid 65532 with a read-only root filesystem and an empty `/bin`.**
   These are properties of the artifact, asserted in `nix/checks.nix` and therefore
   checkable by `nix flake check` rather than by reading a Dockerfile for the string
   `USER`.

5. **One workload, one image, one credential.** The application splits into `victual-app`
   (php-fpm, holds the database credential, no document root), `victual-web` (nginx,
   holds the document root, no credential and no PHP interpreter) and `victual-migrate`
   (the only image carrying `migrations/`, `db/` and `bin/`, and the only one that should
   ever hold a role able to run DDL).

6. **The deploy tree lives in `deploy/`**, in this repository, carrying the manifests for
   fork-shipped workloads and saying nothing about ingress, storage classes or secret
   management. This is the concrete form of ADR-0010's open question 1, which leans the
   same way; if 0010 is accepted with the other answer the tree moves and the flake does
   not care, because a `streamLayeredImage` output has no opinion about where its
   manifests live.

7. **`flake.lock` is committed and reviewed.** It is the pin that makes "reproducible"
   mean anything and it is a supply-chain input like any other. A nixpkgs bump is a pull
   request that says what moved.

## Consequences

**`kubectl exec … sh` stops working, forever.** This is the cost that will be felt first
and most often. Debugging a running pod becomes `kubectl debug --image=…` with an
ephemeral container, or reproducing the problem locally against the same image. That is
strictly more work than typing `sh`, and it is the same property that stops an attacker
typing `sh`. It should be decided on purpose rather than discovered during an incident,
which is why it is the first line of this section.

**Nix becomes a hard dependency of releasing.** Nobody can build a publishable image
without it. On the maintainer's Mac that means a Linux builder — either `nix-darwin`'s
`linux-builder` or a Nix container under podman — because macOS cannot produce a Linux
image on its own. `nix/README.md` documents both. The bootstrap is a real cost and it is
paid once.

**Two content hashes are maintained by hand.** The Composer vendor tree and the yarn
offline mirror are fixed-output derivations, so `composer.lock` and `yarn.lock` each have
a hash in `nix/hashes.nix` that must be updated when they change. The failure mode is
loud — the build stops and prints the correct value — which makes this an annoyance
rather than a hazard. It is still an annoyance, and it is the thing most likely to make
someone want a Dockerfile back.

**CI and production build different images from the same tree.** The differential suite
runs in the Debian image; the deployment runs the Nix images. "It passed CI" therefore
does not mean "it works in the image that ships" — a missing PHP extension is exactly the
kind of thing this splits. The mitigation is that the extension list in `nix/php.nix` is
derived from `PrerequisiteChecker` and from traced callers rather than from guessing, and
that plan 20's verification walks the built image. It is a mitigation, not a proof, and
whether CI should also build the Nix images is open question 2.

**The nixpkgs pin moves PHP's patch version.** `php85` in nixpkgs is a specific point
release; updating the lock updates PHP. That is more visible than `FROM php:8.5-cli` (a
tag that moves under you) and less flexible (there is no "just the security patch"). Net
better, but different, and a bump is now a diff someone reads.

**The images are smaller than the Debian ones by a large factor, and the number is not
yet known.** No build has run. Any figure in this record would be a guess, so there is
none; taking the measurement is acceptance prerequisite 2.

**New workloads are born into this.** A Go or TypeScript sidecar — the MCP one, plan 18's
publisher, ADR-0011's drainer — is a `buildGoModule`/`buildNpmPackage` away from an image
that has the same uid, the same labels, the same empty `/bin` and the same checks, for
roughly the cost of naming it. That is the payoff for deciding this before the family
exists rather than after, and it is the same argument ADR-0010 makes for the standard
itself.

## Acceptance prerequisites

Gates, not suggestions. This record is written from the interfaces `nixos-unstable`
exposes today, read rather than run, and it should not be accepted on that basis.

1. **All three images build, and the pod serves.** On the maintainer's Mac, through
   podman: `nix run .#load`, then `podman kube play deploy/podman/victual.yaml` against a
   throwaway PostgreSQL, then a rendered `/login` and one authenticated API read
   (`GET /api/stock`). The accepting pull request records the image sizes and the closure
   listing (`nix path-info -rSh .#image-app`).
2. **The comparison against today's image is measured, not asserted.** Size, and whether
   a shell is present, for the Debian image and for `victual-app`. The claim in
   *Consequences* that these are "smaller by a large factor" is either replaced by the
   number or deleted.
3. **`nix flake check` passes, including `image-has-no-shell`.** If nixpkgs' PHP turns out
   to drag a shell into its runtime closure after all, this record's "no shell" claim is
   amended to what is true rather than the check being relaxed.
4. **The two fixed-output hashes reproduce.** `nix/hashes.nix` filled in on one machine
   and verified on a second, or on a second architecture. A hash that only holds on the
   machine that produced it is not a pin.

## Open questions

1. **Where do the images go?** Nothing is published today and
   [16](../plans/16-project-rename.md) parks the registry and domain claims until
   announcement. GHCR is the obvious default given the repository is already on GitHub.
   *Lean: decide nothing now; `podman load` locally and a k3s node-local image is enough
   for the household deployment, and a registry is a claim to make once rather than
   twice.*
2. **Should CI build the Nix images on every pull request?** It would close the
   "CI and production build different images" gap, and it would add several minutes and a
   Nix cache to a workflow whose current virtue is being fast.
   *Lean: not per-PR. Build them on `master` and on tags, so a break is caught within a
   merge rather than within a release, and keep the pull-request loop as it is.*
3. **nginx, or something smaller?** nginx is conventional and its config maps cleanly
   onto `public/.htaccess`'s one rewrite rule. A single static binary — Caddy, or serving
   the assets from the ingress and dropping the tier entirely — would be smaller still.
   *Lean: nginx for the first cut, because the interesting question is whether the split
   works at all, and re-answering this later costs one file.*
4. **One architecture or two?** The cluster is one architecture; the maintainer's laptop
   is another. Building both doubles the build and makes `nix run .#load` on the Mac
   produce something the cluster cannot run unless it is the cluster's arch.
   *Lean: build for the cluster's architecture, and treat "runs on the laptop" as a
   property of the podman bootstrap rather than of the published image.*
5. **Does the development image eventually become a devShell?** `flake.nix` already
   exposes one. Folding the Debian image into it would leave one dependency description
   instead of two — and would make the CI suite depend on Nix, which is the thing
   consequence 4 above is uneasy about.
   *Lean: no, not as part of this. It is a separate decision with a separate cost, and
   coupling it to this one is how a build-system change becomes a CI outage.*

## Options considered

**A second, hardened Dockerfile.** The obvious answer, and the cheapest to start. It
gets non-root and a `.dockerignore` easily; it does not get an empty `/bin`, because
multi-stage-copying a PHP install and its shared libraries out of a Debian builder by
hand is a job people do badly and then stop maintaining. It gets no reproducibility at
all. Rejected on the family argument above: three more workloads are coming and each
would be another copy of the same conventions.

**A distroless base (`gcr.io/distroless/*`).** Solves the shell and the package manager;
does not solve reproducibility (the base tag moves), does not solve PHP (there is no
distroless PHP, so the interpreter and every extension still come from a Debian builder
stage), and adds a dependency on a base image somebody else versions. It is the right
answer for a Go binary and an awkward one here.

**Buildpacks / `ko` / `jib`.** Language-ecosystem tools with no PHP story worth having.

**Nix.** Costs a bootstrap and two hand-maintained hashes; gives an allowlisted source
tree, a closure-exact image, the same conventions for every future workload in the family
for free, and assertions about the artifact rather than about the file that describes it.

## Research

- Tree facts (`Dockerfile`, `docker-compose.yml`, the absence of `.dockerignore` and of a
  deploy tree, `.yarnrc`'s `--modules-folder public/packages`, the two VCS forks pinned
  by commit in `composer.lock`) measured against the working copy of 2026-09-03.
- nixpkgs interfaces read against `nixos-unstable` on 2026-09-03: `php85` exists at
  8.5.10, which satisfies `PrerequisiteChecker::REQUIRED_PHP_VERSION`; `php.buildEnv`
  replaces rather than extends the default extension set; `buildComposerProject2` splits
  the fetch into a hashed fixed-output derivation and honours `composer.json`'s
  `vendor-dir`; `fetchYarnDeps` handles the one git-resolved entry in `yarn.lock`;
  `streamLayeredImage` takes its closure roots from both `contents` and the image config,
  so a store path named only in `Entrypoint` is still in the image. Read, not run — which
  is what prerequisite 1 exists for.
- The writable-path inventory this record's images have to live with is
  [plan 10](../plans/10-cold-start-statelessness.md)'s table, not a fresh survey.
