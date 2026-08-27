<?php

namespace Grocy\Helpers;

use GuzzleHttp\Client;

/**
 * Fires outgoing webhooks (HTTP POST requests with a 2 second timeout),
 * e.g. for thermal printer / label printer integration; failures are only
 * logged to stderr, never thrown.
 */
class WebhookRunner
{
	public function __construct()
	{
		$this->client = new Client(['timeout' => 2.0]);
	}

	private $client;

	/**
	 * POSTs the given data to a webhook URL.
	 *
	 * @param string $url The webhook URL
	 * @param array $args The data to send
	 * @param bool $json True to send $args as a JSON body, false (default) as form parameters
	 */
	public function run($url, $args, $json = false)
	{
		$reqArgs = [];
		if ($json)
		{
			$reqArgs = ['json' => $args];
		}
		else
		{
			$reqArgs = ['form_params' => $args];
		}
		try
		{
			file_put_contents('php://stderr', 'Running Webhook: ' . $url . "\n" . print_r($reqArgs, true));

			$this->client->request('POST', $url, $reqArgs);
		}
		catch (RequestException $e)
		{
			file_put_contents('php://stderr', 'Webhook failed: ' . $url . "\n" . $e->getMessage());
		}
	}

	/**
	 * POSTs the given data (as form parameters) to each of the given webhook URLs.
	 *
	 * @param string[] $urls The webhook URLs
	 * @param array $args The data to send
	 */
	public function runAll($urls, $args)
	{
		foreach ($urls as $url)
		{
			$this->run($url, $args);
		}
	}
}
