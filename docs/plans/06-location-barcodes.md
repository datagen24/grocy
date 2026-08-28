# 06. Location barcodes

**Goal:** Machine readable codes on storage locations, so a future camera based inventory
system can tell *where* it is looking and keep stock by location current without anyone
typing anything.
**Depends on:** [12](12-frontend-shared-core.md), per the README. Pairs naturally with
[08](08-nested-locations.md) but does not need it.
**Status:** draft for review.

## The use case drives the design

This is not primarily "scan a shelf with your phone". The intent is a fixed or handheld
camera that reads a marker on a shelf, knows which location it is looking at, and reports
what it sees back into Grocy. That changes three things a phone-first design would get
wrong, and they are worth deciding before any code:

1. **Labels are physical and long lived; database ids are not.** A printed label stuck to a
   shelf may outlive several restores, re-imports and migrations. `grcy:l:7` is only stable
   as long as that row keeps id 7.
2. **Optics matter.** A code read at distance and at an angle by a fixed camera has
   different requirements from one held 10 cm from a phone.
3. **The write path is the point.** Scanning to navigate a UI is incidental; an external
   system needs an API to say "location 7 now contains these things".

## Today

Grocy already has an internal barcode format, `Grocycode` (`helpers/Grocycode.php`):

```php
public const PRODUCT = 'p';
public const BATTERY = 'b';
public const CHORE   = 'c';
public const RECIPE  = 'r';
public const MAGIC   = 'grcy';
```

producing codes like `grcy:p:42`, with `Validate()`, `GetType()`, `GetId()` and optional
extra data already implemented. Label printing exists for products, and
`GROCYCODE_TYPE` config selects Code128 or DataMatrix.

Locations are simply not one of the supported types.

## Proposed change

This is the smallest item on the roadmap because the mechanism already exists.

### Grocycode

Add `public const LOCATION = 'l';` and include it in whatever validation list constrains
the type character. Everything else — parsing, rendering, printing — is generic.

### Label stability

`Grocycode` encodes the row id, so a location label reads `grcy:l:7`. That is fine while
the database is continuous, and wrong the first time ids shift — a restore from a seed, a
re-import, or a rebuild. Every printed label then points at the wrong shelf, silently.

Two options:

- **Accept id based codes.** Simplest, and ids are in practice stable for a database that
  is only ever migrated rather than rebuilt. `bin/grocy-db-import` preserves ids exactly,
  so the PostgreSQL move does not break labels.
- **Add a stable opaque id.** `locations.code_uuid`, generated once, encoded in the label
  instead of the row id. Survives anything. `ramsey/uuid` is already a dependency and
  currently unused.

For a camera system that may run for years against labels printed once, the second is
cheap insurance. It only matters for entities that get physical labels, so it need not
apply to products. See Q1.

### Symbology

`GROCYCODE_TYPE` currently offers `1D` (Code128) or `2D` (DataMatrix). DataMatrix is
designed for small marks read close up — good for a product label, less good for a shelf
marker read across a room at an angle. QR carries stronger error correction and is what
most camera pipelines expect.

Adding `QR` as a third `GROCYCODE_TYPE` value is a small change and probably the single
most useful thing here for the camera use case. Worth checking whether the bundled
`interficieis/php-barcode` can emit QR, or whether another dependency is needed.

### Scanning and the write path

Two separate concerns:

- **Interactive scanning** — the existing scan-input handler resolves a Grocycode and acts
  on it. A location code should preselect that location as the target for subsequent scans,
  so you scan the shelf then scan items onto it. That implies a small "current location"
  notion Grocy does not have today.
- **Machine reporting** — an external system needs an endpoint that says "these products,
  these amounts, are at this location". Grocy has `/stock/transfer` and inventory endpoints,
  which mutate one product at a time and assume a human decided. A camera system reporting
  observed contents is a different shape, and is closer to inventory reconciliation than to
  a transfer. See Q2 — this is the part that needs real design, and it may be better as its
  own plan once the camera side is more concrete.

### Label printing

Locations get a "print label" action, reusing the existing webhook/thermal printer paths.
The label wants the location name and, once [08](08-nested-locations.md) lands, probably
its path rather than the bare name.

### API

Additive. If products expose a `grocycode` field, locations should expose the same in the
same shape. No existing response changes.

### UI

A print action on the locations list and form, mirroring products.

## Open questions

1. **Id based or stable opaque codes?** I lean to adding `locations.code_uuid` given labels
   are printed once and expected to last. It is one column and one migration now, versus
   reprinting every label later. Note it only needs to apply to physically labelled
   entities.

   > **Response:** Yes, `locations.code_uuid` — and decide the encoding explicitly:
   > `grcy:l:{uuid}` with the uuid *as* the id (an indexed lookup, and a Grocycode
   > parser that accepts non-numeric ids) is cleaner than appending the uuid as
   > extra data while the row id stays authoritative. Labels carry only the stable
   > identifier.
2. **What shape should the machine reporting endpoint take?** The interesting one. A camera
   reporting "I see 3 of product X at location 7" is an *observation*, and Grocy has no
   concept of one — it has authoritative stock that humans mutate. Options range from
   mapping observations onto the existing inventory endpoint (simple, but an incorrect
   observation silently corrupts stock) to recording observations separately and letting a
   human accept them (safer, more work, and a new concept). Worth deciding once you know
   what the camera side can actually report, and possibly worth splitting into its own plan.

   > **Response:** Agreed — out of scope here, its own plan once the camera exists.
   > When it comes, the observation-then-accept shape (a staging record a human
   > confirms) is the one that cannot silently corrupt stock; a camera writing
   > straight through the inventory endpoint is the trapdoor to avoid.
3. **Add `QR` to `GROCYCODE_TYPE`?** I think yes, specifically for this use case. Needs a
   check that the bundled barcode library can produce it.

   > **Response:** Yes — and expect a new dependency: the bundled generation is
   > 1D/DataMatrix oriented, and a GD/SVG-capable QR library (e.g.
   > chillerlan/php-qrcode) is the usual PHP answer. Verify before assuming.
4. **Should other master data get codes at the same time?** `shopping_locations`,
   `quantity_units`, `product_groups` are the same one-line change. Cheap together,
   another round of work later.

   > **Response:** Just locations now. `quantity_units` and `product_groups` never
   > get physical labels; add `shopping_locations` only if a use appears.
5. **Does the label need the tree path** or just the name? Path is more useful physically
   but longer on a small label. Interacts with [08](08-nested-locations.md).

   > **Response:** The human-readable text line shows the path once 08 lands; the
   > encoded payload stays the bare uuid. Never encode display strings into the
   > machine side.

## Effort

The Grocycode and printing part is small — half a day, plus a little for QR and the UUID
column. Q2 is unbounded until the camera side is specified, and should probably not be
scoped as part of this. Recommend shipping codes, printing and interactive scanning first,
so the physical labels exist and are stable, and treating the ingest API as separate work
once there is something real to ingest from.
