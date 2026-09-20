"""Run vendored maintenance scripts only against disposable flag fixtures."""

# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


VENDOR = Path(__file__).resolve().parents[2] / "include/vendor/flag-icons"
SVG = '<svg viewBox="0 0 4 3"><path d="M0 0h4v3H0z"/></svg>\n'


class FlagHelpersTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="flag-helper-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for ratio in ("1x1", "4x3"):
            directory = self.root / "flags" / ratio
            directory.mkdir(parents=True)
            (directory / "aa.svg").write_text(SVG, encoding="utf-8")
            for name in ("aa.svg.bak", "bb.svg.tmp", "notes.svg.txt", "README"):
                (directory / name).write_text(SVG, encoding="utf-8")
        (self.root / "country.json").write_text(
            json.dumps([{"code": "aa", "name": "Fixture"}]), encoding="utf-8"
        )

    def run_helper(self, name):
        return subprocess.run(
            [sys.executable, str(VENDOR / name)],
            cwd=self.root, capture_output=True, text=True, timeout=10, check=False,
        )

    def test_ids_change_only_files_with_svg_suffix(self):
        result = self.run_helper("flag-ids.py")
        self.assertEqual(result.returncode, 0, result.stderr)
        for ratio in ("1x1", "4x3"):
            directory = self.root / "flags" / ratio
            self.assertIn('id="flag-icons-aa"', (directory / "aa.svg").read_text())
            for name in ("aa.svg.bak", "bb.svg.tmp", "notes.svg.txt", "README"):
                self.assertEqual((directory / name).read_text(), SVG, name)

    def test_country_check_ignores_backup_and_temporary_files(self):
        result = self.run_helper("flags.py")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("All flag icons and country.json are in sync.", result.stdout)

    def test_country_check_still_rejects_unknown_real_svg(self):
        (self.root / "flags/1x1/zz.svg").write_text(SVG, encoding="utf-8")
        result = self.run_helper("flags.py")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("Code not found in country.json: zz", result.stdout)


if __name__ == "__main__":
    unittest.main()
