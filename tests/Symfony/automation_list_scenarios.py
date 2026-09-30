"""Exercise all remaining Automation read models against real HTTP/MariaDB."""
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request


def verify_automation_lists(harness, session, user_id, check):
    fixtures = []
    group = None
    original = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23').strip()

    def fetch(path, method='GET'):
        try:
            response = session.opener.open(Request(harness.base + path, method=method))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    screens = [
        ('devices', 'discovery_filter', 'twig-<router>', '&lt;router&gt;', 'automation_devices',
         "INSERT INTO automation_devices (hostname,ip,sysDescr,sysUptime,snmp,up,time,snmp_password) VALUES ('twig-<router>','192.0.2.237','fixture',12000,1,1,UNIX_TIMESTAMP(),'credential-sentinel')"),
        ('templates', 'automation_template_filter', 'twig-<template>', '&lt;template&gt;', 'automation_templates',
         "INSERT INTO automation_templates (host_template,sysDescr,availability_method,sequence) VALUES (0,'twig-<template>',3,1)"),
        ('tree-rules', 'automation_tree_rule_filter', 'twig-<tree>', '&lt;tree&gt;', 'automation_tree_rules',
         "INSERT INTO automation_tree_rules (name,leaf_type,enabled) VALUES ('twig-<tree>',2,'on')"),
        ('graph-rules', 'automation_graph_rule_filter', 'twig-<graph>', '&lt;graph&gt;', 'automation_graph_rules',
         "INSERT INTO automation_graph_rules (name,enabled) VALUES ('twig-<graph>','on')"),
    ]
    try:
        if original == '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},23)')
        for screen, form, term, escaped, table, insert in screens:
            ident = int(harness.sql(insert + '; SELECT LAST_INSERT_ID()').strip())
            fixtures.append((table, ident))
            route = '/app.php/automation/' + screen
            query = urlencode({form + '[q]': term})
            status, body, headers = fetch(route + '?' + query)
            check(status == 200 and escaped in body and term not in body and 'credential-sentinel' not in body,
                  'Automation ' + screen + ' partial filters render escaped MariaDB values without credentials')
            check('no-store' in headers.get('Cache-Control', ''), 'Automation ' + screen + ' response prohibits storage')
            status, body, _ = fetch(route + '?' + query, 'HEAD')
            check(status == 200 and body == '', 'Automation ' + screen + ' HEAD omits the body')
            check(fetch(route + '?page[]=1')[0] == 400, 'Automation ' + screen + ' rejects malformed page input')
            check(fetch(route, 'POST')[0] == 405, 'Automation ' + screen + ' cannot mutate through its read route')
            check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE id={ident}').strip() == '1',
                  'Automation ' + screen + ' read leaves its source row unchanged')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23')
        for screen, *_ in screens:
            check(fetch('/app.php/automation/' + screen)[0] == 403,
                  'Automation ' + screen + ' requires realm 23 independently of device access')
        group = int(harness.sql("INSERT INTO user_auth_group (name,enabled) VALUES ('twig-automation','on'); SELECT LAST_INSERT_ID()").strip())
        harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group},{user_id}); INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group},23)')
        for screen, *_ in screens:
            check(fetch('/app.php/automation/' + screen)[0] == 200, 'Automation ' + screen + ' accepts an enabled group grant')
        harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group}")
        for screen, *_ in screens:
            check(fetch('/app.php/automation/' + screen)[0] == 403, 'Automation ' + screen + ' rejects a disabled group grant')
    finally:
        for table, ident in fixtures:
            harness.sql(f'DELETE FROM {table} WHERE id={ident}')
        if group is not None:
            harness.sql(f'DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23')
        if original != '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},23)')
