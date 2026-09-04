'use strict';

// Structural diff of two normalised traces, reported by JSON pointer.
//
// The output is a flat list of differences rather than a rendered patch, because every
// difference has to be routed: matched against the accepted-differences registry, counted
// by endpoint, and printed with enough context to act on. A patch is a good thing to read
// and a bad thing to classify.

const MAX_VALUE_CHARS = 400;

function short(value) {
	const text = JSON.stringify(value);
	if (text === undefined) return String(value);
	return text.length > MAX_VALUE_CHARS ? `${text.slice(0, MAX_VALUE_CHARS)}…` : text;
}

function typeOf(value) {
	if (value === null) return 'null';
	if (Array.isArray(value)) return 'array';
	return typeof value;
}

// Walks both sides together. `pointer` is an RFC 6901-ish path — good enough to paste
// into a jq expression, which is what someone triaging a report actually does next.
function diffValues(pointer, victual, upstream, out) {
	const vType = typeOf(victual);
	const uType = typeOf(upstream);

	if (vType !== uType) {
		out.push({
			pointer,
			kind: 'type',
			victual,
			upstream,
			detail: `${vType} on victual, ${uType} on upstream`
		});
		return;
	}

	if (vType === 'array') {
		if (victual.length !== upstream.length) {
			out.push({
				pointer,
				kind: 'length',
				victual: victual.length,
				upstream: upstream.length,
				detail: `${victual.length} elements on victual, ${upstream.length} on upstream`
			});
		}
		const n = Math.min(victual.length, upstream.length);
		for (let i = 0; i < n; i++) {
			diffValues(`${pointer}/${i}`, victual[i], upstream[i], out);
		}
		return;
	}

	if (vType === 'object') {
		const keys = new Set([...Object.keys(victual), ...Object.keys(upstream)]);
		for (const key of [...keys].sort()) {
			const inV = Object.prototype.hasOwnProperty.call(victual, key);
			const inU = Object.prototype.hasOwnProperty.call(upstream, key);
			const childPointer = `${pointer}/${key}`;

			if (inV && !inU) {
				out.push({
					pointer: childPointer,
					kind: 'extra-field',
					victual: victual[key],
					upstream: undefined,
					detail: 'present on victual, absent upstream'
				});
			} else if (!inV && inU) {
				out.push({
					pointer: childPointer,
					kind: 'missing-field',
					victual: undefined,
					upstream: upstream[key],
					detail: 'absent on victual, present upstream'
				});
			} else {
				diffValues(childPointer, victual[key], upstream[key], out);
			}
		}
		return;
	}

	if (victual !== upstream) {
		out.push({
			pointer,
			kind: 'value',
			victual,
			upstream,
			detail: `${short(victual)} against ${short(upstream)}`
		});
	}
}

// Compares one step of a scenario. Status is compared before body on purpose: when the
// two disagree about the status, the bodies are two different kinds of thing and diffing
// them produces noise that buries the one difference that matters.
function diffStep(vStep, uStep) {
	const differences = [];

	if (vStep.status !== uStep.status) {
		differences.push({
			pointer: '/status',
			kind: 'status',
			victual: vStep.status,
			upstream: uStep.status,
			detail: `HTTP ${vStep.status} against HTTP ${uStep.status}`
		});
		return differences;
	}

	if (vStep.parseError || uStep.parseError) {
		differences.push({
			pointer: '/body',
			kind: 'unparsable',
			victual: vStep.parseError || null,
			upstream: uStep.parseError || null,
			detail: 'at least one side did not answer JSON'
		});
		return differences;
	}

	diffValues('/body', vStep.body, uStep.body, differences);
	return differences;
}

// Compares two traces of the same scenario. A length mismatch is a harness bug rather than
// a finding — the same code produced both — so it is reported as its own kind and the
// comparison stops, instead of pairing step N against step N+1 and reporting everything
// after it as different.
function diffTrace(victualTrace, upstreamTrace) {
	if (victualTrace.length !== upstreamTrace.length) {
		return [{
			step: null,
			label: '(trace)',
			path: null,
			differences: [{
				pointer: '/',
				kind: 'trace-length',
				victual: victualTrace.length,
				upstream: upstreamTrace.length,
				detail:
					`the scenario made ${victualTrace.length} recorded calls against victual and ` +
					`${upstreamTrace.length} against upstream. The same function produced both, so ` +
					'this is a scenario that branches on a response rather than a finding about the fork.'
			}]
		}];
	}

	const steps = [];
	for (let i = 0; i < victualTrace.length; i++) {
		const differences = diffStep(victualTrace[i], upstreamTrace[i]);
		if (differences.length > 0) {
			steps.push({
				step: i,
				label: victualTrace[i].label,
				path: victualTrace[i].path,
				method: victualTrace[i].method,
				differences
			});
		}
	}
	return steps;
}

module.exports = { diffTrace, diffStep, diffValues, short };
