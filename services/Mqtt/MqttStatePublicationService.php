<?php

namespace Victual\Services\Mqtt;

use Victual\Services\DatabaseService;

/**
 * Ties the three pieces of MQTT state publication together and owns the two triggers.
 *
 * **Trigger one: the end of a request that changed data.** DatabaseService marks the
 * request dirty when a write statement goes through it and clears the mark when a
 * bookkeeping write restores the changed time, and its shutdown handler calls
 * PublishForRequestEnd() once, after everything else. That seam was chosen over explicit
 * calls at StockService's seven entrypoints for two reasons: it is the same "did anything
 * really change" question GET /api/system/db-changed-time already answers, so chores,
 * batteries, tasks, the shopping list and every generic CRUD write are covered without
 * naming any of them; and it fires once per request rather than once per commit, which is
 * what the plan's question 3 asks for - a shopping trip is many commits and one snapshot.
 *
 * **Trigger two: bin/victual-publish-state.** PHP has no boot event, so "publish on boot"
 * is a command the deployment runs, from a postStart hook or a Job alongside the
 * initContainer that runs bin/victual-migrate. It publishes the discovery payloads as well
 * as the state, which is what makes an out-of-band change - a migration, an import, someone
 * in psql - self-heal rather than silently diverge.
 *
 * Nothing here throws. A broker is not allowed to affect a write that has already
 * committed, so every failure is logged inside MqttPublisher and reported as a bool.
 */
class MqttStatePublicationService
{
	/**
	 * Set by an explicit publish so the request-end trigger does not fire a second time in
	 * the same process.
	 *
	 * This is not state between requests - it is a single process deciding not to do the
	 * same work twice within one run, and it is gone when the process is. bin/victual-migrate
	 * and bin/victual-db-import deliberately do not set it: they change data, so the
	 * request-end publish is exactly right for them.
	 */
	private static $RequestEndPublishSuppressed = false;

	/**
	 * Whether publication is configured on at all.
	 *
	 * Read as a constant so that a fork with MQTT off pays for this feature with one
	 * constant lookup and nothing else - no class loaded, no query run, no connection
	 * attempted.
	 */
	public static function IsEnabled(): bool
	{
		return defined('VICTUAL_MQTT_ENABLED') && VICTUAL_MQTT_ENABLED === true;
	}

	/**
	 * The request-end trigger, called from DatabaseService's shutdown handler when the
	 * request changed data and every transaction is closed.
	 *
	 * That last condition is checked by the caller rather than here, because the caller owns
	 * the PDO connection - but it is the point of the whole seam: a state published inside a
	 * transaction that then rolls back is a lie that persists in a retained topic.
	 *
	 * @return bool True when a snapshot was published
	 */
	public static function PublishForRequestEnd(): bool
	{
		if (self::$RequestEndPublishSuppressed || !self::IsEnabled())
		{
			return false;
		}

		return self::PublishState();
	}

	/**
	 * Publishes the state topics only. Used by the request-end trigger: discovery is a
	 * literal that does not change between requests, and republishing it on every write
	 * would make Home Assistant reprocess the device on every purchase.
	 */
	public static function PublishState(): bool
	{
		return self::Publish(false);
	}

	/**
	 * Publishes the discovery payloads and then the full state snapshot - what
	 * bin/victual-publish-state does, and what a fresh deployment needs so that Home
	 * Assistant learns the entities exist before it is told their values.
	 */
	public static function PublishDiscoveryAndState(): bool
	{
		return self::Publish(true);
	}

	/**
	 * Assembles and publishes: the ambient state topics always, the ambient discovery
	 * payloads when asked, and whichever per-product entities have appeared, changed or gone
	 * since the last publish.
	 *
	 * The ambient half is unconditional because a whole snapshot every time is the design -
	 * a publish lost to a broker restart is repaired by the next write with no
	 * reconciliation logic. The per-product half is a diff because it cannot be: retracting
	 * a removed entity means knowing it was there, and republishing hundreds of unchanged
	 * discovery payloads on every purchase would be a real cost rather than a theoretical
	 * one.
	 *
	 * The ledger is only updated after the broker has accepted the batch, so a failed publish
	 * is retried by the next one rather than being recorded as done.
	 */
	private static function Publish(bool $includeDiscovery): bool
	{
		if (!self::IsEnabled())
		{
			return false;
		}

		// Everything from the read to the ledger update is one critical section. Two
		// requests would otherwise interleave a read of the state with a write of it and
		// leave the older snapshot retained - silently, since nothing failed and retained
		// topics carry no ordering. The assembly is inside the lock, not just the publish:
		// a lock around the publish alone lets both requests read before either writes,
		// which is the same lost update with a smaller window.
		return DatabaseService::GetInstance()->GetDialect()->WithPublicationLock(function () use ($includeDiscovery)
		{
			return self::PublishLocked($includeDiscovery);
		});
	}

