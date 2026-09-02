<?php

namespace Victual\Services\Influx;

use Victual\Services\DatabaseService;

/**
 * Collects the stock bookings a request made and turns them into InfluxDB events once the
 * request ends and every transaction is closed.
 *
 * Question 7 of docs/plans/18-mqtt-state-publication.md, answered 2026-08-31: price and
 * valuation *events* go to InfluxDB on the same after-commit seam as the MQTT publish. Two
 * measurements, both per product:
 *
 * - **price_paid** - one point per purchase booking that carried a price, timestamped with
 *   the booking's own row_created_timestamp rather than with now(), so a backdated purchase
 *   lands where it belongs in the series.
 * - **stock_value** - one point per product touched, holding that product's stock value
 *   after the commit, read from the same `stock_current` view the stock overview page shows
 *   the household. Timestamped with the request's end, because that is when the value it
 *   describes became true.
 *
 * What is collected during the request is transaction ids and nothing else. That keeps the
 * calls at StockService's entrypoints to one line each and means nothing is derived from a
 * state that a later rollback could invalidate - the rows are read back afterwards, from the
 * committed ledger.
 *
 * Points carry product_id as their only tag. No user id, no note, no location: this is a
 * series about what the household spends, not about who booked it.
 */
class BookingEventPublisher
{
	/**
	 * Transaction ids booked during this request, deduplicated.
	 *
	 * Per-request rather than between requests, and gone when the process is - the same
	 * category as DatabaseService's dirty flag rather than the cold-start problem ADR-0007
	 * forbids. Deduplicated because InventoryProduct delegates to AddProduct or
	 * ConsumeProduct and would otherwise record the same transaction twice.
	 *
	 * @var array<string, true>
	 */
	private static $TransactionIds = [];

	/**
	 * Records that a stock booking happened in this request. Called from StockService's write
	 * entrypoints, after the commit.
	 *
	 * Cheap and unconditional at the call site: when InfluxDB is off this is a constant
	 * lookup and a return, which is why the entrypoints do not have to know about the setting.
	 *
	 * @param string|null $transactionId
	 */
	public static function RecordTransaction($transactionId): void
	{
		if (!InfluxEventWriter::IsEnabled() || $transactionId === null || $transactionId === '')
		{
			return;
		}

		self::$TransactionIds[(string)$transactionId] = true;
	}

	/**
	 * Whether this request booked anything worth writing.
	 */
	public static function HasBookings(): bool
	{
		return count(self::$TransactionIds) > 0;
	}

	/**
	 * The request-end trigger: reads back the bookings this request made and writes their
	 * events.
	 *
	 * Called from DatabaseService's shutdown handler once every transaction is closed, for
	 * the same reason the MQTT publish is: a point written for a booking that then rolled
	 * back is a number in a time series that nothing will ever correct.
	 *
	 * @return bool True when a batch was written
	 */
	public static function WriteForRequestEnd(): bool
	{
		if (!InfluxEventWriter::IsEnabled() || !self::HasBookings())
		{
			return false;
		}

		$transactionIds = array_keys(self::$TransactionIds);
		self::$TransactionIds = [];

		try
		{
			$lines = (new self())->BuildLines($transactionIds);
		}
		catch (\Throwable $ex)
		{
			error_log('Victual: could not build the InfluxDB event batch, nothing was written: ' . $ex->getMessage());

			return false;
		}

		return (new InfluxEventWriter())->Write($lines);
	}

	/**
	 * The line-protocol batch for a set of committed transactions.
	 *
	 * @param string[] $transactionIds
	 * @return string[]
	 */
	public function BuildLines(array $transactionIds): array
	{
		if (count($transactionIds) === 0)
		{
			return [];
		}

		$lines = [];
		$touchedProductIds = [];

		// Raw SQL rather than LessQL because the IN list is variable length: the raw path takes
		// a positional parameter array, which is the one place in this tree that binds a list
		$placeholders = implode(', ', array_fill(0, count($transactionIds), '?'));
		$bookings = DatabaseService::GetInstance()->ExecuteDbQuery(
			'SELECT product_id, amount, price, transaction_type, undone, row_created_timestamp FROM stock_log'
				. ' WHERE transaction_id IN (' . $placeholders . ')',
			array_values($transactionIds)
		)->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($bookings as $booking)
		{
			$productId = (int)$booking['product_id'];
			$touchedProductIds[$productId] = true;

			// Undone bookings still count as touching the product - undoing a purchase changes
			// what the stock is worth - but they are not a price the household paid, so they
			// contribute a stock_value point and no price_paid one.
			//
			// Only a purchase carries a price paid at all. A consume booking also has a price
			// column - the value of what left stock - and writing it here would make
			// "price paid" mean two different things in one series.
			if ((int)$booking['undone'] === 1 || $booking['transaction_type'] !== 'purchase' || $booking['price'] === null)
			{
				continue;
			}

			$lines[] = InfluxEventWriter::BuildLine('price_paid', [
				'product_id' => $productId
			], [
				'price' => (float)$booking['price'],
				'amount' => (float)$booking['amount']
			], InfluxEventWriter::ToNanoseconds((string)$booking['row_created_timestamp']));
		}

		if (count($touchedProductIds) === 0)
		{
			return $lines;
		}

		$now = InfluxEventWriter::ToNanoseconds(date('Y-m-d H:i:s'));

		// Raw SQL again: stock_current has no id column, and LessQL wants one
		$currentStock = DatabaseService::GetInstance()
			->ExecuteDbQuery('SELECT product_id, amount, value FROM stock_current')
			->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($currentStock as $row)
		{
			$productId = (int)$row['product_id'];

			if (!isset($touchedProductIds[$productId]))
			{
				continue;
			}

			unset($touchedProductIds[$productId]);

			$lines[] = InfluxEventWriter::BuildLine('stock_value', [
				'product_id' => $productId
			], [
				'value' => (float)$row['value'],
				'amount' => (float)$row['amount']
			], $now);
		}

		// A product that was consumed down to nothing drops out of stock_current entirely, and
		// its series has to say zero rather than simply stop - a gap would read as "no data"
		// where the truth is "none left"
		foreach (array_keys($touchedProductIds) as $productId)
		{
			$lines[] = InfluxEventWriter::BuildLine('stock_value', [
				'product_id' => $productId
			], [
				'value' => 0.0,
				'amount' => 0.0
			], $now);
		}

		return $lines;
	}
}
