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
 * - **price_paid** - one point per purchase booking that carried a price, at the booking's
 *   own row_created_timestamp rather than at delivery time, so a backdated purchase lands
 *   where it belongs in the series.
 * - **stock_value** - one point per product the transaction touched, holding that product's
 *   stock value as it stood when the transaction committed, read from the same
 *   `stock_current` view the stock overview page shows the household.
 *
 * **Delivery is at-least-once through the outbox, not best-effort from process memory.**
 * RecordTransaction() writes an outbox row inside the booking's own transaction, so a
 * rollback takes it with it and a crash between the commit and the write does not lose it.
 * A drain reads the undelivered rows, builds one batch, POSTs it, and marks them delivered
 * only on success; a failure increments `attempts`, stores `last_error` and leaves the rows
 * for the next drain, which is any later request or an explicit
 * `bin/victual-publish-state --drain`.
 *
 * **The event is a self-contained immutable record, and that is what makes redelivery
 * safe.** The payload carries the moment the transaction committed, the booking rows as
 * they stood at that moment, and the post-commit `stock_current` snapshot for every product
 * touched. BuildLines() derives every line from the payload alone and queries nothing, so
 * rebuilding an event a week later produces byte-identical output.
 *
 * That property is load-bearing rather than tidy. A point in InfluxDB is identified by its
 * measurement, tag set and timestamp, and writing the same identity again overwrites rather
 * than appends - so a batch delivered twice is indistinguishable from one delivered once,
 * which is exactly what at-least-once delivery needs. Deriving anything at delivery time
 * breaks it twice over: a retry after a POST that succeeded but was never acknowledged
 * would compute a different timestamp and leave a second point, and a backlog drained after
 * an outage would give every queued transaction the *latest* stock snapshot instead of each
 * one's own. Both were real defects in the first version of this class.
 *
 * The identity itself: `price_paid` carries the `stock_log` row id as `booking_id`, so two
 * purchases of one product in the same second are two points rather than one, and both
 * measurements carry `transaction_id` so two transactions in one request cannot collide.
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
	 * Records that a stock booking happened, by appending a self-contained event to the
	 * outbox.
	 *
	 * **Call this inside the transaction that made the booking**, after its writes. Two
	 * things follow from that and both matter:
	 *
	 * - the outbox row and the ledger rows commit together or not at all, so the queue can
	 *   never describe a booking that was rolled back, and a process that dies between the
	 *   commit and the drain loses nothing;
	 * - the ledger reads below see this transaction's own uncommitted writes, so what is
	 *   captured is the post-commit state, which is the state the event is about.
	 *
	 * Everything the consumer will ever need is captured here rather than looked up at
	 * delivery time: the commit moment, the booking rows, and the resulting stock snapshot
	 * for each product touched. See the class docblock for why deriving any of it later is
	 * wrong rather than merely slower.
	 *
	 * **A failure here fails the booking.** It is deliberately not caught. Swallowing it
	 * would make the transactional guarantee conditional on the kind of error: an engine or
	 * error that does not abort the transaction would commit a booking with no event, and
	 * PostgreSQL would instead surface an aborted transaction later at commit() as something
	 * apparently unrelated. A booking whose event cannot be recorded has not fully happened,
	 * and rolling it back is the honest outcome - the household retries a purchase, which is
	 * a better failure than a silent hole in the series.
	 *
	 * Nothing is written when InfluxDB is off. An outbox nobody drains is a leak, and this
	 * is the only consumer of this event type today.
	 *
	 * @param string|null $transactionId
	 * @throws \Throwable When the event cannot be enqueued, aborting the caller's transaction
	 */
	public static function RecordTransaction($transactionId): void
	{
		if (!InfluxEventWriter::IsEnabled() || $transactionId === null || $transactionId === '')
		{
			return;
		}

		(new OutboxService())->Enqueue(
			OutboxService::EVENT_STOCK_TRANSACTION_BOOKED,
			(new self())->CaptureEvent((string)$transactionId)
		);
	}

	/**
	 * The immutable record of one committed transaction: when it happened, what was booked,
	 * and what the stock looked like afterwards.
	 *
	 * Bounded by what a transaction touches, which is a handful of products - a purchase is
	 * one, a recipe consumption a few, an undo the size of the transaction it reverses.
	 * Nothing here grows with the size of the catalogue or of the ledger.
	 *
	 * `occurred_at` is the moment the transaction committed rather than any booking's own
	 * timestamp, because it is the moment the stock snapshot is true at. The bookings carry
	 * their own timestamps separately, which is what puts a backdated purchase where it
	 * belongs in the price series.
	 *
	 * @return array The outbox payload
	 */
	public function CaptureEvent(string $transactionId): array
	{
		$bookings = [];
		$productIds = [];

		foreach (DatabaseService::GetInstance()->ExecuteDbQuery(
			'SELECT id, product_id, amount, price, transaction_type, undone, row_created_timestamp'
				. ' FROM stock_log WHERE transaction_id = ? ORDER BY id',
			[$transactionId]
		)->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$productId = (int)$row['product_id'];
			$productIds[$productId] = true;

			$bookings[] = [
				'booking_id' => (int)$row['id'],
				'product_id' => $productId,
				'amount' => (float)$row['amount'],
				// Kept null rather than coerced to 0.0: a booking with no price is not a
				// booking at a price of nothing, and the difference decides whether a
				// price_paid point exists at all
				'price' => $row['price'] === null ? null : (float)$row['price'],
				'transaction_type' => (string)$row['transaction_type'],
				'undone' => (int)$row['undone'],
				'row_created_timestamp' => (string)$row['row_created_timestamp']
			];
		}

		$snapshot = [];

		if (count($productIds) > 0)
		{
			// Raw SQL because stock_current has no id column and LessQL wants one. Read
			// whole rather than once per product: the view is a handful of rows at
			// household scale, and one query beats one per product.
			foreach (DatabaseService::GetInstance()
				->ExecuteDbQuery('SELECT product_id, amount, value FROM stock_current')
				->fetchAll(\PDO::FETCH_ASSOC) as $row)
			{
				$productId = (int)$row['product_id'];

				if (isset($productIds[$productId]))
				{
					$snapshot[] = [
						'product_id' => $productId,
						'amount' => (float)$row['amount'],
						'value' => (float)$row['value']
					];
					unset($productIds[$productId]);
				}
			}

			// A product consumed down to nothing drops out of stock_current entirely, and
			// its series has to say zero rather than simply stop - a gap would read as "no
			// data" where the truth is "none left"
			foreach (array_keys($productIds) as $productId)
			{
				$snapshot[] = ['product_id' => $productId, 'amount' => 0.0, 'value' => 0.0];
			}
		}

		return [
			'transaction_id' => $transactionId,
			'occurred_at' => date('Y-m-d H:i:s'),
			'bookings' => $bookings,
			'stock' => $snapshot
		];
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
	 * **One batch per request, deliberately.** A request that finds a long backlog should
	 * not turn into an unbounded write on somebody's purchase; the next request takes the
	 * next batch, and the queue empties over a handful of them. An operator who wants the
	 * whole backlog gone now runs `bin/victual-publish-state --drain`, which loops.
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

		try
		{
			$lines = self::BuildLines(array_column($events, 'payload'));
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
	 * The line-protocol batch for a set of captured events.
	 *
	 * **Nothing here reads the database.** Every value comes from the payload
	 * CaptureEvent() wrote inside the booking's transaction, which is what makes rebuilding
	 * an event produce byte-identical output however long afterwards it happens, and
	 * therefore what makes the outbox's at-least-once delivery safe. A version of this that
	 * re-read the ledger produced a different timestamp on every retry and gave a whole
	 * drained backlog the same latest stock snapshot; both are recorded in plan 18's
	 * Executed section.
	 *
	 * Every point's identity - measurement, tag set, timestamp - is unique to the thing it
	 * describes, so writing it again overwrites the point rather than adding a second one.
	 *
	 * @param array[] $payloads Outbox payloads as CaptureEvent() built them
	 * @return string[]
	 */
	public static function BuildLines(array $payloads): array
	{
		$lines = [];

		foreach ($payloads as $payload)
		{
			$transactionId = (string)($payload['transaction_id'] ?? '');

			if ($transactionId === '' || !isset($payload['occurred_at']))
			{
				// An event this version cannot read. Skipped rather than thrown on, so one
				// unreadable row cannot wedge the queue behind it forever.
				continue;
			}

			$occurredAt = InfluxEventWriter::ToNanoseconds((string)$payload['occurred_at']);

			foreach (($payload['bookings'] ?? []) as $booking)
			{
				// Undone bookings still counted as touching the product when the snapshot was
				// taken - undoing a purchase changes what the stock is worth - but they are
				// not a price the household paid, so they contribute only to stock_value.
				//
				// Only a purchase carries a price paid at all. A consume booking also has a
				// price column, the value of what left stock, and writing it here would make
				// "price paid" mean two different things in one series.
				if ((int)($booking['undone'] ?? 0) === 1
					|| ($booking['transaction_type'] ?? '') !== 'purchase'
					|| ($booking['price'] ?? null) === null)
				{
					continue;
				}

				// booking_id is what makes this point unique. Without it the identity is the
				// product and a timestamp truncated to the second, so two purchases of the
				// same product within one second would be one point holding the second one's
				// values.
				$lines[] = InfluxEventWriter::BuildLine('price_paid', [
					'product_id' => (int)$booking['product_id'],
					'booking_id' => (int)$booking['booking_id'],
					'transaction_id' => $transactionId
				], [
					'price' => (float)$booking['price'],
					'amount' => (float)$booking['amount']
				], InfluxEventWriter::ToNanoseconds((string)$booking['row_created_timestamp']));
			}

			foreach (($payload['stock'] ?? []) as $stock)
			{
				$lines[] = InfluxEventWriter::BuildLine('stock_value', [
					'product_id' => (int)$stock['product_id'],
					'transaction_id' => $transactionId
				], [
					'value' => (float)$stock['value'],
					'amount' => (float)$stock['amount']
				], $occurredAt);
			}
		}

		return $lines;
	}
}
