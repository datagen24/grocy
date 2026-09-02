<?php

namespace Victual\Services\Influx;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Writes events to InfluxDB over its v2 HTTP line-protocol endpoint.
 *
 * The transport half of question 7 of docs/plans/18-mqtt-state-publication.md, answered
 * 2026-08-31: the fork does write to InfluxDB, scoped to *events* rather than sampled state.
 * The distinction is the whole answer. Sampling stock state from a pod that is mostly asleep
 * would produce a series full of holes that mean "nobody was shopping" rather than "nothing
 * was true"; a point written when a purchase commits produces a series whose gaps mean "no
 * purchases", which is true. So this is only ever called with points that describe something
 * that happened, at the moment it happened.
 *
 * Everything the MQTT publisher's contract says applies here too, for the same reasons:
 *
 * - **The endpoint is a configured constant.** Nothing derived from a request can influence
 *   where a write goes, so the 2026-08-29 security sweep's finding that this tree has no
 *   user-configurable outbound URL survives this plan's second outbound connection as well
 *   as its first.
 * - **The token is a setting** and must never join SystemApiController's EXPOSED_SETTINGS.
 * - **Nothing throws.** This runs after the transaction has committed, so a database that is
 *   down, slow or misconfigured cannot turn a successful write into an error response.
 * - **Short timeouts**, so an unreachable endpoint bounds the delay rather than hanging the
 *   request.
 *
 * Unlike the broker there is no wall-tablet test to pass: InfluxDB is queried with
 * credentials rather than subscribed to, which is exactly why question 8's "no prices on
 * MQTT" and question 7's "prices to InfluxDB" are consistent rather than contradictory.
 */
class InfluxEventWriter
{
	/**
	 * Whether event writing is configured on at all. Read as a constant so that a fork with
	 * it off pays one constant lookup.
	 */
	public static function IsEnabled(): bool
	{
		return defined('VICTUAL_INFLUXDB_ENABLED') && VICTUAL_INFLUXDB_ENABLED === true;
	}

	/**
	 * Writes a batch of line-protocol lines, with nanosecond timestamps.
	 *
	 * @param string[] $lines
	 * @return bool True when InfluxDB accepted the batch
	 */
	public function Write(array $lines): bool
	{
		if (count($lines) === 0)
		{
			return true;
		}

		$timeout = max(1, (int)VICTUAL_INFLUXDB_TIMEOUT_SECONDS);

		try
		{
			$client = new Client([
				'timeout' => $timeout,
				'connect_timeout' => $timeout
			]);

			$client->request('POST', rtrim((string)VICTUAL_INFLUXDB_URL, '/') . '/api/v2/write', [
				'query' => [
					'org' => VICTUAL_INFLUXDB_ORG,
					'bucket' => VICTUAL_INFLUXDB_BUCKET,
					'precision' => 'ns'
				],
				'headers' => [
					'Authorization' => 'Token ' . VICTUAL_INFLUXDB_TOKEN,
					'Content-Type' => 'text/plain; charset=utf-8'
				],
				'body' => implode("\n", $lines)
			]);

			return true;
		}
		// GuzzleException rather than RequestException, for the reason WebhookRunner records:
		// ConnectException extends TransferException, not RequestException, so a DNS failure
		// or a connect timeout would otherwise escape - and those are the likely failures here
		catch (GuzzleException $ex)
		{
			error_log('Victual: writing events to InfluxDB at ' . VICTUAL_INFLUXDB_URL
				. ' failed, the write itself was unaffected: ' . $ex->getMessage());

			return false;
		}
		catch (\Throwable $ex)
		{
			error_log('Victual: writing events to InfluxDB failed, the write itself was unaffected: ' . $ex->getMessage());

			return false;
		}
	}

	/**
	 * One line-protocol line.
	 *
	 * Tags and field keys are escaped per InfluxDB's line protocol rules (commas, spaces and
	 * equals signs); string field values are not produced here at all, because every field
	 * this plan writes is a number and quoting rules that are never exercised are rules that
	 * are wrong when they finally are.
	 *
	 * @param array<string, string|int> $tags
	 * @param array<string, float|int> $fields
	 * @param int $timestampNs
	 */
	public static function BuildLine(string $measurement, array $tags, array $fields, int $timestampNs): string
	{
		$line = self::Escape($measurement);

		foreach ($tags as $key => $value)
		{
			$line .= ',' . self::Escape((string)$key) . '=' . self::Escape((string)$value);
		}

		$fieldParts = [];
		foreach ($fields as $key => $value)
		{
			$fieldParts[] = self::Escape((string)$key) . '=' . self::FormatFloat((float)$value);
		}

		return $line . ' ' . implode(',', $fieldParts) . ' ' . $timestampNs;
	}

	/**
	 * A local "Y-m-d H:i:s" timestamp as nanoseconds since the epoch, which is the precision
	 * the write above declares.
	 */
	public static function ToNanoseconds(string $localTimestamp): int
	{
		return (new \DateTimeImmutable($localTimestamp))->getTimestamp() * 1000000000;
	}

	/**
	 * Line protocol reserves comma, space and equals in measurement, tag and field keys.
	 */
	private static function Escape(string $value): string
	{
		return str_replace([',', ' ', '='], ['\\,', '\\ ', '\\='], $value);
	}

	/**
	 * A float field value with a decimal point, so InfluxDB types the field as a float on
	 * the first point and does not then reject a later one for being a different type - an
	 * integer-looking "2" and a float "2.5" in the same field is a rejected write.
	 */
	private static function FormatFloat(float $value): string
	{
		$formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

		return str_contains($formatted, '.') ? $formatted : $formatted . '.0';
	}
}
