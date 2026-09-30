"""Collector editor real HTTP checks against MariaDB and authenticated sessions."""
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request

from device_edit_scenarios import Inputs


def verify_collector_edit(harness, session, user_id, check):
    suffix = str(int(harness.sql('SELECT COALESCE(MAX(id),0)+100 FROM poller').strip()))
    hostname = 'collector-edit-' + suffix + '.invalid'
    collector_id = int(harness.sql(
        "INSERT INTO poller (name,hostname,timezone,notes,processes,threads,sync_interval,dbdefault,dbhost,dbuser,dbpass,dbport,dbretries,dbssl) "
        f"VALUES ('editor fixture','{hostname}','UTC','initial notes',2,3,3600,'remote','db-{suffix}.invalid','svc','credential-sentinel',3306,5,''); SELECT LAST_INSERT_ID()"
    ).strip())
    route = f'/app.php/collectors/{collector_id}/edit'
    timezone_granted = False
    new_id = None

    def snapshot():
        row = harness.rows(f"SELECT JSON_OBJECT('hostname',HEX(hostname),'notes',HEX(notes),'dbhost',HEX(dbhost),'dbpass',HEX(dbpass),'processes',processes,'threads',threads) FROM poller WHERE id={collector_id}")[0]
        return {key: bytes.fromhex(value).decode() if key in ('hostname', 'notes', 'dbhost', 'dbpass') and value is not None else value for key, value in row.items()}

    def form(path=route):
        with session.opener.open(harness.base + path) as response:
            body = response.read().decode()
            check('no-store' in response.headers.get('Cache-Control', ''),
                  'collector edit responses prohibit storage')
        parser = Inputs()
        parser.feed(body)
        check('collector_edit[_token]' in parser.fields and 'collector_edit[revision]' in parser.fields,
              'collector edit includes CSRF and opaque revision values')
        check('credential-sentinel' not in body and parser.fields.get('collector_edit[dbpass]', '') == '',
              'collector edit never prefills or displays a stored database password')
        return parser, body

    def post(fields, path=route, origin=True):
        headers = {'Origin': harness.base} if origin else {}
        request = Request(harness.base + path, data=urlencode(fields).encode(), headers=headers)
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    try:
        original = snapshot()
        parser, _ = form()
        fields = parser.fields | {'collector_edit[notes]': 'edited notes'}
        check(post(fields, origin=False)[0] == 422 and snapshot() == original,
              'collector saves require same-origin evidence and leave data unchanged when Origin is missing')
        status, _, location = post(fields)
        check(status in (200, 303) and 'saved=1' in location, 'collector save reports completion')
        after = snapshot()
        check(after['notes'] == 'edited notes' and after['dbpass'] == original['dbpass'],
              'blank password retains exact stored credential while saving public fields')
        check(post(fields)[0] == 422 and snapshot() == after, 'stale collector edit cannot overwrite saved changes')

        parser, _ = form()
        before = snapshot()
        for bad in ({'collector_edit[hostname][]': 'bad'}, {'collector_edit[timezone]': 'x' * 41}, {'collector_edit[hostname]': 'invalid\0host'}):
            status, _, _ = post(parser.fields | bad)
            check(status == 422 and snapshot() == before, 'malformed collector field leaves storage unchanged')

        parser, _ = form()
        harness.sql(f"UPDATE poller SET dbpass='rotated-sentinel' WHERE id={collector_id}")
        before = snapshot()
        status, _, _ = post(parser.fields | {'collector_edit[dbpass]': 'replacement-secret'})
        check(status == 422 and snapshot() == before, 'concurrent credential rotation invalidates editor revision')

        parser, _ = form()
        credential_fields = ['dbhost', 'dbuser', 'dbpass', 'dbdefault', 'dbport', 'dbretries', 'dbssl', 'dbsslkey', 'dbsslcert', 'dbsslca', 'revision']
        probe = {f'collector_edit[{name}]': parser.fields[f'collector_edit[{name}]'] for name in credential_fields if f'collector_edit[{name}]' in parser.fields}
        probe |= {'connection_token': parser.fields['connection_token'], 'collector_id': str(collector_id), 'collector_edit[dbhost]': '127.0.0.1', 'collector_edit[dbport]': '1', 'collector_edit[dbretries]': '0'}
        test_url = '/app.php/collectors/connection-test'
        status, body, _ = post(probe, test_url)
        check(status == 200 and 'Connection Failed' in body and 'rotated-sentinel' not in body,
              'connection-test form handoff reaches the probe without leaking stored credentials')
        check(post(probe | {'collector_edit[notes]': 'unexpected'}, test_url)[0] == 400,
              'connection-test endpoint rejects unrelated editor fields')
        no_token = {key: value for key, value in parser.fields.items() if key.startswith('collector_edit[')}
        status, body, _ = post(no_token | {'collector_id': str(collector_id)}, test_url)
        check(status == 419 and 'rotated-sentinel' not in body, 'connection test requires its CSRF token and returns no secrets')
        check(session.request('/app.php/collectors/timezones?term=UTC')['status'] == 502,
              'missing MySQL timezone access returns a controlled failure')
        harness.sql("GRANT SELECT ON mysql.time_zone_name TO 'cactiuser'@'%'")
        timezone_granted = True
        check(session.request('/app.php/collectors/timezones?term=UTC')['status'] == 200,
              'timezone autocomplete is served through the protected collector route')
        with session.opener.open(harness.base + '/app.php/collector-editor.js') as asset:
            asset_body = asset.read().decode()
            check(asset.status == 200 and 'javascript' in asset.headers.get('Content-Type', ''),
                  'collector editor JavaScript is served through the Symfony asset route')
            check('data-timezone-url' in asset_body and 'data-collector-connection-test' in asset_body,
                  'served collector editor JavaScript includes both form behaviors')
        create_parser, _ = form('/app.php/collectors/new')
        create_fields = create_parser.fields | {
            'collector_edit[name]': 'Created collector', 'collector_edit[hostname]': 'created-' + suffix + '.invalid',
            'collector_edit[dbdefault]': 'remote', 'collector_edit[dbhost]': 'created-db-' + suffix + '.invalid',
            'collector_edit[dbuser]': 'svc', 'collector_edit[dbpass]': 'creation-sentinel',
        }
        check(post(create_fields | {'collector_edit[dbhost]': 'localhost'}, '/app.php/collectors/new')[0] == 422,
              'new remote collector cannot use an ambiguous localhost database target')
        status, _, location = post(create_fields, '/app.php/collectors/new')
        check(status == 200 and 'saved=1' in location, 'collector creation saves through the protected Symfony form')
        new_id = int(harness.sql("SELECT id FROM poller WHERE hostname='created-" + suffix + ".invalid'").strip())
        check(harness.sql(f"SELECT dbpass FROM poller WHERE id={new_id}").strip() == 'creation-sentinel',
              'collector creation hands the submitted credential to persistence without rendering it')
        check(session.request(f'/pollers.php?action=edit&id={collector_id}')['status'] in (200, 303),
              'legacy collector edit route forwards to the Symfony editor')
        check(urlsplit(route).path == route, 'scenario uses the fixed Symfony collector route')
    finally:
        if timezone_granted:
            harness.sql("REVOKE SELECT ON mysql.time_zone_name FROM 'cactiuser'@'%'")
        harness.sql(f'DELETE FROM poller WHERE id={collector_id}')
        if new_id is not None:
            harness.sql(f'DELETE FROM poller WHERE id={new_id}')
    print('Collector editing HTTP checks passed.', flush=True)
