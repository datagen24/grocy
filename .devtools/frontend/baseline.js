// Plan 12, verification check 1: record what the list and form pages actually do, before
// the shared core and the entity factories change how they are built.
//
// For every master data list this walks the real user path - open the list, add a record
// through the page's own Add button, edit it on its form page, delete it from the list -
// and writes down what it observed: whether the page loaded clean, how many rows the table
// had at each step, whether saving inside an embedded dialog reloads the parent list,
// whether *dismissing* that dialog also reloads it, whether the delete removes the row in
// place or navigates, and every console error along the way.
//
// The dialog-dismiss probe is the point of the exercise. `Reload` and `CloseLastModal` both
// end up reloading a parent list today, so saving cannot tell them apart; pressing Escape
// can, because only a list that reloads on `CloseLastModal` reloads when nothing was saved.
// That is the single genuine drift marker the plan's Q3 names, and it is the thing a
// factory conversion is most likely to flatten by accident.
//
// This is a harness, not a test framework: it asserts nothing and fails nothing. It records
// a JSON document plus a readable Markdown summary, and the acceptance criterion for the
// conversion work is that a later run says the same things.
//
// Usage:  PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node baseline.js --url http://127.0.0.1:8200 --out baseline.json

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { LISTS, LOAD_ONLY_LISTS, FORMS } = require('./pages');

const args = process.argv.slice(2);
const argOf = (name, fallback) =>
{
	const i = args.indexOf('--' + name);
	return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};

const BASE = (argOf('url', process.env.VICTUAL_URL || 'http://127.0.0.1:8200')).replace(/\/$/, '');
const OUT = path.resolve(argOf('out', 'baseline.json'));
const ONLY = argOf('only', null);
const TAG = 'VBASE' + Date.now().toString(36).toUpperCase();

let seq = 0;
// Two forms (userfield, userentity) constrain the name to ^[a-zA-Z0-9_]*$, so the slug
// variant is what they get; everything else takes the readable one.
const uniqueName = (prefix, slug) => slug
	? (prefix + '_' + TAG + '_' + (++seq)).toLowerCase().replace(/[^a-z0-9_]/g, '')
	: prefix + '-' + TAG + '-' + (++seq);

// --- small helpers ------------------------------------------------------------------

const sleep = ms => new Promise(r => setTimeout(r, ms));

// A page's console errors and failed requests, collected from the moment it is created.
function watch(page)
{
	const problems = [];
	page.on('console', msg =>
	{
		if (msg.type() === 'error')
		{
			problems.push('console: ' + msg.text().slice(0, 300));
		}
	});
	page.on('pageerror', err => problems.push('pageerror: ' + String(err.message).slice(0, 300)));
	page.on('requestfailed', req => problems.push('requestfailed: ' + req.url().slice(0, 200)));
	return problems;
}

async function go(page, urlPath)
{
	await page.goto(BASE + urlPath, { waitUntil: 'domcontentloaded' });
	// The view scripts run at the bottom of the body; give jQuery and DataTables their tick.
	await page.waitForLoadState('load');
	await sleep(250);
}

// Number of rows the table currently holds. DataTables is asked when it is initialised,
// because the DOM only carries the current page of rows.
async function rowCount(page, tableSelector)
{
	if (!tableSelector)
	{
		return null;
	}

	try
	{
		return await page.evaluate(sel =>
		{
			if (!window.$ || !$(sel).length)
			{
				return null;
			}

			if ($.fn.DataTable && $.fn.DataTable.isDataTable($(sel)[0]))
			{
				return $(sel).DataTable().rows().count();
			}

			return $(sel + ' tbody tr').length;
		}, tableSelector);
	}
	catch (e)
	{
		return null;
	}
}

// Marks the current document so a later check can tell whether it was replaced.
async function mark(page)
{
	try
	{
		await page.evaluate(() => { window.__victualBaselineMark = true; });
		return true;
	}
	catch (e)
	{
		return false;
	}
}

