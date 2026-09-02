<?php

namespace Victual\Services;

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\Database\InitialDataSeeder;

/**
 * Brings the database schema up to date on application start, for either engine.
 *
 * SQLite databases replay the numbered files in migrations/ from the beginning;
 * PostgreSQL (added later) instead loads a squashed baseline schema from db/pgsql/baseline/
 * equivalent to migrations 0001-0255, records those as applied and continues with the
 * regular migration path from 0256 on. Applied migration numbers are tracked in the
 * "migrations" table.
 */
class DatabaseMigrationService extends BaseService
{
	/**
	 * This migration will be always executed, can be used to fix things manually (will never be shipped).
	 */
	const EMERGENCY_MIGRATION_ID = 9999;

	/**
	 * This migration will be always executed, is used for things which need to be checked always.
	 */
	const DOALWAYS_MIGRATION_ID = 8888;

	/**
	 * Migrations 0001-0255 are SQLite only and describe how the schema grew historically.
	 * Engines added later start from a squashed baseline equivalent to that end state
	 * rather than replaying a history they were never part of.
	 *
	 * Consequently every migration from here on has to work on all supported engines -
	 * either as a portable NNNN.sql, as per engine NNNN.sqlite.sql / NNNN.pgsql.sql, or
	 * as a documented engine-exclusive file where the other engine genuinely needs no
	 * change. The last case means the two engines can sit at different numbers while
	 * both being fully migrated, which is why GetLatestMigrationNumber() takes a
	 * dialect.
	 */
	const BASELINE_MIGRATION_ID = 255;

	/**
	 * Applies all pending migrations: ensures the migrations table exists, loads the
	 * baseline schema when the engine uses one and the database is empty, then executes
	 * every not-yet-applied migration file in ascending number order. When anything was
	 * applied, generated-id counters are resynced (migrations insert explicit ids) and
	 * the engine's optimize statement (e.g. VACUUM/ANALYZE) is run.
	 *
	 * @param bool $seedInitialData Whether a database created from the baseline also gets
	 *                              the initial data that the migration history would have
	 *                              inserted. Only bin/victual-db-import passes false: it is
	 *                              about to fill the database from an existing one, and
	 *                              seeding first would leave it looking non-empty to its
	 *                              own overwrite check.
	 */
	public function MigrateDatabase(bool $seedInitialData = true)
	{
		$dialect = DatabaseService::GetInstance()->GetDialect();

		// The whole run, baseline and the always-run 8888 included, happens with the
		// engine's migration lock held: everything below is check-then-apply, so two
		// processes starting together interleave rather than one waiting for the other.
		// See DatabaseDialect::WithMigrationLock().
		$dialect->WithMigrationLock(function () use ($dialect, $seedInitialData)
		{
			$this->RunMigrations($dialect, $seedInitialData);
		});
	}

