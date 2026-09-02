<?php

namespace Victual\Services\Database;

/**
 * Encapsulates everything about persistence that differs between database engines.
 *
 * Victual's data access is split in two: LessQL's fluent API (portable) and hand written
 * SQL in the migrations and a handful of services (not portable). This class is the seam
 * for the second part - connection setup, engine specific functions, and the few SQL
 * fragments that leak into PHP.
 */
abstract class DatabaseDialect
{
	/**
	 * Every driver name this fork supports, which is also every suffix a migration file
	 * may carry. Kept here rather than inline in Create() because the migration loader
	 * needs the same list: a file named NNNN.<something>.sql is only meaningful if
	 * <something> names a real engine, and a list that exists twice is a list that will
	 * eventually disagree with itself.
	 */
	const SUPPORTED_DRIVERS = ['sqlite', 'pgsql'];

	/**
	 * "sqlite" or "pgsql", matching the value of the DB_DRIVER setting.
	 */
	abstract public function GetName(): string;

	/**
	 * Creates a new PDO connection. Callers are expected to invoke OnConnected() afterwards.
	 */
	abstract public function CreateConnection(): \PDO;

	/**
	 * Installs engine specific functions and session settings on a fresh connection.
	 */
	abstract public function OnConnected(\PDO $pdo): void;

	/**
	 * The SQL condition implementing the API's "§" (regular expression) query operator,
	 * with a single positional placeholder for the pattern.
	 *
	 * This is part of the public API contract (see BaseApiController::FilterData), so every
	 * dialect has to offer it.
	 */
	abstract public function GetRegexpCondition(string $field): string;

	/**
	 * The SQL condition implementing the API's "~" and "!~" (substring match) query
	 * operators, with a single positional placeholder for the pattern.
	 *
	 * This exists for the same reason GetRegexpCondition() does, and is easier to miss:
	 * the operator is spelled differently per engine because the obvious spelling does not
	 * mean the same thing on both. SQLite's LIKE ignores ASCII case by default, PostgreSQL's
	 * does not, so a literal LIKE in the controller answers the same request with different
	 * rows depending on the engine - silently, since the response shape is identical either
	 * way. SQLite's case insensitivity is the documented behaviour of this API, so the
	 * PostgreSQL side matches it rather than the other way round.
	 *
	 * The agreement is guaranteed for ASCII and only for ASCII. SQLite's LIKE folds A-Z and
	 * nothing else; PostgreSQL's ILIKE folds per the database collation, so on a UTF-8
	 * database a pattern of "æ" also matches "Æ" where SQLite would not. That residual is
	 * deliberate - the alternative is reimplementing one engine's folding table in the
	 * other - and it is what `run-tests.sh filter` measures and prints on every run rather
	 * than leaving to be rediscovered. The API documents the same limit.
	 *
	 * @param bool $negated The "!~" form, which must negate the match rather than be wrapped
	 *                      in NOT by the caller - the two are the same here but only because
	 *                      both engines treat NULL identically, and that is worth pinning
	 *                      down in one place instead of at every call site.
	 */
	abstract public function GetLikeCondition(string $field, bool $negated): string;

	/**
	 * The declared/reported type of every column of $table, keyed by column name.
	 *
	 * Used to validate the fields a caller names in "query[]" and "order" before they reach
	 * SQL, so an unusable one is a 400 rather than whatever the engine happens to do with
	 * it. Works for views as well as tables, because most of what this API lists is a view.
	 *
	 * @return array<string, string> Empty when the table is unknown to the engine.
	 */
	abstract public function GetColumnTypes(\PDO $pdo, string $table): array;

	/**
	 * The types to validate against: the engine's own catalogue, with
	 * ColumnTypeManifest filling only the columns the catalogue could not type.
	 *
	 * This is the method callers want; GetColumnTypes() is the per-engine half of it. It is
	 * concrete and lives here rather than on either dialect precisely because the manifest
	 * has to be applied the same way on both - the whole point of it is that "may I search
	 * this field" stops depending on which engine is answering.
	 *
	 * The manifest fills gaps and never overrides. A catalogue that reports a real type is
	 * describing what the engine will actually do with the column, and no entry here can be
	 * more right about that than the engine is.
	 *
	 * @return array<string, string>
	 */
	final public function GetValidationColumnTypes(\PDO $pdo, string $table): array
	{
		$types = $this->GetColumnTypes($pdo, $table);

		foreach (ColumnTypeManifest::For($table) as $column => $semanticType)
		{
			if (array_key_exists($column, $types) && trim($types[$column]) === '')
			{
				$types[$column] = $semanticType;
			}
		}

		return $types;
	}

