-- Failed login attempts per username, so that guessing a password is rate limited.
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
-- See ADR-0007, which decided that shape before this was built.
--
-- **There is deliberately no per-address counter here**, and its absence is the decision
-- worth recording. A first draft had one, and behind a reverse proxy - which is this
-- fork's stated deployment - REMOTE_ADDR is the proxy for every request, so it would not
-- have been a per-address counter at all: it would have been a global one wearing a
-- per-address name, holding the whole instance for the window as soon as anybody made ten
-- mistakes. That is the same failure ADR-0007 rejects in a different disguise, something
-- that looks like protection and is not. Rate limiting a misbehaving *address* needs the
-- real client address, which only the proxy has, so it belongs at the proxy layer
-- (fail2ban, nginx limit_req, an ingress middleware) and not here.
--
-- What this table bounds is therefore exactly one thing, and bounds it wherever the
-- request came from: how many times a password may be guessed against one username inside
-- LOGIN_THROTTLE_WINDOW_MINUTES. An attacker spreading attempts across many usernames is
-- the proxy's to notice.
--
-- Rows are written only on failure and deleted on the next success for that username, so
-- the table is empty on a healthy installation. LoginThrottleService also drops anything
-- older than the window whenever it writes, so a burst of attempts against a name nobody
-- ever logs in as cannot accumulate for ever.

CREATE TABLE login_attempts (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	username TEXT NOT NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX login_attempts_username ON login_attempts (username, row_created_timestamp);
