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

# 3. The pod, with the ConfigMap and Secret it references appended to the same stream.
{ cat deploy/podman/victual.yaml; cat <<'YAML'
---
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
  VICTUAL_FILE_STORAGE: database
  VICTUAL_BASE_URL: http://localhost:8080
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-secrets
data:
  # base64, because that is what a Kubernetes Secret's `data` holds. "victual".
  VICTUAL_DB_PASSWORD: dmljdHVhbA==
YAML
} | podman kube play -

# 4. http://localhost:8080/
```

**Both the ConfigMap and the Secret must be in the stream, and the Secret must be a
Kubernetes `Secret`.** This is worth stating plainly because two plausible-looking
alternatives both fail:

- `podman kube play --secret …` takes a *podman* secret (`podman secret create`), which
  is not the same object. Passing one fails with
  `secret victual-secrets is not valid JSON/YAML: cannot unmarshal string into Go value of type v1.Secret`.
- `--configmap /dev/stdin` can only supply the ConfigMap, so the manifest is still
  rejected with `no secret with name or id "victual-secrets"`.

Concatenating the documents, as above, is the form that works. `VICTUAL_FILE_STORAGE`
belongs in the ConfigMap rather than being optional: this pod mounts nothing writable, so
the default `filesystem` backend would fail on the first upload.

## What a running instance needs

**Configuration is environment variables.** `config-dist.php`'s `Setting()` resolves in
this order: a file in `$VICTUAL_DATAPATH/settingoverrides`, then a `VICTUAL_`-prefixed
environment variable, then the shipped default. So a container needs no `config.php` at
all, and since 2026-09-04 it does not have one: `app.php` loads the file when it is there
and carries on when it is not.

That is a change from what this file said until then. The images used to seed a near-empty
`config.php` into `$VICTUAL_DATAPATH` from `/etc/victual/config.php`, through an
entrypoint, purely because `PrerequisiteChecker` refused to start without the file
existing. The seed, the entrypoint, the writable data directory they needed and the
`pcntl` extension the entrypoint used are all gone together — see issue #49, which is what
that arrangement cost. A deployment that would rather supply a real `config.php` still
can: mount one at `$VICTUAL_DATAPATH/config.php`, which is an empty read-only directory in
the image.

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
- `fsGroup: 65532` — an `emptyDir` volume is otherwise `root:root 0755` and uid 65532
  cannot write to it, which is the single most common reason a correctly built non-root
  image fails to start. **`podman kube play` does not honour it** (issue #49), so this pod
  is built not to need it: nothing writable is mounted except the two `/tmp` tmpfs
  volumes. It stays in the manifest because a real cluster does honour it and the day
  something writable comes back is not the day to rediscover this.
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

- ~~**One writable mount remains, and it is not the view cache.**~~ **Done, 2026-09-04.**
  It named `PrerequisiteChecker::checkForConfigFile()` as the only thing keeping `/data`,
  and said removing it "deletes the entrypoint and this mount together". That is what
  happened, and issue #49 is why it happened when it did rather than eventually: podman
  does not honour `fsGroup`, so the mount the check required could not be written to on a
  laptop and the pod would not start. The two writable mounts left are `/tmp`, one per
  serving container.
- ~~**File uploads still land on the filesystem.**~~ **Done.** Plan 01 landed, and this
  deployment sets `VICTUAL_FILE_STORAGE=database` rather than treating it as an option —
  with nothing writable mounted, the `filesystem` backend has nowhere to write. A
  deployment that wants files on a volume must mount one and say so.
- **Two production images exist in the tree.** The `Dockerfile`'s `production` target and
  these. That is deliberate and temporary:
  [ADR-0013](../docs/adr/0013-nix-built-container-images.md) retires the former when it is
  accepted, and its open question 5 is about when.
