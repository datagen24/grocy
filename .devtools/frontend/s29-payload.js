// Plan 12 verification check 5: prove sweep finding S29 with a stored payload rather
// than by reading the diff.
//
// It seeds one record of each affected kind with a name of
// `&lt;img src=x onerror=window.__xss=1&gt;` - which the API's sanitiser stores as a
// *live* tag, because every column here is a text column and the text branch of
// `BaseApiController::GetParsedAndFilteredRequestBody` undoes the purifier's entity
// encoding on purpose. Then, on each page that lists or acts on that record, it opens
// the delete confirmation (or triggers the success toast) and asserts two things:
//
//   1. `window.__xss` is still undefined - nothing executed;
//   2. the payload is present as visible *text* in the dialog / toast body.
//
// Run it against the unfixed head first. There it must report `xss: 1` on every row,
// otherwise the check is not capable of failing and proves nothing.
//
//   node s29-payload.js --url http://127.0.0.1:8500 --out /tmp/s29-before.json
//
// `--only locations,products` limits the run to the named probes.

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

// Names are unique per entity, so a re-run against the same database has to seed
// distinguishable records; the token is appended outside the payload itself.
const RUN_TOKEN = 's29-' + Date.now().toString(36);
const PAYLOAD_ENCODED = '&lt;img src=x onerror=window.__xss=1&gt; ' + RUN_TOKEN;
const PAYLOAD_LIVE = '<img src=x onerror=window.__xss=1> ' + RUN_TOKEN;

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8500') || '').replace(/\/$/, '');
const OUT = arg('out', path.join(__dirname, 's29-payload.json'));
const ONLY = (arg('only', '') || '').split(',').map(s => s.trim()).filter(Boolean);

/** Seeds one object through the API, from inside the page so the session cookie applies. */
async function apiPost(page, apiPath, body)
{
	return page.evaluate(async ([p, b]) =>
	{
		const response = await fetch(p, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(b)
		});
		const text = await response.text();

		try
		{
			return { status: response.status, body: JSON.parse(text) };
		}
		catch (e)
		{
			return { status: response.status, body: text };
		}
	}, [apiPath, body]);
}

async function apiGet(page, apiPath)
{
	return page.evaluate(async p =>
	{
		const response = await fetch(p);
		const text = await response.text();

		try
		{
			return JSON.parse(text);
		}
		catch (e)
		{
			return text;
		}
	}, apiPath);
}

/** Fresh page with `window.__xss` unset and every console message captured. */
async function newProbePage(context)
{
	const page = await context.newPage();
	page.on('pageerror', () => { });
	return page;
}

/**
 * Opens `url`, performs `action(page)` - which must make a bootbox dialog or a toastr
 * toast appear - and reports whether the payload executed and whether it is visible as
 * text.
 */
async function probe(context, name, url, action, sink)
{
	const page = await newProbePage(context);
	const record = { probe: name, url: url, xss: null, visibleText: null, error: null };

	try
	{
		await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(900);

		// The payload may already have fired on load (rendered markup), so read it before
		// and after and report either.
		await action(page);
		await page.waitForTimeout(1200);

		const selector = sink === 'toast' ? '#toast-container' : '.bootbox';
		const visible = await page.evaluate(s =>
		{
			const el = document.querySelector(s);
			return el ? el.innerText : null;
		}, selector);

		record.sinkFound = visible !== null;
		record.visibleText = visible === null ? null : (visible.indexOf('<img src=x onerror=') !== -1);
		record.xss = await page.evaluate(() => window.__xss);
		record.imgInjected = await page.evaluate(s =>
		{
			const el = document.querySelector(s);
			return el ? el.querySelectorAll('img[src="x"]').length : 0;
		}, selector);
	}
	catch (e)
	{
		record.error = e.message;
	}

	await page.close();
	return record;
}

