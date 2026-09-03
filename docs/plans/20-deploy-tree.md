# 20. The deploy tree

**Goal:** Manifests in this repository that describe how to run the images it builds —
what a running instance needs, with probes, limits and a security context — so that
[ADR-0010](../adr/0010-workload-standard.md)'s fourth property is satisfiable rather than
aspirational.
**Depends on:** the `Dockerfile`'s `production` target, which
[10](10-cold-start-statelessness.md) landed. Nothing else.
**Status:** draft. `deploy/podman/victual.yaml` landed with this plan and **has not been
run**; piece 1 is running it.

## How this plan got its scope

It started as something else, and the record of that is
[ADR-0013](../adr/0013-nix-built-container-images.md), which is **Rejected**.

The original plan proposed building production images with Nix — three of them, from
`scratch`, with no shell and no package manager — and the deploy tree was one item in a
list of six. It was written against a repository whose only image was, by its own
header, a development one that ran as root. While it was in review,
[10](10-cold-start-statelessness.md) landed on `master` with a `production` target in the
same `Dockerfile`: Apache with mod_php, non-root `www-data`, a baked view cache, a
read-only root filesystem, a `.dockerignore`, and an `images` CI job asserting each of
those against the built artifact.

That refuted the load-bearing part of ADR-0013's case within hours of it being written.
The record was rejected rather than re-argued, and the reasoning is in it — including
what Nix would still have added, which is not nothing. What survived is the part that was
never coupled to how the image gets built: the manifests. This plan is that part, and
nothing else.

Two things from the Nix branch are worth carrying forward as facts rather than as code,
because they were established by review and cost something to find:

- **The first review of that branch found six defects, five of them invisible to
  reading.** Omitted runtime files, a stripped directory the request path still reads, a
  file declared but never installed, and two configuration errors that only appear when
  something runs. The transferable lesson is the one this plan's verification section is
  built on: a manifest that has not been applied is a guess with YAML syntax.
- **A `tcpSocket` probe cannot reach a loopback-bound listener.** The kubelet resolves a
  TCP probe's target to the pod IP, and setting the probe's `host` to `127.0.0.1` names
  the node's loopback rather than the container's. It does not bite the current design —
  the production image serves HTTP on 8080 and every probe is an `httpGet` — but it is
  the kind of thing that is obvious once and expensive twice.

## Today

`master` builds a production image and CI asserts four things about it: it does not run
as root, its view cache is baked and unwritable by the serving user, it carries no `.git`
and no `data/`, and it serves with a read-only root filesystem against exactly three
writable mounts.

What does not exist is any statement of **how to run it**. Which environment variables,
which mounts and with what ownership, what has to happen before the first request, what
the probes should look at, what a `SIGTERM` costs. All of that is currently knowledge
that lives in the `Dockerfile`'s comments and in the CI job's shell, and knowledge is
what a deploy tree is for turning into a file.

Three details from the image decide the whole manifest, and each of them is a thing a
deployment gets wrong silently:

- **`/data` must contain `config.php` before the first request.** The image creates the
  directory and does not seed the file; `PrerequisiteChecker::checkForConfigFile` refuses
  to start without it. The CI job copies `config-dist.php` into place by hand before it
  can serve anything, which is a step, not a detail.
- **Migrations no longer happen inside a request.** Since 10, `SchemaVersionMiddleware`
  answers 503 until the database matches the code. Something has to run
  `bin/victual-migrate` first, and if it does not, the pod comes up and answers 503 to
  everything.
- **`VICTUAL_BASE_PATH` is baked into the image.** The route cache file is named after a
  fingerprint of `routes.php` and the base path, so a deployment that sets a base path
  the image was not built with does not silently misroute — Slim refuses to start. That
  is the right failure, and it is one an operator should meet in a README rather than in
  a crash loop.

## What landed with this plan

`deploy/podman/victual.yaml`: a Kubernetes pod with two initContainers — `seed-config`,
which is the `cp` the CI job makes, and `migrate` — then the serving container. Three
in-memory `emptyDir` volumes matching exactly the three writable paths the `Dockerfile`
names. `runAsUser: 33` with `fsGroup: 33`, `readOnlyRootFilesystem`, all capabilities
dropped, `seccompProfile: RuntimeDefault`. Requests and limits on every container. An
`httpGet` startup and liveness probe on `/robots.txt`, which Apache serves without PHP,
and a readiness probe on `/login`, which goes through Blade and the database and
therefore reports the schema gate as well.

A Kubernetes object rather than a compose file, deliberately: `podman kube play` on a
laptop and k3s in the cluster then read the same description, and there is one manifest
to keep true instead of two.

