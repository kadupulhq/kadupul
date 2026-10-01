# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock

spec = importlib.util.spec_from_file_location('cdef_reference_runner', Path(__file__).with_name('run_cdef_reference_contracts.py'))
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class CdefReferenceRunnerTest(unittest.TestCase):
    def test_all_eleven_required_probes_exist_and_names_are_unique(self):
        self.assertEqual(11, len(runner.CASES))
        self.assertEqual(11, len({name for name, *_ in runner.CASES}))
        for _, probe, _, _ in runner.CASES:
            self.assertTrue((runner.ROOT / runner.probe_path(probe)).is_file())

    def test_missing_native_dsn_refuses_before_creating_any_output(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(runner.os.environ, {}, clear=True):
            output = Path(temporary) / 'proof'
            with self.assertRaisesRegex(RuntimeError, 'fixture DSN'):
                runner.run(output)
            self.assertFalse(output.exists())

    def test_existing_evidence_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(runner.os.environ, {
            'KADUPUL_REFERENCE_TEST_DSN': 'mysql:host=fixture', 'KADUPUL_REFERENCE_TEST_USER': 'fixture',
        }, clear=True):
            output = Path(temporary)
            (output / 'retained.log').write_text('original evidence')
            with self.assertRaisesRegex(RuntimeError, 'existing evidence is preserved'):
                runner.run(output)
            self.assertEqual('original evidence', (output / 'retained.log').read_text())

    def test_historical_schema_hash_is_checked_before_use(self):
        with patch.object(runner.subprocess, 'check_output', return_value=b'wrong historical source'):
            with self.assertRaisesRegex(RuntimeError, 'does not match'):
                runner.previous_schema(runner.ROOT)

    def test_missing_real_assets_refuses_candidate_preparation(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'source'
            root.mkdir()
            (root / 'file.php').write_text('<?php')
            with self.assertRaisesRegex(RuntimeError, 'Real dependencies and compiled assets'):
                runner.copy_candidate(root, Path(temporary) / 'candidate', {'file.php': 'fixture'})

    def test_native_failure_retains_exit_log_and_source_manifest_then_stops(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(runner.os.environ, {
            'KADUPUL_REFERENCE_TEST_DSN': 'mysql:host=fixture', 'KADUPUL_REFERENCE_TEST_USER': 'fixture',
        }, clear=True), patch.object(runner, 'source_manifest', return_value={'fixture.php': 'actual fixture hash'}), \
                patch.object(runner.shutil, 'which', return_value='/selected/php'), \
                patch.object(runner.subprocess, 'check_output', return_value='8.4.25'), \
                patch.object(runner, 'previous_schema', return_value=b'fixture'), \
                patch.object(runner, 'copy_candidate'), \
                patch.object(runner.subprocess, 'run', return_value=Mock(returncode=23)) as process:
            output = Path(temporary) / 'proof'
            with self.assertRaisesRegex(RuntimeError, 'actual native probe failed: api'):
                runner.run(output)
            self.assertEqual(1, process.call_count)
            report = json.loads((output / 'results.json').read_text())
            self.assertEqual(23, report['results'][0]['exit'])
            self.assertEqual({'fixture.php': 'actual fixture hash'}, report['source_sha256'])
            self.assertTrue((output / 'api.log').is_file())
            self.assertFalse((output / 'callers.log').exists())


if __name__ == '__main__':
    unittest.main()
