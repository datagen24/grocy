-- The outbox: events that have been committed but not yet delivered to a consumer.
--
-- Plan 18's InfluxDB events were held in process memory between the booking and the write
-- at the end of the request, so a crash, a timeout or a rejected write lost a committed
-- purchase permanently and silently. That is the at-least-once invariant the constitution's
-- workload standard states and [ADR-0010](../docs/adr/0010-workload-standard.md) formalises:
-- every consumer is an at-least-once consumer, every side effect is idempotent or
-- deduplicated, and queues are drained rather than fired and forgotten.
--
-- This table is the first instance of that record's "one outbox schema discriminated by
-- event type". It is deliberately not an InfluxDB table: the point of the contract is that
-- consumers may multiply while contracts may not, so a second consumer attaches by reading
-- its own event_type rather than by minting a table of its own.
--
-- The row is written INSIDE the booking's transaction, which is the whole mechanism - a
-- rollback takes the event with it, so the outbox can never describe a booking that did not
-- happen. Delivery is a separate step that acknowledges by setting delivered_at, and until
-- it does the row stays and is retried.
--
-- payload is JSON held as text and kept small on purpose: it carries the transaction id and
-- nothing else, because the facts are derived from stock_log at delivery time. A payload
-- that duplicated the booking would be a second copy of the ledger that could disagree with
-- the first.
--
-- attempts and last_error exist so a permanently failing event is visible rather than
-- merely slow. Nothing prunes delivered rows yet; they are the delivery log until a
-- retention decision is made deliberately.

CREATE TABLE outbox (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	event_type TEXT NOT NULL,
	payload TEXT NOT NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime')),
	delivered_at DATETIME,
	attempts INTEGER NOT NULL DEFAULT 0,
	last_error TEXT
);

CREATE INDEX outbox_undelivered ON outbox (delivered_at, id);
