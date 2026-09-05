import tempfile
import unittest
from pathlib import Path

from check_adr_headers import select_adrs, validate

PATH = "docs/adr/0042-example.md"
HEADER = """# ADR-0042: Example decision

- **Status:** Proposed
- **Decider:** maintainer
- **Recorded:** 2026-09-05, when drafted.

## Context

Body text.
"""


class AdrHeaderTests(unittest.TestCase):
    def test_existing_status_formats(self):
        for status in [
            "- **Status:** Proposed",
            "- **Status: Proposed.** Written to be argued with.",
            "- **Status:** **Accepted.** The original date is not recorded.",
            "- **Status: Accepted, 2026-09-04.** More context.",
            "- **Status:** Rejected (2026-09-04)",
            "- **Status:** **Superseded by [ADR-0008](0008-example.md)**,\n  2026-08-31.",
        ]:
            with self.subTest(status=status):
                self.assertEqual(validate(PATH, HEADER.replace("- **Status:** Proposed", status)), [])

    def test_missing_empty_duplicate_and_body_only_fields(self):
        for field, value in [("Status", "Proposed"), ("Decider", "maintainer"), ("Recorded", "2026-09-05, when drafted.")]:
            line = f"- **{field}:** {value}\n"
            for replacement in ["", f"- **{field}:**\n", line + line]:
                with self.subTest(field=field, replacement=replacement):
                    self.assertTrue(validate(PATH, HEADER.replace(line, replacement)))
            self.assertTrue(validate(PATH, HEADER.replace(line, "") + line))

    def test_invalid_title_status_and_date(self):
        for before, after in [
            ("ADR-0042", "ADR-0041"),
            ("Example decision", ""),
            ("Proposed", "Approved"),
            ("Proposed", "Superseded"),
            ("2026-09-05", "2026-02-30"),
            ("2026-09-05", "yesterday"),
        ]:
            with self.subTest(after=after):
                self.assertTrue(validate(PATH, HEADER.replace(before, after)))

    def test_multiline_metadata(self):
        self.assertEqual(validate(PATH, HEADER.replace("**Decider:** maintainer", "**Decider:**\n  maintainer")), [])

    def test_only_changed_existing_records_are_selected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "docs/adr").mkdir(parents=True)
            for path in [PATH, "docs/adr/0043-untouched.md", "docs/adr/README.md"]:
                (root / path).write_text(HEADER)
            self.assertEqual(select_adrs([PATH, "docs/adr/README.md", "docs/adr/0044-deleted.md", "README.md"], root), [PATH])
            self.assertEqual(select_adrs([], root), [])
            self.assertEqual(select_adrs(None, root), [PATH, "docs/adr/0043-untouched.md"])
