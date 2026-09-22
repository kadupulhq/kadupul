"""Collector reassignment through actual Symfony forms and isolated workers."""
from urllib.request import Request
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from device_edit_scenarios import Inputs


class CollectorForm:
    def __init__(self, harness, session, device_id):
        self.harness = harness
        self.session = session
        self.path = f'/app.php/inventory/devices/{device_id}/collector'

    def request(self, fields=None, path=None, origin=True):
        request = Request(self.harness.base + (path or self.path), data=None if fields is None else urlencode(fields).encode(), headers={'Origin': self.harness.base} if origin else {})
        try:
            response = self.session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode()

    def fields(self):
        status, body = self.request()
        if status != 200:
            raise AssertionError('Collector form did not load')
        parser = Inputs()
        parser.feed(body)
        if urlsplit(parser.action).path != self.path or urlsplit(parser.action).netloc:
            raise AssertionError('Collector form has an unexpected action')
        return parser.fields

    def assign(self, collector, fields=None):
        return self.request(fields=(fields or self.fields()) | {'device_collector[collector_id]': str(collector)})[0]


def verify_device_collector(harness, session, device_id, hidden_id, check):
    form = CollectorForm(harness, session, device_id)
    original = harness.sql(f'SELECT poller_id,host_template_id FROM host WHERE id={device_id}').strip().split('\t')
    offline = int(harness.sql("INSERT INTO poller (name,hostname,last_status) VALUES ('Assignment offline','offline.invalid','2000-01-01 00:00:00'); SELECT LAST_INSERT_ID()").strip())
    try:
        fields = form.fields()
        check('device_collector[_token]' in fields, 'collector form includes CSRF and fixed action')
        check(form.request(path=f'/app.php/inventory/devices/{hidden_id}/collector')[0] == 404, 'collector assignment hides inaccessible devices')
        check(form.request(path='/app.php/inventory/devices/99999999/collector')[0] == 404, 'collector assignment hides missing devices')
        check(form.assign(original[0]) == 200, 'unchanged collector assignment is a no-op')
        check(form.request(fields=fields, origin=False)[0] == 422, 'collector assignment requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_collector[_token]')
        check(form.request(fields=missing)[0] == 422, 'collector assignment requires CSRF token')
        for invalid in ['', '0', '-1', '16777216', '9999999', 'bad']:
            check(form.assign(invalid) == 422, 'invalid collector rejected: ' + repr(invalid))
        missing = dict(fields)
        missing.pop('device_collector[collector_id]')
        check(form.request(fields=missing)[0] == 422, 'collector assignment cannot omit its target')
        check(form.request(fields=fields | {'device_collector[template_id]': '0'})[0] == 422, 'collector form rejects unrelated fields')
        stale = form.fields()
        harness.sql(f'UPDATE host SET host_template_id=16777214 WHERE id={device_id}')
        check(form.assign(offline, stale) == 409, 'template changes invalidate collector confirmations')
        harness.sql(f'UPDATE host SET host_template_id={original[1]} WHERE id={device_id}')
        check(form.assign(offline) == 502, 'offline target collector prevents assignment')
        check(harness.sql(f'SELECT poller_id FROM host WHERE id={device_id}').strip() == original[0], 'offline target leaves polling ownership unchanged')
        harness.sql(f"UPDATE poller SET disabled='on' WHERE id={offline}")
        check(form.assign(offline) == 422, 'disabled collector cannot be selected')
    finally:
        harness.sql(f'UPDATE host SET poller_id={original[0]},host_template_id={original[1]} WHERE id={device_id}')
        harness.sql(f'DELETE FROM poller WHERE id={offline}')


