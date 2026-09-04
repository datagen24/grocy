-- Failed login attempts, so that guessing a password is rate limited.
--
-- Sweep finding S12: DefaultAuthMiddleware ran password_verify() on every attempt with no
-- counter and no delay, and migration 0027 seeds admin/admin.
--
-- It is a table rather than a counter in memory, and that is the whole point of the
-- finding rather than an implementation preference. The deployment target is a pod that
-- scales to zero, and per plan 17's question 2 the idle windows are a night or longer -
-- so an in-process or APCu counter is reset for free by an attacker who waits, which is
-- the same as having no throttle at all while looking like having one. Redis would do
-- equally well; a table costs one write per failed attempt and needs no second service.
--
-- Rows are written only on failure and deleted on the next success for that identity, so
-- the table is empty on a healthy installation. PasswordLogin also drops anything older
-- than the window whenever it writes, so a burst of attempts against a name nobody ever
-- logs in as cannot accumulate for ever.
--
-- Both columns are recorded because the two limits answer different questions: a
-- per-username count stops one account being ground down from a botnet, and a per-address
-- count stops one client working through a list of usernames. Either being over the limit
-- refuses the attempt.
--
-- The address is whatever REMOTE_ADDR says, which behind a reverse proxy is the proxy.
-- That is deliberate and is not a bug to fix by trusting X-Forwarded-For: a header a
-- client can set is a throttle a client can evade, and the per-username limit is what
-- carries the load in that deployment. It is recorded here rather than left to be
-- rediscovered.

CREATE TABLE login_attempts (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	username TEXT NOT NULL,
	ip_address TEXT NOT NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX login_attempts_username ON login_attempts (username, row_created_timestamp);
CREATE INDEX login_attempts_ip_address ON login_attempts (ip_address, row_created_timestamp);
