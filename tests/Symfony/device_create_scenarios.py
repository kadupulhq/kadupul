"""Device creation through real Symfony HTTP and the isolated legacy save API."""
import base64
import json
import uuid
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request
from device_edit_scenarios import Inputs
from harness import Session

# Runs a legacy worker directly. With a lock, the probe holds that row until
# the worker blocks inside its own transaction, then reports whether the
# worker's audit record already existed at that moment.
WORKER_PROBE = r'''
require 'include/vendor/autoload.php';
[, $script, $payload, $lock, $waitFor, $correlation] = $argv;
$db = null;
$waiting = false;
$pending = false;
if ($lock !== 'none') {
    $db = (new Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase(new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd())))->get();
    $db->beginTransaction();
    $db->query($lock)->fetchAll();
}
$worker = proc_open([PHP_BINARY, $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
fwrite($pipes[0], base64_decode($payload));
fclose($pipes[0]);
if ($db !== null) {
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID != CONNECTION_ID() AND INFO LIKE ?');
    for ($deadline = microtime(true) + 15; !$waiting && microtime(true) < $deadline && proc_get_status($worker)['running']; usleep(20000)) {
        $query->execute([$waitFor]);
        $waiting = (int) $query->fetchColumn() > 0;
    }
    $pending = is_file('log/kadupul-audit.jsonl') && str_contains(file_get_contents('log/kadupul-audit.jsonl'), $correlation);
    $db->rollBack();
}
$output = stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($worker);
echo json_encode(['output' => $output, 'waiting' => $waiting, 'pending_record' => $pending]);
'''


def run_worker(harness, script, marker, command, lock='none', wait_for='none'):
    payload = base64.b64encode(json.dumps(command).encode()).decode()
    probe = json.loads(harness.php('-r', WORKER_PROBE, script, payload, lock, wait_for, str(command.get('correlation_id')))['stdout'])
    probe['result'] = json.loads(probe['output'].split(marker + '=')[-1])
    return probe


def audit_events(harness, correlation_id):
    return [event for event in harness.jsonl('/var/www/html/log/kadupul-audit.jsonl') if event.get('correlation_id') == correlation_id]


