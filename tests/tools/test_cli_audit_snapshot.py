# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Verify audit comparisons preserve state and require explicit exit contracts."""
from contextlib import redirect_stdout
from datetime import date, datetime, timezone
from io import StringIO
from pathlib import Path
import subprocess
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
                patch.object(schema, 'log_lines', side_effect=[[], ['same log'], [], [log]]), \
                patch.object(schema, 'utc_date', return_value=date(2026, 10, 17)), \
                patch.object(schema, 'log_date_layout', return_value=('mdY', '/')):
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


class AuditLogDateTest(unittest.TestCase):
    def compare(self, original, native, days=('2026-10-17', '2026-10-17', '2026-10-18', '2026-10-18'),
                layout=('0', '1')):
        events = []
        ticks = iter(days)

        class ClockHarness:
            def sql(inner, query):
                self.assertEqual("SELECT name, value FROM settings WHERE name IN ('default_date_format', 'default_datechar')", query)
                return f'default_date_format\t{layout[0]}\ndefault_datechar\t{layout[1]}\n'

            def command(inner, *arguments, check=False):
                self.assertEqual(('date', '-u', '+%Y-%m-%d'), arguments)
                self.assertTrue(check)
                events.append('date')
                return {'exit': 0, 'stdout': next(ticks) + '\n', 'stderr': ''}

        def invoke(harness, script, arguments):
            events.append(script)
            return {'exit': 0, 'stdout': 'same output', 'stderr': ''}

        with patch.object(schema, 'run', side_effect=invoke), \
                patch.object(schema, 'log_lines', side_effect=[[], original, original, original + native]), \
                redirect_stdout(StringIO()):
            schema.compare(ClockHarness(), lambda condition, label: self.assertTrue(condition, label),
                           'audit log date', ('original.php', 'native.php'), [], None, Mock(),
                           lambda harness: 'same schema', 'audit', log_filter=audit.log_masked)
        return events

    def test_clock_probe_reads_the_selected_runtime_in_utc(self):
        commands = []

        class NativeDateHarness:
            def command(inner, *arguments, check=False):
                commands.append((arguments, check))
                result = subprocess.run(arguments, check=check, capture_output=True, text=True)
                return {'exit': result.returncode, 'stdout': result.stdout, 'stderr': result.stderr}

        first = datetime.now(timezone.utc).date()
        observed = schema.utc_date(NativeDateHarness())
        last = datetime.now(timezone.utc).date()
        self.assertIn(observed, {first, last})
        self.assertEqual([(('date', '-u', '+%Y-%m-%d'), True)], commands)

    def test_invalid_clock_output_and_failed_reads_are_not_accepted(self):
        for output in ('not a date', '20261017', '2026-W42-6', '2026-02-30'):
            with self.subTest(output=output), self.assertRaises(ValueError):
                schema.utc_date(Mock(command=Mock(return_value={'stdout': output})))
        with self.assertRaisesRegex(RuntimeError, 'clock unavailable'):
            schema.utc_date(Mock(command=Mock(side_effect=RuntimeError('clock unavailable'))))

    def test_recorded_midnight_failure_is_accepted(self):
        self.compare(["10/17/2026 23:59:58 - DBCALL ERROR: mysqldump failed with exit code 2 for database 'cacti'"],
                     ["10/18/2026 00:00:00 - DBCALL ERROR: mysqldump failed with exit code 2 for database 'cacti'"])

    def test_each_command_has_its_own_clock_boundaries(self):
        events = self.compare(['10/17/2026 23:59:58 - same'], ['10/18/2026 00:00:00 - same'])
        self.assertEqual(['date', 'original.php', 'date', 'date', 'native.php', 'date'], events)

    def test_same_day_still_ignores_only_the_time_of_day(self):
        self.compare(['10/17/2026 10:00:00 - same'], ['10/17/2026 11:00:00 - same'], ('2026-10-17',) * 4)

    def test_all_supported_date_orders_and_separators_cross_midnight(self):
        for order, (before, after) in enumerate([('10|17|2026', '10|18|2026'), ('Oct|17|2026', 'Oct|18|2026'),
                              ('17|10|2026', '18|10|2026'), ('17|Oct|2026', '18|Oct|2026'),
                              ('2026|10|17', '2026|10|18'), ('2026|Oct|17', '2026|Oct|18')]):
            for separator in ('/', '-', '.'):
                with self.subTest(order=before, separator=separator):
                    self.compare([before.replace('|', separator) + ' 23:59:58 - same'],
                                 [after.replace('|', separator) + ' 00:00:00 - same'],
                                 layout=(str(order), {'-': '0', '/': '1', '.': '2'}[separator]))

    def test_month_year_and_leap_day_boundaries(self):
        for before, after, first, second in [
                ('12/31/2026', '01/01/2027', '2026-12-31', '2027-01-01'),
                ('02/29/2028', '03/01/2028', '2028-02-29', '2028-03-01'),
                ('02/28/2027', '03/01/2027', '2027-02-28', '2027-03-01'),
                ('10/10/2026', '11/10/2026', '2026-10-10', '2026-10-11')]:
            with self.subTest(before=before, after=after):
                self.compare([before + ' 23:59:58 - same'], [after + ' 00:00:00 - same'], (first, first, second, second),
                             layout=('2' if before == '10/10/2026' else '0', '1'))

    def test_ambiguous_numeric_dates_cannot_select_an_unconfigured_layout(self):
        with self.assertRaisesRegex(AssertionError, 'logs'):
            self.compare(['10/10/2026 23:59:58 - same'], ['11/10/2026 00:00:00 - same'],
                         ('2026-10-10', '2026-10-10', '2026-10-11', '2026-10-11'))

    def test_missing_settings_use_actual_defaults_and_unsupported_values_fail_closed(self):
        self.assertEqual(('Ymd', '-'), schema.log_date_layout(Mock(sql=Mock(return_value=''))))
        for settings in ('default_date_format\tbad\n', 'default_datechar\t9\n'):
            with self.subTest(settings=settings), self.assertRaises(ValueError):
                schema.log_date_layout(Mock(sql=Mock(return_value=settings)))

    def test_changed_configuration_between_commands_remains_a_failure(self):
        with patch.object(schema, 'log_date_layout', side_effect=[('mdY', '/'), ('dmY', '/')]), \
                self.assertRaisesRegex(AssertionError, 'logs'):
            self.compare(['10/10/2026 10:00:00 - same'], ['10/10/2026 11:00:00 - same'], ('2026-10-10',) * 4)

    def test_a_single_command_can_span_midnight(self):
        self.compare(['10/17/2026 23:59:58 - first', '10/18/2026 00:00:00 - second'],
                     ['10/18/2026 00:00:01 - first', '10/18/2026 00:00:02 - second'],
                     ('2026-10-17', '2026-10-18', '2026-10-18', '2026-10-18'))

    def test_identical_stale_dates_do_not_pass_equality(self):
        with self.assertRaisesRegex(AssertionError, 'logs'):
            self.compare(['10/16/2026 12:00:00 - same'], ['10/16/2026 12:00:01 - same'])

    def test_a_date_from_the_other_commands_window_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'logs'):
            self.compare(['10/17/2026 23:59:58 - same'], ['10/17/2026 00:00:00 - same'])

    def test_wrong_dates_and_layout_changes_remain_failures(self):
        for changed in ('10/19/2026', '10/18/2025', '02/30/2026', '18/10/2026', '10-18-2026', 'Oct/18/2026'):
            with self.subTest(changed=changed), self.assertRaisesRegex(AssertionError, 'logs'):
                self.compare(['10/17/2026 23:59:58 - same'], [changed + ' 00:00:00 - same'])

    def test_message_changes_missing_and_duplicate_records_remain_failures(self):
        for changed in (['10/18/2026 00:00:00 - different'], [], ['10/18/2026 00:00:00 - same'] * 2):
            with self.subTest(changed=changed), self.assertRaisesRegex(AssertionError, 'logs'):
                self.compare(['10/17/2026 23:59:58 - same'], changed)

    def test_existing_filter_exclusions_and_continuation_lines_are_preserved(self):
        self.compare(['10/17/2026 23:59:58 - same', 'continuation', '10/16/2026 10:00:00' + audit.BACKTRACE + 'ignored'],
                     ['10/18/2026 00:00:00 - same', 'continuation'])

    def test_reversed_and_unbounded_clock_observations_fail_closed(self):
        for days in (('2026-10-18', '2026-10-17', '2026-10-18', '2026-10-18'),
                     ('2026-10-16', '2026-10-18', '2026-10-18', '2026-10-18')):
            with self.subTest(days=days), self.assertRaisesRegex(AssertionError, 'logs'):
                self.compare(['10/17/2026 23:59:58 - same'], ['10/18/2026 00:00:00 - same'], days)


if __name__ == '__main__':
    unittest.main()
