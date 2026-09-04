'use strict';

// Runs every scenario against both instances and diffs what came back.
//
//   node run-api.js --victual http://127.0.0.1:8080 --upstream http://127.0.0.1:8081
//                   [--only stock,entities] [--out ../reports]
//
// Exit code 0 when every difference was either absent or accepted; 1 otherwise, so this is
// usable as a gate. A scenario that throws is a failure too — the alternative is a suite
// that reports parity because it stopped asking.

const path = require('path');

const { victual, upstream } = require('./lib/instance');
const { normalizeRecord } = require('./lib/normalize');
const { diffTrace } = require('./lib/diff');
const report = require('./lib/report');
const scenarios = require('./scenarios');

function parseArgs(argv) {
	const args = {
		victual: process.env.PARITY_VICTUAL_URL || 'http://127.0.0.1:8080',
		upstream: process.env.PARITY_UPSTREAM_URL || 'http://127.0.0.1:8081',
		upstreamImage: process.env.PARITY_UPSTREAM_IMAGE || null,
		out: path.join(__dirname, '..', 'reports'),
		only: null
	};
	for (let i = 2; i < argv.length; i++) {
		const flag = argv[i];
		const value = argv[i + 1];
		if (flag === '--victual') { args.victual = value; i++; }
		else if (flag === '--upstream') { args.upstream = value; i++; }
		else if (flag === '--out') { args.out = value; i++; }
		else if (flag === '--only') { args.only = value.split(',').map((s) => s.trim()); i++; }
		else if (flag === '--upstream-image') { args.upstreamImage = value; i++; }
	}
	return args;
}

// Runs one scenario against one instance and hands back its trace. The instance's trace is
// reset first so a scenario's trace is its own, whatever ran before it.
async function traceOf(instance, scenario) {
	instance.resetTrace();
	await scenario.run(instance);
	return instance.trace.map((record) => normalizeRecord(record, instance.baseUrl));
}

async function main() {
	const args = parseArgs(process.argv);
	const selected = args.only
		? scenarios.filter((s) => args.only.includes(s.name))
		: scenarios;

	if (selected.length === 0) {
		console.error(`No scenario matched --only ${args.only}. Known: ${scenarios.map((s) => s.name).join(', ')}`);
		process.exit(2);
	}

	const v = victual(args.victual);
	const u = upstream(args.upstream);

	// Login before anything, and let a failure here stop the run: every scenario below
	// would otherwise compare two identical 401s and report parity.
	await v.login();
	await u.login();

	const run = {
		startedAt: new Date().toISOString(),
		victual: { baseUrl: args.victual },
		upstream: { baseUrl: args.upstream, image: args.upstreamImage },
		scenarios: [],
		totals: { calls: 0, reported: 0, accepted: 0 }
	};

	for (const scenario of selected) {
		process.stdout.write(`  running ${scenario.name} … `);
		const result = { name: scenario.name, tags: scenario.tags || [], calls: 0, steps: [], error: null };

		try {
			// Victual first, then upstream, one scenario at a time. Running the whole list
			// against one instance and then the whole list against the other would work
			// equally well; doing it per scenario means a crash in scenario 6 still leaves
			// scenarios 1-5 comparable.
			const victualTrace = await traceOf(v, scenario);
			const upstreamTrace = await traceOf(u, scenario);

			result.calls = victualTrace.length;
			result.steps = diffTrace(victualTrace, upstreamTrace);
		} catch (error) {
			result.error = error && error.stack ? error.stack.split('\n')[0] : String(error);
		}

		run.scenarios.push(result);
		run.totals.calls += result.calls;
		process.stdout.write(result.error ? 'error\n' : `${result.calls} calls\n`);
	}

	const totals = report.classifyRun(run.scenarios);
	run.totals.reported = totals.reported;
	run.totals.accepted = totals.accepted;

	// A scenario that threw is a failure with no differences to count, so it has to be
	// added to the failing total explicitly or an error would exit 0.
	const errored = run.scenarios.filter((s) => s.error).length;

	console.log(report.renderTerminal(run));
	const written = report.write(run, args.out);
	console.log(`  report: ${written.mdPath}`);
	console.log(`          ${written.jsonPath}`);

	process.exit(run.totals.reported === 0 && errored === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(2);
});
