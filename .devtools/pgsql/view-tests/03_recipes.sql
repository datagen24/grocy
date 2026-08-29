-- @views recipes_resolved recipes_pos_resolved recipes_nestings_resolved
-- @views recipes_missing_product_counts meal_plan_internal_recipe_relation
-- @views stock_missing_products shopping_lists_view uihelper_shopping_list
--
-- Recipes, the meal plan and shopping lists in the states those views distinguish
-- between: a recipe that nests another recipe, so fulfilment has to walk through the
-- nesting rather than just its own positions; one position in stock and one not, so a
-- recipe's fulfilment has a middle case rather than being simply "yes" or "no"; a
-- product missing stock alone and a parent product missing it in aggregate with its sub
-- product; a shopping list with items, one priced and one never purchased; and a single
-- meal_plan row, whose own AFTER INSERT trigger builds the internal day/week/shadow
-- recipes that meal_plan_internal_recipe_relation joins against -- letting it do that is
-- what actually exercises all three of the view's branches. Dates are fixed, not
-- relative to today, for the same reason as 01_stock_basics.sql.
--
-- Recipes 2/3, products 3-6, shopping_lists 1, users 1 and meal_plan_sections 1 come
-- from fixtures/00_base.sql. New rows use ids 700+.

-- Recipe 3 (base fixture, no positions yet) gets stock recipe 2 does not, so the two
-- existing fixture recipes land on opposite sides of "fulfilled": recipe 2 (product 3,
-- amount 1) has nothing in stock; recipe 3 has no positions at all until this seed adds
-- one on product 3 as well, comfortably in stock.
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (3, 3, 1, 2);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (3, 5, '2027-01-01', '2026-06-01', 'view-rp-3-1', 1.50, 1, 1, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (3, 5, '2027-01-01', '2026-06-01', 'view-rp-3-1', 'purchase', 1.50, 1, 1, 1);

-- One new product for the nested recipe below, referenced twice (see the comment by
-- recipes_pos further down for why it is the same product both times rather than two
-- differently-named ones).
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (703, 'Seed Recipe Product', 'Enough stock for a small amount, not for a large one', 1, 1, 2, 2, 0, 0);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (703, 6, '2027-01-01', '2026-06-01', 'view-rp-703-1', 3.00, 1, 1, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (703, 6, '2027-01-01', '2026-06-01', 'view-rp-703-1', 'purchase', 3.00, 1, 1, 1);

-- 700 is a nested "component" recipe, included by 701 with a servings factor -- this is
-- what recipes_nestings_resolved and recipes_pos_resolved's recursion actually have to
-- walk. 701 also has 2/4 desired-vs-base servings, so its own position needs scaling.
INSERT INTO recipes (id, name, description, base_servings, desired_servings, type)
VALUES (700, 'Seed Nested Recipe', 'Included by 701', 1, 1, 'normal');
INSERT INTO recipes (id, name, description, base_servings, desired_servings, type)
VALUES (701, 'Seed Main Recipe', 'Includes 700; partly in stock', 2, 4, 'normal');
INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) VALUES (701, 700, 3);

-- Both positions are product 703, deliberately -- not two differently-named products.
-- recipes_resolved.product_names_comma_separated is a bare string_agg/group_concat with
-- no ORDER BY, so its row order is whatever each engine's aggregation happens to visit
-- first; with two different product names that is an unforced difference the suite would
-- report as a DIFF even though both engines are faithfully unordered. Using the same
-- product for both positions keeps the comparison meaningful without relying on
-- aggregate order: the string is identical either way, and the fulfilment split is what
-- is actually being tested. 701's own position (amount 2, scaled to 4) is covered by the
-- 6 in stock; 700's position, reached only through the nesting (amount 100, scaled to
-- 600), is not -- so recipe 701 ends up with need_fulfilled = 0 overall despite one of
-- its two positions being satisfied. Recipe 700 on its own is simply unfulfilled.
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (701, 703, 2, 2);
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (700, 703, 100, 2);

-- The meal plan. Its internal day/week/shadow recipes are NOT inserted here -- the
-- create_internal_recipe trigger (AFTER INSERT ON meal_plan) builds them itself, nesting
-- the day- and week-recipes onto recipe_id 701, which is what pulls the whole 701/700
-- tree above one level further and is what actually exercises
-- meal_plan_internal_recipe_relation's three branches (day, week, shadow) end to end,
-- including the week-recipe name that victual_sqlite_percent_w (hazard 9) has to agree
-- with the SQLite-computed one on.
INSERT INTO meal_plan (id, day, type, recipe_id, recipe_servings, section_id) VALUES (700, '2026-01-19', 'recipe', 701, 4, 1);

-- stock_missing_products: 706 is a plain product, partly in stock (4 of a required 10).
-- 707 is a plain product with no stock at all (fully missing). 708/709 are a parent and
-- sub product with cumulate_min_stock_amount_of_sub_products = 1, so the aggregate
-- branch of the view is exercised too, not just the simple one.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (706, 'Seed Missing Product', 'Partly in stock', 1, 1, 2, 2, 10, 0);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (707, 'Seed Empty Product', 'Never in stock', NULL, 1, 2, 2, 5, 0);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, cumulate_min_stock_amount_of_sub_products)
VALUES (708, 'Seed Missing Parent', 'Cumulates its sub product''s min stock amount', 1, 1, 2, 2, 0, 0, 1);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, parent_product_id)
VALUES (709, 'Seed Missing Sub Product', 'Partly in stock, rolled up into 708', 1, 1, 2, 2, 8, 0, 708);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (706, 4, '2027-01-01', '2026-06-01', 'view-smp-706-1', 1.00, 1, 1, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (706, 4, '2027-01-01', '2026-06-01', 'view-smp-706-1', 'purchase', 1.00, 1, 1, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (709, 2, '2027-01-01', '2026-06-01', 'view-smp-709-1', 1.00, 1, 1, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (709, 2, '2027-01-01', '2026-06-01', 'view-smp-709-1', 'purchase', 1.00, 1, 1, 1);

-- A shopping list with items: product 4 (base fixture) has a purchase behind it, so
-- uihelper_shopping_list's price columns are non-null; product 5 has never been
-- purchased, so they stay null. A second, empty list exercises item_count = 0 on
-- shopping_lists_view alongside list 1's item_count = 2.
INSERT INTO shopping_lists (id, name, description) VALUES (700, 'Seed Empty List', 'No items');
INSERT INTO shopping_list (product_id, note, amount, shopping_list_id, qu_id) VALUES (4, NULL, 3, 1, 2);
INSERT INTO shopping_list (product_id, note, amount, shopping_list_id, qu_id) VALUES (5, 'Never purchased', 2, 1, 2);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (4, 1, '2027-01-01', '2026-06-01', 'view-sl-4-1', 0.80, 1, 1, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (4, 1, '2027-01-01', '2026-06-01', 'view-sl-4-1', 'purchase', 0.80, 1, 1, 1);
