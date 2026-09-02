<?php

namespace Victual\Services\Mqtt;

use Victual\Services\BaseService;
use Victual\Services\Database\ValueComparison;

/**
 * Builds the ambient state snapshot that gets published to MQTT: the whole read model,
 * every time, as plain facts.
 *
 * Two rules shape everything here, and both come from
 * docs/plans/18-mqtt-state-publication.md.
 *
 * **Facts, never derived states.** A derived state ("expiring soon", "chore overdue") is a
 * function of the data *and* the current time, so publishing one means something has to be
 * awake to recompute it as the clock moves - which is exactly what this plan exists to
 * avoid. So this emits best_before_date and next_estimated_execution_time and no boolean
 * that depends on now(). Home Assistant renders a "device_class: timestamp" sensor as
 * relative time and compares it against now() locally, at no cost to a server that may be
 * asleep for days.
 *
 * **The wall-tablet test.** Anything with broker credentials reads these topics without
 * authenticating to Victual at all, so the payload carries only what a wall tablet would
 * show. That is enforced twice over: DENIED_COLUMNS names the columns of the views this
 * reads which must never leave (every price, cost and value column, plus notes), and each
 * entity's allow-list names the only keys it may emit. Neither list is trusted on its own -
 * AssertNoForbiddenKeys() walks the finished payload and throws rather than publish a key
 * that looks like money.
 *
 * The views read here are the ones the UI reads, which is deliberate: a divergence between
 * the engines shows up in this payload before it shows up anywhere a person would notice.
 */
class StateSnapshotAssembler extends BaseService
{
	/**
	 * Entity ids, in publication order. These are the object ids in the discovery payload
	 * and the last segment of every state topic, so they are part of the contract with any
	 * subscriber and do not change without a retraction.
	 */
	const ENTITY_STOCK = 'stock';
	const ENTITY_SHOPPING_LIST = 'shopping_list';
	const ENTITY_NEXT_CHORE = 'next_chore';
	const ENTITY_NEXT_BATTERY = 'next_battery';
	const ENTITY_NEXT_TASK = 'next_task';
	const ENTITY_LAST_PUBLISHED = 'last_published';

	/**
	 * Columns of the views below which must never reach a topic, by name.
	 *
	 * This is the deny-list half of the guard and it is written out rather than derived,
	 * because the point of it is to be reviewable: a reader can check it against
	 * uihelper_stock_current_overview (migrations/0252.sql) and uihelper_shopping_list
	 * (db/pgsql/baseline/04_views_l1b.sql) by eye. "value", "last_price" and "average_price"
	 * are the three the plan's security note names explicitly - they are in the stock
	 * overview the UI reads and would ship by default if the attributes were assembled from
	 * a bare row. "note" is not money but fails the same wall-tablet test.
	 */
	const DENIED_COLUMNS = [
		'value',
		'last_price',
		'average_price',
		'avg_price',
		'price',
		'last_price_unit',
		'last_price_total',
		'costs',
		'note',
		'api_key'
	];

	/**
	 * The only keys each entity's attribute rows may carry. The allow-list half of the
	 * guard: a column added to one of these views in a later migration cannot appear here
	 * by accident, only by being named.
	 */
	const ALLOWED_ROW_KEYS = [
		self::ENTITY_STOCK => ['product_id', 'product_name', 'amount', 'unit', 'best_before_date'],
		self::ENTITY_SHOPPING_LIST => ['shopping_list_id', 'product_id', 'product_name', 'amount', 'unit'],
		self::ENTITY_NEXT_CHORE => ['chore_id', 'chore_name', 'next_estimated_execution_time'],
		self::ENTITY_NEXT_BATTERY => ['battery_id', 'battery_name', 'next_estimated_charge_time'],
		self::ENTITY_NEXT_TASK => ['task_id', 'task_name', 'due_date']
	];

	/**
	 * Anything matching this in an emitted key is a bug, and a loud one.
	 *
	 * The deny-list catches the columns that exist today; this catches the ones a future
	 * migration invents. Deliberately broad - "value" matches more than money does - because
	 * a false positive here is a five minute rename and a false negative is household
	 * pricing sitting on a retained topic until somebody notices.
	 */
	const FORBIDDEN_KEY_PATTERN = '/price|cost|value/i';

	/**
	 * The date the views use to mean "no due date at all": the stock overview substitutes
	 * 2888-12-31 for a null best before date and batteries_current substitutes 2999-12-31
	 * for a battery with no charge interval. Both are sentinels rather than dates, and
	 * publishing them would put a sensor eight hundred years in the future on a wall.
	 */
	const NO_DUE_DATE_SENTINEL_FROM = '2888-01-01';

