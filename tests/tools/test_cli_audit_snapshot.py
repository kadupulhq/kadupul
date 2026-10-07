# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Verify audit comparisons preserve state and require explicit exit contracts."""
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Symfony'))
import cli_audit_scenarios as audit
import cli_schema_scenarios as schema


INDEX = ['settings', '0', 'PRIMARY', '1', 'name', 'A', '80', 'NULL', 'NULL', '', 'BTREE', '']


def dump(values):
    tokens = [str(value) if index in (1, 3, 6) or value == 'NULL' else "'" + value.replace("'", "\\'") + "'"
              for index, value in enumerate(values)]
    return 'INSERT INTO `table_indexes` VALUES (' + ','.join(tokens) + ');\n'


class SnapshotHarness:
    def __init__(self, index, state='version\t1.2.31\n'):
        self.index = index
        self.state = state

    def sql(self, query):
        if 'SELECT TABLE_NAME FROM information_schema.TABLES' in query:
            return 'table_indexes\n'
        if query.startswith('SELECT * FROM table_indexes'):
            return '\t'.join(self.index) + '\n'
        if query.startswith('SELECT cacti FROM version'):
            return self.state
        return 'unchanged schema\n'


def snapshot(index, imported=True, state='version\t1.2.31\n'):
    with patch.object(audit, 'dump_file', return_value=('audit_schema.sql\n', dump(index))):
        return audit.schema(SnapshotHarness(index, state), imported=imported)


class AuditSnapshotTest(unittest.TestCase):
    def test_separate_loads_ignore_only_numeric_engine_estimates(self):
        for table in ('settings', 'host', 'table_columns', 'table_indexes'):
            with self.subTest(table=table):
                first = INDEX.copy()
                first[0] = table
                second = first.copy()
                second[6] = '2'
                self.assertEqual(snapshot(first), snapshot(second))
                self.assertNotEqual(snapshot(first, imported=False), snapshot(second, imported=False))

    def test_each_index_definition_field_remains_visible(self):
        for index, changed in enumerate(['host', '1', 'secondary', '2', 'value', 'D', None, '10', '1', 'YES', 'HASH', 'changed comment']):
            if changed is None:
                continue
            with self.subTest(field=index):
                other = INDEX.copy()
                other[index] = changed
                self.assertNotEqual(snapshot(INDEX), snapshot(other))

    def test_missing_or_null_cardinality_is_not_a_numeric_estimate(self):
        for value in ('NULL', '', '-1', 'invalid'):
            with self.subTest(value=value):
                other = INDEX.copy()
                other[6] = value
                self.assertNotEqual(snapshot(INDEX), snapshot(other))

    def test_escaped_comments_remain_visible(self):
        first = INDEX.copy()
        first[11] = "operator's index"
        second = first.copy()
        second[6] = '2'
        self.assertEqual(snapshot(first), snapshot(second))
        second[11] = "other's index"
        self.assertNotEqual(snapshot(first), snapshot(second))

    def test_column_rows_and_incomplete_records_are_not_normalized(self):
        for row in ['host\t1\tid\tint(10)\tNO\tPRI\t80\t\n', '\t'.join(INDEX[:-1]) + '\n']:
            self.assertEqual(row, audit.IMPORTED_CARDINALITY[0].sub(lambda match: 'MASKED', row))
        column_dump = "INSERT INTO `table_columns` VALUES ('host',1,'id','int(10)','NO','PRI','80','');\n"
        self.assertEqual(column_dump, audit.IMPORTED_CARDINALITY[1].sub(lambda match: 'MASKED', column_dump))

    def test_recorded_collation_is_required_and_wrong_values_remain_visible(self):
        recorded = {('poller_command', 'command'): 'utf8mb4_unicode_ci'}
        legacy = "ALTER TABLE `poller_command`\n   MODIFY COLUMN `command` varchar(191) NOT NULL DEFAULT '',\n"
        expected = legacy.replace(" NOT NULL", " COLLATE utf8mb4_unicode_ci NOT NULL")
        self.assertEqual(expected, audit.with_recorded_collations(legacy, recorded))
        self.assertEqual(expected, audit.with_recorded_collations(expected, recorded))
        self.assertNotEqual(legacy, audit.with_recorded_collations(legacy, recorded))
        wrong = expected.replace('utf8mb4_unicode_ci', 'utf8mb4_bin')
        self.assertNotEqual(expected, audit.with_recorded_collations(wrong, recorded))

    def test_legacy_index_only_rewrite_has_one_exact_scope(self):
        legacy = "ALTER TABLE `poller_resource_cache`\n   MODIFY COLUMN `path` varchar(191),\n   ADD UNIQUE INDEX `path` (`path`) USING BTREE,\n"
        expected = legacy.replace('   MODIFY COLUMN `path` varchar(191),\n', '')
        self.assertEqual(expected, audit.without_legacy_index_only_modify(legacy))
        for changed in (legacy.replace('poller_resource_cache', 'host'),
                        legacy.replace('varchar(191)', 'varchar(190)'),
                        legacy.replace('varchar(191)', 'varchar(191) NOT NULL'),
                        legacy.replace('`path` varchar', '`attributes` varchar'),
                        legacy.replace('varchar(191)', 'varchar(191) COLLATE utf8mb4_bin')):
            with self.subTest(changed=changed):
                self.assertEqual(changed, audit.without_legacy_index_only_modify(changed))
        self.assertEqual(expected, audit.without_legacy_index_only_modify(expected))

    def test_collation_extension_comparison_excludes_only_the_verified_shape(self):
        records = [
            'table_columns\ttable_collation\t9\tvarchar(64)\tYES\tNULL\t\n',
            'table_columns\t9\ttable_collation\tvarchar(64)\tYES\t\tNULL\t\n',
            "INSERT INTO `table_columns` VALUES ('table_columns',9,'table_collation','varchar(64)','YES','',NULL,'');\n",
        ]
        for record in records:
            with self.subTest(record=record):
                self.assertEqual('', audit.without_collation_extension_record(record))
                for changed in (record.replace('varchar(64)', 'varchar(63)'),
                                record.replace('YES', 'NO'), record.replace('table_collation', 'table_extra'),
                                record.replace('NULL', "'changed'"), record.replace('9', '10')):
                    self.assertEqual(changed, audit.without_collation_extension_record(changed))

    def test_non_index_data_changes_remain_visible(self):
        self.assertNotEqual(snapshot(INDEX), snapshot(INDEX, state='version\tchanged\n'))


