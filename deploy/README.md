# Deploying Victual

The manifests for the workloads this repository ships, and what a running instance needs.

The boundary is [ADR-0010](../docs/adr/0010-workload-standard.md)'s: **the fork declares
what its workloads need; the operator decides where they run.** So there is a pod
manifest here with probes, limits and a security context, and there is nothing here about
ingress classes, storage classes, secret management or DNS. PostgreSQL is not here
either — it is infrastructure the fork consumes, not a workload the fork ships.

The image these target is the one the `Dockerfile`'s `production` stage builds and the
`images` CI job asserts: Apache with mod_php, `www-data`, port 8080, a view cache baked at
build time, and a read-only root filesystem.

**Nothing in this directory has been run yet.** See
[plan 20](../docs/plans/20-deploy-tree.md), "Verification", for what the first run has to
establish.

## What is here

| File | What it is |
|---|---|
| [`podman/victual.yaml`](podman/victual.yaml) | The pod: a config-seed initContainer, a migrate initContainer, and the serving container |

A k3s `Deployment`, `Service` and the ConfigMap/Secret shapes below as real manifests
arrive with plan 20's piece 2. The pod manifest is a Kubernetes object rather than a
compose file on purpose: `podman kube play` and k3s then read the same description, and
there is one manifest to keep true instead of two.

## Bootstrapping on a Mac with podman

```sh
# 1. Build the production image. The `images` CI job builds exactly this.
podman build --target production -t victual:production .

# 2. A throwaway PostgreSQL. Not a deployment artifact; a database to point at.
podman run -d --name victual-db \
  -e POSTGRES_USER=victual -e POSTGRES_PASSWORD=victual -e POSTGRES_DB=victual \
  -p 5432:5432 postgres:16

# 3. The configuration the pod expects.
podman kube play --configmap /dev/stdin deploy/podman/victual.yaml <<'YAML'
apiVersion: v1
kind: ConfigMap
metadata:
  name: victual-config
data:
  VICTUAL_MODE: production
  VICTUAL_DB_DRIVER: pgsql
  VICTUAL_DB_HOST: host.containers.internal
  VICTUAL_DB_PORT: "5432"
  VICTUAL_DB_NAME: victual
  VICTUAL_DB_USER: victual
  VICTUAL_BASE_URL: http://localhost:8080
YAML

# 4. http://localhost:8080/
```

The secret (`victual-secrets`) carries `VICTUAL_DB_PASSWORD` and nothing else in this
shape. Create it however your cluster creates secrets; for the podman bootstrap,
`podman kube play --secret` or a second ConfigMap will do — it is a laptop, and the
password is `victual`.

## What a running instance needs

**Configuration is environment variables.** `config-dist.php`'s `Setting()` resolves in
this order: a file in `$VICTUAL_DATAPATH/settingoverrides`, then a `VICTUAL_`-prefixed
environment variable, then the shipped default. So a container needs no `config.php` with
anything *in* it — but it does need the file to exist, which is what the `seed-config`
initContainer is for. See below.

The minimum for a PostgreSQL deployment:

| Variable | Why |
|---|---|
| `VICTUAL_DB_DRIVER=pgsql` | ADR-0008 makes this the only runtime engine, and the image refuses anything else |
| `VICTUAL_DB_HOST`, `_PORT`, `_NAME`, `_USER` | Connection |
| `VICTUAL_DB_PASSWORD` | A Secret, never a ConfigMap |
| `VICTUAL_BASE_URL` | What the ingress publishes |
| `VICTUAL_MODE=production` | Any other value disables authentication and generates demo data |

`VICTUAL_DATAPATH` (`/data`) and `VICTUAL_VIEWCACHE_PATH` (`/app/viewcache`) are set by
the image and should be left alone.

**`VICTUAL_BASE_PATH` is baked into the image, not configured at runtime.** The route
cache file is named after a fingerprint of `routes.php` and the base path, and the warmer
that ran at build time produced one name. Setting a different base path at runtime does
not silently misroute — Slim refuses to start, naming the cache directory — but it does
mean that serving under a sub-path is a `--build-arg VICTUAL_BASE_PATH=...` at build
time, not an environment variable at deploy time.

**Four things about the pod are load-bearing**, and each has a failure that does not say
what it is:

- **`fsGroup: 33`** — without it the `emptyDir` volumes are `root:root 0755`, uid 33
  cannot write to them, and the pod fails at `seed-config` with a message about
  `/data/config.php`. This is the single most common reason a correctly built non-root
  image fails to start. (33 is `www-data` on Debian; the CI job passes `uid=33,gid=33` to
  its tmpfs mounts for the same reason.)
- **The three writable mounts, and only those three** — `/data`, `/tmp` and
  `/var/run/apache2`. The `Dockerfile`'s own header is the authority on that list and
  says what writes to each. Adding a fourth means something writes where the image says
  nothing does, which is a finding rather than a mount.
- **`seed-config` runs before anything else.** `PrerequisiteChecker::checkForConfigFile`
  refuses to start unless `config.php` exists inside `VICTUAL_DATAPATH`, the image does
  not seed it, and `/data` is a fresh `emptyDir` on every start. The initContainer makes
  the same copy the CI job makes by hand. It uses `cp -n`, so a deployment that mounts a
  real `config.php` over `/data/config.php` keeps it and can drop the container entirely.
- **`migrate` runs before the serving container.** Since
  [plan 10](../docs/plans/10-cold-start-statelessness.md), nothing migrates inside a
  request and `SchemaVersionMiddleware` answers 503 until the database matches the code.
  `bin/victual-migrate` is a no-op against an up-to-date database, takes a
  `pg_advisory_lock` so concurrent runs are safe, and exits non-zero on failure — so a
  bad migration keeps the pod from starting rather than letting it start and fail per
  request.

**Probes.** Startup and liveness are `httpGet /robots.txt`, which Apache serves without
involving PHP, so they prove the server is up and its document root is mounted and
nothing more. Readiness is `httpGet /login`, which renders through Blade and touches the
database, so it also reports the schema gate: a pod whose migration has not finished is
correctly not ready rather than serving 503s to users.

**Signals.** Apache treats `SIGWINCH` as "stop accepting, finish what you have".
Kubernetes ignores an image's `StopSignal` and sends `SIGTERM` unless the container sets
`lifecycle.stopSignal`, so the manifest says it. On a cluster too old for that field the
pod gets `SIGTERM`, which Apache takes as "stop now" — acceptable for a stateless request
handler, but not the same thing.

## What this deployment does not yet do

Stated plainly because the gap is the point of tracking it:

- **File attachments need a real volume.** `FilesService` writes under
  `VICTUAL_DATAPATH`, which in this pod is memory that disappears on restart.
  [Plan 01](../docs/plans/01-file-storage.md) moves file storage into the database. Until
  then, a deployment that uses attachments mounts something durable at `/data` and
  accepts that it is a deliberate exception to everything above.
- **The image is not reproducible, and it carries a shell and a package manager.** It is
  built `FROM php:8.5-apache-bookworm` followed by `apt-get update`.
  [ADR-0013](../docs/adr/0013-nix-built-container-images.md) is the record of that
  argument being made and rejected; `readOnlyRootFilesystem` and dropped capabilities do
  not make it moot.