// True when the document has been reloaded/navigated since mark() was called.
async function reloaded(page)
{
	try
	{
		return !(await page.evaluate(() => window.__victualBaselineMark === true));
	}
	catch (e)
	{
		return true; // execution context destroyed == navigation
	}
}

// Waits until either the document was replaced or the timeout expires. Returns the wait
// in ms, or null when nothing happened.
async function waitForReload(page, timeoutMs)
{
	const started = Date.now();
	while (Date.now() - started < timeoutMs)
	{
		if (await reloaded(page))
		{
			await page.waitForLoadState('load').catch(() => { });
			await sleep(200);
			return Date.now() - started;
		}
		await sleep(100);
	}
	return null;
}

// Fills a form (in a page or a frame) well enough to pass its own validation: the name
// field, then anything else marked required that is still empty.
async function fillForm(scope, name, extraFields)
{
	await scope.evaluate(([theName, extras]) =>
	{
		const setValue = (el, value) =>
		{
			el.value = value;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
			el.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
		};

		const nameField = document.querySelector(extras && extras.__nameField ? extras.__nameField : '#name');
		if (nameField)
		{
			setValue(nameField, theName);
		}

		if (extras)
		{
			for (const selector of Object.keys(extras))
			{
				if (selector === '__nameField')
				{
					continue;
				}

				const el = document.querySelector(selector);
				if (el)
				{
					setValue(el, extras[selector]);
				}
			}
		}

		document.querySelectorAll('[required]').forEach(el =>
		{
			if (el.type === 'checkbox' || el.type === 'radio' || el.type === 'hidden' || el.disabled)
			{
				return;
			}

			if (el.tagName === 'SELECT')
			{
				if (!el.value)
				{
					const option = Array.from(el.options).find(o => o.value !== '');
					if (option)
					{
						el.value = option.value;
						el.dispatchEvent(new Event('change', { bubbles: true }));
					}
				}
				return;
			}

			if (!el.value)
			{
				setValue(el, theName);
			}
		});
	}, [name, extraFields || null]);

	await sleep(150);
}

// --- the passes ---------------------------------------------------------------------

