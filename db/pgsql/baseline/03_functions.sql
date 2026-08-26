-- PostgreSQL baseline schema: functions
--
-- On SQLite, Grocy registers helper functions per connection via PDO::createFunction(),
-- which calls back into PHP. PostgreSQL cannot do that, so the equivalents live here.
--
-- `ceil`, `substr`, `abs`, `round` and friends are native in PostgreSQL and need nothing.
-- `regexp` is not needed either: the dialect emits the `~` operator instead (see
-- Grocy\Services\Database\PostgresDialect::GetRegexpCondition).
--
-- That leaves grocy_user_setting(), which is used by the stock overview views.

-- Default user settings, mirrored here from the PHP configuration by
-- DatabaseMigrationService so that SQL can resolve a setting the user has never
-- explicitly set. Kept in sync on every migration run.
CREATE TABLE user_settings_defaults (
	key TEXT NOT NULL PRIMARY KEY,
	value TEXT
);

-- Resolves a setting for the user the current connection is acting for.
--
-- The acting user comes from the `grocy.user_id` session variable, which
-- Grocy\Services\DatabaseService::SetCurrentUserId() sets once authentication has
-- established who is making the request. It falls back to user 1 so that the function
-- still behaves sensibly on a connection which has not been told yet (during schema
-- migration, for instance).
--
-- Resolution order matches UsersService::GetUserSetting(): the user's own value first,
-- then the configured default, then NULL.
CREATE FUNCTION grocy_user_setting(setting_key TEXT) RETURNS TEXT AS $$
	SELECT COALESCE(
		(
			SELECT us.value
			FROM user_settings us
			WHERE us.user_id = COALESCE(NULLIF(current_setting('grocy.user_id', true), '')::INTEGER, 1)
				AND us.key = setting_key
		),
		(
			SELECT usd.value
			FROM user_settings_defaults usd
			WHERE usd.key = setting_key
		)
	);
$$ LANGUAGE sql STABLE;
