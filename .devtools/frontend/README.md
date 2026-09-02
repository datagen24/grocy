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
