# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Verify the original producer's guest mutations and checked PHP byte handoff."""
import ast
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
PHP_VERSION = os.environ.get("KADUPUL_OFFLINE_FIXTURE_PHP_VERSION", "8.4.25")


def producer_functions(**bindings):
    tree = ast.parse((ROOT / 'tests/Symfony/offline_coverage.py').read_text())
    names = {'execute', 'remove_fixture', 'write_fixture'}
    nodes = [node for node in ast.walk(tree) if isinstance(node, ast.FunctionDef) and node.name in names]
    if {node.name for node in nodes} != names:
        raise AssertionError('The producer must mutate fixtures through its checked guest helpers')
    scope = {'subprocess': subprocess, 'os': __import__('os'), 'hashlib': hashlib, 'ROOT': ROOT, **bindings}
    exec(compile(ast.Module(body=nodes, type_ignores=[]), 'offline_coverage.py', 'exec'), scope)
    return scope


class OfflineFixtureMutationTest(unittest.TestCase):
    def test_guest_execution_passes_binary_stdin_and_independent_arguments(self):
        scope = producer_functions(stage=Path('/stage'), raw=Path('/raw'), diagnostics=Path('/artifacts'), args=SimpleNamespace(image='owned-runtime'))
        with patch.object(subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=b'', stderr=b'')) as run:
            scope['write_fixture']("include/fa/font'quoted.woff2", b'\x00\xff')
            command = run.call_args.args[0]
            self.assertEqual(command[:5], ['docker', 'run', '--rm', '--interactive', '--network'])
            self.assertEqual(command[5], 'none')
            self.assertEqual(command[-3:], ["include/fa/font'quoted.woff2", '2', hashlib.sha256(b'\x00\xff').hexdigest()])
            self.assertEqual(run.call_args.kwargs['input'], b'\x00\xff')
            self.assertNotIn('text', run.call_args.kwargs)
            scope['remove_fixture']("include/fa/font'quoted.woff2")
            self.assertEqual(run.call_args.args[0][-1], "include/fa/font'quoted.woff2")
            self.assertNotIn('--interactive', run.call_args.args[0])

    def test_actual_php_writes_empty_and_binary_bytes_then_removes_exact_fixture(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'include/fa').mkdir(parents=True)
            scope = producer_functions()

            def execute(script, network='none', error=None, arguments=(), input_bytes=None):
                self.assertEqual(network, 'none')
                result = subprocess.run(['mise', 'exec', 'php@' + PHP_VERSION, '--', 'php', script, *arguments], cwd=root,
                                        input=input_bytes, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr.decode(errors='replace'))

            scope['execute'] = execute
            relative = "include/fa/font'quoted.woff2"
            for data in [b'\x00\xfffont\xc3\xa9', b'']:
                scope['write_fixture'](relative, data)
                self.assertEqual((root / relative).read_bytes(), data)
            scope['remove_fixture'](relative)
            self.assertFalse((root / relative).exists())

    def test_actual_php_refuses_mismatched_length_and_checksum(self):
        scope = producer_functions()
        captured = []
        scope['execute'] = lambda *args, **kwargs: captured.append((args, kwargs))
        scope['write_fixture']('font.bin', b'font')
        arguments = captured[0][1]['arguments']
        with tempfile.TemporaryDirectory() as directory:
            for size, checksum in [('3', hashlib.sha256(b'font').hexdigest()), ('4', '0' * 64)]:
                result = subprocess.run(['mise', 'exec', 'php@' + PHP_VERSION, '--', 'php', '-d', 'zend.exception_ignore_args=1',
                                         '-r', arguments[0], 'font.bin', size, checksum], cwd=directory,
                                        input=b'font', capture_output=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(b'Offline fixture bytes were not confirmed', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
