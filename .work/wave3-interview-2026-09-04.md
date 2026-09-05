# Wave 3 gating interview — 2026-09-04 (process record, not a deliverable)

Answers given by Steve in order. Reasoning/alternatives kept here; plan files get only the Response.

## 19-Q8 — gate reads?  → (a) gate reads in piece 1
Rejected: (c) out-of-scope (my recommendation), (b) piece 2. Consequence: piece 1 is a model
change; wave 3 re-planned around it.

## 19-Q1 — split direction → X_VIEW leaf under X, applied uniformly to stock, shopping list,
chores, tasks, recipes. X keeps read+write meaning; no grant rows change on upgrade.

## Upgrade rule (new, follows from (a)) → migration grants X_VIEW to every existing user for
each of the five subtrees; behaviour-preserving, admins remove deliberately.
Rejected: close reads on upgrade (breaks HA/API-key users).

## 19-Q3 — meal plan → split: MEALPLAN_VIEW leaf under RECIPES_MEALPLAN; Child seed role
holds only the view leaf. (Against my lean.)

## 19-Q2 — field policy → TABLE (`permission_fields`), against my lean. Open detail: the
contract snapshot must then be generated from the *seeded* rows (migration data, versioned),
not from a live database — to confirm with Steve.

## 19-Q6 — STOCK_PURCHASE → implies STOCK_PRICES_VIEW (against my lean). Child role that
purchases sees prices; Child seed role therefore must not hold STOCK_PURCHASE if prices are
to stay hidden — surface this.

## 19-Q7 — Guest → yes, seeded: STOCK_VIEW + RECIPES_VIEW (+ MEALPLAN_VIEW? ask). Four roles.

## Consequences confirmed → snapshot generated from seeded `permission_fields` rows; Child
seed role excludes STOCK_PURCHASE (so prices stay hidden from Child).

## 19-Q9 → 19 piece 1 takes it (NOT wave 2). Endpoint moves to USERS_READ read / USERS_EDIT
write, resolved shape + via_roles, together in piece 1. Wave 2 PR leaves it alone.

## Wave 3 shape → split: 3a = 19 piece 1 alone (model change, gates every read path);
3b = 03, 06 (and 09 if the experiment is done) as parallel disjoint tracks.
Rejected: parallel with additive overlap; features first.

## 06 interactive scanning → out of 06; its own later plan, after 08.
## 0008 retirement work → before wave 3a, its own PR ("wave 2.5 — retirements").
## 20 piece 3 → same retirements sitting as 0008's work.

Still kitchen-dependent (not asked): 07-Q6 (taxonomy vs packaging → 03's scope), 09-Q1
experiment. Guest role composition: STOCK_VIEW + RECIPES_VIEW; MEALPLAN_VIEW — ask.
