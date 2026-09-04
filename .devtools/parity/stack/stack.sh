#!/usr/bin/env bash
#
# The two stacks the parity suite compares, and everything they need.
#
# Sourced by ../bin/parity; not meant to be run directly, though every function here is
# safe to call on its own if you are debugging one container.
#
# **Why plain `podman run` and not `podman kube play`.** deploy/podman/victual.yaml is a
# Kubernetes Pod on purpose and that is the right shape for a *deployment*. It is the
# wrong shape for this, for one concrete reason: Kubernetes runs every initContainer to
# completion before any regular container starts, so a migrate initContainer in a pod that
# also contains PostgreSQL waits for a database that has not been started yet and never
# will be. Splitting infrastructure into a second pod would then need a shared network and
# would be two manifests describing one thing. This file is a test fixture, not a
# deployment artifact, and it says so by not pretending to be one.
#
# Everything runs on one podman network with DNS aliases, so `postgres`, `mosquitto` and
# `influxdb` mean the same thing here as they would in a compose file.

set -euo pipefail

# --- Names and knobs -------------------------------------------------------------------

PARITY_NETWORK="${PARITY_NETWORK:-victual-parity}"
PARITY_PREFIX="${PARITY_PREFIX:-parity}"

VICTUAL_IMAGE="${VICTUAL_IMAGE:-localhost/victual:parity}"

# Pinned to the fork's base version rather than :latest, and that is the whole argument of
# this suite. version.json says 4.6.0 / 2026-03-06 and so does the upstream image's own
# version.json, so a difference the suite reports is a difference *this fork* introduced —
# not one upstream introduced in a release the fork has not merged. Comparing against
# :latest would produce a report full of upstream's changelog.
UPSTREAM_IMAGE="${UPSTREAM_IMAGE:-docker.io/linuxserver/grocy:version-v4.6.0}"

POSTGRES_IMAGE="${POSTGRES_IMAGE:-docker.io/library/postgres:16}"
MOSQUITTO_IMAGE="${MOSQUITTO_IMAGE:-docker.io/library/eclipse-mosquitto:2}"
INFLUX_IMAGE="${INFLUX_IMAGE:-docker.io/library/influxdb:2.7}"

VICTUAL_PORT="${VICTUAL_PORT:-8080}"
UPSTREAM_PORT="${UPSTREAM_PORT:-8081}"
INFLUX_PORT="${INFLUX_PORT:-8086}"
MQTT_PORT="${MQTT_PORT:-1883}"

# Credentials for throwaway infrastructure, in the open for the same reason
# docker-compose.yml states: these databases exist for the length of a suite run, on a
# tmpfs, and every run creates them from nothing. Sweep finding S25 records exactly this
# shape as an Info-level observation. Changing them would move the secret, not remove it.
PGUSER_="${PGUSER_:-victual}"
PGPASSWORD_="${PGPASSWORD_:-victual}"
PGDATABASE_="${PGDATABASE_:-victual}"

INFLUX_TOKEN="${INFLUX_TOKEN:-victual-parity-token}"
INFLUX_ORG="${INFLUX_ORG:-victual}"
INFLUX_BUCKET="${INFLUX_BUCKET:-victual}"

ENGINE="${CONTAINER_ENGINE:-podman}"

c_pg="${PARITY_PREFIX}-postgres"
c_mqtt="${PARITY_PREFIX}-mosquitto"
c_influx="${PARITY_PREFIX}-influxdb"
c_victual="${PARITY_PREFIX}-victual"
c_upstream="${PARITY_PREFIX}-upstream"
v_victual_data="${PARITY_PREFIX}-victual-data"
v_upstream_data="${PARITY_PREFIX}-upstream-config"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m warn\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# --- Small helpers ---------------------------------------------------------------------

exists()  { "$ENGINE" container exists "$1" 2>/dev/null; }
running() { [ "$("$ENGINE" inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" = "true" ]; }

rm_container() { "$ENGINE" rm -f "$1" >/dev/null 2>&1 || true; }