def verify_remote_collector_assignment(harness, session, device_id, poller, check):
    form = CollectorForm(harness, session, device_id)
    data = int(harness.sql(f'INSERT INTO data_local (host_id) VALUES ({device_id}); SELECT LAST_INSERT_ID()').strip())
    graph = int(harness.sql(f'INSERT INTO graph_local (host_id) VALUES ({device_id}); SELECT LAST_INSERT_ID()').strip())
    dtd = int(harness.sql(f"INSERT INTO data_template_data (local_data_id,name) VALUES ({data},'collector fixture'); SELECT LAST_INSERT_ID()").strip())
    template_graph = int(harness.sql('SELECT MIN(id) FROM graph_templates').strip())
    trigger = False
    try:
        harness.sql(f"INSERT INTO data_template_rrd (local_data_id,data_source_name) VALUES ({data},'collector')")
        harness.sql(f"INSERT INTO data_input_data (data_template_data_id,data_input_field_id,value) VALUES ({dtd},1,'collector input')")
        harness.sql(f"INSERT INTO graph_templates_item (local_graph_id,text_format) VALUES ({graph},'collector graph')")
        harness.sql(f"INSERT INTO poller_item (local_data_id,host_id,poller_id,rrd_name) VALUES ({data},{device_id},{poller},'collector')")
        harness.sql(f'REPLACE INTO host_graph (host_id,graph_template_id) VALUES ({device_id},{template_graph})')
        stale = form.fields()
        check(form.assign(1) == 200, 'collector reassignment moves remote device to primary')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={device_id}').strip() == '0', 'collector reassignment verifies old host cleanup')
        check(harness.sql(f'SELECT poller_id FROM poller_item WHERE local_data_id={data}').strip() == '1', 'primary move updates polling item ownership')
        check(form.assign(poller, stale) == 409, 'collector moves invalidate stale confirmations')
        check(form.assign(poller) == 200, 'collector reassignment replicates primary device to remote')
        check(harness.sql(f'SELECT poller_id FROM create_remote.host WHERE id={device_id}').strip() == str(poller), 'collector reassignment verifies target identity')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device_id} AND graph_template_id={template_graph}').strip() == '1', 'collector reassignment preserves host graph associations')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.data_local WHERE id={data}').strip() == '1' and harness.sql(f'SELECT COUNT(*) FROM create_remote.graph_local WHERE id={graph}').strip() == '1', 'collector reassignment preserves graph and data identities')
        check(harness.sql(f'SELECT value FROM create_remote.data_input_data WHERE data_template_data_id={dtd} AND data_input_field_id=1').strip() == 'collector input', 'collector reassignment copies data input configuration')
        check(harness.sql(f'SELECT COUNT(*) FROM poller_command WHERE poller_id={poller} AND action=3 AND command="{device_id}"').strip() == '0', 'returning device cancels obsolete queued purge')
        check(form.assign(1) == 200, 'collector reassignment can return to primary')
        harness.sql("CREATE TRIGGER create_remote.reject_collector_graph BEFORE INSERT ON create_remote.host_graph FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='collector fixture rejection'")
        trigger = True
        check(form.assign(poller) == 502, 'collector replication failure cannot report success')
        check(harness.sql(f'SELECT poller_id FROM host WHERE id={device_id}').strip() == '1', 'collector replication failure rolls back primary ownership')
    finally:
        if trigger:
            harness.sql('DROP TRIGGER create_remote.reject_collector_graph')
        # The primary is authoritative after an uncertain remote write.
        # Restore the creation fixture through the real successful move.
        check(form.assign(poller) == 200, 'collector assignment recovers after rejected replication')
        for prefix in ['', 'create_remote.']:
            harness.sql(f'DELETE FROM {prefix}poller_item WHERE local_data_id={data}')
            harness.sql(f'DELETE FROM {prefix}data_input_data WHERE data_template_data_id={dtd}')
            harness.sql(f'DELETE FROM {prefix}data_template_data WHERE id={dtd}')
            harness.sql(f'DELETE FROM {prefix}data_template_rrd WHERE local_data_id={data}')
            harness.sql(f'DELETE FROM {prefix}data_local WHERE id={data}')
            harness.sql(f'DELETE FROM {prefix}graph_templates_item WHERE local_graph_id={graph}')
            harness.sql(f'DELETE FROM {prefix}graph_local WHERE id={graph}')
            harness.sql(f'DELETE FROM {prefix}host_graph WHERE host_id={device_id} AND graph_template_id={template_graph}')
