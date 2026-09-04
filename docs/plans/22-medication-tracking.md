# 22. Vitamin and medication tracking

**Goal:** Medications, vitamins and supplements are tracked as what they are — a per-person
regimen drawing on a shared physical supply — rather than as groceries that happen never to
appear in a recipe. Scheduling, adherence, days-of-supply, lot traceability and cold chain,
built on the stock subsystem rather than beside it.
**Depends on:** [23](23-storage-classes.md) (the storage vocabulary, extracted from this plan
per Q1), [19](19-rbac.md) (per-subject visibility, see Q5),
[14](14-contract-and-regression-scaffolding.md) piece 2 (this surface is invisible to the
parity suite and contract tests are its only guard). Builds on
[12](12-frontend-shared-core.md), landed.
**Governed by:** [ADR-0014](../adr/0014-medication-records-never-advises.md) (scope boundary)
and [ADR-0015](../adr/0015-schedule-expansion-in-the-application.md) (where expansion lives),
both **Proposed** and written alongside this plan.
**Consumes:** [ADR-0011](../adr/0011-label-namespace.md) for labels — this plan proposes no
code format of its own — and answers its Q3 for the medication case.
**Affects:** [02](02-mcp-endpoint.md) — the interface spec is still a draft and should decide
medication exposure now rather than retrofit it.
**Status:** draft for review.

## Today

There is no medication concept. A vitamin is a product, a bottle is a stock entry, and taking
one is a consume booking. That works, and about a third of what this plan needs is already in
the tree — which is the reason to build on stock rather than alongside it.

**What already fits.** `products.due_type = 2` is hard expiry.
`default_best_before_days_after_open` is a beyond-use date and `OpenProduct` already applies
it, capped so it can never exceed the original due date
([StockService.php:1457](../../services/StockService.php:1457)) — the 28-day inhaler and the
30-day pierced vial are this field, not new machinery. `move_on_open` plus
`default_consume_location_id` moves a vial from the fridge to the in-use tray on opening.
`hide_on_stock_overview` keeps medications out of the general stock view.
`not_check_stock_fulfillment_for_recipes` keeps them out of recipe fulfilment.
`quantity_unit_conversions` is per-product, so "1 bottle = 90 tablets" and "1 mL = 100 mg" are
expressible today. Consumption is FEFO by default, which is the correct order for drugs.
`stock_log` carries `transaction_id`, `correlation_id` and an `undone`/`undone_timestamp`
pair, so the audit trail and its reversal already exist.

**What does not.** No person dimension — `users` requires `password NOT NULL`, so a child or a
pet cannot be represented without minting a credentialed account. No lot number: `stock` has
`note`, untyped and unindexed, and recalls are issued by lot. No storage vocabulary beyond a
freezer boolean, which is [23](23-storage-classes.md)'s subject. No schedule that survives
contact with real dosing, and no adherence record.

**Chores are the near miss.** `chores` already has `period_type`, `period_interval`,
`period_config`, `rollover`, `start_date`, `consume_product_on_execution` with `product_id`
and `product_amount`, and `chores_log` has `skipped` and `scheduled_execution_time`. It is the
right *shape* and the wrong *model*: one dose amount per chore, no end date, no occurrence
materialisation (only "next execution from last"), assignment rotates a household chore among
users rather than saying whose medication this is, and PRN is the inverse of a recurrence.
This plan reads chores as a design source and does not extend it.

## Proposed change

Seven pieces, each shippable alone. Pieces 1–2 are useful with no scheduler at all: together
with [23](23-storage-classes.md) they give cold-chain-aware inventory with recall
traceability, which is most of the value for the smallest fraction of the work.

### Piece 1 — Medication master data

New `medication_products`, keyed on `product_id`. Presence in this table *is* the
classification — there is no `is_medication` column on `products`, so `products` is untouched
and nothing in the wire contract moves.

