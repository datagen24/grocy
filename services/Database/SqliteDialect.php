<?php

namespace Grocy\Services\Database;

use Grocy\Services\UsersService;

/**
 * The original (and default) storage engine. Behaviour here is deliberately identical to
 * what Grocy did before the dialect layer existed, so that existing installations are
 * untouched by the PostgreSQL work.
 */
class SqliteDialect extends DatabaseDialect
{
	public function GetName(): string
	{
		return 'sqlite';
	}

	public function CreateConnection(): \PDO
	{
		$pdo = new \PDO\Sqlite('sqlite:' . $this->GetDbFilePath());
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);

		return $pdo;
	}

	public function OnConnected(\PDO $pdo): void
	{
		$pdo->createFunction('regexp', function ($pattern, $value)
		{
			mb_regex_encoding('UTF-8');
			return (false !== mb_ereg($pattern, $value)) ? 1 : 0;
		});

		$pdo->createFunction('grocy_user_setting', function ($value)
		{
			$usersService = new UsersService();
			return $usersService->GetUserSetting(GROCY_USER_ID, $value);
		});

		// Unfortunately not included by default
		// https://www.sqlite.org/lang_mathfunc.html#ceil
		$pdo->createFunction('ceil', function ($value)
		{
			return ceil($value);
		});
	}

	public function GetRegexpCondition(string $field): string
	{
		return $field . ' REGEXP ?';
	}

	public function GetNowExpression(): string
	{
		return "datetime('now', 'localtime')";
	}

	public function GetTimestampType(): string
	{
		return 'DATETIME';
	}

	public function GetOptimizeStatement(): ?string
	{
		return 'VACUUM';
	}

	public function QuoteIdentifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

	public function GetIdentifierDelimiter(): string
	{
		// SQLite accepts both; the backtick is what LessQL has always used here
		return '`';
	}

	public function GetDbChangedTime(\PDO $pdo): string
	{
		clearstatcache(true, $this->GetDbFilePath());
		return date('Y-m-d H:i:s', filemtime($this->GetDbFilePath()));
	}

	public function SetDbChangedTime(\PDO $pdo, string $dateTime): void
	{
		touch($this->GetDbFilePath(), strtotime($dateTime));
	}

	public function MarkDbChanged(\PDO $pdo): void
	{
		// The file modification time SQLite maintains already is the changed time
	}

	public function RequiresChangeTracking(): bool
	{
		return false;
	}

	public function GetDbFilePath(): string
	{
		if (GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease')
		{
			$dbSuffix = GROCY_DEFAULT_LOCALE;
			if (defined('GROCY_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = GROCY_DEMO_DB_SUFFIX;
			}

			return GROCY_DATAPATH . '/grocy_' . $dbSuffix . '.db';
		}

		return GROCY_DATAPATH . '/grocy.db';
	}
}
