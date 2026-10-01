<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Kernel;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
if (($argv[4] ?? '') === 'coverage') {
    define('SYMFONY_SESSION_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
$scenario = $argv[1];
$directory = $argv[2];
$databaseSessions = $argv[3] === 'database';
$remembered = str_starts_with($scenario, 'unbound-remembered');
$rollback = str_ends_with($scenario, '-rollback');
$dsn = getenv('KADUPUL_SESSION_TEST_DSN') ?: 'sqlite:' . $directory . '/credentials.sqlite';
class RehashInterleavingPdo extends PDO
{
    public ?Closure $interleave = null;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->interleave !== null && str_starts_with($query, 'SELECT value FROM settings_user')) {
            $callback = $this->interleave;
            $this->interleave = null;
            $callback();
        }
        return parent::prepare($query, $options);
    }
}
class CompletedAccountRead extends PDOStatement
{
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        // Complete SQLite's one-row cursor so a committed rival rehash becomes
        // visible between statements, as it does with MySQL buffered reads.
        if (str_starts_with($this->queryString, 'SELECT id, username')) {
            $this->closeCursor();
        }
        return $row;
    }
}
$db = new RehashInterleavingPdo($dsn, getenv('KADUPUL_SESSION_TEST_USER') ?: null, getenv('KADUPUL_SESSION_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec('PRAGMA journal_mode=WAL');
    $db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CompletedAccountRead::class]);
}
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password TEXT,realm INTEGER);
    INSERT INTO user_auth VALUES(9,'operator','on','','','old-password-hash',0);
    CREATE TABLE settings(name TEXT,value TEXT); INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
    CREATE TABLE settings_user(user_id INTEGER,name VARCHAR(255),value TEXT,PRIMARY KEY(user_id,name));
    CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER); INSERT INTO user_auth_realm VALUES(9,8),(9,15);
    CREATE TABLE sessions(id VARCHAR(256) PRIMARY KEY,data TEXT,access INTEGER);
    CREATE TABLE user_auth_cache(user_id INTEGER,hostname TEXT,last_update TEXT,token TEXT);
    CREATE TABLE user_log(username TEXT,user_id INTEGER,result INTEGER,ip TEXT,time TEXT)");
