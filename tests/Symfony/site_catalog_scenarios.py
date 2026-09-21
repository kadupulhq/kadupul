"""Site administration listing, with device-scoped counts over real HTTP."""
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request
from details_scenarios import Links
from harness import Session


def verify_site_catalog(harness, session, user_id, ids, allowed, device_listing, check):
    base = '/app.php/inventory/sites'
    prefix = 'site-catalog-fixture-'
    saved = harness.rows('SELECT JSON_OBJECT(\'id\',id,\'site_id\',site_id,\'deleted\',deleted) FROM host WHERE id IN (' + ','.join(map(str, ids)) + ')')
    mode = harness.sql("SELECT value FROM settings WHERE name='graph_auth_method'").strip()

    def listing(**filters):
        response = session.request(base + '.json?' + urlencode(filters))
        check(response['status'] == 200, 'site catalog query succeeds')
        return response['json']

    def text(value):
        return f"CONVERT(UNHEX('{str(value).encode().hex()}') USING utf8mb4)"

    try:
        harness.sql('INSERT INTO sites (name,city,state,country,notes) VALUES ' + ','.join(
            f"('{prefix}{i:02d}','City','State','Country','private-site-notes')" for i in range(27)))
        site_ids = [int(x) for x in harness.sql(f"SELECT id FROM sites WHERE name LIKE '{prefix}%' ORDER BY name,id").splitlines()]
        harness.sql(f'UPDATE host SET site_id={site_ids[0]} WHERE id IN ({ids[0]},{allowed[0]},{allowed[1]})')
        harness.sql(f"UPDATE host SET site_id={site_ids[1]},deleted='on' WHERE id={allowed[2]}")
        graph_ids = []
        for _ in range(2):
            graph_ids.append(harness.sql(f'INSERT INTO graph_local (host_id) VALUES ({allowed[0]}); SELECT LAST_INSERT_ID()').strip())
        first, second = listing(q=prefix), listing(q=prefix, page=2)
        check(len(first['sites']) == 25 and first['hasNext'] and len(second['sites']) == 2 and not second['hasNext'],
              'site catalog paginates all sites including empty sites')
        check([s['id'] for s in first['sites'] + second['sites']] == site_ids, 'site ordering is deterministic')
        check(first['sites'][0]['visibleDevices'] == 2 and first['sites'][1]['visibleDevices'] == 0,
              'site counts exclude hidden and deleted devices')
        check(all(set(s) == {'id', 'name', 'city', 'state', 'country', 'visibleDevices'} for s in first['sites']),
              'site projection excludes notes and other private fields')
        for visibility_mode in (1, 2, 3, 4):
            harness.sql(f"REPLACE INTO settings (name,value) VALUES ('graph_auth_method','{visibility_mode}')")
            count = len(device_listing(site=site_ids[0], size=100)['devices'])
            check(listing(q=prefix)['sites'][0]['visibleDevices'] == count,
                  'site counts match device visibility mode ' + str(visibility_mode))
        harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
        harness.sql(f"UPDATE sites SET name='{prefix}tie' WHERE id IN ({site_ids[0]},{site_ids[1]})")
        for direction, expected in (('asc', site_ids[:2]), ('desc', site_ids[1::-1])):
            check([s['id'] for s in listing(q=prefix + 'tie', direction=direction)['sites']] == expected,
                  'equal site names use the ID tie-breaker ' + direction)
        markers = {'name': '<site>東京 %_!', 'city': '<city>%_!', 'state': '<state>%_!', 'country': '<country>%_!'}
        harness.sql('UPDATE sites SET ' + ','.join(k + '=' + text(v) for k, v in markers.items()) + f' WHERE id={site_ids[0]}')
        for marker in markers.values():
            check([s['id'] for s in listing(q=marker)['sites']] == [site_ids[0]], 'site search treats wildcard characters literally')
        check(not listing(q='private-site-notes')['sites'], 'site notes are not searchable')
        with session.opener.open(harness.base + base + '?' + urlencode({'q': markers['name']})) as response:
            body = response.read().decode()
            cache_control = ','.join(response.headers.get_all('Cache-Control', []))
            check('private' in cache_control and 'no-store' in cache_control,
                  'site controller responses are private and not stored')
        check(all(raw not in body for raw in markers.values()) and all(raw.split('>')[0].replace('<', '&lt;') + '&gt;' in body for raw in markers.values()),
              'Twig escapes site columns, link labels and reflected search')
        links = Links()
        links.feed(body)
        check(any(urlsplit(link).path == '/app.php/inventory/devices' and parse_qs(urlsplit(link).query) == {'site': [str(site_ids[0])]} for link in links.links),
              'site count links select the matching device site')
        with session.opener.open(harness.base + base + '?' + urlencode({'q': prefix, 'direction': 'desc', 'size': 25})) as response:
            links = Links()
            links.feed(response.read().decode())
        check(any(urlsplit(link).path == base and parse_qs(urlsplit(link).query) == {'q': [prefix], 'direction': ['desc'], 'size': ['25'], 'page': ['2']} for link in links.links),
              'site pagination preserves search, direction and page size')
        for suffix in ('', '.json'):
            with session.opener.open(Request(harness.base + base + suffix, method='HEAD')) as response:
                check(response.status == 200 and response.read() == b'', 'site HEAD responses have no body')
            check(session.request(base + suffix, fields={})['status'] == 405, 'site catalog rejects mutations')
            check(Session(harness.base).request(base + suffix)['status'] == 401, 'anonymous site catalog access is denied')
        for query in ('q[]=x', 'direction[]=asc', 'page[]=1', 'size[]=25', 'page=0', 'size=24', 'page=1000001', 'direction=invalid', 'q=%FF', 'q=a%00b', 'q=' + 'x' * 201):
            check(session.request(base + '.json?' + query)['status'] == 400, 'site catalog rejects malformed filters: ' + query[:40])
        for query in ({'q': prefix, 'page': 100000}, {'q': 'no-matching-site-fixture'}):
            empty = listing(**query)
            check(empty['sites'] == [] and not empty['hasNext'], 'empty site pages have no next page')
        with session.opener.open(harness.base + base + '?q=no-matching-site-fixture') as response:
            check('No sites match these filters.' in response.read().decode(), 'site page renders empty state')
        # A new legacy login captures the saved language in its native session.
        language_names = "'i18n_language_support','i18n_auto_detection','i18n_default_language'"
        language_settings = harness.rows(f"SELECT JSON_OBJECT('name',name,'value',value) FROM settings WHERE name IN ({language_names})")
        user_language = harness.rows(f"SELECT JSON_OBJECT('value',value) FROM settings_user WHERE user_id={user_id} AND name='user_language'")
        try:
            harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_auto_detection','0'),('i18n_default_language','en-US')")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','fr-FR')")
            french = Session(harness.base)
            check(french.login('behavior-admin')['status'] == 200, 'legacy login accepts French language preference')
            with french.opener.open(harness.base + base + '?q=no-matching-site-fixture&language=en&_locale=en') as response:
                body = response.read().decode()
            check('<html lang="fr">' in body and 'Aucun site ne correspond' in body,
                  'Symfony site translations honor the legacy session and ignore locale query overrides')
            with french.opener.open(harness.base + base + f'/{site_ids[0]}/edit') as response:
                body = response.read().decode()
            check('Enregistrer le site' in body and '>Nom</label>' in body and '&lt;site&gt;' in body,
                  'French site forms translate labels and preserve escaping')
            check(french.request(base + '.json?' + urlencode({'q': prefix}))['json'] == listing(q=prefix),
                  'site JSON data is independent of locale')
            check(harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='user_language'").strip() == 'fr-FR',
                  'Symfony locale selection does not rewrite the saved preference')
            harness.sql("UPDATE settings SET value='0' WHERE name='i18n_language_support'")
            with french.opener.open(harness.base + base + '?q=no-matching-site-fixture') as response:
                body = response.read().decode()
            check('<html lang="en">' in body and 'No sites match these filters.' in body,
                  'disabled translation overrides a French shared session')
        finally:
            harness.sql(f"DELETE FROM settings WHERE name IN ({language_names})")
            for setting in language_settings:
                harness.sql(f"INSERT INTO settings (name,value) VALUES ({text(setting['name'])},{text(setting['value'])})")
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='user_language'")
            for setting in user_language:
                harness.sql(f"INSERT INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language',{text(setting['value'])})")
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            check(session.request(base + '.json')['status'] == 403, 'revoked site administration realm denies catalog access')
        finally:
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
    finally:
        if 'graph_ids' in locals() and graph_ids:
            harness.sql('DELETE FROM graph_local WHERE id IN (' + ','.join(graph_ids) + ')')
        for row in saved:
            harness.sql(f"UPDATE host SET site_id={int(row['site_id'])},deleted={text(row['deleted'])} WHERE id={int(row['id'])}")
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('graph_auth_method',{text(mode)})")
        if 'site_ids' in locals():
            harness.sql('DELETE FROM sites WHERE id IN (' + ','.join(map(str, site_ids)) + ')')
    print('Site catalog HTTP checks passed.', flush=True)
