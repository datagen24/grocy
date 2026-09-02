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
	 * request changed data.
	 *
	 * The publish must not run with a transaction still open: the whole point of hanging off
	 * the after-commit seam is that a published state which then rolls back is a lie that
	 * persists in a retained topic. An open transaction at shutdown means something threw
	 * past InTransaction()'s rollback, so the honest thing is to skip and say so rather than
	 * publish a state that may be about to disappear.
	 *
	 * @return bool True when a snapshot was published
	 */
	public static function PublishForRequestEnd(): bool
	{
		if (self::$RequestEndPublishSuppressed || !self::IsEnabled())
		{
			return false;
		}

		try
		{
			if (DatabaseService::GetInstance()->GetDbConnectionRaw()->inTransaction())
			{
				error_log('Victual: skipped the MQTT state publish because a database transaction was still open at the end of the request');

				return false;
			}
		}
		catch (\Throwable $ex)
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
		if (!self::IsEnabled())
		{
			return false;
		}

		try
		{
			$topics = self::BuildStateTopics();
		}
		catch (\Throwable $ex)
		{
			// Assembling can throw on purpose - AssertNoForbiddenKeys() does - and refusing
			// to publish is the right outcome when it does
			error_log('Victual: could not assemble the MQTT state snapshot, nothing was published: ' . $ex->getMessage());

			return false;
		}

		return (new MqttPublisher())->PublishBatch($topics);
	}

	/**
	 * Publishes the discovery payloads and then the full state snapshot - what
	 * bin/victual-publish-state does, and what a fresh deployment needs so that Home
	 * Assistant learns the entities exist before it is told their values.
	 */
	public static function PublishDiscoveryAndState(): bool
	{
		if (!self::IsEnabled())
		{
			return false;
		}

		try
		{
			$topics = array_merge((new DiscoveryPayloadBuilder())->BuildDiscoveryPayloads(), self::BuildStateTopics());
		}
		catch (\Throwable $ex)
		{
			error_log('Victual: could not assemble the MQTT state snapshot, nothing was published: ' . $ex->getMessage());

			return false;
		}

		return (new MqttPublisher())->PublishBatch($topics);
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

		return (new MqttPublisher())->PublishBatch($topics);
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
			$topics[$builder->GetStateTopic($entity)] = json_encode($payload);
		}

		return $topics;
	}
}
