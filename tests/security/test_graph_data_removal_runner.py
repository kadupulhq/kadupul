# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Keep native removal evidence on the exact runtime selected by its caller."""
import unittest
from unittest.mock import patch
from pathlib import Path
import run_graph_data_removal_contracts as runner


class RemovalRuntimeTest(unittest.TestCase):
    def test_selects_requested_patch_and_checks_actual_readback(self):
        php = str(Path(__file__).resolve())
        with patch.object(runner.subprocess, 'check_output', side_effect=[php, '8.4.26']) as output:
            self.assertEqual((php, '8.4.26'), runner.selected_php('8.4.26'))
            self.assertEqual(['mise', 'which', 'php', '--tool=php@8.4.26'], output.call_args_list[0].args[0])
            self.assertEqual([php, '-r', 'echo PHP_VERSION;'], output.call_args_list[1].args[0])

    def test_wrong_patch_or_series_cannot_be_used_for_native_evidence(self):
        php = str(Path(__file__).resolve())
        for requested, actual in [('8.4.26', '8.4.25'), ('8.3.30', '8.3.30')]:
            with self.subTest(requested=requested, actual=actual), \
                    patch.object(runner.subprocess, 'check_output', side_effect=[php, actual]):
                with self.assertRaisesRegex(RuntimeError, 'Select PHP 8.4'):
                    runner.selected_php(requested)

    def test_missing_interpreter_fails_before_execution(self):
        with patch.object(runner.subprocess, 'check_output', return_value='/missing/php') as output:
            with self.assertRaisesRegex(RuntimeError, 'runtime unavailable'):
                runner.selected_php('8.4.26')
            self.assertEqual(1, output.call_count)


if __name__ == '__main__':
    unittest.main()
