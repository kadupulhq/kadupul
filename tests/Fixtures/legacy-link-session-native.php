<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real viewer, realm cache, sessions, cookie parser and SQL-backed link writer.
// Bootstrap/request/log/footer transport is isolated; authorization is not stubbed.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
[$script, $scenario, $directory, $storage, $stage] = $argv;
require $root . '/include/vendor/autoload.php';
require $root . '/tests/Helpers/PhpSource.php';
$coverageSources = ['composer.lock', 'tests/Helpers/PhpSource.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'include/session.php', 'lib/functions.php', 'lib/auth.php', 'link.php', 'src/Navigation/Domain/ExternalLink.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkAccess.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyAuthenticatedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/ReadOnlyDatabaseSessionHandler.php'];
if (($argv[5] ?? '') === 'coverage') {
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/legacy-link-session-native.php', "$scenario:$storage:$stage", $coverageSources);
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach ($coverageSources as $source) {
        if (str_starts_with($source, 'src/') || in_array($source, ['lib/auth.php', 'link.php'], true)) {
            $filter->includeFile($root . '/' . $source);
        }
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $coverage->start('native link session');
    register_shutdown_function(static function () use ($coverage, $directory, $snapshot, $root, $stage) {
        register_shutdown_function(static function () use ($coverage, $directory, $snapshot, $root, $stage) {
            $coverage->stop();
            $report = "$directory/$stage.coverage";
            if (file_put_contents($report, serialize($coverage)) === false || !isset($GLOBALS['completed'])) {
                throw new RuntimeException('Native link scenario did not complete.');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, ['persisted-link-state-observed']);
        });
    });
}
require $root . '/lib/auth.php';
$functions = file_get_contents($root . '/lib/functions.php');
if ($functions === false) {
    throw new RuntimeException('Missing native session source.');
}
foreach (['cacti_session_start', 'cacti_session_regenerate', 'cacti_session_destroy', 'cacti_cookie_logout', 'cacti_cookie_session_logout', 'general_header', 'top_header', 'kill_session_var'] as $name) {
    eval(test_php_function_source($functions, $name));
}
$db = new PDO(getenv('KADUPUL_LINK_TEST_DSN') ?: 'sqlite:' . $directory . '/state.sqlite', getenv('KADUPUL_LINK_TEST_USER') ?: null, getenv('KADUPUL_LINK_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
}
$config = ['base_path' => $root, 'url_path' => '/', 'cacti_db_version' => '1.2.32', 'cacti_session_name' => 'Cacti', 'cookie_options' => []];
$queries = 0;
function db_fetch_row_prepared($sql, $parameters = [])
{
    if (str_contains($sql, 'FROM external_links')) {
        $GLOBALS['queries']++;
    }
    if ($GLOBALS['scenario'] === 'query-failure' && str_starts_with($sql, 'SELECT enabled, locked, password')) {
        try {
            $GLOBALS['db']->query('SELECT missing_policy_column FROM user_auth');
        } catch (PDOException) {
            return false;
        }
        throw new RuntimeException('Native invalid policy query unexpectedly succeeded.');
    }
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($parameters);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_cell_prepared($sql, $parameters = [])
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($parameters);
    return $q->fetchColumn();
}
function db_execute_prepared($sql, $parameters = [])
{
    if ($GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql = str_replace(['UPDATE IGNORE', 'INSERT IGNORE'], ['UPDATE', 'INSERT OR IGNORE'], $sql);
    }
    if ($GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
        $sql = substr($sql, 0, strpos($sql, 'ON DUPLICATE KEY UPDATE')) . 'ON CONFLICT(id) DO UPDATE SET data=excluded.data,access=excluded.access,user_agent=excluded.user_agent,transactions=transactions+1';
    }
    return $GLOBALS['db']->prepare($sql)->execute($parameters);
}
function db_table_exists($name)
{
    return $GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? db_fetch_cell_prepared("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$name]) !== false
        : $GLOBALS['db']->query('SHOW TABLES LIKE ' . $GLOBALS['db']->quote($name))->fetchColumn() !== false;
}
function db_column_exists($table, $column)
{
    return $GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
        : $GLOBALS['db']->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $GLOBALS['db']->quote($column))->fetchColumn() !== false;
}
function read_config_option($name)
{
    return ['auth_method' => $GLOBALS['scenario'] === 'auth-disabled' ? 0 : 1, 'guest_user' => 0, 'auth_cache_enabled' => 'on'][$name] ?? '';
}
function get_guest_account()
{
    return $GLOBALS['scenario'] === 'disabled-guest' ? 9 : 0;
}
function get_client_addr()
{
    return '127.0.0.1';
}
function cacti_log(...$args) {}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function cacti_version_compare($a, $b, $op)
{
    return version_compare($a, $b, $op);
}
function get_request_var($name)
{
    return $_REQUEST[$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function get_filter_request_var($name)
{
    return filter_var(get_request_var($name), FILTER_VALIDATE_INT);
}
function isset_request_var($name)
{
    return isset($_REQUEST[$name]);
}
function html_escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function raise_message($id)
{
    $GLOBALS['message'] = $id;
}
function bottom_footer()
{
    session_write_close();
}
$connection = new class ($db) implements Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private PDO $db) {}
    public function get(): PDO
    {
        return $this->db;
    }
};
$configuration = new class ($root, $storage) implements Kadupul\Platform\Contract\LegacyConfiguration {
    public function __construct(private string $root, private string $storage) {}
    public function values(): array
    {
        return ['root' => $this->root, 'session_name' => 'Cacti', 'database_sessions' => $this->storage === 'database', 'url_path' => '/', 'cookie_domain' => '', 'collector_id' => 1];
    }
};
session_name('Cacti');
session_save_path($directory);
if ($storage === 'database') {
    $sessionSource = file_get_contents($root . '/include/session.php');
    if ($sessionSource === false) {
        throw new RuntimeException('Missing native database session source.');
    }
    foreach (['cacti_db_session_check', 'cacti_db_session_open', 'cacti_db_session_close', 'cacti_db_session_read', 'cacti_db_session_write', 'cacti_db_session_destroy', 'cacti_db_session_clean'] as $name) {
        eval(test_php_function_source($sessionSource, $name));
    }
    session_set_save_handler(new class implements SessionHandlerInterface {
        public function open(string $path, string $name): bool
        {
            return cacti_db_session_open($path, $name);
        }
        public function close(): bool
        {
            return cacti_db_session_close();
        }
        public function read(string $id): string|false
        {
            return cacti_db_session_read($id);
        }
        public function write(string $id, string $data): bool
        {
            return cacti_db_session_write($id, $data);
        }
        public function destroy(string $id): bool
        {
            return cacti_db_session_destroy($id);
        }
        public function gc(int $lifetime): int|false
        {
            cacti_db_session_clean($lifetime);
            return 0;
        }
    }, true);
}
$cookie = '9,0,' . str_repeat('a', 64);
if ($stage === 'init') {
    $schema = "CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password TEXT,realm INTEGER,reset_perms INTEGER NOT NULL DEFAULT 0 CHECK(reset_perms BETWEEN 0 AND 4294967295));
        INSERT INTO user_auth VALUES(9,'viewer','on','','','current-hash',0,0);
        CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT); INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
        CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
        CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER,PRIMARY KEY(user_id,realm_id));
        CREATE TABLE user_auth_group(id INTEGER,enabled TEXT); CREATE TABLE user_auth_group_realm(group_id INTEGER,realm_id INTEGER); CREATE TABLE user_auth_group_members(user_id INTEGER,group_id INTEGER);
        CREATE TABLE external_links(id INTEGER PRIMARY KEY AUTOINCREMENT,sortorder INTEGER,title TEXT,contentfile TEXT,style TEXT,extendedstyle TEXT,enabled TEXT,refresh INTEGER);
        INSERT INTO external_links VALUES(1,1,'Native viewer','https://example.com/native-viewer','TAB','','on',0);
        CREATE TABLE sessions(id TEXT PRIMARY KEY,data TEXT,access INTEGER,user_id INTEGER,remote_addr TEXT,user_agent TEXT,transactions INTEGER DEFAULT 1);
        CREATE TABLE user_auth_cache(user_id INTEGER,hostname TEXT,last_update TEXT,token TEXT);";
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $schema = str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT', 'name TEXT', 'id TEXT PRIMARY KEY', 'reset_perms INTEGER NOT NULL'], ['INTEGER PRIMARY KEY AUTO_INCREMENT', 'name VARCHAR(255)', 'id VARCHAR(256) PRIMARY KEY', 'reset_perms INTEGER UNSIGNED NOT NULL'], $schema);
    }
    $db->exec($schema);
    foreach ([[9,hash('sha512', str_repeat('a', 64))], [9,hash('sha512', 'other-client')], [10,hash('sha512', str_repeat('a', 64))]] as [$user,$token]) {
        $db->prepare("INSERT INTO user_auth_cache VALUES(?,'127.0.0.1',CURRENT_TIMESTAMP,?)")->execute([$user,$token]);
    }
    session_start();
    file_put_contents($directory . '/id', session_id());
    $_SESSION = ['cacti_cwd' => $root, 'sess_user_id' => 9, 'sess_user_perms_key' => 0, 'sess_user_realms' => [10001 => !str_starts_with($scenario, 'grant')]];
    if (!str_starts_with($scenario, 'unbound')) {
        $_SESSION['sess_user_credential'] = auth_session_credential_key($scenario === 'stale' ? 'old-hash' : 'current-hash');
    }
    $_SESSION['sess_remember_token'] = ['user_id' => 9, 'hash' => hash('sha512', str_repeat('a', 64))];
    if (str_starts_with($scenario, 'grant')) {
        $db->exec('INSERT INTO user_auth_realm VALUES(9,8),(9,15)');
        if ($scenario === 'grant-wrap') {
            $db->exec('UPDATE user_auth SET reset_perms=4294967295');
            $_SESSION['sess_user_perms_key'] = 4294967295;
        }
    } else {
        $db->exec('INSERT INTO user_auth_realm VALUES(9,10001)');
    }
    if (in_array($scenario, ['disabled', 'disabled-guest'], true)) {
        $db->exec("UPDATE user_auth SET enabled=''");
    }
    if ($scenario === 'missing') {
        $db->exec('DELETE FROM user_auth');
    }
    if (in_array($scenario, ['anonymous', 'auth-disabled'], true)) {
        unset($_SESSION['sess_user_id'], $_SESSION['sess_user_credential'], $_SESSION['sess_user_realms'], $_SESSION['sess_remember_token']);
    }
    if ($scenario === 'locked') {
        $db->exec("UPDATE user_auth SET locked='on'");
    }
    session_write_close();
    fwrite(STDOUT, '{}');
    exit;
}
$id = file_get_contents($directory . '/id');
$_COOKIE = ['Cacti' => $id, 'cacti_remembers' => $cookie];
if ($scenario === 'unbound-missing-cookie') {
    unset($_COOKIE['cacti_remembers']);
} elseif ($scenario === 'unbound-malformed-cookie') {
    $_COOKIE['cacti_remembers'] = ['malformed'];
}
if ($stage === 'save') {
    $requests = new Symfony\Component\HttpFoundation\RequestStack();
    $requests->push(Symfony\Component\HttpFoundation\Request::create('/links', cookies: $_COOKIE));
    $shared = new Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession($requests, $configuration, new Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler($connection), $connection);
    $access = new Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkAccess(new Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession($shared, $connection), $connection);
    $audit = new class implements Kadupul\IdentityAccess\Contract\AuditTrail {
        public function record(Kadupul\IdentityAccess\Contract\AuditEvent $event): void {}
    };
    $store = new Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkStore($connection, $access, $audit, $configuration, $root);
    if ($scenario === 'grant-rollback') {
        $db->exec($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "CREATE TRIGGER reject_permission_epoch BEFORE UPDATE OF reset_perms ON user_auth BEGIN SELECT RAISE(ABORT,'epoch-write-rejected'); END"
            : "CREATE TRIGGER reject_permission_epoch BEFORE UPDATE ON user_auth FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='epoch-write-rejected'");
    }
    if ($scenario === 'grant-coerce') {
        $db->exec($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "CREATE TRIGGER coerce_permission_epoch AFTER UPDATE OF reset_perms ON user_auth WHEN NEW.reset_perms != OLD.reset_perms BEGIN UPDATE user_auth SET reset_perms=OLD.reset_perms WHERE id=NEW.id; END"
            : 'CREATE TRIGGER coerce_permission_epoch BEFORE UPDATE ON user_auth FOR EACH ROW SET NEW.reset_perms=OLD.reset_perms');
    }
    $failed = false;
    try {
        $store->save(9, 1, ['title' => 'Saved viewer', 'style' => 'TAB', 'filename' => '0', 'fileurl' => 'https://example.com/native-viewer', 'consolesection' => '', 'consolenewsection' => '', 'refresh' => 0, 'enabled' => true], $store->snapshot()['revision']);
    } catch (PDOException|RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'epoch-write-rejected') && !str_contains($error->getMessage(), 'Link permission invalidation')) {
            throw $error;
        }
        $failed = true;
    }
    $GLOBALS['completed'] = true;
    fwrite(STDOUT, json_encode(['epoch' => $db->query('SELECT reset_perms FROM user_auth WHERE id=9')->fetchColumn(), 'grant' => $db->query('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id=10001')->fetchColumn(), 'title' => $db->query('SELECT title FROM external_links WHERE id=1')->fetchColumn(), 'failed' => $failed, 'transaction' => $db->inTransaction()], JSON_THROW_ON_ERROR));
    exit;
}
session_id($id);
session_start();
$_REQUEST = ['id' => 1, 'header' => 'false'];
if (!is_dir($directory . '/include')) {
    mkdir($directory . '/include', 0700);
}
file_put_contents($directory . '/include/global.php', '<?php // Native bootstrap already loaded.');
chdir($directory);
ob_start();
register_shutdown_function(static function () use ($directory, $id, $cookie, $db, $storage) {
    $output = ob_get_clean();
    $actor = $_SESSION['sess_user_id'] ?? null;
    session_write_close();
    $revoked = $storage === 'database' ? db_fetch_cell_prepared('SELECT COUNT(*) FROM sessions WHERE id=?', [$id]) === 0 : !is_file($directory . '/sess_' . $id);
    // Query the real stored token before cookie replay; accepted cookies rotate.
    $remaining = $db->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn();
    $replay = null;
    if (($GLOBALS['scenario'] === 'stale' || str_starts_with($GLOBALS['scenario'], 'unbound')) && $remaining === 2) {
        $_COOKIE['cacti_remembers'] = $cookie;
        $replay = check_auth_cookie();
    }
    $GLOBALS['completed'] = true;
    fwrite(STDOUT, json_encode(['status' => http_response_code() ?: 200, 'output' => $output, 'actor' => $actor, 'protected_queries' => $GLOBALS['queries'], 'revoked' => $revoked, 'remaining' => $remaining, 'replay' => $replay, 'message' => $GLOBALS['message'] ?? null], JSON_THROW_ON_ERROR));
});
require $root . '/link.php';
