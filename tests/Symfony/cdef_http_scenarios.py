"""Real HTTP and MariaDB scenarios for the CDEF Symfony migration."""
from html.parser import HTMLParser
import json
import urllib.parse
from urllib.error import HTTPError
from urllib.request import Request
import uuid


class Fields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == 'input' and 'name' in attributes:
            self.values.setdefault(attributes['name'], []).append(attributes.get('value', ''))


def verify_cdefs(harness, session, check):
    base = harness.base
    user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
    harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},14)')

    listing = session.opener.open(base + '/app.php/graph-definitions/cdefs')
    content = listing.read().decode('utf-8')
    check(listing.status == 200 and '<h1>CDEFs</h1>' in content and 'no-store' in listing.headers.get('Cache-Control', ''),
          'CDEF list executes through the authenticated Symfony route and real MariaDB catalog')

    name = 'CDEF HTTP ' + uuid.uuid4().hex[:12]
    create_fields = _page(session, base + '/app.php/graph-definitions/cdefs/new')
    created = _post(session, base + '/app.php/graph-definitions/cdefs/new', {
        'cdef_edit[id]': create_fields['cdef_edit[id]'],
        'cdef_edit[name]': name,
        'cdef_edit[_token]': create_fields['cdef_edit[_token]'],
    })
    rows = harness.rows("SELECT JSON_OBJECT('id',id,'name',name) FROM cdef WHERE name='" + name + "'")
    check(created.status == 200 and len(rows) == 1 and rows[0]['name'] == name,
          'CSRF-protected CDEF create persists through the transactional editor')
    cdef_id = int(rows[0]['id'])

    item_ids = []
    for value in ('42', '8'):
        path = f'/app.php/graph-definitions/cdefs/{cdef_id}/items/0?type=6'
        item_fields = _page(session, base + path)
        response = _post(session, base + path, {
            'cdef_item[id]': item_fields['cdef_item[id]'],
            'cdef_item[cdef_id]': item_fields['cdef_item[cdef_id]'],
            'cdef_item[type]': '6',
            'cdef_item[value]': value,
            'cdef_item[_token]': item_fields['cdef_item[_token]'],
        })
        check(response.status == 200 and response.url.endswith(f'/app.php/graph-definitions/cdefs/{cdef_id}/edit'),
              'typed CDEF item save redirects through the authorized edit route')
        row = harness.rows(f"SELECT JSON_OBJECT('id',id,'value',value) FROM cdef_items WHERE cdef_id={cdef_id} AND value='{value}' ORDER BY id DESC LIMIT 1")
        check(len(row) == 1, 'typed CDEF item reaches MariaDB')
        item_ids.append(int(row[0]['id']))

    edit_response = session.opener.open(base + f'/app.php/graph-definitions/cdefs/{cdef_id}/edit')
    edit_html = edit_response.read().decode('utf-8')
    check('cdef=42,8' in edit_html, 'CDEF preview preserves raw RPN item ordering')

    order_path = f'/app.php/graph-definitions/cdefs/{cdef_id}/items/reorder'
    order_form = _page(session, base + f'/app.php/graph-definitions/cdefs/{cdef_id}/edit')
    baseline = json.loads(order_form['__all__']['order[items]'][0])
    if len(baseline) != 2 or set(baseline) != set(item_ids):
        raise AssertionError('The CDEF reorder form did not contain the expected item snapshot')
    harness.sql(f'UPDATE cdef_items SET sequence=99 WHERE id={baseline[0]}')
    harness.sql(f'UPDATE cdef_items SET sequence=1 WHERE id={baseline[1]}')
    harness.sql(f'UPDATE cdef_items SET sequence=2 WHERE id={baseline[0]}')
    stale = _post(session, base + order_path, {
        'order[items]': json.dumps(baseline),
        'order[_token]': order_form['order[_token]'],
    })
    stale_body = stale.read().decode('utf-8')
    current = [int(value) for value in harness.sql(f'SELECT id FROM cdef_items WHERE cdef_id={cdef_id} ORDER BY sequence,id').splitlines()]
    if stale.status != 409 or 'selection changed' not in stale_body or current != [baseline[1], baseline[0]]:
        raise AssertionError(f'Stale reorder mismatch: status={stale.status}, baseline={baseline}, current={current}, body={stale_body[:250]!r}')
    check(True, 'stale reorder is rejected when the same IDs have a different current sequence')

    action_query = urllib.parse.urlencode([('ids[]', str(cdef_id))])
    duplicate_url = base + '/app.php/graph-definitions/cdefs/actions/duplicate?' + action_query
    duplicate_fields = _page(session, duplicate_url)
    duplicated = _post(session, duplicate_url, {
        'cdef_action[selection]': duplicate_fields['cdef_action[selection]'],
        'cdef_action[title_format]': duplicate_fields['cdef_action[title_format]'],
        'cdef_action[_token]': duplicate_fields['cdef_action[_token]'],
    })
    duplicate_body = duplicated.read().decode('utf-8')
    copies = harness.rows("SELECT JSON_OBJECT('id',id,'name',name) FROM cdef WHERE name='" + name + ' (1)' + "'")
    if duplicated.status != 200 or len(copies) != 1:
        raise AssertionError(f'Duplicate flow mismatch: status={duplicated.status}, fields={duplicate_fields}, copies={copies}, body={duplicate_body[-1200:]!r}')
    check(True, 'bulk duplicate copies the CDEF and its selected item state')

    referrer_name = name + ' reference'
    harness.sql(f"INSERT INTO cdef (hash,`system`,name) VALUES ('{uuid.uuid4().hex}',0,'{referrer_name}')")
    referrer_id = int(harness.sql("SELECT id FROM cdef WHERE name='" + referrer_name + "'").strip())
    reference_lock = harness.php('-r', _mariadb_reference_lock_probe(user_id, cdef_id, referrer_id))
    check(reference_lock['exit'] == 0 and 'CDEF_REFERENCE_LOCK_OK' in reference_lock['stdout'],
          'a concurrent target-CDEF deletion lock blocks and rolls back a new reference')
    harness.sql(f"INSERT INTO cdef_items (hash,cdef_id,sequence,type,value) VALUES ('{uuid.uuid4().hex}',{referrer_id},1,5,'{cdef_id}')")
    delete_url = base + '/app.php/graph-definitions/cdefs/actions/delete?' + action_query
    delete_fields = _page(session, delete_url)
    denied = _post(session, delete_url, {
        'cdef_action[selection]': delete_fields['cdef_action[selection]'],
        'cdef_action[title_format]': delete_fields['cdef_action[title_format]'],
        'cdef_action[_token]': delete_fields['cdef_action[_token]'],
    })
    source_count = int(harness.sql(f'SELECT COUNT(*) FROM cdef WHERE id={cdef_id}').strip())
    check(denied.status == 409 and source_count == 1,
          'delete refuses a CDEF referenced by another definition without removing source rows')

    locked_form = _page(session, base + f'/app.php/graph-definitions/cdefs/{cdef_id}/items/{item_ids[0]}')
    harness.sql(f"UPDATE user_auth SET locked='on' WHERE id={user_id}")
    response = _post(session, base + f'/app.php/graph-definitions/cdefs/{cdef_id}/items/{item_ids[0]}', {
        'cdef_item[id]': str(item_ids[0]),
        'cdef_item[cdef_id]': str(cdef_id),
        'cdef_item[type]': '6',
        'cdef_item[value]': '999',
        'cdef_item[_token]': locked_form['cdef_item[_token]'],
    })
    value = harness.sql(f'SELECT value FROM cdef_items WHERE id={item_ids[0]}').strip()
    harness.sql(f"UPDATE user_auth SET locked='' WHERE id={user_id}")
    check(response.status in (401, 403) and value == '42',
          'locking an actor after form retrieval prevents the pending CDEF mutation')

    rollback = harness.php('-r', _mariadb_rollback_probe())
    check(rollback['exit'] == 0 and 'CDEF_ROLLBACK_OK' in rollback['stdout'],
          'a MariaDB item insert failure rolls back the newly inserted duplicate CDEF')


