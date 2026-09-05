<?php

// This is executed inside DatabaseMigrationService class/context

use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseService;

// Replaces every regular API key with its SHA-256 hash, and records the last four
// characters in the key_hint column migration 0263 added.
//
// Plan 11, question 4. Keys were stored and compared in plaintext, so a copy of the
// database - a backup, a dump, a screenshot of a table - is a set of live credentials.
// Clients keep sending exactly the string they already have; only what is on disk changes,
// which is why this is a one-way migration that invalidates nothing.
//
// SHA-256 and not password_hash(): a key is looked up by value on every authenticated
// request, which salted bcrypt cannot do without a full table scan, and these are 50
// characters of random alphabet. Brute force is not the threat model; the table is.
//
// **Special-purpose calendar keys are deliberately left readable**, and this is the one
// thing here the plan did not anticipate - it was written before sweep finding S17 was
// fixed, which is what made the calendar sharing link work at all. The application has to
// be able to hand that URL back to the person who asks for it, and it cannot do that from
// a hash. The exposure is bounded and is not the API: ApiKeyAuthenticator accepts a key of
// this type on the calendar-ical route only, and IsValidApiKey() checks key_type, so such
// a key reads the household's calendar and nothing else. The alternative - regenerating
// the key whenever the sharing dialog is opened - would break every calendar application
// already subscribed to it.
//
// Portable by construction: it is PHP and PDO, so it holds on both engines with one file.
// It must run after 0263, which is why it takes the next number rather than sharing one.

$db = DatabaseService::GetInstance()->GetDbConnectionRaw();

$rows = $db->query('SELECT id, api_key FROM api_keys WHERE key_type = ' . $db->quote(ApiKeyService::API_KEY_TYPE_DEFAULT))->fetchAll(PDO::FETCH_ASSOC);

$update = $db->prepare('UPDATE api_keys SET api_key = :hashed, key_hint = :hint WHERE id = :id');

foreach ($rows as $row)
{
	$plaintext = (string)$row['api_key'];

	// Already hashed - so re-running this migration, or running it after a partial one,
	// cannot hash a hash and lock every client out
	if (preg_match('/^[0-9a-f]{64}$/', $plaintext))
	{
		continue;
	}

	$update->execute([
		':hashed' => ApiKeyService::HashKey($plaintext),
		':hint' => ApiKeyService::HintFor($plaintext),
		':id' => $row['id']
	]);
}
