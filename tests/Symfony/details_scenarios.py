"""Permission-filtered Symfony device details and navigation over real HTTP."""
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request
from harness import Session


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'a' and 'href' in attrs:
            self.links.append(attrs['href'])


def verify_details(harness, session, user_id, allowed_id, hidden_id, listing, check):
    path = f'/app.php/inventory/devices/{allowed_id}'
    def snapshot():
        return harness.rows(f"SELECT JSON_OBJECT('description',description,'notes',notes,'status',status,'disabled',disabled,'location',location,'external_id',external_id,'site_id',site_id,'deleted',deleted,'snmp_community',snmp_community) FROM host WHERE id={allowed_id}")[0]
    original = snapshot()
    def literal(value):
        if value is None:
            return 'NULL'
        return f"CONVERT(UNHEX('{str(value).encode().hex()}') USING utf8mb4)"
    harness.sql("INSERT INTO sites (name) VALUES ('details <site>')")
    site = int(harness.sql("SELECT id FROM sites WHERE name='details <site>'").strip())
    notes = '<script>alert(1)</script>\n東京 notes'
    harness.sql(f"UPDATE host SET description='details <router>', notes={literal(notes)}, location='Rack <west>', external_id='asset<&>', site_id={site}, status=4, snmp_community='details-secret-no-display' WHERE id={allowed_id}")
    context = {'q': '<router>', 'state': 'enabled', 'status': 'error', 'sort': 'hostname',
               'direction': 'desc', 'page': '2', 'size': '50', 'site': str(site)}
    url = path + '?' + urlencode({'list[' + key + ']': value for key, value in context.items()})
    with session.opener.open(harness.base + url) as response:
        body = response.read().decode()
        check(response.status == 200 and 'no-store' in response.headers.get('Cache-Control', ''),
              'details are readable and never cached')
    for raw, escaped in (('<router>', '&lt;router&gt;'), ('<site>', '&lt;site&gt;'),
                         ('<script>', '&lt;script&gt;'), ('<west>', '&lt;west&gt;'), ('<&>', '&lt;&amp;&gt;')):
        check(raw not in body and escaped in body, 'details escape text: ' + raw)
    check('東京 notes' in body and '<dd>Error</dd>' in body, 'details preserve notes and Error status')
    check('details-secret-no-display' not in body and '<form' not in body,
          'details exclude credentials and mutation forms')
    links = Links()
    links.feed(body)
    back = next(link for link in links.links if urlsplit(link).path == '/app.php/inventory/devices')
    edit = next(link for link in links.links if urlsplit(link).path == path + '/edit')
    check(parse_qs(urlsplit(back).query) == {key: [value] for key, value in context.items()},
          'details return to the selected inventory view')
    check(parse_qs(urlsplit(edit).query) == {'list[' + key + ']': [value] for key, value in context.items()},
          'details pass validated context to the editor')
    with session.opener.open(Request(harness.base + url, method='HEAD')) as response:
        check(response.status == 200 and response.read() == b'', 'details HEAD has no body')
    check(session.request(path, fields={})['status'] == 405, 'details reject mutations')
    check(Session(harness.base).request(path)['status'] == 401, 'anonymous details access is denied')
    for suffix in ('?list=bad', '?list[site][]=1', '?list[page]=0'):
        check(session.request(path + suffix)['status'] == 400, 'invalid details context is rejected')
    for missing in (hidden_id, 99999999):
        check(session.request(f'/app.php/inventory/devices/{missing}')['status'] == 404,
              'hidden and missing details have the same response')
    for mode in (1, 2, 3, 4):
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('graph_auth_method','{mode}')")
        visible = allowed_id in [d['id'] for d in listing(q='details <router>')['devices']]
        check(session.request(path)['status'] == (200 if visible else 404),
              'details match list visibility in mode ' + str(mode))
    harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
    harness.sql(f"UPDATE host SET disabled='on' WHERE id={allowed_id}")
    with session.opener.open(harness.base + path) as response:
        check('<dd>Disabled</dd>' in response.read().decode(), 'disabled takes precedence in details')
    for site_id, label in ((0, 'Unassigned'), (4294967295, 'Unavailable')):
        harness.sql(f'UPDATE host SET site_id={site_id}, notes=NULL WHERE id={allowed_id}')
        with session.opener.open(harness.base + path) as response:
            body = response.read().decode()
        check(f'<dd>{label}</dd>' in body and 'No notes.' in body,
              'details distinguish missing sites and normalize null notes')
    harness.sql(f"UPDATE host SET deleted='on' WHERE id={allowed_id}")
    check(session.request(path)['status'] == 404, 'deleted device details are hidden')
    harness.sql(f"UPDATE host SET deleted='' WHERE id={allowed_id}")
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
    check(session.request(path)['status'] == 403, 'revoked device realm denies details')
    harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
    harness.sql('UPDATE host SET ' + ','.join(key + '=' + literal(value) for key, value in original.items()) + f' WHERE id={allowed_id}')
    check(snapshot() == original, 'details restores every modified device field, including disabled and fixture credentials')
    harness.sql(f'DELETE FROM sites WHERE id={site}')
    print('Device details HTTP checks passed.', flush=True)