`deploy/README.md`: what a running instance needs, why `fsGroup` is load-bearing, and the
bootstrap sequence against a throwaway PostgreSQL.

## Proposed change

### Piece 1 — run it

`podman kube play deploy/podman/victual.yaml` against a throwaway PostgreSQL, on the
maintainer's Mac. This is the whole of piece 1 and it is not a formality: see *How this
plan got its scope* on what unrun manifests are worth.

Expect the failures to be in the seams rather than the design. In descending order of
likelihood: `podman kube play`'s handling of `initContainers` ordering or of
`configMapRef`; `fsGroup` semantics under podman's rootless user-namespace mapping, which
is not Kubernetes'; and `lifecycle.stopSignal`, which is recent enough that older podman
will reject or ignore the field.

### Piece 2 — the k3s manifests

A `Deployment`, a `Service`, and the ConfigMap/Secret shapes `deploy/README.md` currently
describes in prose. After piece 1, for the reason above.

`replicas` stays at 1 until there is a reason it should not. 10's migration lock makes
concurrent `bin/victual-migrate` runs safe, so the constraint is no longer correctness —
it is that nothing has measured whether a second replica helps a household-sized
instance.

### Piece 3 — the rest of the family

The MCP sidecar ([02](02-mcp-endpoint.md)), [18](18-mqtt-state-publication.md)'s
publisher and [ADR-0011](../adr/0011-label-namespace.md)'s print drainer each need a
manifest here, and each is a workload ADR-0010's four properties apply to. Note what
ADR-0013's rejection leaves open for them: none of them is PHP, so none inherits the
`Dockerfile`'s Debian-and-Apache answer, and "what builds a Go or TypeScript image here"
is a live question with no recorded answer. That is a new ADR when the first one arrives.

## What this cannot fix

- **File attachments still need a real volume.** `FilesService` writes under
  `VICTUAL_DATAPATH`, which in this pod is memory that vanishes on restart.
  [01](01-file-storage.md) moves file storage into the database; until it lands, a
  deployment that uses attachments mounts something durable at `/data` and accepts that
  it is a deliberate exception to everything else here.
- **The image is not reproducible.** It is built `FROM php:8.5-apache-bookworm` followed
  by `apt-get update`, so two builds of the same commit are two different dependency
  graphs. ADR-0013 records the argument and its rejection; nothing in this plan changes
  it, and a deployment that needs a bit-exact artifact needs a different record than
  either of them.
- **The image carries a shell and a package manager.** Same record, same rejection. It is
  worth restating here only because the manifest's `readOnlyRootFilesystem` and dropped
  capabilities are sometimes read as making that moot, and they do not.

## Verification

1. **The pod serves.** `podman kube play` against a throwaway PostgreSQL: both
   initContainers exit 0 in order, `/login` renders, and `GET /api/stock` with an API key
   returns JSON.
2. **The schema gate is visible.** Delete the `migrate` initContainer, apply, and confirm
   the pod comes up and answers 503 rather than appearing healthy — and that the
   readiness probe therefore keeps it out of service. This is the check that proves the
   readiness probe is on the right URL.
3. **The read-only root filesystem holds under real use.** Browse every top-level page and
   perform one create, one edit and one delete through the API, plus one thumbnail
   request, which is the path that needs `/tmp`. Any `EROFS` is a finding to fix, not to
   work around. CI's `images` job covers a narrower version of this against the image;
   this is the same check against the manifest's three mounts.
4. **Ownership is right.** Confirm uid 33 can write all three volumes. Without `fsGroup`
   an `emptyDir` is `root:root 0755` and the pod fails at `seed-config` with a message
   about `/data/config.php`, which is the single most common way a correct image fails to
   start.
5. **The base-path failure is loud.** Set `VICTUAL_BASE_PATH` to something the image was
   not built with and confirm Slim refuses to start, naming the cache directory, rather
   than serving 404s. This is verifying plan 10's design, not this plan's — but this is
   where an operator meets it.
6. **Signals.** `podman kube down` drains in-flight requests rather than truncating them.
   Then repeat with `SIGTERM` to see what a cluster too old for `lifecycle.stopSignal`
   costs, so it is a known quantity rather than a suspicion.

## Sequencing

**Independent of everything.** It adds no PHP, touches no schema, and blocks nothing. It
wants only the production image, which has landed.

It pairs with [01](01-file-storage.md) in one direction: 01 is what makes `/data` an
`emptyDir` honestly rather than by omission, and until it lands this plan's manifest
carries the caveat above rather than a solution.

## Effort

Small, and almost entirely in piece 1. The manifest is written; running it is an
afternoon of podman-versus-Kubernetes seams. Piece 2 is a mechanical translation once
piece 1 has proved the shape. Piece 3 is per-workload and not this plan's to schedule.
