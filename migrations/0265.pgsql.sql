-- users.must_change_password: whether this account is still on the password migration 0027
-- seeds, and so must change it before it may do anything else.
--
-- The PostgreSQL half of the pair. See migrations/0265.sqlite.sql for why this is a column
-- rather than the user setting it used to be, and why the answer is stored rather than
-- recomputed; the reasoning is the same on both engines and is not repeated here.

ALTER TABLE users ADD COLUMN must_change_password SMALLINT NOT NULL DEFAULT 0 CHECK(must_change_password IN (0, 1));
