<?php

namespace Victual\Services\Storage;

/**
 * How large an uploaded file may be, reconciled with what PHP will actually accept.
 *
 * Sweep finding S10: the upload path streamed the raw request body with no cap at all,
 * and a raw PUT body is not subject to post_max_size, so any account that could upload
 * could force an unbounded write. FILE_STORAGE_MAX_SIZE_MB is that bound.
 *
 * The reconciliation is the interesting half (plan 01 Q2). A configured 64 MB on a PHP
 * that accepts 2 MB is not a 64 MB limit, it is a lie with a number attached - so the
 * effective limit is the smallest of the three, it is logged once when the setting is not
 * the binding constraint, and GET /api/system/config reports the effective value rather
 * than the configured one. Startup keeps running either way; a clamp is information, not
 * a failure.
 *
 * upload_max_filesize and post_max_size do not actually gate a raw PUT body, which is
 * exactly why they are worth honouring here: they are what the household already told PHP
 * about how large an upload it wants to accept, and quietly exceeding that through a
 * different door is the dishonest outcome.
 */
class FileSizeLimit
{
	/** @var int|null The memoized effective limit in bytes */
	private static $EffectiveBytes = null;

	/**
	 * The effective maximum upload size in bytes.
	 *
	 * Computed once per process, which is what "logged once" means here: this application
	 * keeps nothing between processes (ADR-0007), so a worker that has never resolved the
	 * limit logs the clamp on the request that makes it, and the same worker never logs it
	 * again. ConfigurationValidator resolves it at startup so that is normally the boot,
	 * not an upload.
	 */
	public static function EffectiveMaxBytes(): int
	{
		if (self::$EffectiveBytes !== null)
		{
			return self::$EffectiveBytes;
		}

		$configured = (int)VICTUAL_FILE_STORAGE_MAX_SIZE_MB * 1024 * 1024;

		$effective = $configured;
		$clampedBy = null;

		foreach (['upload_max_filesize', 'post_max_size'] as $directive)
		{
			$limit = self::ParseIniBytes((string)ini_get($directive));

			// 0 (or an unparseable value) means "no limit" for these directives, which is
			// nothing to clamp to
			if ($limit > 0 && $limit < $effective)
			{
				$effective = $limit;
				$clampedBy = $directive;
			}
		}

		if ($clampedBy !== null)
		{
			error_log('Victual: FILE_STORAGE_MAX_SIZE_MB is ' . VICTUAL_FILE_STORAGE_MAX_SIZE_MB
				. ' MB, but PHP\'s ' . $clampedBy . ' (' . ini_get($clampedBy) . ') is smaller, so uploads are limited to '
				. self::FormatMegabytes($effective) . ' MB. Raise ' . $clampedBy . ' in php.ini to use the configured value.');
		}

		self::$EffectiveBytes = $effective;

		return self::$EffectiveBytes;
	}

	/**
	 * The effective maximum upload size in megabytes, for a message or an API response.
	 *
	 * A float only when a php.ini directive is not a whole number of megabytes; the
	 * ordinary case returns an int, which is the shape a client reading the setting name
	 * expects.
	 */
	public static function EffectiveMaxMegabytes()
	{
		$megabytes = self::EffectiveMaxBytes() / 1024 / 1024;

		return $megabytes == (int)$megabytes ? (int)$megabytes : round($megabytes, 2);
	}

	/**
	 * The same number as a string, for a message.
	 */
	public static function FormatMegabytes(int $bytes): string
	{
		$megabytes = $bytes / 1024 / 1024;

		return $megabytes == (int)$megabytes ? (string)(int)$megabytes : (string)round($megabytes, 2);
	}

	/**
	 * Parses a php.ini shorthand byte value ("2M", "8192K", "1G", "512") into bytes.
	 *
	 * Returns 0 for an empty, zero or unparseable value, which every caller here reads as
	 * "no limit from this directive".
	 */
	private static function ParseIniBytes(string $value): int
	{
		$value = trim($value);
		if ($value === '')
		{
			return 0;
		}

		$number = (int)$value;
		$suffix = strtolower(substr($value, -1));

		if ($suffix === 'g')
		{
			return $number * 1024 * 1024 * 1024;
		}

		if ($suffix === 'm')
		{
			return $number * 1024 * 1024;
		}

		if ($suffix === 'k')
		{
			return $number * 1024;
		}

		return max($number, 0);
	}
}