# Waits for a command to succeed, with a deadline. Every readiness gate in this file goes
# through here so that a hung container produces a named timeout rather than a suite that
# sits forever — the failure mode that makes a laptop run unusable.
wait_for() {
	local what="$1" timeout="$2"; shift 2
	local deadline=$(( SECONDS + timeout ))
	while [ "$SECONDS" -lt "$deadline" ]; do
		if "$@" >/dev/null 2>&1; then
			return 0
		fi
		sleep 1
	done
	warn "last output from the readiness check for $what:"
	"$@" 2>&1 | tail -n 20 >&2 || true
	die "$what was not ready within ${timeout}s"
}

ensure_network() {
	"$ENGINE" network exists "$PARITY_NETWORK" 2>/dev/null \
		|| "$ENGINE" network create "$PARITY_NETWORK" >/dev/null
}

# --- Infrastructure --------------------------------------------------------------------

start_postgres() {
	rm_container "$c_pg"
	log "postgres"
	# tmpfs, no volume, for the reason docker-compose.yml gives: a stale volume is how a
	# passing suite starts lying.
	"$ENGINE" run -d --name "$c_pg" \
		--network "$PARITY_NETWORK" --network-alias postgres \
		--tmpfs /var/lib/postgresql/data \
		-e POSTGRES_USER="$PGUSER_" \
		-e POSTGRES_PASSWORD="$PGPASSWORD_" \
		-e POSTGRES_DB="$PGDATABASE_" \
		"$POSTGRES_IMAGE" >/dev/null
	wait_for postgres 90 "$ENGINE" exec "$c_pg" pg_isready -U "$PGUSER_" -d "$PGDATABASE_"
}

start_mosquitto() {
	rm_container "$c_mqtt"
	log "mosquitto"
	# mosquitto 2.x refuses non-local connections unless told otherwise, so the config is
	# not optional. It is passed on stdin rather than bind-mounted because a bind mount of
	# a single file from a macOS host into the podman VM is the kind of thing that works
	# until it does not.
	"$ENGINE" run -d --name "$c_mqtt" \
		--network "$PARITY_NETWORK" --network-alias mosquitto \
		-p "${MQTT_PORT}:1883" \
		"$MOSQUITTO_IMAGE" \
		sh -c 'printf "listener 1883 0.0.0.0\nallow_anonymous true\npersistence false\n" > /tmp/mosquitto.conf && exec mosquitto -c /tmp/mosquitto.conf' >/dev/null
	wait_for mosquitto 60 "$ENGINE" exec "$c_mqtt" sh -c "nc -z 127.0.0.1 1883"
}

start_influx() {
	rm_container "$c_influx"
	log "influxdb"
	"$ENGINE" run -d --name "$c_influx" \
		--network "$PARITY_NETWORK" --network-alias influxdb \
		-p "${INFLUX_PORT}:8086" \
		-e DOCKER_INFLUXDB_INIT_MODE=setup \
		-e DOCKER_INFLUXDB_INIT_USERNAME=victual \
		-e DOCKER_INFLUXDB_INIT_PASSWORD=victual-parity \
		-e DOCKER_INFLUXDB_INIT_ORG="$INFLUX_ORG" \
		-e DOCKER_INFLUXDB_INIT_BUCKET="$INFLUX_BUCKET" \
		-e DOCKER_INFLUXDB_INIT_ADMIN_TOKEN="$INFLUX_TOKEN" \
		"$INFLUX_IMAGE" >/dev/null
	wait_for influxdb 120 curl -fsS "http://127.0.0.1:${INFLUX_PORT}/health"
}

# --- Victual ---------------------------------------------------------------------------

