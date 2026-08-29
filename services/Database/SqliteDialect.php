<?php

namespace Victual\Services\Database;

use Victual\Services\UsersService;

/**
 * The original (and default) storage engine. Behaviour here is deliberately identical to
 * what the code did before the dialect layer existed, so that existing installations are
 * untouched by the PostgreSQL work.
 */
class SqliteDialect extends DatabaseDialect
{
	public function GetName(): string
	{
		return 'sqlite';
	}

	/**
	 * Opens the database file (creating it when missing, as SQLite does by default).
	 * NULL_EMPTY_STRING matches upstream grocy: empty strings come back as null.
	 */
	public function CreateConnection(): \PDO
	{
		$pdo = new \PDO\Sqlite('sqlite:' . $this->GetDbFilePath());
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);

		return $pdo;
	}

	/**
	 * Registers the PHP callbacks SQLite lacks natively and Victual's SQL relies on:
	 * REGEXP (UTF-8 aware via mb_ereg), victual_user_setting() for user settings resolved
	 * inside views, and ceil(). PostgreSQL provides native equivalents of all three
	 * instead, since it cannot call back into PHP.
	 */
	public function OnConnected(\PDO $pdo): void
	{
		$pdo->createFunction('regexp', function ($pattern, $value)
		{
			mb_regex_encoding('UTF-8');
			return (false !== mb_ereg($pattern, $value)) ? 1 : 0;
		});

		$pdo->createFunction('victual_user_setting', function ($value)
		{
			$usersService = new UsersService();
			return $usersService->GetUserSetting(VICTUAL_USER_ID, $value);
		});

		// Unfortunately not included by default
		// https://www.sqlite.org/lang_mathfunc.html#ceil
		$pdo->createFunction('ceil', function ($value)
		{
			return ceil($value);
		});
	}

	/**
	 * SQLite's REGEXP operator, backed by the mb_ereg callback from OnConnected().
	 */
	public function GetRegexpCondition(string $field): string
	{
		return $field . ' REGEXP ?';
	}

	/**
	 * Plain LIKE, which SQLite already applies case insensitively for ASCII
	 * (PRAGMA case_sensitive_like is off by default). This is the reference behaviour the
	 * PostgreSQL dialect mimics with ILIKE.
	 */
	public function GetLikeCondition(string $field, bool $negated): string
	{
		return $field . ($negated ? ' NOT LIKE ?' : ' LIKE ?');
	}

	/**
	 * Local (process time zone) timestamp with second precision - the reference
	 * behaviour the PostgreSQL dialect mimics.
	 */
	public function GetNowExpression(): string
	{
		return "datetime('now', 'localtime')";
	}

	public function GetTimestampType(): string
	{
		return 'DATETIME';
	}

	/**
	 * SQLite never returns free pages to the OS on its own, so VACUUM after
	 * migrations keeps the single-file database compact.
	 */
	public function GetOptimizeStatement(): ?string
	{
		return 'VACUUM';
	}

	/**
	 * Standard SQL double-quote quoting, which SQLite supports alongside backticks.
	 */
	public function QuoteIdentifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

	public function GetIdentifierDelimiter(): string
	{
		// SQLite accepts both; the backtick is what LessQL has always used here
		return '`';
	}

	/**
	 * The database file's modification time serves as the changed time - the OS
	 * maintains it on every write, so no in-database bookkeeping is needed.
	 */
	public function GetDbChangedTime(\PDO $pdo): string
	{
		clearstatcache(true, $this->GetDbFilePath());
		return date('Y-m-d H:i:s', filemtime($this->GetDbFilePath()));
	}

	/**
	 * Rewinds the file modification time via touch() - how bookkeeping writes
	 * (session/API key last-used stamps) are hidden from clients polling for changes.
	 */
	public function SetDbChangedTime(\PDO $pdo, string $dateTime): void
	{
		touch($this->GetDbFilePath(), strtotime($dateTime));
	}

	public function MarkDbChanged(\PDO $pdo): void
	{
		// The file modification time SQLite maintains already is the changed time
	}

	/**
	 * False: the file modification time makes per-statement change tracking unnecessary.
	 */
	public function RequiresChangeTracking(): bool
	{
		return false;
	}

	/**
	 * Absolute path of the database file: victual.db in the data directory, or a
	 * per-locale victual_<suffix>.db in demo/prerelease mode.
	 */
	public function GetDbFilePath(): string
	{
		if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			$dbSuffix = VICTUAL_DEFAULT_LOCALE;
			if (defined('VICTUAL_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = VICTUAL_DEMO_DB_SUFFIX;
			}

			return VICTUAL_DATAPATH . '/victual_' . $dbSuffix . '.db';
		}

		return VICTUAL_DATAPATH . '/victual.db';
	}
}
