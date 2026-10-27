#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Concrete route samples must preserve parent-child bindings and fail closed."""
import unittest
from unittest.mock import patch
from entry_point_authorization import authentication_only, BASELINE, Client, entries, sample, route_fixtures, has_feature_realm


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
                      'app.php/automation/templates/{id}/move/{direction}', 'automation_templates.php',
                      'app.php/templates/devices', 'app.php/templates/graphs', 'app.php/templates/data-sources',
                      'app.php/templates/aggregates', 'app.php/data-collection/queries', 'app.php/automation/snmp-options',
                      'app.php/automation/graph-rules', 'app.php/automation/tree-rules', 'app.php/automation/networks',
                      'app.php/utilities/notification-receivers',
                      'app.php/administration/users', 'app.php/administration/user-groups', 'app.php/administration/domains', 'app.php/automation/discovered-devices', 'app.php/data-collection/collectors', 'app.php/graphing/trees', 'app.php/utilities/rrd-candidates', 'app.php/utilities/snmp-objects', 'app.php/utilities/snmp-notifications', 'app.php/utilities/login-history',
                      'app.php/inventory/device-templates/action/delete',
                      'app.php/aggregate-templates/new',
                      'app.php/graphing/gprint-presets/new',
                      'app.php/graphing/color-templates/new',
                      'app.php/graphing/color-template-items/legacy',
                      'app.php/utilities/rrd-check/purge', 'app.php/management/graphs', 'app.php/management/graph-template-items/{id}', 'app.php/utilities/system', 'app.php/utilities/system/poller-cache',
                      'app.php/graphing/data-source-profiles/{id}/rras/{rraId}/edit',
                      'data_source_profiles.php', 'data_input.php', 'links.php', 'host_templates.php', 'rrdcheck.php'):
            with self.subTest(entry=entry):
                self.assertTrue(has_feature_realm(entry))
        for entry in ('app.php/about', 'about.php', 'app.php/graphing/colors-other',
                      'app.php/inventory/devices/{id}', 'app.php/links-extra',
                      'app.php/utilities/rrd-checker', 'app.php/management-extra', 'app.php/utilities/system-extra', 'app.php/automation/templates-other',
                      'automation_snmp.php', 'app.php/graphing/data-source-profiles-other', 'app.php/administration/users-other'):
            with self.subTest(entry=entry):
                self.assertFalse(has_feature_realm(entry))

    def test_personal_report_feature_denials_are_not_skipped_as_authenticated_only(self):
        self.assertFalse(authentication_only('app.php/management/my-reports', 'symfony:management_my_reports',
                                             'AuthenticatedAccess signed-in account + realm 22'))
        self.assertTrue(authentication_only('app.php/about', 'symfony:platform_about',
                                            'AuthenticatedAccess signed-in account; no realm required'))
        self.assertTrue(authentication_only('auth_profile.php', 'authenticated', 'own session'))

    def test_management_parent_routes_use_actual_installed_parent_ids(self):
        class Rig:
            def sql(self, statement):
                return {'SELECT MIN(id) FROM graph_templates': '17',
                        'SELECT MIN(id) FROM host_template': '23',
                        'SELECT MIN(id) FROM snmp_query': '31'}[statement]
        rows = [('app.php/management/' + name + '/{id}', 'symfony:management', '') for name in
                ('graph-template-items', 'graph-template-inputs', 'device-template-graphs', 'data-query-graphs')]
        ids = {'devices': 1, 'sites': 1}
        route_fixtures(Rig(), rows, ids)
        for name, expected in [('graph-template-items', 17), ('graph-template-inputs', 17),
                               ('device-template-graphs', 23), ('data-query-graphs', 31)]:
            self.assertEqual('app.php/management/' + name + '/' + str(expected),
                             sample('app.php/management/' + name + '/{id}', '', ids))

    def test_missing_management_parent_is_a_fixture_failure(self):
        class Rig:
            def sql(self, statement): return ''
        with self.assertRaisesRegex(RuntimeError, 'Missing concrete management parent'):
            route_fixtures(Rig(), [('app.php/management/graph-template-items/{id}', 'symfony:management', '')],
                           {'devices': 1, 'sites': 1})
    def test_relationship_routes_bind_existing_parents(self):
        class Rig:
            def sql(self, statement):
                self.statement = statement
                return '37'
        routes = ['device-template-queries', 'data-template-definitions', 'aggregate-template-items',
                  'snmp-option-entries', 'graph-rule-device-criteria', 'graph-rule-query-criteria',
                  'tree-rule-device-criteria', 'tree-rule-steps', 'receiver-subscriptions', 'receiver-history']
        routes += ['user-group-memberships', 'group-members', 'user-graphs-permissions', 'user-devices-permissions', 'user-templates-permissions', 'user-trees-permissions', 'group-graphs-permissions', 'group-devices-permissions', 'group-templates-permissions', 'group-trees-permissions']
        for route in routes:
            entry = 'app.php/relationships/' + route + '/{id}'
            rig = Rig()
            fixtures = {'devices': 7, 'sites': 9}
            route_fixtures(rig, [(entry, 'symfony:relationships_' + route, '')], fixtures)
            self.assertTrue(rig.statement.startswith('SELECT MIN(id) FROM '))
            self.assertEqual(entry.replace('{id}', '37'), sample(entry, 'methods=GET', fixtures))
            self.assertTrue(has_feature_realm(entry))
        class Missing:
            def sql(self, statement):
                return 'NULL'
        with self.assertRaisesRegex(ValueError, 'Missing concrete relationship parent'):
            route_fixtures(Missing(), [(entry, '', '')], {'devices': 7, 'sites': 9})

    def test_relationships_sample_after_aggregate_creation(self):
        class Rig:
            created = False
            def sql(self, statement):
                if statement.startswith('INSERT INTO aggregate_graph_templates ('):
                    self.created = True
                    return '43'
                if statement.startswith('INSERT INTO aggregate_graph_templates_graph '):
                    return ''
                if statement == 'SELECT MIN(id) FROM aggregate_graph_templates':
                    return '43' if self.created else 'NULL'
                raise AssertionError(statement)
        rig = Rig()
        ids = {'devices': 7, 'sites': 9}
        entry = 'app.php/relationships/aggregate-template-items/{id}'
        route_fixtures(rig, [('app.php/aggregate-templates/{id}', '', ''), (entry, '', '')], ids)
        self.assertTrue(rig.created)
        self.assertEqual('app.php/relationships/aggregate-template-items/43', sample(entry, '', ids))

    def test_empty_rule_and_receiver_catalogs_get_real_inserted_ids(self):
        tables = {'aggregate-template-items': 'aggregate_graph_templates', 'device-template-queries': 'host_template', 'data-template-definitions': 'data_template', 'snmp-option-entries': 'automation_snmp', 'graph-rule-device-criteria': 'automation_graph_rules',
                  'tree-rule-steps': 'automation_tree_rules', 'receiver-history': 'snmpagent_managers'}
        for route, table in tables.items():
            class Rig:
                created = False
                def sql(self, statement):
                    if statement == 'SELECT MIN(id) FROM ' + table:
                        return '57' if self.created else 'NULL'
                    if 'INSERT INTO ' + table + ' ' in statement:
                        self.created = True
                        self.insert = statement
                        return '57'
                    raise AssertionError(statement)
            rig = Rig()
            ids = {'devices': 7, 'sites': 9}
            entry = 'app.php/relationships/' + route + '/{id}'
            route_fixtures(rig, [(entry, '', '')], ids)
            self.assertTrue(rig.created)
            self.assertEqual(entry.replace('{id}', '57'), sample(entry, '', ids))
            if table == 'snmpagent_managers':
                self.assertIn("'on'", rig.insert)

    def test_mapping_and_mib_routes_bind_existing_or_created_parents(self):
        routes = {'graph-input-items': 'graph_template_input', 'query-data-sources': 'snmp_query_graph',
                  'query-graph-suggestions': 'snmp_query_graph', 'query-data-suggestions': 'snmp_query_graph',
                  'query-device-templates': 'snmp_query', 'graph-device-templates': 'graph_templates',
                  'mib-objects': 'snmpagent_mibs', 'mib-conventions': 'snmpagent_mibs', 'mib-notification-attributes': 'snmpagent_mibs'}
        for route, table in routes.items():
            class Rig:
                def sql(self, statement):
                    if statement.startswith('SELECT MIN(id) FROM ' + table): return '53'
                    raise AssertionError(statement)
            ids = {'devices': 1, 'sites': 1}
            entry = 'app.php/management/' + route + '/{id}'
            route_fixtures(Rig(), [(entry, '', '')], ids)
            self.assertEqual(entry.replace('{id}', '53'), sample(entry, '', ids))
            self.assertTrue(has_feature_realm(entry))
        for route in ['graph-input-items', 'query-data-sources', 'mib-objects']:
            class Empty:
                def sql(self, statement): return 'NULL' if statement.startswith('SELECT MIN') else '61'
            ids = {'devices': 1, 'sites': 1}
            entry = 'app.php/management/' + route + '/{id}'
            route_fixtures(Empty(), [(entry, '', '')], ids)
            self.assertEqual(entry.replace('{id}', '61'), sample(entry, '', ids))

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

    def test_every_data_source_profile_route_binds_a_real_profile_and_archive(self):
        class Rig:
            def sql(self, statement):
                self.statement = statement
                return '3\t13'
        rig = Rig()
        fixtures = {'devices': 7, 'sites': 9}
        rows = [row for row in entries() if row[0].startswith('app.php/graphing/data-source-profiles')]
        self.assertTrue(any('{' in entry for entry, _, _ in rows))
        route_fixtures(rig, rows, fixtures)
        self.assertIn('FROM data_source_profiles_rra', rig.statement)
        for entry, _, detail in rows:
            with self.subTest(entry=entry):
                self.assertNotIn('{', sample(entry, detail, fixtures))
        self.assertEqual('app.php/graphing/data-source-profiles/3/rras/13/delete',
                         sample('app.php/graphing/data-source-profiles/{id}/rras/{rraId}/delete', 'methods=GET', fixtures))

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


