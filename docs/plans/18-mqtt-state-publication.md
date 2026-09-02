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

## Client impact

**No HTTP client sees anything change; this plan adds a channel rather than altering one.**
No route, status code, header or response field moves, so
[14](14-contract-and-regression-scaffolding.md)'s snapshot is unaffected and neither is
anything [17](17-ecosystem-clients.md) tracks over REST.

The impact is that a *new* class of consumer appears with no authentication to Victual at
all — anything holding broker credentials. That is the reason the security notes below
draw the line where they do, and the reason [19](19-rbac.md)'s Q5 is carried here as
question 8 rather than inherited from that plan: what goes on a retained topic is a
visibility decision made at publish time, by this plan, for every subscriber at once, and
there is no reader identity to gate it on afterwards.

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

**This plan owns [19](19-rbac.md)'s Q5 rather than deferring to it, and the reason is the
ordering.** 19 asks whether published state carries prices, offering three answers: publish
without the visibility-gated fields, publish per-role topics, or declare MQTT an admin
channel. It asks 18 to record which it chose — but 19's piece 2 is wave 5 and this is wave
1, so on the roadmap as written 18 merges four waves before the plan whose question it
defers to. That is not a deferral anyone could honour: a retained topic is *retained*, so
an unanswered question here is not a decision postponed, it is household pricing sitting on
the broker until something re-publishes without it.

It is therefore **question 8 below**, answered here or not at all — and, like every other
question in this plan, it carries a lean rather than a settled answer until it has a
Response. The lean is the first option: publish no price or cost field, on any topic. It
costs little to lean that way because it is what this plan's own rules already say — the
entity set is facts a wall tablet would show, and the security notes below already exclude
user records, notes fields and API keys on exactly the "anything with broker access reads
this without authenticating to Victual" reasoning that applies to prices with more force,
not less. Per-role topics are the option to revisit if 19's piece 2 ever gives the
publisher a role to publish *as*; until then there is no reader identity here to gate on,
which is 19's own framing of why this channel is the hard case.

## Open questions

1. **What is in v1's ambient entity set?** Everything else here is mechanism; this is the
   only part that is a judgement about what the household looks at. I lean to five: a stock
   summary (count, with rows and dates as attributes), a shopping list count, and three
   `timestamp` sensors for the next due chore, battery and task. That covers what a wall
   tablet or a phone glance is for, and anything else is a template away rather than a
   server change.

   > **Response:** The lean's five, plus two promoted counts — products due soon and
   > products expired — for seven ambient entities. The two counts are the classic
   > glanceable numbers a wall tablet exists to show, they are already computed by the
   > views the stock summary reads, and promoting them now costs two discovery payloads
   > rather than the household rediscovering question 2's promote-on-demand path on day
   > one. Everything else stays a template away, as the lean says. (2026-08-31.)

2. **Few sensors with rich attributes, or one entity per tracked thing?** I lean strongly
   to the former, as above. Per-product entities mean hundreds of entities, a discovery
   payload per product, and a retraction problem every time a product is deleted. The case
   against my lean is that attributes are second-class in Home Assistant — not recorded in
   long-term statistics, awkward in some UI cards — so anything the household wants
   *graphed* over time has to be a state rather than an attribute. That is an argument for
   promoting specific values to their own sensors as they prove wanted, not for starting
   there.

   > **Response:** The summary set stands as the ambient default, and the answer to
   > few-versus-many is *both, deliberately*: per-product entities exist, but only for
   > products the household opts in — a per-product flag, so the Home Assistant entity
   > count is chosen rather than inherited from the catalogue. The lean's retraction
   > problem is handled rather than avoided: deleting a product, deactivating it, or
   > clearing its flag publishes an empty retained payload to its discovery and state
   > topics, on the same after-commit seam as every other publish. This is the lean's own
   > escape hatch — "promoting specific values to their own sensors as they prove
   > wanted" — with the promotion made a flag the household sets rather than a server
   > change per product. What stays rejected is the maximal reading: an entity per
   > product for the whole catalogue, which is hundreds of discovery payloads and the
   > retraction problem at scale for entities mostly nothing looks at. (2026-08-31.)

