-- PostgreSQL baseline schema: functions
--
-- On SQLite, Victual registers helper functions per connection via PDO::createFunction(),
-- which calls back into PHP. PostgreSQL cannot do that, so the equivalents live here.
--
-- `ceil`, `substr`, `abs`, `round` and friends are native in PostgreSQL and need nothing.
-- `regexp` is not needed either: the dialect emits the `~` operator instead (see
-- Victual\Services\Database\PostgresDialect::GetRegexpCondition).
--
-- That leaves victual_user_setting(), which is used by the stock overview views.

-- Victual sorts and compares names case insensitively by writing SQLite's built in
-- "COLLATE NOCASE" directly into its queries - 116 times across eleven PHP files, mostly
-- ORDER BY on list pages and API responses, plus one barcode lookup in StockService.
--
-- PostgreSQL has no such collation built in and rejects the query outright, so rather than
-- rewriting every call site we provide a collation under the same name. Identifiers fold
-- to lower case, so the "COLLATE NOCASE" the PHP emits resolves to this.
--
-- "und-u-ks-level2" means compare at the secondary level: case is ignored, accents are
-- not. It is not byte for byte identical to SQLite's NOCASE, which folds ASCII A-Z only,
-- but it agrees on ASCII and is more correct beyond it.
--
-- This needs a PostgreSQL built with ICU (the official images are). Without it the
-- migration fails here, which is the right place to find out.
CREATE COLLATION nocase (
	provider = icu,
	locale = 'und-u-ks-level2',
	deterministic = false
);

-- Default user settings, mirrored here from the PHP configuration by
-- DatabaseMigrationService so that SQL can resolve a setting the user has never
-- explicitly set. Kept in sync on every migration run.
CREATE TABLE user_settings_defaults (
	key TEXT NOT NULL PRIMARY KEY,
	value TEXT
);

-- Resolves a setting for the user the current connection is acting for.
--
-- The acting user comes from the `victual.user_id` session variable, which
-- Victual\Services\DatabaseService::SetCurrentUserId() sets once authentication has
-- established who is making the request. It falls back to user 1 so that the function
-- still behaves sensibly on a connection which has not been told yet (during schema
-- migration, for instance).
--
-- Resolution order matches UsersService::GetUserSetting(): the user's own value first,
-- then the configured default, then NULL.
CREATE FUNCTION victual_user_setting(setting_key TEXT) RETURNS TEXT AS $$
	SELECT COALESCE(
		(
			SELECT us.value
			FROM user_settings us
			WHERE us.user_id = COALESCE(NULLIF(current_setting('victual.user_id', true), '')::INTEGER, 1)
				AND us.key = setting_key
		),
		(
			SELECT usd.value
			FROM user_settings_defaults usd
			WHERE usd.key = setting_key
		)
	);
$$ LANGUAGE sql STABLE;
