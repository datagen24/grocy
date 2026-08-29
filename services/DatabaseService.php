<?php

namespace Grocy\Services;

use Grocy\Services\Database\DatabaseDialect;
use LessQL\Database;

/**
 * Central access point to the database: owns the single PDO connection, the LessQL
 * wrapper around it, and the DatabaseDialect for the configured engine.
 *
 * The dialect (SQLite or PostgreSQL, chosen via DB_DRIVER) encapsulates everything
 * engine specific - connection setup, identifier quoting and the "db changed time"
 * bookkeeping. This service routes both LessQL writes and raw SQL through the dialect
 * so that change tracking stays accurate on engines that need it (PostgreSQL); on
 * SQLite the file modification time covers it for free.
 */
class DatabaseService
{
	private static $DbConnection = null;
	private static $DbConnectionRaw = null;
	private static $Dialect = null;
	private static $instance = null;
	private static $ShutdownHandlerRegistered = false;

	/**
	 * Executes a SQL query and returns its result set.
	 *
	 * The statement is executed twice by design (once via ExecuteDbStatement for
	 * logging/change tracking, once to obtain the result), so only use this for
	 * side-effect free SELECTs.
	 *
	 * Pass $params for anything that would otherwise be interpolated into $sql -
	 * notably date cut-offs, which have to be computed in PHP because the engines
	 * disagree about date arithmetic (SQLite's DATE(x, '-6 months') has no
	 * PostgreSQL equivalent).
	 *
	 * @param array|null $params Positional placeholder values, or null for a plain query
	 * @return \PDOStatement|false The result statement, or false when execution failed
	 */
	public function ExecuteDbQuery(string $sql, ?array $params = null)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($this->ExecuteDbStatement($sql, $params) !== true)
		{
			return false;
		}

		if (empty($params))
		{
			return $pdo->query($sql);
		}

		$cmd = $pdo->prepare($sql);
		if ($cmd->execute($params) === false)
		{
			return false;
		}