Columns: form (tablet / capsule / inhaler / vial / pen / drops / patch / suspension),
`strength_amount` + `strength_qu_id` (50 mg per tablet), route, `splittable`,
`min_dose_increment`, `required_storage_class_id` (FK into [23](23-storage-classes.md)),
`requires_reconstitution`, `days_after_reconstitution`, and the prescription block —
`is_prescription`, prescriber, pharmacy, `rx_number`, `refills_remaining`, `rx_expires_on`.

`splittable` and `min_dose_increment` exist because half tablets are real and enteric-coated
or extended-release tablets must not be split. The regimen form validates a dose against them.
This is not clinical validation under
[ADR-0014](../adr/0014-medication-records-never-advises.md): it compares a dose against a
physical fact about a tablet that the household itself recorded, and asserts nothing the
household did not already know.

Creating a medication product sets defaults on the `products` row itself — `due_type = 2`,
`hide_on_stock_overview = 1`, `not_check_stock_fulfillment_for_recipes = 1`,
`treat_opened_as_out_of_stock = 0`, and `default_stock_label_type = 2` — rather than inventing
parallel behaviour.

That last default is this plan's answer to [ADR-0011](../adr/0011-label-namespace.md) Q3,
which asks whether per-unit labelling stays the default granularity and leans to letting the
consuming plans decide. For medication it does, and for a specific reason: `stockLabelType = 2`
gives each physical unit its own stock entry and its own `stock_id`
([StockService.php:204](../../services/StockService.php:204)), which is exactly what a vial or
a pen needs — its own pierce date, its own 30-day clock, its own lot. Per-unit labelling is not
a printing preference here; it is what makes piece 2 correct.

### Piece 2 — Lot, cold chain and the stock entry

New `medication_stock_attributes`, keyed on **`stock_id`, not `stock.id`**. This is the
load-bearing schema decision in the plan. `stock.id` is a row that splits on partial open and
is deleted when consumed to zero; `stock_id` is the `uniqid` string carried into every
`stock_log` booking, so it is the only identifier under which a lot survives the bottle being
finished — which is exactly when a recall notice arrives.

Columns: `lot_number`, `serial_number`, `national_code` (NDC/DIN/PZN), manufacturer,
`reconstituted_date`, `first_pierced_date`, `quarantined`, `quarantine_reason`.

**The split hazard.** `OpenProduct` splits an entry covering more than the requested amount
and gives *the unopened remainder a new `stock_id`*
([StockService.php:1385](../../services/StockService.php:1385)); `TransferProduct` does the
same. An attribute row keyed to the original `stock_id` silently stops describing the
remainder. Every split site must copy the attribute row — Q4 is where that copy lives. Piece
1's per-unit labelling default reduces how often this fires but does not remove it, because a
transfer still splits.

**Excursions.** New `storage_excursions`: location_id, started, ended, observed min/max,
source, note. Excursions arrive from outside — the fridge sensor lives in Home Assistant, not
here — through an inbound API route. Not MQTT: [18](18-mqtt-state-publication.md) is
publish-only and adding a subscriber is a larger change than this needs.

**How this sits with [ADR-0012](../adr/0012-observations-are-proposals.md).** A thermometer is
not guessing, so an excursion is not a probabilistic observation and does not become a
proposal; it also never touches the stock ledger, which is what 0012 and the constitution's
"the stock ledger is exact history" actually protect. What *would* fall under 0012 is the
inference built on top of it — "these entries are spoiled" is a confidence-bearing claim about
stock, and if quarantining ever becomes automatic it must arrive as a proposal a human
confirms, not as a booking. Q8 keeps v1 on the safe side of that line by flagging only.

The application records the excursion, surfaces it against the entries that were resident, and
stops. Whether a vial is still good is a judgement a person makes —
[ADR-0014](../adr/0014-medication-records-never-advises.md).

### Piece 3 — Subjects

New `subjects`: name, `user_id` (nullable FK to `users`), active, note. A subject is a person
or animal a regimen is for; the nullable link is what lets a subject who is *also* a login see
their own data without forcing every subject to be an account.