class LiveLoginSetup(unittest.TestCase):
    def test_local_login_posts_the_actual_local_form_without_a_realm_selector(self):
        client = Client('http://fixture.invalid')
        response = {'status': 302, 'body': '', 'location': 'index.php'}
        with patch.object(client, 'request', side_effect=[{'status': 200}, response]) as request:
            self.assertIs(response, client.login('fixture-user', 'fixture-password'))
        self.assertEqual(('index.php',), request.call_args_list[0].args)
        self.assertEqual(('index.php', {'action': 'login', 'login_username': 'fixture-user',
                         'login_password': 'fixture-password'}), request.call_args_list[1].args)

    def test_console_redirect_posts_to_the_native_login_page(self):
        client = Client('http://fixture.invalid/kadupul')
        response = {'status': 302, 'body': '', 'location': 'index.php'}
        with patch.object(client, 'request', side_effect=[
                {'status': 302, 'location': '/kadupul/login.php'}, {'status': 200}, response]) as request:
            self.assertIs(response, client.login('fixture-user', 'fixture-password'))
        self.assertEqual(('login.php',), request.call_args_list[1].args)
        self.assertEqual('login.php', request.call_args_list[2].args[0])

    def test_console_login_refuses_foreign_or_unexpected_redirects(self):
        for location in ('https://foreign.invalid/login.php', '/outside/login.php', '/kadupul/other.php'):
            client = Client('http://fixture.invalid/kadupul')
            with patch.object(client, 'request', return_value={'status': 302, 'location': location}) as request:
                with self.assertRaises(RuntimeError):
                    client.login('fixture-user', 'fixture-password')
            self.assertEqual(1, request.call_count)

    def test_failed_login_status_never_starts_the_authorization_sweep(self):
        for status in (400, 401, 403, 500):
            with self.subTest(status=status):
                client = Client('http://fixture.invalid')
                with patch.object(client, 'request', side_effect=[{'status': 200}, {'status': status, 'body': 'Refused'}]):
                    with self.assertRaisesRegex(RuntimeError, 'Login failed for fixture-user'):
                        client.login('fixture-user', 'fixture-password')

    def test_existing_http_200_login_form_refusal_remains_an_error(self):
        client = Client('http://fixture.invalid')
        with patch.object(client, 'request', side_effect=[{'status': 200}, {'status': 200, 'body': 'login_username'}]):
            with self.assertRaisesRegex(RuntimeError, 'Login failed for fixture-user'):
                client.login('fixture-user', 'fixture-password')


if __name__ == '__main__':
    unittest.main()