// One list: load, add through the page's own Add control, edit, delete. Every step records
// what it saw rather than what it expected.
async function walkList(context, entry, shared)
{
	const page = await context.newPage();
	const problems = watch(page);
	const record = { key: entry.key, url: entry.list, steps: {} };

	try
	{
		let loadAttempts = 0;
		let tableFound = false;
		while (loadAttempts < 2 && !tableFound)
		{
			loadAttempts++;
			await go(page, entry.list);
			// The very first request after a cold view cache can take a while to compile.
			await page.locator(entry.table).waitFor({ timeout: 30000 }).catch(() => { });
			tableFound = (await page.locator(entry.table).count()) > 0;
		}

		record.steps.load = {
			status: 'ok',
			rows: await rowCount(page, entry.table),
			tableFound: tableFound,
			attempts: loadAttempts
		};

		const embedded = entry.add.includes('embedded');
		const name = uniqueName(entry.key, entry.slugName);

		// --- create ---------------------------------------------------------------
		const before = record.steps.load.rows;
		await mark(page);

		if (embedded)
		{
			await page.locator('a.show-as-dialog-link[href*="' + entry.add.split('?')[0] + '"]').first().click();
			const frame = page.frameLocator('iframe.embed-responsive');
			await frame.locator(entry.form).waitFor({ timeout: 15000 });
			const frameHandle = await (await page.locator('iframe.embed-responsive').first().elementHandle()).contentFrame();
			await fillForm(frameHandle, name, entry.extraFields ? Object.assign({ __nameField: entry.nameField }, entry.extraFields) : (entry.nameField ? { __nameField: entry.nameField } : null));
			await frame.locator(entry.save).first().click();
		}
		else
		{
			await go(page, entry.add);
			await page.locator(entry.form).waitFor({ timeout: 15000 });
			await fillForm(page, name, entry.extraFields ? Object.assign({ __nameField: entry.nameField }, entry.extraFields) : (entry.nameField ? { __nameField: entry.nameField } : null));
			await mark(page);
			await page.locator(entry.save).first().click();
		}

		const saveWait = await waitForReload(page, 15000);
		if (!embedded)
		{
			// A non-embedded form navigates back to its list; wait for that list to settle.
			await page.waitForLoadState('load').catch(() => { });
			await sleep(400);
		}
		else if (saveWait === null)
		{
			// The dialog stayed open or the parent refreshed in place - look at the list as
			// it stands.
			await sleep(600);
		}

		if (!page.url().includes(entry.list))
		{
			await go(page, entry.list);
		}
		else
		{
			await sleep(200);
		}

		const afterCreate = await rowCount(page, entry.table);
		const created = await findRow(page, entry, name);
		record.steps.create = {
			via: embedded ? 'embedded dialog' : 'own page',
			savedName: name,
			parentReloadedOnSave: saveWait !== null,
			rowsBefore: before,
			rowsAfter: afterCreate,
			delta: (before === null || afterCreate === null) ? null : afterCreate - before,
			foundInList: created !== null
		};

		// --- the dialog-dismiss probe --------------------------------------------
		if (embedded)
		{
			await mark(page);
			await page.locator('a.show-as-dialog-link[href*="' + entry.add.split('?')[0] + '"]').first().click();
			await page.frameLocator('iframe.embed-responsive').locator(entry.form).waitFor({ timeout: 15000 }).catch(() => { });
			await page.keyboard.press('Escape');
			const dismissWait = await waitForReload(page, 4000);
			record.steps.dismissDialog = {
				parentReloadedOnDismiss: dismissWait !== null,
				convention: dismissWait !== null ? 'reloads on CloseLastModal' : 'no reload on close'
			};

			if (dismissWait === null)
			{
				await page.keyboard.press('Escape').catch(() => { });
				await sleep(300);
			}
		}

		// --- edit -----------------------------------------------------------------
		if (created)
		{
			// A form whose name is disabled once the object exists is edited through
			// another field, and then verified by its original name.
			const renamed = entry.slugName ? name + '_edit' : name + '-EDIT';
			const editField = entry.editField || entry.nameField || '#name';
			const lookupName = entry.nameImmutableOnEdit ? name : renamed;
			await go(page, entry.edit(created.id));
			await page.locator(entry.form).waitFor({ timeout: 15000 });
			await fillForm(page, renamed, { __nameField: editField });
			await mark(page);
			await page.locator(entry.save).first().click();
			const editWait = await waitForReload(page, 15000);
			await sleep(400);

			// A form that neither navigates nor re-enables its inputs after a successful
			// save leaves the user looking at a dead page. That is worth writing down.
			const leftDisabled = editWait === null ? await formDisabled(page, entry.form) : false;

			if (!page.url().includes(entry.list))
			{
				await go(page, entry.list);
			}

			const afterEdit = await rowCount(page, entry.table);
			const stillThere = await findRow(page, entry, lookupName);
			record.steps.edit = {
				navigatedBackToList: editWait !== null,
				formLeftDisabledAfterSave: leftDisabled,
				rowsAfter: afterEdit,
				delta: (afterCreate === null || afterEdit === null) ? null : afterEdit - afterCreate,
				editedField: editField,
				rowStillListed: stillThere !== null
			};
			created.id = stillThere ? stillThere.id : created.id;
		}
		else
		{
			record.steps.edit = { skipped: 'the created row was not found in the list' };
		}

		// --- delete ---------------------------------------------------------------
		if (created)
		{
			const rowsBeforeDelete = await rowCount(page, entry.table);
			await mark(page);

			// The delete button is fired through jQuery: on several lists it lives inside a
			// closed row menu, and what is being baselined is the handler, not Bootstrap's
			// dropdown.
			await page.evaluate(([selector, idAttr, id]) =>
			{
				$(selector + '[' + idAttr + '="' + id + '"]').first().trigger('click');
			}, [entry.deleteButton, entry.idAttr, created.id]);

			await page.locator('.bootbox-accept').first().waitFor({ timeout: 8000 });
			await page.locator('.bootbox-accept').first().click();
			const deleteWait = await waitForReload(page, 8000);
			await sleep(600);

			if (!page.url().includes(entry.list))
			{
				await go(page, entry.list);
			}

			const afterDelete = await rowCount(page, entry.table);
			record.steps.delete = {
				confirmDialog: 'bootbox.confirm',
				navigatedAfterDelete: deleteWait !== null,
				removalStyle: deleteWait !== null ? 'reload/redirect to the list' : 'row removed in place',
				rowsBefore: rowsBeforeDelete,
				rowsAfter: afterDelete,
				delta: (rowsBeforeDelete === null || afterDelete === null) ? null : afterDelete - rowsBeforeDelete,
				stillInList: (await findRow(page, entry, null, created.id)) !== null
			};
		}
		else
		{
			record.steps.delete = { skipped: 'nothing was created to delete' };
		}
	}
	catch (e)
	{
		record.error = String(e.message).split('\n')[0].slice(0, 300);
	}

	record.consoleProblems = dedupe(problems);
	await page.close();
	return record;
}

