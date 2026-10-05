# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Verify audit snapshot comparisons omit only numeric engine estimates."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Symfony'))
import cli_audit_scenarios as audit


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


if __name__ == '__main__':
    unittest.main()
