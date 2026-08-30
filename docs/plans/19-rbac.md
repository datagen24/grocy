# 19. Roles and data-visibility permissions

**Goal:** Let a household say "adults see what things cost, children see chores and
recipes, one person administers" as three named roles rather than thirty checkboxes per
user — and make "see what things cost" a permission the server actually enforces, on every
channel, rather than a column the web UI hides.
**Depends on:** wave 2's **S5/S6** (never grant what the caller lacks) because role
assignment is a grant — this plan inherits that rule rather than defining it, which is
why S5 and S6 are not parked here. **Piece 2 additionally** depends on
[11](11-api-error-handling.md) for the single error helper (a redacted field and a
refused call must be distinguishable and both must be spec'd) and on
[14](14-contract-and-regression-scaffolding.md) piece 2 for the response-contract
snapshot that proves redaction; piece 1 needs neither, and verifies its views against
14 **piece 1**, which landed in wave 0. Feeds [04](04-seed-datasets.md) (the three
roles are a seed) and constrains [02](02-mcp-endpoint.md) and
[18](18-mqtt-state-publication.md) (both are channels that carry prices — see Q4 and Q5).
**Status:** draft for review, **blocked on its own question 8** (does this plan gate reads
at all — the answer changes piece 1's size and its wave), and **split across two waves** —
piece 1 in wave 3 as its own
track, piece 2 in wave 5 alongside 14 piece 2, whose snapshot harness it extends. Piece 1
is in wave 3 rather than later because it grows the API read surface, which the roadmap
requires to happen *before* 14 piece 2 freezes the contract; piece 2 is in wave 5 because
it changes existing response shapes and cannot precede the harness that proves it. Both
land before any client work in [17](17-ecosystem-clients.md) resumes, because the Swift
client renders price fields and needs to know they may be absent.

## Why this is two plans wearing one number

The question that raised this — *who can see pricing history and costs?* — has no answer
in the current model, and the reason is structural rather than a missing checkbox. Every
one of the 30 permissions in `permission_hierarchy` gates an **action**: consume, purchase,
open, transfer, edit master data, mark a chore done. Not one gates a **field**.

It is worse than that, and the plan is written against the wrong baseline if this is not
said first: **no permission gates a read of household data.** `PERMISSION_STOCK` is
declared at `controllers/Users/User.php:30` and checked in exactly zero places; every read
method on `StockApiController` — current stock, product details, stock entries, price
history — and both `GenericEntityApiController::GetObject` and `::GetObjects`
(`controllers/Api/GenericEntityApiController.php:242,277`) run with no `CheckPermission`
at all, and `routes.php:268` adds only CORS and JSON middleware to the group. The users
surface is the sole exception and the only gated read anywhere:
`UsersController.php:23,93` and `UsersApiController::GetUsers` (`:174`) require
`USERS_READ`, and `::ListPermissions` (`:210`) requires `ADMIN` — which is the mismatch
question 9 is about. Everywhere else, *every authenticated user* already sees `stock.price`, `stock_log.price`,
`products_average_price`, `product_price_history`, `products_last_purchased.price`, a
product's `last_price` and `avg_price` in `/stock/products/{id}`, and every recipe's
`costs` — holding nothing. The action permissions gate writes; reads are open to anyone
who can log in.

So the household policy needs two things, and they are separable:

1. **Roles** — a named bundle of permissions assignable to users. This is a thin layer
   over the machinery that already exists and changes nothing about how a permission is
   checked.
2. **A data-visibility permission** for prices, with enforcement in the service/API layer.
   This is the new kind of thing, and it is where the risk and the effort are.

They are kept in one plan because the second is what makes the first worth doing for the
family that asked, and because both touch `controllers/Users/User.php`, `0110.sql`'s
views and the users UI. They are staged separately (piece 1, piece 2) so that the roles
layer can land alone if piece 2's questions stall.

## Today

**Permissions.** `permission_hierarchy` (id, name, parent) holds 30 rows; `ADMIN` is the
root and every other permission is under it, so ADMIN resolves to everything through the
recursive `permission_tree` view. `user_permissions` (user_id, permission_id) stores the
direct grants; `user_permissions_resolved` is the closure and is what
`User::HasPermission()` queries; `uihelper_user_permissions` drives the checkbox tree at
`/user/{id}/permissions`. The API mirrors it: `GET/POST/PUT /users/{id}/permissions`.
Controllers call `User::CheckPermission($request, User::PERMISSION_*)` and the exception
becomes a 403 (or, on the web side, an error page) — but only on write paths; see above.
`permission_hierarchy` is itself an exposed read-only entity, and since the generic read
is ungated, the tree is world-readable to any authenticated user.

**The tree is downward-inclusive, and the `USERS` subtree is a chain.** Holding a
permission resolves to every *descendant* name, never to an ancestor: `permission_tree`
seeds on each id and walks to its children (`migrations/0110.sql:98-109`). Two
consequences this plan has to design around. First, granting a parent grants every leaf
under it, so a role cannot be built by naming a parent and excluding some of its children
— there is no deny. Second, `USERS` → `USERS_CREATE` → `USERS_EDIT` → `USERS_READ` is a
chain rather than a fan (`migrations/0110.sql:24-43`), so **`USERS_CREATE` alone already
resolves to `USERS_EDIT` and `USERS_READ`**: an account that may create users may today
rewrite any admin's password. That is sweep S6 as a structural fact rather than a missing
check, and it is what S6's fix in wave 2 has to be written against. The read/write split
this plan proposes for the roles API is unaffected and works as intended — `USERS_EDIT`
resolves down to `USERS_READ`.

**Defaults.** `UsersService::CreateUser()` grants `VICTUAL_DEFAULT_PERMISSIONS` to every
new user; today that is `['ADMIN']` (sweep S5, fixed in wave 2). There is no notion of "a
new user is a child".

**Prices.** `FEATURE_FLAG_STOCK_PRICE_TRACKING` is consumed in exactly two places:
`config-dist.php` where it is declared, and the Blade views, where it adds `d-none` to
price columns and hides the price inputs. No service, controller or API path reads it. It
is a per-instance UI preference, and the API returns prices whether it is on or off.
That is the same shape as sweep R1 — the flag exists on one side of the wall — and it is
the reason a per-role version cannot be built by moving the flag into the session.

**Where prices leave the server.** Enumerated from `victual.openapi.json` and the views:

| Channel | Carrier | Fields |
|---|---|---|
| `GET /objects/stock`, `/objects/stock_log` | generic entity read | `price` |
| `GET /objects/products_average_price`, `/objects/products_last_purchased` | generic entity read | `average_price`, `price` |
| `GET /stock`, `GET /stock/entry/{id}`, `GET /stock/products/{id}/entries` | StockApiController | `price` per entry |
| `GET /stock/products/{id}` | StockApiController → `StockService::GetProductDetails` | `last_price`, `avg_price` |
| `GET /stock/products/{id}/price-history` | StockApiController | the whole payload |
| `GET /objects/recipes_pos_resolved`, `GET /recipes/{id}/fulfillment` | RecipesService | `costs` |
| Blade: stockoverview, stockentries, purchase, inventory, products, recipes, mealplan, productcard | server-rendered | all of the above |
| [02](02-mcp-endpoint.md) MCP tools (draft) | whatever 02 chooses to expose | inherits the API's |
| [18](18-mqtt-state-publication.md) (draft, `pr/25`) | published state | **no user in the loop** — Q5 |

**Recipes.** `RECIPES` is one permission, and it gates *writes only* — creating, editing
and deleting recipes and their ingredients (`GenericEntityApiController` maps `recipes`,
`recipes_pos`, `recipes_nestings` writes to it; `MASTER_DATA_EDIT` does not cover them).
Reading them is ungated like every other read. So the problem is not that a child who may
read may also rewrite; it is that reading is not a grant at all, and `RECIPES_VIEW` has
nothing to narrow until it is. See Q8.

## Proposed change

### Piece 1 — roles

Roles are a bundling layer only. Nothing in the check path changes; `HasPermission()`
still reads `user_permissions_resolved`, which is widened to union the user's direct
grants with the grants of every role they hold.

#### Schema

```sql
CREATE TABLE roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    description TEXT,
    builtin     TINYINT NOT NULL DEFAULT 0,   -- seeded rows: renamable, not deletable
    row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);
CREATE TABLE role_permissions (
    role_id       INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permission_hierarchy(id),
    PRIMARY KEY (role_id, permission_id)
);
CREATE TABLE user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);
```

`user_permissions_resolved` becomes:

```sql
SELECT u.id AS id, u.id AS user_id, pt.name AS permission_name
FROM permission_tree pt, users u
WHERE pt.id IN (
    SELECT permission_id FROM user_permissions WHERE user_id = u.id
    UNION
    SELECT rp.permission_id FROM user_roles ur
      JOIN role_permissions rp ON rp.role_id = ur.role_id
    WHERE ur.user_id = u.id
);
```

`uihelper_user_permissions` gains a `via_role` column (name of the first role that grants
it, else NULL) so the checkbox tree can show a grant as inherited and read-only rather
than silently ticked. This is a dual-engine migration (PostgreSQL has no `AUTOINCREMENT`
and `TINYINT` becomes `SMALLINT`), and the `permission_tree` recursive CTE already runs on
both, so the closure needs no new engine-specific SQL.

Direct grants in `user_permissions` stay. A user's effective permissions are the union of
direct and role grants; there is no "deny". This keeps the existing API and the existing
per-user page working unchanged, and it is what lets the roles layer land with zero
behaviour change for a database that has no rows in `user_roles`.

#### Seed

Three built-in roles, shipped by the migration (and re-shippable by [04](04-seed-datasets.md)):

| Role | Grants | Deliberately absent |
|---|---|---|
| **Admin** | `ADMIN` | — |
| **Adult** | `STOCK` (all children), `SHOPPINGLIST` (all), `RECIPES`, `RECIPES_MEALPLAN`, `CHORES` (all), `TASKS` (all), `BATTERIES` (all), `EQUIPMENT`, `CALENDAR`, `USERS_READ`, `USERS_EDIT_SELF`, and piece 2's `STOCK_PRICES_VIEW` | `ADMIN`, `MASTER_DATA_EDIT`, `USERS`, `USERS_CREATE`, `USERS_EDIT` |
| **Child** | **leaves only** — `CHORE_TRACK_EXECUTION`, `STOCK_CONSUME`, `STOCK_OPEN`, `SHOPPINGLIST_ITEMS_ADD`, `TASKS_MARK_COMPLETED`, `RECIPES_MEALPLAN` (Q3), `RECIPES_VIEW` (piece 2 split), `CALENDAR`, `USERS_EDIT_SELF` | everything that creates or destroys: purchase, inventory, transfer, stock edit, undo of anything, delete from shopping list, recipe edit, all master data, prices |

The Child row is leaves-only for a reason worth stating, because the first draft of this
table got it wrong in four places. It granted `STOCK`, `SHOPPINGLIST`, `CHORES` and
`TASKS` alongside the leaves — and since the tree is downward-inclusive, each of those
grants exactly what the same row's third column says is deliberately absent: `STOCK`
carries `STOCK_PURCHASE`, `STOCK_INVENTORY`, `STOCK_TRANSFER` and `STOCK_EDIT`,
`SHOPPINGLIST` carries `SHOPPINGLIST_ITEMS_DELETE`, and `CHORES` and `TASKS` each carry
their `*_UNDO_EXECUTION`. As written it was an administrator-of-stock role. **A role in
this model is a set of leaves**; naming a parent is a decision to grant its whole subtree,
which is right for Adult and wrong for Child.

Dropping the parents costs the Child nothing today only because reads are ungated — see
Q8, which is where that debt is recorded rather than being silently relied on.

`VICTUAL_DEFAULT_PERMISSIONS` gains a sibling `VICTUAL_DEFAULT_ROLES` (default `[]`).
With wave 2's S5 in place a new user starts with nothing unless the creator assigns a
role, and the creator can only assign a role whose grants are a subset of their own
effective permissions — the same rule S5/S6 impose on direct grants, applied to the
bundle. `Admin` therefore cannot be handed out by an Adult.

#### API

Additive, in the style of the existing users endpoints:

```
GET    /roles                          list (ExposedEntity: roles is also readable via /objects)
POST   /roles                          create custom role
PUT    /roles/{id}                     rename / describe; builtin rows accept name+description only
DELETE /roles/{id}                     refused for builtin
GET    /roles/{id}/permissions
PUT    /roles/{id}/permissions         replace set (subset-of-caller rule applies)
GET    /users/{id}/roles
PUT    /users/{id}/roles               replace set (subset-of-caller rule applies)
GET    /users/{id}/permissions         unchanged shape; each row gains "via_role": string|null
```

All role management is behind `USERS_EDIT`; reading roles is behind `USERS_READ`. Editing
a **role's** permission set is a grant to every holder at once, so it also requires that
the caller's effective permissions be a superset of the *new* set, not just of the delta.

#### UI

The users list gains a Roles column. `/user/{id}/permissions` grows a roles multi-select
above the existing tree; inherited ticks render disabled with the role name as a tooltip.
A new `/roles` and `/role/{id}` pair reuses the same tree component with the checkbox
tree bound to `role_permissions` instead. Under [12](12-frontend-shared-core.md) that is
one `Victual.EntityList` call for `/roles` and **two mixin adopters, not a factory form**
— `/role/{id}` is the same partial-clone shape 12's Q5 response already buckets
`userpermissions.js` into, and this plan modifies `userpermissions.js` itself. Nor is
`/roles` a plain `EntityList`/`EntityForm` pair, since roles are readable through
`/objects` but written through `PUT /roles/{id}/permissions`. So 12's ordering applies
here for the same reason it applies to 05, 06 and 08, plus sweep S29's: a role name is a
text column rendered through `bootbox`'s `.html()` delete confirmation.

### Piece 2 — data-visibility permissions

#### New permission leaves

| Name | Parent | Gates |
|---|---|---|
| `STOCK_PRICES_VIEW` | `STOCK` | every field in the table above, on every channel |
| `RECIPES_VIEW` | `RECIPES` | reading recipes; `RECIPES` itself keeps write (Q1) |

`STOCK_PRICES_VIEW` under `STOCK` means anyone who already holds `STOCK` or `ADMIN`
resolves to it through the tree, so for those users **an upgraded instance behaves exactly
as before** until an administrator removes the leaf or builds a role without it.

That is the migration-safety property this plan wants, and **it does not hold as stated**,
because reads are ungated: a user holding only `CHORES`, or holding nothing at all, reads
every price today and holds no `STOCK` grant to inherit the new leaf from. Gating a field
that was never gated necessarily takes it from someone. The honest form of the property is
therefore narrower — *no user who holds `STOCK` or `ADMIN` loses a field on upgrade* — and
the residue is a deliberate change of behaviour for everyone else, which is the point of
the plan rather than an accident of it. Q8 asks whether the two halves land together.

`RECIPES_VIEW` is the inverse case: `RECIPES` today means read+write, and the plan keeps
that meaning (so existing grants keep working) while making `RECIPES_VIEW` the narrower
leaf a Child role can hold. Q1 asks whether to invert this to the more usual
`RECIPES_EDIT` under `RECIPES`, which is cleaner but changes what `RECIPES` alone means.

#### Enforcement — one place, at the boundary

A new `Victual\Services\FieldPolicy` (or a static on `User`, Q2) answers one question:
*which response fields must this user not see?* It returns a set of `(entity, field)`
pairs derived from the user's resolved permissions — today only the price group, but
shaped so a future `CHORES_ASSIGNEE_VIEW` or similar is a table row, not a code change:

```php
const FIELD_POLICY = [
    User::PERMISSION_STOCK_PRICES_VIEW => [
        'stock'                     => ['price'],
        'stock_log'                 => ['price'],
        'products_average_price'    => ['average_price'],
        'products_last_purchased'   => ['price'],
        'recipes_pos_resolved'      => ['costs'],
        'recipes_resolved'          => ['costs', 'costs_per_serving', 'prices_incomplete'],
        'uihelper_stock_current_overview' => ['value', 'last_price', 'average_price'],
        'products_price_history'    => ['*'],  // the /objects path; the route is refused
        'uihelper_shopping_list'    => ['last_price_unit', 'last_price_total', 'price'],
        'product_details'           => ['last_price', 'avg_price'],
        'stock_entry'               => ['price'],
        'recipe_fulfillment'        => ['costs'],
    ],
];
```

Four of those rows were missing from the first draft — `recipes_resolved`,
`uihelper_shopping_list`, `uihelper_stock_current_overview` and `products_price_history` —
and each is a real channel rather than belt-and-braces. `recipes_resolved` is returned wholesale by `GET /recipes/fulfillment`
through `FilteredApiResponse` (`controllers/Api/RecipesApiController.php:73`), so it is
both unredacted *and* filterable today; it also carries `prices_incomplete`, which is
`MIN(costs) = 0` (`migrations/0247.sql:15`) and therefore survives the redaction of
`costs` while still answering a question about them. It leaks one bit — some ingredient
has no recorded cost — rather than a value, which is enough to make the point: a policy
has to enumerate derived columns, not columns named `price`.
`uihelper_shopping_list` selects `last_price_unit`, `last_price_total` and `price`
(`migrations/0251.sql:7-9`) and is an exposed entity, so it is a live ungated price
channel today rather than a future one.
`uihelper_stock_current_overview` (`migrations/0252.sql:38-39` — the highest-numbered
migration defining it; 0219 is superseded) is what the Blade stock overview renders, what [02](02-mcp-endpoint.md)'s `stock_overview` tool reads and what
[18](18-mqtt-state-publication.md) would publish as attributes; it is not an API path
today, but [14](14-contract-and-regression-scaffolding.md)'s section 2b requires it to
become one before piece 2 freezes the contract, so it will be. And
`products_price_history` is the entity 14's 2b parked as "a deliberate widening pending a
decision" — this plan is that decision, and the leaf is what makes exposing it safe.

Redaction is applied where the entity is still known: **`FilteredApiResponse()`**
(`controllers/Api/BaseApiController.php:63`), which receives a LessQL `Result` and can
therefore tell a `stock` row from a `chores` row, plus the hand-built responses' own
shaping — and in **`BaseController`'s view data** for the Blade side. It is *not*
`ApiResponse()`: that takes bare `$data` and no entity name
(`controllers/Api/BaseApiController.php:31`), so a policy keyed by `(entity, field)`
cannot tell what it is looking at by the time it gets there. The first draft named
`ApiResponse()` and also said [11](11-api-error-handling.md) was centralising it; neither
is right. `ApiResponse()` predates 11, and what 11 centralises is the *error* path
(`HandleApiCall`). 11 is still the dependency, for the filter rule below.

Redacted fields are **removed from the object**, not nulled — a null price is a legitimate
value in `stock_log` (a consumption has none) and must stay distinguishable from "you may
not see this". **A redacted field and a refused call are already distinguishable without a
new error kind**, so this plan asks 11 for no taxonomy slot: redaction is a 200 with a
shorter body, refusal is 11's 403. `GET /stock/products/{id}/price-history` is refusal —
the whole endpoint is the field, so it gets
`User::CheckPermission(STOCK_PRICES_VIEW)` and 11's 403 body.

The filter hole that verification 5 names closes at one call site rather than in a new
mechanism. `BaseApiController::AssertFieldExists()`
(`controllers/Api/BaseApiController.php:180`) already rejects a field the entity does not
have with 400, and is reached from both the `query[]` and the `order` path with the
entity's column types in hand; it gains the caller's policy alongside the column list, and
a filter on a redacted field is refused with 11's `EInvalidApiQuery`. The message
distinguishes it from an unknown field and the status code deliberately does not, since a
distinct code would confirm the field exists.

Write paths are untouched. `price` on `POST /stock/products/{id}/add` is already gated by
`STOCK_PURCHASE`; a user who may purchase but not view prices can still record one (Q6
asks whether that is what the household wants).

#### OpenAPI

Every redactable field gets `x-visibility: STOCK_PRICES_VIEW` in `victual.openapi.json`
and is no longer listed as `required` on its schema. [14](14-contract-and-regression-scaffolding.md)'s
contract snapshot is run twice per affected path, once as Admin and once as a fixture
user without the leaf, and the second snapshot is asserted to equal the first minus
exactly the `x-visibility` fields. That assertion is the proof the feature works, and it
is also what stops a future endpoint leaking a price: a new path that returns `price`
without the annotation fails the snapshot diff for the fixture user.

Both legs are load-bearing, and it is worth saying why since the assertion is described as
the proof. A field that is absent *for everyone* — dropped by a bug, renamed, never
populated — satisfies the restricted leg exactly as a correctly redacted one does. Only
the Admin leg distinguishes them, so the restricted snapshot proves redaction only in
combination with an Admin snapshot that shows the field was there to redact.

#### UI

The Blade `d-none` on `VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING` becomes
`@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING || !User::HasPermission(STOCK_PRICES_VIEW))`
via one helper, so the instance-wide flag and the per-user leaf collapse to a single
condition. The feature flag stays: it is still the right knob for "this household does not
track prices at all".

## Verification

1. Fresh database: `user_roles` empty, every existing permission test passes unchanged.
2. Upgrade test: a pre-19 fixture with users holding `STOCK` directly; after migration
   every one of them still receives every price field on every path in the table above.
3. Roles: create Adult and Child from the seed, assign each to a fixture user, snapshot
   `GET /users/{id}/permissions` and assert `via_role` on every inherited row; remove the
   role, assert the rows revert.
4. Subset rule: as an Adult, `PUT /users/{id}/roles` with Admin → 403; `PUT
   /roles/{child}/permissions` adding `MASTER_DATA_EDIT` → 403.
5. Redaction: the double contract snapshot described above, across all paths in the
   table. Include `/objects/{entity}` with `query[]` filters on `price` — a filter on a
   redacted field must be refused (400 via 11's helper), not silently applied, or a
   child can binary-search a price by filtering on it.
6. Blade: render stockoverview, stockentries, recipes and productcard as the fixture user
   and assert no `locale-number-currency` span is emitted.
7. Views on both engines: `.devtools/pgsql/difftest.php` on `user_permissions_resolved`
   and `uihelper_user_permissions`.
8. Negative: `GET /stock/products/{id}/price-history` as the fixture user → 403 with 11's
   body, not an empty array.
9. Sweep **S27** on this plan's own new endpoints: `PUT /roles/{id}/permissions` and
   `PUT /users/{id}/roles` both take ids, which is exactly the shape S27 found writing
   unvalidated into `user_permissions` and answering 204 while granting nothing. Both
   must reject an id absent from `permission_hierarchy` / `roles` with 400 rather than
   accepting it silently. Assert it, because this plan doubles the number of places the
   bug can exist.
10. The **`userpictures` residual** stays closed under roles. Wave 2 replaces "may edit
    some user" with an ownership lookup through `users.picture_file_name`
    (`controllers/Api/FilesApiController.php:143-151`); assert here that a user whose only
    `USERS_EDIT` arrives *through a role* is bound by it identically to one holding it
    directly. That is the general shape of this plan's risk — a rule written against
    direct grants and silently not re-checked against inherited ones — and this is the
    cheapest place to catch it.

## Sequencing

**Piece 1 — wave 3, its own track.**

- **After wave 2's S5/S6** — the subset-of-caller rule is theirs and this plan reuses it.
  It is a mechanism over `user_permissions_resolved` and `permission_tree`, both of which
  exist today, so nothing about it waits on this plan; the roles layer only widens the
  view the same comparison reads. That is why the roadmap does *not* park S5 and S6 here.
- **After 12** for the UI, or accept that `/roles` is the last pre-12 form written. The
  API does not wait on 12.
- **Before 14 piece 2**, not after it. Piece 1 adds `/roles`, `/roles/{id}/permissions`,
  `/users/{id}/roles`, a `roles` exposed entity and a `via_role` field on an existing
  response — all read surface, and the roadmap's rule is that the read surface grows
  before the snapshot freezes it.
- It touches `User.php`, `0110`-successor views, `UsersApiController` and the users
  views, none of which 03, 06 or (deferred) 09 open. The two files the wave's tracks
  would share are `routes.php` and `victual.openapi.json` — additive in every case, and
  named here because the wave rule says disjoint rather than mostly disjoint.

**Piece 2 — wave 5, co-scheduled with 14 piece 2.**

- **After 11** — the 403 body a refusal returns and the 400 a refused filter returns are
  11's error helper; building them here first would mean building them twice. Note this is
  11's *error* path only: the redaction funnel is `FilteredApiResponse()`, which 11 does
  not touch, and the first draft's claim that 11 was centralising it was wrong.
- **With 14 piece 2, not after it.** The double snapshot is not a consumer of that harness
  but an extension of it: the harness has to run a path under two identities and diff the
  results, which single-identity snapshotting does not do. Build it once, in 14, with this
  plan as its first caller — and land piece 2 before the freeze is signed off, since
  removing fields from `required` changes existing response shapes.
- **Before the Swift transport is generated**, which is stronger than 17's "before the
  client work resumes". The client decodes `price` and `costs` into non-optional
  properties, so a Child logging in from a phone gets a decoding failure rather than a
  list without prices — the app stops rather than degrading. Generated after this plan,
  the optionality is generated; generated before it, every model is generated twice.
  Recorded as **Coupling 5** in 17, which has numbered Coupling sections rather than the
  table the first draft referred to.
- **Before 02 exposes any stock tool** — see Q4. **Not before 18**, which the first draft
  asked for and which is not achievable: 18 is wave 1 track D, two waves ahead of piece 1.
  Q5 moves to 18 instead, as its question 8, since 18's security notes are where that
  reasoning already lives. Moved, not answered: 18 records a lean to publish no prices and
  has no response yet, so this plan should read 18's question 8 before piece 2 rather than
  assume it.

## Open questions

1. **`RECIPES_VIEW` under `RECIPES` (keeps today's meaning of `RECIPES`) or
   `RECIPES_EDIT` under `RECIPES` (makes `RECIPES` mean read, and edit the leaf under
   it)?** The second is what a new user would expect; the first is the one that changes
   nothing on upgrade. Note that the drafted comparison to `STOCK` and `CHORES` does not
   hold — those do not gate reads either (see Q8), so there is no existing read/write
   split in the tree for this to be consistent with.
   A migration that grants `RECIPES_EDIT` to every user currently holding `RECIPES`
   gets both, at the cost of one more row per user.

2. **Where does the field policy live** — a table (`permission_fields`, editable, so a
   household can decide `note` on `stock_log` is also adults-only) or a PHP constant
   (auditable, versioned, and the contract snapshot can be generated from it)? The plan
   above says constant. A table is a small step later if wanted; a constant cannot be
   made from a table without losing the snapshot generation.

3. **Meal plan for children: read, or read+write?** `RECIPES_MEALPLAN` is one leaf
   covering both. If a child should be able to pick Tuesday's dinner, no split is
   needed. If not, it is the same split as Q1, one more time.

4. **[02](02-mcp-endpoint.md): the MCP endpoint runs as a user, and that half is already
   answered.** 02's Q1 and Q6 responses put a sidecar behind an `API_KEY_TYPE_MCP` bearer
   key that resolves to a Victual user, with every REST call permission-checked as that
   user, so redaction is inherited and this plan needs nothing further. What survives is
   narrower and belongs to 02: a single shared MCP key means *one* user's price
   visibility for every household member the assistant talks to, so the key is per person
   or its user holds no `STOCK_PRICES_VIEW`. Worth noting alongside it that 02's read
   tools already carry prices — `stock_overview` reads
   `uihelper_stock_current_overview` and `recipes_i_can_cook` reads `recipes_resolved` —
   and that 02's Q5 response keeps them out by construction (small hand-built responses)
   rather than by policy. This plan makes it policy.

5. **[18](18-mqtt-state-publication.md): published state has no reader.** If stock
   state is published with prices, every subscriber sees them regardless of role. The
   options are to publish without the `x-visibility` fields, to publish per-role topics,
   or to declare MQTT an admin channel. This plan proposes the first, and 18 already
   argues for it without naming prices: its security notes say publish nothing that would
   not also be shown on a wall tablet, and a broker subscriber is not a logged-in user and
   cannot be made into one. Per-role topics are foreclosed by the same plan's case for a
   single discovery payload. So the question is really 18's to close, and is carried there
   as its own numbered question rather than gating this plan — see Sequencing. The live
   leak to close is 18's stock summary, whose row attributes come from
   `uihelper_stock_current_overview` and therefore carry `value`, `last_price` and
   `average_price`.

6. **Should `STOCK_PURCHASE` imply `STOCK_PRICES_VIEW`?** A user who records purchases
   without seeing prices is coherent (a child doing the shopping run with a list) but
   odd. Leaving them independent is the flexible choice; the seed roles never combine
   them the odd way.

7. **Is there a fourth role?** "Guest" — read-only stock and recipes, nothing else — is
   the obvious one for a visitor's phone, and costs one seed row. It is not the family
   that asked, so it is a question rather than a row.

8. **Does this plan also gate reads, and if so does that land with piece 1 or piece 2?**
   This is the largest question the plan has and it was not in the first draft, because
   the draft assumed reads were already gated. Outside the users surface, they are not:
   no read of household data checks a permission. Three things follow. The Child role's *withheld* permissions are real
   (writes are checked) but its *granted* ones are decorative — it could hold nothing and
   still read all stock. `RECIPES_VIEW` narrows a permission that does not currently gate
   reading, so as drafted it grants nothing that is not already universal. And piece 2's
   field redaction, which *is* enforced, would be the only read-side authorization in the
   system — a field-level control sitting on top of an object-level control that does not
   exist, which is a strange shape to build and a stranger one to explain.

   The honest options are: (a) gate reads in piece 1, which means a `*_VIEW` leaf for
   stock, shopping list, chores and tasks — the same split Q1 debates for recipes, four
   more times — and turns piece 1 from a bundling layer into a model change with real
   upgrade risk; (b) gate reads in piece 2, where the redaction funnel already has to
   inspect every response and can refuse the whole object as easily as a field; or
   (c) declare read-gating out of scope and say so in the plan, accepting that roles
   restrict what a user can *do* and only prices restrict what they can *see*. Option (c)
   is coherent and is what the family actually asked for — it is only unacceptable if
   left unsaid. This question is worth answering before piece 1 is scheduled, because (a)
   changes piece 1's size and its wave.

9. **The permissions page's `ADMIN`-versus-`USERS_READ` mismatch, which
   [14](14-contract-and-regression-scaffolding.md)'s section 2b deferred to this plan.**
   `UsersController` renders `/user/{id}/permissions` behind `USERS_READ` in the resolved,
   hierarchy-joined shape; the API returns raw unresolved rows behind `ADMIN`. So a
   `USERS_READ` user can open the page, tick boxes and get a 403 on save. This plan
   changes what that endpoint returns (`via_role`) without deciding either half, and 14
   cannot freeze the contract until it does — it is one of the eight reads 2b lists. The
   resolved shape is the one both consumers want; the permission is the real question, and
   roles sharpen it, because "who may read the permission model" and "who may read *this
   user's* grants" come apart once a grant can arrive through a bundle.

   What this plan states is the rule for its own new endpoints — role management behind
   `USERS_EDIT`, reading roles behind `USERS_READ`, which the tree supports since
   `USERS_EDIT` resolves down to `USERS_READ`. It does **not** decide the existing
   `GET /users/{id}/permissions`, which the API section above leaves at "unchanged shape".
   The obvious extension is to apply the same rule there and return the resolved shape both
   consumers want, and wave 2 may take that rather than wait — but it is an extension of
   this plan's rule, not a decision this plan has recorded, and it wants an answer here
   before anyone relies on it.

## Effort

Piece 1 (roles): small–medium **if Q8 answers (b) or (c)**. One dual-engine migration
with two views rewritten, ~8 routes on `UsersApiController` (or a new
`RolesApiController`), one list plus two mixin adopters, seed rows. Two to three sittings.
If Q8 answers (a) — gate reads in piece 1 — it is a different plan: four more `*_VIEW`
leaves, a `CheckPermission` on every read path in the tree, and an upgrade in which users
who hold nothing stop being able to read anything. Answer Q8 before sizing this.

Piece 2 (visibility): medium. Two permission leaves, the policy constant, the funnel in
`BaseApiController`, the Blade helper, the OpenAPI annotations, and the double snapshot
in 14. The snapshot work is the bulk of it and is also the part that pays back on every
future endpoint. Three to four sittings, of which the first is answering Q1, Q3 and Q8 —
Q2 the plan answers itself (a constant), and Q4, Q5 and Q9 are now questions for 02, 18
and wave 2 rather than for this plan.
