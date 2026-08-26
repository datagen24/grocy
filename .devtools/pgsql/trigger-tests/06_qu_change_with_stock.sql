-- Batch A's heaviest trigger, which rescales amounts across chores, meal_plan,
-- recipes_pos, shopping_list, stock and stock_log at once - and so reaches batch B.
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (2, 3, 0.001, 6);
UPDATE products SET qu_id_stock = 3 WHERE id = 6;