class AuditExitComparisonTest(unittest.TestCase):
    def compare(self, original_exit=0, native_exit=1, expected_exits=None,
                stdout='same output', stderr='', state='same schema', log='same log'):
        original = {'exit': original_exit, 'stdout': 'same output', 'stderr': ''}
        native = {'exit': native_exit, 'stdout': stdout, 'stderr': stderr}
        snapshot = Mock(side_effect=[('start',), ('same schema',), ('start',), (state,)])
        with patch.object(schema, 'run', side_effect=[original, native]), \
                patch.object(schema, 'log_lines', side_effect=[[], ['same log'], [], [log]]):
            return schema.compare(object(), lambda condition, label: self.assertTrue(condition, label),
                                  'audit failed write', ('original.php', 'native.php'), ['--repair'],
                                  None, Mock(), snapshot, 'audit', stdout=lambda text: text,
                                  log_filter=lambda lines: lines, expected_exits=expected_exits)

    def test_explicit_failure_pair_admits_exact_historical_and_native_exits(self):
        compared = self.compare(expected_exits=(0, 1))
        self.assertEqual(0, compared['original']['exit'])
        self.assertEqual(1, compared['shim']['exit'])

    def test_default_retains_strict_exit_parity(self):
        for exit_code in (0, 1):
            with self.subTest(exit=exit_code):
                self.compare(original_exit=exit_code, native_exit=exit_code)
        with self.assertRaisesRegex(AssertionError, 'matches the original'):
            self.compare()

    def test_explicit_pair_rejects_changed_original_or_native_exit(self):
        for original, native in ((1, 1), (0, 0), (0, 2), (2, 1)):
            with self.subTest(original=original, native=native):
                with self.assertRaisesRegex(AssertionError, 'exit code is'):
                    self.compare(original_exit=original, native_exit=native, expected_exits=(0, 1))

    def test_explicit_exit_contract_preserves_other_comparisons(self):
        for field, value, diagnostic in (('stdout', 'different', 'stdout'),
                                         ('stderr', 'different', 'stderr'),
                                         ('state', 'different', 'schema'),
                                         ('log', 'different', 'logs')):
            with self.subTest(field=field):
                with redirect_stdout(StringIO()), self.assertRaisesRegex(AssertionError, diagnostic):
                    self.compare(expected_exits=(0, 1), **{field: value})

    def test_audit_caller_selects_only_the_three_intentional_failure_states(self):
        class Compared(Exception):
            pass

        for label, arguments, state in audit.AUDIT_CASES:
            # These baseline-refusal cases have their own direct outcome checks.
            if state in ('no dump', 'unparsable', 'create denied'):
                continue
            harness = Mock()
            harness.sql.side_effect = lambda query: (
                'varchar(191)\tYES\t<sql-null>\t\tutf8mb4_unicode_ci\tUNI'
                if 'COLUMN_TYPE' in query else '')
            with self.subTest(state=state), patch.object(audit, 'AUDIT_CASES', [(label, arguments, state)]), \
                    patch.object(audit, 'compare', side_effect=Compared) as comparator:
                with self.assertRaises(Compared):
                    audit.verify_audit_cases(harness, lambda condition, message: self.assertTrue(condition, message), [], '1.2.35')
                self.assertEqual((0, 1) if state in ('failing', 'untyped index', 'dump denied') else None,
                                 comparator.call_args.kwargs['expected_exits'])

    def test_reset_state_still_has_to_match(self):
        with patch.object(schema, 'run', return_value={'exit': 0, 'stdout': '', 'stderr': ''}), \
                patch.object(schema, 'log_lines', return_value=[]):
            with self.assertRaisesRegex(AssertionError, 'same schema'):
                schema.compare(object(), lambda condition, label: self.assertTrue(condition, label),
                               'audit failed write', ('original.php', 'native.php'), [], None, Mock(),
                               Mock(side_effect=['start', 'after', 'wrong start']), 'audit', expected_exits=(0, 1))


if __name__ == '__main__':
    unittest.main()
