-- api_keys.key_hint: the last four characters of a key, kept so that a key can still be
-- identified after it stops being readable.
--
-- The PostgreSQL half of the pair. See migrations/0263.sqlite.sql for why the column
-- exists, why the hash is SHA-256 rather than password_hash(), and why the hashing itself
-- is a separate migration; the reasoning is the same on both engines and is not repeated
-- here.

ALTER TABLE api_keys ADD COLUMN key_hint TEXT;
