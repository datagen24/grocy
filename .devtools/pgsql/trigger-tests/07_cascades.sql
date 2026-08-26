-- Batch C cascades for the smaller entities.
DELETE FROM batteries WHERE id = 2;
DELETE FROM chores WHERE id = 3;
INSERT INTO userfields (id, entity, name, caption, type) VALUES (61, 'products', 'intfield', 'Int Field', 'text');
INSERT INTO userfield_values (field_id, object_id, value) VALUES (61, '4', 'hello');
DELETE FROM userfields WHERE id = 61;
