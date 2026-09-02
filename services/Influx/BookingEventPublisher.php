<?php

namespace Victual\Services\Influx;

use Victual\Services\DatabaseService;
use Victual\Services\Outbox\OutboxService;

/**
 * Turns committed stock bookings into InfluxDB events, by draining the outbox.
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
 *   the household.
 *
 * **Delivery is at-least-once through the outbox, not best-effort from process memory.**
 * RecordTransaction() writes an outbox row inside the booking's own transaction, so a
 * rollback takes it with it and a crash between the commit and the write does not lose it.
 * WriteForRequestEnd() drains: it reads the undelivered rows, builds one batch, POSTs it,
 * and marks them delivered only on success. A failure increments `attempts`, stores
 * `last_error` and leaves the rows for the next drain, which is any later request that
 * books something or an explicit `bin/victual-publish-state --drain`.
 *
 * **That makes redelivery possible, so every point has to be idempotent, and it is.** A
 * point in InfluxDB is identified by its measurement, its tag set and its timestamp, and
 * writing the same identity again overwrites rather than appends - so a batch delivered
 * twice is indistinguishable from one delivered once, which is exactly what at-least-once
 * delivery needs. The identity is what BuildLines() is careful about: `price_paid` carries
 * the `stock_log` row id as `booking_id`, so two purchases of one product in the same second
 * are two points rather than one, and both measurements carry `transaction_id` so two
 * transactions in one request cannot collide either.
 *
 * What is read back from the ledger, rather than carried in the payload, is every fact:
 * amounts, prices and values come from `stock_log` and `stock_current` at delivery time. A
 * payload that duplicated the booking would be a second copy of the ledger that could
 * disagree with the first.
 *
 * Points carry no user id, no note and no location: this is a series about what the
 * household spends, not about who booked it.
 */
class BookingEventPublisher
{
	/**
	 * Set by an explicit CLI drain so the request-end trigger does not run a second one on
	 * the way out of the same process.
	 *
	 * Per-process, gone when the process is - the same category as DatabaseService's dirty
	 * flag rather than the cold-start problem ADR-0007 forbids. It only saves a redundant
	 * pass: a second drain after a successful one finds an empty queue, and after a failed
	 * one would simply fail again and log twice.
	 */
	private static $RequestEndDrainSuppressed = false;

	/**
	 * Stops the request-end drain firing for the rest of this process.
	 */
	public static function SuppressRequestEndDrain(): void
	{
		self::$RequestEndDrainSuppressed = true;
	}

	/**
	 * Records that a stock booking happened, by appending to the outbox.
	 *
	 * **Call this inside the transaction that made the booking.** That is the entire
	 * guarantee: the outbox row and the ledger rows commit together or not at all, so the
	 * queue can never describe a booking that was rolled back, and a process that dies
	 * between the commit and the drain loses nothing.
	 *
	 * Nothing is written when InfluxDB is off. An outbox nobody drains is a leak, and this
	 * is the only consumer of this event type today.
	 *
	 * @param string|null $transactionId
	 */
	public static function RecordTransaction($transactionId): void
	{
		if (!InfluxEventWriter::IsEnabled() || $transactionId === null || $transactionId === '')
		{
			return;
		}

		try
		{
			(new OutboxService())->Enqueue(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, [
				'transaction_id' => (string)$transactionId
			]);
		}
		catch (\Throwable $ex)
		{
			// Deliberately swallowed rather than allowed to abort the booking. Losing an
			// event is a hole in a metrics series; failing the booking because a metrics
			// queue would not take a row is a household unable to record their shopping.
			error_log('Victual: could not enqueue a stock booking event for InfluxDB, the booking itself was unaffected: ' . $ex->getMessage());
		}
	}

