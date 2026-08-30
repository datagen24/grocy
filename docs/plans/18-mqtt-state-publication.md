# 18. MQTT state publication

**Goal:** Home Assistant knows what is in stock, what is due and what is expiring without
ever asking the server — so the pod can sleep for days and the household still sees
current information.
**Depends on:** [13](13-write-path-transactions.md), landed, which centralised the write
entrypoints and established that side effects fire *after* commit. Pairs with
[10](10-cold-start-statelessness.md): 18 is what makes 10's scale-to-zero survive contact
with an always-on consumer.
**Status:** draft for review. Exists because of [17](17-ecosystem-clients.md)'s Q2, which
asked which Python HTTP client the Home Assistant integration should use and was answered
"none of them".

## Today

Nothing in the tree publishes anything. The server is asked or it is idle.

- The only outbound network call the application makes on its own behalf is the label
  printer webhook, and its target is the `VICTUAL_LABEL_PRINTER_WEBHOOK` constant. The
  2026-08-29 [security sweep](../security-sweep.md) records this as the reason there is no
  SSRF surface in the tree: no user-configurable outbound URL exists.
- `GET /system/db-changed-time` exists so a client can poll cheaply before fetching. It is
  the best a pull design can do and it is still a pull design.
- Upstream's Home Assistant integration awaits one HTTP round trip per entity type,
  sequentially, every thirty seconds — thirteen serialized requests a minute, forever.
  [17](17-ecosystem-clients.md)'s Coupling 1 has the detail.

Against a pod that is meant to scale to zero, that last point is not a performance problem
but a contradiction. There is no poll interval that is both fresh enough to be useful and
long enough to let the pod sleep.

Three cluster services are always on and were not being used by any of this: an MQTT
broker, Redis and InfluxDB. The first one is the whole plan.

## The property this design rests on

**While the pod is asleep, the data cannot change except by the clock.**

Writes only happen when something is talking to the server, and the server is awake exactly
then. So a consumer does not need to ask what changed while nobody was looking — nothing
did. What it needs is:

1. to be told when something *is* written, by the server, which is awake at that moment;
2. to hold the last thing it was told, across its own restarts and arbitrarily long server
   absences;
3. to handle the passage of time itself, since that is the only thing that moves while the
   server is away.

Retained MQTT topics give (1) and (2) for free. (3) is a design constraint on what gets
published, and it is the part that is easy to get wrong.

## Proposed change

### Publish facts, never derived states

This is the rule the rest of the plan follows from. Publish `best_before_date`, not
"expiring soon". Publish `next_estimated_execution_time`, not "chore overdue".

A derived state is a function of the data *and the current time*. Publishing one means
something has to recompute it as time passes — which means something has to be awake at
midnight, which is precisely what this plan exists to avoid. A fact is a function of the
data alone: correct when published, still correct three days later, and re-derived by the
consumer whenever it likes.

Home Assistant is well suited to this and does not need help: a sensor published with
`device_class: timestamp` renders as relative time in the UI and compares against `now()`
in automations and templates, with no server involvement at all. "Due in 3 days" is a
presentation concern and it belongs on the always-on side of the boundary.

The same rule keeps the entity count sane. Rather than one entity per product, publish a
small set of summary sensors whose *state* is a count or a timestamp — both facts — and
carry the underlying rows as JSON attributes. Home Assistant templates derive whatever
views the household wants from the attributes, locally, and each new view costs nothing on
the server.

### Publish a whole snapshot, not deltas

Every publish is the complete ambient read model. At household scale this is kilobytes, and
it buys three properties that a delta stream does not have:

- **Self-healing.** A publish lost to a broker restart is repaired by the next write, with
  no reconciliation logic and nothing to detect.
- **No ordering to get wrong.** There is no sequence of deltas that can be applied out of
  order, because there are no deltas.
- **Out-of-band changes are caught.** A migration, a `bin/victual-db-import`, or someone
  in `psql` all get picked up by the next boot publish rather than silently diverging.

### Publish after commit, and on boot

Two triggers, both moments the server is provably awake and correct:

- **After commit**, on the write paths [13](13-write-path-transactions.md) already
  centralised. The label printer webhook established the precedent and the reasoning is the
  same: never inside the transaction, because a published state that was then rolled back
  is a lie that persists in a retained topic.
- **On boot**, once per cold start. This is what makes the design self-healing rather than
  merely eventually-consistent, and on this deployment "boot" is roughly "whenever anyone
  first touches it", which is exactly when a refresh is wanted.

### What Home Assistant actually supports

Checked against `home-assistant/core` on 2026-08-29 rather than assumed, because two of
these decide the shape of the plan.