3. **Does a bulk write publish once or many times?** An import or a shopping trip is many
   commits in quick succession, and publishing a full snapshot per commit is wasteful even
   if it is harmless. I lean to marking the request dirty and publishing once as it ends,
   which also makes the publish trivially skippable for reads. The cost is that a
   long-running CLI operation publishes only at the end.

   > **Response:** As leaned — mark the request dirty on the first committed write,
   > publish once as the request ends. The tree already has the pattern this hangs off:
   > `DatabaseService::InTransaction` is reentrancy-aware (only the outermost call
   > commits), and `StockService` already collects label-webhook payloads during a
   > transaction and fires them after commit — the dirty flag is that pattern carrying
   > one bit. Reads never publish. A CLI operation publishing only at the end is correct
   > rather than a cost: the importer and `bin/victual-migrate` both end. (2026-08-31.)

4. **Does the topic prefix carry a schema version?** `victual/…` versus `victual/v1/…`. I
   lean to no version. A version in the topic makes every consumer's configuration a
   migration when it changes, in exchange for a transition this household can perform by
   hand in five minutes; and retained topics need explicit retraction on rename either way,
   so the version does not save the cleanup it appears to.

   > **Response:** No version in the prefix, as leaned. One consequence of question 2's
   > opt-in entities is noted rather than feared: per-product topics raise the
   > payload-evolution surface, but a payload gaining an attribute is not a topic rename,
   > and the retraction mechanism question 2 commits to is the same one a rename would
   > need anyway. Revisit only if a change ever requires incompatible payloads on the
   > same topic. (2026-08-31.)

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

   > **Response:** As leaned, entirely: QoS 0 with retain, connect-publish-disconnect
   > per publish, MQTT 3.1.1, `php-mqtt/client`. The library argument and the design
   > argument agree, and the design one is load-bearing: every publish supersedes the
   > last, so delivery guarantees on a message about to be replaced buy nothing the boot
   > publish does not already bound. (2026-08-31.)

6. **Is the publish path allowed to be optional?** `MQTT_ENABLED` defaulting to false means
   the fork ships a feature the fork itself is the only user of, and every other
   installation carries dead config. Defaulting it to true means an unconfigured install
   tries to reach a broker that is not there and logs a failure per write. I lean to
   disabled by default with the failure logged once per process rather than once per
   publish, on the grounds that this is a household-specific integration and should look
   like one.

   > **Response:** As leaned: `MQTT_ENABLED` defaults false, an unreachable broker logs
   > once per process rather than once per publish, and a failed publish never surfaces
   > into the write that triggered it — the security notes' last bullet already requires
   > that. A household-specific integration should look like one. (2026-08-31.)

7. **Does anything publish to InfluxDB?** I lean to no, and to recording why: Home
   Assistant's own InfluxDB integration already writes entity history, and Home Assistant
   is the component that is always on. A pod awake an hour a week would write a sparse
   series full of holes that mean "nobody was shopping", not "nothing was true". The
   history belongs to the consumer that is always there to observe it.

   > **Response:** Yes — scoped, and not for the reason the lean feared. The
   > sparse-series objection is right about *sampled state* and does not apply to
   > *events*: a point written when a purchase or a price change commits produces a
   > series whose gaps mean "no purchases", which is true rather than an artifact of the
   > pod sleeping. So the server writes price and valuation events directly to InfluxDB
   > on the same after-commit seam — per product: price paid, stock value — and that
   > channel, not MQTT, is where "how has spending shifted over time" is answered.
   > InfluxDB has its own credentials and is queried rather than broadcast, so the
   > wall-tablet test never applies to it; this is exactly the deliberate add question 8
   > says a household wanting pricing history should have to make. Home Assistant's own
   > InfluxDB integration still records entity history for the entities that exist, and
   > nothing pricing-shaped ever becomes a Home Assistant entity. What stays rejected is
   > the lean's actual target: sampling stock state into InfluxDB from a pod that is
   > mostly asleep. (2026-08-31.)


