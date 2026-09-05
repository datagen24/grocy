"""Run with python3 -m unittest discover -s .devtools/ci."""
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from changes import changed_paths, requires_tests


class ChangeTests(unittest.TestCase):
    def test_classification(self):
        for paths, expected in [
            (["README.md", "docs/adr/0013.md", "nix/README.md"], False),
            (["NOTES.MD"], False),
            ([], False),
            (["README.md", "services/StockService.php"], True),
            (["composer.lock"], True),
            ([".github/workflows/tests.yml"], True),
            (["db/migration.sql"], True),
            (None, True),
        ]:
            with self.subTest(paths=paths):
                self.assertEqual(requires_tests(paths), expected)

    def test_schedules_and_new_branches_run(self):
        self.assertIsNone(changed_paths("schedule", {}))
        self.assertIsNone(changed_paths("push", {"before": "0" * 40}))

    def test_git_comparisons(self):
        with tempfile.TemporaryDirectory() as directory:
            def git(*args):
                return subprocess.check_output(["git", "-C", directory, *args]).decode().strip()

            git("init", "-q")
            git("config", "user.email", "ci@example.invalid")
            git("config", "user.name", "CI test")
            git("config", "commit.gpgsign", "false")
            root = Path(directory)
            (root / "code.php").write_text("<?php\n")
            git("add", ".")
            git("commit", "-qm", "base")
            base = git("rev-parse", "HEAD")
            (root / "README.md").write_text("docs\n")
            git("add", ".")
            git("commit", "-qm", "docs")
            head = git("rev-parse", "HEAD")
            original_run = subprocess.run

            def run_here(*args, **kwargs):
                return original_run(*args, cwd=directory, **kwargs)

            with patch("changes.subprocess.run", side_effect=run_here):
                self.assertFalse(requires_tests(changed_paths("push", {"before": base, "after": head})))
                self.assertFalse(requires_tests(changed_paths("pull_request", {
                    "pull_request": {"base": {"sha": base}, "head": {"sha": head}}
                })))
                # A rename out of a code path must retain the deletion in the diff.
                git("mv", "code.php", "code.md")
                git("commit", "-qm", "rename")
                renamed = git("rev-parse", "HEAD")
                self.assertTrue(requires_tests(changed_paths("push", {"before": head, "after": renamed})))
                self.assertIsNone(changed_paths("push", {"before": "missing", "after": renamed}))