- **Discovery covers the entity types this needs and one it does not.** `sensor`,
  `binary_sensor`, `button`, `number`, `select`, `text`, `event`, `image`, `date`,
  `datetime` and about twenty more are dispatched by the MQTT discovery flow
  (`homeassistant/components/mqtt/const.py`, `ENTITY_PLATFORMS`). **`todo` is not among
  them**, and there is no `mqtt/todo.py` in core — so a shopping list as a real Home
  Assistant to-do entity cannot come from MQTT and is exactly the interactive surface
  [17](17-ecosystem-clients.md) says needs a thin first-party integration. Everything in
  question 1's proposed set is a `sensor`.
- **Discovery topics are `<discovery_prefix>/<component>/[<node_id>/]<object_id>/config`**,
  with `homeassistant` as the default prefix.
- **Device-based discovery exists**: one config payload can declare many entities at once
  under a `components` key, with `device` and `origin` at the root and a `platform` and
  `unique_id` per entity (`mqtt/discovery.py` handles `CONF_COMPONENTS` explicitly). That
  is the right shape here — Victual is one device with a handful of sensors, and it makes
  the config a single retained topic rather than five. It requires a reasonably current
  Home Assistant; the corroboration for *which* release introduced it points at 2024.11
  but is second-hand, so confirm the floor before relying on it. The per-entity topic
  format above is the fallback and costs nothing but four extra topics.
- **An empty retained payload to a config topic removes the entity**, and in device-based
  mode propagates the removal to that device's other components. This is the retraction
  path verification 6 exercises.
- **An entity with no availability topic is always available.** This is the one that makes
  the design legal rather than merely convenient, and it is why the section above refuses
  a last will and testament: absence has to mean nothing, and omitting availability is how
  you say so.

### Writes go the other way, over HTTP

Consuming a product or ticking a chore from Home Assistant is an HTTP call that wakes the
pod. That is correct rather than a compromise: a write is user-initiated, so the cold start
sits behind a button press where it is invisible, and the household's own pattern —
shopping once or twice a week, bulk shopping every other week — puts that at a handful of
wakes a week rather than 2,880 polls a day.

This keeps the interesting property of the split: **the ambient path never wakes the
server, and the interactive path always may.**

Home Assistant's `rest_command` is a core integration that calls arbitrary HTTP endpoints
from automations and scripts, with a `headers` block for the API key and templating on the
URL and payload — so the simplest version of the write path needs no custom component at
all.

### Absence is normal and must not read as failure

The subtle one, and the place a conventional MQTT design would be wrong here.

The usual pattern is a last-will-and-testament message marking the device offline, so
entities show as unavailable when it stops publishing. That is exactly backwards for this
deployment: the server is *intentionally* absent most of the time, and its absence carries
no information about whether the data is still true. An entity that goes unavailable every
night because the pod scaled down is an entity the household learns to ignore.

So the ambient entities have no availability tracking: retained state stands until it is
replaced. If a freshness signal is wanted, it is a fact like any other — a `last_published`
timestamp sensor the household can look at or alert on — rather than a state that
invalidates everything else.

## Verification

A booted instance and a real broker, per the standard the rest of the roadmap is held to.
Lint is not verification, and neither is watching one message arrive.

1. **Retention actually retains.** Publish, then stop the pod entirely. Attach a *fresh*
   subscriber and confirm every topic arrives from the broker with the retain flag set and
   the correct payload. A subscriber that was already connected proves nothing.
2. **Home Assistant survives the pod being away.** With the pod scaled to zero, restart
   Home Assistant. Every entity must repopulate with its last known value. None may show
   `unavailable`, and none may show `unknown`.
3. **A write propagates without the consumer asking.** Change stock in the browser and
   confirm the Home Assistant entity updates within seconds, with the server's access log
   showing no request from Home Assistant. The log is the evidence, not the entity.
4. **Time moves without the server.** With the pod asleep, confirm a chore's due sensor
   still reads correctly relative to now, and that a template derived from the attributes
   (something expiring within five days) changes as the day rolls over. This is the check
   that the facts-not-derived-states rule was actually followed.
5. **The broker being down cannot hurt a write.** Stop the broker, then consume a product.
   The write must succeed, the response must not be visibly delayed beyond the configured
   timeout, and the failure must be logged once rather than raised.
6. **Retraction works.** Remove an entity from the published set and confirm the empty
   retained payload actually removes it from Home Assistant. Stale retained topics are this
   design's one operational wart and the household should have seen the cleanup work once.