# The image's /data is a mount point with nothing in it, and PrerequisiteChecker refuses to
# start without a config.php inside it. The file it wants can be empty: app.php loads
# config.php first and config-dist.php after it, so every setting still resolves — from the
# VICTUAL_* environment, which is where a container's configuration belongs and where S25
# says the credential has to come from. This seeds the same near-empty stub nix/config-seed.nix
# ships, for the same reason.
seed_victual_data() {
	"$ENGINE" volume rm -f "$v_victual_data" >/dev/null 2>&1 || true
	"$ENGINE" volume create "$v_victual_data" >/dev/null
	"$ENGINE" run --rm -v "$v_victual_data:/data" --user root "$VICTUAL_IMAGE" \
		sh -c 'printf "<?php\n" > /data/config.php && chown -R www-data:www-data /data' >/dev/null
}

victual_env_args() {
	printf '%s\n' \
		-e VICTUAL_MODE=production \
		-e VICTUAL_DB_DRIVER=pgsql \
		-e VICTUAL_DB_HOST=postgres \
		-e VICTUAL_DB_PORT=5432 \
		-e "VICTUAL_DB_NAME=$PGDATABASE_" \
		-e "VICTUAL_DB_USER=$PGUSER_" \
		-e "VICTUAL_DB_PASSWORD=$PGPASSWORD_" \
		-e "VICTUAL_BASE_URL=http://127.0.0.1:${VICTUAL_PORT}" \
		-e TZ=UTC \
		-e VICTUAL_MQTT_ENABLED=true \
		-e VICTUAL_MQTT_HOST=mosquitto \
		-e VICTUAL_MQTT_PORT=1883 \
		-e VICTUAL_MQTT_TOPIC_PREFIX=victual \
		-e VICTUAL_MQTT_CLIENT_ID=victual-parity \
		-e VICTUAL_INFLUXDB_ENABLED=true \
		-e VICTUAL_INFLUXDB_URL=http://influxdb:8086 \
		-e "VICTUAL_INFLUXDB_TOKEN=$INFLUX_TOKEN" \
		-e "VICTUAL_INFLUXDB_ORG=$INFLUX_ORG" \
		-e "VICTUAL_INFLUXDB_BUCKET=$INFLUX_BUCKET"
}

# Migrations are a step, not a side effect of the first request — that is what plan 10
# bought and what SchemaVersionMiddleware enforces. Running it here rather than as a pod
# initContainer is the ordering point this whole file exists for: PostgreSQL is already up.
migrate_victual() {
	log "victual: migrate"
	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_env_args)
	"$ENGINE" run --rm --network "$PARITY_NETWORK" \
		-v "$v_victual_data:/data" \
		"${args[@]}" \
		"$VICTUAL_IMAGE" php bin/victual-migrate \
		|| die "victual migration failed"
}

start_victual() {
	rm_container "$c_victual"
	log "victual"
	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_env_args)
	"$ENGINE" run -d --name "$c_victual" \
		--network "$PARITY_NETWORK" --network-alias victual \
		-p "${VICTUAL_PORT}:8080" \
		-v "$v_victual_data:/data" \
		"${args[@]}" \
		"$VICTUAL_IMAGE" >/dev/null
	# /login rather than / — it renders through Blade, touches the database and passes the
	# schema gate, so a 200 here means the whole stack answered, not that a socket is open.
	wait_for victual 120 curl -fsS -o /dev/null "http://127.0.0.1:${VICTUAL_PORT}/login"

	# The same assertive gate the upstream side has, for the same reason: the suite's first
	# action is a login, so the stack is not "up" until one succeeds.
	wait_for "victual login" 90 sh -c \
		"test \"\$(curl -s -o /dev/null -w '%{http_code}' -X POST \
			-d 'username=admin&password=admin' \
			'http://127.0.0.1:${VICTUAL_PORT}/login')\" = 302"
}

# --- Upstream grocy --------------------------------------------------------------------

