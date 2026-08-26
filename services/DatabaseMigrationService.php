<?php

namespace Grocy\Services;

use Grocy\Services\Database\DatabaseDialect;

class DatabaseMigrationService extends BaseService
{
	// This migration will be always executed, can be used to fix things manually (will never be shipped)
	const EMERGENCY_MIGRATION_ID = 9999;

	// This migration will be always executed, is used for things which need to be checked always
	const DOALWAYS_MIGRATION_ID = 8888;

	/**
	 * Migrations 0001-0255 are SQLite only and describe how the schema grew historically.
	 * Engines added later start from a squashed baseline equivalent to that end state
	 * rather than replaying a history they were never part of.
	 *
	 * Consequently every migration from here on has to work on all supported engines -
	 * either as a portable NNNN.sql, or as per engine NNNN.sqlite.sql / NNNN.pgsql.sql.
	 */
	const BASELINE_MIGRATION_ID = 255;

	public function MigrateDatabase()
	{
		$dialect = DatabaseService::GetInstance()->GetDialect();

		$this->EnsureMigrationsTable($dialect);
		$this->ApplyBaselineSchemaWhenNeeded($dialect);

		$migrationCounter = 0;
		foreach ($this->GetMigrationFiles($dialect) as $migrationNumber => $migrationFile)
		{
			if ($migrationFile->getExtension() === 'php')
			{
				$this->ExecutePhpMigrationWhenNeeded($migrationNumber, $migrationFile->getPathname(), $migrationCounter);
			}
			else
			{
				$this->ExecuteSqlMigrationWhenNeeded($migrationNumber, file_get_contents($migrationFile->getPathname()), $migrationCounter);
			}
		}

		$this->SyncUserSettingDefaults($dialect);

		if ($migrationCounter > 0)
		{
			// Migrations routinely insert rows with explicit ids
			$dialect->ResyncGeneratedIdCounters(DatabaseService::GetInstance()->GetDbConnectionRaw());

			$optimizeStatement = $dialect->GetOptimizeStatement();

			if ($optimizeStatement !== null)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement($optimizeStatement);
			}
		}
	}

	/**
	 * Returns the migrations which apply to the given engine, keyed and ordered by
	 * migration number.
	 *
	 * A migration file is named either NNNN.sql / NNNN.php, meaning it applies to every
	 * engine, or NNNN.<driver>.sql / NNNN.<driver>.php, meaning it applies only to that
	 * engine and takes precedence over a generic file with the same number.
	 *
	 * @return \SplFileInfo[]
	 */
	private function GetMigrationFiles(DatabaseDialect $dialect): array
	{
		$generic = [];
		$specific = [];

		foreach (new \FilesystemIterator(__DIR__ . '/../migrations') as $file)
		{
			$matches = [];

			if (preg_match('/^(\d+)\.(sql|php)$/', $file->getBasename(), $matches))
			{
				$generic[intval($matches[1])] = $file;
			}
			elseif (preg_match('/^(\d+)\.([a-z]+)\.(sql|php)$/', $file->getBasename(), $matches))
			{
				if ($matches[2] === $dialect->GetName())
				{
					$specific[intval($matches[1])] = $file;
				}
			}
		}

		$migrationFiles = $specific + $generic;
		ksort($migrationFiles);

		return $migrationFiles;
	}

	private function EnsureMigrationsTable(DatabaseDialect $dialect)
	{
		DatabaseService::GetInstance()->ExecuteDbStatement(
			'CREATE TABLE IF NOT EXISTS migrations ('
			. 'migration INTEGER NOT NULL PRIMARY KEY, '
			. 'execution_time_timestamp ' . $dialect->GetTimestampType() . ' DEFAULT (' . $dialect->GetNowExpression() . ')'
			. ')'
		);
	}

	/**
	 * On an engine which starts from a baseline rather than from the migration history,
	 * loads that baseline into an empty database and records the migrations it stands in
	 * for as already applied.
	 */
	private function ApplyBaselineSchemaWhenNeeded(DatabaseDialect $dialect)
	{
		$baselinePath = $dialect->GetBaselineSchemaPath();

		if ($baselinePath === null)
		{
			return;
		}

		$appliedCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations')->fetchColumn();

		if ($appliedCount > 0)
		{
			return;
		}

		$baselineFiles = glob($baselinePath . '/*.sql');
		sort($baselineFiles);

		if (empty($baselineFiles))
		{
			throw new \Exception('No baseline schema found for database engine "' . $dialect->GetName() . '" in ' . $baselinePath);
		}

		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$pdo->beginTransaction();

		try
		{
			foreach ($baselineFiles as $baselineFile)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement(file_get_contents($baselineFile));
			}

			for ($migration = 1; $migration <= self::BASELINE_MIGRATION_ID; $migration++)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migration . ')');
			}
		}
		catch (\Exception $ex)
		{
			$pdo->rollback();
			throw $ex;
		}

		$pdo->commit();
	}

	/**
	 * Mirrors the default user settings from the PHP configuration into the database, for
	 * engines which resolve settings in SQL rather than through a PHP callback.
	 */
	private function SyncUserSettingDefaults(DatabaseDialect $dialect)
	{
		if ($dialect->GetName() !== 'pgsql')
		{
			return;
		}

		global $GROCY_DEFAULT_USER_SETTINGS;

		foreach ($GROCY_DEFAULT_USER_SETTINGS as $key => $value)
		{
			DatabaseService::GetInstance()->ExecuteDbStatement(
				'INSERT INTO user_settings_defaults (key, value) VALUES (?, ?) '
				. 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
				[$key, is_bool($value) ? ($value ? '1' : '0') : (string)$value]
			);
		}
	}

	private function ExecutePhpMigrationWhenNeeded(int $migrationId, string $phpFile, int &$migrationCounter)
	{
		$rowCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations WHERE migration = ' . $migrationId)->fetchColumn();
		if ($rowCount == 0 || $migrationId == self::EMERGENCY_MIGRATION_ID || $migrationId == self::DOALWAYS_MIGRATION_ID)
		{
			include $phpFile;

			if ($migrationId != self::EMERGENCY_MIGRATION_ID && $migrationId != self::DOALWAYS_MIGRATION_ID)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migrationId . ')');
				$migrationCounter++;
			}
		}
	}

	private function ExecuteSqlMigrationWhenNeeded(int $migrationId, string $sql, int &$migrationCounter)
	{
		$rowCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations WHERE migration = ' . $migrationId)->fetchColumn();
		if ($rowCount == 0 || $migrationId == self::EMERGENCY_MIGRATION_ID || $migrationId == self::DOALWAYS_MIGRATION_ID)
		{
			DatabaseService::GetInstance()->GetDbConnectionRaw()->beginTransaction();

			try
			{
				DatabaseService::GetInstance()->ExecuteDbStatement($sql);

				if ($migrationId != self::EMERGENCY_MIGRATION_ID && $migrationId != self::DOALWAYS_MIGRATION_ID)
				{
					DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migrationId . ')');
					$migrationCounter++;
				}
			}
			catch (\Exception $ex)
			{
				DatabaseService::GetInstance()->GetDbConnectionRaw()->rollback();
				throw $ex;
			}

			DatabaseService::GetInstance()->GetDbConnectionRaw()->commit();
		}
	}
}