	/**
	 * The whole snapshot: entity id => ['state' => scalar|null, 'attributes' => array].
	 *
	 * @param string|null $publishedAt "Y-m-d H:i:s" for the last_published sensor; defaults
	 *                                 to now. Passed in only so a test can pin it.
	 * @throws \Exception When the assembled payload carries a forbidden key
	 */
	public function Assemble(?string $publishedAt = null): array
	{
		$snapshot = [
			self::ENTITY_STOCK => $this->AssembleStock(),
			self::ENTITY_SHOPPING_LIST => $this->AssembleShoppingList(),
			self::ENTITY_NEXT_CHORE => $this->AssembleNextChore(),
			self::ENTITY_NEXT_BATTERY => $this->AssembleNextBattery(),
			self::ENTITY_NEXT_TASK => $this->AssembleNextTask(),
			self::ENTITY_LAST_PUBLISHED => [
				'state' => self::ToIso8601($publishedAt ?? date('Y-m-d H:i:s')),
				'attributes' => []
			]
		];

		self::AssertNoForbiddenKeys($snapshot);

		return $snapshot;
	}

	/**
	 * Products currently in stock: the count as the state, one row per product as
	 * attributes.
	 *
	 * Reads uihelper_stock_current_overview, the same view the stock overview page reads,
	 * filtered to a positive amount - the view also lists products below their minimum
	 * stock amount with an amount of zero, and "in stock" is the fact worth counting.
	 */
	private function AssembleStock(): array
	{
		$rows = [];

		foreach ($this->DB->uihelper_stock_current_overview()->where('amount > 0')->orderBy('product_name') as $row)
		{
			$rows[] = self::FilterRow(self::ENTITY_STOCK, [
				'product_id' => (int)$row['product_id'],
				'product_name' => (string)$row['product_name'],
				'amount' => ValueComparison::Normalise($row['amount']),
				'unit' => self::NullableString($row['qu_stock_name']),
				'best_before_date' => self::AsDateFact($row['best_before_date'])
			]);
		}

		return ['state' => count($rows), 'attributes' => ['products' => $rows]];
	}

	/**
	 * Everything on every shopping list: the item count as the state, one row per item as
	 * attributes.
	 *
	 * uihelper_shopping_list selects sl.* and therefore carries the item's note as well as
	 * three price columns; none of them survives the allow-list.
	 */
	private function AssembleShoppingList(): array
	{
		$rows = [];

		foreach ($this->DB->uihelper_shopping_list()->orderBy('product_name') as $row)
		{
			$rows[] = self::FilterRow(self::ENTITY_SHOPPING_LIST, [
				'shopping_list_id' => (int)$row['shopping_list_id'],
				'product_id' => $row['product_id'] === null ? null : (int)$row['product_id'],
				'product_name' => self::NullableString($row['product_name']),
				'amount' => ValueComparison::Normalise($row['amount']),
				'unit' => self::NullableString($row['qu_name'])
			]);
		}

		return ['state' => count($rows), 'attributes' => ['items' => $rows]];
	}

	/**
	 * The next chore due, as a timestamp, with every scheduled chore as attributes.
	 *
	 * chores_current returns null for a chore with period type "manually", which has no next
	 * execution to publish; those are left out rather than given a made up date.
	 */
	private function AssembleNextChore(): array
	{
		$rows = [];

		foreach ($this->DB->chores_current() as $row)
		{
			$due = self::AsTimestampFact($row['next_estimated_execution_time']);
			if ($due === null)
			{
				continue;
			}

			$rows[] = self::FilterRow(self::ENTITY_NEXT_CHORE, [
				'chore_id' => (int)$row['chore_id'],
				'chore_name' => (string)$row['chore_name'],
				'next_estimated_execution_time' => $due
			]);
		}

		return self::EarliestOf($rows, 'next_estimated_execution_time', 'chores');
	}

	/**
	 * The next battery charge due, as a timestamp, with every active battery as attributes.
	 *
	 * The battery name is not in batteries_current (which groups by id), so it comes from
	 * the batteries table the same way BatteriesService::GetCurrent() gets it.
	 */
	private function AssembleNextBattery(): array
	{
		$names = [];
		foreach ($this->DB->batteries()->where('active = 1') as $battery)
		{
			$names[(int)$battery['id']] = (string)$battery['name'];
		}

		$rows = [];

		foreach ($this->DB->batteries_current() as $row)
		{
			$due = self::AsTimestampFact($row['next_estimated_charge_time']);
			if ($due === null)
			{
				continue;
			}

			$batteryId = (int)$row['battery_id'];

			$rows[] = self::FilterRow(self::ENTITY_NEXT_BATTERY, [
				'battery_id' => $batteryId,
				'battery_name' => $names[$batteryId] ?? null,
				'next_estimated_charge_time' => $due
			]);
		}

		return self::EarliestOf($rows, 'next_estimated_charge_time', 'batteries');
	}