7. **The assembler agrees on both engines.** Run it against SQLite and PostgreSQL over the
   same fixtures and diff the payloads through 14's `ValueComparison` normalisation. It
   reads the same views the UI does, so a divergence here is a divergence anywhere — this
   is the cheapest place to notice.
8. **The number that actually matters.** Over a week of ordinary household use, measure how
   long the pod stays scaled to zero, and confirm at the end of it that Home Assistant's
   entities are still correct. [17](17-ecosystem-clients.md)'s verification 3 named pod
   idle time as the real acceptance criterion for all of this; this is where it is
   collected.

## Sequencing

**Wave 1, alongside 10 and 01.** Disjoint from every other track: it adds a service and a
call at the end of write paths that 13 already centralised, and touches no file 10, 01 or
12 opens.

It wants to land *with* 10 rather than after it. 10's deliverable is a pod that scales to
zero; until 18 exists, the household's own Home Assistant is the reason it will not, so 10
would ship a capability nothing is allowed to use. Neither blocks the other's code.

Not a dependency of [02](02-mcp-endpoint.md), and deliberately not shared with it. An
assistant query is on-demand — it wakes the pod and asks, like any interactive client — so
the sidecar reads the API and has no use for retained state. Keeping them separate avoids
inventing a general "always-on read tier" that only one consumer needs.

**This plan answers [19](19-rbac.md)'s Q5 rather than waiting for it, and the reason is
the ordering.** 19 asks whether published state carries prices, offering three answers:
publish without the visibility-gated fields, publish per-role topics, or declare MQTT an
admin channel. It asks 18 to record which it chose — but 19 is proposed for wave 3 and
this is wave 1, so on the roadmap as written 18 merges two waves before the plan whose
question it defers to. That is not a deferral anyone could honour: a retained topic is
*retained*, so an unanswered question here is not a decision postponed, it is household
pricing sitting on the broker until something re-publishes without it.

**The answer is the first option: publish no price or cost field, on any topic, in any
version of this plan.** It costs nothing to decide now because it is what this plan's own
rules already say — the entity set is facts a wall tablet would show, and the security
notes below already exclude user records, notes fields and API keys on exactly the
"anything with broker access reads this without authenticating to Victual" reasoning that
applies to prices with more force, not less. Per-role topics are the option to revisit if
19's piece 2 ever gives the publisher a role to publish *as*; until then there is no reader
identity here to gate on, which is 19's own framing of why this channel is the hard case.
Concretely, the v1 entity set in Q1 carries none of `stock.price`, `stock_log.price`,
`products_average_price`, `product_price_history`, `products_last_purchased.price`,
`last_price`, `avg_price` or a recipe's `costs`, and adding an entity that would is a
change this paragraph has to be edited to permit.

## Open questions

1. **What is in v1's ambient entity set?** Everything else here is mechanism; this is the
   only part that is a judgement about what the household looks at. I lean to five: a stock
   summary (count, with rows and dates as attributes), a shopping list count, and three
   `timestamp` sensors for the next due chore, battery and task. That covers what a wall
   tablet or a phone glance is for, and anything else is a template away rather than a
   server change.

   > **Response:**

2. **Few sensors with rich attributes, or one entity per tracked thing?** I lean strongly
   to the former, as above. Per-product entities mean hundreds of entities, a discovery
   payload per product, and a retraction problem every time a product is deleted. The case
   against my lean is that attributes are second-class in Home Assistant — not recorded in
   long-term statistics, awkward in some UI cards — so anything the household wants
   *graphed* over time has to be a state rather than an attribute. That is an argument for
   promoting specific values to their own sensors as they prove wanted, not for starting
   there.

   > **Response:**

3. **Does a bulk write publish once or many times?** An import or a shopping trip is many
   commits in quick succession, and publishing a full snapshot per commit is wasteful even
   if it is harmless. I lean to marking the request dirty and publishing once as it ends,
   which also makes the publish trivially skippable for reads. The cost is that a
   long-running CLI operation publishes only at the end.

   > **Response:**

4. **Does the topic prefix carry a schema version?** `victual/…` versus `victual/v1/…`. I
   lean to no version. A version in the topic makes every consumer's configuration a
   migration when it changes, in exchange for a transition this household can perform by
   hand in five minutes; and retained topics need explicit retraction on rename either way,
   so the version does not save the cleanup it appears to.

   > **Response:**