**Visibility defaults to per-subject.** A subject's regimens and administrations are visible to
the linked user, and to holders of a new `MEDICATIONS_ALL` permission; household-wide
visibility is opt-in per subject. This is the sharpest data-visibility case in the application
and it is enforced **server-side, in the service and the view predicate, on every route** — not
by filtering in the frontend. Under
[ADR-0006](../adr/0006-authenticated-issues-in-scope.md) a leak here is a finding, not a
cosmetic issue. Q5 is whether this ships its own enforcement or waits on [19](19-rbac.md).

New permission constants alongside the existing 30 in
[controllers/Users/User.php](../../controllers/Users/User.php): `MEDICATIONS`,
`MEDICATIONS_ADMINISTER`, `MEDICATIONS_UNDO`, `MEDICATIONS_ALL`.

### Piece 4 — Regimens

New `regimens`: subject_id, product_id, `schedule_type`, `dose_qu_id`, route, `start_date`,
`end_date`, `previous_regimen_id`, `prn`, `prn_min_interval_minutes`, `prn_max_per_day`,
`refill_lead_days`, active, note. Plus `regimen_doses`: regimen_id, `time_of_day`,
`dose_amount`, `day_selector` — one row per dose event in a cycle, which is how 1 tablet in the
morning and 2 at night is expressed.

`schedule_type` for v1: `fixed_daily` (N times a day at wall-clock times), `interval_days`
(every N days), `weekdays` (mask), `cycle` (N days on, M off), `prn`.

**A regimen is versioned, not edited.** Changing a dose ends the current regimen and starts a
new one linked by `previous_regimen_id`. Administrations reference the regimen they were taken
under, so history stays interpretable against the instruction that was actually in force. Two
things fall out of this for free: **a taper is a chain of regimens** with consecutive
start/end dates and needs no separate model, and **the same product at different doses for
different people** is simply two regimens against one product and one stock pool — the case
that made a per-product schedule field unworkable in the first place.

**Occurrence expansion lives in PHP**, in a new `MedicationService`, not in a view. That is
[ADR-0015](../adr/0015-schedule-expansion-in-the-application.md)'s subject and the argument is
there rather than here; the short form is that recursive date arithmetic is the surface where
the two engines diverge most, and the dual-engine discipline is live regardless of
[ADR-0008](../adr/0008-postgresql-only-runtime-engine.md)'s acceptance.

### Piece 5 — Administrations

New `administrations`: regimen_id, subject_id, product_id, `scheduled_time` (null for PRN),
`actual_time`, `state`, `dose_amount`, `stock_transaction_id`, `recorded_by_user_id`, `undone`,
`undone_timestamp`, note. States: taken, taken-late, skipped, refused, held, missed.

**Nothing is ever generated automatically.** A dose is recorded because a person recorded it.
Auto-consuming on schedule would make the adherence record fiction and the stock count a guess
dressed as a measurement — which is the same defect
[ADR-0012](../adr/0012-observations-are-proposals.md) exists to prevent, arrived at from a
different direction.

Consumption follows the log: recording a `taken` calls `StockService::ConsumeProduct` and
stores the returned transaction id, inheriting FEFO, the correlated bookings and the
transactional write path [13](13-write-path-transactions.md) landed. Correction is
undo-and-rerecord on the `stock_log` precedent — rows are never deleted.

### Piece 6 — Supply and refills

Days of supply = on-hand converted into dose units ÷ the summed daily dose of every active
regimen against that product. Because the pool is shared, the figure is **pool-wide**; the plan
does not allocate stock to subjects, and the UI must not imply it does.

Refills surface in the medication module, keyed on `refill_lead_days`. They deliberately do
**not** flow into the shopping list: `min_stock_amount` is a static number where
days-of-supply is derived, and a refill is a pharmacy call rather than a grocery item.
Adjacent to [03](03-category-min-stock.md) but not built on it.

### Piece 7 — Labels and scanning

