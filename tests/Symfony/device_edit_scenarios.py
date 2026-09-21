"""Real Symfony form, CSRF, authorization, concurrency and legacy-save checks."""
from html.parser import HTMLParser
from urllib.parse import urlencode
from urllib.request import Request
from urllib.error import HTTPError


class Inputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = {}
        self.select = None
        self.textarea = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and 'name' in attrs:
            self.fields[attrs['name']] = attrs.get('value', '')
        elif tag == 'select':
            self.select = attrs.get('name')
        elif tag == 'option' and self.select and 'selected' in attrs:
            self.fields[self.select] = attrs.get('value', '')
        elif tag == 'textarea':
            self.textarea = attrs.get('name')
            if self.textarea:
                self.fields[self.textarea] = ''
    def handle_data(self, data):
        if self.textarea:
            self.fields[self.textarea] += data
    def handle_endtag(self, tag):
        if tag == 'select':
            self.select = None
        elif tag == 'textarea':
            self.textarea = None


def verify_device_edit(harness, session, user_id, allowed_id, hidden_id, check):
    path = f'/app.php/inventory/devices/{allowed_id}/edit'
    def get_fields():
        response = session.opener.open(harness.base + path)
        body = response.read().decode()
        parser = Inputs()
        parser.feed(body)
        check('device_edit[_token]' in parser.fields and 'device_edit[revision]' in parser.fields,
              'Symfony renders CSRF and revision fields')
        return parser.fields
    def post(fields, origin=None, target=path):
        headers = {} if origin is None else {'Origin': origin}
        request = Request(harness.base + target, data=urlencode(fields).encode(), headers=headers)
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        body = response.read().decode()
        return response.status, body
    original = harness.sql(f'SELECT description FROM host WHERE id={allowed_id}').strip()
    fields = get_fields()
    check(fields.get('device_edit[enabled]') == 'enabled', 'editor displays current polling state')
    check(fields.get('device_edit[location]') == '' and fields.get('device_edit[external_id]') == '', 'legacy null metadata renders as empty fields')
    fields.update({'device_edit[location]': 'Rack <west>', 'device_edit[external_id]': 'asset-42'})
    fields.update({'device_edit[description]': 'Edited inventory device', 'device_edit[hostname]': 'edited.invalid', 'device_edit[notes]': '<script>alert(1)</script> notes'})
    check(post(fields)[0] == 422, 'save requires same-origin CSRF evidence')
    check(post(fields, 'https://attacker.invalid')[0] == 422, 'cross-origin save is rejected')
    for bad_state in ('', 'on', 'false', 'unexpected'):
        invalid_state = dict(fields, **{'device_edit[enabled]': bad_state})
        check(post(invalid_state, harness.base)[0] == 422, 'invalid polling choice is rejected: ' + repr(bad_state))
    missing_state = dict(fields)
    missing_state.pop('device_edit[enabled]')
    check(post(missing_state, harness.base)[0] == 422, 'omitted polling state cannot silently disable a device')
    for field in ('location', 'external_id'):
        too_long = dict(fields, **{f'device_edit[{field}]': '界' * 41})
        check(post(too_long, harness.base)[0] == 422, field + ' rejects overlong Unicode text')
    no_token = dict(fields)
    no_token.pop('device_edit[_token]')
    check(post(no_token, harness.base)[0] == 422, 'save requires the Symfony CSRF field')
    extra = dict(fields, **{'device_edit[snmp_community]': 'overwrite'})
    check(post(extra, harness.base)[0] == 422, 'unmigrated fields cannot be mass-assigned')
    invalid = dict(fields, **{'device_edit[hostname]': 'invalid; command'})
    check(post(invalid, harness.base)[0] == 422, 'domain rejects invalid device address')
    check(harness.sql(f'SELECT description FROM host WHERE id={allowed_id}').strip() == original,
          'rejected submissions do not change the device')
    check(session.request(f'/app.php/inventory/devices/{hidden_id}/edit')['status'] == 404,
          'hidden device cannot be loaded for editing')
    check(post(fields, harness.base, f'/app.php/inventory/devices/{hidden_id}/edit')[0] == 404,
          'hidden device cannot be edited directly')
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
    check(post(fields, harness.base)[0] == 403, 'realm revocation after form load prevents save')
    harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
    for field in ('location', 'external_id'):
        pending = get_fields()
        harness.sql(f"UPDATE host SET {field}='Concurrent metadata' WHERE id={allowed_id}")
        check(post(pending, harness.base)[0] == 409, field + ' change invalidates previously loaded form')
        harness.sql(f"UPDATE host SET {field}=NULL WHERE id={allowed_id}")
    harness.sql(f"UPDATE host SET description='Concurrent edit' WHERE id={allowed_id}")
    check(post(fields, harness.base)[0] == 409, 'stale edit reports conflict without overwriting')
    fresh = get_fields()
    fresh.update({k: v for k, v in fields.items() if k != 'device_edit[revision]'})
    graph_id = int(harness.sql(f'INSERT INTO graph_local (host_id) VALUES ({allowed_id}); SELECT LAST_INSERT_ID()').strip())
    harness.sql(f"INSERT INTO graph_templates_graph (local_graph_id,title,title_cache) VALUES ({graph_id},'|host_description| - |host_location| - |host_external_id|','Old graph title')")
    for action in ('--install', '--enable'):
        check(harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', action)['exit'] == 0,
              'compatibility plugin prepares for save-hook check')
    check(harness.php('-r', 'require "include/global.php"; function setup_edit_hook() { api_plugin_register_hook("compatibility_test", "host_save", "compatibility_test_filter", "setup.php", true); } setup_edit_hook();')['exit'] == 0,
          'legacy host-save hook is registered')
    check(harness.sql("SELECT status FROM plugin_hooks WHERE name='compatibility_test' AND hook='host_save'").strip() == '1', 'save hook is enabled in the registry')
    status, body = post(fresh, harness.base)
    check(status == 200 and 'Device saved.' in body, 'successful command redirects to the saved device')
    check(harness.sql(f'SELECT description,hostname FROM host WHERE id={allowed_id}').strip() == 'Edited inventory device\tedited.invalid',
          'legacy write adapter persists validated fields')
    check('&lt;script&gt;alert(1)&lt;/script&gt; notes' in body and '<script>alert(1)</script>' not in body,
          'stored notes remain escaped in the edit form')
    check(harness.sql(f'SELECT title_cache FROM graph_templates_graph WHERE local_graph_id={graph_id}').strip() == 'Edited inventory device - Rack <west> - asset-42',
          'legacy graph title cache is refreshed')
    check(harness.sql(f'SELECT location,external_id FROM host WHERE id={allowed_id}').strip() == 'Rack <west>\tasset-42', 'metadata persists through the legacy save adapter')
    check('Rack &lt;west&gt;' in body and 'Rack <west>' not in body, 'location is escaped in the edit form')
    events = harness.jsonl('/artifacts/plugin.jsonl')
    check(any(event.get('callback') == 'filter' and isinstance(event.get('args'), list) and len(event['args']) == 1
              and isinstance(event['args'][0], dict) and str(event['args'][0].get('host_id')) == str(allowed_id) for event in events),
          'legacy host-save plugin receives the edited device')
    audit = harness.command('cat', '/var/www/html/log/cacti.log', check=True)['stdout']
    check(f'INVENTORY: User {user_id} edited device {allowed_id}' in audit, 'save records actor and device in the audit log')
    harness.sql(f'DELETE FROM graph_templates_graph WHERE local_graph_id={graph_id}; DELETE FROM graph_local WHERE id={graph_id}')
    for action in ('--disable', '--uninstall'):
        check(harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', action)['exit'] == 0,
              'save-hook fixture is removed')
    check(post(fresh, harness.base)[0] == 409, 'replayed stale save cannot overwrite the new revision')
    check(session.request('/bin/legacy-device-edit.php')['status'] in (403, 404), 'CLI write boundary is not HTTP-accessible')
    # A state change in another editor must invalidate a previously loaded form.
    pending = get_fields()
    harness.sql(f"UPDATE host SET disabled='on' WHERE id={allowed_id}")
    check(post(pending, harness.base)[0] == 409, 'concurrent polling change rejects stale details form')
    harness.sql(f"UPDATE host SET disabled='', status=3 WHERE id={allowed_id}")
    disable = get_fields()
    disable['device_edit[enabled]'] = 'disabled'
    check(post(disable, harness.base)[0] == 200, 'Symfony command disables device polling')
    check(harness.sql(f'SELECT disabled,status FROM host WHERE id={allowed_id}').strip() == 'on\t0',
          'legacy disable effect resets device status')
    check(get_fields().get('device_edit[enabled]') == 'disabled', 'editor reloads disabled state')
    disabled_list = session.request('/app.php/inventory/devices.json?state=disabled&size=100')['json']['devices']
    check(allowed_id in [device['id'] for device in disabled_list], 'disabled device appears in disabled Inventory filter')
    check(post(disable, harness.base)[0] == 409, 'old enabled revision cannot repeat a state change')
    enable = get_fields()
    enable['device_edit[enabled]'] = 'enabled'
    check(post(enable, harness.base)[0] == 200, 'Symfony command enables device polling')
    check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id={allowed_id} AND disabled='' AND status=0").strip() == '1',
          'enabling retains unknown status until the poller observes the device')
    check(get_fields().get('device_edit[enabled]') == 'enabled', 'editor reloads enabled state')
    disabled_list = session.request('/app.php/inventory/devices.json?state=disabled&size=100')['json']['devices']
    check(allowed_id not in [device['id'] for device in disabled_list], 'enabled device leaves disabled Inventory filter')
    check(harness.sql(f'SELECT notes FROM host WHERE id={allowed_id}').strip() == '<script>alert(1)</script> notes',
          'polling transitions preserve device notes')
    metadata = get_fields()
    check(metadata['device_edit[location]'] == 'Rack <west>' and metadata['device_edit[external_id]'] == 'asset-42', 'polling changes preserve metadata')
    metadata.update({'device_edit[location]': '界' * 40, 'device_edit[external_id]': 'é' * 40})
    check(post(metadata, harness.base)[0] == 200, 'metadata accepts forty multibyte characters')
    check(harness.sql(f'SELECT CHAR_LENGTH(location),CHAR_LENGTH(external_id) FROM host WHERE id={allowed_id}').strip() == '40\t40', 'multibyte metadata is not truncated')
    metadata = get_fields()
    metadata.update({'device_edit[location]': '', 'device_edit[external_id]': ''})
    check(post(metadata, harness.base)[0] == 200, 'metadata can be explicitly cleared')
    check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id={allowed_id} AND location='' AND external_id=''").strip() == '1', 'empty metadata is persisted')
    # Restore fixture fields so the existing listing assertions remain independent.
    harness.sql(f"UPDATE host SET description='{original}', hostname='fixture-edit.invalid', notes='' WHERE id={allowed_id}")
