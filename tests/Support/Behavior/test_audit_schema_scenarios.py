# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Contract regressions for baseline export verification and failure reporting."""
import copy
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

import audit_schema_scenarios as scenarios


class ExportHarness:
    def __init__(self):
        self.expected = {'columns': [{'table_name': 'device', 'table_field': 'id'}],
                         'indexes': [{'idx_table_name': 'device', 'idx_key_name': 'PRIMARY'}]}
        self.actual = copy.deepcopy(self.expected)
        self.load = {'exit': 0, 'stdout': json.dumps({'status': 'ok', 'exported': True}), 'stderr': ''}
        self.parser_results = {}
        self.paths = []
        self.commands = []

    def command(self, *args):
        self.commands.append(args)

    def compose(self, *args):
        self.commands.append(args)

    def php(self, *args):
        if args[0] == 'bin/console':
            return self.load
        if args[0] != '-r':
            raise AssertionError('Unexpected PHP invocation')
        self.paths.append(args[-1])
        if args[-1] in self.parser_results:
            return self.parser_results[args[-1]]
        rows = self.expected if args[-1] == '/tmp/kadupul-audit-schema.sql' else self.actual
        return {'exit': 0, 'stdout': json.dumps(rows), 'stderr': ''}

    def rows(self, *args):
        raise AssertionError('Export verification must inspect the exported SQL, not its source tables')


class AuditBaselineScenariosTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='audit-baseline-selftest-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / 'docs').mkdir()
        (self.root / 'docs/audit_schema.sql').write_text('-- preserved baseline fixture\n')
        module = types.SimpleNamespace(CONTROLLER_ROOT=self.root)
        patcher = patch.dict(sys.modules, {'harness': module})
        patcher.start()
        self.addCleanup(patcher.stop)
        self.harness = ExportHarness()

    def test_clean_audit_returns_actual_table_count(self):
        self.harness.load['stdout'] = json.dumps({'status': 'ok', 'baseline': 'loaded',
            'tables': [{'name': 'device', 'errors': 0, 'warnings': 0, 'findings': []},
                       {'name': 'graph', 'errors': 0, 'warnings': 0, 'findings': []}]})
        self.assertEqual({'status': 'ok', 'baseline': 'loaded', 'tables': 2, 'findings': 0},
                         scenarios.assert_clean_schema_audit(self.harness, 'Installed'))

    def test_clean_audit_rejects_failed_command_with_diagnostic(self):
        self.harness.load = {'exit': 7, 'stdout': json.dumps({'status': 'failed',
            'baseline': 'loaded', 'tables': [{'name': 'device', 'errors': 1,
            'warnings': 0, 'findings': ['bad index']}]}), 'stderr': 'audit failed'}
        with self.assertRaisesRegex(RuntimeError, 'command failed.*bad index.*audit failed'):
            scenarios.assert_clean_schema_audit(self.harness, 'Installed')

    def test_clean_audit_rejects_non_json(self):
        self.harness.load['stdout'] = 'not JSON'
        with self.assertRaisesRegex(RuntimeError, 'did not return JSON'):
            scenarios.assert_clean_schema_audit(self.harness, 'Installed')

    def test_clean_audit_rejects_invalid_or_empty_envelopes(self):
        clean = {'status': 'ok', 'baseline': 'loaded', 'tables': [
            {'name': 'device', 'errors': 0, 'warnings': 0, 'findings': []}]}
        reports = [[], None, 'unexpected', {}, {**clean, 'status': 'failed'},
                   {**clean, 'baseline': 'missing'}, {**clean, 'tables': []},
                   {**clean, 'tables': None}, {**clean, 'tables': ['invalid']}]
        for report in reports:
            with self.subTest(report=report):
                self.harness.load['stdout'] = json.dumps(report)
                with self.assertRaisesRegex(RuntimeError, 'schema audit (found drift|has an invalid envelope)'):
                    scenarios.assert_clean_schema_audit(self.harness, 'Installed')

    def test_clean_audit_rejects_each_dirty_table_signal_and_names_table(self):
        for field, value in (('errors', 1), ('warnings', 1), ('findings', ['bad index']),
                             ('findings', None), ('findings', 1), ('findings', 'bad index')):
            with self.subTest(field=field):
                table = {'name': 'device', 'errors': 0, 'warnings': 0, 'findings': []}
                table[field] = value
                self.harness.load['stdout'] = json.dumps({'status': 'ok', 'baseline': 'loaded',
                                                         'tables': [table]})
                with self.assertRaisesRegex(RuntimeError, 'found drift.*device'):
                    scenarios.assert_clean_schema_audit(self.harness, 'Installed')

    def test_clean_audit_missing_baseline_fails_before_container_calls(self):
        (self.root / 'docs/audit_schema.sql').unlink()
        with self.assertRaisesRegex(RuntimeError, 'Checked-in audit schema is missing'):
            scenarios.assert_clean_schema_audit(self.harness, 'Installed')
        self.assertEqual([], self.harness.commands)

    def test_matching_export_inspects_both_files_and_returns_counts(self):
        result = scenarios.assert_baseline_reproducible(self.harness)
        self.assertEqual(1, result['columns'])
        self.assertEqual(1, result['indexes'])
        self.assertEqual(['/tmp/kadupul-audit-schema.sql', '/var/www/html/docs/audit_schema.sql'],
                         self.harness.paths)

    def test_metadata_order_does_not_change_the_comparison(self):
        extra = {'table_name': 'device_1', 'table_field': 'id'}
        self.harness.expected['columns'].append(extra)
        self.harness.actual['columns'].insert(0, extra)
        self.assertEqual(2, scenarios.assert_baseline_reproducible(self.harness)['columns'])

    def test_changed_export_reports_the_first_differing_row(self):
        self.harness.actual['columns'][0]['table_field'] = 'changed'
        with self.assertRaisesRegex(RuntimeError, '"column_sample": \\{"actual".*"row": 0'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_extra_export_rows_report_the_count_difference(self):
        self.harness.actual['columns'].append({'table_name': 'extra', 'table_field': 'id'})
        with self.assertRaisesRegex(RuntimeError, '"actual_count": 2, "expected_count": 1'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_duplicate_export_rows_are_not_lost_by_set_comparison(self):
        self.harness.actual['indexes'] *= 2
        with self.assertRaisesRegex(RuntimeError, 'Generated audit baseline differs'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_empty_baselines_cannot_pass_vacuously(self):
        for group in ('columns', 'indexes'):
            with self.subTest(group=group):
                self.harness = ExportHarness()
                self.harness.expected[group] = []
                self.harness.actual[group] = []
                with self.assertRaisesRegex(RuntimeError, 'empty ' + group):
                    scenarios.assert_baseline_reproducible(self.harness)

    def test_failed_export_is_rejected_before_inspecting_files(self):
        self.harness.load['exit'] = 1
        with self.assertRaisesRegex(RuntimeError, 'baseline generation failed'):
            scenarios.assert_baseline_reproducible(self.harness)
        self.assertEqual([], self.harness.paths)

    def test_unconfirmed_export_is_rejected(self):
        for report in ({'status': 'ok', 'exported': False}, {'status': 'failed', 'exported': True}):
            with self.subTest(report=report):
                self.harness.load['stdout'] = json.dumps(report)
                with self.assertRaisesRegex(RuntimeError, 'was not exported'):
                    scenarios.assert_baseline_reproducible(self.harness)

    def test_non_json_export_result_is_rejected(self):
        self.harness.load['stdout'] = 'not JSON'
        with self.assertRaisesRegex(RuntimeError, 'generation did not return JSON'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_invalid_export_result_shape_is_controlled(self):
        self.harness.load['stdout'] = '[]'
        with self.assertRaisesRegex(RuntimeError, 'was not exported'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_export_parser_failure_is_rejected(self):
        self.harness.parser_results['/var/www/html/docs/audit_schema.sql'] = {
            'exit': 1, 'stdout': '', 'stderr': 'malformed SQL'}
        with self.assertRaisesRegex(RuntimeError, 'Generated audit baseline could not be parsed'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_non_json_parser_result_is_rejected(self):
        self.harness.parser_results['/var/www/html/docs/audit_schema.sql'] = {
            'exit': 0, 'stdout': 'not JSON', 'stderr': ''}
        with self.assertRaisesRegex(RuntimeError, 'Generated audit baseline parser did not return JSON'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_invalid_parser_shape_is_rejected(self):
        self.harness.actual['columns'] = ['not a metadata row']
        with self.assertRaisesRegex(RuntimeError, 'invalid columns'):
            scenarios.assert_baseline_reproducible(self.harness)

    def test_missing_committed_baseline_is_rejected_before_staging(self):
        (self.root / 'docs/audit_schema.sql').unlink()
        with self.assertRaisesRegex(RuntimeError, 'Checked-in audit schema is missing'):
            scenarios.assert_baseline_reproducible(self.harness)
        self.assertEqual([], self.harness.commands)

    def test_first_difference_equal_changed_and_prefix_cases(self):
        self.assertIsNone(scenarios._first_difference([1], [1]))
        self.assertEqual({'row': 0, 'expected': 1, 'actual': 2},
                         scenarios._first_difference([1], [2]))
        self.assertEqual({'row': 1, 'expected_count': 1, 'actual_count': 2},
                         scenarios._first_difference([1], [1, 2]))


if __name__ == '__main__':
    unittest.main()
