<?php

namespace Victual\Middleware\Auth;

use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
use Victual\Services\UsersService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Active when VICTUAL_AUTH_CLASS is set to Victual\Middleware\Auth\LdapAuthMiddleware:
 * authenticates logins against an external LDAP/Active Directory server (bind DN,
 * base DN, user filter and UID attribute configured via the VICTUAL_LDAP_* settings,
 * see config-dist.php), creating a local Victual user on first successful login.
 * Request authentication itself (session cookie / API key) is delegated to
 * DefaultAuthMiddleware, since logins still result in a normal Victual session.
 */
class LdapAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Marks the request as externally authenticated and delegates the actual
	 * per-request authentication (session cookie / API key) to DefaultAuthMiddleware.
	 *
	 * @return mixed|null The user row or null if the request is not authenticated
	 */
	public function AuthenticateRequest(Request $request)
	{
		define('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION', true);

		$auth = new DefaultAuthMiddleware($this->AppContainer, $this->ResponseFactory);
		return $auth->AuthenticateRequest($request);
	}

	/**
	 * Verifies username/password against the configured LDAP server: binds with the
	 * service account to look up the user's DN, then rebinds as that user to
	 * validate the password. On success, creates the local Victual user if it does not
	 * exist yet, creates a session and sets the session cookie.
	 *
	 * @param array $postParams The login form POST parameters (username, password, stay_logged_in)
	 * @return bool True when the credentials were valid; false on missing input,
	 *              an unresolvable/unknown/ambiguous user or a wrong password
	 * @throws \Exception On LDAP connection/bind/search failures
	 */
	public static function ProcessLogin(array $postParams)
	{
		if (empty($postParams['username']) || empty($postParams['password']))
		{
			return false;
		}

		if ($connect = ldap_connect(VICTUAL_LDAP_ADDRESS))
		{
			ldap_set_option($connect, LDAP_OPT_PROTOCOL_VERSION, 3);
			ldap_set_option($connect, LDAP_OPT_REFERRALS, 0);

			// Bind with service account to retrieve user DN
			if (ldap_bind($connect, VICTUAL_LDAP_BIND_DN, VICTUAL_LDAP_BIND_PW))
			{
				// The username has to be escaped, otherwise filter meta characters in it
				// (e.g. "*)(objectClass=*") would allow altering the search filter
				$filter = '(&(' . VICTUAL_LDAP_UID_ATTR . '=' . ldap_escape($postParams['username'], '', LDAP_ESCAPE_FILTER) . ')' . VICTUAL_LDAP_USER_FILTER . ')';

				$search = ldap_search($connect, VICTUAL_LDAP_BASE_DN, $filter);
				if ($search === false)
				{
					throw new \Exception('LDAP error: ' . ldap_error($connect));
				}

				$result = ldap_get_entries($connect, $search);
				if ($result === false)
				{
					throw new \Exception('LDAP error: ' . ldap_error($connect));
				}

				if (!isset($result['count']) || $result['count'] !== 1)
				{
					// User not found (or not unambiguously)
					ldap_close($connect);
					return false;
				}

				$ldapFirstName = $result[0]['givenname'][0];
				$ldapLastName = $result[0]['sn'][0];
				$ldapDistinguishedName = $result[0]['dn'];
				$ldapUidAttribute = $result[0][strtolower(VICTUAL_LDAP_UID_ATTR)][0];

				if (is_null($ldapDistinguishedName))
				{
					// User not found
					ldap_close($connect);
					return false;
				}
			}
			else
			{
				// Bind authentication failed
				throw new \Exception('LDAP error: ' . ldap_error($connect));
			}

			// Bind with user account to validate password
			if (ldap_bind($connect, $ldapDistinguishedName, $postParams['password']))
			{
				$db = DatabaseService::GetInstance()->GetDbConnection();
				$user = $db->users()->where('username', $ldapUidAttribute)->fetch();
				if ($user == null)
				{
					$user = UsersService::GetInstance()->CreateUser($ldapUidAttribute, $ldapFirstName, $ldapLastName, '');
				}

				$stayLoggedInPermanently = $postParams['stay_logged_in'] == 'on';
				$sessionKey = SessionService::GetInstance()->CreateSession($user->id, $stayLoggedInPermanently);
				self::SetSessionCookie($sessionKey, $stayLoggedInPermanently);

				return true;
			}
			else
			{
				// User authentication failed
				ldap_close($connect);
				return false;
			}
		}
		else
		{
			// LDAP connection failed
			return false;
		}
	}
}