def _page(session, url):
    response = session.opener.open(url)
    body = response.read().decode('utf-8')
    parser = Fields()
    parser.feed(body)
    result = {name: values[-1] for name, values in parser.values.items()}
    result['__all__'] = parser.values
    return result


def _post(session, url, fields):
    request = Request(url, data=urllib.parse.urlencode(fields).encode(), headers={'Origin': session.base})
    try:
        return session.opener.open(request)
    except HTTPError as error:
        return error


def _mariadb_rollback_probe():
    return r'''require "include/vendor/autoload.php";
$configuration = (new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(__DIR__))->values();
$database = Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $configuration['host'], 'port' => $configuration['port'],
    'dbname' => $configuration['database'], 'user' => $configuration['username'], 'password' => $configuration['password'],
]);
foreach ([
    'CREATE TEMPORARY TABLE settings (name VARCHAR(64) PRIMARY KEY,value VARCHAR(255)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE user_auth (id INT PRIMARY KEY,username VARCHAR(64),enabled VARCHAR(8),locked VARCHAR(8),must_change_password VARCHAR(8)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE user_auth_realm (user_id INT,realm_id INT,PRIMARY KEY(user_id,realm_id)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE user_auth_group_realm (group_id INT,realm_id INT,PRIMARY KEY(group_id,realm_id)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE user_auth_group_members (group_id INT,user_id INT,PRIMARY KEY(group_id,user_id)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE user_auth_group (id INT PRIMARY KEY,enabled VARCHAR(8)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE cdef (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,hash VARCHAR(64) NOT NULL UNIQUE,`system` TINYINT NOT NULL DEFAULT 0,name VARCHAR(255) NOT NULL) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE cdef_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,hash VARCHAR(64) NOT NULL UNIQUE,cdef_id INT UNSIGNED NOT NULL,sequence INT NOT NULL,type INT NOT NULL,value VARCHAR(150),CONSTRAINT cdef_test_check CHECK(cdef_id<>99)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE graph_templates_item (id INT UNSIGNED PRIMARY KEY,cdef_id INT UNSIGNED,local_graph_id INT UNSIGNED,graph_template_id INT UNSIGNED) ENGINE=InnoDB',
] as $sql) { $database->executeStatement($sql); }
$database->executeStatement("INSERT INTO settings VALUES ('auth_method','1'),('guest_user','guest')");
$database->executeStatement("INSERT INTO user_auth VALUES (42,'operator','on','','')");
$database->executeStatement('INSERT INTO user_auth_realm VALUES (42,8),(42,14)');
$database->executeStatement("INSERT INTO cdef VALUES (1,'source-hash',0,'Source')");
$database->executeStatement("INSERT INTO cdef_items VALUES (1,'source-item-hash',1,1,6,'7')");
$database->executeStatement('ALTER TABLE cdef AUTO_INCREMENT=99');
$failed = false;
try { (new Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor($database))->act(42,'duplicate',[1]); }
catch (Throwable) { $failed = true; }
if (!$failed || (int)$database->fetchOne('SELECT COUNT(*) FROM cdef') !== 1 || (int)$database->fetchOne('SELECT COUNT(*) FROM cdef_items') !== 1) {
    throw new RuntimeException('CDEF duplicate rollback contract failed.');
}
echo 'CDEF_ROLLBACK_OK';'''


