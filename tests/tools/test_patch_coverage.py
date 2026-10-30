#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

import contextlib
import io
import subprocess
import tempfile
import unittest
from pathlib import Path

import patch_coverage

BASE_SOURCE = "<?php\n$a = 1;\n$b = 2;\n$c = 3;\n"
CONFIG = """<phpunit><source>
<include><directory suffix=".php">src</directory><file>legacy.php</file></include>
<exclude><directory>src/Generated</directory></exclude>
</source></phpunit>"""


class PatchCoverageTest(unittest.TestCase):
    def setUp(self):
        owned = tempfile.TemporaryDirectory()
        self.addCleanup(owned.cleanup)
        self.root = Path(owned.name).resolve()
        self.write("phpunit.xml", CONFIG)
        self.write("src/app.php", BASE_SOURCE)
        self.write("src/other.php", BASE_SOURCE)
        self.write("docs/notes.php", BASE_SOURCE)
        self.git("init", "-q")
        self.commit()
        self.base = self.git("rev-parse", "HEAD")

    def git(self, *args):
        return subprocess.check_output(["git", "-c", "core.hooksPath=/dev/null", "-C", str(self.root), *args], text=True).strip()

    def commit(self):
        self.git("add", "-A")
        self.git("-c", "commit.gpgsign=false", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "fixture")

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def clover(self, name, files):
        body = "".join(
            f'<file name="{self.root}/{path}">' + "".join(f'<line num="{num}" type="stmt" count="{count}"/>' for num, count in lines.items()) + "</file>"
            for path, lines in files.items()
        )
        statements = sum(len(lines) for lines in files.values())
        self.write(name, f'<?xml version="1.0"?><coverage><project>{body}<metrics statements="{statements}"/></project></coverage>')
        return str(self.root / name)

    def run_gate(self, *clovers, paths=("src/**/*.php", "*.php"), excludes=()):
        argv = ["--root", str(self.root), "--base", self.base, "--require", "100", "--phpunit-config", "phpunit.xml"]
        for clover in clovers:
            argv += ["--clover", clover]
        argv += ["--paths", *paths]
        for exclude in excludes:
            argv += ["--exclude", exclude]
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            try:
                status = patch_coverage.main(argv)
            except SystemExit as error:
                status = error.code
        return status, out.getvalue()

    def change_line_three(self, text="$b = 22;"):
        self.write("src/app.php", BASE_SOURCE.replace("$b = 2;", text))
        self.commit()

    def test_uncovered_changed_line_fails(self):
        self.change_line_three()
        clover = self.clover("c.xml", {"src/app.php": {2: 1, 3: 0, 4: 1}})
        status, output = self.run_gate(clover)
        self.assertEqual(1, status)
        self.assertIn("src/app.php", output)
        self.assertIn("3", output)

    def test_unchanged_uncovered_line_passes(self):
        self.change_line_three()
        clover = self.clover("c.xml", {"src/app.php": {2: 0, 3: 1, 4: 0}})
        self.assertEqual(0, self.run_gate(clover)[0])

    def test_covered_changed_line_passes(self):
        self.change_line_three()
        clover = self.clover("c.xml", {"src/app.php": {2: 1, 3: 4, 4: 1}})
        status, output = self.run_gate(clover)
        self.assertEqual(0, status, output)

    def test_ignore_annotation_on_changed_line_fails(self):
        for marker in ("@codeCoverageIgnore", "@codeCoverageIgnoreStart", "@codeCoverageIgnoreEnd"):
            with self.subTest(marker=marker):
                self.change_line_three(f"$b = 2; // {marker}")
                clover = self.clover("c.xml", {"src/app.php": {2: 1, 3: 1, 4: 1}})
                status, output = self.run_gate(clover)
                self.assertEqual(1, status)
                self.assertIn("codeCoverageIgnore", output)
                self.git("reset", "-q", "--hard", self.base)

    def test_missing_report_fails(self):
        self.change_line_three()
        status, output = self.run_gate(str(self.root / "absent.xml"))
        self.assertEqual(1, status)
        self.assertIn("absent.xml", output)

    def test_empty_report_fails(self):
        self.change_line_three()
        self.write("empty.xml", "")
        self.assertEqual(1, self.run_gate(str(self.root / "empty.xml"))[0])

    def test_report_without_statements_fails(self):
        self.change_line_three()
        clover = self.clover("c.xml", {"src/app.php": {}})
        status, output = self.run_gate(clover)
        self.assertEqual(1, status)
        self.assertIn("no statements", output)

    def test_line_covered_in_only_one_of_two_reports_passes(self):
        self.change_line_three()
        first = self.clover("first.xml", {"src/app.php": {2: 1, 3: 0, 4: 1}})
        second = self.clover("second.xml", {"src/app.php": {3: 2}, "src/other.php": {2: 1}})
        status, output = self.run_gate(first, second)
        self.assertEqual(0, status, output)

    def test_absolute_paths_are_normalized_to_the_checkout(self):
        self.change_line_three()
        self.write("c.xml", f'<coverage><project><file name="{self.root}/./src/../src/app.php"><line num="3" type="stmt" count="1"/></file></project></coverage>')
        status, output = self.run_gate(str(self.root / "c.xml"))
        self.assertEqual(0, status, output)

    def test_report_path_outside_checkout_fails(self):
        self.change_line_three()
        self.write("c.xml", '<coverage><project><file name="/elsewhere/src/app.php"><line num="3" type="stmt" count="1"/></file></project></coverage>')
        self.assertEqual(1, self.run_gate(str(self.root / "c.xml"))[0])

    def test_deleted_only_diff_passes(self):
        self.write("src/app.php", "<?php\n$a = 1;\n$c = 3;\n")
        self.git("rm", "-q", "src/other.php")
        self.commit()
        clover = self.clover("c.xml", {"src/app.php": {2: 0, 3: 0}})
        status, output = self.run_gate(clover)
        self.assertEqual(0, status, output)

    def test_unmeasured_changed_file_fails(self):
        self.write("src/new.php", "<?php\necho 1;\n")
        self.commit()
        clover = self.clover("c.xml", {"src/app.php": {2: 1}})
        status, output = self.run_gate(clover)
        self.assertEqual(1, status)
        self.assertIn("src/new.php", output)
        self.assertIn("not measured", output)

    def test_file_outside_coverage_source_is_not_required(self):
        self.write("src/Generated/stub.php", "<?php\necho 1;\n")
        self.write("unlisted.php", "<?php\necho 1;\n")
        self.commit()
        clover = self.clover("c.xml", {"src/app.php": {2: 1}})
        status, output = self.run_gate(clover)
        self.assertEqual(0, status, output)

    def test_changed_file_outside_path_globs_is_ignored(self):
        self.write("docs/notes.php", BASE_SOURCE.replace("$b = 2;", "$b = 5;"))
        self.commit()
        clover = self.clover("c.xml", {"src/app.php": {2: 1}})
        self.assertEqual(0, self.run_gate(clover)[0])

    def test_excluded_glob_is_ignored(self):
        self.change_line_three()
        clover = self.clover("c.xml", {"src/app.php": {3: 0}})
        self.assertEqual(0, self.run_gate(clover, excludes=("src/app.php",))[0])

    def test_non_executable_changed_line_passes(self):
        self.write("src/app.php", BASE_SOURCE + "// a comment\n")
        self.commit()
        clover = self.clover("c.xml", {"src/app.php": {2: 1, 3: 1, 4: 1}})
        self.assertEqual(0, self.run_gate(clover)[0])


if __name__ == "__main__":
    unittest.main()