$connection = new class ($db) implements DatabaseConnection {
    public function __construct(private PDO $db) {}
    public function get(): PDO
    {
        return $this->db;
    }
};
$configuration = new class ($root, $databaseSessions) implements LegacyConfiguration {
    public function __construct(private string $root, private bool $databaseSessions) {}
    public function values(): array
    {
        return ['root' => $this->root, 'session_name' => 'Cacti', 'database_sessions' => $this->databaseSessions, 'url_path' => '/', 'cookie_domain' => ''];
    }
};
session_save_path($directory);
session_name('Cacti');
session_start();
$_SESSION = ['cacti_cwd' => $root, 'sess_user_id' => 9];
if (!str_starts_with($scenario, 'unbound')) {
    $_SESSION['sess_user_credential'] = auth_session_credential_key('old-password-hash');
}
if (str_contains($scenario, '-marker-')) {
    $_SESSION['sess_remember_token'] = ['user_id' => 9, 'hash' => hash('sha512', str_repeat('a', 64))];
}
$id = session_id();
$payload = session_encode();
session_write_close();
if ($databaseSessions) {
    $db->prepare('INSERT INTO sessions VALUES(?,?,?)')->execute([$id, $payload, time()]);
}
$_COOKIE = ['Cacti' => $id];
if ($remembered) {
    $rawToken = str_repeat('a', 64);
    $cookie = ($scenario === 'unbound-remembered-name' ? 'operator,0,' : '9,0,') . $rawToken;
    if (!str_contains($scenario, '-marker-missing')) {
        $_COOKIE['cacti_remembers'] = str_contains($scenario, '-marker-malformed') ? ['malformed'] : $cookie;
    }
    foreach ([[9,hash('sha512', $rawToken)], [9,hash('sha512', 'other-client')], [10,hash('sha512', $rawToken)]] as [$user,$token]) {
        $db->prepare("INSERT INTO user_auth_cache VALUES(?,'127.0.0.1',CURRENT_TIMESTAMP,?)")->execute([$user,$token]);
    }
}
function db_table_exists($name)
{
    return $name === 'user_auth_cache';
}
function db_fetch_row_prepared($sql, $parameters = [])
{
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
        $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
    }
    return $GLOBALS['db']->prepare($sql)->execute($parameters);
}
function read_config_option($name)
{
    return $name === 'auth_cache_enabled' ? 'on' : '';
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_guest_account()
{
    return 0;
}
function get_client_addr()
{
    return '127.0.0.1';
}
function cacti_log(...$args) {}
function cacti_cookie_session_logout()
{
    unset($_COOKIE['cacti_remembers']);
}
function cacti_cookie_session_set(...$args) {}
$requests = new RequestStack();
$requests->push(Request::create('/links', cookies: $_COOKIE));
$session = new SharedSession($requests, $configuration, new ReadOnlyDatabaseSessionHandler($connection), $connection);
$console = new LegacyAuthenticatedSession($session, $connection);
$access = new LegacyLinkAccess($console, $connection);
$initial = null;
if (in_array($scenario, ['reset-write', 'rehash-write'], true)) {
    $initial = $access->authorize()->id;
}
if (str_starts_with($scenario, 'reset')) {
    $db->exec("UPDATE user_auth SET password='replacement-password-hash' WHERE id=9");
}
if (str_starts_with($scenario, 'rehash')) {
    if (!auth_rehash_password_preserving_sessions(9, 'old-password-hash', 'upgraded-password-hash', $db)) {
        throw new RuntimeException('Actual transparent hash upgrade failed.');
    }
}
if ($scenario === 'successive-rehash') {
    if (!auth_rehash_password_preserving_sessions(9, 'old-password-hash', 'first-upgraded-hash', $db)) {
        throw new RuntimeException('First actual transparent hash upgrade failed.');
    }
    $writer = new PDO($dsn, getenv('KADUPUL_SESSION_TEST_USER') ?: null, getenv('KADUPUL_SESSION_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->interleave = static function () use ($writer) {
        if (!auth_rehash_password_preserving_sessions(9, 'first-upgraded-hash', 'second-upgraded-hash', $writer)) {
            throw new RuntimeException('Interleaved actual transparent hash upgrade failed.');
        }
    };
}
$accepted = false;
$unauthenticated = false;
if (str_ends_with($scenario, '-write') || $rollback) {
    $db->beginTransaction();
    if ($rollback) {
        $db->exec("INSERT INTO settings VALUES('caller_owned_work','pending')");
    }
}
$refusedWhileActive = null;
try {
    if ($db->inTransaction()) {
        $access->assertCurrent(9);
        $accepted = true;
    } else {
        $accepted = $access->authorize()->id === 9;
    }
} catch (LinkAccessDenied $error) {
    $unauthenticated = $error->unauthenticated;
    if ($rollback && method_exists($session, 'completeRevocation')) {
        try {
            $session->completeRevocation();
            $refusedWhileActive = false;
        } catch (RuntimeException) {
            $refusedWhileActive = $db->inTransaction()
                && $db->query("SELECT COUNT(*) FROM settings WHERE name='caller_owned_work'")->fetchColumn() === 1
                && $db->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn() === 3;
        }
    }
} finally {
    if ($db->inTransaction()) {
        $rollback ? $db->rollBack() : $db->commit();
    }
}
if (($rollback || str_ends_with($scenario, '-write')) && method_exists($session, 'completeRevocation')) {
    if ($rollback && $db->query("SELECT COUNT(*) FROM settings WHERE name='caller_owned_work'")->fetchColumn() !== 0) {
        throw new RuntimeException('Caller rollback was not preserved.');
    }
    // Execute the real registered kernel.response subscriber. No direct
    // completion call is made after rollback; the HTTP response owns cleanup.
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $kernel->getContainer()->get('test.service_container')->set(SharedSession::class, $session);
    $http = Request::create('/fixture-deferred-revocation', cookies: $_COOKIE);
    $http->attributes->set('_controller', static fn() => new Response($accepted ? 'Accepted' : 'Denied', $accepted ? 200 : 403));
    $response = $kernel->handle($http);
    if ($response->getStatusCode() !== ($accepted ? 200 : 403)) {
        throw new RuntimeException('Actual denial response changed.');
    }
    $kernel->shutdown();
    $responseDispatched = true;
}
$revoked = $databaseSessions ? $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 0 : !is_file($directory . '/sess_' . $id);
$transition = null;
if ($remembered) {
    $cleared = !isset($_COOKIE['cacti_remembers']) && !$requests->getCurrentRequest()->cookies->has('cacti_remembers');
    // Even replaying the original cookie on the following legacy request must
    // fail because the persisted token, not merely its request value, was revoked.
    $_COOKIE['cacti_remembers'] = $cookie;
    $legacy = check_auth_cookie();
    $transition = ['cookie_cleared' => $cleared, 'legacy' => $legacy, 'remaining' => $db->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn()];
}
define('SYMFONY_SESSION_NATIVE_COMPLETED', isset($responseDispatched) ? ['session-state-observed', 'response-dispatched'] : ['session-state-observed']);
fwrite(STDOUT, json_encode(['accepted' => $accepted, 'unauthenticated' => $unauthenticated, 'initial' => $initial, 'revoked' => $revoked, 'transition' => $transition, 'refused_while_active' => $refusedWhileActive], JSON_THROW_ON_ERROR));
