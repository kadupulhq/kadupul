"""Real site creation through Symfony Forms, Twig and the Inventory use case."""
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit, parse_qs
from urllib.request import Request
from device_edit_scenarios import Inputs
from harness import Session


def verify_site_create(harness, session, user_id, check):
    path = '/app.php/inventory/sites/new'
    context = {'q': 'create & 東京', 'page': '2', 'size': '50', 'direction': 'desc'}
    target = path + '?' + urlencode({'list[' + k + ']': v for k, v in context.items()} | {'list[return_url]': 'https://attacker.invalid/'})
    created = []
    before = int(harness.sql('SELECT COUNT(*) FROM sites').strip())

    def form():
        with session.opener.open(harness.base + target) as response:
            body = response.read().decode()
            check('no-store' in response.headers.get('Cache-Control', ''), 'site creation form is not cached')
        parser = Inputs()
        parser.feed(body)
        return parser, body

    def post(fields, origin=harness.base, client=session):
        request = Request(harness.base + target, data=urlencode(fields).encode(), headers={} if origin is None else {'Origin': origin})
        try:
            response = client.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    try:
        parser, body = form()
        check('site_create[_token]' in parser.fields and 'site_create[revision]' not in parser.fields, 'site creation uses its own CSRF form')
        check(urlsplit(parser.action).path == path and 'attacker.invalid' not in parser.action, 'site creation action excludes untrusted return URLs')
        check(any(urlsplit(link).path == '/app.php/inventory/sites' and parse_qs(urlsplit(link).query) == {k: [v] for k, v in context.items()} for link in parser.links), 'site creation preserves validated list navigation')
        with session.opener.open(harness.base + '/app.php/inventory/sites') as response:
            check('/inventory/sites/new' in response.read().decode(), 'site catalog exposes the new creation route')
        fields = parser.fields | {'site_create[name]': 'create-site-fixture 東京 <script>', 'site_create[address1]': '12 Road', 'site_create[address2]': 'Room 3', 'site_create[city]': 'Paris', 'site_create[state]': 'IDF', 'site_create[postal_code]': '75001', 'site_create[country]': 'France', 'site_create[timezone]': 'Europe/Paris', 'site_create[latitude]': '48.8566000000', 'site_create[longitude]': '2.3522000000', 'site_create[zoom]': '12', 'site_create[alternate_id]': 'other', 'site_create[notes]': 'creation notes 🌏'}
        invalid = [fields | {'site_create[id]': '1'}, fields | {'site_create[latitude]': '91'}, fields | {'site_create[zoom]': '24'}, fields | {'site_create[name]': ''}, fields | {'site_create[timezone]': 'Invalid/Zone'}, fields | {'site_create[city]': 'x' * 51}]
        for data in invalid:
            check(post(data)[0] == 422, 'invalid site creation is rejected')
        check(post({k: v for k, v in fields.items() if k != 'site_create[_token]'}, origin=None)[0] == 422, 'site creation rejects missing CSRF evidence')
        check(post(fields, origin='https://attacker.invalid')[0] == 422, 'site creation rejects cross-origin submissions')
        check(post(fields, client=Session(harness.base))[0] == 401, 'site creation rejects anonymous submissions')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            check(session.request(path)['status'] == 403 and post(fields)[0] == 403, 'site creation requires site administration realm for reads and writes')
        finally:
            harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        check(int(harness.sql('SELECT COUNT(*) FROM sites').strip()) == before, 'rejected creations leave all sites unchanged')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('time_last_change_site','1'),('time_last_change_site_device','1')")
        status, body, url = post(fields)
        site_id = int(urlsplit(url).path.split('/')[-2])
        created.append(site_id)
        check(status == 200 and '/inventory/sites/' in url and '&lt;script&gt;' in body and 'create-site-fixture 東京 <script>' not in body, 'successful creation redirects to an escaped Twig editor')
        row = harness.rows(f"SELECT JSON_OBJECT('name',HEX(name),'address1',address1,'address2',address2,'city',city,'state',state,'postal_code',postal_code,'country',country,'timezone',timezone,'latitude',latitude,'longitude',longitude,'zoom',zoom,'alternate_id',alternate_id,'notes',HEX(notes)) FROM sites WHERE id={site_id}")[0]
        for key in ['name', 'notes']:
            row[key] = bytes.fromhex(row[key]).decode()
        for key in ['name', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'timezone', 'alternate_id', 'notes']:
            check(row[key] == fields['site_create[' + key + ']'], 'site creation persists ' + key)
        check(float(row['latitude']) == 48.8566 and float(row['longitude']) == 2.3522 and row['zoom'] == 12, 'site creation persists map values without losing precision')
        check(int(harness.sql("SELECT COUNT(*) FROM settings WHERE name IN ('time_last_change_site','time_last_change_site_device') AND CAST(value AS UNSIGNED)>1").strip()) == 2, 'site creation updates both legacy cache markers')
        probe = Path(__file__).with_name('site_creation_probe.php').read_text().removeprefix('<?php')
        result = harness.php('-r', probe)
        check(result['exit'] == 0 and json.loads(result['stdout']) == {'rollback': True, 'authorization': True}, 'site creation rollback and persistence authorization are enforced: ' + str(result['exit']) + ' ' + result['stderr'])
    finally:
        for site_id in created:
            harness.sql(f'DELETE FROM sites WHERE id={site_id}')
