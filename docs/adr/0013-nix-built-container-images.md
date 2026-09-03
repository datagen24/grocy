# ADR-0013: Production images are built by Nix from a flake in this repository

- **Status: Rejected (2026-09-03).** Superseded in fact rather than by another record:
  [plan 10](../plans/10-cold-start-statelessness.md) landed a production image from the
  `Dockerfile` while this was being written, and the case below did not survive that.
  The number and the file stay, because the reason something was *not* done is what
  future readers most often need and least often have.
- **Decider:** datagen24 (maintainer), 2026-09-03.
- **Recorded:** 2026-09-03, and rejected the same day. It is written here in the past
  tense of a proposal that was made and declined rather than rewritten into a
  justification, so that what was actually argued is still legible.
- **Lifecycle note:** the index's rule is that accepting or rejecting a record is its own
  pull request carrying bookkeeping only. That rule protects a record which has *stood*
  as Proposed from being adopted or discarded by momentum. This one never stood: it was
  written and rejected inside the pull request that introduced it, before it had ever
  been in the tree as a live proposal, so there is no earlier state for a separate
  lifecycle PR to review. Recording that here rather than quietly departing from the
  rule.
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
- **Referenced by:** [plan 20](../plans/20-deploy-tree.md), [the deploy
  tree](../../deploy/README.md).

## Context

**The premise below was true when this was written and false within hours.** PR #33
landed [plan 10](../plans/10-cold-start-statelessness.md) on `master` with a `production`
target in the same `Dockerfile`: Apache with mod_php, non-root `www-data`, a view cache
baked at build time, a read-only root filesystem, a `.dockerignore`, and an `images` CI
job asserting each of those against the built artifact. The paragraph that follows is
left as it was written, because a record that quietly edits its own premises is worth
less than one that shows where it was overtaken.

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
   (the only image carrying `bin/victual-migrate` and `bin/victual-db-import`, and the
   only one that should ever hold a role able to run DDL).

   **What separates them is the credential, not the bytes.** This record's first draft
   claimed the migrate image was also the only one carrying `migrations/` and `db/`, and
   review found that false: `SystemController::Root` still calls `MigrateDatabase()`,
   whose `GetMigrationFiles()` opens a `FilesystemIterator` over `migrations/` that
   throws when the directory is absent, and PostgreSQL's baseline resolves to
   `db/pgsql/baseline` on the same path. The corpus therefore ships in every image until
   [plan 10](../plans/10-cold-start-statelessness.md) takes migration off the request
   path. The claim is downgraded rather than defended: the DDL is read-only text whose
   presence is not itself exploitable, and least privilege here is enforced by the role
   the workload holds.

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

*Preserved as written, in the present tense of the proposal. It refers to files this
record's rejection removed from the tree — `nix/README.md`, `nix/hashes.nix`, the flake —
which is the point: this is what was argued, not a summary written afterwards.*

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
none; taking the measurement was acceptance prerequisite 2, and it was never taken —
see *Why this was rejected*.

**New workloads are born into this.** A Go or TypeScript sidecar — the MCP one, plan 18's
publisher, ADR-0011's drainer — is a `buildGoModule`/`buildNpmPackage` away from an image
that has the same uid, the same labels, the same empty `/bin` and the same checks, for
roughly the cost of naming it. That is the payoff for deciding this before the family
exists rather than after, and it is the same argument ADR-0010 makes for the standard
itself.

## Why this was rejected

The decision above answers a question that had an answer by the time it was read. Taking
the case apart against what `master` now ships:

**"There is no production image" — gone.** This was the load-bearing argument, and the
`production` target refutes it directly.

**"It runs as root, and `COPY .` ships `.git`" — gone.** Both were sweep S25 items and
plan 10 closed both, with CI asserting them rather than a comment claiming them.

**"A read-only root filesystem needs work the Dockerfile cannot do" — gone,** and this is
the one this record was most confident about. Plan 10's image runs read-only against
exactly three writable paths, and verification 4 runs in CI against a live container.

**What Nix would still have added, honestly stated:** an image with no shell and no
package manager, a dependency graph pinned by hash rather than by `apt-get update`, an
allowlisted source tree rather than a `.dockerignore` denylist, and credential-separated
images. Those are real, and the first is the one this record would still argue for.

**Why they did not justify it.** They now buy a *replacement* for something that exists,
is CI-verified and was built deliberately — the production stage's own header chooses
"one container rather than two and one fewer thing to get wrong for a household-sized
deployment", which is a considered answer to the same question, not an omission. Against
that, the cost side is unchanged and the benefit side is much thinner than section
*Context* assumed. A second build system earning its place has to beat the first one, not
merely beat nothing; and this fork's rule is that nothing is adopted by momentum, which
cuts both ways — an unbuilt flake already written is not an argument for keeping it.

**The four acceptance prerequisites were never met and were never attempted.** They were:
that all three images build and the pod serves through podman; that image size and shell
presence be measured against the Debian image rather than asserted; that
`nix flake check`'s no-shell assertion actually hold; and that the two fixed-output
hashes reproduce on a second machine. Nothing here was ever built, so this record is
rejected on its case rather than on its evidence — which is the honest description and
not a criticism of the gates.

**What was kept.** The deploy tree in [`deploy/`](../../deploy/README.md), retargeted at
the `Dockerfile`'s production image. It answers [ADR-0010](0010-workload-standard.md)'s
fourth property — a workload exists in the deploy tree with probes and limits, or it does
not exist — and that property was never coupled to how the image gets built.
[Plan 20](../plans/20-deploy-tree.md) is what carries it.

**What would reopen this.** A workload in the family that is not PHP — the MCP sidecar,
plan 18's publisher, ADR-0011's drainer — has no Debian-and-Apache story to inherit, and
"how do we build a Go or TypeScript image" is a question this record answers well and the
`Dockerfile` does not answer at all. That is a new record when it arrives, arguing on the
new workload's terms, not this one revived.

## Open questions considered moot

These were live while the record was Proposed. They are kept because two of them outlive
it — 1 and 4 are questions the `Dockerfile`'s production image faces just as squarely,
and whoever publishes it first will have to answer them.

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
  by commit in `composer.lock`) measured against the working copy of 2026-09-03 — before
  PR #33 landed, which is what made the first two of those false. "Measured, not assumed"
  does not protect a measurement from going stale between taking it and acting on it, and
  that is the transferable lesson here rather than anything about Nix.
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
- **The scaffolding's first review (2026-09-03) found six defects, and they are the best
  available evidence for how much prerequisite 1 is worth.** Five were packaging or
  configuration errors that reading could in principle have caught and did not — an
  omitted `victual.openapi.json`, a stripped `migrations/`, a config stub declared but
  never installed in an image, nginx temporary paths whose parent no volume creates, and
  a document root with no index file behind a `try_files` that needs one. The sixth was
  a Kubernetes semantics error: a `tcpSocket` probe cannot reach a loopback-bound
  php-fpm, because the kubelet resolves the target to the pod IP. All six are fixed in
  the branch. None of them changes a decision in this record; the pattern they make —
  five of six invisible until something ran — is why prerequisite 1 is a gate.
