'use strict';

// What "the same response" means, spelled out.
//
// The rule this suite exists to check is ADR-0005's: **the engine may change; the JSON on
// the wire may not.** So normalisation here is deliberately thin. Everything it does is
// one of two things — erasing a value that cannot be equal because it names a moment or a
// row that was created at a different instant, or absorbing the one float difference
// ADR-0005 already accepted. It does *not* coerce types, and that is the point: a
// PostgreSQL `true` where SQLite sent `"1"`, or a number where a string was documented, is
// precisely the class of defect the fork can introduce without noticing, so it must
// survive normalisation and be reported.

// Fields whose value is a wall-clock moment or a per-instance identifier. Two instances
// answering the same question milliseconds apart legitimately differ here, so the name is
// masked and its *presence* still compared — a field that exists on one side and not the
// other is still a difference.
const VOLATILE_FIELDS = new Set([
	'row_created_timestamp',
	'last_used',
	'expires',
	'api_key',
	'session_key',
	'undone_timestamp',
	'used_timestamp',
	'last_login',
	'timestamp',

	// **The opaque booking handles, and masking them is not a concession.** `stock_id`,
	// `transaction_id` and `correlation_id` are `uniqid()` output — a hex rendering of the
	// current microsecond — so two instances cannot agree on one and no client is
	// documented to expect a particular value. Before they were masked they were 182 of
	// the first run's 231 differences, which is the shape of a report nobody reads: the
	// three findings that mattered were on page four. What is still compared is that the
	// field is present, that it is present on both, and that rows sharing a transaction on
	// one side share one on the other — because the *grouping* is what plan 13's
	// atomicity means, and grouping survives masking only if it is checked separately,
	// which `.devtools/pgsql/`'s rollback phase is what does.
	'stock_id',
	'transaction_id',
	'correlation_id',

	// GET /system/time reports the clock. The two instances are asked a fraction of a
	// second apart and answered one second apart on the first run that got this far, which
	// is the definition of a flaky assertion. What is still compared is that the fields
	// exist, that the offset variant differs from the plain one by the offset — which the
	// scenario asserts by asking for both — and that the shape is the same.
	'time_local',
	'time_local_sqlite3',
	'time_utc'
]);

// Fields describing the machine rather than the application. Two images built from
// different base layers legitimately ship different interpreter and library versions;
// reporting that on every run would say nothing about whether the fork changed behaviour.
// Kept separate from IDENTITY_FIELDS so that the reason is readable in the code rather
// than inferred from a list.
const ENVIRONMENT_FIELDS = new Set([
	'php_version',
	'sqlite_version',
	'os'
]);

// Fields whose value is the product's identity rather than its behaviour. The fork is
// renamed (plan 16), so these differ by construction and comparing them would report the
// rename on every run instead of once.
const IDENTITY_FIELDS = new Set([
	'grocy_version',
	'victual_version',
	'release_date',
	'version'
]);

const MASK = '<volatile>';
const IDENTITY = '<identity>';
const ENVIRONMENT = '<environment>';

// Six decimal places. ADR-0005's accepted float-accumulation exception is ~1e-15 —
// products_average_price.price being 4.124499999999999 on one engine and 4.1245 on the
// other — and six places is far enough above that to absorb it while staying far below
// anything a household would notice in a price. A difference in the second decimal place
// of a price is still a difference.
const FLOAT_PLACES = 6;

function roundFloat(n) {
	if (!Number.isFinite(n)) return n;
	if (Number.isInteger(n)) return n;
	return Number(n.toFixed(FLOAT_PLACES));
}

function normalizeValue(key, value) {
	if (VOLATILE_FIELDS.has(key)) return MASK;
	if (IDENTITY_FIELDS.has(key)) return IDENTITY;
	if (ENVIRONMENT_FIELDS.has(key)) return ENVIRONMENT;

	if (value === null || value === undefined) return value;

	if (typeof value === 'number') return roundFloat(value);

	// A numeric *string* is left as a string on purpose. "4.1245" and 4.1245 are different
	// answers to the same question and ADR-0005 says only one of them is conforming; the
	// suite's job is to say which endpoint disagrees, not to make them agree. The rounding
	// below applies only so that a string carrying float noise does not report as a
	// difference when the same string on the other side carries different noise.
	if (typeof value === 'string' && /^-?\d+\.\d{7,}$/.test(value)) {
		return roundFloat(Number(value)).toString();
	}

	if (Array.isArray(value)) return value.map((v) => normalizeValue(key, v));

	if (typeof value === 'object') return normalizeObject(value);

	return value;
}

