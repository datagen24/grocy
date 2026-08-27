# Contributing

This is a hard fork of [grocy](https://github.com/grocy/grocy), maintained for one
household and deployed on k3s. It has drifted from upstream deliberately — PostgreSQL
support, and the roadmap in [docs/plans/](../docs/plans/README.md) — and it is not a
community project.

## Where things go

| If you have | Go to |
|---|---|
| A question about using grocy | [r/grocy](https://www.reddit.com/r/grocy) and [grocy.info](https://grocy.info). Upstream's resources cover everything this fork shares with it, which is most of it |
| A bug in upstream grocy | [grocy/grocy issues](https://github.com/grocy/grocy/issues/new/choose) — reproduce it on the [upstream demo](https://demo-prerelease.grocy.info) first |
| A bug in something this fork added | This repo's issue tracker. Fork-only work — PostgreSQL, and anything from [docs/plans/](../docs/plans/README.md) — will not reproduce upstream, so upstream is the wrong place for it |
| Translations | [Transifex](https://explore.transifex.com/grocy/grocy/), which is upstream's. Strings this fork adds are not there |
| A wish to say thanks | <https://grocy.info/#say-thanks>. This fork stands entirely on [Bernd Bestel](https://berrnd.de)'s work and adds nothing to it that is worth paying for |

## Pull requests

Reasonable contributions are welcome. This repository exists to serve one deployment, so
the roadmap is driven by that — but if you are running this fork and have a fix or an
improvement, open it.

**AI-assisted contributions are fine.** What matters is whether the change is correct and
whether you verified it, not how it was written. The verification bar below is the same
either way, and it is the part that is not optional.

The ground rules in [docs/plans/README.md](../docs/plans/README.md) are what a change is
judged against here, and they are stricter than they look:

- **Additive API.** Existing endpoints keep their response shape. Nearly every response is
  a database row serialised as-is, so a schema change *is* an API change; anything that
  alters an existing response is called out explicitly rather than slipped in.
- **Migrations from 0256 on work on every supported engine** — a portable `NNNN.sql`, or a
  per engine `NNNN.sqlite.sql` / `NNNN.pgsql.sql` pair. See
  [db/pgsql/README.md](../db/pgsql/README.md), which also documents fifteen porting hazards
  worth reading before writing SQL for both engines.
- **Verification means a booted instance**, not a lint pass and not "it loads cleanly".
  Schema changes are checked with `.devtools/pgsql/difftest.php` for views and
  `trigdifftest.php` for trigger behaviour; new views must return identical output on both
  engines unless there is a stated reason they cannot.

The [pull request template](PULL_REQUEST_TEMPLATE.md) asks for exactly those three.

## Licensing

Two layers, because this is a fork rather than an original work:

- **Everything inherited from upstream grocy stays MIT.** Upstream's copyright and
  permission notice in [LICENSE.md](../LICENSE.md) is retained as MIT requires, and
  nothing here changes the terms on code that came from there.
- **Changes made in this fork are copyright their author and licensed under the BSD
  4-clause license.** MIT permits sublicensing, so a derivative may carry different terms
  provided the original notice travels with it.

By opening a pull request you agree your contribution can be released under those terms.
