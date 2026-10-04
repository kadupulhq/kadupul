#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Concrete route samples must preserve parent-child bindings and fail closed."""
import unittest
from entry_point_authorization import BASELINE, entries, sample, route_fixtures, has_feature_realm


class RouteSamples(unittest.TestCase):
    def test_inventory_header_precedes_every_real_route(self):
        lines = BASELINE.read_text().splitlines()
        self.assertEqual('entry\tgate\tdetail', lines[0])
        rows = entries()
        self.assertEqual(len(lines) - 1, len(rows))
        self.assertEqual(lines[1].split('\t')[0], rows[0][0])
        self.assertNotIn(('entry', 'gate', 'detail'), rows)

    def test_feature_pages_are_probed_without_console_only_shortcut(self):
        for entry in ('app.php/data-inputs/actions/delete', 'app.php/links',
                      'app.php/graph-definitions/vdefs/{id}/items',
                      'app.php/graph-definitions/cdefs', 'app.php/graphing/colors/import',
                      'app.php/inventory/device-templates/action/delete',
                      'app.php/aggregate-templates/new',
                      'app.php/graphing/gprint-presets/new',
                      'app.php/graphing/color-templates/new',
                      'app.php/graphing/color-template-items/legacy',
                      'data_input.php', 'links.php', 'host_templates.php'):
            with self.subTest(entry=entry):
                self.assertTrue(has_feature_realm(entry))
        for entry in ('app.php/about', 'about.php', 'app.php/graphing/colors-other',
                      'app.php/inventory/devices/{id}', 'app.php/links-extra'):
            with self.subTest(entry=entry):
                self.assertFalse(has_feature_realm(entry))

    def test_parent_child_and_enum_parameters(self):
        fixtures = {'graphing/color-templates': {'id': 12, 'itemId': 31},
                    'graph-definitions/vdefs': {'vdefId': 4, 'itemId': 5},
                    'inventory/devices': 8}
        cases = [
            ('app.php/graphing/color-templates/{id}/items/{itemId}/delete', 'methods=GET|POST requirements=id=[1-9][0-9]{0,9} itemId=[1-9][0-9]{0,9}', 'app.php/graphing/color-templates/12/items/31/delete'),
            ('app.php/graph-definitions/vdefs/{vdefId<\\d+>}/items/{itemId<\\d+>}', 'methods=GET requirements=vdefId=[1-9][0-9]+ itemId=[0-9]+', 'app.php/graph-definitions/vdefs/4/items/5'),
            ('app.php/inventory/devices/{id}/{operation}', 'methods=GET requirements=operation=delete|disable', 'app.php/inventory/devices/8/delete'),
        ]
        for entry, detail, expected in cases:
            with self.subTest(entry=entry):
                self.assertEqual(expected, sample(entry, detail, fixtures))

    def test_data_input_field_binds_to_its_actual_parent(self):
        class Rig:
            def sql(self, statement):
                if 'INSERT INTO data_input (' in statement:
                    return '12'
                if 'INSERT INTO data_input_fields ' in statement:
                    self.statement = statement
                    return '31'
                raise AssertionError(statement)
        rig = Rig()
        fixtures = {'devices': 7, 'sites': 9}
        route_fixtures(rig, [('app.php/data-inputs/{id}/fields/{field}', 'symfony:data_input_field', '')], fixtures)
        self.assertIn("VALUES (12,'Value','value','in',1)", rig.statement)
        self.assertEqual('app.php/data-inputs/12/fields/31', sample('app.php/data-inputs/{id}/fields/{field}', 'methods=GET', fixtures))

    def test_missing_fixture_refuses_instead_of_using_arbitrary_id(self):
        with self.assertRaisesRegex(ValueError, 'Missing concrete route fixture'):
            sample('app.php/new-module/{id}', 'methods=GET', {})

    def test_existing_inventory_fixtures_keep_full_route_prefixes(self):
        class Rig:
            def sql(self, sql):
                raise AssertionError('No unrelated fixtures should be inserted: ' + sql)
        ids = {'devices': 7, 'sites': 9}
        route_fixtures(Rig(), [('app.php/inventory/devices/{id}', 'symfony:device', '')], ids)
        self.assertEqual('app.php/inventory/devices/7', sample('app.php/inventory/devices/{id}', '', ids))
        self.assertEqual('app.php/inventory/sites/9', sample('app.php/inventory/sites/{id}', '', ids))


if __name__ == '__main__':
    unittest.main()
