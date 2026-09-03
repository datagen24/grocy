# Deploying Victual

The manifests for the workloads this repository ships, and what they need to run.

The boundary is [ADR-0010](../docs/adr/0010-workload-standard.md)'s: **the fork declares
what its workloads need; the operator decides where they run.** So there is a pod
manifest here with probes, limits and a security context, and there is nothing here
about ingress classes, storage classes, secret management or DNS. PostgreSQL is not
here either — it is infrastructure the fork consumes, not a workload the fork ships.

**Nothing in this directory has been run yet, and the images it names have never been
built.** See [plan 20](../docs/plans/20-container-infrastructure.md), "Verification".

## What is here

| File | What it is |
|---|---|
| [`podman/victual.yaml`](podman/victual.yaml) | The pod: a migrate initContainer, php-fpm, nginx |

A k3s `Deployment`, `Service` and the ConfigMap/Secret shapes below as real manifests
arrive with plan 20's second piece. The pod manifest is a Kubernetes object rather than
a compose file on purpose: `podman kube play` gives the two serving containers a shared
network namespace exactly as Kubernetes does, so `127.0.0.1:9000` means the same thing on
a laptop and in the cluster, and there is one manifest to keep true instead of two.

## Bootstrapping on a Mac with podman

```sh
# 1. Build and load the images. On macOS this needs a Linux builder — see nix/README.md.
nix run .#load

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
environment variable, then the shipped default. So a container needs no `config.php`
with anything in it, and the image seeds a near-empty one purely because
`PrerequisiteChecker` refuses to start without the file existing. Both PHP images carry
that stub at `/etc/victual/config.php` (`nix/config-seed.nix`); the entrypoint copies it
into `$VICTUAL_DATAPATH` only when nothing is already there, so a deployment that would
rather mount a real `config.php` over that path can.

The minimum for a PostgreSQL deployment:

| Variable | Why |
|---|---|
| `VICTUAL_DB_DRIVER=pgsql` | The default is `sqlite`, and a container that quietly runs on SQLite against a file in a tmpfs is the worst available failure |
| `VICTUAL_DB_HOST`, `_PORT`, `_NAME`, `_USER` | Connection |
| `VICTUAL_DB_PASSWORD` | A Secret, never a ConfigMap |
| `VICTUAL_BASE_URL` | What the ingress publishes |
| `VICTUAL_MODE=production` | Any other value disables authentication and generates demo data |

`VICTUAL_DATAPATH` (`/data`) and `VICTUAL_VIEWCACHE_PATH` (the baked, read-only cache in
the image's store path) are set by the image and should be left alone.

**Three security-context settings are load-bearing**, and each has a failure that does
not say what it is:

- `runAsNonRoot: true` with `runAsUser: 65532` — the images already declare this in
  their OCI config, but a cluster policy that reads the manifest rather than the image
  wants it said here.
- `fsGroup: 65532` — without it the `emptyDir` volumes are `root:root 0755`, uid 65532
  cannot write to them, and the entrypoint exits with "could not create /data/viewcache".
  This is the single most common reason a correctly built non-root image fails to start.
- `readOnlyRootFilesystem: true` — the images are built to run this way. If it has to be
  turned off to get a green pod, something wrote where it should not have, and that is a
  finding rather than a workaround.

**Signals.** The images set `StopSignal=SIGQUIT`, which is php-fpm's graceful stop and
nginx's graceful shutdown. Kubernetes ignores the image's `StopSignal` and sends
`SIGTERM` unless the container spec sets `lifecycle.stopSignal`, so the manifest says it
again. On a cluster too old for that field the containers get `SIGTERM`, which both
treat as "stop now" — acceptable for stateless request handlers, but not the same thing,
and worth knowing before wondering where a truncated request went.

**Migrations run before anything serves.** The `migrate` initContainer runs
`bin/victual-migrate`, which is a no-op against an up-to-date database, takes a
`pg_advisory_lock` so concurrent runs are safe, and exits non-zero on failure — so a bad
migration keeps the pod from starting rather than letting it start and answer 503 to
everything. Since plan 10, `SchemaVersionMiddleware` is what makes that 503 rather than an
unpredictable failure, and it is also why the readiness probe below reports the gate.

**`VICTUAL_BASE_PATH` is a build input, not a runtime setting.** The baked route cache is
named after a fingerprint of `routes.php` and the base path, so an image built with one and
deployed under another does not misroute — Slim refuses to start, naming the cache
directory. Serving under a sub-path is a rebuild (`nix/app.nix`'s `basePath`), not an
environment variable.

**The app container's probe is an `exec`, and it must stay one.** php-fpm binds
`127.0.0.1:9000` so that nothing outside the pod's containers can reach it, and the kubelet
resolves a TCP probe's target to the *pod IP* — so a `tcpSocket` probe fails against a
healthy pool and restarts it on every failure threshold. Setting the probe's `host` to
`127.0.0.1` does not help either: that names the node's loopback. The probe runs
`/opt/victual/healthcheck` inside the container instead. What it cannot see — a pool
accepting connections whose workers are all wedged — is covered by the web container's
readiness probe, which renders `/login` through Blade.

## What this deployment does not yet do

Stated plainly because the gap is the point of tracking it:

- **One writable mount remains, and it is not the view cache.** Since plan 10 the cache
  is baked into the image and mounted read-only. What keeps `/data` is
  `PrerequisiteChecker::checkForConfigFile()`, which still refuses to start unless
  `config.php` exists inside `VICTUAL_DATAPATH` — a check that predates environment
  configuration and now has nothing left to check. Removing it is what deletes the
  entrypoint and this mount together.
- **File uploads still land on the filesystem.** `FilesService` writes to
  `$VICTUAL_DATAPATH/storage`, which in this pod is memory that disappears on restart.
  [Plan 01](../docs/plans/01-file-storage.md) moves it into the database. Until then a
  deployment that uses file attachments needs a real volume mounted there, and that is a
  deliberate, documented exception rather than an oversight.
- **Two production images exist in the tree.** The `Dockerfile`'s `production` target and
  these. That is deliberate and temporary:
  [ADR-0013](../docs/adr/0013-nix-built-container-images.md) retires the former when it is
  accepted, and its open question 5 is about when.