**This plan proposes no code format.**
[ADR-0011](../adr/0011-label-namespace.md) already decides it: opaque `vctl:<uid>` payloads, a
`labels` table the database owns, QR for new labels, grocycode retained as a read-only input
symbology, printing through an outbox rather than the fire-and-forget webhook. The
constitution states the same thing as a standing invariant — physical artifacts are contracts.
That is the QR migration this household already wants, and a `stock_entry_codes` table
invented here would be a second, worse version of it.

What this plan contributes is a consumer and one answer: medication needs stock-entry-granular
labels (piece 1), which is 0011 Q3 resolved for this case. If 0011 is not accepted, this piece
waits rather than routing around it.

Intake stays manual: read the carton, type the expiry and the lot, print a label, and scan
that label thereafter. GS1 DataMatrix parsing at intake — AI (01) GTIN, (17) expiry, (10) lot —
would remove the typing, and is explicitly **out of scope** because the household is moving off
DataMatrix and 0011 retires it to legacy-read-only. Noted so the manual intake cost is a known
trade rather than an oversight.

## Gotchas

Collected because most of them are only visible from inside the existing code.

- **`stock_id` splits.** Piece 2's central hazard, repeated because every future contributor
  will meet it: open and transfer mint a new `stock_id` for the remainder.
- **Half tablets drift.** `stock.amount` is `DOUBLE PRECISION` on both engines, so behaviour is
  at least consistent, but a few hundred 0.5s accumulate off integers. Round for display,
  compare with an epsilon, and never test a remaining amount with `= 0`.
- **Reconstitution is a third transition.** `default_best_before_days_after_freezing` and
  `_after_thawing` are one pair, and a frozen-until-reconstituted product needs a distinct
  clock that starts at reconstitution and is usually refrigerated afterwards — a different
  storage class as well as a different date.
- **Verify the beyond-use cap against a missing due date.** The cap at
  [StockService.php:1461](../../services/StockService.php:1461) takes the *earlier* of the
  computed date and the original, which is right. What is unverified is the behaviour when
  `best_before_date` is absent or a far-future sentinel; a 28-day clock that silently evaluates
  to "no date" would be a quiet safety hole. Confirm before relying on it — this is a check to
  run, not a defect being claimed.
- **Wall clock versus elapsed time.** Grocy stores naive `TIMESTAMP` and has no per-user time
  zone. Wall clock is right for medication, so "every 12 hours" is two fixed times — but the
  DST transitions belong in the test set, in both directions.
- **The pillbox is a location with a storage class.** Dispensing a week ahead is a transfer
  into an Ambient location, and a product whose `required_storage_class_id` is Fridge then
  cannot be dispensed into it — the correct outcome, arrived at with no special case. FEFO
  within a pillbox is meaningless and lot attribution blurs once tablets are commingled; the
  plan accepts that for ambient products and forbids it for the rest.
- **No interaction checking, dose validation or clinical advice, ever.**
  [ADR-0014](../adr/0014-medication-records-never-advises.md) is the record; this line is here
  because a plan is where the temptation actually arrives.
- **MCP exposure is decided now.** Medication entities are excluded from
  [02](02-mcp-endpoint.md) by default and administration is never a write tool. Cheap while
  the spec is a draft, and 0014 makes it more than a privacy question: an LLM handed
  medication data will synthesise advice whether or not a tool offers it.
- **The parity suite cannot see this.** Fork-only surface with no upstream counterpart, so
  `.devtools/parity/` will never exercise it and contract tests are the only guard —
  [14](14-contract-and-regression-scaffolding.md) piece 2.
- **Demo data must be transparently fictional.** Plausible-looking prescriptions attached to a
  demo household are a bad thing to have screenshotted.
- **Migration numbering.** Two pairs, claiming **0262** (medication master data and subjects)
  and **0263** (regimens, administrations, excursions), with rows added to
  [RESERVATIONS.md](../../migrations/RESERVATIONS.md) before any file is written, per
  [ADR-0004](../adr/0004-engine-specific-migrations.md). 0261 belongs to
  [23](23-storage-classes.md), which lands first.

