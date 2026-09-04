'use strict';

// Users and permissions, user fields, per-user settings, files, and the calendar feed.
//
// Permissions matter here more than the count of endpoints suggests. The fork resolves the
// thirty constants in controllers/Users/User.php through `user_permissions_resolved`, a
// recursive view over `permission_hierarchy` — and a recursive view is the single most
// likely thing in the schema to have been ported with different semantics. Plan 19 will
// build on it. Reading it back through the API on both engines is the cheapest evidence
// that the port preserved it.
//
// Files are here rather than in their own scenario because there are three endpoints and
// they only mean anything in sequence: put, get, delete. Note that the fork can store them
// in the database (plan 01, `FILE_STORAGE=database`) while upstream can only use the
// filesystem — this stack runs the default, `filesystem`, so the comparison is like for
// like. Proving the database backend is `.devtools/pgsql/files-import-tests.php`'s job.

const FILE_GROUP = 'productpictures';
const FILE_NAME = 'parity-fixture.txt';
const FILE_BODY = 'parity suite fixture file\n';

// base64 of the file name is how both projects address a file in these routes.
const FILE_NAME_B64 = Buffer.from(FILE_NAME).toString('base64');

// A 1×1 transparent PNG, inline rather than as a fixture file so that the scenario is one
// file and the bytes are visibly the same on both runs. This is the *valid* upload; the
// .txt above it is the rejected one.
const PNG_NAME = 'parity-fixture.png';
const PNG_NAME_B64 = Buffer.from(PNG_NAME).toString('base64');
const PNG_BYTES = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
	'base64'
);

