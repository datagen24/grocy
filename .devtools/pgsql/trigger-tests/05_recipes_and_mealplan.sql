-- Batch C: internal recipe generation and meal plan cascades, plus recipe nesting guards.
INSERT INTO recipes (id, name, base_servings, desired_servings) VALUES (881, 'Integration Base', 2, 2);
INSERT INTO recipes (id, name, base_servings, desired_servings) VALUES (882, 'Integration Outer', 1, 1);
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (881, 3, 2, (SELECT qu_id_stock FROM products WHERE id = 3));
INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) VALUES (882, 881, 2);
-- @expect-error nested
INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) VALUES (881, 882, 1);
INSERT INTO meal_plan (day, type, recipe_id, recipe_servings, section_id) VALUES ('2026-09-14', 'recipe', 882, 1, 1);
INSERT INTO meal_plan (day, type, product_id, product_amount, product_qu_id, section_id) VALUES ('2026-09-15', 'product', 3, 2, (SELECT qu_id_stock FROM products WHERE id = 3), 1);
DELETE FROM recipes WHERE id = 882;
