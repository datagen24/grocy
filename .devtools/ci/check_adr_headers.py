"""Validate ADR metadata, without imposing Markdown style or lifecycle decisions."""
import argparse
from datetime import date
import json
import os
from pathlib import Path
import re

from changes import changed_paths

ADR_PATH = re.compile(r"docs/adr/(\d{4})-[^/]+\.md\Z")
FIELDS = ("Status", "Decider", "Recorded")


def validate(path, text):
    """Return readable errors; accept both existing bold-header conventions."""
    errors = []
    number = ADR_PATH.fullmatch(path).group(1)
    lines = text.splitlines()
    if not lines or not re.fullmatch(rf"# ADR-{number}: \S.*", lines[0]):
        errors.append(f"expected '# ADR-{number}: <title>' on the first line")

    # Only metadata before the first section counts. A body/example must not supply
    # an otherwise missing field. Continuation lines belong to the preceding bullet.
    header = re.split(r"^#{1,6}\s+", "\n".join(lines[1:]), maxsplit=1, flags=re.M)[0]
    header = header.replace("**", "")
    entries = re.findall(r"^- ([^:\n]+):([^\n]*(?:\n[ \t]+[^\n]*)*)", header, re.M)
    values = {}
    for name in FIELDS:
        matches = [value.strip() for key, value in entries if key == name]
        if len(matches) != 1 or not matches[0]:
            errors.append(f"expected exactly one nonempty '{name}:' header field")
        else:
            values[name] = matches[0]

    if "Status" in values:
        status = values["Status"]
        if not re.match(r"(?:Proposed|Accepted|Rejected)\b|Superseded by (?:\[)?ADR-\d{4}\b", status):
            errors.append("Status must start with Proposed, Accepted, Rejected, or Superseded by ADR-NNNN")

    if "Recorded" in values:
        recorded = re.match(r"\d{4}-\d{2}-\d{2}\b", values["Recorded"])
        try:
            if recorded is None:
                raise ValueError
            date.fromisoformat(recorded.group())
        except ValueError:
            errors.append("Recorded must start with a valid YYYY-MM-DD date")
    return errors


def select_adrs(paths, root):
    candidates = (str(path.relative_to(root)) for path in (root / "docs/adr").glob("*.md")) if paths is None else paths
    # Deleted files have no header to validate. The README is the index, not an ADR.
    return sorted(path for path in candidates if ADR_PATH.fullmatch(path) and (root / path).is_file())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--all", action="store_true", help="check all ADRs locally")
    args = parser.parse_args()
    root = Path.cwd()
    paths = None
    if not args.all:
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
        paths = changed_paths(os.environ["GITHUB_EVENT_NAME"], event)
        if paths is None:
            print("Change comparison unavailable; checking all ADR headers.")
    selected = select_adrs(paths, root)
    failures = 0
    for path in selected:
        for error in validate(path, (root / path).read_text()):
            print(f"{path}: {error}")
            failures += 1
    print(f"Checked {len(selected)} ADR header(s); {failures} error(s).")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