8. **Does the ambient payload carry prices?** [19](19-rbac.md)'s Q5 asks this plan to
   record the choice and offers three: publish without the fields it annotates
   `x-visibility`, publish per-role topics, or declare MQTT an admin channel. The first is
   the only one consistent with what is already here — per-role topics multiply the single
   discovery payload question 2 argues for, and "admin channel" is a claim about
   subscribers this design cannot check. I lean to **publish no prices**, with the
   assembler dropping every `x-visibility` field rather than maintaining a second list, so
   that MQTT never becomes the channel that answers a question the API refuses. The cost
   is that a household wanting a spending sensor has to add one deliberately, which is the
   right default for a payload anything on the broker can read. Note this is a constraint
   on the assembler rather than on the entity set: question 1 can keep the stock summary
   and still drop three columns from it.

   > **Response:** As leaned — no price or cost field, on any topic, with the assembler
   > dropping every `x-visibility` field rather than maintaining a second list, so MQTT
   > never becomes the channel that answers a question the API refuses. Taken as written
   > and knowingly: the `x-visibility` annotations arrive with [19](19-rbac.md)'s piece 2
   > in wave 5, so until then the price fields the security note below names are what the
   > rule denotes, and the assembler adopts the spec-driven form the day the annotations
   > exist. The cost the lean names — a spending sensor requires a deliberate add — is
   > now paid deliberately: question 7's InfluxDB event stream is that add, on a channel
   > with its own credentials rather than a broadcast one. (2026-08-31.)

## Executed

Landed 2026-09-02, against the eight Responses recorded above on 2026-08-31 rather than
against the leans they replaced. Measurements below were taken on
`.claude/worktrees/agent-ad096754dd4a1d9ea` (branch `worktree-agent-ad096754dd4a1d9ea`),
PHP 8.4.19, PostgreSQL 16 on `127.0.0.1:5432`, and an `aedes` broker on `127.0.0.1:1884`.

Eight commits, in the order the plan argues for — the dependency, then the transport, then
the assembler, then the triggers, then question 2's schema, then the two answers that
changed the shape of the work:

- **`e794ea8` — the dependency.** `php-mqtt/client` v2.3.2, MIT. **Divergence from the
  security notes' third bullet:** it is not one package with no runtime dependencies beyond
  PSR-3. It also pulls `myclabs/php-enum` 1.8.5, so `composer.json` grows by one direct and
  two installed packages. Cheap either way, and worth the sweep's dependency review knowing
  the real number.
- **`39cd2f7` — settings, publisher, discovery.** `MqttPublisher` connects, publishes a
  batch of retained QoS 0 topics over MQTT 3.1.1, disconnects. No last will, no availability
  topic. `DiscoveryPayloadBuilder` owns the topic layout and both discovery shapes.
  `ConfigurationValidator` refuses `MQTT_ENABLED=true` with an empty host.
- **`d4911d6` — the assembler**, reading the views the UI reads, with the price deny-list
  and the per-entity allow-list.
- **`185ed1c` — the triggers**, and `bin/victual-publish-state`.
- **`d8ed0b9` — migration 257 and the `ExposedEntity` entry**, question 2's opt-in flag and
  the publication ledger.
- **`30179ea` — the seventh and eighth ambient entities and the per-product diff.**
- **`e888f3e` — question 7's InfluxDB event writer.**
- **`5653658` — the devtools** under `.devtools/mqtt/`.

### The seam the after-commit trigger hangs off

`DatabaseService`, not the seven `StockService` entrypoints, and the choice is worth
recording because the plan's own text points at the entrypoints.

