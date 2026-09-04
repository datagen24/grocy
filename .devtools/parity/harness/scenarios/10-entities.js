'use strict';

// Generic entity CRUD, over every entity that can be written through /objects/{entity}.
//
// This is the scenario that matters most and reads the least, because of what the
// architecture review called the deeper additive-API risk: **nearly every response here is
// a raw LessQL row or view serialised as-is, so the database schema is the wire
// contract.** Change a column's type in a migration and this endpoint's JSON changes with
// no code anywhere naming it. There is no tripwire between a migration and a client, which
// is what plan 14 piece 2 is meant to build and has not; until it does, comparing the same
// row against upstream is the nearest available thing.
//
// Each entity gets the full round trip — list, create, read back, filter, update, read
// again, delete, and confirm the delete — because each step reads a different code path:
// the list is the view, the read is the row, the filter goes through the dialects (which
// is where hazard 16 lived, `~` meaning case-insensitive on one engine and case-sensitive
// on the other), and the update is what a trigger fires on.

// Fixed values, never generated. A random name would still compare equal between the two
// instances, but it would make a failing report impossible to reproduce, and reproducing a
// failing report is the whole point of writing one down.
const ENTITIES = [
	{
		entity: 'locations',
		create: { name: 'Parity Pantry', description: 'created by the parity suite', is_freezer: 0 },
		update: { description: 'updated by the parity suite' },
		// `~` is the filter operator hazard 16 was about. Every entity that has a name gets
		// one, because the operator is resolved per dialect rather than per entity.
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'quantity_units',
		create: { name: 'Parity Unit', name_plural: 'Parity Units', description: 'suite unit' },
		update: { description: 'suite unit, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'product_groups',
		create: { name: 'Parity Group', description: 'suite group' },
		update: { description: 'suite group, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'shopping_locations',
		create: { name: 'Parity Store', description: 'suite store' },
		update: { description: 'suite store, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'task_categories',
		create: { name: 'Parity Task Category', description: 'suite category' },
		update: { description: 'suite category, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'shopping_lists',
		create: { name: 'Parity List', description: 'suite list' },
		update: { description: 'suite list, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'equipment',
		create: { name: 'Parity Equipment', description: 'suite equipment' },
		update: { description: 'suite equipment, updated' },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	},
	{
		entity: 'userentities',
		create: {
			name: 'parity_entity',
			caption: 'Parity Entity',
			description: 'suite user entity',
			show_in_sidebar_menu: 1
		},
		update: { description: 'suite user entity, updated' },
		filter: 'query%5B%5D=name%7Elike%7Eparity'
	},
	{
		entity: 'meal_plan_sections',
		create: { name: 'Parity Section', sort_number: 1 },
		update: { sort_number: 2 },
		filter: 'query%5B%5D=name%7Elike%7EParity'
	}
];

// Views and log tables. They cannot be written through this endpoint, so they get the read
// half only — which is still worth doing, because a view is exactly the thing that can
// return a different column type on a different engine without any code changing.
const READ_ONLY_ENTITIES = [
	'products_last_purchased',
	'products_average_price',
	'quantity_unit_conversions_resolved',
	'stock_current_locations',
	'uihelper_shopping_list',
	'permission_hierarchy',
	'stock_log',
	'chores_log'
];

module.exports = {
	name: 'entities',
	tags: ['entities'],

	async run(api) {
		for (const spec of ENTITIES) {
			const { entity } = spec;

			await api.get(`/objects/${entity}`, { label: `${entity}: list before` });

			const created = await api.post(`/objects/${entity}`, spec.create, {
				label: `${entity}: create`
			});

			// A create that did not create is not something to keep walking past: every
			// step after it would compare two 404s and report parity.
			const id = created.body && created.body.created_object_id;
			if (id === undefined || id === null) {
				await api.get(`/objects/${entity}`, { label: `${entity}: list after failed create` });
				continue;
			}

			await api.get(`/objects/${entity}/${id}`, { label: `${entity}: read back` });
			await api.get(`/objects/${entity}?${spec.filter}`, { label: `${entity}: filter by name` });
			await api.put(`/objects/${entity}/${id}`, spec.update, { label: `${entity}: update` });
			await api.get(`/objects/${entity}/${id}`, { label: `${entity}: read after update` });

			// Paging and ordering go through the same query builder the filter does.
			await api.get(`/objects/${entity}?order=name%3Aasc&limit=5&offset=0`, {
				label: `${entity}: ordered page`
			});

			await api.delete(`/objects/${entity}/${id}`, { label: `${entity}: delete` });
			await api.get(`/objects/${entity}/${id}`, { label: `${entity}: read after delete` });
		}

		for (const entity of READ_ONLY_ENTITIES) {
			await api.get(`/objects/${entity}`, { label: `${entity}: read-only list` });
		}

		// Three failure paths, because plan 11 is about the fork's error handling and the
		// only way to compare error handling is to cause errors. An unknown entity, a
		// missing id and a malformed one are the three an ecosystem client hits first.
		await api.get('/objects/not_an_entity', { label: 'unknown entity' });
		await api.get('/objects/locations/999999', { label: 'missing object id' });
		// Issue #48. A page sending "undefined" is how this was found, and on PostgreSQL
		// it used to be a 500 quoting the failing statement rather than any kind of
		// refusal. It is a deliberate 400 here and a 404 upstream; see
		// non-integer-object-id in accepted.js.
		await api.get('/objects/locations/undefined', { label: 'non-integer object id' });
		await api.post('/objects/locations', {}, { label: 'create with no fields' });
	}
};
