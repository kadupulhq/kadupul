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

    before_legacy_post = int(harness.sql('SELECT COUNT(*) FROM cdef').strip())
    legacy_post = _post(session, base + '/cdef.php?action=actions', {
        'action': 'delete',
        'selected_items[]': '1',
    })
    legacy_body = legacy_post.read().decode('utf-8', 'replace')
    after_legacy_post = int(harness.sql('SELECT COUNT(*) FROM cdef').strip())
    check(legacy_post.status == 409 and 'submitted through the Symfony forms' in legacy_body
          and after_legacy_post == before_legacy_post,
          'expired legacy CDEF POST is rejected over HTTP without replaying a bulk mutation')

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

    fresh_order = _page(session, base + f'/app.php/graph-definitions/cdefs/{cdef_id}/edit')
    reordered = _post(session, base + order_path, {
        'order[items]': fresh_order['order[items]'],
        'order[moveUp]': str(baseline[0]),
        'order[_token]': fresh_order['order[_token]'],
    })
    stored_order = [int(value) for value in harness.sql(f'SELECT id FROM cdef_items WHERE cdef_id={cdef_id} ORDER BY sequence,id').splitlines()]
    check(reordered.status == 200 and stored_order == baseline,
          'CDEF successful reorder persists the requested RPN sequence')
    csrf_order = _page(session, base + f'/app.php/graph-definitions/cdefs/{cdef_id}/edit')
    invalid_order = _post(session, base + order_path, {'order[items]': csrf_order['order[items]'], 'order[moveDown]': str(baseline[0])})
    unchanged_order = [int(value) for value in harness.sql(f'SELECT id FROM cdef_items WHERE cdef_id={cdef_id} ORDER BY sequence,id').splitlines()]
    check(invalid_order.status == 422 and unchanged_order == baseline,
          'CDEF reorder without CSRF cannot hand off a mutation')

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

    duplicate_id = int(copies[0]['id'])
    duplicate_items = [int(value) for value in harness.sql(f'SELECT id FROM cdef_items WHERE cdef_id={duplicate_id} ORDER BY sequence,id').splitlines()]
    check(len(duplicate_items) == 2 and harness.sql(f'SELECT GROUP_CONCAT(value ORDER BY sequence,id) FROM cdef_items WHERE cdef_id={duplicate_id}').strip() == '42,8',
          'CDEF duplicate preserves the ordered RPN values consumed by graph generation')
    delete_item_url = base + f'/app.php/graph-definitions/cdefs/{duplicate_id}/items/{duplicate_items[0]}/delete'
    item_delete_fields = _page(session, delete_item_url)
    check(_post(session, delete_item_url, {'confirm[_token]': 'invalid'}).status == 422,
          'CDEF item deletion rejects an invalid CSRF token')
    item_deleted = _post(session, delete_item_url, {'confirm[_token]': item_delete_fields['confirm[_token]']})
    check(item_deleted.status == 200 and harness.sql(f'SELECT GROUP_CONCAT(value ORDER BY sequence,id) FROM cdef_items WHERE cdef_id={duplicate_id}').strip() == '8',
          'CDEF item deletion reaches MariaDB and preserves surviving RPN order')
    try:
        session.opener.open(delete_item_url)
        missing_status = 200
    except HTTPError as error:
        missing_status = error.code
        error.close()
    check(missing_status == 404, 'CDEF removed item cannot be loaded again')

    duplicate_delete_url = base + '/app.php/graph-definitions/cdefs/actions/delete?' + urllib.parse.urlencode([('ids[]', str(duplicate_id))])
    duplicate_delete_fields = _page(session, duplicate_delete_url)
    duplicate_deleted = _post(session, duplicate_delete_url, {key: value for key, value in duplicate_delete_fields.items() if key.startswith('cdef_action[')})
    check(duplicate_deleted.status == 200
          and harness.sql(f'SELECT COUNT(*) FROM cdef WHERE id={duplicate_id}').strip() == '0'
          and harness.sql(f'SELECT COUNT(*) FROM cdef_items WHERE cdef_id={duplicate_id}').strip() == '0',
          'CDEF bulk deletion removes the duplicate and its owned items')

    for legacy_path, expected_suffix in [
        ('/cdef.php?action=edit', '/cdefs/new'),
        (f'/cdef.php?action=edit&id={cdef_id}', f'/cdefs/{cdef_id}/edit'),
        (f'/cdef.php?action=item_edit&cdef_id={cdef_id}&id={item_ids[0]}', f'/cdefs/{cdef_id}/items/{item_ids[0]}'),
        ('/cdef.php?filter=CDEF&rows=30&sort_column=graphs&sort_direction=desc&has_graphs=true', '/cdefs'),
    ]:
        with session.opener.open(base + legacy_path) as response:
            check(response.status == 200 and urllib.parse.urlparse(response.url).path.endswith(expected_suffix),
                  'legacy CDEF GET forwards to its fixed Symfony route: ' + legacy_path)

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

    guards = harness.php('-r', _mariadb_guard_probe())
    check(guards['exit'] == 0 and 'CDEF_WRITE_GUARDS_OK' in guards['stdout'],
          'CDEF writes reject nontransactional tables, remote collectors and caller transactions without losing caller work')

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
try { (new Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor($database, new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(__DIR__)))->act(42,'duplicate',[1]); }
catch (Throwable $error) { $failed = str_contains($error->getMessage(), 'cdef_test_check'); }
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
    (new Kadupul\\GraphDefinition\\Infrastructure\\Legacy\\LegacyCdefEditor($writer, new Kadupul\\Platform\\Infrastructure\\Legacy\\InstallationConfiguration(__DIR__)))->saveItem({actor_id}, {source_id}, 0, 5, '{target_id}');
}} catch (Throwable) {{
    $blocked = true;
}}
$count = (int) $writer->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id = {source_id} AND type = 5 AND value = "{target_id}"');
if (!$blocked || $count !== 0) {{
    $locker->rollBack();
    throw new RuntimeException('The writer did not wait for the target CDEF row lock.');
}}
$locker->commit();
(new Kadupul\\GraphDefinition\\Infrastructure\\Legacy\\LegacyCdefEditor($writer, new Kadupul\\Platform\\Infrastructure\\Legacy\\InstallationConfiguration(__DIR__)))->saveItem({actor_id}, {source_id}, 0, 5, '{target_id}');
$count = (int) $writer->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id = {source_id} AND type = 5 AND value = "{target_id}"');
if ($count !== 1) {{
    throw new RuntimeException('The valid CDEF reference was not saved after releasing the lock.');
}}
echo 'CDEF_REFERENCE_LOCK_OK';'''


def _mariadb_guard_probe():
    fixture = _mariadb_rollback_probe().split("$database->executeStatement('ALTER TABLE cdef AUTO_INCREMENT=99');", 1)[0]
    return fixture + r'''$primary = new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(__DIR__);