	/**
	 * The body of Publish(), called with the publication lock held.
	 */
	private static function PublishLocked(bool $includeDiscovery): bool
	{
		$builder = new DiscoveryPayloadBuilder();
		$ledger = new PublicationLedger();

		try
		{
			$topics = self::BuildStateTopics();

			if ($includeDiscovery)
			{
				$topics = array_merge($builder->BuildDiscoveryPayloads(), $topics);
			}

			$assembler = new StateSnapshotAssembler();
			$entities = $assembler->AssemblePerProductEntities();
			$orphanedFlags = $assembler->GetOrphanedFlagProductIds();
		}
		catch (\Throwable $ex)
		{
			// Assembling can throw on purpose - AssertNoForbiddenKeys() does - and refusing
			// to publish is the right outcome when it does
			error_log('Victual: could not assemble the MQTT state snapshot, nothing was published: ' . $ex->getMessage());

			return false;
		}

		$publishedBefore = $ledger->GetPublished();

		$record = [];
		foreach ($entities as $objectId => $entity)
		{
			$discovery = $builder->BuildProductDiscoveryPayload($entity['product_id'], $entity['attributes']);
			$state = StateSnapshotAssembler::EncodePayload($entity);
			$hash = hash('sha256', $discovery . "\n" . $state);

			if (($publishedBefore[$objectId] ?? null) === $hash)
			{
				// Byte-identical to what the ledger says is already retained: publishing it
				// again would change nothing a subscriber can see
				continue;
			}

			$topics[$builder->GetProductDiscoveryTopic($entity['product_id'])] = $discovery;
			$topics[$builder->GetProductStateTopic($entity['product_id'])] = $state;
			$record[$objectId] = $hash;
		}

		$forget = [];
		foreach (array_keys($publishedBefore) as $objectId)
		{
			if (isset($entities[$objectId]) || !str_starts_with($objectId, StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX))
			{
				continue;
			}

			// Gone: the product was deleted, deactivated, or its opt-in flag cleared. An empty
			// retained payload on the config topic is how Home Assistant is told to remove it
			$productId = (int)substr($objectId, strlen(StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX));

			$topics[$builder->GetProductDiscoveryTopic($productId)] = '';
			$topics[$builder->GetProductStateTopic($productId)] = '';
			$forget[] = $objectId;
		}

		if (!(new MqttPublisher())->PublishBatch($topics))
		{
			return false;
		}

		foreach ($record as $objectId => $hash)
		{
			$ledger->Record($objectId, $hash);
		}

		foreach ($forget as $objectId)
		{
			$ledger->Forget($objectId);
		}

		// Only now that their entities are retracted: a flag row for a product that no longer
		// exists has nothing left to describe
		$ledger->DropFlags($orphanedFlags);

		return true;
	}

	/**
	 * Clears every retained topic this version owns, by publishing an empty retained payload
	 * to each.
	 *
	 * An empty retained payload to a discovery config topic is how Home Assistant is told to
	 * remove an entity, and in device mode the removal propagates to that device's other
	 * components. Both discovery modes' topics are cleared, not just the configured one -
	 * see DiscoveryPayloadBuilder::GetAllDiscoveryTopics().
	 *
	 * The discovery topics go first: telling Home Assistant the entities are gone before
	 * clearing their state avoids a moment where an entity exists with no value.
	 */
	public static function Retract(): bool
	{
		if (!self::IsEnabled())
		{
			return false;
		}

		// Under the same lock as Publish(): a retraction racing a publish would otherwise
		// let the publish land after the empty payloads and resurrect every topic this was
		// asked to clear.
		return DatabaseService::GetInstance()->GetDialect()->WithPublicationLock(function ()
		{
			return self::RetractLocked();
		});
	}

	/**
	 * The body of Retract(), called with the publication lock held.
	 */
	private static function RetractLocked(): bool
	{
		$builder = new DiscoveryPayloadBuilder();

		$topics = [];

		foreach ($builder->GetAllDiscoveryTopics() as $topic)
		{
			$topics[$topic] = '';
		}

		foreach ($builder->GetAllStateTopics() as $topic)
		{
			$topics[$topic] = '';
		}

		// Per-product entities are only known from the ledger, which is exactly what it is for
		$ledger = new PublicationLedger();

		foreach (array_keys($ledger->GetPublished()) as $objectId)
		{
			if (!str_starts_with($objectId, StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX))
			{
				continue;
			}

			$productId = (int)substr($objectId, strlen(StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX));

			$topics[$builder->GetProductDiscoveryTopic($productId)] = '';
			$topics[$builder->GetProductStateTopic($productId)] = '';
		}

		if (!(new MqttPublisher())->PublishBatch($topics))
		{
			return false;
		}

		$ledger->ForgetAll();

		return true;
	}

	/**
	 * Stops the request-end trigger firing for the rest of this process, so an explicit CLI
	 * publish is not followed by a second one on the way out.
	 */
	public static function SuppressRequestEndPublish(): void
	{
		self::$RequestEndPublishSuppressed = true;
	}

	/**
	 * The state topics and their JSON payloads.
	 *
	 * @return array<string, string>
	 * @throws \Exception When the snapshot carries a forbidden key
	 */
	private static function BuildStateTopics(): array
	{
		$snapshot = (new StateSnapshotAssembler())->Assemble();
		$builder = new DiscoveryPayloadBuilder();

		$topics = [];

		foreach ($snapshot as $entity => $payload)
		{
			$topics[$builder->GetStateTopic($entity)] = StateSnapshotAssembler::EncodePayload($payload);
		}

		return $topics;
	}
}
