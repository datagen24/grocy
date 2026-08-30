Grocycode
==========

Grocycode is, in essence, a simple way to reference to arbitrary Victual entities.
Each Grocycode includes a magic, an entitiy identifier, an id and an ordered set of extra data.
It is supported to be entered anywhere Victual expects one to read a barcode, but can also reference
Victual-internal properties like specific stock entries, or specific batteries.

Serialization
----

There are three mandatory parts in a Grocycode:

1. The magic `grcy`, which keeps its spelling from upstream grocy on purpose: it is a
   wire format printed onto physical labels, and those outlive branding. Renaming it
   would invalidate every label already printed by an upstream instance and break
   `bin/victual-db-import`, so `grcy` stays permanently
   ([plan 16](plans/16-project-rename.md), Tier 0). The same goes for the name
   *Grocycode* itself, which names that format rather than the project.
2. An entity identifer matching the regular expression `[a-z]+` (that is, lowercase english alphabet without any fancy accents, minimum length 1 character).
3. An object identifer. Today every emitted code uses `[0-9]+` — a row id — but the format
   does not require it, and [plan 06](plans/06-location-barcodes.md)'s Q1 response puts a
   UUID here for locations (`grcy:l:{uuid}`, the uuid *as* the id rather than appended as
   extra data), because a label outlives the row id printed on it. A parser must therefore
   accept a non-numeric id. Read this part as "an opaque token containing no colon"; the
   numeric form is a property of what currently exists, not a constraint of the format.

Optionally, any number of further data without format restrictions besides not containing any colons [0] may be appended.

These parts are then linearly appended, seperated by a single colon `:` — as every example
in this document shows, and as `Grocycode::__toString` emits. This document said "double
colon" in four places for a format that has never used one; the phrase is corrected here
and in the scanner note below.

Entity Identifers
----

Currently, there are four different entity types defined, per `Grocycode::$Items`:

- `p` for Products
- `b` for Batteries
- `c` for Chores
- `r` for Recipes

[Plan 06](plans/06-location-barcodes.md) adds a fifth, `l` for Locations.

Example
----

In this example, we encode a *Product* with ID *13*, which results in `grcy:p:13` when serialized.

Product grocycodes
----

Product grocycodes extend the data format to include an optional stock id, thus may reference a specific stock entry directly.

Example: `grcy:p:13:60bf8b5244b04`

Battery grocycodes
----

Currently, Battery grocycodes do not define any extra fields.

Chore grocycodes
----

Currently, Chore grocycodes do not define any extra fields.

Recipe grocycodes
----

Currently, Recipe grocycodes do not define any extra fields.

Visual Encoding
----

Victual uses DataMatrix 2D (or alternatively Code128 1D) Barcodes to encode grocycodes into a visual representation. In principle, there is no problem with using
other encoding formats like QR codes; however DataMatrix uses less space for the same information and redundancy and is a bit
easier read by 2D barcode scanners, especially on non-flat surfaces.

That paragraph is upstream's reasoning and it stays, but it is an argument about the
default rather than a restriction: [plan 06](plans/06-location-barcodes.md) adds QR
alongside DataMatrix, because a location label is read by a phone camera as often as by a
dedicated scanner and QR is what phones decode reliably. The encoding is a per-label
choice; the serialization above is what must not vary.

You can pick up cheap-ish used scanners from ebay (about 45€ in germany). Make sure to set them to the correct keyboard emulation,
so that the colons get entered correctly.


Notes
---
[0]: Obviously, it needs to be encoded into some usable visual representation and then read. So probably you only want to encode stuff that can be typed on a keyboard.

Accuracy note
---

This document is the authority for a wire format that [plan 16](plans/16-project-rename.md)'s
Tier 0 says must never drift, and it had drifted from `helpers/Grocycode.php` on four
counts at once — three entity types against the code's four, `[0-9]+` against a UUID that
plan 06 had already accepted, "double colon" against a format that uses one, and DataMatrix
stated as the encoding rather than the default. All four are corrected above (2026-08-30,
rigor review B7). The lesson is the obvious one and worth writing down where it happened:
the guarantee that a format never changes is not the same as the guarantee that its
documentation is right, and this file is only load-bearing if it is checked against
`Grocycode.php` when either moves.
