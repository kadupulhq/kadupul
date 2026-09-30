"""Collector review regressions using real HTTP, session identity and MariaDB."""
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request


def verify_collector_list(harness, session, user_id, check):
    route = '/app.php/collectors'
    start = int(harness.sql('SELECT COALESCE(MAX(id), 0) + 1 FROM poller').strip())
    ids = list(range(start, start + 28))
    realm = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3').strip()
    group = None

    def fetch(path, method='GET'):
        try:
            response = session.opener.open(Request(harness.base + path, method=method))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8'), response.headers

    try:
        values = ','.join(f"({ident},'twig-collector-{index:02d}', 'collector-{index}.example', '', 1, {index}, NOW(), NOW(), 'credential-sentinel')" for index, ident in enumerate(ids))
        harness.sql('INSERT INTO poller (id,name,hostname,disabled,status,snmp,last_status,last_update,dbpass) VALUES ' + values)
        harness.sql(f"UPDATE poller SET name='twig-collector-<script>',hostname='<collector.example>' WHERE id={ids[0]}")
        harness.sql(f"UPDATE poller SET name='twig-collector-percent%',disabled='on',last_status='2000-01-01' WHERE id={ids[1]}")
        harness.sql(f"UPDATE poller SET name='twig-collector-stale',last_status='2000-01-01' WHERE id={ids[2]}")
        if realm == '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        query = urlencode({'collector_filter[q]': 'twig-collector', 'collector_filter[sort]': 'id', 'collector_filter[size]': '25'})
        status, body, headers = fetch(route + '?' + query)
        check(status == 200, 'collector list renders against the production MariaDB schema')
        check('&lt;script&gt;' in body and '&lt;collector.example&gt;' in body and '<collector.example>' not in body,
              'collector names and hostnames are escaped at the Twig boundary')
        check('credential-sentinel' not in body, 'collector list does not disclose database credentials')
        check('Disabled' in body and 'Heartbeat' in body, 'collector disabled and stale heartbeat display overrides are preserved')
        check('no-store' in headers.get('Cache-Control', ''), 'collector responses prohibit caching')
        check(headers.get('Refresh') == '20', 'collector list retains the legacy default refresh interval')
        check('rel="next"' in body, 'collector lookahead exposes the next page')
        status, second, _ = fetch(route + '?' + query + '&page=2')
        check(status == 200 and 'twig-collector-27' in second and 'rel="prev"' in second and 'rel="next"' not in second,
              'collector pagination keeps search and deterministic order without duplicates')
        status, body, headers = fetch(route + '?' + query + '&collector_filter[refresh]=0')
        check(status == 200 and headers.get('Refresh') is None, 'collector auto-refresh can be disabled')
        status, body, _ = fetch(route + '?' + query, 'HEAD')
        check(status == 200 and body == '', 'collector HEAD returns no response body')
        for bad in ('page[]=1', 'collector_filter[q][]=bad', 'collector_filter[refresh]=1', 'collector_filter[sort]=dbpass'):
            check(fetch(route + '?' + bad)[0] == 400, 'collector rejects malformed or unsupported filters: ' + bad)
        for sort in ('snmp', 'script', 'server'):
            check(fetch(route + '?' + query + '&collector_filter[sort]=' + sort)[0] == 200,
                  'collector preserves legacy counter sort: ' + sort)
        status, body, _ = fetch(route + '?' + urlencode({'collector_filter[q]': 'twig-collector-missing'}))
        check(status == 200 and 'No Data Collectors Found' in body, 'collector empty state is rendered')
        check(session.request('/pollers.php?action=actions&drp_action=1', {'selected_items': 'forged', 'drp_action': '1'})['status'] == 409,
              'legacy collector POST cannot replay a serialized mutation')
        check(harness.sql(f'SELECT COUNT(*) FROM poller WHERE id BETWEEN {start} AND {ids[-1]}').strip() == '28',
              'legacy rejected collector form leaves database rows intact')
        check(session.request('/pollers.php?filter=twig-collector&rows=25')['status'] == 200,
              'pollers compatibility URL enters Symfony and reaches the collector page')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        check(fetch(route)[0] == 403, 'console access alone cannot view Data Collectors')
        harness.sql("INSERT INTO user_auth_group (name,enabled) VALUES ('collector-review','on')")
        group = int(harness.sql("SELECT id FROM user_auth_group WHERE name='collector-review'").strip())
        harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group},{user_id}); INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group},3)')
        check(fetch(route)[0] == 200, 'enabled group device realm grants collector access')
        harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group}")
        check(fetch(route)[0] == 403, 'disabled group immediately revokes collector access')
    finally:
        harness.sql(f'DELETE FROM poller WHERE id BETWEEN {start} AND {ids[-1]}')
        if group is not None:
            harness.sql(f'DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        if realm != '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