A write statement reaching the database marks the request dirty; `SetDbChangedTime()` — the
call `SessionService` and `ApiKeyService` already make to hide a last-used stamp from
`GET /api/system/db-changed-time` — clears the mark again. The shutdown handler then
publishes once, after `FlushDbChangedTime()`, having first asked PDO whether a transaction
is still open and skipped with a log line if one is.

Three properties follow, and only the first is available at the entrypoints:

1. **It is the same question the changed time already answers.** Chores, batteries, tasks,
   the shopping list and every generic CRUD write are covered without being named. Explicit
   `StockService` calls would have published a stock snapshot on a purchase and nothing at
   all on a chore being ticked.
2. **It fires once per request rather than once per commit,** which is what question 3 asks
   for. A shopping trip is many commits and one snapshot.
3. **Bookkeeping writes cost nothing,** because the existing restore idiom already says
   they are not data changes. Measured: an authenticated `GET /api/stock` advanced
   `api_keys.last_used` from `12:34:50` to `12:35:01` and published no topic.

One cost, and it is real: `DatabaseService` now names `MqttStatePublicationService` and
`BookingEventPublisher` directly. A listener registry would be cleaner, but registering a
listener needs a boot event PHP does not have, and holding the registry in process memory
between requests is what ADR-0007 forbids. The reference is guarded by
`defined('VICTUAL_MQTT_ENABLED')`, so an installation with the feature off pays one
constant read and never loads the class.

On SQLite the LessQL query callback had to be installed where it previously was not:
`SqliteDialect::RequiresChangeTracking()` returns false because the file modification time
*is* the changed time, so nothing was watching statements at all. The callback is now
installed when either change tracking or MQTT wants it.

### Topic layout

Prefix `victual`, no version (question 4). Each entity's state and attributes ride **one**
retained topic carrying `{"state": …, "attributes": {…}}`, read back through
`value_template` and `json_attributes_template`. One topic rather than two means state and
attributes can never be seen half updated, and halves what a subscriber receives.

```
victual/state/stock                              7 ambient sensors
victual/state/shopping_list
victual/state/next_chore
victual/state/next_battery
victual/state/next_task
victual/state/products_due_soon
victual/state/products_expired
victual/state/last_published                     the freshness fact
victual/state/product/<product_id>               one per opted-in product

homeassistant/device/victual/config              MQTT_DISCOVERY_MODE=device (default)
homeassistant/sensor/victual/<object_id>/config  MQTT_DISCOVERY_MODE=entity
homeassistant/sensor/victual/product_<id>/config always, whatever the mode
```

Per-product entities always take the per-entity discovery form. Removing one product must
retract exactly that entity, and folding hundreds of them into the single device config
would make every removal a rewrite of every other product's config.

Example payloads, from the demo database:

```
victual/state/stock
{"state":24,"attributes":{"products":[{"product_id":1,"product_name":"Cookies",
 "amount":14,"unit":"Pack","best_before_date":"2027-01-01"}, …]}}

victual/state/next_chore
{"state":"2026-08-27T23:59:59+00:00","attributes":{"chores":[{"chore_id":2,
 "chore_name":"Mop the kitchen floor",
 "next_estimated_execution_time":"2026-08-27T23:59:59+00:00"}, …]}}

victual/state/products_due_soon
{"state":4,"attributes":{"due_soon_days":5}}

victual/state/product/1
{"state":14,"attributes":{"product_id":1,"product_name":"Cookies","unit":"Pack",
 "best_before_date":"2027-01-01"}}
```

### On boot

PHP has no boot event, so "publish on boot" is `bin/victual-publish-state`, run from a
postStart hook or a Job alongside the initContainer that runs `bin/victual-migrate`. It
publishes discovery and the full snapshot, exits 0/1, and suppresses the request-end trigger
so one CLI run cannot publish twice. `--retract` clears every retained topic this version
owns, in **both** discovery modes plus every per-product topic the ledger remembers.

