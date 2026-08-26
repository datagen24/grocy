-- PostgreSQL baseline schema: indexes
--
-- Directly portable from SQLite: PostgreSQL supports expression indexes with the same syntax.

CREATE INDEX ix_batteries_performance1 ON batteries (
	id,
	active
);

CREATE INDEX ix_cache__quantity_unit_conversions_resolved_performance1 ON cache__quantity_unit_conversions_resolved (
	product_id,
	from_qu_id,
	to_qu_id
);

CREATE INDEX ix_chores_log_performance1 ON chores_log (
	chore_id,
	undone,
	tracked_time
);

CREATE INDEX ix_chores_performance1 ON chores (
	id,
	active
);

CREATE UNIQUE INDEX ix_product_barcodes ON product_barcodes (
	barcode
);

CREATE INDEX ix_products_performance1 ON products (
    parent_product_id
);

-- PostgreSQL requires an expression in an index to be parenthesised
CREATE INDEX ix_products_performance2 ON products (
	(CASE WHEN parent_product_id IS NULL THEN id ELSE parent_product_id END),
	active
);

CREATE INDEX ix_recipes ON recipes (
	name,
	type
);

CREATE INDEX ix_stock_log_performance1 ON stock_log (
	stock_id,
	transaction_type,
	amount
);

CREATE INDEX ix_stock_log_performance2 ON stock_log (
	product_id,
	best_before_date,
	purchased_date,
	transaction_type,
	stock_id,
	undone
);

CREATE INDEX ix_stock_performance1 ON stock (
    product_id,
    open,
    best_before_date,
    amount
);

