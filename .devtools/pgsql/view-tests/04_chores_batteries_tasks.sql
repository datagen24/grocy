-- @views chores_current chores_assigned_users_resolved chores_execution_average_frequency
-- @views chores_execution_timeline chores_execution_users_statistics batteries_current
-- @views tasks_current stock_average_product_shelf_life
--
-- Chores, batteries and tasks in the states their scheduling and statistics views actually
-- distinguish between. Dates are fixed, never relative to today: chores_current reads
-- LOCALTIMESTAMP itself for its rollover check, so this fixture avoids rollover=1 entirely
-- rather than trying to pin a "still due" state against a clock it does not control.
--
-- User 1 (admin) comes from the base fixture and a default install; it has no second user,
-- so one is added here (id 700) purely so chores can be assigned to and executed by more
-- than one real user - chores_assigned_users_resolved joins against actual users rows, and
-- an assignment_config referring to a user id that does not exist would silently resolve
-- to nobody. Chore 3 and battery 2 from the base fixture are untracked
-- "manually"/zero-interval cases already, so this file adds the cases those do not cover.
INSERT INTO users (id, username, password) VALUES (700, 'chore_helper', 'fixture-password-hash');

-- Chore 700: "manually" scheduled and never tracked. Assigned to two users, so
-- chores_assigned_users_resolved has more than one row for one chore, and
-- chores_execution_users_statistics gets a zero-execution case for both of them.
INSERT INTO chores (id, name, description, period_type, period_interval, assignment_type, assignment_config, active)
VALUES (700, 'Water the plants', 'Manual chore, never tracked, assigned to two users', 'manually', 1, 'random', '1,700', 1);

-- Chore 701: "daily", track_date_only = 1. Tracked once, which exercises chores_current's
-- date-only formatting branch (next_estimated_execution_time forced to 23:59:59) on top of
-- the ordinary daily-interval calculation.
INSERT INTO chores (id, name, description, period_type, period_interval, track_date_only, start_date, assignment_type, active)
VALUES (701, 'Take out recycling', 'Daily chore tracked once; track_date_only=1 exercises the date-only formatting branch', 'daily', 1, 1, '2026-01-01 08:00:00', 'no-assignment', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (701, '2026-06-15 08:00:00', 1, 0);

-- Chore 702: "weekly", restricted to Mondays via period_config. Tracked once so the CTE
-- that finds the next matching weekday has a real tracked_time to start from.
INSERT INTO chores (id, name, description, period_type, period_interval, period_config, start_date, assignment_type, active)
VALUES (702, 'Clean windows', 'Weekly chore, config restricts it to Mondays', 'weekly', 1, 'monday', '2026-01-05 09:00:00', 'no-assignment', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (702, '2026-06-08 09:00:00', 1, 0);

-- Chore 703: "adaptive" - its next execution is derived from past intervals rather than a
-- fixed calendar rule, so it is the one that actually exercises
-- chores_execution_average_frequency. Tracked four times alternating between two users, so
-- chores_execution_timeline has both a first execution (no "before" row, frequency_hours
-- NULL) and later ones with a real gap, and chores_execution_users_statistics has two
-- distinct, non-trivial execution counts to compare.
INSERT INTO chores (id, name, description, period_type, assignment_type, assignment_config, active)
VALUES (703, 'Feed the fish', 'Adaptive chore tracked by two users, for the average-frequency and per-user statistics views', 'adaptive', 'random', '1,700', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (703, '2026-01-01 08:00:00', 1, 0);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (703, '2026-01-05 08:00:00', 700, 0);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (703, '2026-01-10 08:00:00', 1, 0);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (703, '2026-01-12 08:00:00', 700, 0);

-- Chore 704: "monthly", but manually rescheduled and reassigned to a different user than
-- next_execution_assigned_to_user_id. rescheduled_date takes priority over the periodic
-- calculation entirely, and is_rescheduled/is_reassigned in chores_current only turn on
-- because both override columns are set here.
INSERT INTO chores (id, name, description, period_type, period_interval, period_days, start_date, next_execution_assigned_to_user_id, rescheduled_date, rescheduled_next_execution_assigned_to_user_id, assignment_type, assignment_config, active)
VALUES (704, 'Change air filters', 'Monthly chore, manually rescheduled and reassigned - exercises is_rescheduled/is_reassigned and the rescheduled_date override', 'monthly', 1, 15, '2026-01-15 07:00:00', 1, '2026-09-01 10:00:00', 700, 'random', '1,700', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (704, '2026-05-01 07:00:00', 1, 0);

-- Battery 700: a normal charge_interval_days, three completed charge cycles plus one
-- undone one that must not count toward last_tracked_time.
INSERT INTO batteries (id, name, description, used_in, charge_interval_days, active)
VALUES (700, 'Smoke Detector', 'Charged periodically; several charge cycles for last/next estimated time', 'Hallway smoke detector', 90, 1);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (700, '2026-01-01 00:00:00', 0);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (700, '2026-04-05 00:00:00', 0);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (700, '2026-07-10 00:00:00', 0);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (700, '2026-08-01 00:00:00', 1);

-- Battery 701: charge_interval_days = 0, which batteries_current treats as "never due
-- again" (the 2999-12-31 sentinel), even though this one has real charge history - the
-- sentinel branch short-circuits regardless of last_tracked_time.
INSERT INTO batteries (id, name, description, used_in, charge_interval_days, active)
VALUES (701, 'Never-Charge Sensor', 'charge_interval_days = 0, exercises the sentinel next-charge-time branch', 'Weather sensor', 0, 1);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (701, '2026-02-01 00:00:00', 0);

-- Tasks 700/701: one open, one done, so tasks_current's WHERE done = 0 has something real
-- to exclude rather than an empty table trivially passing the filter.
INSERT INTO tasks (id, name, description, due_date, done, assigned_to_user_id)
VALUES (700, 'Renew car registration', 'Not done - appears in tasks_current', '2026-09-15', 0, 1);
INSERT INTO tasks (id, name, description, due_date, done, done_timestamp)
VALUES (701, 'File taxes', 'Already done - excluded from tasks_current but still a row in tasks', '2026-04-15', 1, '2026-04-10 12:00:00');

-- Product 700 carries a purchase history so stock_average_product_shelf_life has an actual
-- average to compute (10, 20 and 30 day gaps -> 20). Base products 3-6 carry no stock_log
-- rows in this file, so they exercise the COUNT = 0 -> -1 branch for free.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (700, 'Shelf Life Test Product', 'Has purchase history for stock_average_product_shelf_life to average', 1, 1, 2, 2, 0, 0);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, location_id, user_id)
VALUES (700, 2, '2026-01-11', '2026-01-01', 'shelf-1', 'purchase', 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, location_id, user_id)
VALUES (700, 2, '2026-02-21', '2026-02-01', 'shelf-2', 'purchase', 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, location_id, user_id)
VALUES (700, 2, '2026-03-31', '2026-03-01', 'shelf-3', 'inventory-correction', 1, 1);
-- Not in the view's transaction_type filter - proves the filter actually filters.
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, location_id, user_id)
VALUES (700, 1, '2026-04-05', '2026-04-01', 'shelf-4', 'consume', 1, 1);
