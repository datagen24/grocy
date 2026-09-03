<?php

namespace Victual\Services\Mqtt;

/**
 * Builds the Home Assistant MQTT discovery payloads, and owns the topic layout every other
 * part of this feature agrees on.
 *
 * Two shapes, chosen by MQTT_DISCOVERY_MODE, because
 * docs/plans/18-mqtt-state-publication.md prefers one and is honest that its evidence for
 * the floor is second-hand:
 *
 * - **"device"** (the default): one retained config topic,
 *   <discovery_prefix>/device/<node_id>/config, declaring every entity at once under a
 *   "components" key, with "device" and "origin" at the root and a "platform" and
 *   "unique_id" per entity. That is the right shape here - this is one device with a
 *   handful of sensors - and it makes retraction a single message. Home Assistant's
 *   mqtt/discovery.py handles CONF_COMPONENTS explicitly; the release that introduced it
 *   is reported as 2024.11, which this fork has not confirmed first hand.
 * - **"entity"**: the older per-entity form,
 *   <discovery_prefix>/sensor/<node_id>/<object_id>/config, one topic each. It costs four
 *   extra topics and works on every Home Assistant that supports MQTT discovery at all, so
 *   it is the fallback for an installation older than the floor above.
 *
 * Each entity's state and attributes ride on a single retained topic carrying
 * {"state": ..., "attributes": {...}}, read back through value_template and
 * json_attributes_template. One topic rather than two means state and attributes can never
 * be seen half updated, and halves the retained topics a subscriber has to receive.
 */
class DiscoveryPayloadBuilder
{
	/**
	 * Everything about each published entity that is a literal rather than data.
	 *
	 * Note what is absent: no availability_topic, no expire_after, and no device_class that
	 * implies a derived state. The three due sensors are device_class "timestamp", which is
	 * what lets Home Assistant render them as relative time and compare them against now()
	 * on its own side - the whole reason this publishes facts rather than "overdue".
	 */
	const ENTITY_DEFINITIONS = [
		StateSnapshotAssembler::ENTITY_STOCK => [
			'name' => 'Stock',
			'icon' => 'mdi:fridge-outline',
			'unit_of_measurement' => 'products',
			'state_class' => 'measurement'
		],
		StateSnapshotAssembler::ENTITY_SHOPPING_LIST => [
			'name' => 'Shopping list',
			'icon' => 'mdi:cart-outline',
			'unit_of_measurement' => 'items',
			'state_class' => 'measurement'
		],
		StateSnapshotAssembler::ENTITY_NEXT_CHORE => [
			'name' => 'Next chore due',
			'icon' => 'mdi:broom',
			'device_class' => 'timestamp'
		],
		StateSnapshotAssembler::ENTITY_NEXT_BATTERY => [
			'name' => 'Next battery charge due',
			'icon' => 'mdi:battery-charging',
			'device_class' => 'timestamp'
		],
		StateSnapshotAssembler::ENTITY_NEXT_TASK => [
			'name' => 'Next task due',
			'icon' => 'mdi:checkbox-marked-circle-outline',
			'device_class' => 'timestamp'
		],
		StateSnapshotAssembler::ENTITY_PRODUCTS_DUE_SOON => [
			'name' => 'Products due soon',
			'icon' => 'mdi:clock-alert-outline',
			'unit_of_measurement' => 'products',
			'state_class' => 'measurement'
		],
		StateSnapshotAssembler::ENTITY_PRODUCTS_EXPIRED => [
			'name' => 'Products expired',
			'icon' => 'mdi:food-off-outline',
			'unit_of_measurement' => 'products',
			'state_class' => 'measurement'
		],
		StateSnapshotAssembler::ENTITY_LAST_PUBLISHED => [
			'name' => 'Last published',
			'icon' => 'mdi:clock-outline',
			'device_class' => 'timestamp',
			'entity_category' => 'diagnostic'
		]
	];

