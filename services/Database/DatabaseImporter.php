<?php

namespace Grocy\Services\Database;

/**
 * Copies the contents of an existing SQLite database into another engine, so that an
 * installation can move without losing its data.
 *
 * The schema is expected to already exist in the target - run the migrations there first.
 * This only moves rows.
 */
class DatabaseImporter
{
	/**
	 * Tables that belong to the target engine alone and have no counterpart in the source.
	 */
	const TARGET_ONLY_TABLES = ['user_settings_defaults', 'system_db_changed_time'];

	/**
	 * Rows per multi-row INSERT. 250 keeps the placeholder count well under
	 * PostgreSQL's 65535 bind parameter limit even for wide tables.
	 */
	const BATCH_SIZE = 250;

	private $Source;
	private $Target;
	private $TargetDialect;
	private $Progress;

	/**
	 * @param \PDO $source The SQLite database to copy from
	 * @param \PDO $target The already-migrated database to copy into
	 * @param DatabaseDialect $targetDialect Dialect matching $target (quoting, id counter resync)
	 * @param callable|null $progress Receives one human-readable string per progress line, or null for silence
	 */
	public function __construct(\PDO $source, \PDO $target, DatabaseDialect $targetDialect, ?callable $progress = null)
	{
		$this->Source = $source;
		$this->Target = $target;
		$this->TargetDialect = $targetDialect;
		$this->Progress = $progress ?? function ($message)
		{
		};
	}

	/**
	 * Copies all rows of every table both databases have in common, verbatim, with the
	 * target's user triggers disabled. Refuses to run when the schema versions differ or
	 * (unless $force) when the target already holds data.
	 *
	 * @param bool $force Skip the target-is-empty check; existing rows are truncated away
	 * @return array Row counts per table, keyed by table name
	 */
	public function Import(bool $force = false): array
	{
		$tables = $this->GetCommonTables();

		$this->AssertSchemaVersionsMatch();
		$this->AssertTargetIsEmpty($tables, $force);

		$report = [];

		// Triggers exist to maintain data as the application changes it. Replaying rows
		// that were already shaped by the source's triggers has to leave them alone,
		// otherwise cascades fire and derived values get computed a second time.
		$this->SetTriggersEnabled($tables, false);

		try
		{
			$this->Target->exec('TRUNCATE TABLE '
				. implode(', ', array_map(fn($t) => $this->TargetDialect->QuoteIdentifier($t), $tables))
				. ' RESTART IDENTITY CASCADE');

			foreach ($tables as $table)
			{
				$report[$table] = $this->CopyTable($table);
			}
		}
		finally
		{
			$this->SetTriggersEnabled($tables, true);
		}

		// The source's ids came across verbatim, so the target's generated id counters are
		// still sitting at the bottom of the range
		$this->TargetDialect->ResyncGeneratedIdCounters($this->Target);

		$this->AssertRowCountsMatch($report);

		return $report;
	}

	/**
	 * Streams one table from source to target in multi-row INSERT batches,
	 * copying only the columns both sides share. Returns the number of rows copied.
	 */
	private function CopyTable(string $table): int
	{
		$columns = $this->GetCommonColumns($table);

		if (empty($columns))
		{
			throw new \Exception('Table "' . $table . '" has no columns in common between source and target');
		}

		$quotedTable = $this->TargetDialect->QuoteIdentifier($table);
		$quotedColumns = implode(', ', array_map(fn($c) => $this->TargetDialect->QuoteIdentifier($c), $columns));
		$rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

		$select = $this->Source->query('SELECT ' . implode(', ', array_map(fn($c) => '"' . $c . '"', $columns)) . ' FROM "' . $table . '"');

		$copied = 0;
		$batch = [];

		while ($row = $select->fetch(\PDO::FETCH_ASSOC))
		{
			$batch[] = $row;

			if (count($batch) >= self::BATCH_SIZE)
			{
				$copied += $this->InsertBatch($quotedTable, $quotedColumns, $rowPlaceholder, $columns, $batch);
				$batch = [];
			}
		}

		if (!empty($batch))
		{
			$copied += $this->InsertBatch($quotedTable, $quotedColumns, $rowPlaceholder, $columns, $batch);
		}

		($this->Progress)(sprintf('  %-46s %7d rows', $table, $copied));

		return $copied;
	}

	/**
	 * Executes one multi-row INSERT for the batch and returns the row count.
	 *
	 * @param string $rowPlaceholder Placeholder group for a single row, e.g. "(?, ?, ?)"
	 * @param array $batch Associative source rows, all sharing the keys in $columns
	 */
	private function InsertBatch(string $quotedTable, string $quotedColumns, string $rowPlaceholder, array $columns, array $batch): int
	{
		$sql = 'INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES '
			. implode(', ', array_fill(0, count($batch), $rowPlaceholder));

		$values = [];
		foreach ($batch as $row)
		{
			foreach ($columns as $column)
			{
				$values[] = $row[$column];
			}
		}

		$statement = $this->Target->prepare($sql);
		$statement->execute($values);

		return count($batch);
	}

