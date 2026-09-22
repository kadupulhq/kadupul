"""Device-template assignment through real Symfony forms and legacy writes."""
import json
from pathlib import Path
from urllib.request import Request
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from device_edit_scenarios import Inputs


def verify_device_template(harness, session, user_id, device_id, hidden_id, check):
    path = f'/app.php/inventory/devices/{device_id}/template'
    def request(target=path, fields=None, origin=True):
        req = Request(harness.base + target, data=None if fields is None else urlencode(fields).encode(), headers={'Origin': harness.base} if origin else {})
        try:
            response = session.opener.open(req)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode()
    def form():
        status, body = request()
        check(status == 200, 'device template assignment form loads')
        parser = Inputs()
        parser.feed(body)
        check(urlsplit(parser.action).path == path and not urlsplit(parser.action).netloc and 'device_template[_token]' in parser.fields, 'template form has fixed action and CSRF protection')
        return parser.fields
    probe = Path(__file__).with_name('device_template_authorization_probe.php').read_text().removeprefix('<?php')
    result = harness.php('-r', probe, str(user_id), str(device_id))
    check(result['exit'] == 0 and all(json.loads(result['stdout']).values()), 'template visibility permissions are current and locked through persistence: ' + repr(result))
    check(harness.php('-r', 'require "include/global.php"; function setup_template_change() { api_plugin_register_hook("compatibility_test","device_template_change","compatibility_test_filter","setup.php",true); } setup_template_change();')['exit'] == 0, 'template-change hook fixture registered')
    def changes(template_id):
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        return sum(json.loads(line).get('args') == [{'device_id': device_id, 'device_template_id': template_id}] for line in events.splitlines())
    original = harness.sql(f'SELECT host_template_id,poller_id FROM host WHERE id={device_id}').strip().split('\t')
    template = int(harness.sql("INSERT INTO host_template (hash,name) VALUES ('template-assignment-fixture','Template <assignment>'); SELECT LAST_INSERT_ID()").strip())
    graph = int(harness.sql("SELECT id FROM graph_templates ORDER BY id LIMIT 1").strip())
    query = int(harness.sql('SELECT MIN(id) FROM snmp_query').strip())
    try:
        harness.sql(f'INSERT INTO host_template_snmp_query (host_template_id,snmp_query_id) VALUES ({template},{query})')
        harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graph})')
        fields = form()
        check(request(f'/app.php/inventory/devices/{hidden_id}/template')[0] == 404 and request('/app.php/inventory/devices/99999999/template')[0] == 404, 'template assignment hides inaccessible and missing devices equally')
        changed = fields | {'device_template[template_id]': str(template)}
        check(request(fields=changed, origin=False)[0] == 422, 'template assignment rejects missing CSRF origin')
        missing = dict(changed)
        missing.pop('device_template[_token]')
        check(request(fields=missing)[0] == 422, 'template assignment requires a CSRF token')
        for value in ['', '-1', '16777216', '9999999', 'bad']:
            check(request(fields=fields | {'device_template[template_id]': value})[0] == 422, 'invalid template choice rejected: ' + repr(value))
        missing = dict(fields)
        missing.pop('device_template[template_id]')
        check(request(fields=missing)[0] == 422, 'missing template choice cannot silently unassign')
        check(request(fields=changed | {'device_template[poller_id]': '2'})[0] == 422, 'template form cannot mass-assign collector identity')
        check(request(fields=changed)[0] == 200, 'device template assignment saves through Symfony')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={device_id}').strip() == str(template), 'selected template assignment persists')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device_id} AND graph_template_id={graph}').strip() == '1', 'template assignment verifies required graph associations')
        check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id={device_id} AND snmp_query_id={query}').strip() == '1', 'template assignment verifies required data-query associations')
        check(request(fields=fields)[0] == 409, 'template changes invalidate stale assignment forms')
        current = form()
        check(request(fields=current)[0] == 200, 'unchanged template assignment is a no-op')
        before_unassign = changes(0)
        check(request(fields=current | {'device_template[template_id]': '0'})[0] == 200, 'device template can be explicitly unassigned')
        check(changes(0) == before_unassign + 1, 'template unassignment invokes the template-change hook once')
        check(request(fields=form())[0] == 200 and changes(0) == before_unassign + 1, 'unchanged unassignment does not repeat the template-change hook')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device_id} AND graph_template_id={graph}').strip() == '1', 'unassignment retains existing graph associations')
        stale = form()
        harness.sql(f'UPDATE host SET poller_id=2 WHERE id={device_id}')
        check(request(fields=stale | {'device_template[template_id]': str(template)})[0] == 409, 'collector moves invalidate stale template forms')
        check(request(fields=form() | {'device_template[template_id]': str(template)})[0] == 502, 'offline collector prevents template assignment success')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={device_id}').strip() == '0', 'offline collector leaves the primary template unchanged')
        harness.sql(f'UPDATE host SET poller_id={original[1]} WHERE id={device_id}')
        fields = form()
        harness.sql(f'DELETE FROM host_template WHERE id={template}')
        check(request(fields=fields | {'device_template[template_id]': str(template)})[0] == 422, 'deleted templates cannot be assigned')
    finally:
        harness.sql(f'UPDATE host SET host_template_id={original[0]},poller_id={original[1]} WHERE id={device_id}')
        harness.sql(f'DELETE FROM host_graph WHERE host_id={device_id} AND graph_template_id={graph}')
        harness.sql(f'DELETE FROM host_snmp_query WHERE host_id={device_id} AND snmp_query_id={query}')
        harness.sql(f'DELETE FROM host_snmp_cache WHERE host_id={device_id} AND snmp_query_id={query}')
        harness.sql(f'DELETE FROM host_template_snmp_query WHERE host_template_id={template}')
        harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={template}')
        harness.sql(f'DELETE FROM host_template WHERE id={template}')


