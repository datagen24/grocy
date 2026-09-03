// Plan 12 verification check 6, and step 5b's acceptance test.
//
// Two datetimepickers on one page must set, clear and validate independently - that is the
// only reason a second, copy-pasted component ever existed. This probe drives both pickers
// on every page that has them and, after each action on one, reads the *other* one's value
// and validity back. A picker is addressed by the id its Blade include was given, and the
// component API by whichever name the tree uses, so the same run works against a tree that
// still carries the copy and against the merged one.
//
//   node two-pickers.js --url http://127.0.0.1:8200
//
// The three "shortcuts" it exercises are the ones the app actually offers:
//
//   "now"       the widget's "Go to today" button (data-action="today")
//   "clear"     the component's Clear() API - what inventory.js, purchase.js and
//               mealplan.js call. Note that tempusdominus' own trash-can button is never
//               rendered: the component's `buttons` config enables showToday and showClose
//               only, so there is no data-action="clear" in the widget on any page.
//   shorthand   the typed "x" (never overdue) and the "Never overdue" shortcut checkbox
//
// Exits non-zero if any action on one picker moved the other.

const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8200')).replace(/\/$/, '');

const rows = [];
let failures = 0;

function input(id) { return '#' + id + ' input.form-control'; }
function toggle(id) { return '#' + id + ' .input-group-append'; }

async function read(page, id)
{
	return page.evaluate(sel =>
	{
		const el = document.querySelector(sel);
		if (!el) return { value: '(absent)', valid: null };
		return { value: el.value, valid: el.checkValidity() };
	}, input(id));
}

async function snapshot(page, ids)
{
	const out = {};
	for (const id of ids) out[id] = await read(page, id);
	return out;
}

function fmt(s)
{
	if (s.value === '(absent)') return '(absent)';
	return (s.value === '' ? '(empty)' : s.value) + (s.valid === null ? '' : s.valid ? ' / valid' : ' / invalid');
}

/** Runs one action, then records what both pickers looked like before and after it. */
async function step(page, ids, target, label, action)
{
	const before = await snapshot(page, ids);
	try
	{
		await action();
	}
	catch (e)
	{
		rows.push({
			ok: false, page: page.__label, picker: '-', role: 'action failed',
			label, before: '-', after: e.message.split('\n')[0]
		});
		failures++;
		return;
	}
	await page.waitForTimeout(700);
	const after = await snapshot(page, ids);

	for (const id of ids)
	{
		const moved = before[id].value !== after[id].value || before[id].valid !== after[id].valid;
		const shouldMove = id === target;
		const ok = shouldMove || !moved;
		if (!ok) failures++;
		rows.push({
			ok, page: page.__label, picker: id,
			role: shouldMove ? 'acted on' : 'the other one',
			label, before: fmt(before[id]), after: fmt(after[id])
		});
	}
}

async function typeInto(page, id, value)
{
	await page.fill(input(id), '');
	await page.type(input(id), value, { delay: 25 });
	await page.press(input(id), 'End');
}

/** Opens one picker's widget and clicks a toolbar action, then closes the widget. */
async function widgetAction(page, id, action)
{
	await page.click(toggle(id));
	await page.waitForSelector('.bootstrap-datetimepicker-widget a[data-action="' + action + '"]', { timeout: 8000 });
	await page.click('.bootstrap-datetimepicker-widget a[data-action="' + action + '"]');
	await page.keyboard.press('Escape');
}

/** Calls Clear() on the named instance, under whichever name this tree registers it. */
async function clearApi(page, which)
{
	await page.evaluate(w =>
	{
		const api = w === 'primary'
			? Victual.Components.DateTimePicker
			: (Victual.Components.SecondaryDateTimePicker || Victual.Components.DateTimePicker2);
		api.Clear();
	}, which);
}

/** The matrix, for a page where both pickers are visible at once. */
async function bothVisible(browser, label, url, primary, secondary)
{
	const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
	page.__label = label;
	page.on('pageerror', e => console.log('   pageerror on ' + label + ': ' + e.message));
	await page.goto(BASE + url, { waitUntil: 'networkidle' });
	await page.waitForTimeout(1000);

	const ids = [primary, secondary];
	const present = await snapshot(page, ids);
	for (const id of ids)
	{
		if (present[id].value === '(absent)')
		{
			console.log('SKIP %s: picker #%s is not rendered on the page', label, id);
			await page.close();
			return;
		}
	}
	console.log('%s: #%s = %s, #%s = %s (as loaded)',
		label, primary, fmt(present[primary]), secondary, fmt(present[secondary]));

	await step(page, ids, primary, 'type 2027-03-04 into the primary',
		() => typeInto(page, primary, '2027-03-04'));
	await step(page, ids, secondary, 'type 2028-06-07 into the secondary',
		() => typeInto(page, secondary, '2028-06-07'));
	await step(page, ids, secondary, 'secondary: "now" (widget Go-to-today)',
		() => widgetAction(page, secondary, 'today'));
	await step(page, ids, secondary, 'secondary: "clear" (component Clear())',
		() => clearApi(page, 'secondary'));
	await step(page, ids, primary, 'primary: "now" (widget Go-to-today)',
		() => widgetAction(page, primary, 'today'));
	await step(page, ids, primary, 'primary: "clear" (component Clear())',
		() => clearApi(page, 'primary'));
	await step(page, ids, primary, 'primary: typed "x" shorthand (never overdue)',
		() => typeInto(page, primary, 'x'));

	const shortcuts = await page.locator('input[id$="-shortcut"]').count();
	if (shortcuts > 0)
	{
		await step(page, ids, primary, 'primary: "Never overdue" shortcut checkbox on',
			() => page.evaluate(() => document.querySelector('input[id$="-shortcut"]').click()));
		await step(page, ids, primary, 'primary: "Never overdue" shortcut checkbox off',
			() => page.evaluate(() => document.querySelector('input[id$="-shortcut"]').click()));
	}

	await page.close();
}

