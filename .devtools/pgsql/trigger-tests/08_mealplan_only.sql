-- Isolates the internal-recipe generation that batch C deliberately diverges on.
INSERT INTO meal_plan (day, type, recipe_id, recipe_servings, section_id) VALUES ('2026-01-04', 'recipe', 2, 1, 1);
INSERT INTO meal_plan (day, type, recipe_id, recipe_servings, section_id) VALUES ('2026-01-05', 'recipe', 3, 2, 1);
INSERT INTO meal_plan (day, type, product_id, product_amount, product_qu_id, section_id) VALUES ('2026-01-05', 'product', 3, 2, (SELECT qu_id_stock FROM products WHERE id = 3), 1);
