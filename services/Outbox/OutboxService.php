<?php

namespace Victual\Services\Outbox;

use Victual\Services\BaseService;
use Victual\Services\DatabaseService;

/**
 * The transactional outbox: events written inside the transaction that produced them, and
 * acknowledged only once a consumer has actually taken them.
 *
 * This is the first instance of ADR-0010's contract, and the constitution's workload
 * standard is what it exists to satisfy: every consumer is an at-least-once consumer, every
 * side effect is idempotent or deduplicated, and queues are drained rather than fired and
 * forgotten.
 *
 * The mechanism is one sentence. **Enqueue() is called inside the caller's transaction**, so
 * a rollback takes the event with it and the outbox can never describe something that did
 * not happen; **a drain acknowledges only after the consumer succeeded**, so a crash, a
 * timeout or a rejected write leaves the row for the next attempt. What it buys is that
 * nothing committed is lost; what it costs is that a consumer may see an event twice - which
 * is why the consumer has to be idempotent (see BookingEventPublisher::BuildLines(), where
 * every point carries a unique identity so a redelivered batch overwrites rather than
 * duplicates).
 *
 * One table, discriminated by event_type, because that record's rule is that consumers may
 * multiply and contracts may not. A second consumer attaches by reading its own event type,
 * not by minting a table of its own.
 *
 * Nothing writes to the outbox unless the consumer for that event type is configured on. An
 * outbox nobody drains is a leak - rows accumulate for a delivery that will never be
 * attempted - so the enabled check belongs at the enqueue site, not only at the drain.
 */
class OutboxService extends BaseService
{
	/**
	 * A stock booking was committed. Payload: {"transaction_id": "..."}.
	 *
	 * The payload is deliberately just the identifier. Every fact the consumer needs is in
	 * stock_log and is read at delivery time, because a payload that duplicated the booking
	 * would be a second copy of the ledger that could disagree with the first.
	 */
	const EVENT_STOCK_TRANSACTION_BOOKED = 'stock.transaction_booked';

	/**
	 * How many undelivered rows one drain takes. Bounded so that a long outage does not turn
	 * the first request after it into an unbounded batch; what is left is taken by the next
	 * drain.
	 */
	const DRAIN_BATCH_SIZE = 200;

	/**
	 * Appends an event, to be committed with whatever transaction the caller has open.
	 *
	 * There is no transaction handling here on purpose. The guarantee comes from this INSERT
	 * being part of the caller's transaction, so opening one here would break exactly the
	 * property the outbox exists for.
	 *
	 * @param string $eventType One of the EVENT_* constants
	 * @param array $payload Encoded as JSON; keep it small
	 */
	public function Enqueue(string $eventType, array $payload): void
	{
		$this->DB->outbox()->createRow([
			'event_type' => $eventType,
			'payload' => json_encode($payload),
			'attempts' => 0
		])->save();
	}

	/**
	 * The undelivered events of one type, oldest first.
	 *
	 * @return array<int, array{id: int, payload: array}>
	 */
	public function GetUndelivered(string $eventType, int $limit = self::DRAIN_BATCH_SIZE): array
	{
		$rows = [];

		foreach ($this->DB->outbox()->where('event_type = :1 AND delivered_at IS NULL', $eventType)->orderBy('id')->limit($limit) as $row)
		{
			$rows[] = [
				'id' => (int)$row['id'],
				'payload' => json_decode((string)$row['payload'], true) ?: []
			];
		}

		return $rows;
	}

	/**
	 * Marks rows delivered. Called only after the consumer has confirmed it took them.
	 *
	 * @param int[] $ids
	 */
	public function MarkDelivered(array $ids): void
	{
		if (count($ids) === 0)
		{
			return;
		}

		$this->AsBookkeeping(function () use ($ids)
		{
			$now = date('Y-m-d H:i:s');

			foreach ($ids as $id)
			{
				$row = $this->DB->outbox()->where('id = :1', (int)$id)->fetch();

				if ($row !== null)
				{
					$row->update(['delivered_at' => $now]);
				}
			}
		});
	}

	/**
	 * Records a failed delivery attempt against rows that stay in the queue.
	 *
	 * attempts and last_error are what make a permanently failing event visible rather than
	 * merely slow - without them a poison event is indistinguishable from an idle queue.
	 *
	 * @param int[] $ids
	 */
	public function RecordFailure(array $ids, string $error): void
	{
		if (count($ids) === 0)
		{
			return;
		}

		$this->AsBookkeeping(function () use ($ids, $error)
		{
			foreach ($ids as $id)
			{
				$row = $this->DB->outbox()->where('id = :1', (int)$id)->fetch();

				if ($row !== null)
				{
					$row->update([
						'attempts' => (int)$row['attempts'] + 1,
						// Bounded: a driver can return a very long message and this column is
						// for a human glancing at why a queue stopped moving
						'last_error' => substr($error, 0, 1000)
					]);
				}
			}
		});
	}

	/**
	 * Runs a write without letting it count as a data change.
	 *
	 * Draining is bookkeeping: it records what was delivered, not what the household did.
	 * Without this, acknowledging a delivery would mark the database changed, which would
	 * mark the request dirty, which is the condition that triggered the drain - so every
	 * drain would schedule another one. The idiom is the tree's own
	 * (SessionService::IsValidSession).
	 */
	private function AsBookkeeping(callable $work): void
	{
		$database = DatabaseService::GetInstance();
		$changedTime = $database->GetDbChangedTime();

		$work();

		$database->SetDbChangedTime($changedTime);
	}
}