`bin/victual-migrate` and `bin/victual-db-import` deliberately do *not* suppress it: they
change data, so the request-end publish is exactly right for them and an out-of-band change
self-heals. There is no first-request-per-process publish; that would need process state.

### Divergences from the Responses, and what was deferred

- **Question 2's flag is a side table, not a column on `products`.** A column would change
  the shape of every products response — the invariant [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md)
  names — and on PostgreSQL it would not reach `products_view` or the views built on it
  without recreating all of them, where SQLite's `SELECT p.*` would pick it up silently.
  That is a divergence the differential suite would catch and nobody should have to fix.
  `mqtt_product_entities` is invisible to both.
- **Migration 257 is a pair, not one portable file.** Generated primary keys have no
  spelling both engines accept: SQLite's `INTEGER PRIMARY KEY AUTOINCREMENT` assigns ids on
  insert and PostgreSQL's `INTEGER PRIMARY KEY` does not.
  `.devtools/pgsql/check-migrations.php` reports a complete per-engine set, which needs no
  marker. Everything else about the two files is the same schema.
- **No foreign key on `mqtt_product_entities.product_id`.** This schema does its cascades
  with triggers rather than constraints (`products_DELETE`, `migrations/0225.sql`), and
  SQLite would not enforce a `REFERENCES` clause without a pragma the application does not
  set. The cascade is handled where it matters: the publisher joins `products`, so a
  deleted or deactivated product's entity is retracted and its orphan flag row dropped on
  the next publish. Verified below.
- **No product-form checkbox — deferred.** The flag is set and cleared through
  `POST`/`DELETE /api/objects/mqtt_product_entities`. The product form files belong to
  track B this wave, so a UI for it is a follow-on rather than part of this change.
- **`mqtt_product_entities` joins the `ExposedEntity` enum,** which adds a value to
  `GET /api/openapi/specification`. Additive, and the only wire change this plan makes; the
  Client impact section's "no HTTP client sees anything change" holds for every existing
  route and field.
- **The two count sensors are a knowing exception to this plan's own first rule.** A count
  of what is due within N days is a function of the clock, so it is a fact at publish time
  and stale afterwards. Question 1's Response promotes them anyway; the exception is
  contained by publishing no per-row derived boolean anywhere, so the stock summary still
  carries the dates a consumer needs to re-derive the number locally after midnight. The
  horizon comes from the configured `stock_due_soon_days` default rather than from the user
  who happened to make the request, because these topics are one household-wide snapshot
  with no reader identity.
- **Question 8's `x-visibility` form is a marked seam, not code.** The annotations arrive
  with [19](19-rbac.md)'s piece 2 in wave 5. Until then `StateSnapshotAssembler::DENIED_COLUMNS`
  is the deny-list the rule denotes, written out so it can be checked against the views by
  eye, and its docblock says where the spec-driven form plugs in.
- **Question 6's "once per process" is APCu-if-available.** ADR-0007 allows process memory
  only for pure caches, and a suppression window whose loss costs one extra log line is
  exactly that. Where APCu is absent — as it is in this environment — it degrades to one
  line per publish attempt, which for a web request is one line per request. Measured: three
  failing writes produced three log lines.

### Verification

Home Assistant itself was not available, so verifications **2** (entities repopulate after
a Home Assistant restart with the pod at zero), the Home-Assistant half of **4** (a template
derived from the attributes changes as the day rolls over) and **8** (a week of pod idle
time) **could not be run**. Everything else was, against a real broker.

1. **Retention actually retains.** Publisher connected, published, disconnected. A *fresh*
   subscriber (`php .devtools/mqtt/subscribe.php 127.0.0.1 1884 '#' 2`, clean session, a
   client id nothing else uses) then received all 11 topics — 8 ambient, 1 device discovery
   config, and one opted-in product's config and state — every one with `retain=true` and
   the correct payload.
