"""Device graph-template association mutation and retention through Symfony."""
import json
from device_collector_scenarios import CollectorForm
from device_edit_scenarios import Inputs


class AssociationForm(CollectorForm):
    def __init__(self, harness, session, device, kind='graph'):
        super().__init__(harness, session, device)
        self.path = f'/app.php/inventory/devices/{device}/associations/{kind}'

    def fields(self):
        status, body = self.request()
        if status != 200:
            raise AssertionError(f'Association form unavailable: {status}')
        parser = Inputs()
        parser.feed(body)
        return parser.fields


def verify_graph_associations(harness, session, check, poller=1):
    device = None
    trigger = False
    remote_template_removed = False
    prefix = 'create_remote.' if poller > 1 else ''
    saved_automation = harness.sql("SELECT value FROM settings WHERE name='automation_graphs_enabled'").strip()
    saved_test_source = None
    target = None
    def association_events():
        return [json.loads(line)['args'] for line in harness.command('cat', '/artifacts/plugin.jsonl')['stdout'].splitlines()
                if json.loads(line).get('callback') == 'graph_association']
    check(harness.php('-r', 'require "include/global.php"; function setup_association_hook() { api_plugin_register_hook("compatibility_test","add_graph_template_to_host","compatibility_graph_association","setup.php",true); } setup_association_hook();')['exit'] == 0, 'graph association plugin hook registered')
    try:
        device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,availability_method) VALUES (CONVERT(UNHEX('67726170682d6173736f63696174696f6e2d6669787475726520f09f8c8f') USING utf8mb4),'127.0.0.1',{poller},0,0,0); SELECT LAST_INSERT_ID()").strip())
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={device}')
        target = int(harness.sql('SELECT DISTINCT gt.id FROM graph_templates gt LEFT JOIN snmp_query_graph sqg ON sqg.graph_template_id=gt.id INNER JOIN graph_templates_item gti ON gti.graph_template_id=gt.id INNER JOIN data_template_rrd dtr ON gti.task_item_id=dtr.id INNER JOIN data_template_data dtd ON dtd.data_template_id=dtr.data_template_id WHERE sqg.name IS NULL AND gti.local_graph_id=0 AND dtr.local_data_id=0 ORDER BY gt.id LIMIT 1').strip())
        form = AssociationForm(harness, session, device)
        fields = form.fields() | {'device_association[operation]': 'add', 'device_association[target]': str(target)}
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph associations GET is read-only')
        check(form.request(fields=fields, origin=False)[0] == 422, 'graph associations require same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_association[_token]')
        check(form.request(fields=missing)[0] == 422, 'graph associations require CSRF token')
        check(form.request(fields=fields | {'device_association[target]': '16777214'})[0] == 422, 'graph associations reject unavailable templates')
        check(form.request(fields=fields | {'device_association[poller_id]': '2'})[0] == 422, 'graph associations reject extra fields')
        harness.sql(f'INSERT INTO host_graph (host_id,graph_template_id) VALUES ({device},{target})')
        check(form.request(fields=fields)[0] == 409, 'concurrent graph associations invalidate confirmation')
        harness.sql(f'DELETE FROM host_graph WHERE host_id={device}')
        if poller > 1:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.request(fields=fields)[0] == 502, 'graph association refuses offline collector before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
            harness.sql(f'DELETE FROM create_remote.graph_templates WHERE id={target}')
            remote_template_removed = True
            check(form.request(fields=fields)[0] == 502, 'graph association rejects a stale remote template catalog')
            check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'missing remote template rolls back the primary association')
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device}').strip() == '0', 'missing remote template leaves no orphan collector association')
            harness.sql(f'INSERT INTO create_remote.graph_templates SELECT * FROM graph_templates WHERE id={target}')
            remote_template_removed = False
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_graph_association BEFORE INSERT ON {prefix}host_graph FOR EACH ROW BEGIN IF NEW.host_id={device} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='graph association rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.request(fields=fields)[0] == 502, 'graph association SQL rejection cannot report success')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph association failure rolls back primary writes')
        harness.sql(f'DROP TRIGGER {prefix}reject_graph_association')
        trigger = False
        saved_test_source = harness.sql(f'SELECT test_source FROM graph_templates WHERE id={target}').strip()
        harness.sql(f"UPDATE graph_templates SET test_source='' WHERE id={target}; REPLACE INTO settings (name,value) VALUES ('automation_graphs_enabled','on')")
        before_events = len(association_events())
        check(form.request(fields=fields)[0] == 200, 'graph association adds through Symfony')
        check(association_events()[before_events:] == [[{'host_id': device, 'graph_template_id': target}]], 'graph association invokes plugin hook once with exact payload')
        check(int(harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE host_id={device} AND graph_template_id={target}').strip()) > 0, 'graph association automation creates a graph')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device} AND graph_template_id={target}').strip() == '1', 'graph association persists selected template')
        if poller > 1:
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device} AND graph_template_id={target}').strip() == '1', 'graph association verifies remote template')
        harness.sql(f'INSERT INTO graph_local (host_id,graph_template_id) VALUES ({device},{target})')
        graphs = harness.sql(f'SELECT id FROM graph_local WHERE host_id={device} ORDER BY id')
        fields = form.fields() | {'device_association[operation]': 'remove', 'device_association[target]': str(target)}
        check(form.request(fields=fields)[0] == 200, 'graph association removes through Symfony')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph association removal persists')
        check(harness.sql(f'SELECT id FROM graph_local WHERE host_id={device} ORDER BY id') == graphs, 'graph association removal retains existing graphs')
        if poller > 1:
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device}').strip() == '0', 'graph association removal reaches collector')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='add_graph_template_to_host' AND `function`='compatibility_graph_association'")
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('automation_graphs_enabled','{saved_automation}')")
        if target and saved_test_source is not None:
            harness.sql(f"UPDATE graph_templates SET test_source='{saved_test_source}' WHERE id={target}")
        if device:
            # Remove generated graph/data dependents while ownership is still known.
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                harness.sql(f'DELETE FROM {database}graph_templates_item WHERE local_graph_id IN (SELECT id FROM {database}graph_local WHERE host_id={device}); DELETE FROM {database}graph_templates_graph WHERE local_graph_id IN (SELECT id FROM {database}graph_local WHERE host_id={device}); DELETE FROM {database}data_input_data WHERE data_template_data_id IN (SELECT id FROM {database}data_template_data WHERE local_data_id IN (SELECT id FROM {database}data_local WHERE host_id={device})); DELETE FROM {database}data_template_rrd WHERE local_data_id IN (SELECT id FROM {database}data_local WHERE host_id={device}); DELETE FROM {database}data_template_data WHERE local_data_id IN (SELECT id FROM {database}data_local WHERE host_id={device}); DELETE FROM {database}data_local WHERE host_id={device}; DELETE FROM {database}poller_item WHERE host_id={device}')
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_graph_association')
        if remote_template_removed:
            harness.sql(f'INSERT INTO create_remote.graph_templates SELECT * FROM graph_templates WHERE id={target}')
        if device:
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                harness.sql(f'DELETE FROM {database}host_graph WHERE host_id={device}; DELETE FROM {database}graph_local WHERE host_id={device}; DELETE FROM {database}host WHERE id={device}')
