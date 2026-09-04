<?php

namespace Victual\Services;

/**
 * Rate limits password guessing, by counting failed attempts per username and per client
 * address inside a rolling window.
 *
 * Sweep finding S12. The state is a table rather than anything held between requests,
 * which is the finding's own emphasis and not an implementation preference: the deployment
 * target is a pod that scales to zero and stays down for a night at a time, so a counter
 * in process memory or APCu is reset for free by an attacker who waits - the same as
 * having no throttle, while looking like having one. See migrations/0262.sqlite.sql.
 *
 * Two counters rather than one, because they answer different questions: per username
 * stops one account being ground down from many addresses, per address stops one client
 * working through a list of usernames. Either being at the limit refuses the attempt.
 */
class LoginThrottleService extends BaseService
{
	/**
	 * Whether this username, from this address, may attempt a login at all right now.
	 */
	public function IsAttemptAllowed(string $username, string $ipAddress): bool
	{
		$limit = self::MaxAttempts();

		if ($limit <= 0)
		{
			return true;
		}

		$since = self::WindowStart();

		if ($this->CountSince('username', $username, $since) >= $limit)
		{
			return false;
		}

		return $this->CountSince('ip_address', $ipAddress, $since) < $limit;
	}

	/**
	 * Records a failed attempt, and takes the opportunity to drop everything that has
	 * fallen out of the window.
	 *
	 * Pruning here rather than on a schedule is what keeps the table bounded without a
	 * second moving part: attempts against a username nobody ever succeeds as would
	 * otherwise accumulate for ever, since the success path is what clears them.
	 */
	public function RecordFailedAttempt(string $username, string $ipAddress): void
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();

		$this->DB->login_attempts()->where('row_created_timestamp <= :1', self::WindowStart())->delete();

		$this->DB->login_attempts()->createRow([
			'username' => $username,
			'ip_address' => $ipAddress
		])->save();

		// A refused login is not a data change, and clients poll on this value
		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	/**
	 * Clears the counters a successful login earns back - this username, and the address
	 * it came from.
	 */
	public function ClearAttempts(string $username, string $ipAddress): void
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();

		$this->DB->login_attempts()->where('username = :1 OR ip_address = :2', $username, $ipAddress)->delete();

		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	/**
	 * The address the request came from.
	 *
	 * REMOTE_ADDR, which behind a reverse proxy is the proxy rather than the client. That
	 * is deliberate: X-Forwarded-For is a header a client can set, so throttling on it is
	 * a throttle a client can evade by varying it, and the per-username limit is what
	 * carries the load in a proxied deployment. Trusting a forwarded address would need
	 * the same trusted-proxy allowlist S4 built, and is worth doing only alongside it.
	 */
	public static function ClientAddress(): string
	{
		return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
	}

	private static function MaxAttempts(): int
	{
		return (int)VICTUAL_LOGIN_THROTTLE_MAX_ATTEMPTS;
	}

	private static function WindowStart(): string
	{
		return date('Y-m-d H:i:s', time() - ((int)VICTUAL_LOGIN_THROTTLE_WINDOW_MINUTES * 60));
	}

	private function CountSince(string $column, string $value, string $since): int
	{
		return count($this->DB->login_attempts()->where($column . ' = :1 AND row_created_timestamp > :2', $value, $since)->fetchAll());
	}
}
