"""Device removal preserves retention choices and rejects unreviewed scope."""
import json
from urllib.parse import urlencode
from device_state_scenarios import StateForm
from harness import Session


class RemovalForm(StateForm):
    def __init__(self, harness, session, ids):
        self.harness = harness
        self.session = session
        self.path = '/app.php/inventory/devices/remove?' + urlencode({'ids[]': ids}, doseq=True)

    def remove(self, policy='retain', fields=None):
        return self.apply((self.fields() if fields is None else fields) | {'device_removal[policy]': policy})


def verify_device_removal(harness, session, user_id, poller, check):
    fixtures = []
    triggers = []
    saved_agent = harness.sql("SELECT value FROM settings WHERE name='enable_snmp_agent'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='rrd_autoclean'").strip()
    saved_method = harness.sql("SELECT value FROM settings WHERE name='rrd_autoclean_method'").strip()

    def create(collector=1):
        description_hex = 'remove-fixture 🌏'.encode().hex().upper()
        device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id) VALUES (CONVERT(UNHEX('{description_hex}') USING utf8mb4),'remove.invalid',{collector},0); SELECT LAST_INSERT_ID()").strip())
        check(harness.sql(f'SELECT HEX(description) FROM host WHERE id={device}').strip() == description_hex,
              'removal fixture preserves four-byte description before revision checks')
        graph = int(harness.sql(f'INSERT INTO graph_local (host_id) VALUES ({device}); SELECT LAST_INSERT_ID()').strip())
        sources = []
        for index in range(2):
            data = int(harness.sql(f'INSERT INTO data_local (host_id) VALUES ({device}); SELECT LAST_INSERT_ID()').strip())
            dtd = int(harness.sql(f"INSERT INTO data_template_data (local_data_id,name,active,data_source_path) VALUES ({data},'remove source','on','<path_rra>/remove-{device}-{index}.rrd'); SELECT LAST_INSERT_ID()").strip())
            rrd = int(harness.sql(f"INSERT INTO data_template_rrd (local_data_id,data_source_name) VALUES ({data},'remove'); SELECT LAST_INSERT_ID()").strip())
            harness.sql(f"INSERT INTO data_input_data (data_template_data_id,data_input_field_id,value) VALUES ({dtd},1,'removal input')")
            harness.sql(f"INSERT INTO poller_item (local_data_id,host_id,poller_id,rrd_name) VALUES ({data},{device},{collector},'remove')")
            sources.append((data, dtd, rrd))
        harness.sql(f'INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES ({graph},{sources[0][2]})')
        fixture = {'device': device, 'graph': graph, 'sources': sources}
        fixtures.append(fixture)
        if collector > 1:
            for table, where in [('host', f'id={device}'), ('graph_local', f'id={graph}'), ('graph_templates_item', f'local_graph_id={graph}'), ('data_local', f'host_id={device}'), ('poller_item', f'host_id={device}')]:
                harness.sql(f'INSERT INTO create_remote.{table} SELECT * FROM {table} WHERE {where}')
            for data, dtd, rrd in sources:
                for table, where in [('data_template_data', f'id={dtd}'), ('data_template_rrd', f'id={rrd}'), ('data_input_data', f'data_template_data_id={dtd}')]:
                    harness.sql(f'INSERT INTO create_remote.{table} SELECT * FROM {table} WHERE {where}')
        check(harness.php('-r', 'require "include/global.php"; snmpagent_api_device_new(["id" => ' + str(device) + ']);')['exit'] == 0, 'removal SNMP-agent device fixture initialized')
        return fixture

    def exists(device):
        return harness.sql(f'SELECT COUNT(*) FROM host WHERE id={device}').strip() == '1'

    def hook_count(args):
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        return sum(json.loads(line).get('args') == [args] for line in events.splitlines())

    try:
        harness.sql("REPLACE INTO settings (name,value) VALUES ('enable_snmp_agent','on')")
        harness.sql("REPLACE INTO settings (name,value) VALUES ('rrd_autoclean','on'),('rrd_autoclean_method','delete')")
        check(harness.php('-r', 'require "include/global.php"; function setup_remove_hook() { api_plugin_register_hook("compatibility_test","device_remove","compatibility_test_filter","setup.php",true); api_plugin_register_hook("compatibility_test","data_source_remove","compatibility_test_event","setup.php",true); api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_test_filter","setup.php",true); } setup_remove_hook();')['exit'] == 0, 'device removal hooks registered')
        kept = create()
        device = kept['device']
        form = RemovalForm(harness, session, [device])
        fields = form.fields()
        check(exists(device), 'device removal GET does not mutate')
        check(RemovalForm(harness, Session(harness.base), [device]).request()[0] == 401, 'anonymous device removal is denied')
        check(RemovalForm(harness, session, [device, 16777215]).request()[0] == 404, 'partial missing removal selection is hidden')
        check(form.request(fields=fields, origin=False)[0] == 422, 'device removal requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_removal[_token]')
        check(form.apply(missing) == 422, 'device removal requires CSRF token')
        for invalid in ['', 'unknown']:
            check(form.remove(invalid, fields) == 422, 'device removal rejects invalid retention choice')
        missing = dict(fields)
        missing.pop('device_removal[policy]', None)
        check(form.apply(missing) == 422, 'device removal requires explicit retention choice')
        check(form.apply(fields | {'device_removal[extra]': '1'}) == 422, 'device removal rejects unrelated fields')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        check(form.remove(fields=fields) == 403, 'device removal rechecks revoked device realm')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        added = int(harness.sql(f'INSERT INTO graph_local (host_id) VALUES ({device}); SELECT LAST_INSERT_ID()').strip())
        check(form.remove(fields=fields) == 409 and exists(device), 'new graph invalidates device removal confirmation')
        harness.sql(f'DELETE FROM graph_local WHERE id={added}')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER inject_unreviewed_remove BEFORE DELETE ON host FOR EACH ROW BEGIN IF OLD.id={device} THEN INSERT INTO graph_local (host_id) VALUES (OLD.id); INSERT INTO data_local (host_id) VALUES (OLD.id); END IF; END$$\nDELIMITER ;")
        triggers.append('inject_unreviewed_remove')
        check(form.remove() == 502 and exists(device), 'association added during removal fails closed')
        check(hook_count(['1', [device]]) == 0, 'rejected removal emits no bulk action callback')
        check(harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE host_id={device}').strip() == '1', 'failed removal rolls back the unreviewed graph and reviewed graph changes')
        check(harness.sql(f'SELECT COUNT(*) FROM data_local WHERE host_id={device}').strip() == '2', 'failed removal rolls back the unreviewed data source and reviewed data-source changes')
        harness.sql('DROP TRIGGER inject_unreviewed_remove')
        triggers.remove('inject_unreviewed_remove')
        before = hook_count([str(device)]) + hook_count([device])
        check(form.remove() == 200, 'device removal retains graphs and disabled data sources')
        check(not exists(device), 'primary device row is removed')
        check(harness.sql(f"SELECT COUNT(*) FROM snmpagent_cache WHERE otype='DATA' AND oid LIKE '%.{device}' AND name='cactiApplDeviceStatus'").strip() == '0', 'device removal updates SNMP-agent cache')
        check(harness.sql(f'SELECT host_id FROM graph_local WHERE id={kept["graph"]}').strip() == '0', 'retained graph becomes unassigned')
        for data, dtd, rrd in kept['sources']:
            check(harness.sql(f'SELECT host_id FROM data_local WHERE id={data}').strip() == '0', 'retained data source becomes unassigned')
            check(harness.sql(f"SELECT active='' FROM data_template_data WHERE id={dtd}").strip() == '1', 'retained data source is disabled')
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_purge_action WHERE local_data_id={data}').strip() == '0', 'retention does not schedule RRD deletion')
        check(hook_count([str(device)]) + hook_count([device]) == before + 1, 'device removal preserves device-remove hook')
        check(hook_count(['1', [device]]) == 1, 'device removal preserves bulk action hook')

        first, second = create(), create()
        bulk = RemovalForm(harness, session, [first['device'], second['device']])
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_device_remove BEFORE DELETE ON host FOR EACH ROW BEGIN IF OLD.id={second['device']} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='removal fixture'; END IF; END$$\nDELIMITER ;")
        triggers.append('reject_device_remove')
        check(bulk.remove() == 502, 'device removal SQL failure cannot report success')
        check(exists(first['device']) and exists(second['device']), 'device removal failure rolls back whole primary batch')
        harness.sql('DROP TRIGGER reject_device_remove')
        triggers.remove('reject_device_remove')
        extra_graph = second['graph']
        harness.sql(f'INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES ({extra_graph},{first["sources"][0][2]})')
        purge = RemovalForm(harness, session, [first['device']])
        check(purge.remove('purge') == 409 and exists(first['device']), 'device removal rejects shared data-source purge')
        harness.sql(f'DELETE FROM graph_templates_item WHERE local_graph_id={extra_graph} AND task_item_id={first["sources"][0][2]}')
        harness.sql(f'INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id) VALUES (16777214,{first["graph"]})')
        check(purge.remove('purge') == 409, 'device removal rejects aggregate graph purge')
        harness.sql(f'DELETE FROM aggregate_graphs_items WHERE local_graph_id={first["graph"]}')
        harness.sql(f'INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id) VALUES ({first["graph"]},{extra_graph})')
        check(purge.remove('purge') == 200, 'device removal purges graphs and all owned data sources')
        check(harness.sql(f'SELECT COUNT(*) FROM aggregate_graphs_items WHERE aggregate_graph_id={first["graph"]} AND local_graph_id={extra_graph}').strip() == '1', 'purge ignores unrelated aggregate IDs that collide with selected graph IDs')
        check(harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE id={first["graph"]}').strip() == '0', 'purged graph is removed')
        events = [json.loads(line) for line in harness.command('cat', '/artifacts/plugin.jsonl')['stdout'].splitlines()]
        for data, dtd, rrd in first['sources']:
            occurrences = 0
            for event in events:
                args = event.get('args', [])
                if event.get('callback') == 'event' and args and isinstance(args[0], (list, dict)):
                    values = args[0].values() if isinstance(args[0], dict) else args[0]
                    occurrences += sum(str(value) == str(data) for value in values)
            check(occurrences == 1, 'data-source purge callback runs once per linked or ungraphed source')
            for table, where in [('data_local', f'id={data}'), ('data_template_data', f'id={dtd}'), ('data_template_rrd', f'id={rrd}'), ('data_input_data', f'data_template_data_id={dtd}')]:
                check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE {where}').strip() == '0', 'purge removes graph-linked and ungraphed data configuration: ' + table)
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_purge_action WHERE local_data_id={data}').strip() == '1', 'purge schedules configured RRD maintenance')

        mixed_local, mixed_remote = create(), create(poller)
        # A stale replica does not grant permission to delete on its collector.
        harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={mixed_local["device"]}')
        mixed_ids = [mixed_local['device'], mixed_remote['device']]
        before_mixed = hook_count(mixed_ids)
        mixed = RemovalForm(harness, session, [mixed_local['device'], mixed_remote['device']])
        check(mixed.remove() == 200, 'mixed collector removal succeeds for each owning collector')
        check(hook_count(mixed_ids) == before_mixed + 1, 'mixed removal invokes one device-remove hook for the entire selection')
        check(not exists(mixed_local['device']), 'mixed removal deletes the local device')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={mixed_remote["device"]}').strip() == '0', 'mixed removal purges the assigned remote device')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={mixed_local["device"]}').strip() == '1', 'mixed removal preserves copies outside the owning collector')

        remote = create(poller)
        remote_form = RemovalForm(harness, session, [remote['device']])
        remote_extra = int(harness.sql(f'INSERT INTO create_remote.graph_local (host_id) VALUES ({remote["device"]}); SELECT LAST_INSERT_ID()').strip())
        check(remote_form.remove() == 502 and exists(remote['device']), 'collector association drift prevents device removal')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.graph_local WHERE id={remote_extra}').strip() == '1', 'collector drift is not silently purged')
        harness.sql(f'DELETE FROM create_remote.graph_local WHERE id={remote_extra}')
        outside_link = int(harness.sql(f'INSERT INTO create_remote.graph_templates_item (local_graph_id,task_item_id) VALUES (0,{remote["sources"][0][2]}); SELECT LAST_INSERT_ID()').strip())
        try:
            check(remote_form.remove() == 502 and exists(remote['device']), 'remote removal rejects outside graph references before cleanup')
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.data_template_rrd WHERE id={remote["sources"][0][2]}').strip() == '1', 'remote shared reference preserves reviewed data')
        finally:
            harness.sql(f'DELETE FROM create_remote.graph_templates_item WHERE id={outside_link}')

        harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
        check(remote_form.remove() == 502 and exists(remote['device']), 'offline collector prevents device removal')
        harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql("CREATE TRIGGER create_remote.reject_device_remove BEFORE DELETE ON create_remote.host FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='remote removal fixture'")
        triggers.append('create_remote.reject_device_remove')
        check(remote_form.remove() == 502, 'remote removal failure cannot report success')
        check(harness.sql(f"SELECT deleted='' FROM host WHERE id={remote['device']}").strip() == '1', 'remote removal failure rolls back primary tombstone')
        for data, dtd, rrd in remote['sources']:
            for table, where in [('data_template_data', f'id={dtd}'), ('data_template_rrd', f'id={rrd}'), ('data_input_data', f'data_template_data_id={dtd}'), ('poller_item', f'local_data_id={data}')]:
                check(harness.sql(f'SELECT COUNT(*) FROM create_remote.{table} WHERE {where}').strip() == '1', 'remote removal failure rolls back dependent cleanup')

        harness.sql('DROP TRIGGER create_remote.reject_device_remove')
        triggers.remove('create_remote.reject_device_remove')
        check(remote_form.remove() == 200, 'device removal recovers after remote purge failure')
        check(harness.sql(f"SELECT deleted FROM host WHERE id={remote['device']}").strip() == 'on', 'remote device retains primary cleanup tombstone')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={remote["device"]}').strip() == '0', 'remote device is purged before success')
        check(harness.sql(f'SELECT host_id FROM graph_local WHERE id={remote["graph"]}').strip() == '0', 'remote retention preserves and unassigns the primary graph')
        for data, dtd, rrd in remote['sources']:
            check(harness.sql(f'SELECT host_id FROM data_local WHERE id={data}').strip() == '0', 'remote retention preserves and unassigns the primary data source')
            check(harness.sql(f"SELECT active='' FROM data_template_data WHERE id={dtd}").strip() == '1', 'remote retention keeps the primary data configuration disabled')
            check(harness.sql(f'SELECT COUNT(*) FROM data_template_rrd WHERE id={rrd}').strip() == '1', 'remote retention preserves the primary RRD definition')
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_purge_action WHERE local_data_id={data}').strip() == '0', 'remote retention never schedules RRD deletion')
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.data_input_data WHERE data_template_data_id={dtd}').strip() == '0', 'remote removal purges dependent configuration')
    finally:
        for trigger in triggers:
            harness.sql('DROP TRIGGER ' + trigger)
        harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        for name, value in [('enable_snmp_agent', saved_agent), ('rrd_autoclean', saved), ('rrd_autoclean_method', saved_method)]:
            harness.sql(f"REPLACE INTO settings (name,value) VALUES ('{name}',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
        for fixture in fixtures:
            device, graph = fixture['device'], fixture['graph']
            for prefix in ['', 'create_remote.']:
                for table, column, value in [('host', 'id', device), ('graph_local', 'id', graph), ('graph_templates_item', 'local_graph_id', graph), ('aggregate_graphs_items', 'local_graph_id', graph), ('poller_item', 'host_id', device)]:
                    harness.sql(f'DELETE FROM {prefix}{table} WHERE {column}={value}')
                for data, dtd, rrd in fixture['sources']:
                    for table, column, value in [('data_local', 'id', data), ('data_template_data', 'id', dtd), ('data_template_rrd', 'id', rrd), ('data_input_data', 'data_template_data_id', dtd), ('data_source_purge_action', 'local_data_id', data)]:
                        harness.sql(f'DELETE FROM {prefix}{table} WHERE {column}={value}')
