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
3. An object identifer. Every emitted code uses `[0-9]+` — a row id — and every code this
   fork ever emits will, because [ADR-0011](adr/0011-label-namespace.md) (accepted
   2026-09-04) makes Grocycode an input symbology: no new type, no new id shape, and no
   emission at all once that record's print outbox lands.
   [Plan 06](plans/06-location-barcodes.md)'s Q1 response once put a UUID here for
   locations (`grcy:l:{uuid}`) because a label outlives the row id printed on it; that
   reasoning was right, and is exactly what ADR-0011 generalized — into a separate
   `vctl:<uid>` namespace rather than a non-numeric Grocycode id. The format still does not
   *require* a numeric id, and a parser reading codes from the wider grocy world should
   read this part as "an opaque token containing no colon" — but the non-numeric id this
   document warned about is not something this fork will produce.

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

**There is no fifth.** [Plan 06](plans/06-location-barcodes.md) proposed `l` for
Locations; [ADR-0011](adr/0011-label-namespace.md) decided against it — no new Grocycode
type is added, ever, and location labels carry `vctl:<uid>` instead. The four above are
the whole set.

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
default rather than a restriction: the encoding is a per-label choice, and the
serialization above is what must not vary. QR was going to join it here — plan 06 argued
that a location label is read by a phone camera as often as by a dedicated scanner, and
that QR is what phones decode reliably. [ADR-0011](adr/0011-label-namespace.md) took that
argument with the rest of the label question: **QR is the symbology of the `vctl:`
namespace, not a third `GROCYCODE_TYPE`.** DataMatrix and Code128 stay exactly as they
are, for as long as this fork still renders Grocycodes at all, which ADR-0011's print
outbox ends when it lands.

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

Checked again 2026-09-04, when [ADR-0011](adr/0011-label-namespace.md) was accepted. Three
of the statements above were true only because plan 06 was going to make them true — a
fifth entity type, a UUID in the object id, and QR as a third `GROCYCODE_TYPE` — and the
record decided against all three by moving new labels out of this format entirely. They are
corrected above. Note what did *not* change: the serialization, the magic, the four entity
types and the parser's obligations, because ADR-0011 keeps this format readable forever.
The second lesson stacks on the first — documentation drifts toward what the plans intend as
readily as toward what the code does, and an accepted record that cancels a plan has to be
walked back through the documents that were already written as if it had shipped.