	/**
	 * The node id: a stable, discovery-safe identifier for this installation, derived from
	 * the topic prefix so that two Victuals on one broker do not collide as long as their
	 * prefixes differ. Home Assistant restricts it to word characters and hyphens.
	 */
	public function GetNodeId(): string
	{
		$nodeId = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)VICTUAL_MQTT_TOPIC_PREFIX);

		return $nodeId === '' ? 'victual' : $nodeId;
	}

	/**
	 * The retained topic carrying one entity's state and attributes.
	 */
	public function GetStateTopic(string $entity): string
	{
		return rtrim((string)VICTUAL_MQTT_TOPIC_PREFIX, '/') . '/state/' . $entity;
	}

	/**
	 * Every state topic, in entity order.
	 *
	 * @return array<string, string> Entity id => topic
	 */
	public function GetAllStateTopics(): array
	{
		$topics = [];

		foreach (array_keys(self::ENTITY_DEFINITIONS) as $entity)
		{
			$topics[$entity] = $this->GetStateTopic($entity);
		}

		return $topics;
	}

	/**
	 * The discovery config topics this version owns, in both modes rather than only the
	 * configured one.
	 *
	 * Retraction needs all of them: switching MQTT_DISCOVERY_MODE leaves the other mode's
	 * retained config behind, and a config topic nobody rewrites is this design's one
	 * operational wart. Clearing both is cheap and means "--retract" actually means it.
	 *
	 * @return string[]
	 */
	public function GetAllDiscoveryTopics(): array
	{
		$prefix = rtrim((string)VICTUAL_MQTT_DISCOVERY_PREFIX, '/');
		$nodeId = $this->GetNodeId();

		$topics = [$prefix . '/device/' . $nodeId . '/config'];

		foreach (array_keys(self::ENTITY_DEFINITIONS) as $entity)
		{
			$topics[] = $prefix . '/sensor/' . $nodeId . '/' . $entity . '/config';
		}

		return $topics;
	}

	/**
	 * The discovery payloads to publish for the configured mode.
	 *
	 * @return array<string, string> Topic => JSON payload
	 */
	public function BuildDiscoveryPayloads(): array
	{
		$prefix = rtrim((string)VICTUAL_MQTT_DISCOVERY_PREFIX, '/');
		$nodeId = $this->GetNodeId();

		if (VICTUAL_MQTT_DISCOVERY_MODE === 'entity')
		{
			$payloads = [];

			foreach (self::ENTITY_DEFINITIONS as $entity => $definition)
			{
				$payload = $this->BuildComponent($entity, $definition);

				// In per-entity mode the component is named by the topic, so the key that
				// names it in device mode is redundant
				unset($payload['platform']);

				$payload['device'] = $this->BuildDevice();
				$payload['origin'] = $this->BuildOrigin();

				$payloads[$prefix . '/sensor/' . $nodeId . '/' . $entity . '/config'] = json_encode($payload);
			}

			return $payloads;
		}

		$components = [];
		foreach (self::ENTITY_DEFINITIONS as $entity => $definition)
		{
			$components[$entity] = $this->BuildComponent($entity, $definition);
		}

		return [
			$prefix . '/device/' . $nodeId . '/config' => json_encode([
				'device' => $this->BuildDevice(),
				'origin' => $this->BuildOrigin(),
				'components' => $components,
				'qos' => 0
			])
		];
	}

	/**
	 * The retained topic carrying one opted-in product's state and attributes.
	 */
	public function GetProductStateTopic(int $productId): string
	{
		return rtrim((string)VICTUAL_MQTT_TOPIC_PREFIX, '/') . '/state/product/' . $productId;
	}

	/**
	 * The discovery config topic for one opted-in product.
	 *
	 * Per-product entities always use the per-entity discovery form, whatever
	 * MQTT_DISCOVERY_MODE says, and that is deliberate rather than an oversight. Question 2's
	 * Response requires that removing one product's entity - by deleting the product,
	 * deactivating it or clearing its flag - retracts exactly that entity; an empty retained
	 * payload on its own config topic is the removal path the plan names and the only one
	 * this fork has confirmed. Folding hundreds of them into the single device config would
	 * make every product's removal a rewrite of every other product's config.
	 */
	public function GetProductDiscoveryTopic(int $productId): string
	{
		return rtrim((string)VICTUAL_MQTT_DISCOVERY_PREFIX, '/') . '/sensor/' . $this->GetNodeId()
			. '/' . StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX . $productId . '/config';
	}

	/**
	 * The discovery payload for one opted-in product, as JSON.
	 *
	 * It belongs to the same Home Assistant device as the ambient sensors, so the household
	 * sees one Victual device with the summary sensors and whichever products it promoted.
	 *
	 * @param array $attributes The entity's attributes, for the product name
	 */
	public function BuildProductDiscoveryPayload(int $productId, array $attributes): string
	{
		$objectId = StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX . $productId;
		$stateTopic = $this->GetProductStateTopic($productId);

		return json_encode([
			'unique_id' => $this->GetNodeId() . '_' . $objectId,
			'object_id' => $this->GetNodeId() . '_' . $objectId,
			'name' => $attributes['product_name'] ?? ('Product ' . $productId),
			'icon' => 'mdi:package-variant-closed',
			'state_class' => 'measurement',
			'unit_of_measurement' => $attributes['unit'] ?? null,
			'state_topic' => $stateTopic,
			'value_template' => '{{ value_json.state }}',
			'json_attributes_topic' => $stateTopic,
			'json_attributes_template' => '{{ value_json.attributes | tojson }}',
			'device' => $this->BuildDevice(),
			'origin' => $this->BuildOrigin()
		]);
	}

	/**
	 * One entity's discovery fields.
	 */
	private function BuildComponent(string $entity, array $definition): array
	{
		$stateTopic = $this->GetStateTopic($entity);

		$component = array_merge([
			'platform' => 'sensor',
			'unique_id' => $this->GetNodeId() . '_' . $entity,
			'object_id' => $this->GetNodeId() . '_' . $entity,
			'state_topic' => $stateTopic,
			'value_template' => '{{ value_json.state }}'
		], $definition);

		// The last_published sensor has no rows to carry, and an attributes topic that is
		// always an empty object is noise in the UI
		if ($entity !== StateSnapshotAssembler::ENTITY_LAST_PUBLISHED)
		{
			$component['json_attributes_topic'] = $stateTopic;
			$component['json_attributes_template'] = '{{ value_json.attributes | tojson }}';
		}

		return $component;
	}

	/**
	 * The device every entity belongs to. Identified by the node id so that renaming the
	 * device in Home Assistant does not detach the entities from it.
	 */
	private function BuildDevice(): array
	{
		return [
			'identifiers' => [$this->GetNodeId()],
			'name' => (string)VICTUAL_MQTT_DEVICE_NAME,
			'manufacturer' => 'Victual',
			'model' => 'Victual household inventory',
			'sw_version' => $this->GetVersion()
		];
	}

	/**
	 * Who published this, which Home Assistant shows in its MQTT diagnostics.
	 */
	private function BuildOrigin(): array
	{
		return [
			'name' => 'Victual',
			'sw_version' => $this->GetVersion()
		];
	}

	/**
	 * The application version, or an empty string when version.json cannot be read - a
	 * missing version must not stop a publish.
	 */
	private function GetVersion(): string
	{
		$versionFile = __DIR__ . '/../../version.json';

		if (!file_exists($versionFile))
		{
			return '';
		}

		$version = json_decode(file_get_contents($versionFile), true);

		return is_array($version) && isset($version['Version']) ? (string)$version['Version'] : '';
	}
}
