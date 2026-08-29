<?php

namespace Victual\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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
		// GuzzleException is the interface every Guzzle exception implements - catching
		// only RequestException would miss ConnectException (which extends TransferException,
		// not RequestException), so DNS failures and timeouts would still escape, and a
		// timeout is the most likely printer failure given the 2 second client timeout above.
		catch (GuzzleException $e)
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