def verify_remote_template_assignment(harness, session, device_id, check):
    path = f'/app.php/inventory/devices/{device_id}/template'
    def assign(template):
        with session.opener.open(harness.base + path) as response:
            parser = Inputs()
            parser.feed(response.read().decode())
        fields = parser.fields | {'device_template[template_id]': str(template)}
        request = Request(harness.base + path, data=urlencode(fields).encode(), headers={'Origin': harness.base})
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status
    check(session.request('/bin/legacy-device-template.php')['status'] in (403, 404), 'template worker is inaccessible over HTTP')
    original = int(harness.sql(f'SELECT host_template_id FROM host WHERE id={device_id}').strip())
    templates = []
    trigger = False
    graphs = [int(value) for value in harness.sql('SELECT id FROM graph_templates ORDER BY id DESC LIMIT 2').splitlines()]
    try:
        for index, graph in enumerate(graphs):
            template = int(harness.sql(f"INSERT INTO host_template (hash,name) VALUES ('remote-template-{index}','Remote template {index}'); SELECT LAST_INSERT_ID()").strip())
            templates.append(template)
            harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graph})')
        def saves():
            events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
            return sum(json.loads(line).get('args') == [{'host_id': device_id}] for line in events.splitlines())
        check(harness.php('-r', 'require "include/global.php"; function setup_template_collector_lock() { api_plugin_register_hook("compatibility_test","device_template_change","compatibility_template_collector_lock","setup.php",true); } setup_template_collector_lock();')['exit'] == 0, 'template collector lock fixture registered')
        before = saves()
        check(assign(templates[0]) == 200, 'online collector template assignment succeeds')
        check(saves() == before + 1, 'template assignment preserves the legacy host-save hook')
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        check(any(json.loads(line).get('callback') == 'template_collector_lock' for line in events.splitlines()), 'template assignment blocks concurrent collector disable until commit')
        check(harness.sql(f'SELECT host_template_id FROM create_remote.host WHERE id={device_id}').strip() == str(templates[0]), 'collector template identity matches the primary')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device_id} AND graph_template_id={graphs[0]}').strip() == '1', 'collector receives required template associations')
        before = saves()
        check(assign(0) == 200, 'online collector template can be unassigned')
        check(saves() == before + 1, 'remote unassignment invokes the host-save hook once')
        for database in ['', 'create_remote.']:
            check(harness.sql(f'SELECT host_template_id FROM {database}host WHERE id={device_id}').strip() == '0', 'unassignment clears primary and remote template identities')
            check(harness.sql(f'SELECT COUNT(*) FROM {database}host_graph WHERE host_id={device_id} AND graph_template_id={graphs[0]}').strip() == '1', 'remote unassignment retains existing graph associations')
        check(assign(templates[0]) == 200, 'remote template can be reassigned after unassignment')
        harness.sql(f"UPDATE create_remote.host SET deleted='on' WHERE id={device_id}")
        check(assign(0) == 502, 'template unassignment refuses deleted remote devices')
        check(harness.sql(f'SELECT host_template_id FROM create_remote.host WHERE id={device_id}').strip() == str(templates[0]), 'deleted remote device retains its template')
        harness.sql(f"UPDATE create_remote.host SET deleted='' WHERE id={device_id}")
        harness.sql("CREATE TRIGGER create_remote.reject_template_graph BEFORE INSERT ON create_remote.host_graph FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='template fixture rejection'")
        trigger = True
        check(assign(templates[1]) == 502, 'collector association failure cannot report successful template assignment')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={device_id}').strip() == str(templates[0]), 'collector association failure rolls back the primary template')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_template_change' AND `function`='compatibility_template_collector_lock'")
        if trigger:
            harness.sql('DROP TRIGGER create_remote.reject_template_graph')
        for database in ['', 'create_remote.']:
            harness.sql(f'UPDATE {database}host SET host_template_id={original} WHERE id={device_id}')
            for graph in graphs:
                harness.sql(f'DELETE FROM {database}host_graph WHERE host_id={device_id} AND graph_template_id={graph}')
        for template in templates:
            harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={template}; DELETE FROM host_template WHERE id={template}')
