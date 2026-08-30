# 19. Roles and data-visibility permissions

**Goal:** Let a household say "adults see what things cost, children see chores and
recipes, one person administers" as three named roles rather than thirty checkboxes per
user — and make "see what things cost" a permission the server actually enforces, on every
channel, rather than a column the web UI hides.
**Depends on:** [11](11-api-error-handling.md) for the single error helper (a redacted
field and a refused call must be distinguishable and both must be spec'd); wave 2's
**S5/S6** (never grant what the caller lacks) because role assignment is a grant;
[14](14-contract-and-regression-scaffolding.md) piece 2 for the response-contract snapshot
that proves redaction. Feeds [04](04-seed-datasets.md) (the three roles are a seed) and
constrains [02](02-mcp-endpoint.md) and [18](18-mqtt-state-publication.md) (both are
channels that carry prices — see Q4 and Q5).
**Status:** draft for review. Not in any wave yet; the sequencing section proposes
wave 3, after 11 and before any client work in [17](17-ecosystem-clients.md) resumes,
because the Swift client renders price fields and needs to know they may be absent.

## Why this is two plans wearing one number

The question that raised this — *who can see pricing history and costs?* — has no answer
in the current model, and the reason is structural rather than a missing checkbox. Every
one of the 30 permissions in `permission_hierarchy` gates an **action**: consume, purchase,
open, transfer, edit master data, mark a chore done. Not one gates a **field**. A user
holding `STOCK` sees `stock.price`, `stock_log.price`, `products_average_price`,
`product_price_history`, `products_last_purchased.price`, a product's `last_price` and
`avg_price` in `/stock/products/{id}`, and every recipe's `costs`, because those are
carried by the same rows and views the action permissions unlock.

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
becomes a 403 (or, on the web side, an error page). `permission_hierarchy` is itself an
exposed read-only entity, so clients can enumerate the tree.

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

**Recipes.** `RECIPES` is one permission. It gates viewing, creating, editing and
deleting recipes and their ingredients (`GenericEntityApiController` maps `recipes`,
`recipes_pos`, `recipes_nestings` writes to it; `MASTER_DATA_EDIT` does not cover them).
A child who can *read* recipes can rewrite them.

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
| **Child** | `CHORES`, `CHORE_TRACK_EXECUTION`, `RECIPES_VIEW` (piece 2 split), `RECIPES_MEALPLAN` read (Q3), `STOCK`, `STOCK_CONSUME`, `STOCK_OPEN`, `SHOPPINGLIST`, `SHOPPINGLIST_ITEMS_ADD`, `TASKS`, `TASKS_MARK_COMPLETED`, `CALENDAR`, `USERS_EDIT_SELF` | everything that creates or destroys: purchase, inventory, transfer, stock edit, undo of anything, delete from shopping list, recipe edit, all master data, prices |

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
tree bound to `role_permissions` instead. Under [12](12-frontend-shared-core.md) this is
one list/form pair on the shared core, which is the argument for landing 12 first.

### Piece 2 — data-visibility permissions

#### New permission leaves

| Name | Parent | Gates |
|---|---|---|
| `STOCK_PRICES_VIEW` | `STOCK` | every field in the table above, on every channel |
| `RECIPES_VIEW` | `RECIPES` | reading recipes; `RECIPES` itself keeps write (Q1) |

`STOCK_PRICES_VIEW` under `STOCK` means anyone who already holds `STOCK` or `ADMIN`
resolves to it through the tree, so **an upgraded instance behaves exactly as before**
until an administrator removes the leaf from a user or builds a role without it. That is
the migration-safety property this plan holds itself to: no existing user loses a field on
upgrade.

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
        'product_details'           => ['last_price', 'avg_price'],
        'stock_entry'               => ['price'],
        'recipe_fulfillment'        => ['costs'],
    ],
];
```

Redaction is applied in **`BaseApiController::ApiResponse()`** (the one funnel every API
response passes through, which [11](11-api-error-handling.md) is already centralising)
and in **`BaseController`'s view data** for the Blade side. Redacted fields are
**removed from the object**, not nulled — a null price is a legitimate value in
`stock_log` (a consumption has none) and must stay distinguishable from "you may not see
this". `GET /stock/products/{id}/price-history` is not redacted, it is refused: the whole
endpoint is the field, so it gets `User::CheckPermission(STOCK_PRICES_VIEW)` and 11's
403 body.

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

## Sequencing

- **After 11** — the redaction funnel and the 403/400 bodies are 11's `ApiResponse` and
  error helper; building them here first would mean building them twice.
- **After wave 2's S5/S6** — the subset-of-caller rule is theirs; roles reuse it.
- **After 12** for the UI, or accept that `/roles` is the last pre-12 form written. The
  API and enforcement do not wait on 12.
- **Before 17 resumes on Grocy-SwiftUI** — the client renders `price` and `costs`
  unconditionally; it needs to treat them as optional before a Child logs in from a
  phone. This is a Coupling for 17's table.
- **Before 02 exposes any stock tool** and **before 18 merges** — see Q4, Q5.
- Proposed slot: **wave 3**, as its own track; it touches `User.php`, `0110`-successor
  views, `BaseApiController`, `UsersApiController` and the users views, none of which 06
  or 03 open.

## Open questions

1. **`RECIPES_VIEW` under `RECIPES` (keeps today's meaning of `RECIPES`) or
   `RECIPES_EDIT` under `RECIPES` (makes `RECIPES` read-only, which is how `STOCK` and
   `CHORES` already work)?** The second is consistent with the rest of the tree and is
   what a new user would expect; the first is the one that changes nothing on upgrade.
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

4. **[02](02-mcp-endpoint.md): does the MCP endpoint run as a user?** If a tool call
   carries the API key of a user, redaction is inherited and nothing more is needed. If
   the endpoint has a service identity, it needs a role of its own — and *that* role
   decides whether an assistant a child talks to can quote prices.

5. **[18](18-mqtt-state-publication.md): published state has no reader.** If stock
   state is published with prices, every subscriber sees them regardless of role. The
   options are to publish without the `x-visibility` fields, to publish per-role topics,
   or to declare MQTT an admin channel. This plan proposes the first as the default and
   asks 18 to record which it chose.

6. **Should `STOCK_PURCHASE` imply `STOCK_PRICES_VIEW`?** A user who records purchases
   without seeing prices is coherent (a child doing the shopping run with a list) but
   odd. Leaving them independent is the flexible choice; the seed roles never combine
   them the odd way.

7. **Is there a fourth role?** "Guest" — read-only stock and recipes, nothing else — is
   the obvious one for a visitor's phone, and costs one seed row. It is not the family
   that asked, so it is a question rather than a row.

## Effort

Piece 1 (roles): small–medium. One dual-engine migration with two views rewritten, ~8
routes on `UsersApiController` (or a new `RolesApiController`), one list/form pair, seed
rows. Two to three sittings.

Piece 2 (visibility): medium. Two permission leaves, the policy constant, the funnel in
`BaseApiController`, the Blade helper, the OpenAPI annotations, and the double snapshot
in 14. The snapshot work is the bulk of it and is also the part that pays back on every
future endpoint. Three to four sittings, of which the first is answering Q1–Q3.
