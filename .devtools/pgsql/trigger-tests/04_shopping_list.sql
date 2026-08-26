-- Batch B: shopping list defaults and cascade when a list is deleted.
INSERT INTO shopping_lists (id, name) VALUES (91, 'Integration List');
INSERT INTO shopping_list (product_id, amount, shopping_list_id) VALUES (4, 2, 91);
INSERT INTO shopping_list (product_id, amount, shopping_list_id) VALUES (5, 7, 91);
UPDATE shopping_list SET amount = 12 WHERE shopping_list_id = 91 AND product_id = 5;
DELETE FROM shopping_lists WHERE id = 91;
