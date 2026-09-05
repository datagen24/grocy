<?php

namespace Victual\Services\Database;

use Victual\Services\ApiKeyService;

/**
 * Replaces plaintext API keys that are already in a database with their hashes.
 *
 * This is migration 0264's work, applied to rows that arrived after it ran. The two exist
 * for the same reason StoredHtmlPurifier and migration 0260 do: an in-place upgrade meets
 * the migration with its rows already there, while an import migrates an empty target and
 * only then copies rows into it - so the migration finds nothing and the rows land
 * untouched.
 *
 * Until ADR-0008's retirement that gap could not open here. The importer demanded a source
 * at exactly the SQLite line's latest migration, so a source had necessarily run 0264 on
 * its own side and arrived already hashed. The supported import span reaches back to 0255
 * (DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN) - which is where an upstream grocy
 * database stops, and grocy stores its keys in plaintext - so from now on a legitimate
 * source can carry them. Without this, such an import produces a target whose api_keys
 * table holds plaintext where every authenticated request hashes what it is given: the
 * keys silently stop working, and the table that was the finding's whole subject is back
 * to being a set of live credentials.
 *
 * Deliberately a copy of 0264's rule rather than a call into it: an applied migration is
 * history and is not edited to grow a second caller. The rule is small and its two halves -
 * which keys, and the already-hashed guard - are stated once here and once there.
 */
class StoredApiKeyHasher
{
	/**
	 * The shape of a value this has already been applied to: 64 hex characters of SHA-256.
	 *
	 * The guard matters more here than in the migration. An importer may be run twice
	 * against the same target with --force, and hashing a hash locks out every client
	 * holding a key that is otherwise still valid - silently, since a hash of a hash is a
	 * perfectly well-formed value.
	 */
	const HASHED_PATTERN = '/^[0-9a-f]{64}$/';

	/**
	 * Hashes every regular API key that is still readable, in place.
	 *
	 * Special-purpose calendar keys are left alone, exactly as migration 0264 leaves them:
	 * the application hands that sharing URL back to whoever asks for it and cannot do that
	 * from a hash. ApiKeyService::IsValidApiKey() checks key_type, so such a key reads the
	 * household's calendar and nothing else.
	 *
	 * @param \PDO $db The database to clean, already migrated and populated
	 * @param callable|null $progress Receives one human-readable line when anything changed
	 * @return int How many keys were hashed
	 */
	public static function HashPlaintextKeys(\PDO $db, ?callable $progress = null): int
	{
		$statement = $db->prepare('SELECT id, api_key FROM api_keys WHERE key_type = ?');
		$statement->execute([ApiKeyService::API_KEY_TYPE_DEFAULT]);
		$rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

		$update = $db->prepare('UPDATE api_keys SET api_key = ?, key_hint = ? WHERE id = ?');
		$hashed = 0;

		foreach ($rows as $row)
		{
			$plaintext = (string)$row['api_key'];

			if (preg_match(self::HASHED_PATTERN, $plaintext) === 1)
			{
				continue;
			}

			$update->execute([ApiKeyService::HashKey($plaintext), ApiKeyService::HintFor($plaintext), $row['id']]);
			$hashed++;
		}

		if ($hashed > 0 && $progress !== null)
		{
			$progress('  hashed ' . $hashed . ' API key(s) that were stored in plaintext');
		}

		return $hashed;
	}
}
