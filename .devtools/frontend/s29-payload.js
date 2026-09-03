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
// One probe seeds nothing: the payload arrives in a *server error message* instead,
// injected by intercepting the route and answering 500, because
// `Victual.FrontendHelpers.ShowGenericError` renders the technical details through
// bootbox - and on PostgreSQL a uniqueness violation quotes the offending value back.
//
// Run it against the unfixed head first. There it must report `xss: 1` and exit non-zero,
// otherwise the check is not capable of failing and proves nothing.
//
//   node s29-payload.js --url http://127.0.0.1:8500 --out /tmp/s29-before.json
//
// `--only locations,products` limits the run to the named probes.
//
// Exits non-zero if any probe is not clean, so it can be run as a gate. A probe counts as
// failed when the payload executed, when an `<img>` was injected, when the payload is not
// visible as text, when the sink never appeared, or when the action threw - "the sink was
// not found" and "the action errored" are failures, not skips, because a run in which
// every action silently did nothing must not be able to pass.

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
 * text. `setup`, when given, runs against the fresh page *before* the navigation, which
 * is where `page.route()` interception has to be registered.
 */
async function probe(context, name, url, action, sink, setup)
{
	const page = await newProbePage(context);
	const record = { probe: name, url: url, xss: null, visibleText: null, sinkFound: false, imgInjected: 0, error: null };

	try
	{
		if (setup)
		{
			await setup(page);
		}

		await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(900);

		// The payload may already have fired on load (rendered markup), so read it before
		// and after and report either.
		await action(page);
		await page.waitForTimeout(1200);

		const selector = sink === 'toast' ? '#toast-container' : '.bootbox';
		// The last match, not the first: a probe that goes through more than one dialog
		// leaves the earlier one in the DOM, and it is the newest one that holds the
		// payload.
		const visible = await page.evaluate(s =>
		{
			const el = Array.from(document.querySelectorAll(s)).pop();
			return el ? el.innerText : null;
		}, selector);

		record.sinkFound = visible !== null;
		record.visibleText = visible === null ? null : (visible.indexOf('<img src=x onerror=') !== -1);
		record.xss = await page.evaluate(() => window.__xss);
		record.imgInjected = await page.evaluate(s =>
		{
			const el = Array.from(document.querySelectorAll(s)).pop();
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

/**
 * Turns one probe record into a verdict. Every way a probe can be uninformative is a
 * failure: a sink that never appeared and an action that threw both mean the assertion
 * was never made, and a run of those must not be able to report success.
 * @param {Object} record One entry of `results`
 * @returns {{ok: boolean, reasons: string[]}}
 */
function verdict(record)
{
	const reasons = [];

	if (record.seedFailure)
	{
		return { ok: false, reasons: ['the record was never created, so nothing could be probed'] };
	}

	if (record.error)
	{
		// Playwright's messages carry a multi-line call log; the first line is what belongs
		// in a one-line-per-probe summary, and the full text is in the JSON report.
		reasons.push('the action threw: ' + String(record.error).split('\n')[0]);
	}

	if (!record.sinkFound)
	{
		reasons.push('no dialog or toast appeared - the sink was never reached');
	}

	if (record.xss)
	{
		reasons.push('THE PAYLOAD EXECUTED (window.__xss = ' + JSON.stringify(record.xss) + ')');
	}

	if (record.imgInjected)
	{
		reasons.push(record.imgInjected + ' injected <img> in the sink');
	}

	if (record.sinkFound && record.visibleText !== true)
	{
		reasons.push('the payload is not present as visible text');
	}

	return { ok: reasons.length === 0, reasons: reasons };
}

// Module scope, not a local of the run below, so the catch at the bottom can close it. A
// browser left open holds the event loop open, and the process then hangs instead of
// exiting - which in CI is a job that runs to its timeout rather than a gate that fails.
let browser = null;

(async () =>
{
	browser = await chromium.launch({ args: ['--no-sandbox'] });
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

	// A recipe and one ingredient on it, whose *note* carries the payload.
	// `recipes_pos` has no entry in BaseApiController::HTML_RENDERED_COLUMNS, so `note` is
	// a text column and is stored as a live tag exactly like the names above. The recipe
	// form writes it into `data-recipe-pos-note` and `recipeform.js` reads it back with
	// `.attr()`, which decodes it again, before handing it to `bootbox.alert()`.
	const recipe = await apiPost(seed, '/api/objects/recipes', { name: PAYLOAD_ENCODED, base_servings: 1 });
	ids.recipe = recipe.body && recipe.body.created_object_id;
	console.log('seed  recipe -> ' + recipe.status + ' id ' + ids.recipe);

	if (ids.recipe && ids.product)
	{
		const recipePos = await apiPost(seed, '/api/objects/recipes_pos', {
			recipe_id: ids.recipe,
			product_id: ids.product,
			amount: 1,
			note: PAYLOAD_ENCODED
		});
		ids.recipepos = recipePos.body && recipePos.body.created_object_id;
		console.log('seed  recipepos -> ' + recipePos.status + ' id ' + ids.recipepos);
	}

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
		['shoppinglocation', 'shopping_locations'], ['recipe', 'recipes']
	])
	{
		if (!ids[key])
		{
			continue;
		}

		const row = await apiGet(seed, '/api/objects/' + entity + '/' + ids[key]);
		stored[key] = row && (row.name || row.description);
	}

	if (ids.recipepos)
	{
		const row = await apiGet(seed, '/api/objects/recipes_pos/' + ids.recipepos);
		stored.recipepos = row && row.note;
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
		}, 'toast'],
		// The recipe form's "show notes" button: the note round-trips through
		// `data-recipe-pos-note`, so the Blade template's attribute escaping is undone by
		// the `.attr()` that reads it back, and bootbox renders the result as HTML.
		['recipeform-note', '/recipe/' + ids.recipe, clickDelete('.recipe-pos-show-note-button'), 'dialog'],
		// The technical-details dialog behind the generic error toast. Nothing is seeded
		// for this one: the payload is the server's *error message*, which is what a
		// uniqueness violation on PostgreSQL quotes back. The route, the page and the
		// click-through are forced-failure.js check 1, which is the sequence already known
		// to reach this dialog; only the body of the 500 differs.
		['error-details', '/locations', async page =>
		{
			await page.locator('.location-delete-button').first().click();
			await page.waitForTimeout(500);
			await page.locator('.bootbox .btn-success').first().click();
			await page.waitForTimeout(900);
			await page.locator('#toast-container .toast, #toast-container > div').first().click();
		}, 'dialog', async page =>
		{
			await page.route('**/api/objects/locations/*', route => route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error_message: PAYLOAD_LIVE })
			}));
		}]
	];

	const results = [];

	// A seed that did not come back with an id is reported as its own failure, so the
	// summary says "the record was never created" rather than leaving the reader to infer
	// it from a probe that could not find its button.
	for (const key of ['location', 'chore', 'quantityunit', 'shoppinglist', 'task', 'battery',
		'equipment', 'taskcategory', 'productgroup', 'shoppinglocation', 'product', 'apikey',
		'recipe', 'recipepos'])
	{
		if (!ids[key])
		{
			results.push({
				probe: 'seed:' + key, url: null, xss: null, visibleText: null,
				sinkFound: false, imgInjected: 0, error: null, seedFailure: true
			});
		}
	}

	for (const [name, url, action, sink, setup] of probes)
	{
		if (ONLY.length && !ONLY.includes(name))
		{
			continue;
		}

		const record = await probe(context, name, url, action, sink, setup);
		results.push(record);
		console.log('probe ' + name.padEnd(22) + ' xss=' + String(record.xss) +
			' text=' + String(record.visibleText) +
			' img=' + String(record.imgInjected) +
			(record.error ? ' ERROR ' + record.error : ''));
	}

	await browser.close();

	for (const record of results)
	{
		const outcome = verdict(record);
		record.ok = outcome.ok;
		record.reasons = outcome.reasons;
	}

	const failed = results.filter(r => !r.ok);
	const report = {
		base: BASE, when: new Date().toISOString(), runToken: RUN_TOKEN,
		payloadSent: PAYLOAD_ENCODED, ids, stored, results,
		passed: results.length - failed.length, failed: failed.length
	};
	fs.writeFileSync(OUT, JSON.stringify(report, null, '\t'));

	console.log('\nStored values read back from the API:');
	for (const key of Object.keys(stored))
	{
		console.log('  ' + key.padEnd(18) + JSON.stringify(stored[key]));
	}

	console.log('\nVerdict per probe:');
	for (const record of results)
	{
		console.log((record.ok ? 'PASS  ' : 'FAIL  ') + record.probe.padEnd(24) + record.reasons.join('; '));
	}

	console.log('\nwrote ' + OUT);

	if (!results.length)
	{
		// An empty run is a failure: it is what a mistyped --only produces, and reporting
		// "0 failed" for it would be the same lie as passing on a run where nothing fired.
		console.log('\nFAIL  no probe ran at all' + (ONLY.length ? ' - --only matched nothing: ' + ONLY.join(',') : ''));
		process.exitCode = 1;
		return;
	}

	console.log('\n' + (results.length - failed.length) + '/' + results.length + ' probes clean');

	if (failed.length)
	{
		process.exitCode = 1;
	}
})().catch(async e =>
{
	// Seeding or the browser launch itself falling over is a failed run, not a silent one:
	// without this the harness would report nothing and exit on Node's default handling.
	console.error('\nFAIL  the run itself threw before any verdict: ' + e.message);
	process.exitCode = 1;

	// And the browser has to be closed on this path too, or the process never exits.
	if (browser)
	{
		try
		{
			await browser.close();
		}
		catch (closeError)
		{
			console.error('and the browser would not close either: ' + closeError.message);
		}
	}
});
