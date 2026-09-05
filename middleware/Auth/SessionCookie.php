<?php

namespace Victual\Middleware\Auth;

use Victual\Services\SessionService;

/**
 * Sets and clears the session cookie.
 *
 * It is its own class because two things that are not middlewares need it: PasswordLogin
 * sets it, and LoginController clears it on logout. It used to be a protected static on
 * BaseAuthMiddleware, which is why logging out deleted the session row and left the
 * cookie in the browser (sweep finding S19) - the controller had no way to reach it.
 */
class SessionCookie
{
	/**
	 * Sets the session cookie carrying the given session key.
	 *
	 * The session key is the credential itself, so the cookie carries HttpOnly (nothing
	 * reads it from JavaScript), SameSite=Lax and, over HTTPS, Secure. Its own lifetime
	 * mirrors the session row: a browser session cookie for a normal login, and the
	 * stay-logged-in lifetime when that box was ticked. Sweep finding S3 / plan 15-B2.
	 *
	 * @param string $sessionKey The session key as returned by SessionService::CreateSession()
	 * @param bool $stayLoggedInPermanently Whether the login asked to be remembered
	 */
	public static function Set(string $sessionKey, bool $stayLoggedInPermanently = false): void
	{
		setcookie(SessionService::SESSION_COOKIE_NAME, $sessionKey, [
			// 0 is a browser session cookie. The server remains the authority on validity
			// either way - see SessionService::CreateSession().
			'expires' => $stayLoggedInPermanently ? time() + SessionService::GetStayLoggedInLifetimeSeconds() : 0,
			'path' => rtrim(VICTUAL_BASE_PATH, '/') . '/',
			'secure' => self::IsHttpsRequest(),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}

	/**
	 * Expires the session cookie in the browser.
	 *
	 * Every attribute except the expiry has to match what Set() used, or the browser
	 * treats this as a different cookie and leaves the original in place. Sweep finding
	 * S19: logging out removed the server-side session and left the client holding a
	 * cookie that looked like a credential, which is a confusing thing to hand somebody
	 * on a shared machine even though the server refuses it.
	 */
	public static function Clear(): void
	{
		setcookie(SessionService::SESSION_COOKIE_NAME, '', [
			'expires' => time() - 3600,
			'path' => rtrim(VICTUAL_BASE_PATH, '/') . '/',
			'secure' => self::IsHttpsRequest(),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}

	/**
	 * Whether the current request reached us over HTTPS, honoring X-Forwarded-Proto for the
	 * reverse proxy deployments this fork targets. Same determination UrlManager makes.
	 *
	 * This trusts a client-settable header, which is the pattern sweep finding S4 rates
	 * High - and is Low here because of which way it fails. The header can only make the
	 * cookie *more* restrictive: forging it adds `Secure`, which stops that browser sending
	 * the cookie back over plain HTTP. That is a self-inflicted denial of service, not an
	 * escalation, and it cannot remove a flag or reveal anything.
	 */
	public static function IsHttpsRequest(): bool
	{
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
		{
			// The header is a comma-separated list when more than one proxy appends to it;
			// the first entry is the scheme the client actually used. Matched exactly rather
			// than by substring, so a value merely containing "https" does not qualify.
			$forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));

			if ($forwardedProto === 'https')
			{
				return true;
			}
		}

		return !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
	}
}