5. **QoS, protocol version, and whether the connection is per-request.** I lean to **QoS 0**
   with retain, a fresh connect-publish-disconnect per publish, and MQTT 3.1.1.

   That is against the instinct to reach for QoS 1, and the library is what changed it.
   `php-mqtt/client` — MIT, `php: ^8.0`, v2.3.2 as of 2026-03-28, TLS and retain
   first-class, and the obvious choice on adoption and upkeep — supports MQTT 3, 3.1 and
   3.1.1 but **not 5.0**, and needs `loop()` to be running to process the acknowledgements
   QoS 1 and 2 depend on. Running an MQTT event loop at the end of a web request to collect
   an ACK is precisely the shape this design exists to avoid, and the library additionally
   has no cross-session persistence for QoS 1/2, so the guarantee would be weaker than it
   looks anyway.

   QoS 0 is the right level here for a reason that is about the design rather than the
   library: **the snapshot is idempotent and every publish supersedes the last**, so a lost
   message costs nothing that the next write or the next boot does not repair. Paying for
   delivery guarantees on a message that is about to be replaced is paying for the wrong
   thing. Once the broker has accepted a retained publish it is durable there, which is the
   part that actually matters.

   Note the one thing this gives up: a publish lost between the pod and the broker is
   invisible until the next one, and if the pod then sleeps for a day the household sees
   stale entities for a day. Question 3's per-request publish and the boot publish are what
   bound that, and verification 8 is where it would show up.

   > **Response:**

6. **Is the publish path allowed to be optional?** `MQTT_ENABLED` defaulting to false means
   the fork ships a feature the fork itself is the only user of, and every other
   installation carries dead config. Defaulting it to true means an unconfigured install
   tries to reach a broker that is not there and logs a failure per write. I lean to
   disabled by default with the failure logged once per process rather than once per
   publish, on the grounds that this is a household-specific integration and should look
   like one.

   > **Response:**

7. **Does anything publish to InfluxDB?** I lean to no, and to recording why: Home
   Assistant's own InfluxDB integration already writes entity history, and Home Assistant
   is the component that is always on. A pod awake an hour a week would write a sparse
   series full of holes that mean "nobody was shopping", not "nothing was true". The
   history belongs to the consumer that is always there to observe it.

   > **Response:**

## Effort

Small, and mostly not the MQTT part.

- The snapshot assembler is the real work, and it is the same work as an aggregate read
  endpoint: query the views the UI already uses, shape them into a payload. If something
  later wants that synchronously, it is the same function behind a route.
- The publisher is a service, a config block of the usual `Setting()` shape, and a call at
  two seams that already exist.
- The discovery payloads are a builder that runs once per publish and is mostly a literal.
- One new Composer dependency, `php-mqtt/client` (MIT, `php: ^8.0`, v2.3.2 2026-03-28,
  ~2.7M installs, actively kept current with PHP 8.4). The alternatives are a ReactPHP
  client, two coroutine-runtime clients built for Swoole and Workerman, and an
  unmaintained one last released in 2021 — none of which fit a synchronous connect,
  publish, disconnect inside a PHP request. See question 5 for what its QoS support
  implies.

The judgement in question 1 is what will take the time, and it is the kind that is settled
by living with the result rather than by design.

## Security notes

Recorded here rather than left to a later sweep, since this plan adds the first outbound
connection the application makes that is not the label printer.

- **The broker address is a configured constant**, exactly like
  `VICTUAL_LABEL_PRINTER_WEBHOOK`. Nothing in a request can influence where a publish goes,
  so the sweep's finding that no user-configurable outbound URL exists still holds after
  this plan. That property is worth protecting deliberately: if a future change ever lets a
  user name a broker, it needs S4's trusted-target treatment first.
- **Credentials are settings**, so they land in `data/config.php` or the environment and
  must never be added to `SystemApiController`'s `EXPOSED_SETTINGS` allowlist. The
  allowlist is an allowlist precisely so this stays a non-event.
- **A new dependency is a new supply chain.** `php-mqtt/client` is one package with no
  runtime dependencies of its own beyond PSR-3, which is the cheap end of this, but it is
  the first addition to `composer.json` this fork has made and the sweep's dependency
  review should pick it up next time round.
- **The retained payload is household data on a shared broker.** Anything with access to
  the broker can read the household's stock and chores without authenticating to Victual.
  That is a real widening of who can see this data, it is accepted here because the broker
  is on the same private cluster with its own credentials, and it is a reason not to
  publish anything that would not also be shown on a wall tablet — no user records, no
  notes fields, no API keys, **and no prices or costs**, which is
  [19](19-rbac.md)'s Q5 answered in this plan rather than deferred to a wave-3 one. See
  Sequencing for why 18 owes that answer instead of inheriting it.
- **A publish must never carry a failure into a committed write.** Short connect and
  publish timeouts, exceptions caught and logged, and the write path unaffected. The
  transaction is already closed by then, per 13.
