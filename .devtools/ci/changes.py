"""Classify the complete Git diff; uncertain comparisons run all checks."""
import json
import os
from pathlib import Path
import subprocess


def changed_paths(event_name, event):
    if event_name == "pull_request":
        base = event["pull_request"]["base"]["sha"]
        head = event["pull_request"]["head"]["sha"]
        comparison = [f"{base}...{head}"]
    elif event_name == "push" and event.get("before", "").strip("0"):
        comparison = [event["before"], event["after"]]
    else:
        return None
    try:
        # Disable rename detection so moving code to a Markdown path still runs tests.
        result = subprocess.run(
            ["git", "diff", "--name-only", "--no-renames", "-z", *comparison, "--"],
            check=True, capture_output=True,
        )
    except subprocess.CalledProcessError:
        return None
    return [os.fsdecode(path) for path in result.stdout.split(b"\0") if path]


def requires_tests(paths):
    return paths is None or any(not path.lower().endswith(".md") for path in paths)


if __name__ == "__main__":
    event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
    run = requires_tests(changed_paths(os.environ["GITHUB_EVENT_NAME"], event))
    with open(os.environ["GITHUB_OUTPUT"], "a") as output:
        output.write(f"run-tests={str(run).lower()}\n")
    print("Run expensive checks" if run else "Markdown-only or empty diff: lint only")
