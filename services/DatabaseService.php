<?php

namespace Grocy\Services;

use Grocy\Services\Database\DatabaseDialect;
use LessQL\Database;

class DatabaseService
{
	private static $DbConnection = null;
	private static $DbConnectionRaw = null;
	private static $Dialect = null;
	private static $instance = null;
	private static $ShutdownHandlerRegistered = false;

	public function ExecuteDbQuery(string $sql)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($this->ExecuteDbStatement($sql) === true)
		{
			return $pdo->query($sql);
		}

		return false;
	}

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

	public function GetDbChangedTime()
	{
		return $this->GetDialect()->GetDbChangedTime($this->GetDbConnectionRaw());
	}

	public function GetDialect(): DatabaseDialect
	{
		if (self::$Dialect == null)
		{
			self::$Dialect = DatabaseDialect::Create();
		}

		return self::$Dialect;
	}

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
	 * Tells the connection which user it acts for. Engines which resolve user settings in
	 * SQL (PostgreSQL) need this; on SQLite it is a no-op.
	 */
	public function SetCurrentUserId($userId)
	{
		$this->GetDialect()->SetCurrentUserId($this->GetDbConnectionRaw(), $userId);
	}

	public function SetDbChangedTime($dateTime)
	{
		$this->GetDialect()->SetDbChangedTime($this->GetDbConnectionRaw(), $dateTime);
	}

	public static function GetInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

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
