"""Behavioral contracts for the test entrypoint, independent of vendor installs.

SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class SuiteRunnerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="kadupul-suite-runner-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.runner = self.root / "tests/bin/run"
        self.runner.parent.mkdir(parents=True)
        shutil.copyfile(Path(__file__).resolve().parents[1] / "bin/run", self.runner)
        self.runner.chmod(0o755)
        self.environment = dict(os.environ)
        self.environment["PATH"] = str(self.root / "bin") + os.pathsep + os.environ["PATH"]
        self.environment["RUNNER_RECEIPT"] = str(self.root / "receipt.json")
        self.environment["RUNNER_PYTHON"] = os.sys.executable
        self.fixture("bin/mise", '''#!/bin/sh
exec "$RUNNER_PYTHON" -c 'import json,os,sys; json.dump({"argv":sys.argv[1:],"cwd":os.getcwd()},open(os.environ["RUNNER_RECEIPT"],"w")); sys.exit(int(os.environ.get("RUNNER_EXIT", "0")))' "$@"
''')
        (self.root / "bin/mise").chmod(0o755)

    def fixture(self, path, content=""):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def run_suite(self, *arguments):
        return subprocess.run([str(self.runner), *arguments], cwd=self.root.parent,
                              env=self.environment, capture_output=True, text=True)

    def receipt(self):
        return json.loads((self.root / "receipt.json").read_text())

    def test_help_and_unknown_suite(self):
        self.assertEqual(self.run_suite("help").returncode, 0)
        result = self.run_suite("unknown")
        self.assertEqual(result.returncode, 2)
        self.assertIn("Unknown suite", result.stderr)
        self.assertFalse((self.root / "receipt.json").exists())

    def test_missing_dependencies_and_empty_javascript_fail_before_runtime(self):
        for suite in ("symfony", "legacy", "database", "unit-coverage", "themes", "javascript"):
            with self.subTest(suite=suite):
                result = self.run_suite(suite)
                self.assertEqual(result.returncode, 2)
                self.assertIn("Missing", result.stderr)
                self.assertFalse((self.root / "receipt.json").exists())

    def test_php_frameworks_configs_and_empty_suite_guard(self):
        self.fixture("include/vendor/bin/phpunit")
        self.fixture("tests/vendor/bin/pest")
        for suite, config in (("symfony", "phpunit-symfony.xml"),
                              ("legacy", "phpunit-spikekill.xml"),
                              ("database", "phpunit-database.xml"),
                              ("unit-coverage", "phpunit-coverage.xml")):
            self.fixture(("" if suite == "symfony" else "tests/") + config)
            with self.subTest(suite=suite):
                self.assertEqual(self.run_suite(suite, "--filter", "case with spaces;$HOME").returncode, 0)
                receipt = self.receipt()
                argv = receipt["argv"]
                self.assertEqual(argv[:3], ["exec", "--", "php"])
                self.assertEqual(argv[3], "include/vendor/bin/phpunit" if suite == "symfony" else "vendor/bin/pest")
                self.assertIn(config, argv)
                self.assertIn("--fail-on-empty-test-suite", argv)
                self.assertEqual(argv[-2:], ["--filter", "case with spaces;$HOME"])
                self.assertEqual(receipt["cwd"], str(self.root if suite == "symfony" else self.root / "tests"))

    def test_javascript_discovery_and_child_failure_propagation(self):
        self.fixture("tests/Unit/first.test.mjs")
        self.fixture("tests/Unit/second.test.mjs")
        self.fixture("tests/Unit/fixture.mjs")
        self.environment["RUNNER_EXIT"] = "7"
        self.assertEqual(self.run_suite("javascript", "--test-name-pattern=two words").returncode, 7)
        self.assertEqual(self.receipt()["argv"], ["exec", "--", "node", "--test",
                         "--test-name-pattern=two words", "tests/Unit/first.test.mjs", "tests/Unit/second.test.mjs"])

    def test_theme_config_is_explicit_and_arguments_are_preserved(self):
        self.fixture("tests/e2e/node_modules/@playwright/test/cli.js")
        self.assertEqual(self.run_suite("themes", "--list").returncode, 0)
        self.assertEqual(self.receipt()["argv"], ["exec", "--", "npm", "run", "test:themes", "--",
                         "--config=playwright.config.js", "--list"])
        self.assertEqual(self.receipt()["cwd"], str(self.root / "tests/e2e"))


if __name__ == "__main__":
    unittest.main()
