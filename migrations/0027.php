<?php

// This is executed inside DatabaseMigrationService class/context

use Grocy\Services\DatabaseService;

$db = DatabaseService::GetInstance()->GetDbConnection();

if (defined('VICTUAL_HTTP_USER'))
{
	// Migrate old user defined in config file to database
	$newUserRow = $db->users()->createRow([
		'username' => VICTUAL_HTTP_USER,
		'password' => password_hash(VICTUAL_HTTP_PASSWORD, PASSWORD_ARGON2ID)
	]);
	$newUserRow->save();
}
else
{
	// Create default user "admin" with password "admin"
	$newUserRow = $db->users()->createRow([
		'username' => 'admin',
		'password' => password_hash('admin', PASSWORD_ARGON2ID)
	]);
	$newUserRow->save();
}
