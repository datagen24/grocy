# Deploying Victual

The manifests for the workloads this repository ships, and what they need to run.

The boundary is [ADR-0010](../docs/adr/0010-workload-standard.md)'s: **the fork declares
what its workloads need; the operator decides where they run.** So there is a pod
manifest here with probes, limits and a security context, and there is nothing here
about ingress classes, storage classes, secret management or DNS. PostgreSQL is not
here either — it is infrastructure the fork consumes, not a workload the fork ships.

**Nothing in this directory has been run yet.** See
[plan 20](../docs/plans/20-container-infrastructure.md), "Verification", for what the
first run has to establish.

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
`PrerequisiteChecker` refuses to start without the file existing.

The minimum for a PostgreSQL deployment:

| Variable | Why |
|---|---|
| `VICTUAL_DB_DRIVER=pgsql` | The default is `sqlite`, and a container that quietly runs on SQLite against a file in a tmpfs is the worst available failure |
| `VICTUAL_DB_HOST`, `_PORT`, `_NAME`, `_USER` | Connection |
| `VICTUAL_DB_PASSWORD` | A Secret, never a ConfigMap |
| `VICTUAL_BASE_URL` | What the ingress publishes |
| `VICTUAL_MODE=production` | Any other value disables authentication and generates demo data |

`VICTUAL_DATAPATH` is set by the image to `/data` and should be left alone.

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
`bin/victual-migrate`, which is a no-op against an up-to-date database and exits
non-zero on failure, so a bad migration keeps the pod from starting rather than letting
it start and fail per request. Note what plan 10 has not yet landed: there is **no
cross-process lock** in the migration runner today, so two pods starting simultaneously
against an empty database will race, and the loser fails. With `replicas: 1` and an
initContainer this does not arise; do not raise the replica count before plan 10's lock
exists.

## What this deployment does not yet do

Stated plainly because the gap is the point of tracking it:

- **The pod still needs a writable scratch directory.** `/data` is an in-memory
  `emptyDir`, not a persistent volume, so the pod has no durable state outside
  PostgreSQL — but it is not the "no writable path at all" that
  [plan 10](../docs/plans/10-cold-start-statelessness.md) is for. The application
  creates `data/viewcache` on boot and empties it on every cold start.
- **A cold-start request still gets a 302.** `app.php`'s version-hash marker never
  survives a restart, so the first request after a scale-up is answered with a redirect
  to `/` rather than with data. This is plan 10's first item and it is why
  scale-to-zero is not yet a thing to turn on.
- **File uploads still land on the filesystem.** `FilesService` writes to
  `$VICTUAL_DATAPATH/storage`, which in this pod is memory that disappears on restart.
  [Plan 01](../docs/plans/01-file-storage.md) moves it into the database. Until then, a
  deployment that uses file attachments needs a real volume mounted there, and that is a
  deliberate exception to everything above rather than an oversight.
