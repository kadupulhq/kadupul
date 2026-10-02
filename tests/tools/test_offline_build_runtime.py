# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Resolve npm from the selected Node installation, never a PATH wrapper."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import subprocess
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('offline_builder', ROOT / 'tools/build-offline.py')
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class OfflineBuildRuntimeTest(unittest.TestCase):
    def test_selected_node_cli_ignores_shell_and_javascript_path_launchers(self):
        for launcher in ('#!/bin/bash\nset -euo pipefail\n', '#!/usr/bin/env node\n'):
            with self.subTest(launcher=launcher), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                node = root / 'node/bin/node'
                node.parent.mkdir(parents=True)
                node.write_text('selected node')
                cli = root / 'node/lib/node_modules/npm/bin/npm-cli.js'
                cli.parent.mkdir(parents=True)
                cli.write_text('#!/usr/bin/env node\n')
                npm = root / 'other/bin/npm'
                npm.parent.mkdir(parents=True)
                npm.write_text(launcher)
                executables = {'node': str(node), 'php': '/php', 'composer': '/composer', 'npm': str(npm)}
                with patch.object(BUILDER.shutil, 'which', side_effect=executables.get):
                    self.assertEqual(('/php', str(node), '/composer', str(cli.resolve())), BUILDER.build_runtimes())

    def test_missing_adjacent_cli_refuses_unknown_path_npm(self):
        with tempfile.TemporaryDirectory() as temporary:
            node = Path(temporary) / 'bin/node'
            node.parent.mkdir()
            node.write_text('selected node')
            with self.assertRaisesRegex(RuntimeError, 'missing its npm CLI'):
                BUILDER.npm_cli_path(node)

    def test_windows_installation_layout(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            node = root / 'node.exe'
            node.write_text('selected node')
            cli = root / 'node_modules/npm/bin/npm-cli.js'
            cli.parent.mkdir(parents=True)
            cli.write_text('#!/usr/bin/env node\n')
            self.assertEqual(str(cli.resolve()), BUILDER.npm_cli_path(node))

    def test_mise_selected_node_overrides_path_node_and_npm(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            node = root / 'node/bin/node'
            node.parent.mkdir(parents=True)
            node.write_text('selected node')
            cli = root / 'node/lib/node_modules/npm/bin/npm-cli.js'
            cli.parent.mkdir(parents=True)
            cli.write_text('#!/usr/bin/env node\n')
            php = root / 'php'
            php.write_text('selected php')
            executables = {'node': '/wrong/node', 'php': '/wrong/php', 'composer': '/composer', 'npm': '/wrong/npm', 'mise': '/mise'}
            def selected(command, **kwargs):
                return type('Result', (), {'returncode': 0, 'stdout': str(php if command[-1] == 'php' else node) + '\n'})()
            with patch.object(BUILDER.shutil, 'which', side_effect=executables.get), patch.object(BUILDER.subprocess, 'run', side_effect=selected):
                self.assertEqual((str(php), str(node), '/composer', str(cli.resolve())), BUILDER.build_runtimes())

    def test_real_node_refuses_shell_wrapper_and_runs_its_adjacent_npm_cli(self):
        node = subprocess.check_output(['mise', 'which', 'node'], text=True).strip()
        self.assertTrue(Path(node).is_file(), 'Run this regression through mise with the selected Node runtime.')
        with tempfile.TemporaryDirectory() as temporary:
            wrapper = Path(temporary) / 'npm'
            wrapper.write_text('#!/bin/bash\nset -euo pipefail\n')
            old = subprocess.run([node, str(wrapper), '--version'], capture_output=True, text=True, timeout=15)
            self.assertNotEqual(0, old.returncode)
            self.assertIn('SyntaxError', old.stderr)
            fixed = subprocess.run([node, BUILDER.npm_cli_path(node), '--version'], capture_output=True, text=True, timeout=15)
            self.assertEqual(0, fixed.returncode, fixed.stderr)
            self.assertRegex(fixed.stdout.strip(), r'^\d+\.\d+\.\d+$')


if __name__ == '__main__':
    unittest.main()
