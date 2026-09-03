<?php

// This migration is always executed (on every migration run, not only once)

// This is executed inside DatabaseMigrationService class/context

use Victual\Services\DatabaseService;

// When FEATURE_FLAG_STOCK_LOCATION_TRACKING is disabled,
// some places assume that there exists a location with id 1,
// so make sure that this location is available in that case
if (!VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
{
	$db = DatabaseService::GetInstance()->GetDbConnection();

	// The count is the fast path: this file runs on every migration run forever, and
	// re-issuing the insert each time would move the "database changed" timestamp that
	// clients poll, for a row that has existed since the first run.
	if ($db->locations()->where('id', 1)->count() === 0)
	{
		// Conditional in SQL rather than only in the check above. Migration runs are
		// serialised by DatabaseDialect::WithMigrationLock(), so nothing should be able
		// to insert this row between the count and the insert - but this file is the one
		// that runs on every start, it sits outside the per-migration try/catch, and it
		// is reachable from anywhere that calls MigrateDatabase(). It should not depend
		// on its caller having taken a lock to be safe.
		DatabaseService::GetInstance()->ExecuteDbStatement(
			'INSERT INTO locations (id, name) SELECT 1, \'Default\' '
			. 'WHERE NOT EXISTS (SELECT 1 FROM locations WHERE id = 1)'
		);
	}
}
