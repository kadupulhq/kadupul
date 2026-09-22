"""Complete Sites workflows over the real framework and disposable database."""
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request
from device_edit_scenarios import Inputs
from harness import Session


def verify_site_lifecycle(harness, session, user_id, check):
    created = []
    hosts = []

    def request(path, fields=None, origin=True, client=session):
        req = Request(harness.base + path, data=None if fields is None else urlencode(fields).encode(), headers={'Origin': harness.base} if origin else {})
        try:
            response = client.opener.open(req)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    def form(path):
        status, body, _ = request(path)
        check(status == 200, 'site lifecycle confirmation loads')
        parser = Inputs()
        parser.feed(body)
        return parser.fields

    def action(operation, ids):
        return '/app.php/inventory/sites/' + operation + '?' + urlencode([('ids[]', str(i)) for i in ids])

    try:
        for name in ['lifecycle-A', 'lifecycle-B']:
            created.append(int(harness.sql(f"INSERT INTO sites (name,address1,city,timezone,latitude,longitude,zoom,alternate_id,notes) VALUES ('{name}','Street','City','UTC',1.5,-2.5,9,'alias','notes'); SELECT LAST_INSERT_ID()").strip()))
        first, second = created
        for deleted in ['', 'on']:
            hosts.append(int(harness.sql(f"INSERT INTO host (description,hostname,site_id,deleted) VALUES ('lifecycle-host','127.0.0.1',{first},'{deleted}'); SELECT LAST_INSERT_ID()").strip()))
        edit = f'/app.php/inventory/sites/{first}/edit'
        fields = form(edit)
        updates = {'address1': 'Updated street', 'address2': 'Building 2', 'city': 'Tokyo', 'state': 'Tokyo', 'postal_code': '100-0001', 'country': 'Japan', 'timezone': 'Asia/Tokyo', 'latitude': '35.1234567890', 'longitude': '139.75', 'zoom': '15', 'alternate_id': 'Tokyo site'}
        fields.update({'site_edit[' + key + ']': value for key, value in updates.items()})
        check(request(edit, fields)[0] == 200, 'full site settings save through Symfony')
        row = harness.rows(f"SELECT JSON_OBJECT('address1',address1,'address2',address2,'city',city,'state',state,'postal_code',postal_code,'country',country,'timezone',timezone,'latitude',CAST(latitude AS CHAR),'longitude',CAST(longitude AS CHAR),'zoom',CAST(zoom AS CHAR),'alternate_id',alternate_id) FROM sites WHERE id={first}")[0]
        check(all(float(row[k]) == float(v) if k in ['latitude', 'longitude'] else row[k] == v for k, v in updates.items()), 'full site address timezone map and alternate name persist')
        for invalid in [{'latitude': '91'}, {'longitude': '-181'}, {'zoom': '24'}, {'timezone': 'Invalid/Zone'}, {'address1': 'x' * 101}]:
            fresh = form(edit)
            check(request(edit, fresh | {'site_edit[' + k + ']': v for k, v in invalid.items()})[0] == 422, 'full site settings reject invalid fields')
        for key in ['id', 'city', 'state', 'country', 'devices']:
            data = session.request('/app.php/inventory/sites.json?' + urlencode({'q': 'lifecycle-', 'sort': key, 'direction': 'desc'}))
            check(data['status'] == 200 and [site['id'] for site in data['json']['sites']] == ([second, first] if key == 'id' else [first, second]), 'site catalog supports migrated sort ' + key)
        for path, expected in [('/sites.php', '/app.php/inventory/sites'), (f'/sites.php?action=edit&id={first}', edit), ('/sites.php?action=edit&id=0', '/app.php/inventory/sites/new')]:
            status, _, url = request(path)
            check(status == 200 and urlsplit(url).path == expected, 'legacy Sites URL reaches Symfony ' + expected)
        check(request('/sites.php?action=ajax_tz&term=Tokyo')[0] == 200, 'legacy timezone lookup uses Symfony')
        before = harness.sql('SELECT COUNT(*) FROM sites').strip()
        check(request('/sites.php', {'action': 'save', 'id': str(first), 'name': 'Forged'})[0] == 409 and harness.sql('SELECT COUNT(*) FROM sites').strip() == before, 'legacy site POST is never replayed')
        check(request('/sites.php', client=Session(harness.base))[0] == 401, 'legacy Sites bridge requires authentication')
        target = action('duplicate', [first, second])
        fields = form(target)
        check(harness.sql('SELECT COUNT(*) FROM sites').strip() == before, 'site action GET never mutates')
        check(request(target, fields, origin=False)[0] == 422, 'site action requires CSRF origin evidence')
        check(request(target, {k: v for k, v in fields.items() if k != 'site_action[_token]'})[0] == 422, 'site action requires CSRF token')
        check(request(target, fields | {'site_action[pattern]': 'x' * 101})[0] == 422 and harness.sql('SELECT COUNT(*) FROM sites').strip() == before, 'invalid copy batch cannot partially insert')
        harness.sql(f"UPDATE sites SET city='Concurrent' WHERE id={second}")
        check(request(target, fields)[0] == 409 and harness.sql('SELECT COUNT(*) FROM sites').strip() == before, 'stale bulk selection rejects all copies')
        fields = form(target)
        check(request(target, fields | {'site_action[pattern]': '<site> copied'})[0] == 200, 'bulk duplication succeeds through Symfony')
        copies = [int(v) for v in harness.sql("SELECT id FROM sites WHERE name IN ('lifecycle-A copied','lifecycle-B copied') ORDER BY id").splitlines()]
        created.extend(copies)
        check(len(copies) == 2 and harness.sql(f"SELECT COUNT(*) FROM host WHERE site_id IN ({','.join(map(str,copies))})").strip() == '0', 'site duplication copies settings without moving devices')
        check(harness.sql(f'SELECT timezone FROM sites WHERE id={copies[0]}').strip() == 'Asia/Tokyo', 'site duplication retains the full source settings')
        single = action('duplicate', [copies[0]])
        check(request(single, form(single) | {'site_action[pattern]': 'lifecycle-single-copy'})[0] == 200, 'single site duplication succeeds')
        single_id = int(harness.sql("SELECT id FROM sites WHERE name='lifecycle-single-copy'").strip())
        created.append(single_id)
        single = action('delete', [single_id])
        check(request(single, form(single))[0] == 200 and harness.sql(f'SELECT COUNT(*) FROM sites WHERE id={single_id}').strip() == '0', 'single site deletion succeeds')
        target = action('delete', [first, second])
        fields = form(target)
        forged = fields | {'site_action[selection]': json.dumps({str(copies[0]): 'a' * 64})}
        check(request(target, forged)[0] == 422, 'confirmation rejects changed selection IDs')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            check(request(target, fields)[0] == 403, 'site bulk writes reject revoked authorization')
        finally:
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        check(request(target, fields, client=Session(harness.base))[0] == 401, 'anonymous site bulk writes are denied')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('time_last_change_site','1'),('time_last_change_site_device','1')")
        check(request(target, fields)[0] == 200 and harness.sql(f'SELECT COUNT(*) FROM sites WHERE id IN ({first},{second})').strip() == '0', 'bulk site deletion succeeds atomically')
        check(harness.sql(f'SELECT site_id FROM host WHERE id={hosts[0]}').strip() == '0' and harness.sql(f'SELECT site_id FROM host WHERE id={hosts[1]}').strip() == str(first), 'site deletion unassigns active devices and preserves deleted-device history')
        check(harness.sql("SELECT COUNT(*) FROM settings WHERE name IN ('time_last_change_site','time_last_change_site_device') AND value > 1").strip() == '2', 'site lifecycle commits both cache invalidations')
        probe = harness.php('-r', Path(__file__).with_name('site_lifecycle_probe.php').read_text().removeprefix('<?php'))
        check(probe['exit'] == 0 and all(json.loads(probe['stdout']).values()), 'site lifecycle adapter rejects revoked stale and partial writes')
        from site_collector_scenarios import verify_collector_sites
        verify_collector_sites(harness, request, form, action, copies[0], created, check)
        for suffix in ['ids[]=0', 'ids[]=1&ids[]=1', 'ids=bad', 'ids[]=4294967296']:
            check(request('/app.php/inventory/sites/delete?' + suffix)[0] == 400, 'site lifecycle rejects malformed selections')
    finally:
        if hosts:
            harness.sql('DELETE FROM host WHERE id IN (' + ','.join(map(str, hosts)) + ')')
        if created:
            harness.sql('DELETE FROM sites WHERE id IN (' + ','.join(map(str, created)) + ')')
