<?php

// This is executed inside DatabaseMigrationService class/context

use Victual\Services\StockService;

StockService::GetInstance()->CompactStockEntries();