	/**
	 * Whether a column of the given declared type can be substring-matched.
	 *
	 * Deliberately one rule for both engines rather than one per dialect, because the
	 * point of it is that the two agree. It is SQLite's own TEXT-affinity rule - a declared
	 * type containing CHAR, CLOB or TEXT - which also happens to select exactly
	 * PostgreSQL's "text", "character varying" and "character" out of information_schema.
	 *
	 * Everything else is rejected on both engines, including timestamps. SQLite stores
	 * those as text and would happily match "2026-08" against one; PostgreSQL cannot,
	 * because a TIMESTAMP is not a string there. Rendering one to text so both could match
	 * is a real feature and a real decision - which format, in which time zone, with which
	 * precision - and it is not one to arrive at by accident through whatever CAST each
	 * engine happens to implement. Until that is designed, the honest answer is that this
	 * API does not offer substring matching on a timestamp, on either engine.
	 */
	public static function IsTextMatchableType(string $declaredType): bool
	{
		$type = strtoupper($declaredType);

		return str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT');
	}

	/**
	 * An SQL expression yielding the current local (not UTC) timestamp.
	 */
	abstract public function GetNowExpression(): string;

	/**
	 * The column type used for timestamps.
	 */
	abstract public function GetTimestampType(): string;

	/**
	 * Directory holding the squashed baseline schema for this engine, relative to the
	 * application root, or null when the engine replays the migration history instead.
	 */
	public function GetBaselineSchemaPath(): ?string
	{
		return null;
	}

	/**
	 * The statement to reclaim space / refresh planner statistics after migrations,
	 * or null when the engine needs nothing.
	 */
	abstract public function GetOptimizeStatement(): ?string;

	/**
	 * Runs $work with a cross process lock held, so that only one migration run at a
	 * time touches this database.
	 *
	 * DatabaseMigrationService is check-then-apply from end to end - does this migration
	 * number exist, is the migrations table empty, does location 1 exist - and two
	 * processes starting together interleave those checks. The losing one rolls back on
	 * a primary key violation rather than corrupting anything, but it exits non-zero, and
	 * an initContainer that fails because a sibling won is an outage rather than a
	 * no-op. The always-run 8888 fixup is worse: it is outside the per-migration
	 * try/catch, so its race has nothing to catch it at all.
	 *
	 * It lives on the dialect because locking is where engines differ most, but there is
	 * only one real implementation: PostgreSQL's advisory lock. Under
	 * ADR-0008 PostgreSQL is the only runtime engine, and SqliteDialect's version is a
	 * deliberate no-op - see the reasoning there. Engine-neutral composition of work
	 * belongs on DatabaseService (see InTransaction); this is its counterpart.
	 *
	 * It wraps the entire MigrateDatabase() call rather than each migration, baseline
	 * included, because the checks that race are spread across all of it.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the lock is released
	 */
	abstract public function WithMigrationLock(callable $work);

	/**
	 * Runs $work with a cross process lock held, so that only one process at a time
	 * assembles and publishes the retained MQTT state.
	 *
	 * A separate lock from the migration one, on a separate key, because they guard
	 * unrelated things and sharing a key would have a publish wait behind a migration for
	 * no reason.
	 *
	 * The race it closes is a lost update with no error anywhere. Publishing is
	 * read-then-write across a network: request A assembles the snapshot, request B commits
	 * a later change and publishes it, then A publishes the state it read earlier. Retained
	 * topics have no version and no ordering, so the broker simply keeps whatever arrived
	 * last - and A's stale snapshot stands until the next write, which on a pod that sleeps
	 * for days is exactly the failure this whole plan exists to prevent. Nothing logs it,
	 * because nothing failed.
	 *
	 * **The assembly has to be inside the lock, not just the publish.** A lock around the
	 * publish alone still lets both requests read before either writes, which is the same
	 * lost update with a smaller window. Holding it across assemble, diff, publish and the
	 * ledger update makes the loser re-read after the winner released, so it publishes
	 * state that is at least as new.
	 *
	 * It lives on the dialect for the same reason WithMigrationLock() does - locking is
	 * where engines differ most - and has the same one real implementation.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the lock is released
	 */
	abstract public function WithPublicationLock(callable $work);

