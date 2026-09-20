"""Inventory migration checks against real Symfony HTTP routes and legacy tables."""
import json
from urllib.parse import urlencode


def verify_inventory(harness, session, user_id, check):
    base = '/app.php/inventory/devices'

    def listing(**filters):
        result = session.request(base + '.json?' + urlencode(filters))
        check(result['status'] == 200, 'Inventory query succeeds')
        return result['json']

    # No production database is used: Harness.setup owns this disposable schema.
    saved = harness.rows(f"SELECT JSON_OBJECT('policy_hosts',policy_hosts,'policy_graphs',policy_graphs,'policy_graph_templates',policy_graph_templates) FROM user_auth WHERE id={user_id}")[0]
    saved_mode = harness.sql("SELECT value FROM settings WHERE name='graph_auth_method'").strip()
    harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
    harness.sql(f"UPDATE user_auth SET policy_hosts=2, policy_graphs=2, policy_graph_templates=2 WHERE id={user_id}")
    values = ','.join(f"('inventory-fixture-{i:02d}','fixture-{i}.invalid','',3)" for i in range(28))
    harness.sql('INSERT INTO host (description,hostname,disabled,status) VALUES ' + values)
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
    first = listing(q='inventory-fixture')
    second = listing(q='inventory-fixture', page=2)
    check(len(first['devices']) == 25 and first['hasNext'] and len(second['devices']) == 2 and not second['hasNext'],
          'permissions apply before page boundaries and lookahead')
    check([d['id'] for d in first['devices'] + second['devices']] == allowed,
          'hidden devices never enter either page')
    check(all(set(d) == {'id', 'description', 'hostname', 'disabled', 'status'} for d in first['devices']),
          'device projection exposes no SNMP credentials or notes')
    check(not listing(q="%' OR 1=1 --")['devices'], 'search metacharacters cannot expand the query')
    check(not listing(q='inventory-fixture%')['devices'], 'search percent is literal')
    harness.sql(f"UPDATE host SET disabled='on' WHERE id={allowed[0]}")
    check([d['id'] for d in listing(q='inventory-fixture', state='disabled')['devices']] == [allowed[0]],
          'disabled filter preserves visibility')
    check(allowed[0] not in [d['id'] for d in listing(q='inventory-fixture', state='enabled')['devices']],
          'enabled filter excludes disabled devices')
    harness.sql(f"UPDATE host SET deleted='on' WHERE id={allowed[1]}")
    check(allowed[1] not in [d['id'] for d in listing(q='inventory-fixture', size=100)['devices']],
          'deleted devices are excluded')
    unsafe = '<script>alert(1)</script>'
    harness.sql(f"UPDATE host SET description='{unsafe}' WHERE id={allowed[0]}")
    response = session.opener.open(harness.base + base + '?' + urlencode({'q': unsafe}))
    body = response.read().decode()
    check(unsafe not in body and '&lt;script&gt;alert(1)&lt;/script&gt;' in body, 'Twig escapes stored and reflected text')
    check('no-store' in response.headers.get('Cache-Control', ''), 'Inventory responses are not cached')
    response.close()
    check('/app.php/inventory/devices' in body, 'Symfony generates links for the compatibility entry URL')
    for query in ('page=0', 'page=1e3', 'page[]=1', 'q[]=x', 'size=100000', 'state=other'):
        check(session.request(base + '.json?' + query)['status'] == 400, 'invalid filters are rejected: ' + query)
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
    check(session.request(base + '.json')['status'] == 403, 'console access alone does not authorize Inventory')
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
