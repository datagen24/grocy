<?php

namespace Grocy\Services\Database;

/**
 * Encapsulates everything about persistence that differs between database engines.
 *
 * Grocy's data access is split in two: LessQL's fluent API (portable) and hand written
 * SQL in the migrations and a handful of services (not portable). This class is the seam
 * for the second part - connection setup, engine specific functions, and the few SQL
 * fragments that leak into PHP.
 */
abstract class DatabaseDialect
{
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

	public static function Create(): self
	{
		$driver = defined('GROCY_DB_DRIVER') ? strtolower(GROCY_DB_DRIVER) : 'sqlite';

		switch ($driver)
		{
			case 'sqlite':
				return new SqliteDialect();

			case 'pgsql':
				return new PostgresDialect();

			default:
				throw new \Exception('Unsupported database driver "' . $driver . '", only sqlite and pgsql are supported');
		}
	}
}