	/**
	 * Quotes a single table or column name for safe interpolation into SQL.
	 */
	abstract public function QuoteIdentifier(string $name): string;

	/**
	 * The character LessQL wraps table and column names in. LessQL defaults to the MySQL
	 * backtick, which PostgreSQL rejects outright.
	 */
	abstract public function GetIdentifierDelimiter(): string;

	/**
	 * When the data last changed, as "Y-m-d H:i:s".
	 *
	 * Exposed publicly as GET /api/system/db-changed-time and used by the iOS app and the
	 * Home Assistant integration to decide whether to refetch, so the semantics have to hold
	 * across engines: it must advance on data changes and must NOT advance for bookkeeping
	 * writes such as session or API key last-used stamps.
	 */
	abstract public function GetDbChangedTime(\PDO $pdo): string;

	/**
	 * Overwrites the changed time with an explicit value ("Y-m-d H:i:s" or anything
	 * strtotime() accepts). Used to restore the previous value after bookkeeping writes.
	 */
	abstract public function SetDbChangedTime(\PDO $pdo, string $dateTime): void;

	/**
	 * Records that data changed. May defer the actual write until FlushDbChangedTime().
	 */
	abstract public function MarkDbChanged(\PDO $pdo): void;

	/**
	 * Persists a pending MarkDbChanged(). Called once at the end of a request.
	 */
	public function FlushDbChangedTime(\PDO $pdo): void
	{
	}

	/**
	 * Brings generated-id counters back in line with the data.
	 *
	 * SQLite's AUTOINCREMENT tracks the highest id ever used, so inserting an explicit id
	 * implicitly moves the counter past it. Other engines do not necessarily do that, and
	 * would then hand out ids which already exist. Anything that inserts explicit ids -
	 * migrations, demo data, importing an existing database - has to call this afterwards.
	 */
	public function ResyncGeneratedIdCounters(\PDO $pdo): void
	{
	}

	/**
	 * Tells the connection which user it is acting for, so that SQL side helpers resolving
	 * user settings work. Called once authentication has established the user.
	 */
	public function SetCurrentUserId(\PDO $pdo, $userId): void
	{
	}

	/**
	 * True when a single PDO::exec() may contain several semicolon separated statements,
	 * which is how the .sql migration files are written.
	 */
	public function SupportsMultiStatementExec(): bool
	{
		return true;
	}

	/**
	 * True when the changed time has to be maintained by inspecting executed statements.
	 * Engines where the storage layer already tracks it (SQLite, via the file modification
	 * time) return false so that no per-query work is done at all.
	 */
	public function RequiresChangeTracking(): bool
	{
		return true;
	}

	/**
	 * True when the SQL in the given string writes data, used to keep the changed time
	 * accurate for the raw SQL paths that bypass LessQL.
	 */
	public function IsWriteStatement(string $sql): bool
	{
		return preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $sql) === 1;
	}

	/**
	 * Factory: picks the dialect matching the VICTUAL_DB_DRIVER setting
	 * ("sqlite" when undefined). Called once per request by DatabaseService.
	 *
	 * @throws \Exception On an unsupported driver value
	 */
	public static function Create(): self
	{
		$driver = defined('VICTUAL_DB_DRIVER') ? strtolower(VICTUAL_DB_DRIVER) : 'sqlite';

		switch ($driver)
		{
			case 'sqlite':
				return new SqliteDialect();

			case 'pgsql':
				return new PostgresDialect();

			default:
				throw new \Exception('Unsupported database driver "' . $driver . '", only '
					. implode(' and ', self::SUPPORTED_DRIVERS) . ' are supported');
		}
	}
}
