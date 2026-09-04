-- api_keys.key_hint: the last four characters of a key, kept so that a key can still be
-- identified after it stops being readable.
--
-- Migration 0264 is what makes it stop being readable: it replaces every regular API key
-- in api_keys.api_key with its SHA-256 hash. This column has to exist before that runs,
-- which is why the two are separate numbers rather than one file.
--
-- SHA-256 rather than password_hash(): a key is looked up *by value* on every
-- authenticated request, which a salted bcrypt cannot do without scanning the table, and
-- these are 50 characters of random alphabet - roughly 250 bits. Brute force is not the
-- threat model; a leaked api_keys table is, and an unsalted hash of a high-entropy secret
-- is exactly right for that. Plan 11, question 4.
--
-- A pair rather than one portable file because adding a column is DDL, and DDL is where
-- the two engines diverge. Both engines run migration 263 and end up with the same column,
-- so the pair is complete and needs no @engine-exclusive marker.

ALTER TABLE api_keys ADD COLUMN key_hint TEXT;
