<?php

namespace Victual\Helpers;

use Jenssegers\Blade\Blade;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Blade template renderer for Slim: renders a Blade template
 * (via jenssegers/blade) into a PSR-7 response body, with support for
 * shared view data set via set().
 */
class SlimBladeView
{
	/**
	 * @param string $viewPaths Path to the folder containing the Blade templates
	 * @param string $cachePath Path to the folder for compiled template cache files
	 */
	public function __construct(string $viewPaths, string $cachePath)
	{
		$this->ViewPaths = $viewPaths;
		$this->CachePath = $cachePath;
	}

	protected $ViewPaths;
	protected $CachePath;
	protected $ViewData = [];

	/**
	 * Renders the given template and writes the output to the response body.
	 *
	 * @param string $template Template name (Blade dot notation, without file extension)
	 * @param array $data Template variables, merged over the shared view data
	 * @return ResponseInterface The passed response with the rendered output appended
	 */
	public function Render(ResponseInterface $response, string $template, array $data = [])
	{
		$data = array_merge($this->ViewData, $data);
		$renderer = new Blade($this->ViewPaths, $this->CachePath, null);
		$output = $renderer->make($template, $data)->render();

		$response->getBody()->write($output);
		return $response;
	}

	/**
	 * Sets a shared view data value which is available in all subsequently rendered templates.
	 */
	public function set(string $key, mixed $value)
	{
		$this->ViewData[$key] = $value;
	}
}
