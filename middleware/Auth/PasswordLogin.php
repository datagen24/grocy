<?php

namespace Victual\Middleware\Auth;

use Victual\Services\DatabaseService;
use Victual\Services\LoginThrottleService;
use Victual\Services\SessionService;
use Victual\Services\UsersService;

/**
 * Verifies a submitted username and password against the users table and, on success,
 * creates the session and sets the cookie.
 *
 * It is a class of its own rather than a static on a middleware because only one
 * authentication backend can process a login at all: the other two delegate to a proxy or
 * a directory. ProcessLogin() used to be an abstract static on BaseAuthMiddleware that
 * three of five subclasses satisfied by throwing, which is a shape that documents a hole
 * rather than closing one (plan 15-C1).
 */
class PasswordLogin
{
	/**
	 * A valid Argon2id hash of a value nobody knows, verified against when the username
	 * does not exist.
	 *
	 * Without it, an unknown username returns before password_verify() is ever called and
	 * a known one pays for a full Argon2id verification, so the response time says which
	 * of the two happened - and Argon2id's whole point is that the gap is large. Sweep
	 * finding S19. It is a constant rather than something hashed at startup because
	 * generating it would cost every request what it is meant to cost only the failing
	 * one.
	 */
	private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZFNrRnJMR3VZTWFqREJUZQ$StazWDuUq3vY/JYgP4TlkBjp5SViCpYpjfSmkN4wo+A';

	/**
	 * @param array $postParams The login form POST parameters (username, password, stay_logged_in)
	 * @return bool True when the credentials were valid
	 */
	public static function Process(array $postParams): bool
	{
		$username = $postParams['username'] ?? '';
		$inputPassword = $postParams['password'] ?? '';
		$stayLoggedInPermanently = ($postParams['stay_logged_in'] ?? '') == 'on';

		if (empty($username) || empty($inputPassword))
		{
			return false;
		}

		$throttle = LoginThrottleService::GetInstance();

		if (!$throttle->IsAttemptAllowed($username))
		{
			// Answered exactly like a wrong password, and deliberately: telling a guesser
			// that they have hit the limit tells them the limit exists and roughly where
			// it is, which is information worth more to them than to anybody else. The
			// person who has genuinely forgotten their password waits and tries again.
			return false;
		}

		$db = DatabaseService::GetInstance()->GetDbConnection();
		$user = $db->users()->where('username', $username)->fetch();

		if ($user === null)
		{
			// Deliberately does the work anyway - see DUMMY_PASSWORD_HASH
			password_verify($inputPassword, self::DUMMY_PASSWORD_HASH);
			$throttle->RecordFailedAttempt($username);

			return false;
		}

		if (!password_verify($inputPassword, $user->password))
		{
			$throttle->RecordFailedAttempt($username);

			return false;
		}

		$throttle->ClearAttempts($username);
		UsersService::GetInstance()->RecordPasswordUsedAtLogin((int)$user->id, $inputPassword);

		// Every session this login supersedes and every expired one, cleared while there
		// is a reason to be writing to the table anyway. Nothing pruned the sessions table
		// before, so it grew for the life of the installation (sweep finding S19).
		SessionService::GetInstance()->RemoveExpiredSessions();

		$sessionKey = SessionService::GetInstance()->CreateSession($user->id, $stayLoggedInPermanently);
		SessionCookie::Set($sessionKey, $stayLoggedInPermanently);

		if (password_needs_rehash($user->password, PASSWORD_ARGON2ID))
		{
			$user->update([
				'password' => password_hash($inputPassword, PASSWORD_ARGON2ID)
			]);
		}

		return true;
	}
}
