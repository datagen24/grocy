# Documentation conventions

Write for a human with software engineering experience and for agents picking up the
repository. State the relevant facts directly. Keep technical reasoning where it helps
someone evaluate a decision or understand a constraint.

## Document purposes

| Document | Contents |
|---|---|
| Root README | What Victual is, the maintainer's goals, current state, and links for getting started. |
| Folder README | What the folder contains, where to start, and any required reading or work order. |
| ADR | The problem, evidence, decision, alternatives, and consequences. Enough rationale to evaluate the choice. |
| Plan | Research for a major change: current behavior, requirements, scope, design options, dependencies, open questions, and verification criteria. Enough information to derive execution steps later. |
| Operational guide | Setup, commands, configuration, expected results, and troubleshooting needed to perform a task. |

## Prose

- Lead with the result or requirement. Use short paragraphs with one main point each.
- Explain a dependency by stating what requires what and why.
- Retain concrete examples, evidence, tradeoffs, and rejected alternatives that affect the design.
- Remove rhetorical questions, self-evaluation, repeated conclusions, and narration of
  how the author reached or revised a sentence.
- Replace historical corrections with the corrected fact. Keep material provenance,
  maintainer decisions, and implementation departures in the relevant record.
- Use tables for status and comparisons. Keep entries short; link to detailed evidence.
- Distinguish proposed, accepted, implemented, and verified. Do not infer one from another.

## Plans

Plans are research and design inputs for a coding assistant's planning function. Describe
what the change needs to achieve and the constraints it must satisfy. Components, code
examples, dependency order, and acceptance criteria are useful when they establish the
design. Agent assignments, session scripts, motivational instructions, and speculative
step-by-step execution sequences do not belong in the plan.

Keep numbered **Open questions**. Put review answers directly below their questions as
`> **Response:**` blocks. Preserve their meaning and attribution when editing.

For landed work, retain the proposed design in its original tense. An **Executed** section
records what shipped, verification results, remaining work, and departures from the design.
The [plan index](plans/README.md) owns delivery status and cross-plan order; update both
when a dependency or status changes.

## ADRs and evidence

Follow the [ADR lifecycle](adr/README.md#lifecycle). An editorial cleanup must not change
a decision, its status, acceptance gates, or unresolved questions. Explain alternatives
and tradeoffs without reproducing a review conversation.

Measurements identify the date, working copy, and reproduction method. Cite code symbols
rather than line numbers. Keep links to sources and verification evidence when shortening
prose. Do not rewrite upstream release history as part of a style cleanup.
