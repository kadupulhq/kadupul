# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise the real CI launcher with two isolated child producers."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SessionCoverageTest(unittest.TestCase):
    def run_producers(self, failed):
        with tempfile.TemporaryDirectory() as directory:
            scratch = Path(directory)
            launcher = scratch / 'mise'
            launcher.write_text(f'#!{sys.executable}\n' + '''
import json
import os
from pathlib import Path
import sys
import time

mode = 'database' if '--database-sessions' in sys.argv else 'files'
scratch = Path(os.environ['RUNNER_TEMP'])
(scratch / (mode + '.started')).write_text(json.dumps(sys.argv[1:]))
deadline = time.monotonic() + 5
while not all((scratch / (name + '.started')).exists() for name in ('files', 'database')):
    if time.monotonic() > deadline:
        raise SystemExit('producers did not overlap')
    time.sleep(0.01)
if mode == 'database':
    time.sleep(0.1)
(scratch / (mode + '.finished')).touch()
raise SystemExit(7 if mode in os.environ['FAILED_MODES'].split(',') else 0)
''')
            launcher.chmod(0o700)
            environment = dict(os.environ, PATH=str(scratch) + os.pathsep + os.environ['PATH'],
                               RUNNER_TEMP=str(scratch), GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='2',
                               FAILED_MODES=','.join(failed))
            result = subprocess.run(['bash', str(ROOT / 'tests/Symfony/session_coverage.sh')],
                                    cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(1 if failed else 0, result.returncode, result.stderr)
            for mode in ('files', 'database'):
                self.assertTrue((scratch / (mode + '.finished')).exists(), result.stderr)
                arguments = json.loads((scratch / (mode + '.started')).read_text())
                expected = ['exec', 'python@3.12.12', '--', 'python', 'tests/Symfony/session_bridge.py',
                            '--project', f'kadupul-sonar-123-2-{mode}']
                if mode == 'database':
                    expected.append('--database-sessions')
                expected.extend(['--coverage-output', str(scratch / ('symfony-' + mode))])
                self.assertEqual(expected, arguments)

    def test_both_complete_runs_overlap_and_succeed(self):
        self.run_producers(())

    def test_files_failure_waits_for_database_cleanup_and_fails(self):
        self.run_producers(('files',))

    def test_database_failure_is_not_masked_by_files_success(self):
        self.run_producers(('database',))

    def test_both_failures_fail_the_job(self):
        self.run_producers(('files', 'database'))


if __name__ == '__main__':
    unittest.main()