	/**
	 * Tables present in both databases; tables existing on only one side are
	 * reported through the progress callback and skipped.
	 *
	 * @return string[]
	 */
	private function GetCommonTables(): array
	{
		$targetTables = $this->Target->query("SELECT table_name FROM information_schema.tables
			WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
			ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN);

		$sourceTables = $this->Source->query("SELECT name FROM sqlite_master
			WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);

		$common = array_values(array_intersect($targetTables, $sourceTables));

		$missing = array_diff($targetTables, $sourceTables, self::TARGET_ONLY_TABLES);
		foreach ($missing as $table)
		{
			($this->Progress)('  note: target table "' . $table . '" does not exist in the source and stays empty');
		}

		$extra = array_diff($sourceTables, $targetTables);
		foreach ($extra as $table)
		{
			($this->Progress)('  warning: source table "' . $table . '" has no counterpart in the target and is NOT copied');
		}

		return $common;
	}

	/**
	 * Columns of the given table present in both databases; source-only columns are
	 * reported through the progress callback and not copied.
	 *
	 * @return string[]
	 */
	private function GetCommonColumns(string $table): array
	{
		$targetColumns = $this->Target->query("SELECT column_name FROM information_schema.columns
			WHERE table_schema = current_schema() AND table_name = " . $this->Target->quote($table))->fetchAll(\PDO::FETCH_COLUMN);

		$sourceColumns = array_map(
			fn($c) => $c['name'],
			$this->Source->query('PRAGMA table_info("' . $table . '")')->fetchAll(\PDO::FETCH_ASSOC)
		);

		$common = array_values(array_intersect($sourceColumns, $targetColumns));

		foreach (array_diff($sourceColumns, $targetColumns) as $column)
		{
			($this->Progress)('  warning: ' . $table . '.' . $column . ' exists only in the source and is NOT copied');
		}

		return $common;
	}

	/**
	 * Importing into a schema at a different migration level would put rows into columns
	 * that mean something else, so refuse rather than guess.
	 */
	private function AssertSchemaVersionsMatch()
	{
		$sourceVersion = $this->Source->query('SELECT MAX(migration) FROM migrations')->fetchColumn();
		$targetVersion = $this->Target->query('SELECT MAX(migration) FROM migrations')->fetchColumn();

		if ($sourceVersion === false || $sourceVersion === null)
		{
			throw new \Exception('The source database has no migrations recorded, so it is not a Grocy database');
		}

		if ($targetVersion === false || $targetVersion === null)
		{
			throw new \Exception(
				'The target database has no schema yet. Run the migrations against it first '
				. '(bin/grocy-db-import does that for you when it is the configured database).'
			);
		}

		if (intval($sourceVersion) !== intval($targetVersion))
		{
			throw new \Exception(
				'Schema versions differ: the source is at migration ' . $sourceVersion
				. ' and the target at ' . $targetVersion . '. '
				. 'Start the source installation once so it migrates itself up to date, then import again.'
			);
		}
	}

	/**
	 * Refuses to overwrite a target that already holds data, unless $force. The
	 * migrations table is exempt - a freshly migrated target always has rows there.
	 */
	private function AssertTargetIsEmpty(array $tables, bool $force)
	{
		if ($force)
		{
			return;
		}

		foreach ($tables as $table)
		{
			if ($table === 'migrations')
			{
				continue;
			}

			$count = $this->Target->query('SELECT COUNT(*) FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchColumn();

			if ($count > 0)
			{
				throw new \Exception(
					'The target database already contains data (' . $table . ' has ' . $count . ' rows). '
					. 'Importing replaces everything in it. Pass --force if that is what you want.'
				);
			}
		}
	}

	/**
	 * Post-import sanity check: every table in the target must hold exactly the number
	 * of rows that were copied into it.
	 *
	 * @param array $report Row counts per table, keyed by table name (as returned by Import())
	 */
	private function AssertRowCountsMatch(array $report)
	{
		$mismatches = [];

		foreach ($report as $table => $expected)
		{
			$actual = $this->Target->query('SELECT COUNT(*) FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchColumn();

			if (intval($actual) !== intval($expected))
			{
				$mismatches[] = $table . ' (copied ' . $expected . ', target now holds ' . $actual . ')';
			}
		}

		if (!empty($mismatches))
		{
			throw new \Exception('Row counts do not match after import: ' . implode('; ', $mismatches));
		}
	}

	/**
	 * Toggles user-defined triggers on the given target tables. Only implemented for
	 * PostgreSQL - it is currently the only non-SQLite target, and rows never need
	 * trigger suppression on the way out of the SQLite source.
	 */
	private function SetTriggersEnabled(array $tables, bool $enabled)
	{
		if ($this->TargetDialect->GetName() !== 'pgsql')
		{
			return;
		}

		// ALTER TABLE rather than session_replication_role, which needs superuser -
		// a Grocy database user normally owns its tables but is not a superuser
		foreach ($tables as $table)
		{
			$this->Target->exec('ALTER TABLE ' . $this->TargetDialect->QuoteIdentifier($table)
				. ($enabled ? ' ENABLE' : ' DISABLE') . ' TRIGGER USER');
		}
	}
}
