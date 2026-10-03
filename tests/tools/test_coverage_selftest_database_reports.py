# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Negative coverage probes retain separate real database measurements."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('coverage_selftest', ROOT / 'tests/Symfony/coverage_selftest.py')
SELFTEST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SELFTEST)
SOURCE = '/var/www/html/src/IdentityAccess/Infrastructure/Legacy/AuthenticationDatabaseSessionHandler.php'
OBSERVER = '/var/www/html/lib/api_device.php'
OTHER_IMPORTED = '/var/www/html/src/IdentityAccess/Infrastructure/Legacy/SharedSession.php'


class DatabaseReportPreservationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name) / 'database'
        self.scratch = Path(self.temporary.name) / 'scratch'
        (self.directory / 'raw').mkdir(parents=True)
        (self.scratch / 'raw').mkdir(parents=True)
        self.reports = {
            'coverage-first.json': {'php': '8.4.25', 'files': {
                SOURCE: {'sha256': 'a' * 64, 'lines': {'10': 0, '11': -1}},
                OBSERVER: {'sha256': 'b' * 64, 'lines': {'20': 1}},
                OTHER_IMPORTED: {'sha256': 'c' * 64, 'lines': {'30': 1}},
            }},
            'coverage-second.json': {'php': '8.4.25', 'files': {
                SOURCE: {'sha256': 'a' * 64, 'lines': {'10': 1, '11': 0}},
                OBSERVER: {'sha256': 'd' * 64, 'lines': {'20': 1}},
                OTHER_IMPORTED: {'sha256': 'e' * 64, 'lines': {'30': 1}},
            }},
            'coverage-without-target.json': {'php': '8.4.25', 'files': None},
        }
        self.write_reports()

    def write_reports(self):
        for name, report in self.reports.items():
            (self.directory / 'raw' / name).write_text(json.dumps(report, indent=2) + '\n')

    def test_dynamic_helper_import_has_no_cli_scenario_dependency(self):
        code = '''import importlib.util, sys
original_path = list(sys.path)
spec = importlib.util.spec_from_file_location('coverage_selftest', sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
assert sys.path == original_path
assert 'cdef_legacy_page_scenarios' not in sys.modules
assert callable(module.prepare_database_failure_reports)
'''
        result = subprocess.run(
            [sys.executable, '-c', code, str(ROOT / 'tests/Symfony/coverage_selftest.py')],
            cwd=self.temporary.name, capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(0, result.returncode, result.stderr)

    def test_each_mutation_preserves_all_reports_and_unrelated_hash_variants(self):
        original = {path.name: path.read_bytes() for path in (self.directory / 'raw').iterdir()}
        for mutation in ('unmeasured', 'stale'):
            with self.subTest(mutation=mutation):
                SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, mutation)
                self.assertEqual(set(self.reports), {path.name for path in (self.scratch / 'raw').iterdir()})
                for name, report in self.reports.items():
                    expected = copy.deepcopy(report)
                    observation = (expected['files'] or {}).get(SOURCE)
                    if observation is not None:
                        if mutation == 'unmeasured':
                            observation['lines'] = {line: -1 for line in observation['lines']}
                        else:
                            observation['sha256'] = '0' * 64
                    destination = self.scratch / 'raw' / name
                    self.assertEqual(expected, json.loads(destination.read_text()))
                    if observation is None:
                        self.assertEqual(original[name], destination.read_bytes())
                    self.assertEqual(original[name], (self.directory / 'raw' / name).read_bytes())

    def test_missing_target_refuses_before_copying_reports(self):
        with self.assertRaisesRegex(RuntimeError, 'requires real database-session'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE + '.missing', 'stale')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_target_without_positive_execution_refuses_before_copying_reports(self):
        self.reports['coverage-second.json']['files'][SOURCE]['lines'] = {'10': 0, '11': -1}
        self.write_reports()
        with self.assertRaisesRegex(RuntimeError, 'requires real database-session'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unmeasured')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_empty_measurements_refuse(self):
        for path in (self.directory / 'raw').iterdir():
            path.unlink()
        with self.assertRaisesRegex(RuntimeError, 'requires real database-session'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')

    def test_unknown_mutation_refuses_before_copying_reports(self):
        with self.assertRaisesRegex(ValueError, 'Unknown database measurement mutation'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unknown')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_changed_input_set_rejects_stale_scratch_reports_before_writes(self):
        SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unmeasured')
        previous = {path.name: path.read_bytes() for path in (self.scratch / 'raw').iterdir()}
        (self.directory / 'raw' / 'coverage-without-target.json').unlink()
        with self.assertRaisesRegex(RuntimeError, 'Unexpected scratch coverage reports'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')
        self.assertEqual(previous, {path.name: path.read_bytes() for path in (self.scratch / 'raw').iterdir()})

    def test_scratch_symlink_cannot_redirect_a_target_report_write(self):
        destination = self.scratch / 'raw' / 'coverage-first.json'
        original = self.directory / 'raw' / 'coverage-first.json'
        original_bytes = original.read_bytes()
        destination.symlink_to(original)
        with self.assertRaisesRegex(RuntimeError, 'Unexpected scratch coverage reports'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')
        self.assertEqual(original_bytes, original.read_bytes())


if __name__ == '__main__':
    unittest.main()
