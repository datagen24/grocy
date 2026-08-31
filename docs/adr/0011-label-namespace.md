# ADR-0011: Labels carry stable opaque identifiers; grocycode becomes an input symbology

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-31.
- **Relationship:** generalizes the reasoning [06](../plans/06-location-barcodes.md)
  already wrote for locations to every labelled thing; the print path it replaces is the
  webhook the [security sweep](../security-sweep.md) notes as the tree's only outbound
  call. The drainer it introduces is a workload under
  [ADR-0010](0010-workload-standard.md). Pairs with
  [ADR-0008](0008-postgresql-only-runtime-engine.md)'s importer without depending on it.
- **Would affect:** [06](../plans/06-location-barcodes.md) (largely absorbed),
  [08](../plans/08-nested-locations.md), [17](../plans/17-ecosystem-clients.md),
  [18](../plans/18-mqtt-state-publication.md).

## Context

The fork prints Grocycodes: `grcy:p:{product_id}[:{stock_id}]` and friends
(`helpers/Grocycode.php`; types `p`/`b`/`c`/`r`), rendered as DataMatrix, emitted per
booking or per unit (`stockLabelType 2` splits a purchase into one stock entry per unit
so each unit gets its own label) and delivered by firing a webhook at an external
printing container. Three facts make this the wrong foundation to build years of physical
labels on:

1. **The payload is a row pointer, and rows are not stable.** A printed label outlives
   every deployment — it is the slowest-moving artifact in the system, and it cannot be
   rolled back or patched. Plan [06](../plans/06-location-barcodes.md) already states the
   problem for locations: `grcy:l:7` is only meaningful while that row keeps id 7.
   `bin/victual-db-import` renumbers rows, which under
   [ADR-0008](0008-postgresql-only-runtime-engine.md) is a first-class path rather than a
   corner case; a re-import silently repoints every label in the building.
2. **The print path is fire-and-forget.** A webhook that fails prints nothing and records
   nothing; a reprint requires re-triggering the booking that caused it; a printer that
   is off for a day eats a day of labels. It is also the tree's only outbound HTTP call,
   which the security sweep tracks as the reason there is no SSRF surface to audit.
3. **This installation has printed zero Grocycodes.** The migration constraint that would
   normally dominate this decision — a building full of legacy labels — binds future
   adopters arriving from grocy, not this deployment. For adopters, Grocycode is a door
   in, exactly as grocy's SQLite file is a door in for
   [ADR-0008](0008-postgresql-only-runtime-engine.md)'s importer.

## Decision (proposed)

**Label payloads carry stable opaque identifiers; a mapping the database owns resolves
them; no row id ever leaves the database on paper.**

1. **A new namespace.** A label payload is `vctl:<uid>`, where `<uid>` is random,
   generated at label creation, and meaningless. A `labels` table maps
   `uid → (kind, target id, created_at, retired_at)`, with kinds for at least stock
   entries, products, and locations. Resolution of a retired or unknown uid fails
   loudly and distinctly — a retired label seen in the world is a discrepancy signal,
   not an error to swallow.
2. **`ResolveBarcode` tries the new namespace first**, then Grocycode, then product
   barcodes, preserving today's behaviour for everything it already accepts.
3. **Grocycode becomes read-only.** The fork parses `grcy:*` indefinitely as a migration
   input for adopters — pinned by fixture codes per type, the same posture as the
   importer — and never emits one again. No new Grocycode types are added; plan 06's
   `grcy:l:` question dissolves.
4. **Printing becomes an outbox.** Label creation enqueues a print job row (uid,
   template, payload, status). A drainer — its own workload under
   [ADR-0010](0010-workload-standard.md), not this repository's process — renders and
   prints, marks done, retries on failure. Reprint is resetting a row. The webhook and
   its `VICTUAL_LABEL_PRINTER_WEBHOOK` constant are retired, taking the tree's only
   outbound call with them.
