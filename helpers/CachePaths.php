<?php

namespace Victual\Helpers;

/**
 * Names the files inside VICTUAL_VIEWCACHE_PATH which more than one part of the
 * application has to agree about.
 *
 * The cache directory holds three kinds of derived file: compiled Blade templates,
 * the Slim/FastRoute route cache, and the HTMLPurifier definition cache. Blade and
 * HTMLPurifier name their own files and invalidate them by themselves; the route
 * cache does neither, which is what this class is for.
 */
class CachePaths
{
	/**
	 * The route cache file for the current routing table and base path.
	 *
	 * FastRoute writes a plain PHP array of compiled routes and never looks at it
	 * again - it has no notion of the file being out of date. The route table is a
	 * function of two things: routes.php, and VICTUAL_BASE_PATH, which Slim prefixes
	 * onto every pattern before FastRoute compiles it. Both are therefore in the file
	 * name, so that changing either produces a different file rather than silently
	 * reusing a cache which now dispatches the wrong URLs.
	 *
	 * That used to be covered from a distance: app.php hashed version.json plus the
	 * base URL and base path, and emptied the whole cache directory when the hash
	 * changed. It emptied too much (compiled templates are unaffected by any of it),
	 * it caught only released version changes rather than any edit to routes.php, and
	 * it cost a redirect on every cold start. Naming the one file that genuinely
	 * depends on those inputs after its inputs does the same job locally.
	 *
	 * On a writable cache directory a name nobody has written yet is simply written on
	 * first use, as before. On a read-only baked cache directory the name must be one
	 * bin/victual-warm-cache produced, which means the image and the deployment have to
	 * agree about VICTUAL_BASE_PATH; if they do not, Slim refuses to start with a
	 * message naming the directory, rather than serving 404s for every route.
	 */
	public static function RouteCacheFile(): string
	{
		$routesFile = __DIR__ . '/../routes.php';
		$fingerprint = hash('xxh128', file_get_contents($routesFile) . '|' . VICTUAL_BASE_PATH);

		return VICTUAL_VIEWCACHE_PATH . '/route_cache-' . $fingerprint . '.php';
	}

	/**
	 * The glob matching every route cache file, current or stale, so that the warmer
	 * can clean up after a base path or routing change.
	 */
	public static function RouteCacheGlob(): string
	{
		return VICTUAL_VIEWCACHE_PATH . '/route_cache*.php';
	}
}