3. **A write propagates, a read does not.** `POST /api/stock/products/1/add` (3 units at
   2.75) moved `victual/state/stock`'s product 1 from `amount 11, best_before 2027-03-01`
   to `amount 14, best_before 2027-01-01`, observed by a subscriber that connected only
   after the write. Two reads — `GET /api/stock` and `GET /stockoverview` — left the
   broker's log at exactly 28 lines. A bookkeeping-only request published nothing while
   provably writing: `api_keys.last_used` advanced from `12:34:50` to `12:35:01`.
4. **Facts, not derived states.** Every payload was read. No boolean appears anywhere in
   any of them; the only clock-dependent values are the two counts question 1's Response
   promotes deliberately, and `chores_current`'s own rollover recomputation, which is the
   view's behaviour and the same number the chores page shows.
5. **The broker being down cannot hurt a write.** Against a *refused* connection the write
   succeeded in **0.046 s** (`curl -w '%{time_total}'`) — a closed local port fails
   instantly, so this bounds nothing. Against an *unroutable* address (`10.255.255.1`, with
   InfluxDB pointed at the same) the write succeeded in **4.11 s** and **4.05 s** on two
   attempts, which is the two configured 2-second timeouts in sequence and nothing more; a
   read on the same instance took 0.012 s. Both failures were logged, neither reached the
   response, and both writes returned 200. **Worth an operator's attention:** this tree does
   not call `fastcgi_finish_request()`, so the delay is on the response rather than only on
   the process — two unreachable targets cost the sum of their timeouts.
6. **Retraction works.** With 11 retained topics on the broker,
   `php bin/victual-publish-state --retract` exited 0 and a fresh subscriber then saw
   **0 messages**. The per-product path was verified three ways: clearing the flag
   (`DELETE /api/objects/mqtt_product_entities/1`) published empty payloads to
   `homeassistant/sensor/victual/product_1/config` and `victual/state/product/1`;
   deactivating a flagged product (`PUT /api/objects/products/2` with `active: 0`) did the
   same and left **0** orphan flag rows behind; and running `bin/victual-publish-state`
   twice in a row published the per-product topics **once**, the second run finding the
   ledger hash unchanged.
7. **The assembler agrees on both engines.** `.devtools/mqtt/engine-diff.sh` migrates a
   fresh SQLite database, seeds it, migrates a fresh PostgreSQL database, copies the first
   into it with the real `bin/victual-db-import`, assembles on each and diffs:
   `MQTT PAYLOAD IDENTICAL ON BOTH ENGINES`, 3735 bytes over 124 lines, byte-identical
   rather than merely equivalent. The fixture is deliberately awkward — the null-due-date
   sentinel, a below-minimum product with no stock, a priced purchase, a shopping list note,
   an expired product, a chore with no schedule, a battery with no interval, a task with no
   due date, and two opted-in products of which one has no stock.

Alongside those: `.devtools/mqtt/price-guard.php` passes all 22 checks (the deny-list covers
every column the security note names, no allow-list admits a money-shaped key, and
`AssertNoForbiddenKeys` rejects a price nested inside an attribute row while accepting a
realistic snapshot). `.devtools/pgsql/run-tests.sh` is green on all five phases —
`MIGRATION NUMBERING OK`, `MIGRATED STATE IDENTICAL`, `ALL VIEWS IDENTICAL` ×5,
`TRIGGER BEHAVIOUR IDENTICAL`, `EVERY FAILED OPERATION ROLLED BACK` on both engines,
`QUERY FILTER OPERATORS IDENTICAL`, `SUITE PASSED` — which matters because
`services/DatabaseService.php` was touched. `php -l` is clean on all 15 changed PHP files
and `victual.openapi.json` parses. The suite ran with `MQTT_ENABLED` and `INFLUXDB_ENABLED`
at their defaults, so the whole feature is provably inert when off.