	/**
	 * The migration run itself, always called with the migration lock held.
	 */
	private function RunMigrations(DatabaseDialect $dialect, bool $seedInitialData)
	{
		$this->EnsureMigrationsTable($dialect);
		$this->ApplyBaselineSchemaWhenNeeded($dialect, $seedInitialData);

		$migrationCounter = 0;
		foreach (self::GetMigrationFiles($dialect) as $migrationNumber => $migrationFile)
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
	/**
	 * The highest migration number that exists for an engine.
	 *
	 * Dialect-aware on purpose. A migration can be engine-exclusive — shipped as
	 * NNNN.sqlite.sql or NNNN.pgsql.sql with no counterpart, because the other engine
	 * genuinely needs no change — and once one exists, "what number should this
	 * database be at?" has a different answer per engine. Anything comparing schema
	 * versions has to ask this rather than assume the two engines count alike.
	 */
	public static function GetLatestMigrationNumber(DatabaseDialect $dialect): int
	{
		$migrationFiles = self::GetMigrationFiles($dialect);

		// The always-run migrations are fixups rather than schema versions, and they are
		// deliberately never recorded in the migrations table — counting them would put
		// every database permanently "behind" a number it can never reach.
		$numbers = array_filter(
			array_keys($migrationFiles),
			fn($number) => $number !== self::DOALWAYS_MIGRATION_ID && $number !== self::EMERGENCY_MIGRATION_ID
		);

		return empty($numbers) ? 0 : max($numbers);
	}

	private static function GetMigrationFiles(DatabaseDialect $dialect): array
	{
		$generic = [];
		$specific = [];

		foreach (new \FilesystemIterator(__DIR__ . '/../migrations') as $file)
		{
			$matches = [];
			$name = $file->getBasename();

			if (preg_match('/^(\d+)\.(sql|php)$/', $name, $matches))
			{
				$generic[intval($matches[1])] = $file;
			}
			elseif (preg_match('/^(\d+)\.([a-z]+)\.(sql|php)$/', $name, $matches))
			{
				// A suffix that does not name a real engine matches nothing and would
				// otherwise be skipped in silence on every engine — the migration simply
				// never runs, and nothing says so. A typo here is indistinguishable from
				// a deliberate omission at runtime, so refuse to start instead.
				if (!in_array($matches[2], DatabaseDialect::SUPPORTED_DRIVERS, true))
				{
					throw new \Exception('Migration "' . $name . '" is suffixed "' . $matches[2]
						. '", which is not a supported database driver. Expected one of: '
						. implode(', ', DatabaseDialect::SUPPORTED_DRIVERS) . '.');
				}

				if ($matches[2] === $dialect->GetName())
				{
					$specific[intval($matches[1])] = $file;
				}
			}
			elseif (preg_match('/\.(sql|php)$/', $name))
			{
				// Same failure, different spelling: a migration whose name does not parse
				// at all is dead weight nobody would notice.
				throw new \Exception('Migration "' . $name . '" does not follow the naming '
					. 'convention. Expected NNNN.sql, NNNN.php, or NNNN.<driver>.sql / '
					. 'NNNN.<driver>.php.');
			}
		}

		$migrationFiles = $specific + $generic;
		ksort($migrationFiles);

		return $migrationFiles;
	}

	/**
	 * Creates the "migrations" bookkeeping table (applied migration number plus
	 * execution timestamp) when it does not exist yet.
	 */
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
	 * loads that baseline into an empty database, seeds the data those migrations would
	 * have inserted, and records the migrations it stands in for as already applied.
	 *
	 * Schema and data go in together on purpose. The baseline files are DDL only, while
	 * a third of the migrations they stand in for also insert rows - the admin user, the
	 * permission hierarchy, the default quantity units - so loading the schema alone
	 * produces a database that migrates cleanly and cannot be logged into. See
	 * InitialDataSeeder.
	 *
	 * @param bool $seedInitialData False to load the schema alone, for a caller which is
	 *                              about to import an existing database into it
	 */
	private function ApplyBaselineSchemaWhenNeeded(DatabaseDialect $dialect, bool $seedInitialData)
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

			if ($seedInitialData)
			{
				(new InitialDataSeeder($pdo, $dialect))->Seed();
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

		global $VICTUAL_DEFAULT_USER_SETTINGS;

		foreach ($VICTUAL_DEFAULT_USER_SETTINGS as $key => $value)
		{
			DatabaseService::GetInstance()->ExecuteDbStatement(
				'INSERT INTO user_settings_defaults (key, value) VALUES (?, ?) '
				. 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
				[$key, is_bool($value) ? ($value ? '1' : '0') : (string)$value]
			);
		}
	}

	/**
	 * Includes the given PHP migration file unless it was already applied. The special
	 * EMERGENCY/DOALWAYS ids run on every start and are never recorded as applied;
	 * regular migrations are recorded and increment $migrationCounter.
	 */
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

	/**
	 * Executes the given SQL migration in a transaction unless it was already applied,
	 * with the same special-id and bookkeeping rules as ExecutePhpMigrationWhenNeeded()
	 * (PHP migrations, in contrast, manage their own transactions).
	 */
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