start_upstream() {
	rm_container "$c_upstream"
	"$ENGINE" volume rm -f "$v_upstream_data" >/dev/null 2>&1 || true
	"$ENGINE" volume create "$v_upstream_data" >/dev/null
	log "upstream grocy"
	# SQLite, which is what upstream is: ADR-0001 put PostgreSQL *alongside* it and
	# ADR-0008 retired it here, but upstream never had it. Comparing the fork on its engine
	# against upstream on its own is the comparison worth making — anything else would be
	# testing a configuration nobody runs.
	"$ENGINE" run -d --name "$c_upstream" \
		--network "$PARITY_NETWORK" --network-alias upstream \
		-p "${UPSTREAM_PORT}:80" \
		-v "$v_upstream_data:/config" \
		-e PUID=1000 -e PGID=1000 -e TZ=UTC \
		-e GROCY_MODE=production \
		-e "GROCY_BASE_URL=http://127.0.0.1:${UPSTREAM_PORT}" \
		"$UPSTREAM_IMAGE" >/dev/null
	# **`/`, not `/login`, and this is the ordering trap of the whole file.** Upstream grocy
	# has no migrate command: `SystemController::Root` is what calls `MigrateDatabase()`, so
	# the schema is created by the first request to `/` and by nothing else. Waiting on
	# `/login` returns 200 against an *empty* database — the login form renders without
	# touching a table — and the first POST to it then dies with
	# "no such table: migrations", which is exactly what happened here before this line said
	# `/`. The fork does not have this problem, because plan 10 made migrating a step
	# (`bin/victual-migrate`) rather than a side effect of whoever knocks first.
	# **`-L` is load-bearing.** Unauthenticated `/` answers 302 to `/login`, and `curl -f`
	# treats a 302 as success — so without `-L` this gate passes on a redirect that never
	# reached the route, the schema is never created, and the failure surfaces later as
	# "login failed (HTTP 500)" from the harness. Following the redirect is what makes the
	# request actually execute SystemController::Root.
	wait_for upstream 180 curl -fsSL -o /dev/null "http://127.0.0.1:${UPSTREAM_PORT}/"

	# And then assert the thing the suite actually needs rather than a proxy for it: that
	# admin/admin logs in. A 302 is a successful login; the form re-renders with 200 when
	# the credentials are refused, and answers 500 when the schema is not there — so this
	# one check distinguishes all three.
	wait_for "upstream login" 90 sh -c \
		"test \"\$(curl -s -o /dev/null -w '%{http_code}' -X POST \
			-d 'username=admin&password=admin' \
			'http://127.0.0.1:${UPSTREAM_PORT}/login')\" = 302"
}

# --- Lifecycle -------------------------------------------------------------------------

stack_up() {
	ensure_network
	start_postgres
	start_mosquitto
	start_influx
	seed_victual_data
	migrate_victual
	start_victual
	start_upstream
	log "up:  victual http://127.0.0.1:${VICTUAL_PORT}   upstream http://127.0.0.1:${UPSTREAM_PORT}"
}

stack_down() {
	log "tearing down"
	for c in "$c_victual" "$c_upstream" "$c_influx" "$c_mqtt" "$c_pg"; do
		rm_container "$c"
	done
	"$ENGINE" volume rm -f "$v_victual_data" "$v_upstream_data" >/dev/null 2>&1 || true
	"$ENGINE" network rm -f "$PARITY_NETWORK" >/dev/null 2>&1 || true
}

stack_status() {
	printf '%-22s %-10s %s\n' CONTAINER STATE IMAGE
	for c in "$c_pg" "$c_mqtt" "$c_influx" "$c_victual" "$c_upstream"; do
		if exists "$c"; then
			printf '%-22s %-10s %s\n' "$c" \
				"$("$ENGINE" inspect -f '{{.State.Status}}' "$c")" \
				"$("$ENGINE" inspect -f '{{.ImageName}}' "$c")"
		else
			printf '%-22s %-10s %s\n' "$c" absent -
		fi
	done
}

# A cold start is a first-class check, not a convenience: plan 10 is about what happens on
# the first request after a scale-up, and the only way to test it is to have a first
# request. This throws both databases away and rebuilds them from nothing.
stack_reset() {
	rm_container "$c_victual"
	rm_container "$c_upstream"
	start_postgres
	seed_victual_data
	migrate_victual
	start_victual
	start_upstream
}
