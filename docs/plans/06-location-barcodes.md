# 06. Location barcodes

**Goal:** Print and scan barcodes for storage locations.
**Depends on:** nothing. Pairs naturally with [08](08-nested-locations.md) but does not
need it.
**Status:** draft for review.

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

### Scanning

The scan-input handler resolves a Grocycode to an entity and navigates accordingly. A
location code should do something sensible; what exactly is Q1.

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

1. **What should scanning a location barcode actually do?** Candidates, not exclusive:
   - filter the stock overview to that location ("what is in this cupboard")
   - preselect it as the target in purchase / transfer / inventory, so you scan the shelf
     then scan the items onto it
   - open the location's edit form

   The second is the one that makes barcoded shelves genuinely useful, and it implies a
   small amount of state ("current location" for the next scans) that Grocy does not have
   today. Worth deciding before building, since it is the difference between a
   half-day change and a two-day one.
2. **Should other master data get codes at the same time?** `shopping_locations` (stores),
   `quantity_units`, `product_groups` are all the same one-line change. Adding them
   together costs almost nothing; adding them later costs another round of the same work.
3. **Does the label need the tree path** or just the name? Path is more useful physically
   but longer on a small label.

## Effort

Small — half a day for the code plus printing, if Q1 resolves to "open" or "filter".
Closer to two days if it resolves to "preselect as target for subsequent scans", because
that introduces a scan-context concept.