## Open questions

1. **Should the storage-class work be its own plan?**

   > **Response:** Yes — extracted as [23](23-storage-classes.md). It changes a column
   > every `/objects/locations` client can see for the sake of a wine cooler and a
   > cheese cave as much as a medication fridge, and a schema change justified only
   > inside a medication plan is one nobody reading `locations` would think to open.
   > 23 takes migration 0261 and lands first.

2. **Where do lot numbers live?** A column on `stock` and `stock_log`, or a side table keyed on
   `stock_id`.

   > **Response:** A medication side table referenced to the root item. Taken as
   > settled; the plan keys it on `stock_id` rather than `stock.id` for the reason in
   > piece 2, which was not part of the question but follows from it.

3. **Per-subject or household visibility?**

   > **Response:** Per-subject by default, household-level optional.

4. **Where does the attribute row get copied on a split?** A trigger on `stock` insert
   (portable, invisible, fires for every caller including the importer), or an explicit copy at
   the three call sites in `StockService` (visible, testable, and forgettable when a fourth
   split site is added). *Lean: the trigger, plus an assertion in the contract tests that no
   `stock_id` belonging to a medication product lacks an attribute row — belt and braces,
   because the failure is silent and safety-relevant.* Note this is the same
   trigger-versus-application question [23](23-storage-classes.md) Q2 asks about derivation,
   and the two should be answered the same way or the inconsistency explained.

5. **Does piece 3 ship its own visibility enforcement, or wait on [19](19-rbac.md)?** Waiting
   blocks the whole plan behind a draft that is itself blocked on its own Q8. Shipping first
   means writing enforcement 19 will subsume, and the risk is that a half-measure gets treated
   as the finished thing. *Lean: ship a narrow version — subject-scoped predicates in
   `MedicationService` and the medication views only, no general mechanism — and state in 19
   that it is a client of whatever 19 builds.*

6. **What happens to this plan if [ADR-0011](../adr/0011-label-namespace.md) is not accepted?**
   Piece 7 assumes it. *Lean: piece 7 waits rather than inventing a parallel code table, and
   the rest of the plan is unaffected — pieces 1–6 need no labels at all. Worth stating
   explicitly because "medication needs QR codes" is exactly the argument that would otherwise
   be used to route around an unaccepted record.*

7. **What is the storage class of a location used at two set points over the year?** The wine
   cooler again. One class per location and a second location for the second use, or a class
   with a range wide enough for both? *Lean: two locations. Honest, and costs nothing.* Belongs
   to [23](23-storage-classes.md) but is recorded here because 23 was extracted from this plan
   and the question originated with the medication fridge.

8. **Does an excursion quarantine automatically, or only flag?** Automatic quarantine of every
   entry in a fridge that touched 9 °C for twenty minutes produces alarm fatigue and then gets
   ignored, which is worse than not having it. *Lean: flag only in v1.* If it ever becomes
   automatic, [ADR-0012](../adr/0012-observations-are-proposals.md) governs: the claim "this
   stock is spoiled" is confidence-bearing and must arrive as a proposal a human confirms.

9. **Weight-based and age-based dosing.** Paediatric and veterinary dosing is often mg/kg,
   which would put a weight on `subjects` and a computed dose on the regimen. The arithmetic is
   trivial and the act is dose *calculation*, which
   [ADR-0014](../adr/0014-medication-records-never-advises.md) puts outside the line: it
   asserts a dose the household did not enter. *Lean: out of scope, and 0014 is the reason
   rather than v1 sequencing — so this does not quietly return as a "small addition" later.*

## Effort

Large, and genuinely so — seven pieces, two migration pairs, a new service, a new UI section
and a new permission family, on top of [23](23-storage-classes.md). But the pieces are
separable and the first two are independently useful: with 23, medication master data and lot
attributes give cold-chain-aware inventory with recall traceability and no scheduler at all.
Regimens and administrations are the second half and the larger one.