def _mariadb_reference_lock_probe(actor_id, target_id, source_id):
    return f'''require "include/vendor/autoload.php";
$configuration = (new Kadupul\\Platform\\Infrastructure\\Legacy\\InstallationConfiguration(__DIR__))->values();
$options = [
    'driver' => 'pdo_mysql', 'host' => $configuration['host'], 'port' => $configuration['port'],
    'dbname' => $configuration['database'], 'user' => $configuration['username'], 'password' => $configuration['password'],
];
$locker = Doctrine\\DBAL\\DriverManager::getConnection($options);
$writer = Doctrine\\DBAL\\DriverManager::getConnection($options);
$writer->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
$locker->beginTransaction();
$locker->fetchOne('SELECT id FROM cdef WHERE id = ? FOR UPDATE', [{target_id}]);
$blocked = false;
try {{
    (new Kadupul\\GraphDefinition\\Infrastructure\\Legacy\\LegacyCdefEditor($writer))->saveItem({actor_id}, {source_id}, 0, 5, '{target_id}');
}} catch (Throwable) {{
    $blocked = true;
}}
$count = (int) $writer->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id = {source_id} AND type = 5 AND value = "{target_id}"');
if (!$blocked || $count !== 0) {{
    $locker->rollBack();
    throw new RuntimeException('The writer did not wait for the target CDEF row lock.');
}}
$locker->commit();
(new Kadupul\\GraphDefinition\\Infrastructure\\Legacy\\LegacyCdefEditor($writer))->saveItem({actor_id}, {source_id}, 0, 5, '{target_id}');
$count = (int) $writer->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id = {source_id} AND type = 5 AND value = "{target_id}"');
if ($count !== 1) {{
    throw new RuntimeException('The valid CDEF reference was not saved after releasing the lock.');
}}
echo 'CDEF_REFERENCE_LOCK_OK';'''
