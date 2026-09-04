'use strict';

const fs = require('fs');
const path = require('path');

const { classify } = require('./accepted');
const { short } = require('./diff');

// What a run produces: a machine-readable JSON file, a Markdown summary, and a terminal
// rendering. The three exist for three readers — a later run diffing against this one, a
// person reading a pull request, and a person watching the suite now.
//
// The counting rule, said once here because everything else depends on it: a difference is
// either **reported** or **accepted**, never dropped. `accepted` means an entry in
// accepted.js explained it and named the record that decided it. The exit code is driven
// by the reported count alone, and accepted differences are printed anyway.

function classifyRun(scenarioResults) {
	let reported = 0;
	let accepted = 0;

	for (const scenario of scenarioResults) {
		for (const step of scenario.steps) {
			for (const difference of step.differences) {
				const entry = classify(step, difference);
				if (entry) {
					difference.accepted = { id: entry.id, reference: entry.reference, reason: entry.reason };
					accepted++;
				} else {
					difference.accepted = null;
					reported++;
				}
			}
		}
	}

	return { reported, accepted };
}

function renderTerminal(run) {
	const lines = [];
	const bold = (s) => `[1m${s}[0m`;
	const red = (s) => `[31m${s}[0m`;
	const green = (s) => `[32m${s}[0m`;
	const yellow = (s) => `[33m${s}[0m`;
	const dim = (s) => `[2m${s}[0m`;

	lines.push('');
	lines.push(bold('API parity — this fork against upstream grocy'));
	lines.push(dim(`victual  ${run.victual.baseUrl}`));
	lines.push(dim(`upstream ${run.upstream.baseUrl}  (${run.upstream.image || 'image not recorded'})`));
	lines.push('');

	for (const scenario of run.scenarios) {
		const reported = scenario.steps.reduce(
			(n, s) => n + s.differences.filter((d) => !d.accepted).length, 0);
		const accepted = scenario.steps.reduce(
			(n, s) => n + s.differences.filter((d) => d.accepted).length, 0);

		let status;
		if (scenario.error) status = red('ERROR');
		else if (reported > 0) status = red(`${reported} reported`);
		else if (accepted > 0) status = yellow(`${accepted} accepted`);
		else status = green('parity');

		lines.push(`  ${scenario.name.padEnd(26)} ${String(scenario.calls).padStart(4)} calls   ${status}`);

		if (scenario.error) {
			lines.push(`      ${red(scenario.error)}`);
		}

		for (const step of scenario.steps) {
			const notAccepted = step.differences.filter((d) => !d.accepted);
			if (notAccepted.length === 0) continue;
			lines.push(`      ${bold(step.label)}  ${dim(`${step.method || ''} ${step.path || ''}`)}`);
			for (const d of notAccepted.slice(0, 8)) {
				lines.push(`        ${d.pointer}  ${dim(`[${d.kind}]`)}  ${d.detail}`);
			}
			if (notAccepted.length > 8) {
				lines.push(dim(`        … and ${notAccepted.length - 8} more in the JSON report`));
			}
		}
	}

	lines.push('');
	if (run.totals.accepted > 0) {
		lines.push(bold(`Accepted differences (${run.totals.accepted}) — found, classified, not failed:`));
		const byEntry = new Map();
		for (const scenario of run.scenarios) {
			for (const step of scenario.steps) {
				for (const d of step.differences) {
					if (!d.accepted) continue;
					const key = d.accepted.id;
					if (!byEntry.has(key)) byEntry.set(key, { entry: d.accepted, count: 0, sample: d });
					byEntry.get(key).count++;
				}
			}
		}
		for (const { entry, count, sample } of byEntry.values()) {
			lines.push(`  ${yellow(entry.id)}  ×${count}   ${dim(entry.reference)}`);
			lines.push(dim(`    e.g. ${sample.pointer}: ${short(sample.victual)} against ${short(sample.upstream)}`));
		}
		lines.push('');
	}

	const verdict = run.totals.reported === 0
		? green(`PASS — ${run.totals.calls} calls compared, 0 unexplained differences`)
		: red(`FAIL — ${run.totals.reported} unexplained differences across ${run.totals.calls} calls`);
	lines.push(bold(verdict));
	lines.push('');

	return lines.join('\n');
}