		return $cmd;
	}

	/**
	 * Executes a SQL statement without returning a result set, throwing on failure.
	 *
	 * Without $params the statement is run via PDO::exec(), which (engine permitting)
	 * may contain several semicolon separated statements; with $params it is prepared
	 * and executed with the given positional values.
	 *
	 * @param array|null $params Positional placeholder values, or null for a plain exec
	 * @return bool Always true (an exception is thrown on failure)
	 */
	public function ExecuteDbStatement(string $sql, ?array $params = null)
	{
		$pdo = $this->GetDbConnectionRaw();

		$this->LogQuery($sql, $params ?? []);

		if ($params == null)
		{

			if ($pdo->exec($sql) === false)
			{
				throw new \Exception($pdo->errorInfo());
			}
		}
		else
		{
			$cmd = $pdo->prepare($sql);
			if ($cmd->execute($params) === false)
			{
				throw new \Exception($pdo->errorInfo());
			}
		}

		// Raw SQL bypasses LessQL, so the changed time has to be maintained here too
		$dialect = $this->GetDialect();
		if ($dialect->RequiresChangeTracking() && $dialect->IsWriteStatement($sql))
		{
			$dialect->MarkDbChanged($pdo);
		}

		return true;
	}

	/**
	 * When the data last changed, as "Y-m-d H:i:s" (see DatabaseDialect::GetDbChangedTime()
	 * for the semantics external API clients rely on).
	 *
	 * @return string
	 */
	public function GetDbChangedTime()
	{
		return $this->GetDialect()->GetDbChangedTime($this->GetDbConnectionRaw());
	}

	/**
	 * The dialect for the configured database engine (created on first use from
	 * the DB_DRIVER setting, defaulting to SQLite).
	 */
	public function GetDialect(): DatabaseDialect
	{
		if (self::$Dialect == null)
		{
			self::$Dialect = DatabaseDialect::Create();
		}

		return self::$Dialect;
	}

	/**
	 * The shared LessQL wrapper around the PDO connection, configured for the current
	 * dialect (identifier delimiter, and a query callback for change tracking and
	 * query logging where needed).
	 *
	 * @return Database
	 */
	public function GetDbConnection()
	{
		if (self::$DbConnection == null)
		{
			$pdo = $this->GetDbConnectionRaw();
			self::$DbConnection = new Database($pdo);

			$dialect = $this->GetDialect();
			self::$DbConnection->setIdentifierDelimiter($dialect->GetIdentifierDelimiter());

			$trackChanges = $dialect->RequiresChangeTracking();

			if ($trackChanges || $this->IsQueryLoggingEnabled())
			{
				self::$DbConnection->setQueryCallback(function ($query, $params) use ($pdo, $dialect, $trackChanges)
				{
					$this->LogQuery($query, $params);

					if ($trackChanges && $dialect->IsWriteStatement($query))
					{
						$dialect->MarkDbChanged($pdo);
					}
				});
			}
		}

		return self::$DbConnection;
	}

	/**
	 * The shared raw PDO connection, created and initialized by the dialect on first use.
	 *
	 * @return \PDO
	 */
	public function GetDbConnectionRaw()
	{
		if (self::$DbConnectionRaw == null)
		{
			$dialect = $this->GetDialect();

			$pdo = $dialect->CreateConnection();
			$dialect->OnConnected($pdo);

			self::$DbConnectionRaw = $pdo;
			$this->RegisterShutdownHandler();
		}

		return self::$DbConnectionRaw;
	}

	/**
	 * Runs $work inside a database transaction, committing on return and rolling back if
	 * it throws.
	 *
	 * Nesting is the reason this exists. PDO has no nested transactions, and the call
	 * graph already nests: RecipesService::ConsumeRecipe wraps and then calls
	 * ConsumeProduct and AddProduct; StockService::InventoryProduct delegates to one of
	 * those two; UndoTransaction loops over UndoBooking, which recurses into itself for
	 * correlated bookings. A naive beginTransaction() in each of those would throw the
	 * moment two of them met.
	 *
	 * So an inner call is a no-op: whoever opened the transaction owns committing it, and
	 * the innermost work simply joins it. That is the right semantics as well as the
	 * simple one — nothing here wants partial rollback, and an undo that half-succeeds is
	 * exactly the state these transactions exist to prevent. Savepoints would allow it and
	 * are supported by both engines, but no caller wants it; if one ever does, this
	 * signature does not have to change.
	 *
	 * "Is a transaction already open?" is asked of PDO rather than tracked in a counter of
	 * our own. A counter would only know about transactions opened through this method,
	 * and DatabaseMigrationService opens its own directly — so a migration that called a
	 * service would nest wrongly and the mistake would surface as a runtime error far from
	 * its cause.
	 *
	 * The engine-specific counterpart is on the dialect: see DatabaseDialect for the
	 * per-engine locking used around migrations. Engine-neutral composition belongs here;
	 * anything an engine does differently belongs there.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the transaction is rolled back
	 */
	public function InTransaction(callable $work)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($pdo->inTransaction())
		{
			return $work();
		}

		$pdo->beginTransaction();

		try
		{
			$result = $work();
		}
		catch (\Throwable $ex)
		{
			// Guarded because a statement can abort the transaction on its own (SQLite
			// does this on some errors), and rolling back a transaction that is no longer
			// open would replace the real exception with a misleading one.
			if ($pdo->inTransaction())
			{
				$pdo->rollBack();
			}

			throw $ex;
		}

		$pdo->commit();

		return $result;
	}

	/**
	 * Tells the connection which user it acts for. Engines which resolve user settings in
	 * SQL (PostgreSQL) need this; on SQLite it is a no-op.
	 *
	 * @param int|null $userId
	 */
	public function SetCurrentUserId($userId)
	{
		$this->GetDialect()->SetCurrentUserId($this->GetDbConnectionRaw(), $userId);
	}

	/**
	 * Overrides the "db changed time", used to restore it after bookkeeping writes
	 * (e.g. API key last-used stamps) that must not count as data changes.
	 *
	 * @param string $dateTime "Y-m-d H:i:s"
	 */
	public function SetDbChangedTime($dateTime)
	{
		$this->GetDialect()->SetDbChangedTime($this->GetDbConnectionRaw(), $dateTime);
	}

	/**
	 * Returns the singleton instance of this service.
	 *
	 * @return self
	 */
	public static function GetInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Query logging is opt-in: dev mode plus an existing <data path>/sql.log file.
	 */
	private function IsQueryLoggingEnabled(): bool
	{
		return GROCY_MODE === 'dev' && file_exists(GROCY_DATAPATH . '/sql.log');
	}

	private function LogQuery(string $sql, array $params)
	{
		if (!$this->IsQueryLoggingEnabled())
		{
			return;
		}

		$logFilePath = GROCY_DATAPATH . '/sql.log';

		$line = $sql;
		if (!empty($params))
		{
			$line .= ' #### ' . implode(';', $params);
		}

		file_put_contents($logFilePath, $line . PHP_EOL, FILE_APPEND);
	}

	/**
	 * Registers a once-per-request shutdown handler which flushes a pending
	 * "db changed" mark to the database (a no-op for dialects that write immediately).
	 */
	private function RegisterShutdownHandler()
	{
		if (self::$ShutdownHandlerRegistered)
		{
			return;
		}

		self::$ShutdownHandlerRegistered = true;

		// Dialects which track the changed time in a table batch it into a single write
		register_shutdown_function(function ()
		{
			try
			{
				if (self::$DbConnectionRaw !== null)
				{
					$this->GetDialect()->FlushDbChangedTime(self::$DbConnectionRaw);
				}
			}
			catch (\Exception $ex)
			{
				// A failure here must never turn an otherwise successful request into an error
			}
		});
	}
}
