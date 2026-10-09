#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise the real Pest runner: discovery parity, empty suites and failures."""

import argparse
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
PHP_VERSION = "8.4.25"


class PestRunnerTest(unittest.TestCase):
    def run_pest(self, *arguments):
        return subprocess.run(
            [sys.executable, str(ROOT / "tests/tools/run_pest.py"), *arguments,
             "--php-version", PHP_VERSION],
            cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            timeout=180,
        )

    def cases(self, report):
        return sorted((case.attrib["classname"], case.attrib["name"],
                       case.find("skipped") is not None)
                      for case in ET.parse(report).findall(".//testcase"))

    def test_serial_and_parallel_execute_identical_complete_inventory(self):
        with tempfile.TemporaryDirectory(prefix="kadupul-pest-runner-") as owned:
            outputs = []
            for mode, extra in [("profile", ["--parallel-suite"]), ("parallel", [])]:
                report = Path(owned) / (mode + ".xml")
                run = self.run_pest(mode, *extra, "--junit", str(report))
                self.assertEqual(run.returncode, 0, run.stdout)
                outputs.append(self.cases(report))
            self.assertEqual(outputs[0], outputs[1])
            inventory = ET.parse(ROOT / "tests/phpunit-parallel.xml").findall("./testsuites/testsuite/file")
            for entry in inventory:
                stem = Path(entry.text).stem
                self.assertTrue(any(stem in classname for classname, _, _ in outputs[0]), stem)
            numeric = [case for case in outputs[0] if "GraphItemNumericValueTest" in case[0]]
            self.assertEqual(len(numeric), 13)
            self.assertFalse(any(skipped for _, _, skipped in numeric))

    def test_filtered_profile_keeps_every_numeric_boundary(self):
        with tempfile.TemporaryDirectory(prefix="kadupul-pest-profile-") as owned:
            report = Path(owned) / "profile.xml"
            run = self.run_pest("profile", "--parallel-suite", "--filter",
                                "graph items accept exactly one numeric argument", "--junit", str(report))
            self.assertEqual(run.returncode, 0, run.stdout)
            self.assertEqual(len(self.cases(report)), 13)
            self.assertIn("Top 10 slowest tests", run.stdout)

    def test_empty_suite_is_not_success(self):
        for mode, extra in [("profile", ["--parallel-suite"]), ("parallel", [])]:
            with self.subTest(mode=mode):
                run = self.run_pest(mode, *extra, "--filter", "__kadupul_no_such_test__")
                self.assertNotEqual(run.returncode, 0, run.stdout)
                self.assertIn("No tests found", run.stdout)

    def test_partial_parallel_coverage_is_refused(self):
        run = self.run_pest("parallel", "--with-coverage")
        self.assertEqual(run.returncode, 2, run.stdout)
        self.assertIn("full serial coverage suite", run.stdout)

    def test_invalid_worker_count_and_empty_filter_are_refused(self):
        for arguments in [("--processes", "0"), ("--processes", "9"), ("--filter", "")]:
            with self.subTest(arguments=arguments):
                self.assertEqual(self.run_pest("parallel", *arguments).returncode, 2)

    def test_existing_evidence_is_not_overwritten(self):
        with tempfile.TemporaryDirectory(prefix="kadupul-pest-retained-") as owned:
            report = Path(owned) / "original.xml"
            report.write_text("retained earlier evidence")
            run = self.run_pest("profile", "--junit", str(report))
            self.assertEqual(run.returncode, 2)
            self.assertEqual(report.read_text(), "retained earlier evidence")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--php-version", default=PHP_VERSION)
    args = parser.parse_args()
    PHP_VERSION = args.php_version
    unittest.main(argv=[sys.argv[0]])
