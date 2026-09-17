"""Regression checks for the all-files runner's fail-closed result handling."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET


class RunnerTest(unittest.TestCase):
    @unittest.skipUnless(os.name == 'posix', 'POSIX process-group exit race')
    def test_timeout_exit_race_is_recorded_and_next_process_runs(self):
        import importlib.util
        from unittest.mock import Mock, patch
        spec = importlib.util.spec_from_file_location('runner_under_test', Path(__file__).resolve().parents[1] / 'run_unit_suite.py')
        runner = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runner)
        expired = Mock(pid=12345, returncode=0)
        expired.wait.side_effect = [subprocess.TimeoutExpired(['fixture'], 0.1), 0]
        next_process = Mock(returncode=0)
        next_process.wait.return_value = 0
        with tempfile.TemporaryDirectory() as directory, patch.object(runner.subprocess, 'Popen', side_effect=[expired, next_process]), patch.object(runner.os, 'killpg', side_effect=ProcessLookupError()):
            root = Path(directory)
            self.assertEqual(runner.run_file(['fixture'], root, root / 'expired.log', 0.1), (0, True))
            self.assertEqual(runner.run_file(['fixture'], root, root / 'next.log', 0.1), (0, False))
        self.assertEqual(expired.wait.call_count, 2)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / 'Unit').mkdir()
        shutil.copy(Path(__file__).resolve().parents[1] / 'run_unit_suite.py', self.root / 'runner.py')
        self.php = self.root / 'fake-php'
        self.php.write_text('#!' + sys.executable + '\n' + '''
import sys, time
from pathlib import Path
report = Path(next(x.split('=', 1)[1] for x in sys.argv if x.startswith('--log-junit=')))
mode = Path(sys.argv[-1]).read_text()
if mode == 'timeout': time.sleep(10)
if mode == 'missing': sys.exit(0)
if mode == 'crash': sys.exit(255)
if mode == 'malformed': report.write_text('<'); sys.exit(0)
shapes = {
    'wrapped-failure': '<testsuites><testsuite><testcase name="contract"><wrapper><failure/></wrapper></testcase></testsuite></testsuites>',
    'suite-error': '<testsuites><testsuite><error/><testcase name="contract"/></testsuite></testsuites>',
    'nested-status': '<testsuite><testcase name="contract"><skipped><failure/></skipped></testcase></testsuite>',
    'bad-count': '<testsuite failures="1"><testcase name="contract"/></testsuite>',
    'negative-count': '<testsuite tests="-1"><testcase name="contract"/></testsuite>',
    'missing-name': '<testsuite><testcase/></testsuite>',
    'conflicting-status': '<testsuite><testcase name="contract"><skipped/><failure/></testcase></testsuite>',
    'metadata': '<testsuite tests="1" failures="0"><properties><property name="runtime" value="test"/></properties><testcase name="contract"><system-out>output</system-out></testcase></testsuite>',
    'wrong-root': '<not-junit><testcase/></not-junit>',
    'orphan-case': '<testsuites><testcase/></testsuites>',
    'wrapped-case': '<testsuites><testsuite><wrapper><testcase/></wrapper></testsuite></testsuites>',
    'wrapped-suite': '<testsuites><wrapper><testsuite><testcase/></testsuite></wrapper></testsuites>',
    'nested-collection': '<testsuites><testsuite><testsuites><testsuite><testcase/></testsuite></testsuites></testsuite></testsuites>',
    'single-suite': '<testsuite><testcase name="contract"/></testsuite>',
    'nested-suite': '<testsuites><testsuite><testsuite><testcase name="contract"/></testsuite></testsuite></testsuites>',
}
if mode in shapes: report.write_text(shapes[mode]); sys.exit(0)
if mode == 'empty': report.write_text('<testsuites/>'); sys.exit(0)
case = '<skipped/>' if mode == 'skipped' else '<failure/>' if mode == 'failure' else ''
report.write_text('<testsuites><testsuite><testcase name="contract">' + case + '</testcase></testsuite></testsuites>')
if mode == 'risky' and '--fail-on-risky' in sys.argv: sys.exit(1)
if mode == 'warning' and '--fail-on-warning' in sys.argv: sys.exit(1)
''')
        self.php.chmod(0o700)

    def tearDown(self):
        self.temp.cleanup()

    def run_suite(self, mode):
        (self.root / 'Unit' / 'FirstTest.php').write_text(mode)
        (self.root / 'Unit' / 'SecondTest.php').write_text('passed')
        result = subprocess.run([sys.executable, str(self.root / 'runner.py'), '--php', str(self.php),
                                 '--timeout', '0.5', '--output', str(self.root / 'results')],
                                cwd=self.root, capture_output=True, text=True, timeout=5)
        results = json.loads((self.root / 'results/results.json').read_text())
        self.assertEqual(len(results), 2, 'Must continue to the next file after a failure')
        self.assertTrue(results[1]['passed'])
        return result.returncode, results[0]

    def test_success_and_skips_have_valid_reports(self):
        for mode in ['passed', 'skipped', 'single-suite', 'nested-suite', 'metadata']:
            with self.subTest(mode=mode):
                status, result = self.run_suite(mode)
                self.assertEqual(status, 0)
                self.assertEqual(result['tests'], 1)
                combined = ET.parse(self.root / 'results/junit.xml').getroot()
                self.assertEqual(len(list(combined.iter('testcase'))), 2)
                self.assertEqual(result['skipped'], int(mode == 'skipped'))

    def test_missing_empty_malformed_and_failure_reports_fail_closed(self):
        for mode in ['missing', 'empty', 'malformed', 'failure', 'crash', 'timeout', 'wrong-root', 'orphan-case', 'wrapped-case', 'wrapped-suite', 'nested-collection', 'risky', 'warning', 'wrapped-failure', 'suite-error', 'nested-status', 'bad-count', 'negative-count', 'missing-name', 'conflicting-status']:
            with self.subTest(mode=mode):
                status, result = self.run_suite(mode)
                self.assertEqual(status, 1)
                self.assertFalse(result['passed'])
                if mode == 'timeout':
                    self.assertTrue(result['timeout'])


if __name__ == '__main__':
    unittest.main()
