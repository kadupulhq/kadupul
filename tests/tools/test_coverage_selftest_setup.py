# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Diagnostic setup tests; synthetic records are never execution evidence."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('coverage_selftest', Path(__file__).resolve().parents[1] / 'Symfony/coverage_selftest.py')
selftest = importlib.util.module_from_spec(spec)
spec.loader.exec_module(selftest)


class CoverageSelftestSetup(unittest.TestCase):
    def test_incomplete_measurements_fail_closed_without_uninitialized_cases(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'raw').mkdir()
            (root / 'observations.json').write_text('{}')
            (root / 'raw/coverage-empty.json').write_text('{"files": {}}')
            with patch('sys.argv', ['coverage_selftest', '--unit', str(root), '--files', str(root), '--database', str(root), '--offline', str(root)]):
                with self.assertRaisesRegex(RuntimeError, 'requires real HTTP and worker measurements'):
                    selftest.main()

    def test_measurements_accumulate_disjoint_reports(self):
        with tempfile.TemporaryDirectory() as directory:
            raw = Path(directory)
            first = {'sha256': 'a' * 64, 'lines': {'1': 1}}
            second = {'sha256': 'b' * 64, 'lines': {'2': 1}}
            (raw / 'coverage-one.json').write_text(json.dumps({'files': {'/first.php': first}}))
            (raw / 'coverage-two.json').write_text(json.dumps({'files': {'/second.php': second}}))
            self.assertEqual({'/first.php': first, '/second.php': second}, selftest.measured_sources(raw, ['/first.php', '/second.php']))


if __name__ == '__main__':
    unittest.main()
