# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Palette CRUD, CSV, authorization and transaction handoff on isolated MariaDB."""
from pathlib import Path
import csv
import io
import sys
import secrets
from html.parser import HTMLParser
from urllib.parse import urlencode, urlsplit
from urllib.request import Request
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Session
from device_edit_scenarios import Inputs



class PaletteLabels(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = {}
        self.labels = {}
        self.href = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a':
            self.href = attrs.get('href')
        if tag == 'input' and attrs.get('name') == 'ids[]':
            self.labels[attrs.get('value')] = attrs.get('aria-label')

    def handle_data(self, data):
        if self.href is not None:
            self.links[self.href] = self.links.get(self.href, '') + data

    def handle_endtag(self, tag):
        if tag == 'a':
            self.href = None


def verify_palette_colors(h, s, uid, check):
    original_groups = h.sql(f'SELECT group_id FROM user_auth_group_members WHERE user_id={uid}').split()
    original_direct = h.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={uid} AND realm_id=5').strip() != '0'
    h.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({uid},5)')
    def fetch(path, fields=None, origin=True, upload=None):
        headers = {'Origin': h.base} if origin else {}
        data = None if fields is None else urlencode(fields).encode()
        if upload is not None:
            boundary = 'palette-test-boundary-984213'
            body = bytearray()
            for key, value in fields.items():
                if key.endswith('[file]'):
                    continue
                body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="palette_color_import[file]"; filename="colors.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode())
            body.extend(upload.encode())
            body.extend(f'\r\n--{boundary}--\r\n'.encode())
            data = bytes(body)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        try:
            response = s.opener.open(Request(h.base + path, data=data, headers=headers))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url
    def form(path):
        status, body, _ = fetch(path)
        check(status == 200, 'form GET ' + path + f' ({status})')
        parser = Inputs(); parser.feed(body)
        return parser.fields
    create = '/app.php/graphing/colors/new'
    fields = form(create) | {'palette_color[name]': '<tag>, "quoted"', 'palette_color[hex]': 'abcdef'}
    check(fetch(create, fields, False)[0] == 422, 'origin required')
    check(fetch(create, {k:v for k,v in fields.items() if not k.endswith('[_token]')})[0] == 422, 'CSRF token required')
    status, body, url = fetch(create, fields)
    check(status == 200 and 'saved=1' in url, 'create succeeds')
    ident = int(url.split('/colors/')[1].split('/')[0])
    check('&lt;tag&gt;' in body and '<tag>' not in body, 'Twig escapes exact names')
    check(h.sql(f'SELECT hex FROM colors WHERE id={ident}').strip() == 'abcdef', 'exact hex handoff')
    before_duplicate = h.sql('SELECT COUNT(*) FROM colors').strip()
    status, body, _ = fetch(create, form(create) | {'palette_color[name]': 'duplicate create', 'palette_color[hex]': 'ABCDEF'})
    check(status == 422 and 'A Color with this hex value already exists.' in body,
          'duplicate hex creation is a known validation failure after rollback')
    check(h.sql('SELECT COUNT(*) FROM colors').strip() == before_duplicate,
          'duplicate hex creation leaves no inserted row')
    edit = f'/app.php/graphing/colors/{ident}/edit'
    status, body, _ = fetch(edit, form(edit) | {'palette_color[name]': 'duplicate edit', 'palette_color[hex]': '000000'})
    check(status == 422 and 'A Color with this hex value already exists.' in body,
          'duplicate hex edit is a known validation failure after rollback')
    check(h.sql(f'SELECT HEX(name),hex FROM colors WHERE id={ident}').strip().lower()
          == '<tag>, "quoted"'.encode().hex() + '\tabcdef',
          'duplicate hex edit preserves the original name and hex')
    stale = form(edit)
    h.sql(f"UPDATE colors SET name='concurrent' WHERE id={ident}")
    check(fetch(edit, stale | {'palette_color[name]': 'stale'})[0] == 409, 'stale edit rejected')
    h.sql(f"UPDATE colors SET name='Custom exact' WHERE id={ident}")
    status, body, _ = fetch('/app.php/graphing/colors?' + urlencode({'named':'false', 'filter': 'Custom exact', 'rows':'1'}))
    check(status == 200 and 'Custom exact' in body, 'search and named filter')
    check('Custom exact' in fetch('/app.php/graphing/colors')[1], 'per-user filter preference')
    attack = 'javascript:alert(1)" onclick="evil()'
    status, body, _ = fetch('/app.php/graphing/colors?' + urlencode({'named':'false', 'filter':attack}))
    parser = Inputs(); parser.feed(body)
    check(status == 200 and all(not link.lower().startswith('javascript:') for link in parser.links) and ' onclick="evil()"' not in body, 'fixed route links encode malicious filter')
    export = '/app.php/graphing/colors/export?named=false&filter=Custom%20exact&rows=1'
    status, text, _ = fetch(export)
    check(status == 200 and list(csv.reader(io.StringIO(text))) == [['name','hex','kadupul_literal_v1'],["'Custom exact","'abcdef",'1']], 'filtered export exact CSV')
    legacy_status, legacy_text, _ = fetch('/color.php?action=export&named=false&filter=Custom%20exact')
    check(legacy_status == 200 and list(csv.reader(io.StringIO(legacy_text))) == [['name','hex','kadupul_literal_v1'],["'Custom exact","'abcdef",'1']], 'legacy export preserves explicit filters')
    unnamed_hex = secrets.token_hex(3)
    while h.sql(f"SELECT COUNT(*) FROM colors WHERE hex='{unnamed_hex}'").strip() != '0':
        unnamed_hex = secrets.token_hex(3)
    status, _, unnamed_url = fetch(create, form(create) | {'palette_color[name]': '', 'palette_color[hex]': unnamed_hex})
    check(status == 200 and 'saved=1' in unnamed_url, 'unnamed palette color creation succeeds')
    unnamed_id = int(unnamed_url.split('/colors/')[1].split('/')[0])
    status, body, _ = fetch('/app.php/graphing/colors?' + urlencode({'named':'false', 'filter':unnamed_hex}))
    labels = PaletteLabels(); labels.feed(body)
    check(status == 200 and labels.labels.get(str(unnamed_id)) == 'Select ' + unnamed_hex
          and any(urlsplit(href).path.endswith(f'/colors/{unnamed_id}/edit') and text == unnamed_hex for href, text in labels.links.items()),
          'unnamed palette color has a visible edit link and accessible hex label')
    importer = '/app.php/graphing/colors/import'
    formula_names = ['=1+1', '+SUM(1,2)', '-1+2', '@SUM(1,2)', ' =1+1', '\t=1+1', '\r=1+1', '\n=1+1', "'original apostrophe"]
    roundtripped_names = []
    for formula_name in formula_names:
        encoded_name = formula_name.encode().hex()
        h.sql(f"UPDATE colors SET name=CONVERT(0x{encoded_name} USING utf8mb4) WHERE id={ident}")
        status, safe_csv, _ = fetch('/app.php/graphing/colors/export?' + urlencode({'named':'false','filter':'abcdef'}))
        check(status == 200 and list(csv.reader(io.StringIO(safe_csv)))
              == [['name','hex','kadupul_literal_v1'],["'" + formula_name,"'abcdef",'1']],
              'palette CSV formula and control prefixes are literal: ' + repr(formula_name))
        h.sql(f"UPDATE colors SET name='temporary roundtrip change' WHERE id={ident}")
        fields = form(importer) | {'palette_color_import[allow_update]':'1'}
        check(fetch(importer, fields, upload=safe_csv)[0] == 200
              and h.sql(f'SELECT HEX(name) FROM colors WHERE id={ident}').strip().lower() == encoded_name,
              'versioned palette export reimports the exact original name: ' + repr(formula_name))
        roundtripped_names.append(h.sql(f'SELECT HEX(name) FROM colors WHERE id={ident}').strip().lower())
    check(roundtripped_names == [name.encode().hex() for name in formula_names],
          'palette exports neutralize formulas and preserve exact versioned roundtrip names')
    h.sql(f"UPDATE colors SET name='Custom exact' WHERE id={ident}")
    for malformed in ['name,hex,kadupul_literal_v2\n\'bad,\'123,1\n',
                      'name,hex,kadupul_literal_v1\n\'bad,\'123,2\n',
                      'name,hex,kadupul_literal_v1\nbad,\'123,1\n']:
        before_marker = h.sql('SELECT id,HEX(name),hex FROM colors ORDER BY id')
        check(fetch(importer, form(importer), upload=malformed)[0] == 422
              and h.sql('SELECT id,HEX(name),hex FROM colors ORDER BY id') == before_marker,
              'unsupported or malformed palette literal marker rejects the whole import')
    raw_name = 'comma, "quote"\nsecond line'
    output = io.StringIO(); writer = csv.writer(output); writer.writerow(['name','hex']); writer.writerow([raw_name,'abc']); writer.writerow(['Builtin forged','000000'])
    f = form(importer); f.pop('palette_color_import[allow_update]', None)
    check(fetch(importer, f, upload=output.getvalue())[0] == 200, 'quoted newline CSV upload succeeds')
    csv_id = int(h.sql("SELECT id FROM colors WHERE hex='abc'").strip())
    check(h.sql(f'SELECT HEX(name) FROM colors WHERE id={csv_id}').strip().lower() == raw_name.encode().hex(), 'CSV exact name data handoff')
    check(h.sql("SELECT name FROM colors WHERE hex='000000'").strip() == 'Black', 'built-in import skipped')
    f = form(importer) | {'palette_color_import[allow_update]':'1'}
    check(fetch(importer, f, upload='name,hex\nupdated,abc\nforged,000000\n')[0] == 200, 'allow-update import succeeds')
    check(h.sql(f'SELECT name FROM colors WHERE id={csv_id}').strip() == 'updated', 'allow-update changes existing custom color')
    check(h.sql("SELECT name FROM colors WHERE hex='000000'").strip() == 'Black', 'allow-update preserves built-in')
    literal_name = "'plain legacy apostrophe"
    legacy_file = io.StringIO(); legacy_writer = csv.writer(legacy_file)
    legacy_writer.writerow(['name','hex']); legacy_writer.writerow([literal_name,'abc'])
    check(fetch(importer, form(importer) | {'palette_color_import[allow_update]':'1'}, upload=legacy_file.getvalue())[0] == 200
          and h.sql(f'SELECT HEX(name) FROM colors WHERE id={csv_id}').strip().lower() == literal_name.encode().hex(),
          'ordinary legacy CSV import preserves its leading apostrophe literally')
    f = form(importer)
    before = h.sql('SELECT COUNT(*) FROM colors').strip()
    check(fetch(importer, f, upload='name,hex\na,123\nb,xyz\n')[0] == 422, 'invalid CSV whole import rejected')
    check(h.sql('SELECT COUNT(*) FROM colors').strip() == before, 'invalid CSV changes no rows')
    f = form(importer)
    h.sql(f"UPDATE colors SET name='changed' WHERE id={csv_id}")
    check(fetch(importer, f, upload='name,hex\na,123\n')[0] == 409, 'stale import snapshot rejected')
    h.sql("CREATE TRIGGER palette_import_failure BEFORE INSERT ON colors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected palette failure'")
    try:
        f = form(importer)
        check(fetch(importer, f, upload='name,hex\nrollback,abc\nnew,123\n')[0] == 502, 'injected DB failure returned honestly')
        check(h.sql(f'SELECT name FROM colors WHERE id={csv_id}').strip() == 'changed', 'transaction rollback restores prior import updates')
    finally:
        h.sql('DROP TRIGGER palette_import_failure')
    f = form(importer)
    h.sql('ALTER TABLE colors ENGINE=MyISAM')
    try:
        check(fetch(importer, f, upload='name,hex\na,123\n')[0] == 502, 'nontransactional palette rejected before write')
        check(h.sql("SELECT COUNT(*) FROM colors WHERE hex='123'").strip() == '0', 'MyISAM rejection leaves database unchanged')
    finally:
        h.sql('ALTER TABLE colors ENGINE=InnoDB')
    sql_probe = h.command('php', 'tests/Symfony/palette_sql_failure_probe.php')
    check(sql_probe['exit'] == 0 and sql_probe['stdout'] == 'PALETTE_SILENT_SQL_OK' and sql_probe['stderr'] == '',
          'silent palette SQL failures preserve rows and refuse false saves imports and dependency deletes')
    guard_probe = h.command('php', '-r', _mariadb_palette_write_guard_probe(uid))
    check(guard_probe['exit'] == 0 and guard_probe['stdout'] == 'PALETTE_WRITE_GUARDS_OK' and guard_probe['stderr'] == '',
          'palette writes refuse actual nontransactional tables, invalid collectors and caller transactions without losing prior work')
    auth_probe = h.command('php', '-r', _mariadb_palette_authorization_probe(uid))
    check(auth_probe['exit'] == 0 and auth_probe['stdout'] == 'PALETTE_CONCURRENT_AUTHORIZATION_OK' and auth_probe['stderr'] == '',
          'two palette actors authorize concurrently while policy, account and realm revokers wait and later denials take effect')
    h.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_default_language','fr')")
    status, body, _ = fetch('/app.php/graphing/colors/import')
    check(status == 200 and 'Importer des couleurs' in body, 'French locale reaches CSV route')
    h.sql("REPLACE INTO settings (name,value) VALUES ('i18n_default_language','en')")
    delete = f'/app.php/graphing/colors/actions/delete?ids[]={ident}'
    f = form(delete)
    ref = int(h.sql(f'INSERT INTO graph_templates_item (color_id, graph_template_id, local_graph_id) VALUES ({ident},0,900001); SELECT LAST_INSERT_ID()').strip())
    check(fetch(delete, f)[0] == 422, 'graph reference added after confirmation blocks deletion')
    h.sql(f'DELETE FROM graph_templates_item WHERE id={ref}')
    h.sql(f'INSERT INTO color_template_items (color_template_id,color_id,sequence) VALUES (999999,{ident},1)')
    check(fetch(delete, f)[0] == 422, 'color template direct reference blocks deletion')
    h.sql(f'DELETE FROM color_template_items WHERE color_template_id=999999')
    f = form(delete)
    h.sql(f"UPDATE colors SET name='delete changed' WHERE id={ident}")
    check(fetch(delete, f)[0] == 409, 'stale deletion rejected')
    check(fetch(delete, form(delete))[0] == 200, 'fresh deletion succeeds')
    builtin = '/app.php/graphing/colors/1/edit'
    f = form(builtin) | {'palette_color[name]':'forged','palette_color[hex]':'999'}
    check(fetch(builtin, f)[0] == 422, 'forged built-in edit rejected')
    check(fetch('/color.php?action=actions', {'action':'actions','selected_items':'a:1:{i:0;i:1;}'})[0] == 409, 'expired legacy POST never runs')
    check(fetch('/color.php?action=import')[0] == 200, 'legacy import links redirect')
    h.sql(f"UPDATE user_auth SET must_change_password='on' WHERE id={uid}")
    try:
        check(fetch('/app.php/graphing/colors')[0] == 403, 'forced password policy rejects read')
    finally:
        h.sql(f"UPDATE user_auth SET must_change_password='' WHERE id={uid}")
    h.sql(f'DELETE FROM user_auth_realm WHERE user_id={uid} AND realm_id=5; DELETE FROM user_auth_group_members WHERE user_id={uid}')
    check(fetch('/app.php/graphing/colors')[0] == 403, 'missing palette realm rejected')
    denied_paths = [('/app.php/graphing/colors?filter[]=bad',None), ('/app.php/graphing/colors/new?rows[]=bad',None),
                    (f'/app.php/graphing/colors/{csv_id}/edit?rows[]=bad',None), ('/app.php/graphing/colors/actions/delete?ids[]=bad',None),
                    ('/app.php/graphing/colors/legacy?action[]=bad',None), ('/app.php/graphing/colors/import?bad[]=x',None),
                    ('/app.php/graphing/colors/export?filter[]=bad',None), ('/app.php/graphing/colors/new',{}),
                    (f'/app.php/graphing/colors/{csv_id}/edit',{}), ('/app.php/graphing/colors/actions/delete',{}),
                    ('/app.php/graphing/colors/import',{}), ('/app.php/graphing/colors/legacy',{})]
    before_denial = h.sql('SELECT id,HEX(name),hex FROM colors ORDER BY id')
    for path, fields in denied_paths:
        check(fetch(path, fields)[0] == 403, 'console-only palette route denied before parsing: ' + path)
    check(h.sql('SELECT id,HEX(name),hex FROM colors ORDER BY id') == before_denial,
          'console-only palette account cannot parse or mutate any route')
    for group in original_groups:
        h.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({int(group)},{uid})')
    if original_direct:
        h.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({uid},5)')


def _mariadb_palette_write_guard_probe(actor_id):
    return '$actorId = ' + str(actor_id) + ';' + r'''require "include/vendor/autoload.php";
$installation = new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(__DIR__);
$config = $installation->values();
$db = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
    $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$connection = new class($db) implements Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private PDO $db) {}
    public function get(): PDO { return $this->db; }
};
$console = new class($actorId) implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public function __construct(private int $id) {}
    public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor { return new Kadupul\IdentityAccess\Contract\Actor($this->id,'guard-fixture'); }
    public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool { return false; }
};
$access = new Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorAccess($console,$connection);
$audit = new class implements Kadupul\IdentityAccess\Contract\AuditTrail {
    public function record(Kadupul\IdentityAccess\Contract\AuditEvent $event): void {}
};
$store = new Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore($connection,$access,$audit,$installation);
function paletteProbeHex(PDO $db): string {
    $query = $db->prepare('SELECT COUNT(*) FROM colors WHERE hex=?');
    do { $hex = bin2hex(random_bytes(3)); $query->execute([$hex]); } while ((int)$query->fetchColumn() !== 0);
    return $hex;
}
$count = (int)$db->query('SELECT COUNT(*) FROM colors')->fetchColumn();
foreach (['colors','graph_templates_item','color_template_items','settings','user_auth','user_auth_realm',
    'user_auth_group','user_auth_group_members','user_auth_group_realm'] as $table) {
    // A minimal shadow proves preflight refuses the actual table before any
    // account, policy or mutation query can use its columns.
    $db->exec('CREATE TEMPORARY TABLE `'.$table.'` (guard_fixture INT) ENGINE=MyISAM');
    try {
        $denied = false;
        try { $store->save($actorId,null,'nontransactional fixture','123',null); }
        catch (RuntimeException $error) { $denied = $error->getMessage() === 'Color writes require transactional tables.'; }
        if (!$denied || $db->inTransaction()) { throw new RuntimeException('The actual '.$table.' table engine was not refused.'); }
    } finally { $db->exec('DROP TEMPORARY TABLE `'.$table.'`'); }
    if ((int)$db->query('SELECT COUNT(*) FROM colors')->fetchColumn() !== $count) { throw new RuntimeException('Temporary engine refusal changed persistent rows.'); }
}
foreach ([2,'1',null] as $collector) {
    $changed = new class($config,$collector) implements Kadupul\Platform\Contract\LegacyConfiguration {
        public function __construct(private array $config,private mixed $collector) {}
        public function values(): array { return array_replace($this->config,['collector_id'=>$this->collector]); }
    };
    $remote = new Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore($connection,$access,$audit,$changed);
    $denied = false;
    try { $remote->save($actorId,null,'remote fixture','123',null); }
    catch (RuntimeException $error) { $denied = $error->getMessage() === 'Colors must be changed on the primary collector.'; }
    if (!$denied || $db->inTransaction() || (int)$db->query('SELECT COUNT(*) FROM colors')->fetchColumn() !== $count) {
        throw new RuntimeException('Remote or invalid collector identity was accepted.');
    }
}
foreach (['pdo','native'] as $owner) {
    $owner === 'pdo' ? $db->beginTransaction() : $db->exec('START TRANSACTION');
    try {
        $db->prepare("INSERT INTO colors(name,hex,read_only) VALUES ('caller owned',?,'')")->execute([paletteProbeHex($db)]);
        $callerId = (int)$db->lastInsertId();
        $denied = false;
        try { $store->save($actorId,null,'nested fixture','123',null); }
        catch (RuntimeException $error) { $denied = $error->getMessage() === 'Color transaction unavailable.'; }
        $query = $db->prepare('SELECT name FROM colors WHERE id=?'); $query->execute([$callerId]);
        if (!$denied || !$db->inTransaction() || $query->fetchColumn() !== 'caller owned'
            || (int)$db->query('SELECT COUNT(*) FROM colors')->fetchColumn() !== $count+1) {
            throw new RuntimeException('Caller transaction ownership or prior work was lost.');
        }
    } finally { $db->rollBack(); }
}
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$hex = paletteProbeHex($db);
$id = $store->save($actorId,null,'owned after read committed',$hex,null);
$query = $db->prepare('SELECT name,hex FROM colors WHERE id=?'); $query->execute([$id]);
if ($db->inTransaction() || $query->fetch(PDO::FETCH_NUM) !== ['owned after read committed',$hex]) {
    throw new RuntimeException('An owned primary write did not commit confirmed values.');
}
$db->prepare('DELETE FROM colors WHERE id=?')->execute([$id]);
if ((int)$db->query('SELECT COUNT(*) FROM colors')->fetchColumn() !== $count) { throw new RuntimeException('Caller rollback or fixture cleanup changed prior rows.'); }
echo 'PALETTE_WRITE_GUARDS_OK';'''


def _mariadb_palette_authorization_probe(actor_id):
    return '$firstActor = ' + str(actor_id) + ';' + r'''require "include/vendor/autoload.php";
$installation = new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(__DIR__);
$config = $installation->values();
$connect = static fn():PDO => new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
    $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$control = $connect();
$policy = $control->query("SELECT value FROM settings WHERE name='auth_method'")->fetchColumn();
$control->prepare("INSERT INTO user_auth(username,enabled,locked,must_change_password) VALUES (?,'on','','')")
    ->execute(['palette-concurrent-'.bin2hex(random_bytes(6))]);
$secondActor = (int)$control->lastInsertId();
$control->prepare('INSERT INTO user_auth_realm(user_id,realm_id) VALUES (?,8),(?,5)')->execute([$secondActor,$secondActor]);
$first = $connect(); $second = $connect(); $revoker = $connect();
$revoker->exec('SET SESSION innodb_lock_wait_timeout=1');
$accesses = [];
try {
    foreach ([[$first,$firstActor],[$second,$secondActor]] as [$db,$actor]) {
        $db->beginTransaction();
        // Both actors already hold the shared policy lock acquired by
        // LegacyAuthenticatedSession before the feature adapter rechecks them.
        $db->query("SELECT value FROM settings WHERE name='auth_method' LOCK IN SHARE MODE")->fetchColumn();
        $connection = new class($db) implements Kadupul\Platform\Contract\DatabaseConnection {
            public function __construct(private PDO $db) {}
            public function get(): PDO { return $this->db; }
        };
        $console = new class($db,$actor) implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
            public function __construct(private PDO $db,private int $actor) {}
            public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor {
                $this->db->query("SELECT value FROM settings WHERE name='auth_method' LOCK IN SHARE MODE")->fetchColumn();
                return new Kadupul\IdentityAccess\Contract\Actor($this->actor,'concurrent-fixture');
            }
            public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool { return false; }
        };
        $accesses[] = new Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorAccess($console,$connection);
    }
    // An exclusive upgrade here would block against the other actor's
    // existing shared policy read. Both real feature checks must complete.
    $first->exec('SET SESSION innodb_lock_wait_timeout=1');
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $accesses[0]->assertCurrent($firstActor);
    $accesses[1]->assertCurrent($secondActor);
    if (!$first->inTransaction() || !$second->inTransaction()) { throw new RuntimeException('Concurrent authorization lost transaction ownership.'); }
    foreach (["UPDATE settings SET value='0' WHERE name='auth_method'",
        "UPDATE user_auth SET enabled='' WHERE id=".$firstActor,
        'DELETE FROM user_auth_realm WHERE user_id='.$secondActor.' AND realm_id=5'] as $statement) {
        $blocked = false;
        try { $revoker->exec($statement); }
        catch (PDOException $error) { $blocked = ($error->errorInfo[1] ?? null) === 1205; }
        if (!$blocked) { throw new RuntimeException('A policy, account or realm revoker bypassed the authorization lock.'); }
    }
    $first->rollBack(); $second->rollBack();
    $revoker->exec("UPDATE settings SET value='0' WHERE name='auth_method'");
    $denied = false;
    try { $accesses[0]->authorize(); }
    catch (Kadupul\Graphing\Application\Query\PaletteColorAccessDenied) { $denied = true; }
    if (!$denied) { throw new RuntimeException('Committed policy revocation was ignored.'); }
    $control->prepare("UPDATE settings SET value=? WHERE name='auth_method'")->execute([$policy]);
    $revoker->exec("UPDATE user_auth SET enabled='' WHERE id=".$firstActor);
    $denied = false;
    try { $accesses[0]->authorize(); }
    catch (Kadupul\Graphing\Application\Query\PaletteColorAccessDenied) { $denied = true; }
    if (!$denied) { throw new RuntimeException('Committed account revocation was ignored.'); }
    $control->exec("UPDATE user_auth SET enabled='on' WHERE id=".$firstActor);
    $revoker->exec('DELETE FROM user_auth_realm WHERE user_id='.$secondActor.' AND realm_id=5');
    $denied = false;
    try { $accesses[1]->authorize(); }
    catch (Kadupul\Graphing\Application\Query\PaletteColorAccessDenied) { $denied = true; }
    if (!$denied) { throw new RuntimeException('Committed realm revocation was ignored.'); }
} finally {
    foreach ([$first,$second] as $db) { if ($db->inTransaction()) { $db->rollBack(); } }
    $control->prepare("UPDATE settings SET value=? WHERE name='auth_method'")->execute([$policy]);
    $control->exec("UPDATE user_auth SET enabled='on' WHERE id=".$firstActor);
    $control->prepare('DELETE FROM user_auth_realm WHERE user_id=?')->execute([$secondActor]);
    $control->prepare('DELETE FROM user_auth WHERE id=?')->execute([$secondActor]);
}
echo 'PALETTE_CONCURRENT_AUTHORIZATION_OK';'''
