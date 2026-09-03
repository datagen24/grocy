-- Per-product MQTT entities: the opt-in flag, and the record of what has been published.
--
-- docs/plans/18-mqtt-state-publication.md question 2, answered 2026-08-31: per-product
-- Home Assistant entities exist, but only for products the household opts in, so the
-- entity count is chosen rather than inherited from the catalogue.
--
-- The flag is a side table rather than a column on products, deliberately. A column would
-- change the shape of every products response - the wire contract ADR-0005 calls the
-- invariant - and on PostgreSQL it would not appear in products_view or any of the views
-- built on it without recreating all of them, where SQLite's "SELECT p.*" would pick it up
-- silently. That is a divergence the differential suite would catch and nobody should have
-- to fix. A side table is invisible to both.
--
-- mqtt_published_entities is the publisher's bookkeeping and exists because retraction
-- needs to know what was published before. Deleting a product, deactivating it or clearing
-- its flag has to send an empty retained payload to that product's topics, and the only
-- way to know which topics those are is to have written it down - in the database, since
-- ADR-0007 leaves nothing in process memory between requests. The payload hash is what
-- makes a second publish of unchanged data a no-op.
--
-- No foreign keys: this schema expresses cascades with triggers rather than constraints
-- (see products_DELETE, migrations/0225.sql) and SQLite does not enforce REFERENCES unless
-- PRAGMA foreign_keys is on, which this application does not set. A flag row whose product
-- has gone is handled where it matters instead - the publisher joins products, so the
-- entity is retracted and the orphan row removed on the next publish.

CREATE TABLE mqtt_product_entities (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	product_id INTEGER NOT NULL UNIQUE,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE mqtt_published_entities (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	object_id TEXT NOT NULL UNIQUE,
	payload_hash TEXT NOT NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);