	/**
	 * Whether this request has anything queued to deliver.
	 *
	 * Asked of the outbox rather than of process memory, so a request that books nothing
	 * still drains what an earlier failed attempt left behind - which is what makes a
	 * recovery happen on its own rather than needing somebody to notice.
	 */
	public static function HasBookings(): bool
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return false;
		}

		try
		{
			return count((new OutboxService())->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, 1)) > 0;
		}
		catch (\Throwable $ex)
		{
			return false;
		}
	}

	/**
	 * The request-end trigger: drains the outbox.
	 *
	 * Called from DatabaseService's shutdown handler once every transaction is closed, for
	 * the same reason the MQTT publish is: a point written for a booking that then rolled
	 * back is a number in a time series that nothing will ever correct.
	 *
	 * @return bool True when a batch was delivered
	 */
	public static function WriteForRequestEnd(): bool
	{
		if (self::$RequestEndDrainSuppressed)
		{
			return false;
		}

		return self::Drain();
	}

	/**
	 * Reads the undelivered events, builds one batch, sends it, and acknowledges only on
	 * success.
	 *
	 * The order matters and is the whole point: nothing is marked delivered before the
	 * consumer has taken it, so every failure mode - a timeout, a rejected write, the process
	 * dying mid-drain - leaves the rows exactly where they were.
	 *
	 * @return bool True when a batch was delivered
	 */
	public static function Drain(): bool
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return false;
		}

		$outbox = new OutboxService();

		try
		{
			$events = $outbox->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);
		}
		catch (\Throwable $ex)
		{
			error_log('Victual: could not read the outbox to deliver InfluxDB events: ' . $ex->getMessage());

			return false;
		}

		if (count($events) === 0)
		{
			return false;
		}

		$ids = array_column($events, 'id');
		$transactionIds = [];

		foreach ($events as $event)
		{
			if (isset($event['payload']['transaction_id']))
			{
				$transactionIds[] = (string)$event['payload']['transaction_id'];
			}
		}

		try
		{
			$lines = (new self())->BuildLines(array_values(array_unique($transactionIds)));
		}
		catch (\Throwable $ex)
		{
			// One line for the whole drain rather than one per event
			error_log('Victual: could not build the InfluxDB event batch, nothing was delivered: ' . $ex->getMessage());
			$outbox->RecordFailure($ids, $ex->getMessage());

			return false;
		}

		// An event whose bookings have all been undone and removed can produce no lines at
		// all. It is delivered rather than retried forever: there is nothing left to send.
		if (count($lines) === 0)
		{
			$outbox->MarkDelivered($ids);

			return true;
		}

		$writer = new InfluxEventWriter();

		if (!$writer->Write($lines))
		{
			$outbox->RecordFailure($ids, $writer->GetLastError() ?? 'the write was rejected');

			return false;
		}

		$outbox->MarkDelivered($ids);

		return true;
	}

	/**
	 * The line-protocol batch for a set of committed transactions.
	 *
	 * Every point's identity - measurement, tag set, timestamp - is unique to the thing it
	 * describes, which is what lets the outbox redeliver safely: writing the same identity
	 * again overwrites the point rather than adding a second one.
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
		$touchedProducts = [];

		// Raw SQL rather than LessQL because the IN list is variable length: the raw path takes
		// a positional parameter array, which is the one place in this tree that binds a list
		$placeholders = implode(', ', array_fill(0, count($transactionIds), '?'));
		$bookings = DatabaseService::GetInstance()->ExecuteDbQuery(
			'SELECT id, product_id, amount, price, transaction_type, transaction_id, undone, row_created_timestamp FROM stock_log'
				. ' WHERE transaction_id IN (' . $placeholders . ')',
			array_values($transactionIds)
		)->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($bookings as $booking)
		{
			$productId = (int)$booking['product_id'];
			$transactionId = (string)$booking['transaction_id'];

			// Keyed by both, so two transactions touching one product in the same request
			// produce two stock_value points rather than one that overwrites the other
			$touchedProducts[$transactionId . '|' . $productId] = ['product_id' => $productId, 'transaction_id' => $transactionId];

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

			// booking_id is what makes this point unique. Without it the identity is the
			// product and a timestamp truncated to the second, so two purchases of the same
			// product within one second would be one point with the second one's values.
			$lines[] = InfluxEventWriter::BuildLine('price_paid', [
				'product_id' => $productId,
				'booking_id' => (int)$booking['id'],
				'transaction_id' => $transactionId
			], [
				'price' => (float)$booking['price'],
				'amount' => (float)$booking['amount']
			], InfluxEventWriter::ToNanoseconds((string)$booking['row_created_timestamp']));
		}

		if (count($touchedProducts) === 0)
		{
			return $lines;
		}

		// Raw SQL again: stock_current has no id column, and LessQL wants one
		$currentStock = [];
		foreach (DatabaseService::GetInstance()
			->ExecuteDbQuery('SELECT product_id, amount, value FROM stock_current')
			->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$currentStock[(int)$row['product_id']] = $row;
		}

		$now = InfluxEventWriter::ToNanoseconds(date('Y-m-d H:i:s'));

		foreach ($touchedProducts as $touched)
		{
			$productId = $touched['product_id'];
			$row = $currentStock[$productId] ?? null;

			// A product consumed down to nothing drops out of stock_current entirely, and its
			// series has to say zero rather than simply stop - a gap would read as "no data"
			// where the truth is "none left"
			$lines[] = InfluxEventWriter::BuildLine('stock_value', [
				'product_id' => $productId,
				'transaction_id' => $touched['transaction_id']
			], [
				'value' => $row === null ? 0.0 : (float)$row['value'],
				'amount' => $row === null ? 0.0 : (float)$row['amount']
			], $now);
		}

		return $lines;
	}
}