// Finds a row by its name attribute (or by id), returning { id, name } from the delete
// button that carries them.
async function findRow(page, entry, name, id)
{
	try
	{
		return await page.evaluate(([selector, idAttr, nameAttr, wantedName, wantedId]) =>
		{
			const buttons = Array.from(document.querySelectorAll(selector));
			const hit = buttons.find(b => wantedId
				? b.getAttribute(idAttr) === String(wantedId)
				: b.getAttribute(nameAttr) === wantedName);
			return hit ? { id: hit.getAttribute(idAttr), name: hit.getAttribute(nameAttr) } : null;
		}, [entry.deleteButton, entry.idAttr, entry.nameAttr, name, id]);
	}
	catch (e)
	{
		return null;
	}
}

async function loadOnly(context, entry, shared)
{
	const page = await context.newPage();
	const problems = watch(page);
	const url = entry.list.replace('{userentity}', shared.userentity || 'unknown');
	const record = { key: entry.key, url: url };

	try
	{
		if (entry.needs && !shared[entry.needs])
		{
			record.skipped = 'needs a ' + entry.needs + ', which this run did not create';
		}
		else
		{
			const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
			record.status = response ? response.status() : null;
			await page.waitForLoadState('load');
			await sleep(300);
			record.rows = await rowCount(page, entry.table);
			record.tableFound = entry.table ? (await page.locator(entry.table).count()) > 0 : null;
		}
	}
	catch (e)
	{
		record.error = String(e.message).split('\n')[0].slice(0, 300);
	}

	record.consoleProblems = dedupe(problems);
	await page.close();
	return record;
}

async function probeForm(context, entry, shared)
{
	const page = await context.newPage();
	const problems = watch(page);
	const url = entry.url.replace('{userentity}', shared.userentity || 'unknown');
	const record = { key: entry.key, url: url };

	try
	{
		if (entry.needs && !shared[entry.needs])
		{
			record.skipped = 'needs a ' + entry.needs + ', which this run did not create';
		}
		else
		{
			const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
			record.status = response ? response.status() : null;
			await page.waitForLoadState('load');
			await sleep(300);
			record.formFound = (await page.locator(entry.form).count()) > 0;
			record.saveButtonFound = (await page.locator(entry.save).count()) > 0;
			record.enterSubmitsHandler = await page.evaluate(sel =>
			{
				// $._data exposes the handlers jQuery bound; a form with no keydown handler
				// anywhere has lost its Enter-to-submit (the plan names userobjectform as
				// the one that did).
				//
				// Two shapes count, because plan 12 step 3's factory uses the second: a
				// handler bound directly to each input, and one delegated from the form
				// element. The delegated form is what lets a userobject form - whose only
				// inputs are userfields, so an entity with none has no inputs at page load
				// at all - have an Enter-to-submit handler to find.
				const form = document.querySelector(sel);
				if (!form || !window.$ || !$._data)
				{
					return null;
				}

				const formEvents = $._data(form, 'events');
				if (formEvents && formEvents.keydown)
				{
					return true;
				}

				return Array.from(form.querySelectorAll('input')).some(i =>
				{
					const events = $._data(i, 'events');
					return !!(events && events.keydown);
				});
			}, entry.form);
		}
	}
	catch (e)
	{
		record.error = String(e.message).split('\n')[0].slice(0, 300);
	}

	record.consoleProblems = dedupe(problems);
	await page.close();
	return record;
}

