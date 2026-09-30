"""Device template management through actual Symfony HTTP and MariaDB."""
from html.parser import HTMLParser
from urllib.request import Request
from urllib.error import HTTPError
from urllib.parse import urlencode
import json
import uuid
from pathlib import Path
from device_create_scenarios import run_worker


class Fields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'):
            self.fields[attrs['name']] = attrs.get('value', '')


def verify_device_template_definitions(harness, session, user_id, check):
    base = '/app.php/inventory/device-templates'
    def request(path, fields=None, origin=True):
        req = Request(harness.base + path, data=None if fields is None else urlencode(fields).encode(), headers={'Origin': harness.base} if origin else {})
        try:
            response = session.opener.open(req)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode()
    def form(path):
        status, body = request(path)
        check(status == 200, 'device template form loads: ' + path)
        parser = Fields(); parser.feed(body)
        return parser.fields
    probe = Path(__file__).with_name('device_template_definition_authorization_probe.php').read_text().removeprefix('<?php')
    evidence = harness.php('-r', probe, str(user_id))
    check(evidence['exit'] == 0 and all(json.loads(evidence['stdout']).values()), 'permission, forced-password and auth policy inputs stay locked through transaction')
    uid = uuid.uuid4().hex[:12]
    name = 'Twig Device ' + uid
    fields = form(base + '/new')
    fields.update({'device_template_definition[name]': name, 'device_template_definition[class]': 'router'})
    check(request(base + '/new', fields, origin=False)[0] == 422, 'create requires same-origin CSRF proof')
    check(request(base + '/new', fields)[0] == 200, 'create persists then redirects to editor')
    tid = int(harness.sql("SELECT id FROM host_template WHERE name='" + name + "'").strip())
    edit = base + f'/{tid}/edit'
    defaults = harness.sql("SELECT name,value FROM settings WHERE name IN ('default_has','num_rows_table')").strip()
    try:
        harness.sql("INSERT INTO settings (name,value) VALUES ('default_has','on'),('num_rows_table','30') ON DUPLICATE KEY UPDATE value=VALUES(value)")
        status, body = request(base + '?reset=1')
        check(status == 200 and 'value="30" selected' in body and 'value="true" selected' in body and name not in body, 'site defaults select has-devices and configured page size')
        status, body = request(base + '?has_hosts=false&size=15&class=router')
        check(status == 200 and name in body and 'Network Router' in body, 'has-devices filter can be cleared and class label is readable')
        status, body = request(base)
        check(status == 200 and 'value="15" selected' in body and 'value="router" selected' in body, 'filters persist per user across partial requests')
        row = json.loads(harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='twig_device_template_filters'").strip())
        check(row['size'] == 15 and row['class'] == 'router' and row['has_hosts'] == 'false', 'remembered filters are scoped to current actor')
        status, body = request('/host_templates.php?rows=44&has_hosts=false')
        check(status == 200 and 'value="44" selected' in body, 'legacy page-size and has-devices filters normalize correctly')
    finally:
        harness.sql("DELETE FROM settings WHERE name IN ('default_has','num_rows_table')")
        for line in defaults.splitlines():
            key, value = line.split('\t', 1)
            harness.sql("INSERT INTO settings (name,value) VALUES ('" + key + "','" + value.replace("'", "''") + "')")
        request(base + '?reset=1')
    before = form(edit)
    update = before | {'device_template_definition[name]': '<script>device-template-stored</script>', 'device_template_definition[class]': 'switch'}
    status, body = request(edit, update)
    check(status == 200 and '&lt;script&gt;device-template-stored&lt;/script&gt;' in body and '<script>device-template-stored</script>' not in body, 'stored name remains escaped after save')
    check(request(edit, before | {'device_template_definition[name]': 'stale', 'device_template_definition[class]': 'router'})[0] == 409, 'stale parent revision rejects edit')
    excluded = harness.sql('SELECT MIN(graph_template_id) FROM snmp_query_graph').strip()
    if excluded not in ('NULL', ''):
        path = base + f'/{tid}/association/graph/add'
        data = form(path)
        check(f'value="{excluded}"' not in request(path)[1], 'query-backed graph template is excluded from direct-add dropdown')
        data['device_template_association[child]'] = excluded
        check(request(path, data)[0] == 422, 'query-backed direct association rejected by form')
    current = form(edit)
    check(request(edit, current | {'device_template_definition[name]': 'invalid', 'device_template_definition[class]': 'bogus'})[0] == 422, 'class is validated before worker')
    graph = int(harness.sql('SELECT MIN(id) FROM graph_templates WHERE id NOT IN (SELECT graph_template_id FROM snmp_query_graph)').strip())
    query = int(harness.sql('SELECT MIN(id) FROM snmp_query').strip())
    for kind, child, table, key in [('graph', graph, 'host_template_graph', 'graph_template_id'), ('query', query, 'host_template_snmp_query', 'snmp_query_id')]:
        path = base + f'/{tid}/association/{kind}/add'
        data = form(path)
        data['device_template_association[child]'] = str(child)
        check(request(path, data)[0] == 200, kind + ' association writes through Symfony')
        check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE host_template_id={tid} AND {key}={child}').strip() == '1', kind + ' child belongs to route template')
    check(request(edit, current | {'device_template_definition[name]': 'child-stale', 'device_template_definition[class]': 'switch'})[0] == 409, 'association changes invalidate parent revision')
    status, body = request(base + f'?class=switch&graph={graph}')
    check(status == 200 and 'device-template-stored' in body, 'class and direct graph filters find template')
    harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={tid}')
    indirect = harness.sql(f'SELECT MIN(graph_template_id) FROM snmp_query_graph WHERE snmp_query_id={query}').strip()
    if indirect not in ('NULL', ''):
        check('device-template-stored' in request(base + f'?graph={indirect}')[1], 'SNMP-derived graph filter finds template')
    path = base + f'/{tid}/association/query/remove'
    data = form(path); data['device_template_association[child]'] = str(query)
    check(request(path, data)[0] == 200 and harness.sql(f'SELECT COUNT(*) FROM host_template_snmp_query WHERE host_template_id={tid}').strip() == '0', 'remove query is protected and route-bound')
    def bulk(operation, id=tid):
        return form(base + f'/action/{operation}?ids%5B%5D={id}')
    copy = bulk('duplicate'); copy['device_template_action[title_format]'] = '<template_title> copied ' + uid
    check(request(base + '/action/duplicate', copy)[0] == 200, 'duplicate API returns to list')
    duplicate = int(harness.sql("SELECT id FROM host_template WHERE name='<script>device-template-stored</script> copied " + uid + "'").strip())
    check(harness.sql(f'SELECT COUNT(DISTINCT hash) FROM host_template WHERE id IN ({tid},{duplicate})').strip() == '2', 'duplicate retains unique API-generated hash')
    stale_delete = bulk('delete')
    harness.sql(f"UPDATE host_template SET name='updated {uid}' WHERE id={tid}")
    check(request(base + '/action/delete', stale_delete)[0] == 409, 'stale bulk action has no effect')
    check(harness.sql(f'SELECT COUNT(*) FROM host_template WHERE id={tid}').strip() == '1', 'stale delete preserves template')
    data = bulk('duplicate'); data['device_template_action[title_format]'] = 'rollback ' + uid
    harness.sql("CREATE TRIGGER twig_device_template_fail BEFORE INSERT ON host_template FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected device-template failure'")
    try:
        check(request(base + '/action/duplicate', data)[0] == 502, 'database failure is surfaced')
        check(harness.sql("SELECT COUNT(*) FROM host_template WHERE name='rollback " + uid + "'").strip() == '0', 'failed duplicate rolls back')
    finally:
        harness.sql('DROP TRIGGER twig_device_template_fail')
    for malformed in ['?q%5Bx%5D=1', '?page=0', '?sort=bogus', '?graph%5Bx%5D=1']:
        check(request(base + malformed)[0] == 400, 'malformed filters fail before catalog: ' + malformed)
    group = int(harness.sql(f"INSERT INTO user_auth_group (name,enabled) VALUES ('twiggrp-{uid}','on'); SELECT LAST_INSERT_ID()").strip())
    direct = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=12').strip() == '1'
    try:
        harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group},{user_id}); INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group},12); DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=12')
        check(request(base)[0] == 200, 'enabled group supplies current device-template grant')
        harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group}")
        check(request(base)[0] == 403, 'disabled group cannot grant template access')
        harness.sql(f"UPDATE user_auth_group SET enabled='on' WHERE id={group}")
        deny = form(edit)
        harness.sql(f'DELETE FROM user_auth_group_realm WHERE group_id={group} AND realm_id=12')
        deny['device_template_definition[name]'] = 'revoked'
        deny['device_template_definition[class]'] = 'switch'
        check(request(edit, deny)[0] == 403, 'grant revocation between render and save rejects mutation')
    finally:
        if direct: harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},12)')
        harness.sql(f'DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
    original = harness.sql(f'SELECT must_change_password FROM user_auth WHERE id={user_id}').strip()
    try:
        harness.sql(f"UPDATE user_auth SET must_change_password='on' WHERE id={user_id}")
        check(request(base)[0] == 403, 'forced-password account denied on reads')
    finally:
        harness.sql(f"UPDATE user_auth SET must_change_password='{original}' WHERE id={user_id}")
    check(request('/host_templates.php', {'action': 'actions', 'selected_items': 'a:1:{i:0;i:' + str(tid) + ';}'})[0] == 409, 'legacy POST expires without replay')
    check(harness.sql(f'SELECT COUNT(*) FROM host_template WHERE id={tid}').strip() == '1', 'legacy POST cannot delete')
    sync_host = int(harness.sql(f"INSERT INTO host (description,hostname,host_template_id,status,poller_id) VALUES ('sync {uid}','127.0.0.1',{tid},3,1); SELECT LAST_INSERT_ID()").strip())
    old_engine = harness.sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='host_snmp_cache'").strip()
    try:
        harness.sql('ALTER TABLE host_snmp_cache ENGINE=InnoDB')
        harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({tid},{graph})')
        sync = bulk('sync')
        check(request(base + '/action/sync', sync)[0] == 200, 'sync invokes legacy device API and returns to list')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={sync_host} AND graph_template_id={graph}').strip() == '1', 'sync hands template graph association to active device')
        claim = 'device_template_sync_' + sync['device_template_action[operation_id]']
        snapshot = harness.sql(f"SELECT value FROM settings WHERE name='{claim}'").strip()
        check(request(base + '/action/sync', sync)[0] == 409, 'durable operation token prevents sync replay')
        check(harness.sql(f"SELECT value FROM settings WHERE name='{claim}'").strip() == snapshot, 'same-actor replay preserves original claim byte-for-byte')
        other = int(harness.sql(f"INSERT INTO user_auth (username,enabled) VALUES ('sync-collision-{uid}','on'); SELECT LAST_INSERT_ID()").strip())
        try:
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({other},8),({other},12)')
            replay = run_worker(harness, 'bin/legacy-device-template-definition.php', 'KADUPUL_DEVICE_DEFINITION_RESULT', {'actor': other, 'action': 'sync', 'correlation': uuid.uuid4().hex, 'operation_id': sync['device_template_action[operation_id]'], 'revisions': json.loads(sync['device_template_action[selection]'])})
            check(replay['result']['status'] == 'conflict' and harness.sql(f"SELECT value FROM settings WHERE name='{claim}'").strip() == snapshot, 'other-actor token collision preserves original claim byte-for-byte')
        finally:
            harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={other}; DELETE FROM user_auth WHERE id={other}')
        harness.sql(f'DELETE FROM host_graph WHERE host_id={sync_host}')
        partial = bulk('sync')
        harness.sql("CREATE TRIGGER twig_device_template_sync_fail BEFORE INSERT ON host_graph FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected sync failure'")
        try:
            status, body = request(base + '/action/sync', partial)
            check(status == 502 and 'will not be replayed' in body, 'sync failure reports possible external effects explicitly')
            check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={sync_host}').strip() == '0', 'sync failure rolls back primary association writes')
            check(request(base + '/action/sync', partial)[0] == 409, 'partial sync cannot be silently replayed')
        finally:
            harness.sql('DROP TRIGGER twig_device_template_sync_fail')
    finally:
        harness.sql(f'DELETE FROM host_graph WHERE host_id={sync_host}; DELETE FROM host WHERE id={sync_host}')
        if old_engine.upper() != 'INNODB': harness.sql(f'ALTER TABLE host_snmp_cache ENGINE={old_engine}')
    hosts = []
    try:
        for deleted in ['', 'on']:
            hid = int(harness.sql(f"INSERT INTO host (description,hostname,host_template_id,deleted) VALUES ('device-template {uid}','127.0.0.1',{tid},'{deleted}'); SELECT LAST_INSERT_ID()").strip())
            hosts.append(hid)
        status, body = request(base + '?has_hosts=true&graph=0&class=-1&q=' + uid)
        check(status == 200 and f'host_template_id={tid}">2</a>' in body, 'list count and has-devices filter include soft-deleted device references')
        remove = bulk('delete')
        check(request(base + '/action/delete', remove)[0] == 200, 'delete completes')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={hosts[0]}').strip() == '0', 'delete detaches active host')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={hosts[1]}').strip() == str(tid), 'delete preserves soft-deleted host mapping')
        check(harness.sql(f'SELECT COUNT(*) FROM host_template WHERE id={tid}').strip() == '0', 'template parent deleted')
    finally:
        for hid in hosts: harness.sql(f'DELETE FROM host WHERE id={hid}')
        harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id IN ({tid},{duplicate}); DELETE FROM host_template_snmp_query WHERE host_template_id IN ({tid},{duplicate}); DELETE FROM host_template WHERE id IN ({tid},{duplicate})')
