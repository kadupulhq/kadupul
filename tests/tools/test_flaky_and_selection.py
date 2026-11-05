# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Exercise tests/tools/flaky_report.py and select_tests.py on real files and a real repository."""
import contextlib
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/tools'))
import flaky_report  # noqa: E402
import select_tests  # noqa: E402


def junit(cases):
    """A JUnit report shaped like Pest's: one testcase per (file, name, result)."""
    body = []
    for file, name, result in cases:
        inner = {'passed': '', 'failed': '<failure>boom</failure>', 'error': '<error>boom</error>', 'skipped': '<skipped/>'}[result]
        body.append(f'<testcase name="{name}" file="{file}" class="X">{inner}</testcase>')
    return '<?xml version="1.0"?><testsuites><testsuite name="all">' + ''.join(body) + '</testsuite></testsuites>'


def capture(main, argv):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        status = main(argv)
    return status, out.getvalue(), err.getvalue()


class FlakyReportTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.directory.cleanup()

    def report(self, name, cases):
        path = Path(self.directory.name, name)
        path.write_text(junit(cases))
        return str(path)

    def test_mixed_results_are_flaky_and_constant_failures_are_broken(self):
        stable = [('A.php', 'stable', 'passed'), ('A.php', 'skipped', 'skipped'), ('B.php', 'broken', 'failed')]
        first = self.report('one.xml', stable + [('C.php', 'order dependent', 'passed'), ('C.php', 'errors once', 'passed')])
        second = self.report('two.xml', stable + [('C.php', 'order dependent', 'failed'), ('C.php', 'errors once', 'error')])
        status, out, err = capture(flaky_report.main, ['--labels', 'file-order,random-41', first, second])
        self.assertEqual(status, 1, err)
        self.assertIn('5 tests compared across 2 runs: 2 flaky, 1 failing in every run.', out)
        self.assertIn('| C.php::order dependent | passed | failed |', out)
        self.assertIn('| C.php::errors once | passed | failed |', out)
        self.assertIn('- B.php::broken', out)
        self.assertNotIn('stable', out)

    def test_identical_passing_runs_are_clean(self):
        cases = [('A.php', 'one', 'passed'), ('A.php', 'two', 'skipped')]
        status, out, _ = capture(flaky_report.main, [self.report('a.xml', cases), self.report('b.xml', cases), self.report('c.xml', cases)])
        self.assertEqual(status, 0)
        self.assertIn('2 tests compared across 3 runs: 0 flaky, 0 failing in every run.', out)

    def test_unusable_reports_are_tool_errors(self):
        good = self.report('good.xml', [('A.php', 'one', 'passed')])
        cases = {
            'one report': [good],
            'missing file': [good, str(Path(self.directory.name, 'absent.xml'))],
            'empty suite': [good, self.report('empty.xml', [])],
            'different tests': [good, self.report('other.xml', [('A.php', 'renamed', 'passed')])],
        }
        broken = Path(self.directory.name, 'broken.xml')
        broken.write_text('<testsuites><testcase')
        cases['malformed XML'] = [good, str(broken)]
        for label, reports in cases.items():
            with self.subTest(label):
                status, out, err = capture(flaky_report.main, reports)
                self.assertEqual(status, 2, out)
                self.assertIn('ERROR:', err)


class SelectTestsTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        environment = dict(os.environ, GIT_AUTHOR_NAME='A', GIT_AUTHOR_EMAIL='a@example.org', GIT_COMMITTER_NAME='A',
                           GIT_COMMITTER_EMAIL='a@example.org', GIT_CONFIG_GLOBAL='/dev/null')
        self.git = lambda *arguments: subprocess.run(['git', '-C', str(self.root), *arguments], check=True,
                                                    capture_output=True, text=True, env=environment)
        files = {
            'poller.php': '<?php // top-level poller',
            'lib/poller.php': '<?php // library',
            'lib/other.php': '<?php // other',
            'tests/Unit/TopPollerTest.php': "<?php $s = file_get_contents(dirname(__DIR__, 2) . '/poller.php');",
            'tests/Unit/LibPollerTest.php': "<?php require dirname(__DIR__, 2) . '/lib/poller.php';",
            'tests/Unit/UnrelatedTest.php': '<?php // nothing',
            'tests/Unit/DatabaseTest.php': "<?php require 'lib/other.php';",
            'tests/phpunit-spikekill.xml': '<phpunit><testsuites><testsuite name="s"><file>Unit/TopPollerTest.php</file>'
                                           '<file>./Unit/LibPollerTest.php</file><file>Unit/UnrelatedTest.php</file></testsuite></testsuites></phpunit>',
            'tests/phpunit-database.xml': '<phpunit><testsuites><testsuite name="d"><file>Unit/DatabaseTest.php</file></testsuite></testsuites></phpunit>',
        }
        for path, content in files.items():
            Path(self.root, path).parent.mkdir(parents=True, exist_ok=True)
            Path(self.root, path).write_text(content)
        self.git('init', '-q', '-b', 'main')
        self.git('add', '.')
        self.git('commit', '-q', '-m', 'base')

    def tearDown(self):
        self.directory.cleanup()

    def change(self, *paths):
        for path in paths:
            Path(self.root, path).write_text(Path(self.root, path).read_text() + '\n// changed')
        self.git('commit', '-qam', 'change')

    def test_a_library_change_selects_only_tests_that_load_it(self):
        self.change('lib/poller.php')
        status, out, err = capture(select_tests.main, ['--root', str(self.root), 'HEAD~1'])
        self.assertEqual(status, 0, err)
        self.assertIn('1 of 4 registered test files refer to the 1 changed files:', out)
        self.assertIn('Unit/LibPollerTest.php  (lib/poller.php)', out)
        self.assertNotIn('TopPollerTest', out)                   # '/lib/poller.php' is not the top-level poller.php
        self.assertIn('--configuration=phpunit-spikekill.xml Unit/LibPollerTest.php', out)

    def test_a_top_level_change_and_a_changed_test_are_selected_per_configuration(self):
        self.change('poller.php', 'lib/other.php', 'tests/Unit/UnrelatedTest.php')
        status, out, _ = capture(select_tests.main, ['--root', str(self.root), 'HEAD~1'])
        self.assertEqual(status, 0)
        self.assertIn('Unit/TopPollerTest.php  (poller.php)', out)
        self.assertIn('Unit/UnrelatedTest.php  (tests/Unit/UnrelatedTest.php)', out)
        self.assertIn('Unit/DatabaseTest.php  (lib/other.php)', out)
        self.assertNotIn('LibPollerTest', out)
        self.assertIn('--configuration=phpunit-database.xml Unit/DatabaseTest.php', out)

    def test_an_unknown_base_is_an_error(self):
        status, _, err = capture(select_tests.main, ['--root', str(self.root), 'no-such-ref'])
        self.assertEqual(status, 2)
        self.assertIn('git diff against no-such-ref failed', err)


if __name__ == '__main__':
    unittest.main()