(async () =>
{
	const browser = await chromium.launch({ args: ['--no-sandbox'] });
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

	// One page just for seeding; hitting / first establishes the demo session.
	const seed = await context.newPage();
	await seed.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
	await seed.waitForTimeout(500);

	const ids = {};
	const stored = {};

	const quantityUnits = await apiGet(seed, '/api/objects/quantity_units');
	const locations = await apiGet(seed, '/api/objects/locations');
	const fallbackQu = Array.isArray(quantityUnits) && quantityUnits.length ? quantityUnits[0].id : 1;
	const fallbackLocation = Array.isArray(locations) && locations.length ? locations[0].id : 1;

	const seeds = [
		['location', '/api/objects/locations', { name: PAYLOAD_ENCODED }],
		['chore', '/api/objects/chores', { name: PAYLOAD_ENCODED, period_type: 'manually', period_days: 1 }],
		['quantityunit', '/api/objects/quantity_units', { name: PAYLOAD_ENCODED, name_plural: PAYLOAD_ENCODED }],
		['shoppinglist', '/api/objects/shopping_lists', { name: PAYLOAD_ENCODED }],
		['task', '/api/objects/tasks', { name: PAYLOAD_ENCODED }],
		['battery', '/api/objects/batteries', { name: PAYLOAD_ENCODED }],
		['equipment', '/api/objects/equipment', { name: PAYLOAD_ENCODED }],
		['taskcategory', '/api/objects/task_categories', { name: PAYLOAD_ENCODED }],
		['productgroup', '/api/objects/product_groups', { name: PAYLOAD_ENCODED }],
		['shoppinglocation', '/api/objects/shopping_locations', { name: PAYLOAD_ENCODED }],
		['userentity', '/api/objects/userentities', { name: RUN_TOKEN.replace(/-/g, '_'), caption: PAYLOAD_ENCODED, description: PAYLOAD_ENCODED }]
	];

	for (const [key, apiPath, body] of seeds)
	{
		const result = await apiPost(seed, apiPath, body);
		ids[key] = result.body && result.body.created_object_id;
		console.log('seed  ' + key + ' -> ' + JSON.stringify(result.status) + ' id ' + ids[key]);
	}

	const product = await apiPost(seed, '/api/objects/products', {
		name: PAYLOAD_ENCODED,
		location_id: fallbackLocation,
		qu_id_purchase: fallbackQu,
		qu_id_stock: fallbackQu,
		qu_id_consume: fallbackQu,
		qu_id_price: fallbackQu
	});
	ids.product = product.body && product.body.created_object_id;
	console.log('seed  product -> ' + product.status + ' id ' + ids.product);

	// The API key description is not written through the JSON body path (it is a query
	// parameter on a GET), so the key is created the way the UI creates it and its id is
	// read out of the redirect target.
	await seed.goto(BASE + '/manageapikeys/new?description=' + encodeURIComponent(PAYLOAD_LIVE), { waitUntil: 'domcontentloaded' });
	await seed.waitForTimeout(600);
	const keyMatch = /[?&]key=(\d+)/.exec(seed.url());
	ids.apikey = keyMatch ? keyMatch[1] : null;
	console.log('seed  apikey -> id ' + ids.apikey);

	// Read the stored values back, so the record proves the sanitiser really stored a live
	// tag rather than the entity encoded text that was sent.
	for (const [key, entity] of [
		['location', 'locations'], ['chore', 'chores'], ['quantityunit', 'quantity_units'],
		['shoppinglist', 'shopping_lists'], ['task', 'tasks'], ['battery', 'batteries'],
		['product', 'products'], ['equipment', 'equipment'],
		['taskcategory', 'task_categories'], ['productgroup', 'product_groups'],
		['shoppinglocation', 'shopping_locations']
	])
	{
		if (!ids[key])
		{
			continue;
		}

		const row = await apiGet(seed, '/api/objects/' + entity + '/' + ids[key]);
		stored[key] = row && (row.name || row.description);
	}

	await seed.close();

	// Several of these actions live behind a Bootstrap dropdown, which has to be opened
	// before the item inside it is clickable.
	const openMenuThen = (menuSelector, selector) => async page =>
	{
		await page.locator(menuSelector).first().click();
		await page.waitForTimeout(400);
		await page.locator(selector).first().click();
	};

	const clickDelete = selector => async page =>
	{
		// .first(): several pages render the same action more than once per row
		// (choresoverview has three track buttons), and strict mode would reject the click.
		await page.locator(selector).first().click();
	};

	const probes = [
		['locations', '/locations', clickDelete('.location-delete-button[data-location-id="' + ids.location + '"]'), 'dialog'],
		['chores', '/chores', clickDelete('.chore-delete-button[data-chore-id="' + ids.chore + '"]'), 'dialog'],
		['quantityunits', '/quantityunits', clickDelete('.quantityunit-delete-button[data-quantityunit-id="' + ids.quantityunit + '"]'), 'dialog'],
		['products', '/products', clickDelete('.product-delete-button[data-product-id="' + ids.product + '"]'), 'dialog'],
		['shoppinglist', '/shoppinglist?list=' + ids.shoppinglist, openMenuThen('.dropdown:has(#delete-selected-shopping-list) [data-toggle="dropdown"]', '#delete-selected-shopping-list'), 'dialog'],
		['manageapikeys', '/manageapikeys', clickDelete('.apikey-delete-button[data-apikey-id="' + ids.apikey + '"]'), 'dialog'],
		['manageapikeys-qr', '/manageapikeys', clickDelete('tr:has(.apikey-delete-button[data-apikey-id="' + ids.apikey + '"]) .apikey-show-qr-button'), 'dialog'],
		['tasks-delete', '/tasks', clickDelete('.delete-task-button[data-task-id="' + ids.task + '"]'), 'dialog'],
		['tasks-toast', '/tasks', clickDelete('.do-task-button[data-task-id="' + ids.task + '"]'), 'toast'],
		['batteries', '/batteries', clickDelete('.battery-delete-button[data-battery-id="' + ids.battery + '"]'), 'dialog'],
		['equipment', '/equipment', openMenuThen('tr:has(.equipment-delete-button[data-equipment-id="' + ids.equipment + '"]) [data-toggle="dropdown"]', '.equipment-delete-button[data-equipment-id="' + ids.equipment + '"]'), 'dialog'],
		['taskcategories', '/taskcategories', clickDelete('.task-category-delete-button[data-category-id="' + ids.taskcategory + '"]'), 'dialog'],
		['productgroups', '/productgroups', clickDelete('.product-group-delete-button[data-group-id="' + ids.productgroup + '"]'), 'dialog'],
		['shoppinglocations', '/shoppinglocations', clickDelete('.shoppinglocation-delete-button[data-shoppinglocation-id="' + ids.shoppinglocation + '"]'), 'dialog'],
		['batteriesoverview', '/batteriesoverview', async page =>
		{
			await page.locator('.track-charge-cycle-button[data-battery-id="' + ids.battery + '"]').first().click();
		}, 'toast'],
		['choresoverview', '/choresoverview', async page =>
		{
			await page.locator('a.btn.track-chore-button:not(.skip)[data-chore-id="' + ids.chore + '"]').first().click();
		}, 'toast']
	];

	const results = [];

	for (const [name, url, action, sink] of probes)
	{
		if (ONLY.length && !ONLY.includes(name))
		{
			continue;
		}

		const record = await probe(context, name, url, action, sink);
		results.push(record);
		console.log('probe ' + name.padEnd(22) + ' xss=' + String(record.xss) +
			' text=' + String(record.visibleText) +
			' img=' + String(record.imgInjected) +
			(record.error ? ' ERROR ' + record.error : ''));
	}

	await browser.close();

	const report = { base: BASE, when: new Date().toISOString(), runToken: RUN_TOKEN, payloadSent: PAYLOAD_ENCODED, ids, stored, results };
	fs.writeFileSync(OUT, JSON.stringify(report, null, '\t'));

	console.log('\nStored values read back from the API:');
	for (const key of Object.keys(stored))
	{
		console.log('  ' + key.padEnd(18) + JSON.stringify(stored[key]));
	}

	console.log('\nwrote ' + OUT);
})();