module.exports = {
	name: 'users-files-calendar',
	tags: ['users', 'files', 'calendar', 'userfields'],

	async run(api) {
		// --- the current user ------------------------------------------------------------
		await api.get('/user', { label: 'user: current user' });
		await api.get('/users', { label: 'users: list' });

		// --- a second user ----------------------------------------------------------------
		await api.post('/users', {
			username: 'parity',
			first_name: 'Parity',
			last_name: 'Suite',
			password: 'parity-suite-password'
		}, { label: 'users: create' });

		const list = await api.get('/users', { label: 'users: list after create' });
		const created = Array.isArray(list.body)
			? list.body.find((u) => u && u.username === 'parity')
			: null;
		const userId = created && created.id;

		if (userId) {
			await api.put(`/users/${userId}`, {
				username: 'parity',
				first_name: 'Parity',
				last_name: 'Suite Updated'
			}, { label: 'users: update' });

			// The recursive permission view, read through the API.
			await api.get(`/users/${userId}/permissions`, { label: 'users: permissions after create' });

			// **Numeric permission ids, not names.** `SetPermissions` takes "an array of
			// permission ids" and writes them straight into user_permissions.permission_id;
			// a name lands there as NULL. The first run of this suite passed names, and the
			// two engines then disagreed about the same bad write in a way worth recording:
			// PostgreSQL refused it (23502, not-null violation) and SQLite accepted the
			// batch and answered 204. That is a harness bug rather than a fork defect — but
			// it is also the clearest example of why a suite that only drives *valid* input
			// finds less than one that gets something wrong.
			//
			// 5 and 6 are USERS_READ and USERS_EDIT_SELF, verified identical on both.
			await api.post(`/users/${userId}/permissions`, { permissions: [5] },
				{ label: 'users: grant one permission' });
			await api.get(`/users/${userId}/permissions`, { label: 'users: permissions after grant' });

			await api.put(`/users/${userId}/permissions`, { permissions: [5, 6] },
				{ label: 'users: replace permissions' });
			await api.get(`/users/${userId}/permissions`, { label: 'users: permissions after replace' });

			await api.get('/objects/permission_hierarchy?order=id%3Aasc',
				{ label: 'users: permission hierarchy view' });
		}

		// A duplicate username, which is a uniqueness violation — the failure path plan 11
		// is about and the one `.devtools/frontend/s29-payload.js` uses because PostgreSQL
		// quotes the offending value back into the message.
		await api.post('/users', { username: 'parity', password: 'x' },
			{ label: 'users: create a duplicate username' });

		// --- per-user settings --------------------------------------------------------------
		await api.get('/user/settings', { label: 'user settings: all' });
		await api.put('/user/settings/parity_suite_setting', { value: 'parity-value' },
			{ label: 'user settings: set' });
		await api.get('/user/settings/parity_suite_setting', { label: 'user settings: read back' });
		await api.get('/user/settings', { label: 'user settings: all after set' });
		await api.delete('/user/settings/parity_suite_setting', { label: 'user settings: delete' });
		await api.get('/user/settings/parity_suite_setting', { label: 'user settings: read after delete' });

		// --- user fields ---------------------------------------------------------------------
		// A userfield changes the shape of every response for its entity, which makes it the
		// one feature that can break the wire contract for an entity that was not touched.
		const field = await api.post('/objects/userfields', {
			entity: 'products',
			name: 'parity_field',
			caption: 'Parity Field',
			type: 'text-single-line',
			show_as_column_in_tables: 1
		}, { label: 'userfields: create field' });

		const location = await api.post('/objects/locations', { name: 'Userfield Location' },
			{ label: 'userfields: create location' });
		const qu = await api.post('/objects/quantity_units',
			{ name: 'Userfield Unit', name_plural: 'Userfield Units' }, { label: 'userfields: create unit' });

		const product = await api.post('/objects/products', {
			name: 'Userfield Product',
			location_id: location.body && location.body.created_object_id,
			qu_id_purchase: qu.body && qu.body.created_object_id,
			qu_id_stock: qu.body && qu.body.created_object_id
		}, { label: 'userfields: create product' });

		const productId = product.body && product.body.created_object_id;

		if (field.body && productId) {
			await api.get(`/userfields/products/${productId}`, { label: 'userfields: read, unset' });
			await api.put(`/userfields/products/${productId}`, { parity_field: 'parity value' },
				{ label: 'userfields: set' });
			await api.get(`/userfields/products/${productId}`, { label: 'userfields: read back' });

			// The product row itself, which now carries the field.
			await api.get(`/objects/products/${productId}`, { label: 'userfields: product row with field' });
		}

		await api.get('/userfields/products/999999', { label: 'userfields: read for a missing object' });

		// --- files ------------------------------------------------------------------------------
		// A real PNG, because productpictures is an image group and this fork checks that
		// the bytes match the extension. Both instances should store, serve and delete it.
		await api.put(`/files/${FILE_GROUP}/${PNG_NAME_B64}`, undefined, {
			label: 'files: upload a png',
			headers: { 'Content-Type': 'image/png' },
			rawBody: PNG_BYTES
		});
		await api.get(`/files/${FILE_GROUP}/${PNG_NAME_B64}`, { label: 'files: download the png' });
		await api.delete(`/files/${FILE_GROUP}/${PNG_NAME_B64}`, { label: 'files: delete the png' });
		await api.get(`/files/${FILE_GROUP}/${PNG_NAME_B64}`, { label: 'files: download after delete' });

		// And a .txt into the same image group, which this fork refuses and upstream
		// stores. Kept deliberately: it is the probe for FilesApiController's extension
		// check, whose comment says "A name ending in .png that holds a script is the whole
		// of the problem". The accepted-differences registry explains the 400-against-204;
		// a run where this starts succeeding on both is the regression.
		await api.put(`/files/${FILE_GROUP}/${FILE_NAME_B64}`, undefined, {
			label: 'files: upload a .txt into an image group',
			headers: { 'Content-Type': 'application/octet-stream' },
			rawBody: FILE_BODY
		});
		await api.get(`/files/${FILE_GROUP}/${FILE_NAME_B64}`, { label: 'files: download the .txt' });

		// --- calendar ----------------------------------------------------------------------------
		// The iCal feed is text/calendar, not JSON, so it comes back through the unparsable
		// branch and is compared as text. That is deliberate: its line order and property
		// set are exactly what a subscribing calendar app depends on.
		await api.get('/calendar/ical', { label: 'calendar: ical feed' });
		await api.get('/calendar/ical/sharing-link', { label: 'calendar: sharing link' });

		// --- deleting the user, last, because the steps above need it ----------------------------
		if (userId) {
			await api.delete(`/users/${userId}`, { label: 'users: delete' });
			await api.get('/users', { label: 'users: list after delete' });
		}
	}
};
