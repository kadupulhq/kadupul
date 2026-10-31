# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Coverage manifests reach the real merger guards with installed PHP dependencies."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SourceFingerprintTest(unittest.TestCase):
    """Exercise the real publisher and merger before any measured-line import."""

    CLI_SOURCES = [
        'tests/Symfony/cli_user_scenarios.py',
        'tests/Symfony/cli_graph_scenarios.py',
        *[f'tests/Fixtures/legacy-cli/{name}.php' for name in (
            'copy_user', 'add_perms', 'poller_graphs_reapply_names',
            'repair_graphs', 'remove_broken_graphs', 'remove_graphs',
            'add_graph_template')],
    ]

    def test_published_sources_reach_check_guard_and_reject_each_missing_or_stale_cli_source(self):
        spec = importlib.util.spec_from_file_location('coverage_support', ROOT / 'tests/Symfony/coverage_support.py')
        publisher = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(publisher)
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / 'raw').mkdir()
            (directory / 'raw/coverage-probe.json').write_text('{}')
            unit = directory / 'unit.php'
            # Only the manifest guards run: no synthetic line measurements are imported.
            unit.write_text('<?php return (new ReflectionClass(\\SebastianBergmann\\CodeCoverage\\CodeCoverage::class))->newInstanceWithoutConstructor();')
            for database in (False, True):
                publisher.publish_coverage(directory, database, [])
                manifest = json.loads((directory / 'observations.json').read_text())
                self.assertEqual('database' if database else 'files', manifest['session_handler'])
                for source in self.CLI_SOURCES:
                    self.assertEqual(hashlib.sha256((ROOT / source).read_bytes()).hexdigest(),
                                     manifest['source_sha256'].get(source), source)
            publisher.publish_coverage(directory, False, [])
            original = json.loads((directory / 'observations.json').read_text())
            cases = [(None, None)] + [(source, mutation) for source in self.CLI_SOURCES
                                      for mutation in ('missing', 'stale')]
            for source, mutation in cases:
                with self.subTest(source=source, mutation=mutation):
                    manifest = copy.deepcopy(original)
                    if mutation == 'missing':
                        manifest['source_sha256'].pop(source)
                    elif mutation == 'stale':
                        manifest['source_sha256'][source] = '0' * 64
                    (directory / 'observations.json').write_text(json.dumps(manifest))
                    result = subprocess.run(
                        ['php', str(ROOT / 'tests/Symfony/merge_coverage.php'), str(unit),
                         str(directory), str(directory), str(directory), str(directory / 'out.xml')],
                        cwd=ROOT, capture_output=True, text=True, timeout=30)
                    self.assertNotEqual(0, result.returncode)
                    expected = 'Integration test source differs' if source else 'Incomplete Symfony integration'
                    self.assertIn(expected, result.stderr)
                    self.assertFalse((directory / 'out.xml').exists())


if __name__ == '__main__':
    unittest.main()
