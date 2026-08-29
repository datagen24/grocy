<?php

namespace Grocy\Helpers;

/**
 * Builds absolute application URLs, taking the configured base URL
 * (VICTUAL_BASE_URL) and URL rewriting support into account.
 */
class UrlManager
{
	/**
	 * @param string $basePath The configured base path/URL; the special value "/" means "autodetect from the current request"
	 */
	public function __construct(string $basePath)
	{
		if ($basePath === '/')
		{
			$this->BasePath = $this->GetBaseUrl();
		}
		else
		{
			$this->BasePath = $basePath;
		}
	}

	protected $BasePath;

	/**
	 * Builds a full URL for the given relative path.
	 *
	 * When URL rewriting is disabled (VICTUAL_DISABLE_URL_REWRITING), "/index.php"
	 * is inserted before the path - except for resources (static files), which
	 * are always served directly.
	 *
	 * @param string $relativePath Path relative to the application root, must start with "/"
	 * @param bool $isResource True when the path points to a static resource file
	 * @return string The absolute URL
	 */
	public function ConstructUrl($relativePath, $isResource = false)
	{
		if (VICTUAL_DISABLE_URL_REWRITING === false || $isResource === true)
		{
			return rtrim($this->BasePath, '/') . $relativePath;
		}
		else // Is not a resource and URL rewriting is disabled
		{return rtrim($this->BasePath, '/') . '/index.php' . $relativePath;
		}
	}

	/**
	 * Autodetects the base URL (scheme + host) from the current request,
	 * honoring the X-Forwarded-Proto header when running behind a reverse proxy.
	 *
	 * @return string e. g. "https://example.com"
	 */
	private function GetBaseUrl()
	{
		if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false)
		{
			$_SERVER['HTTPS'] = 'on';
		}

		return (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]";
	}
}
