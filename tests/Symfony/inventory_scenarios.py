"""Inventory migration checks against real Symfony HTTP routes and legacy tables."""
import csv
import io
import json
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request


def verify_inventory(harness, session, user_id, check):
    base = '/app.php/inventory/devices'

    def listing(**filters):
        result = session.request(base + '.json?' + urlencode(filters))
        check(result['status'] == 200, 'Inventory query succeeds')
        return result['json']

    def export(**filters):
        with session.opener.open(harness.base + base + '.csv?' + urlencode(filters)) as response:
            check(response.status == 200, 'CSV export succeeds')
            check(response.headers.get_content_type() == 'text/csv', 'CSV has the correct media type')
            check('no-store' in response.headers.get('Cache-Control', ''), 'CSV is not cached')
            check(response.headers.get('Content-Disposition') ==
                  f'attachment; filename="devices-page-{filters.get("page", 1)}.csv"', 'CSV is downloaded with a fixed page filename')
            rows = list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'), newline='')))
            check(rows[0] == ['ID', 'Name', 'Hostname', 'Status', 'Location', 'External ID'], 'CSV only exports list columns')
            return rows[1:]

    # No production database is used: Harness.setup owns this disposable schema.
    saved = harness.rows(f"SELECT JSON_OBJECT('policy_hosts',policy_hosts,'policy_graphs',policy_graphs,'policy_graph_templates',policy_graph_templates) FROM user_auth WHERE id={user_id}")[0]
    saved_mode = harness.sql("SELECT value FROM settings WHERE name='graph_auth_method'").strip()
    harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
    harness.sql(f"UPDATE user_auth SET policy_hosts=2, policy_graphs=2, policy_graph_templates=2 WHERE id={user_id}")
    values = ','.join(f"('inventory-fixture-{i:02d}','fixture-{i}.invalid','',3,1)" for i in range(28))
    harness.sql('INSERT INTO host (description,hostname,disabled,status,ping_method) VALUES ' + values)
    ids = [int(x) for x in harness.sql("SELECT id FROM host WHERE description LIKE 'inventory-fixture-%' ORDER BY id").splitlines()]
    allowed = ids[1:]
    harness.sql('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES ' + ','.join(f'({user_id},{id},3)' for id in allowed))
    for mode in (1, 2, 3, 4):
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('graph_auth_method','{mode}')")
        probe = harness.php('-r', 'require "include/global.php"; require_once "lib/auth.php"; $total=-1; echo json_encode(array_column(get_allowed_devices(' + json.dumps("h.description LIKE 'inventory-fixture-%'") + ', "h1.description ASC, h1.id ASC", "0,100", $total, ' + str(user_id) + '), "id"));')
        check(probe['exit'] == 0, 'legacy visibility comparison executes: ' + probe['stderr'][:300])
        expected_ids = [int(value) for value in json.loads(probe['stdout'])]
        check([d['id'] for d in listing(q='inventory-fixture', size=100)['devices']] == expected_ids,
              'visibility matches legacy policy mode ' + str(mode))
    harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
    from device_edit_scenarios import verify_device_edit
    verify_device_edit(harness, session, user_id, allowed[0], ids[0], check)
    from device_template_scenarios import verify_device_template
    verify_device_template(harness, session, user_id, allowed[0], ids[0], check)
    from site_scenarios import verify_sites
    verify_sites(harness, session, user_id, ids, allowed, listing, export, check)
    from site_catalog_scenarios import verify_site_catalog
    verify_site_catalog(harness, session, user_id, ids, allowed, listing, check)
    from details_scenarios import verify_details
    verify_details(harness, session, user_id, allowed[0], ids[0], listing, check)
    first = listing(q='inventory-fixture')
    second = listing(q='inventory-fixture', page=2)
    check(len(first['devices']) == 25 and first['hasNext'] and len(second['devices']) == 2 and not second['hasNext'],
          'permissions apply before page boundaries and lookahead')
    check([d['id'] for d in first['devices'] + second['devices']] == allowed,
          'hidden devices never enter either page')
    check(all(set(d) == {'id', 'description', 'hostname', 'disabled', 'status', 'location', 'externalId'} for d in first['devices']),
          'device projection exposes no SNMP credentials or notes')
    for page_number, devices in ((1, first['devices']), (2, second['devices'])):
        check(export(q='inventory-fixture', page=page_number) == [
            [str(d['id']), "'" + d['description'], "'" + d['hostname'],
             'Disabled' if d['disabled'] else d['status'], "'" + d['location'], "'" + d['externalId']] for d in devices],
              'CSV uses the same visibility, ordering and page boundary as the list')
    check(all(d['location'] == '' and d['externalId'] == '' for d in first['devices']),
          'null and empty legacy metadata are consistently empty strings')
    location = '<rack>東京 %_!'
    external_id = ' \t=asset-42,"東京"'
    def sql_text(value):
        return f"CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4)"
    harness.sql(f"UPDATE host SET location={sql_text(location)}, external_id={sql_text(external_id)} WHERE id IN ({ids[0]},{allowed[0]})")
    for query in (location, external_id, '%_!'):
        result = listing(q=query)['devices']
        check([d['id'] for d in result] == [allowed[0]], 'metadata search obeys visibility and literal matching')
        check(result[0]['location'] == location and result[0]['externalId'] == external_id,
              'metadata projection preserves Unicode and whitespace')
        check(export(q=query)[0][4:] == ["'" + location, "'" + external_id],
              'metadata CSV quotes text and neutralizes spreadsheet formulas')
    with session.opener.open(harness.base + base + '?' + urlencode({'q': location})) as response:
        html = response.read().decode()
        check(location not in html and '&lt;rack&gt;東京' in html, 'Twig escapes metadata and reflected metadata searches')
        check('external ID' in html and 'External ID</th>' in html, 'Twig exposes searchable metadata columns')
    harness.sql("UPDATE host SET location='metadata-page' WHERE id IN (" + ','.join(map(str, ids)) + ')')
    for page, expected in ((1, allowed[:25]), (2, allowed[25:])):
        result = listing(q='metadata-page', page=page)
        check([d['id'] for d in result['devices']] == expected and result['hasNext'] == (page == 1),
              'metadata search applies permissions before pagination')
        check([int(row[0]) for row in export(q='metadata-page', page=page)] == expected,
              'metadata search pages match CSV exports')
    harness.sql(f"UPDATE host SET notes='private-search-only', snmp_community='private-search-only' WHERE id={allowed[0]}")
    check(not listing(q='private-search-only')['devices'], 'notes and SNMP credentials are not searchable')
    harness.sql('UPDATE host SET location=NULL, external_id=NULL WHERE id IN (' + ','.join(map(str, ids)) + ')')
    # Equal values exercise the ID tie-breaker across both sort directions.
    originals = {d['id']: d for d in first['devices'] + second['devices']}
    harness.sql(f"UPDATE host SET description='inventory-fixture-tie', hostname='fixture-tie.invalid' WHERE id IN ({allowed[0]},{allowed[1]})")
    unsorted = listing(q='inventory-fixture', size=100)['devices']
    for sort, field in (('name', 'description'), ('hostname', 'hostname')):
        for direction in ('asc', 'desc'):
            expected = [d['id'] for d in sorted(unsorted, key=lambda d: (d[field], d['id']),
                                               reverse=direction == 'desc')]
            actual = []
            for page in (1, 2):
                result = listing(q='inventory-fixture', sort=sort, direction=direction, page=page)
                page_ids = [d['id'] for d in result['devices']]
                actual.extend(page_ids)
                check(result['hasNext'] == (page == 1), 'sorted lookahead preserves page boundaries')
                check([int(row[0]) for row in export(q='inventory-fixture', sort=sort,
                                                   direction=direction, page=page)] == page_ids,
                      'CSV retains chosen sort and page')
            check(actual == expected and len(set(actual)) == len(expected),
                  'sort is stable, permission-filtered and complete: ' + sort + '/' + direction)
    for device_id in allowed[:2]:
        original = originals[device_id]
        harness.sql(f"UPDATE host SET description='{original['description']}', hostname='{original['hostname']}' WHERE id={device_id}")
    check(not export(q='inventory-fixture', page=3), 'empty CSV page contains only the header')
    with session.opener.open(Request(harness.base + base + '.csv', method='HEAD')) as response:
        check(response.status == 200 and response.read() == b'', 'CSV HEAD returns no body')
    check(not listing(q="%' OR 1=1 --")['devices'], 'search metacharacters cannot expand the query')
    check(not listing(q='inventory-fixture%')['devices'], 'search percent is literal')
    harness.sql(f"UPDATE host SET disabled='on' WHERE id={allowed[0]}")
    check([d['id'] for d in listing(q='inventory-fixture', state='disabled')['devices']] == [allowed[0]],
          'disabled filter preserves visibility')
    check([int(row[0]) for row in export(q='inventory-fixture', state='disabled')] == [allowed[0]],
          'CSV preserves the disabled filter')
    # Match displayed status, so disabled devices cannot also appear as Up/Down.
    harness.sql(f"UPDATE host SET status=3 WHERE id={allowed[0]}")
    for index, value in enumerate((1, 2, 0, 9, 4), start=1):
        harness.sql(f"UPDATE host SET status={value} WHERE id={allowed[index]}")
    expected_statuses = {'disabled': [allowed[0]], 'down': [allowed[1]],
                         'recovering': [allowed[2]], 'unknown': allowed[3:5], 'error': [allowed[5]], 'up': allowed[6:]}
    harness.sql(f'UPDATE host SET status=4 WHERE id={ids[0]}')
    for status, expected in expected_statuses.items():
        check([d['id'] for d in listing(q='inventory-fixture', status=status, size=100)['devices']] == expected,
              'status filter matches displayed status and preserves visibility: ' + status)
        rows = export(q='inventory-fixture', status=status, size=100)
        check([int(row[0]) for row in rows] == expected and all(row[3] == status.capitalize() for row in rows),
              'CSV preserves status and its displayed label: ' + status)
        if status != 'disabled':
            check(all(d['status'] == status.capitalize() for d in listing(q='inventory-fixture', status=status, size=100)['devices']),
                  'JSON projects the displayed status: ' + status)
    check(not listing(q='inventory-fixture', state='disabled', status='up')['devices'],
          'conflicting polling and status filters return no devices')
    check(not listing(q='inventory-fixture', state='enabled', status='disabled')['devices'],
          'disabled status cannot bypass enabled state')
    harness.sql('UPDATE host SET status=1 WHERE id IN (' + ','.join(map(str, ids)) + ')')
    for page, expected in ((1, allowed[1:26]), (2, allowed[26:])):
        result = listing(q='inventory-fixture', status='down', page=page)
        check([d['id'] for d in result['devices']] == expected and result['hasNext'] == (page == 1),
              'status and visibility apply before pagination')
        check([int(row[0]) for row in export(q='inventory-fixture', status='down', page=page)] == expected,
              'CSV preserves status-filtered page boundaries')

    class Links(HTMLParser):
        def __init__(self):
            super().__init__()
            self.links = []
            self.edits = []
            self.details = []
            self.selected = []

        def handle_starttag(self, tag, attributes):
            attrs = dict(attributes)
            if tag == 'a' and ('rel' in attrs or '.csv?' in attrs.get('href', '')):
                self.links.append(attrs['href'])
            if tag == 'a' and '/edit?' in attrs.get('href', ''):
                self.edits.append(attrs['href'])
            if tag == 'a' and '/inventory/devices/' in attrs.get('href', '') and '/edit' not in attrs.get('href', ''):
                self.details.append(attrs['href'])
            if tag == 'option' and 'selected' in attrs:
                self.selected.append(attrs.get('value'))

    for page in (1, 2):
        with session.opener.open(harness.base + base + '?' + urlencode(
                {'q': 'inventory-fixture', 'status': 'down', 'sort': 'hostname', 'direction': 'desc', 'page': page})) as response:
            html = Links()
            html.feed(response.read().decode())
        check(all(value in html.selected for value in ('down', 'hostname', 'desc')) and len(html.links) == 2,
              'Twig selects status and renders CSV plus pagination')
        check(bool(html.edits) and bool(html.details), 'filtered list links to device details and editors')
        for link in html.edits + html.details:
            context = parse_qs(urlsplit(link).query)
            check(context.get('list[q]') == ['inventory-fixture']
                  and context.get('list[status]') == ['down']
                  and context.get('list[sort]') == ['hostname']
                  and context.get('list[direction]') == ['desc']
                  and context.get('list[page]') == [str(page)]
                  and context.get('list[size]') == ['25'],
                  'device links carry the selected inventory view')
        for link in html.links:
            filters = parse_qs(urlsplit(link).query)
            check(filters.get('status') == ['down'] and filters.get('q') == ['inventory-fixture']
                  and filters.get('sort') == ['hostname'] and filters.get('direction') == ['desc'],
                  'Twig retains search, status and sorting in page and export links')
    harness.sql('UPDATE host SET status=3 WHERE id IN (' + ','.join(map(str, ids)) + ')')
    formula = ' \t=1+1,"東京"\nnext'
    harness.sql(f"UPDATE host SET description=CONVERT(UNHEX('{formula.encode().hex()}') USING utf8mb4) WHERE id={allowed[0]}")
    check(export(q='=1+1')[0][1] == "'" + formula, 'CSV quotes multiline Unicode text and neutralizes formulas')
    check(allowed[0] not in [d['id'] for d in listing(q='inventory-fixture', state='enabled')['devices']],
          'enabled filter excludes disabled devices')
    harness.sql(f"UPDATE host SET deleted='on' WHERE id={allowed[1]}")
    check(allowed[1] not in [d['id'] for d in listing(q='inventory-fixture', size=100)['devices']],
          'deleted devices are excluded')
    unsafe = '<script>alert(1)</script>'
    harness.sql(f"UPDATE host SET description='{unsafe}' WHERE id={allowed[0]}")
    response = session.opener.open(harness.base + base + '?' + urlencode({'q': unsafe}))
    body = response.read().decode()
    check('<option value="error"' in body, 'Twig offers the Error status filter')
    check(unsafe not in body and '&lt;script&gt;alert(1)&lt;/script&gt;' in body, 'Twig escapes stored and reflected text')
    check('no-store' in response.headers.get('Cache-Control', ''), 'Inventory responses are not cached')
    response.close()
    check('/app.php/inventory/devices' in body, 'Symfony generates links for the compatibility entry URL')
    check('/app.php/inventory/devices.csv?' in body and 'Export this page (CSV)' in body,
          'Twig links to the compatibility CSV route')
    for query in ('page=0', 'page=1e3', 'page[]=1', 'q[]=x', 'size=100000', 'state=other', 'status=other', 'status[]=up', 'status=3%20OR%201=1',
                  'site=-1', 'site[]=1', 'site=4294967296', 'site=00000000001', 'site=1e3',
                  'sort=description', 'sort[]=name', 'direction[]=asc', 'direction=invalid',
                  'sort=name%3BSELECT%201', 'direction=desc%3BSELECT%201'):
        check(session.request(base + '.json?' + query)['status'] == 400, 'invalid filters are rejected: ' + query)
        check(session.request(base + '.csv?' + query)['status'] == 400, 'invalid CSV filters are rejected: ' + query)
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
    check(session.request(base + '.json')['status'] == 403, 'console access alone does not authorize Inventory')
    check(session.request(base + '.csv')['status'] == 403, 'revoked device realm prevents CSV export')
    harness.sql("INSERT INTO user_auth_group (name,enabled,policy_hosts,policy_graphs,policy_graph_templates) VALUES ('inventory-test','on',2,2,2)")
    group = int(harness.sql("SELECT id FROM user_auth_group WHERE name='inventory-test'").strip())
    harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group},{user_id}); INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group},3); INSERT INTO user_auth_group_perms (group_id,item_id,type) VALUES ({group},{ids[0]},3)')
    check(ids[0] in [d['id'] for d in listing(q='inventory-fixture', size=100)['devices']],
          'enabled group grants realm and device visibility')
    harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group}")
    check(session.request(base + '.json')['status'] == 403, 'disabled group cannot grant Inventory access')
    harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
    # Default-allow device exceptions must not be bypassed by legacy fast paths.
    harness.sql(f"UPDATE user_auth SET policy_hosts=1 WHERE id={user_id}; DELETE FROM user_auth_perms WHERE user_id={user_id} AND type=3; INSERT INTO user_auth_perms (user_id,item_id,type) VALUES ({user_id},{ids[0]},3)")
    check(ids[0] not in [d['id'] for d in listing(q='inventory-fixture', size=100)['devices']],
          'default-allow device exceptions are enforced')
    harness.sql('DELETE FROM host WHERE id IN (' + ','.join(map(str, ids)) + ')')
    harness.sql(f'DELETE FROM user_auth_perms WHERE user_id={user_id} AND type=3; DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group_perms WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
    harness.sql(f"UPDATE user_auth SET policy_hosts={saved['policy_hosts']}, policy_graphs={saved['policy_graphs']}, policy_graph_templates={saved['policy_graph_templates']} WHERE id={user_id}")
    harness.sql("DELETE FROM settings WHERE name='graph_auth_method'")
    if saved_mode:
        harness.sql(f"INSERT INTO settings (name,value) VALUES ('graph_auth_method','{int(saved_mode)}')")
    print('Inventory HTTP and authorization checks passed.', flush=True)