5. **The importer preserves the mapping.** `bin/victual-db-import` re-keys label targets
   with the rows it creates; uids never change.

## Options considered

**A. Keep printing Grocycodes.** Zero work, and every printed label is a row pointer with
the re-import failure mode above. Rejected on fact 1.

**B. Extend Grocycode** with new types (`grcy:l:`) and per-entity stability rules. Still
row pointers, still someone else's format drifting under fork ownership, and the
stability problem is structural rather than a missing type. Rejected.

**C. Opaque uids plus a mapping table.** The proposal. One indirection buys stability
across imports, restores and schema surgery, and gives retirement semantics for free.

**D. URLs as payloads** (a label encodes a resolvable URL). Self-describing, and roughly
triples the payload — which at fixed module size means a physically larger label or a
shorter read distance, the constraint plan 06 already identifies as decisive for
camera-at-distance reading. The uid can be made a URL suffix later without reprinting
anything; the reverse is not true. Rejected for v1.

## Consequences

**A second lookup on every scan.** One indexed point query. Nothing at household scale.

**The `labels` and print-outbox tables are dual-engine liabilities** while
[ADR-0008](0008-postgresql-only-runtime-engine.md) is Proposed — two more tables under
[ADR-0004](0004-engine-specific-migrations.md)'s discipline. They are plain tables with
no views or triggers, so the tax is small, but it is the exact tax 0008 exists to
retire, and sequencing the two records is worth a thought in the accepting PR.

**The importer grows an obligation** — preserve mappings across re-import — which lands
on the same fixture machinery 0008's acceptance prerequisites already demand.

**Rendering leaves this repository.** Label templates (the human-readable layer —
product, dates, whatever a two-colour thermal printer can usefully add) belong to the
drainer. The fork's contract is the job row, not the label's appearance.

**Ecosystem clients that assume Grocycode** keep working for scanning (the parser
stays) and lose nothing at print time they could name — but
[17](../plans/17-ecosystem-clients.md)'s catalogue should be checked for anything that
*generates* Grocycodes before the accepting PR claims the blast radius is zero.

## Acceptance prerequisites

- **The uid format is chosen** — alphabet, length, collision stance — and written into
  this record by the accepting PR (open question 1 carries the lean).
- **Plan [06](../plans/06-location-barcodes.md) is reconciled** — absorbed into this
  record with a superseded note, or narrowed to what remains (placement, UI).

## Open questions

1. **Uid format.** *Lean: 64 random bits, Crockford base32, uppercase — 13 characters,
   so `VCTL:` plus uid stays inside QR version 1's alphanumeric capacity, which is what
   keeps a label small at a given read distance. Collision odds at household label
   volumes are ignorable; a unique index makes them impossible.*
2. **Symbology.** The namespace is symbology-agnostic; the printed form is not. *Lean:
   QR for new labels — better perspective tolerance and tunable error correction for
   reading at distance and angle; DataMatrix support retained for reading legacy
   Grocycodes only.*
3. **Do per-unit labels stay the default print granularity** (`stockLabelType 2`), or
   does labelling shift toward containers and locations with per-unit as the precision
   tier? Interacts with [08](../plans/08-nested-locations.md). *Lean: decide in the
   plans that consume labels, not here — this record only guarantees any of them a
   stable payload.*
4. **Retirement semantics.** *Lean: labels are never deleted; `retired_at` is set when
   the target is consumed or removed, and a scan of a retired uid reports what the label
   was — which is precisely the information a discrepancy wants.*

## Research

- Tree facts (`helpers/Grocycode.php` types; `StockService` per-unit label splitting and
  webhook payloads) measured on the working copy of 2026-08-31.
- QR version 1 alphanumeric capacity (25 characters at ECC L) — ISO/IEC 18004; the
  read-distance argument is [06](../plans/06-location-barcodes.md)'s.