function normalizeObject(obj) {
	if (obj === null || typeof obj !== 'object') return obj;
	if (Array.isArray(obj)) return obj.map((v) => normalizeObject(v));

	const out = {};
	// Key order is not part of the wire contract — PHP's json_encode follows insertion
	// order and a view's column order is not something either project promises — so keys
	// are sorted before comparison. A *missing* or *extra* key is still a difference.
	for (const key of Object.keys(obj).sort()) {
		out[key] = normalizeValue(key, obj[key]);
	}
	return out;
}

// Lists come back in whatever order the engine felt like unless the endpoint documents
// one. Sorting by id where every element has one removes that as a source of noise
// without hiding a genuinely different set: the elements are still compared one by one.
function normalizeBody(body) {
	const normalized = normalizeObject(body);
	if (Array.isArray(normalized) && normalized.every((e) => e && typeof e === 'object' && 'id' in e)) {
		return [...normalized].sort((a, b) => Number(a.id) - Number(b.id));
	}
	return normalized;
}

// Text that is not JSON — the iCal feed, an HTML error page — still has to be compared,
// because its content is exactly what a subscribing calendar or a browsing user sees.
// What has to come out of it first is everything that names *which instance* answered.
function normalizeText(text, baseUrl) {
	let out = String(text);
	if (baseUrl) out = out.split(baseUrl).join('<base-url>');
	return out
		.replace(/victual/gi, '<product>')
		.replace(/grocy/gi, '<product>')
		// iCal stamps every event with the moment it was generated, and every UID with a
		// per-instance token.
		.replace(/\d{8}T\d{6}Z?/g, '<ical-timestamp>')
		.replace(/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/g, '<timestamp>')
		.replace(/UID:.*/g, 'UID:<uid>');
}

// Any string carrying the instance's own base URL differs by construction — the two run on
// different ports — and any string carrying a generated secret differs by design. The
// calendar sharing link is both at once.
function normalizeStrings(value, baseUrl) {
	if (typeof value === 'string') {
		let out = baseUrl ? value.split(baseUrl).join('<base-url>') : value;
		// The iCal secret is a special-purpose API key: 50 characters of base62. Masked by
		// shape rather than by field name because it arrives inside a URL.
		out = out.replace(/secret=[A-Za-z0-9]{20,}/g, 'secret=<secret>');
		return out;
	}
	if (Array.isArray(value)) return value.map((v) => normalizeStrings(v, baseUrl));
	if (value && typeof value === 'object') {
		const out = {};
		for (const key of Object.keys(value)) out[key] = normalizeStrings(value[key], baseUrl);
		return out;
	}
	return value;
}

function normalizeRecord(record, baseUrl) {
	// A body that did not parse as JSON is compared as normalised text rather than being
	// declared incomparable. Reporting "at least one side did not answer JSON" for the iCal
	// feed — which is text/calendar on both sides and correct on both — was noise that hid
	// whether the feed's *contents* agreed.
	if (record.parseError && record.body && typeof record.body.__unparsed === 'string') {
		return {
			label: record.label,
			method: record.method,
			path: record.path,
			status: record.status,
			body: { __text: normalizeText(record.body.__unparsed, baseUrl) },
			parseError: null
		};
	}

	return {
		label: record.label,
		method: record.method,
		path: record.path,
		status: record.status,
		body: normalizeStrings(normalizeBody(record.body), baseUrl),
		parseError: record.parseError
	};
}

module.exports = {
	normalizeBody,
	normalizeRecord,
	normalizeText,
	VOLATILE_FIELDS,
	IDENTITY_FIELDS,
	ENVIRONMENT_FIELDS,
	FLOAT_PLACES,
	MASK,
	IDENTITY
};