// BeginUiBusy() disables every :input in the form; EndUiBusy() puts them back. True here
// means the save path forgot to do either that or a navigation.
async function formDisabled(page, formSelector)
{
	try
	{
		return await page.evaluate(sel =>
		{
			const form = document.querySelector(sel);
			if (!form)
			{
				return null;
			}

			const inputs = Array.from(form.querySelectorAll('input, select, textarea, button'));
			return inputs.length > 0 && inputs.every(i => i.disabled);
		}, formSelector);
	}
	catch (e)
	{
		return null;
	}
}

function dedupe(list)
{
	return Array.from(new Set(list));
}

// --- report -------------------------------------------------------------------------

function markdown(result)
{
	const lines = [];
	lines.push('# Frontend baseline — ' + result.recordedAt);
	lines.push('');
	lines.push('Recorded by `.devtools/frontend/baseline.js` against ' + result.baseUrl +
		' (' + result.mode + '), commit `' + result.commit + '`.');
	lines.push('This is plan 12 verification check 1: what the list and form pages do *today*.');
	lines.push('A later run of the same harness should say the same things - with the caveat that');
	lines.push('the absolute row counts belong to whatever demo database the run used. The stable');
	lines.push('facts are the deltas, the reload conventions, the delete style and the console column.');
	lines.push('');

	lines.push('## List pages — full round trip');
	lines.push('');
	lines.push('| Page | Rows at load | Add via | Create Δ | Parent reloads on save | Parent reloads on dialog dismiss | Edit Δ | Form left disabled on edit save | Delete style | Delete Δ | Console |');
	lines.push('|---|---|---|---|---|---|---|---|---|---|---|');
	for (const r of result.lists)
	{
		const s = r.steps || {};
		const cell = v => (v === undefined || v === null) ? '—' : String(v);
		lines.push('| `' + r.key + '` | ' + cell(s.load && s.load.rows) +
			' | ' + cell(s.create && s.create.via) +
			' | ' + cell(s.create && s.create.delta) +
			' | ' + cell(s.create && s.create.parentReloadedOnSave) +
			' | ' + (s.dismissDialog ? cell(s.dismissDialog.parentReloadedOnDismiss) : 'n/a') +
			' | ' + cell(s.edit && s.edit.delta) +
			' | ' + cell(s.edit && s.edit.formLeftDisabledAfterSave) +
			' | ' + cell(s.delete && s.delete.removalStyle) +
			' | ' + cell(s.delete && s.delete.delta) +
			' | ' + (r.consoleProblems.length ? r.consoleProblems.length + ' problem(s)' : 'clean') + ' |');
	}
	lines.push('');

	lines.push('## Pages loaded but not round-tripped');
	lines.push('');
	lines.push('| Page | HTTP | Rows | Console |');
	lines.push('|---|---|---|---|');
	for (const r of result.loadOnly)
	{
		lines.push('| `' + r.key + '` | ' + (r.skipped ? 'skipped' : r.status) + ' | ' +
			(r.rows === undefined || r.rows === null ? '—' : r.rows) + ' | ' +
			(r.consoleProblems.length ? r.consoleProblems.length + ' problem(s)' : 'clean') + ' |');
	}
	lines.push('');

	lines.push('## Form pages');
	lines.push('');
	lines.push('| Form | URL | HTTP | Form | Save button | Enter-to-submit bound | Console |');
	lines.push('|---|---|---|---|---|---|---|');
	for (const r of result.forms)
	{
		lines.push('| `' + r.key + '` | `' + r.url + '` | ' + (r.skipped ? 'skipped' : r.status) +
			' | ' + (r.formFound === undefined ? '—' : r.formFound) +
			' | ' + (r.saveButtonFound === undefined ? '—' : r.saveButtonFound) +
			' | ' + (r.enterSubmitsHandler === undefined || r.enterSubmitsHandler === null ? '—' : r.enterSubmitsHandler) +
			' | ' + (r.consoleProblems.length ? r.consoleProblems.length + ' problem(s)' : 'clean') + ' |');
	}
	lines.push('');

	const noisy = []
		.concat(result.lists, result.loadOnly, result.forms)
		.filter(r => r.consoleProblems && r.consoleProblems.length);

	lines.push('## Console output seen');
	lines.push('');
	if (!noisy.length)
	{
		lines.push('None on any page.');
	}
	else
	{
		for (const r of noisy)
		{
			lines.push('- `' + r.key + '`:');
			for (const p of r.consoleProblems)
			{
				lines.push('  - ' + p);
			}
		}
	}
	lines.push('');

	const errored = [].concat(result.lists, result.loadOnly, result.forms).filter(r => r.error);
	lines.push('## Harness errors');
	lines.push('');
	lines.push(errored.length ? errored.map(r => '- `' + r.key + '`: ' + r.error).join('\n') : 'None.');
	lines.push('');

	return lines.join('\n');
}