function renderMarkdown(run) {
	const out = [];
	out.push('# API parity report');
	out.push('');
	out.push(`- Run at: ${run.startedAt}`);
	out.push(`- victual: \`${run.victual.baseUrl}\``);
	out.push(`- upstream: \`${run.upstream.baseUrl}\` (\`${run.upstream.image || 'image not recorded'}\`)`);
	out.push(`- Calls compared: ${run.totals.calls}`);
	out.push(`- Unexplained differences: **${run.totals.reported}**`);
	out.push(`- Accepted differences: ${run.totals.accepted}`);
	out.push('');

	out.push('| Scenario | Calls | Reported | Accepted |');
	out.push('|---|---:|---:|---:|');
	for (const s of run.scenarios) {
		const reported = s.steps.reduce((n, st) => n + st.differences.filter((d) => !d.accepted).length, 0);
		const accepted = s.steps.reduce((n, st) => n + st.differences.filter((d) => d.accepted).length, 0);
		out.push(`| ${s.name} | ${s.calls} | ${reported} | ${accepted} |`);
	}
	out.push('');

	const withReported = run.scenarios.filter((s) =>
		s.error || s.steps.some((st) => st.differences.some((d) => !d.accepted)));

	if (withReported.length > 0) {
		out.push('## Unexplained differences');
		out.push('');
		out.push('Each of these is either a defect in the fork or a difference that belongs in');
		out.push('`.devtools/parity/harness/lib/accepted.js` with the record that decided it.');
		out.push('');
		for (const s of withReported) {
			out.push(`### ${s.name}`);
			out.push('');
			if (s.error) {
				out.push(`**The scenario itself failed:** ${s.error}`);
				out.push('');
			}
			for (const step of s.steps) {
				const notAccepted = step.differences.filter((d) => !d.accepted);
				if (notAccepted.length === 0) continue;
				out.push(`**${step.label}** — \`${step.method || ''} ${step.path || ''}\``);
				out.push('');
				out.push('| Pointer | Kind | victual | upstream |');
				out.push('|---|---|---|---|');
				for (const d of notAccepted) {
					out.push(`| \`${d.pointer}\` | ${d.kind} | \`${short(d.victual)}\` | \`${short(d.upstream)}\` |`);
				}
				out.push('');
			}
		}
	}

	if (run.totals.accepted > 0) {
		out.push('## Accepted differences');
		out.push('');
		out.push('Found and classified, not failed. Each names the record that accepted it.');
		out.push('');
		const byEntry = new Map();
		for (const s of run.scenarios) {
			for (const step of s.steps) {
				for (const d of step.differences) {
					if (!d.accepted) continue;
					if (!byEntry.has(d.accepted.id)) {
						byEntry.set(d.accepted.id, { entry: d.accepted, count: 0, samples: [] });
					}
					const bucket = byEntry.get(d.accepted.id);
					bucket.count++;
					if (bucket.samples.length < 3) bucket.samples.push({ step, d });
				}
			}
		}
		for (const { entry, count, samples } of byEntry.values()) {
			out.push(`### \`${entry.id}\` (×${count})`);
			out.push('');
			out.push(`Record: [\`${entry.reference}\`](../../../${entry.reference})`);
			out.push('');
			out.push(entry.reason);
			out.push('');
			for (const { step, d } of samples) {
				out.push(`- ${step.label} \`${d.pointer}\`: \`${short(d.victual)}\` against \`${short(d.upstream)}\``);
			}
			out.push('');
		}
	}

	return out.join('\n');
}

function write(run, outDir) {
	fs.mkdirSync(outDir, { recursive: true });
	const jsonPath = path.join(outDir, 'api-parity.json');
	const mdPath = path.join(outDir, 'api-parity.md');
	fs.writeFileSync(jsonPath, JSON.stringify(run, null, '\t'));
	fs.writeFileSync(mdPath, renderMarkdown(run));
	return { jsonPath, mdPath };
}

module.exports = { classifyRun, renderTerminal, renderMarkdown, write };
