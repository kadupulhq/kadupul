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

    def test_non_index_data_changes_remain_visible(self):
        self.assertNotEqual(snapshot(INDEX), snapshot(INDEX, state='version\tchanged\n'))


if __name__ == '__main__':
    unittest.main()