// --- main ---------------------------------------------------------------------------

(async () =>
{
	const browser = await chromium.launch({ args: ['--no-sandbox'] });
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

	const result = {
		recordedAt: new Date().toISOString().slice(0, 10),
		baseUrl: BASE,
		mode: process.env.VICTUAL_BASELINE_MODE || 'demo mode, SQLite',
		commit: process.env.VICTUAL_BASELINE_COMMIT || 'unrecorded',
		lists: [],
		loadOnly: [],
		forms: []
	};

	const shared = {};

	for (const entry of LISTS)
	{
		if (ONLY && !ONLY.split(',').includes(entry.key))
		{
			continue;
		}

		process.stderr.write('list  ' + entry.key + '\n');
		const record = await walkList(context, entry, shared);
		result.lists.push(record);
	}

	// The userentity/userobject pair needs a live entity name; make one that outlives the
	// round trip above so the two pages that depend on it can be probed.
	if (!ONLY)
	{
		shared.userentity = await makeUserEntity(context);
	}

	for (const entry of LOAD_ONLY_LISTS)
	{
		if (ONLY && !ONLY.split(',').includes(entry.key))
		{
			continue;
		}

		process.stderr.write('load  ' + entry.key + '\n');
		result.loadOnly.push(await loadOnly(context, entry, shared));
	}

	for (const entry of FORMS)
	{
		if (ONLY && !ONLY.split(',').includes(entry.key))
		{
			continue;
		}

		process.stderr.write('form  ' + entry.key + '\n');
		result.forms.push(await probeForm(context, entry, shared));
	}

	await browser.close();

	fs.writeFileSync(OUT, JSON.stringify(result, null, '\t') + '\n');
	fs.writeFileSync(OUT.replace(/\.json$/, '.md'), markdown(result));
	process.stderr.write('wrote ' + OUT + ' and its .md summary\n');
})();

// Creates a user entity through the API so the userobjects list and form have something to
// point at. The API is used deliberately: this is fixture setup, not a page being baselined.
async function makeUserEntity(context)
{
	const name = 'vbaseline_' + TAG.toLowerCase();
	try
	{
		const page = await context.newPage();
		await page.goto(BASE + '/userentities', { waitUntil: 'domcontentloaded' });
		const ok = await page.evaluate(async ([base, entityName]) =>
		{
			const response = await fetch(base + '/api/objects/userentities', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ name: entityName, caption: entityName, description: '', show_in_sidebar_menu: 0 })
			});
			return response.ok;
		}, [BASE, name]);
		await page.close();
		return ok ? name : null;
	}
	catch (e)
	{
		return null;
	}
}
