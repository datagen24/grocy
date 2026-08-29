<?php

// This is executed inside DatabaseMigrationService class/context

use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;

$localizationService = LocalizationService::GetInstance(VICTUAL_DEFAULT_LOCALE);
$db = DatabaseService::GetInstance()->GetDbConnection();

$defaultShoppingList = $db->shopping_lists()->where('id = 1')->fetch();
$defaultShoppingList->update([
	'name' => $localizationService->__t('Shopping list')
]);