$editor = new Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor($database, $primary);
foreach (['cdef','cdef_items','graph_templates_item','settings','user_auth','user_auth_realm',
          'user_auth_group','user_auth_group_members','user_auth_group_realm'] as $table) {
    $database->executeStatement('ALTER TABLE '.$database->quoteIdentifier($table).' ENGINE=MyISAM');
    $rejected = false;
    try { $editor->save(42, 0, 'Forbidden MyISAM mutation'); }
    catch (RuntimeException $error) { $rejected = $error->getMessage() === 'CDEF mutations require InnoDB tables.'; }
    finally { $database->executeStatement('ALTER TABLE '.$database->quoteIdentifier($table).' ENGINE=InnoDB'); }
    if (!$rejected || (int)$database->fetchOne('SELECT COUNT(*) FROM cdef') !== 1 || $database->isTransactionActive()) {
        throw new RuntimeException('Nontransactional CDEF table was not rejected before writing: '.$table);
    }
}
foreach ([2, '1', null] as $collector) {
    $remote = new class($collector) implements Kadupul\Platform\Contract\LegacyConfiguration {
        public function __construct(private mixed $collector) {}
        public function values(): array { return ['collector_id' => $this->collector]; }
    };
    $rejected = false;
    try { (new Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor($database, $remote))->save(42, 0, 'Remote write'); }
    catch (RuntimeException $error) { $rejected = $error->getMessage() === 'CDEF mutations require the primary collector.'; }
    if (!$rejected || $database->isTransactionActive() || (int)$database->fetchOne('SELECT COUNT(*) FROM cdef') !== 1) {
        throw new RuntimeException('CDEF primary collector precondition failed.');
    }
}
foreach ([false, true] as $nativeTransaction) {
    $native = $database->getNativeConnection();
    if ($nativeTransaction) { $native->beginTransaction(); } else { $database->beginTransaction(); }
    $database->executeStatement("INSERT INTO cdef VALUES (77,'caller-owned',0,'Caller work')");
    $rejected = false;
    try { $editor->save(42, 0, 'Forbidden nested mutation'); }
    catch (RuntimeException $error) { $rejected = $error->getMessage() === 'CDEF mutations cannot join an existing transaction.'; }
    if (!$rejected || !$native->inTransaction() || $database->getTransactionNestingLevel() !== ($nativeTransaction ? 0 : 1)
        || (int)$database->fetchOne('SELECT COUNT(*) FROM cdef') !== 2) {
        throw new RuntimeException('CDEF adapter altered caller transaction ownership or rows.');
    }
    if ($nativeTransaction) { $native->rollBack(); } else { $database->rollBack(); }
    if ((int)$database->fetchOne('SELECT COUNT(*) FROM cdef') !== 1) { throw new RuntimeException('Caller rollback lost ownership.'); }
}
$database->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$editor->save(42, 1, 'Owned successful mutation');
if ($database->isTransactionActive() || $database->fetchOne('SELECT name FROM cdef WHERE id=1') !== 'Owned successful mutation') {
    throw new RuntimeException('CDEF mutation failed with an alternate session isolation.');
}
echo 'CDEF_WRITE_GUARDS_OK';'''
