# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Verify the original producer's guest mutations and checked PHP byte handoff."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
PHP_VERSION = os.environ.get("KADUPUL_OFFLINE_FIXTURE_PHP_VERSION", "8.4.25")


sys.path.insert(0, str(ROOT / 'tests/Symfony'))
from offline_coverage import OfflineFixture


class OfflineFixtureMutationTest(unittest.TestCase):
    def test_guest_execution_passes_binary_stdin_and_independent_arguments(self):
        fixture = OfflineFixture(Path('/stage'), Path('/raw'), Path('/artifacts'), 'owned-runtime')
        with patch.object(subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=b'', stderr=b'')) as run:
            fixture.write_fixture("include/fa/font'quoted.woff2", b'\x00\xff')
            command = run.call_args.args[0]
            self.assertEqual(command[:5], ['docker', 'run', '--rm', '--interactive', '--network'])
            self.assertEqual(command[5], 'none')
            self.assertEqual(command[-3:], ["include/fa/font'quoted.woff2", '2', hashlib.sha256(b'\x00\xff').hexdigest()])
            self.assertEqual(run.call_args.kwargs['input'], b'\x00\xff')
            self.assertNotIn('text', run.call_args.kwargs)
            fixture.remove_fixture("include/fa/font'quoted.woff2")
            self.assertEqual(run.call_args.args[0][-1], "include/fa/font'quoted.woff2")
            self.assertNotIn('--interactive', run.call_args.args[0])

    def test_actual_php_writes_empty_and_binary_bytes_then_removes_exact_fixture(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'include/fa').mkdir(parents=True)
            fixture = OfflineFixture(Path('/stage'), Path('/raw'), Path('/artifacts'), 'owned-runtime')

            def execute(script, network='none', error=None, arguments=(), input_bytes=None):
                self.assertEqual(network, 'none')
                result = subprocess.run(['mise', 'exec', 'php@' + PHP_VERSION, '--', 'php', script, *arguments], cwd=root,
                                        input=input_bytes, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr.decode(errors='replace'))

            relative = "include/fa/font'quoted.woff2"
            with patch.object(fixture, 'execute', side_effect=execute):
                for data in [b'\x00\xfffont\xc3\xa9', b'']:
                    fixture.write_fixture(relative, data)
                    self.assertEqual((root / relative).read_bytes(), data)
                fixture.remove_fixture(relative)
                self.assertFalse((root / relative).exists())

    def test_actual_php_refuses_mismatched_length_and_checksum(self):
        fixture = OfflineFixture(Path('/stage'), Path('/raw'), Path('/artifacts'), 'owned-runtime')
        with patch.object(fixture, 'execute') as execute:
            fixture.write_fixture('font.bin', b'font')
        arguments = execute.call_args.kwargs['arguments']
        with tempfile.TemporaryDirectory() as directory:
            for size, checksum in [('3', hashlib.sha256(b'font').hexdigest()), ('4', '0' * 64)]:
                result = subprocess.run(['mise', 'exec', 'php@' + PHP_VERSION, '--', 'php', '-d', 'zend.exception_ignore_args=1',
                                         '-r', arguments[0], 'font.bin', size, checksum], cwd=directory,
                                        input=b'font', capture_output=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(b'Offline fixture bytes were not confirmed', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