def verify_device_create(harness, session, user_id, check):
    path = '/app.php/inventory/devices/new'
    created = []
    template = int(harness.sql("INSERT INTO host_template (hash,name) VALUES ('device-create-fixture','Creation fixture'); SELECT LAST_INSERT_ID()").strip())
    graph = int(harness.sql('SELECT MIN(id) FROM graph_templates').strip())
    harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graph})')
    before = int(harness.sql('SELECT COUNT(*) FROM host').strip())

    def post(fields, origin=harness.base, client=session):
        request = Request(harness.base + path, data=urlencode(fields).encode(), headers={} if origin is None else {'Origin': origin})
        try:
            response = client.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    def worker(actor, values, correlation_id=None):
        command = {'correlation_id': correlation_id or uuid.uuid4().hex, 'actor': actor, 'fields': values}
        return run_worker(harness, 'bin/legacy-device-create.php', 'KADUPUL_CREATE_RESULT', command)['result']

    def recorded(correlation_id, decision, outcome, target='new'):
        return [(event['actor'], event['action'], event['target'], event['decision'], event['outcome'])
                for event in audit_events(harness, correlation_id)] == [({'id': user_id}, 'inventory.device.create', {'type': 'device', 'id': target}, decision, outcome)]

    try:
        for action in ('--install', '--enable'):
            check(harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', action)['exit'] == 0, 'creation plugin fixture enabled')
        check(harness.php('-r', 'require "include/global.php"; function setup_create_hook() { api_plugin_register_hook("compatibility_test", "host_save", "compatibility_test_filter", "setup.php", true); api_plugin_register_hook("compatibility_test", "api_device_new", "compatibility_test_filter", "setup.php", true); } setup_create_hook();')['exit'] == 0, 'creation hooks registered')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('snmp_community','create-test-community'),('snmp_password','create-test-auth'),('snmp_priv_passphrase','create-test-privacy')")
        with session.opener.open(harness.base + path) as response:
            body = response.read().decode()
            check('no-store' in response.headers.get('Cache-Control', ''), 'device creation form is not cached')
        parser = Inputs()
        parser.feed(body)
        check(all(secret not in body for secret in ['create-test-community', 'create-test-auth', 'create-test-privacy']), 'configured credentials never enter the form')
        check('device_create[_token]' in parser.fields, 'device creation has its own CSRF token')
        fields = parser.fields | {'device_create[description]': 'create-device-fixture 東京 <script>', 'device_create[hostname]': '127.0.0.1', 'device_create[host_template_id]': str(template), 'device_create[site_id]': '0', 'device_create[poller_id]': '1', 'device_create[snmp_version]': '0', 'device_create[availability_method]': '0', 'device_create[notes]': 'creation notes 🌏', 'device_create[location]': 'Rack <west>', 'device_create[external_id]': 'asset-create', 'device_create[enabled]': '0'}
        for data in [fields | {'device_create[id]': '1'}, fields | {'device_create[poller_id]': '99999'}, fields | {'device_create[description]': ''}, fields | {'device_create[snmp_port]': '65536'}, fields | {'device_create[snmp_community]': 'private-test-secret'}]:
            status, body, _ = post(data)
            check(status == 422 and 'private-test-secret' not in body, 'invalid device creation rejects fields and does not redisplay secrets')
        check(post({k:v for k,v in fields.items() if k != 'device_create[_token]'}, origin=None)[0] == 422, 'device creation rejects missing CSRF')
        check(post(fields, origin='https://attacker.invalid')[0] == 422, 'device creation rejects cross-origin requests')
        check(post(fields, client=Session(harness.base))[0] == 401, 'device creation rejects anonymous requests')
        command = {'description': 'rejected-worker-fixture', 'hostname': '127.0.0.1', 'snmp_version': '0', 'availability_method': '0'}
        rejected = uuid.uuid4().hex
        secret = command | {'site_id': '4294967295', 'use_default_credentials': False, 'snmp_community': 'audit-worker-secret'}
        check(worker(user_id, secret, rejected)['status'] == 'invalid', 'worker rechecks reference existence before saving')
        check(recorded(rejected, 'allowed', 'failed'), 'post-authorization creation failure records the structured failure outcome')
        malformed = 'A' * 32
        check(worker(user_id, command, malformed)['status'] == 'invalid' and audit_events(harness, malformed) == [],
              'worker replaces a malformed correlation identifier')
        check(worker(user_id, command | {'id': 1})['status'] == 'invalid', 'worker cannot turn creation into an update')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            denied = uuid.uuid4().hex
            check(worker(user_id, command, denied)['status'] == 'denied', 'worker independently rechecks revoked actor permissions')
            check(recorded(denied, 'denied', 'denied'), 'persistence authorization rejection records the structured denial')
            check(post(fields)[0] == 403 and session.request(path)['status'] == 403, 'device creation requires administration realm')
        finally:
            harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        check(int(harness.sql('SELECT COUNT(*) FROM host').strip()) == before, 'invalid device creations cannot overwrite or insert hosts')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('time_last_change_device','1'),('time_last_change_site_device','1')")
        status, body, url = post(fields)
        ids = harness.sql("SELECT id FROM host WHERE description LIKE 'create-device-fixture%'").strip()
        if ids:
            created.extend(int(v) for v in ids.splitlines())
        check(status == 200 and urlsplit(url).path == '/app.php/inventory/devices' and 'Device created.' in body, 'creation redirects to the permission-filtered device list: ' + str(status))
        check(len(created) == 1, 'creation inserts exactly one device')
        device_id = created[0]
        check(any(event.get('action') == 'inventory.device.create' and event.get('target') == {'type': 'device', 'id': str(device_id)}
                  and event.get('actor') == {'id': user_id} and (event.get('decision'), event.get('outcome')) == ('allowed', 'succeeded')
                  for event in harness.jsonl('/var/www/html/log/kadupul-audit.jsonl')), 'successful creation records the structured audit contract')
        row = harness.rows(f"SELECT JSON_OBJECT('description',HEX(description),'hostname',hostname,'notes',HEX(notes),'location',location,'external_id',external_id,'disabled',disabled,'snmp_community',snmp_community,'host_template_id',host_template_id) FROM host WHERE id={device_id}")[0]
        for key in ['description','notes']:
            row[key] = bytes.fromhex(row[key]).decode()
        for key in ['description','hostname','notes','location','external_id']:
            check(row[key] == fields['device_create[' + key + ']'], 'device creation persists ' + key)
        check(row['snmp_community'] == 'create-test-community' and row['disabled'] == 'on', 'worker resolves configured credentials and disabled state')
        check(int(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device_id} AND graph_template_id={graph}').strip()) == 1, 'legacy template graph associations are preserved')
        check(int(harness.sql("SELECT COUNT(*) FROM settings WHERE name IN ('time_last_change_device','time_last_change_site_device') AND CAST(value AS UNSIGNED)>1").strip()) == 2, 'device creation updates both cache markers')
        events = harness.jsonl('/artifacts/plugin.jsonl')
        check(any(event.get('callback') == 'filter' and isinstance(event.get('args'), list) and len(event['args']) == 1 and isinstance(event['args'][0], dict) and str(event['args'][0].get('host_id')) == str(device_id) for event in events), 'legacy host-save hook receives the new device')
        check(any(event.get('callback') == 'filter' and isinstance(event.get('args'), list) and len(event['args']) == 1 and isinstance(event['args'][0], dict) and event['args'][0].get('description') == fields['device_create[description]'] for event in events), 'legacy device-new hook receives the creation payload')
        explicit = {k:v for k,v in fields.items() if k != 'device_create[use_default_credentials]'}
        explicit.update({'device_create[description]': 'create-device-explicit-fixture', 'device_create[host_template_id]': '0', 'device_create[snmp_community]': 'explicit-fixture-secret'})
        status, body, _ = post(explicit)
        ids = harness.sql("SELECT id FROM host WHERE description='create-device-explicit-fixture'").strip()
        if ids:
            created.extend(int(v) for v in ids.splitlines())
        check(status == 200 and 'explicit-fixture-secret' not in body and len(created) == 2, 'explicit credentials create a device without being rendered')
        check(harness.sql(f'SELECT snmp_community FROM host WHERE id={created[-1]}').strip() == 'explicit-fixture-secret', 'explicit credentials are not replaced by installation defaults')
        from device_creation_review_scenarios import verify_creation_compatibility
        verify_creation_compatibility(harness, post, fields, created, user_id, check, session)
        check(session.request('/bin/legacy-device-create.php')['status'] in (403,404), 'device creation worker is inaccessible over HTTP')
        pending = uuid.uuid4().hex
        # The worker blocks on the collector row after authorization, inside its transaction.
        locked = run_worker(harness, 'bin/legacy-device-create.php', 'KADUPUL_CREATE_RESULT',
                            {'correlation_id': pending, 'actor': user_id, 'fields': command | {'description': 'create-audit-order-fixture'}},
                            lock='SELECT id FROM poller WHERE id = 1 FOR UPDATE', wait_for='SELECT id FROM poller WHERE id = % LOCK IN SHARE MODE')
        ids = harness.sql("SELECT id FROM host WHERE description='create-audit-order-fixture'").strip()
        if ids:
            created.extend(int(v) for v in ids.splitlines())
        check(locked['waiting'] and not locked['pending_record'], 'device creation audit is not written while its transaction is open')
        check(locked['result']['status'] == 'ok' and recorded(pending, 'allowed', 'succeeded', str(locked['result']['id'])),
              'blocked creation records success once its commit resolves')
        audit = harness.command('cat', '/var/www/html/log/kadupul-audit.jsonl', check=True)['stdout']
        check(all(marker not in audit for marker in ('audit-worker-secret', 'explicit-fixture-secret', 'create-device-fixture', 'rejected-worker-fixture', 'Invalid reference', 'Access denied', malformed)),
              'structured creation audit excludes submitted fields, credentials and exception text')
    finally:
        for device_id in created:
            harness.sql(f'DELETE FROM host_graph WHERE host_id={device_id}; DELETE FROM host_snmp_query WHERE host_id={device_id}; DELETE FROM host WHERE id={device_id}')
        harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={template}; DELETE FROM host_template WHERE id={template}')
        for action in ('--disable','--uninstall'):
            harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', action)
