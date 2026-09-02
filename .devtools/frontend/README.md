# Frontend baseline harness

Records what the list and form pages do today, so a refactor of them can be checked
against something better than an opinion. It is [plan 12](../../docs/plans/12-frontend-shared-core.md)
verification check 1; `baseline-2026-09-02.json` and its `.md` summary are the recorded
run.

```bash
# 1. a booted demo instance (see .agents/skills/run-app/SKILL.md)
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php -S 127.0.0.1:8200 -t public &
curl -s -o /dev/null http://127.0.0.1:8200/            # seeds the demo data

# 2. the harness
cd .devtools/frontend
PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install
PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node baseline.js \
	--url http://127.0.0.1:8200 --out /tmp/baseline-now.json
```

`--only locations,productgroups` limits the run to named pages. The page inventory
(selectors, URLs, data attributes) lives in `pages.js`; the walk is `baseline.js`.

It creates and then deletes one record per list, so it needs a throwaway database. The
absolute row counts belong to whatever demo data the run used — what a later run must
reproduce is the deltas, the reload conventions, the delete style and the console column.

**Three cells changed on purpose in plan 12 steps 3 and 4**, so a run against a tree that
has those will not match `baseline-2026-09-02.md` exactly, and should not:

| Page | Column | Was | Is | Why |
|---|---|---|---|---|
| `productgroups` | Parent reloads on dialog dismiss | `true` | `false` | Step 4: its form posts `Reload` after a save instead of `CloseLastModal`, which also fires on Escape |
| `productgroups` | Form left disabled on edit save | `true` | `false` | Step 4: `/productgroup/{id}` had no non-embedded branch, so a save outside a dialog never finished |
| `userobjectform` | Enter-to-submit bound | `false` | `true` | Step 3: the factory binds it by construction |

Anything else that moves is a regression.

## The other two probes

```bash
# plan 12 check 5 - S29, proved with a stored payload rather than by reading the diff
node s29-payload.js --url http://127.0.0.1:8200 --out /tmp/s29.json

# plan 12 check 3 - error surfacing, forced by intercepting routes and answering 500
node forced-failure.js --url http://127.0.0.1:8200

# every view route in routes.php's non-API group: HTTP status and console problems
node routes-smoke.js --url http://127.0.0.1:8200 --out /tmp/routes.json
```

`s29-payload.js` seeds records whose name is a live `<img onerror>` tag and asserts that
no page executes it. Run it against an unfixed tree first — there it must report `xss=1`
on every probe, or it is not capable of failing and proves nothing. It leaves its seeded
records behind, named with a per-run token, so it needs a throwaway database too.

`forced-failure.js` exits non-zero if any assertion fails, so it can be run as a gate.

`routes-smoke.js` reads its route list from `routes.txt`, which is the `$group->get(...)`
paths of `routes.php`'s non-API group; regenerate it with

```bash
sed -n '33,150p' ../../routes.php | grep -oE "\\\$group->get\('[^']*'" | sed "s/.*get('//;s/'\$//" > routes.txt
```
