-- @views users_dto permission_tree user_permissions_resolved uihelper_user_permissions
-- @views userfield_values_resolved uihelper_stock_journal uihelper_stock_journal_summary
--
-- A second and third and fourth user, so the user-facing views are not single-row, plus
-- userfields on more than one entity and a stock journal with more than one user and
-- product behind it. permission_hierarchy itself needs no seeding here - it is fully
-- populated by the migrations every install carries, admin (user 1) already holds ADMIN
-- from the same migration, and permission_tree is a query over that table alone.
--
-- Users 1 (admin), locations 1/3 and products 3/4 come from the base fixture.

-- Three users covering users_dto's three display_name branches: both names, last name
-- only, first name only. admin (user 1) already covers the "neither -> username" branch.
INSERT INTO users (id, username, first_name, last_name, password) VALUES (700, 'jdoe', 'Jane', 'Doe', 'fixture-password-hash');
INSERT INTO users (id, username, first_name, last_name, password) VALUES (701, 'bsmith', NULL, 'Smith', 'fixture-password-hash');
INSERT INTO users (id, username, first_name, last_name, password) VALUES (702, 'firstonly', 'Firstonly', NULL, 'fixture-password-hash');

-- Permission grants below admin, so user_permissions_resolved and uihelper_user_permissions
-- have more than the trivial one-user-with-everything case. Granting a parent node resolves
-- to it and every descendant (permission_tree is a downward walk of permission_hierarchy);
-- granting a leaf (TASKS_MARK_COMPLETED) resolves to just itself, which is the other case
-- worth having.
INSERT INTO user_permissions (user_id, permission_id) VALUES (700, (SELECT id FROM permission_hierarchy WHERE name = 'STOCK'));
INSERT INTO user_permissions (user_id, permission_id) VALUES (701, (SELECT id FROM permission_hierarchy WHERE name = 'CHORES'));
INSERT INTO user_permissions (user_id, permission_id) VALUES (702, (SELECT id FROM permission_hierarchy WHERE name = 'TASKS_MARK_COMPLETED'));

-- Userfields on three different entities, one of them a "userentity-*" custom entity, which
-- userfield_values_resolved also surfaces a second time under the generic 'userobjects'
-- entity via its UNION branch. Values point at real rows from the base fixture (product 3
-- and 4, chore 3) except the custom-entity one, which has no backing table to point at.
INSERT INTO userfields (id, entity, name, caption, type, show_as_column_in_tables) VALUES (700, 'products', 'test_field_products', 'Test Field Products', 'text', 0);
INSERT INTO userfields (id, entity, name, caption, type, show_as_column_in_tables) VALUES (701, 'chores', 'test_field_chores', 'Test Field Chores', 'number', 0);
INSERT INTO userfields (id, entity, name, caption, type, show_as_column_in_tables) VALUES (702, 'userentity-mydevices', 'test_field_devices', 'Device Field', 'text', 0);
INSERT INTO userfield_values (field_id, object_id, value) VALUES (700, '3', 'hello');
INSERT INTO userfield_values (field_id, object_id, value) VALUES (700, '4', 'world');
INSERT INTO userfield_values (field_id, object_id, value) VALUES (701, '3', '42');
INSERT INTO userfield_values (field_id, object_id, value) VALUES (702, '1', 'foo');

-- Stock journal: two products, three users (one of them, 999, does not actually exist).
-- uihelper_stock_journal LEFT JOINs users_dto, so the row with user_id 999 still appears
-- with a null user_display_name; uihelper_stock_journal_summary JOINs it instead, so that
-- same row is silently absent from the summary. That is a real, exercised difference
-- between the two views, not an oversight.
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, price, location_id, user_id, correlation_id, note)
VALUES (3, 5, '2026-01-10', 'journal-1', 'purchase', 3.00, 1, 1, 'corr-700', 'Admin purchase, baseline row for the journal view');
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, location_id, user_id, note)
VALUES (3, 2, '2026-01-15', 'journal-2', 'consume', 1, 700, 'Consumed by the second user');
-- Undone: still shows in uihelper_stock_journal, excluded from uihelper_stock_journal_summary's WHERE undone = 0.
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, location_id, user_id, undone, undone_timestamp, note)
VALUES (3, 1, '2026-01-16', 'journal-3', 'consume', 1, 1, 1, '2026-01-17 10:00:00', 'Undone entry, excluded from the summary view');
-- Two rows for the same user/product/transaction_type, so the summary view's SUM(amount) has something to add (3 + 2 = 5).
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, location_id, user_id, note)
VALUES (4, 3, '2026-02-01', 'journal-4', 'purchase', 3, 700, 'Second product, freezer location, first of a pair the summary sums');
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, location_id, user_id, note)
VALUES (4, 2, '2026-02-05', 'journal-5', 'purchase', 3, 700, 'Second of the pair the summary view sums to 5');
-- user_id 999 does not exist - see the comment above.
INSERT INTO stock_log (product_id, amount, purchased_date, stock_id, transaction_type, location_id, user_id, note)
VALUES (3, 4, '2026-03-01', 'journal-6', 'inventory-correction', 1, 999, 'Orphaned user_id: null display name in the journal, absent from the summary');
