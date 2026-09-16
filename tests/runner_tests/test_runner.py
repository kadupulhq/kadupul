"""Regression checks for the all-files runner's fail-closed result handling."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


class RunnerTest(unittest.TestCase):
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
if mode == 'empty': report.write_text('<testsuites/>'); sys.exit(0)
case = '<skipped/>' if mode == 'skipped' else '<failure/>' if mode == 'failure' else ''
report.write_text('<testsuites><testsuite><testcase name="contract">' + case + '</testcase></testsuite></testsuites>')
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
        for mode in ['passed', 'skipped']:
            with self.subTest(mode=mode):
                status, result = self.run_suite(mode)
                self.assertEqual(status, 0)
                self.assertEqual(result['tests'], 1)
                self.assertEqual(result['skipped'], int(mode == 'skipped'))

    def test_missing_empty_malformed_and_failure_reports_fail_closed(self):
        for mode in ['missing', 'empty', 'malformed', 'failure', 'crash', 'timeout']:
            with self.subTest(mode=mode):
                status, result = self.run_suite(mode)
                self.assertEqual(status, 1)
                self.assertFalse(result['passed'])
                if mode == 'timeout':
                    self.assertTrue(result['timeout'])


if __name__ == '__main__':
    unittest.main()