	/**
	 * The next task due, as a timestamp, with every open dated task as attributes.
	 *
	 * A task's due date is a date rather than a timestamp, and the tasks page treats it as
	 * due until the end of that day (TasksController::Overview compares against
	 * "Y-m-d 23:59:59"), so that is the instant published here. A task with no due date has
	 * no fact to publish and is left out.
	 */
	private function AssembleNextTask(): array
	{
		$rows = [];

		foreach ($this->DB->tasks_current() as $row)
		{
			$due = self::AsEndOfDayFact($row['due_date']);
			if ($due === null)
			{
				continue;
			}

			$rows[] = self::FilterRow(self::ENTITY_NEXT_TASK, [
				'task_id' => (int)$row['id'],
				'task_name' => (string)$row['name'],
				'due_date' => $due
			]);
		}

		return self::EarliestOf($rows, 'due_date', 'tasks');
	}

	/**
	 * Turns a set of dated rows into a timestamp entity: the earliest date as the state, the
	 * rows sorted by date as the attributes.
	 *
	 * A state of null (nothing scheduled) is honest rather than convenient - Home Assistant
	 * shows it as unknown, which is what "there is no next chore" means.
	 */
	private static function EarliestOf(array $rows, string $dateKey, string $attributeKey): array
	{
		usort($rows, function ($a, $b) use ($dateKey)
		{
			return strcmp($a[$dateKey], $b[$dateKey]);
		});

		return [
			'state' => count($rows) === 0 ? null : $rows[0][$dateKey],
			'attributes' => [$attributeKey => $rows]
		];
	}

	/**
	 * Reduces an assembled row to the keys its entity is allowed to emit, after removing
	 * anything on the deny-list.
	 *
	 * Both directions are applied even though the rows above are built by hand: the value of
	 * a guard is that it holds when somebody later builds a row a different way.
	 */
	private static function FilterRow(string $entity, array $row): array
	{
		foreach (self::DENIED_COLUMNS as $denied)
		{
			unset($row[$denied]);
		}

		return array_intersect_key($row, array_flip(self::ALLOWED_ROW_KEYS[$entity]));
	}

	/**
	 * Throws when any key anywhere in the payload looks like money.
	 *
	 * The last line of defence, and the one .devtools/mqtt/price-guard.php exercises.
	 * Throwing means nothing is published, which is the right direction to fail in: a
	 * missing snapshot is repaired by the next write, a published price is not.
	 *
	 * @param mixed $payload
	 * @throws \Exception
	 */
	public static function AssertNoForbiddenKeys($payload, string $path = ''): void
	{
		if (!is_array($payload))
		{
			return;
		}

		foreach ($payload as $key => $value)
		{
			if (is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1)
			{
				throw new \Exception('Refusing to publish "' . $path . $key . '": the MQTT payload may not carry price, cost or value fields (docs/plans/18-mqtt-state-publication.md, question 8)');
			}

			self::AssertNoForbiddenKeys($value, $path . $key . '.');
		}
	}

	/**
	 * A date column as a published fact: the stored date, or null for the "never due"
	 * sentinel. Deliberately not converted to a timestamp - the data has no time of day in
	 * it, and inventing one would be publishing something the database does not know.
	 */
	private static function AsDateFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return substr($value, 0, 10);
	}

	/**
	 * A timestamp column as ISO 8601 with an offset, which is what Home Assistant's
	 * "timestamp" device class requires. Null for the "never due" sentinel.
	 */
	private static function AsTimestampFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return self::ToIso8601($value);
	}

	/**
	 * A date column as the ISO 8601 instant that date stops being "today" locally.
	 */
	private static function AsEndOfDayFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return self::ToIso8601(substr($value, 0, 10) . ' 23:59:59');
	}

	/**
	 * "Y-m-d H:i:s" (what both engines store and hand back) as ISO 8601 with the server's
	 * UTC offset. The stored timestamps are local times - see
	 * DatabaseDialect::GetNowExpression() - so they are read in the configured timezone.
	 */
	private static function ToIso8601(string $localTimestamp): string
	{
		return (new \DateTimeImmutable($localTimestamp))->format(\DateTimeInterface::ATOM);
	}

	/**
	 * PDO hands back strings from one engine where the other gives numbers or nulls; this is
	 * the one place that difference is absorbed for text-shaped columns.
	 */
	private static function NullableString($value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return (string)$value;
	}
}