Question 7's write path was verified against a stand-in InfluxDB (a small node server that
records the body and answers 204). A three-unit purchase at 2.75 produced exactly:

```
POST /api/v2/write?org=household&bucket=victual&precision=ns
authorization: Token …
content-type: text/plain; charset=utf-8
price_paid,product_id=1 price=2.75,amount=3.0 1788352542000000000
stock_value,product_id=1 value=33.66,amount=14.0 1788352542000000000
```

No user id, no note, no location — `product_id` is the only tag.

### What this changes in the record above

The security notes' third bullet understates the dependency: two packages are installed,
not one (`e794ea8`). The fourth bullet's column list is now implemented as
`StateSnapshotAssembler::DENIED_COLUMNS`, which adds `avg_price`, `last_price_unit`,
`last_price_total`, `note` and `api_key` to the names it gives, and is enforced twice over
by a per-entity allow-list and a `/price|cost|value/i` guard that refuses to publish rather
than publishing something with a price in it.

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
- **A new dependency is a new supply chain.** `php-mqtt/client` is the cheap end of this,
  but it is the first addition to `composer.json` this fork has made and the sweep's
  dependency review should pick it up next time round. Corrected on landing: it is not one
  package with no runtime dependencies beyond PSR-3 — it also pulls `myclabs/php-enum`
  1.8.5, so the installed count grows by two. Question 7's InfluxDB writer adds no package
  at all; it goes through the Guzzle client already in the tree.
- **The retained payload is household data on a shared broker.** Anything with access to
  the broker can read the household's stock and chores without authenticating to Victual.
  That is a real widening of who can see this data, it is accepted here because the broker
  is on the same private cluster with its own credentials, and it is a reason not to
  publish anything that would not also be shown on a wall tablet — no user records, no

  notes fields, no API keys, and — per **question 8**, answered — no prices. Prices are the
  addition [19](19-rbac.md) forces and the one this plan would otherwise have missed. The
  wall-tablet test excludes them on its own reasoning, since a broker subscriber is not a
  logged-in user and cannot be made into one, which is why 19's Q5 is carried here rather
  than gating that plan; question 8's Response adopts the lean (2026-08-31). The exposure is real either way: if the
  stock summary's attributes are assembled from `uihelper_stock_current_overview`, which
  the UI reads and which selects `value`, `last_price` and `average_price`
  (`migrations/0252.sql:38-39`), they ship by default unless something removes them.
  Concretely, the v1 entity set in question 1 carries none of `stock.price`,
  `stock_log.price`, `products_average_price`, `product_price_history`,
  `products_last_purchased.price`, `last_price`, `avg_price` or a recipe's `costs`, and
  adding an entity that would is a change this bullet has to be edited to permit. As built
  that list is `StateSnapshotAssembler::DENIED_COLUMNS`, which also names
  `last_price_unit`, `last_price_total`, `note` and `api_key`, and it is not trusted on its
  own: each entity carries an allow-list of the only keys it may emit, and
  `AssertNoForbiddenKeys()` walks the finished payload and throws on any key matching
  `/price|cost|value/i` rather than publishing it. `.devtools/mqtt/price-guard.php` is the
  check. Question 2's per-product entities carry product name, unit, amount and
  `best_before_date` and nothing else, so they do not widen this.
- **Question 7 adds a second outbound connection: InfluxDB.** Same treatment as the
  broker — the endpoint is a configured constant nothing in a request can influence, the
  token is a setting that never joins `EXPOSED_SETTINGS`, and a failed write to it never
  surfaces into the committed write that triggered it. Unlike the broker there is no
  retained-payload exposure: InfluxDB is queried with credentials, not subscribed to.
- **A publish must never carry a failure into a committed write.** Short connect and
  publish timeouts, exceptions caught and logged, and the write path unaffected. The
  transaction is already closed by then, per 13.
