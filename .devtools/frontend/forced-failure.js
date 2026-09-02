// Plan 12 verification check 3: error surfacing, forced.
//
// Intercepts the routes three converted pages depend on and answers 500, then exercises
// a delete, a save and a list load and asserts that each produces the error toast with
// working click-through details. Finally it aborts a save mid-flight and asserts the form
// re-enables rather than staying locked - the failure mode step 2's onerror/timeout pair
// exists for, now reachable from a page that no longer passes its own console.error
// handler.
//
//   node forced-failure.js --url http://127.0.0.1:8500
//
// Exits non-zero if any assertion fails.

const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8500') || '').replace(/\/$/, '');

const results = [];

function record(name, ok, detail)
{
	results.push({ name, ok, detail });
	console.log((ok ? 'PASS  ' : 'FAIL  ') + name.padEnd(52) + (detail || ''));
}

/** The toast toastr renders, if any. */
async function toastText(page)
{
	return page.evaluate(() =>
	{
		const el = document.querySelector('#toast-container');
		// The first line of innerText is the close button's glyph, which says nothing.
		return el ? el.innerText.split('\n').filter(l => l.trim() && l.trim() !== '\u00d7').join(' ') : null;
	});
}

/** Clicks the toast and reads the bootbox "Error details" dialog it opens. */
async function toastDetails(page)
{
	await page.locator('#toast-container .toast, #toast-container > div').first().click();
	await page.waitForTimeout(600);

	return page.evaluate(() =>
	{
		const el = document.querySelector('.bootbox');
		return el ? el.innerText : null;
	});
}

(async () =>
{
	const browser = await chromium.launch({ args: ['--no-sandbox'] });
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

	// Establish the demo session and find something to delete.
	const seed = await context.newPage();
	await seed.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
	await seed.waitForTimeout(400);
	await seed.close();

	// ---------------------------------------------------------------- 1. delete, 500
	{
		const page = await context.newPage();
		await page.route('**/api/objects/locations/*', route =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced failure"}' }));

		await page.goto(BASE + '/locations', { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(700);
		await page.locator('.location-delete-button').first().click();
		await page.waitForTimeout(500);
		await page.locator('.bootbox .btn-success').first().click();
		await page.waitForTimeout(900);

		const toast = await toastText(page);
		record('locations delete 500 toasts', !!toast, toast ? toast.split('\n')[0] : 'no toast');

		if (toast)
		{
			const details = await toastDetails(page);
			record('locations delete 500 details open', !!details && details.indexOf('forced failure') !== -1, (details || '').replace(/\s+/g, ' ').slice(0, 60));
		}

		await page.close();
	}

	// ---------------------------------------------------------------- 2. save, 500
	{
		const page = await context.newPage();
		await page.route('**/api/objects/product_groups', route =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced failure"}' }));

		await page.goto(BASE + '/productgroup/new', { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(700);
		await page.fill('#name', 'forced-failure-probe');
		await page.locator('#save-product-group-button').click();
		await page.waitForTimeout(900);

		const toast = await toastText(page);
		record('productgroupform save 500 toasts', !!toast, toast ? toast.split('\n')[0] : 'no toast');

		const reenabled = await page.evaluate(() => !document.querySelector('#name').disabled);
		record('productgroupform save 500 re-enables form', reenabled, reenabled ? '#name enabled' : '#name still disabled');

		await page.close();
	}

	// ---------------------------------------------------------------- 3. list load, 500
	{
		const page = await context.newPage();
		await page.route('**/api/tasks', route =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced failure"}' }));

		await page.goto(BASE + '/tasks', { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1200);

		// The tasks list's own GET /api/tasks is RefreshStatistics, a deliberate Q2
		// survivor, so this one must NOT toast. What must toast is a user-initiated
		// action - completing a task - on the same failing route.
		const silent = await toastText(page);
		record('tasks background statistics stays silent', silent === null, silent ? 'toasted: ' + silent.split('\n')[0] : 'no toast, as intended');

		await page.route('**/api/tasks/*/complete', route =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced failure"}' }));

		const hasTask = await page.locator('.do-task-button').count();
		if (hasTask)
		{
			await page.locator('.do-task-button').first().click();
			await page.waitForTimeout(900);
			const toast = await toastText(page);
			record('tasks complete 500 toasts', !!toast, toast ? toast.split('\n')[0] : 'no toast');
		}
		else
		{
			record('tasks complete 500 toasts', false, 'no open task to complete');
		}

		await page.close();
	}

	// ---------------------------------------------------------------- 4. list load 500 on a card
	{
		const page = await context.newPage();
		await page.route('**/api/objects/equipment/*', route =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced failure"}' }));

		await page.goto(BASE + '/equipment', { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1500);

		const toast = await toastText(page);
		record('equipment detail load 500 toasts', !!toast, toast ? toast.split('\n')[0] : 'no toast');

		await page.close();
	}

	// ---------------------------------------------------------------- 5. abort mid-save
	{
		const page = await context.newPage();
		await page.route('**/api/objects/locations', route => route.abort('connectionreset'));

		await page.goto(BASE + '/location/new', { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(700);
		await page.fill('#name', 'forced-abort-probe');
		await page.locator('#save-location-button').click();
		await page.waitForTimeout(1200);

		const reenabled = await page.evaluate(() => !document.querySelector('#name').disabled);
		record('locationform aborted save re-enables form', reenabled, reenabled ? '#name enabled' : '#name still disabled');

		const toast = await toastText(page);
		record('locationform aborted save toasts', !!toast, toast ? toast.split('\n')[0] : 'no toast');

		await page.close();
	}

	await browser.close();

	const failed = results.filter(r => !r.ok);
	console.log('\n' + (results.length - failed.length) + '/' + results.length + ' checks passed');
	process.exit(failed.length ? 1 : 0);
})();