/** The meal plan, whose two pickers live in two different modal dialogs. */
async function mealplan(browser)
{
	const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
	page.__label = 'mealplan';
	page.on('pageerror', e => console.log('   pageerror on mealplan: ' + e.message));
	await page.goto(BASE + '/mealplan', { waitUntil: 'networkidle' });
	await page.waitForTimeout(1800);

	const ids = ['day', 'copy_to_date'];

	// mealplan.js moves the primary picker's wrapper into whichever add-dialog opens, so
	// go through that path rather than showing the modal cold.
	await page.evaluate(() =>
	{
		$(".datetimepicker-wrapper").detach().prependTo("#add-recipe-form");
		$('#add-recipe-modal').modal('show');
	});
	await page.waitForTimeout(1000);
	await step(page, ids, 'day', 'primary: type 2027-03-04 (add-recipe dialog)',
		() => typeInto(page, 'day', '2027-03-04'));
	await step(page, ids, 'day', 'primary: "now" (widget Go-to-today)',
		() => widgetAction(page, 'day', 'today'));
	await page.evaluate(() => $('#add-recipe-modal').modal('hide'));
	await page.waitForTimeout(800);

	await page.evaluate(() => $('#copy-day-modal').modal('show'));
	await page.waitForTimeout(1000);
	await step(page, ids, 'copy_to_date', 'secondary: type 2028-06-07 (copy-day dialog)',
		() => typeInto(page, 'copy_to_date', '2028-06-07'));
	await step(page, ids, 'copy_to_date', 'secondary: "now" (widget Go-to-today)',
		() => widgetAction(page, 'copy_to_date', 'today'));
	await step(page, ids, 'copy_to_date', 'secondary: "clear" (component Clear(), what CopyDay does)',
		() => clearApi(page, 'secondary'));
	await step(page, ids, 'day', 'primary: "clear" (component Clear())',
		() => clearApi(page, 'primary'));

	await page.close();
}

(async () =>
{
	const browser = await chromium.launch({
		executablePath: process.env.CHROME_BIN || undefined,
		args: ['--no-sandbox']
	});

	// The purchased-date picker on purchase/inventory is behind a user setting.
	const setup = await browser.newPage();
	await setup.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
	await setup.evaluate(async base =>
	{
		await fetch(base + '/api/user/settings/show_purchased_date_on_purchase', {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ value: true })
		});
	}, BASE);
	await setup.close();

	const entryId = await (async () =>
	{
		const p = await browser.newPage();
		await p.goto(BASE + '/stockentries', { waitUntil: 'networkidle' });
		const href = await p.locator('a[href*="/stockentry/"]').first().getAttribute('href');
		await p.close();
		return href.match(/\/stockentry\/(\d+)/)[1];
	})();

	await bothVisible(browser, 'stockentryform', '/stockentry/' + entryId, 'best_before_date', 'purchase_date');
	await bothVisible(browser, 'purchase', '/purchase', 'best_before_date', 'purchased_date');
	await bothVisible(browser, 'inventory', '/inventory', 'best_before_date', 'purchased_date');
	await mealplan(browser);

	await browser.close();

	console.log('');
	console.log('     %s %s %s %s %s',
		'page'.padEnd(15), 'picker'.padEnd(18), 'role'.padEnd(14),
		'before'.padEnd(24), 'after'.padEnd(24) + ' action');
	for (const r of rows)
	{
		console.log('%s %s %s %s %s %s %s',
			r.ok ? 'PASS' : 'FAIL',
			r.page.padEnd(15), r.picker.padEnd(18), r.role.padEnd(14),
			r.before.padEnd(24), r.after.padEnd(24), r.label);
	}
	console.log('\n%d/%d observations correct (a picker changed only when it was the one acted on)',
		rows.length - failures, rows.length);
	process.exit(failures === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
