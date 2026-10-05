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
$about = str_starts_with($scenario, 'about-');
$credentialScenario = $about ? substr($scenario, 6) : $scenario;
$restore = str_starts_with($credentialScenario, 'restore-');
$policyCase = str_contains($credentialScenario, '-policy-');
$databaseSessions = $argv[3] === 'database';
$remembered = str_starts_with($scenario, 'unbound-remembered');
$rollback = str_ends_with($scenario, '-rollback');
$disabledRollback = $scenario === 'disabled-rollback';
$dsn = getenv('KADUPUL_SESSION_TEST_DSN') ?: 'sqlite:' . $directory . '/credentials.sqlite';
class RehashInterleavingPdo extends PDO
{
    public ?Closure $interleave = null;
    public int $generationReads = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT value FROM settings_user')) {
            $this->generationReads++;
        }
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
$database_hostname = 'native';
$database_port = '0';
$database_default = 'credential-fixture';
$database_sessions = ['native:0:credential-fixture' => $db];
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec('PRAGMA journal_mode=WAL');
    $db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CompletedAccountRead::class]);
}
$cacheIdentity = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INTEGER AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY';
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password TEXT,realm INTEGER,password_change TEXT);
    INSERT INTO user_auth VALUES(9,'operator','on','','','old-password-hash',0,'');
    CREATE TABLE settings(name TEXT,value TEXT); INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
    CREATE TABLE settings_user(user_id INTEGER,name VARCHAR(255),value TEXT,PRIMARY KEY(user_id,name));
    CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER); INSERT INTO user_auth_realm VALUES(9,8),(9,15);
    CREATE TABLE sessions(id VARCHAR(256) PRIMARY KEY,data TEXT,access INTEGER,remote_addr TEXT,user_id INTEGER,user_agent TEXT);
    CREATE TABLE user_auth_cache(id $cacheIdentity,user_id INTEGER,hostname TEXT,last_update TEXT,token TEXT);
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
if (!str_starts_with($credentialScenario, 'unbound') && !$restore) {
    $_SESSION['sess_user_credential'] = auth_session_credential_key('old-password-hash');
}
if ($credentialScenario === 'empty') {
    $_SESSION['sess_user_credential'] = '';
} elseif ($credentialScenario === 'malformed') {
    $_SESSION['sess_user_credential'] = array('not-a-generation');
}
if (str_contains($scenario, '-marker-')) {
    $_SESSION['sess_remember_token'] = ['user_id' => 9, 'hash' => hash('sha512', str_repeat('a', 64))];
}
if ($restore) {
    unset($_SESSION['sess_user_id']);
}
$id = session_id();
$payload = session_encode();
session_write_close();
if ($databaseSessions) {
    $db->prepare('INSERT INTO sessions(id,data,access) VALUES(?,?,?)')->execute([$id, $payload, time()]);
}
$_COOKIE = ['Cacti' => $id];
if ($remembered) {
    $rawToken = str_repeat('a', 64);
    $cookie = ($scenario === 'unbound-remembered-name' ? 'operator,0,' : '9,0,') . $rawToken;
    if (!str_contains($scenario, '-marker-missing')) {
        $_COOKIE['cacti_remembers'] = str_contains($scenario, '-marker-malformed') ? ['malformed'] : $cookie;
    }
    foreach ([[9,hash('sha512', $rawToken)], [9,hash('sha512', 'other-client')], [10,hash('sha512', $rawToken)]] as [$user,$token]) {
        $db->prepare("INSERT INTO user_auth_cache(user_id,hostname,last_update,token) VALUES(?,'127.0.0.1',CURRENT_TIMESTAMP,?)")->execute([$user,$token]);
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
if (str_starts_with($credentialScenario, 'reset')) {
    $db->exec("UPDATE user_auth SET password='replacement-password-hash' WHERE id=9");
}
if (str_starts_with($credentialScenario, 'rehash')) {
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
if ($credentialScenario === 'deleted-live') {
    $db->prepare("INSERT INTO settings_user VALUES(9,'auth_credential_generation',?)")->execute([str_repeat('a', 64) . ':' . str_repeat('b', 64)]);
    $db->interleave = static function () use ($db) {
        $db->exec('DELETE FROM user_auth WHERE id=9');
    };
}
$accepted = false;
$unauthenticated = false;
$resumeWhileActiveDenied = null;
$resumeAfterRollbackDenied = null;
if ($disabledRollback) {
    $db->exec("UPDATE user_auth SET enabled='' WHERE id=9");
}
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
        if ($about) {
            if ($restore) {
                $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
                if (str_starts_with($credentialScenario, 'restore-basic')) {
                    $db->exec("UPDATE user_auth SET realm=2 WHERE id=9");
                    $db->exec("UPDATE settings SET value='2' WHERE name='auth_method'");
                    $_SERVER['REMOTE_USER'] = $_SERVER['PHP_AUTH_USER'] = 'operator';
                } else {
                    $db->exec("INSERT INTO settings VALUES('auth_cache_enabled','on')");
                    $db->prepare("INSERT INTO user_auth_cache(user_id,hostname,last_update,token) VALUES(9,'127.0.0.1',CURRENT_TIMESTAMP,?)")->execute([hash('sha512', 'fixture-remembered-token')]);
                    $_COOKIE['cacti_remembers'] = '9,0,fixture-remembered-token';
                }
                if ($policyCase) {
                    $db->exec("UPDATE user_auth SET must_change_password='on',password_change='on' WHERE id=9");
                    if (str_ends_with($credentialScenario, '-must-off')) {
                        $db->exec("UPDATE user_auth SET must_change_password='' WHERE id=9");
                    } elseif (str_ends_with($credentialScenario, '-change-off')) {
                        $db->exec("UPDATE user_auth SET password_change='' WHERE id=9");
                    } elseif (str_ends_with($credentialScenario, '-nonlocal')) {
                        $db->exec("UPDATE user_auth SET realm=1 WHERE id=9");
                        $_COOKIE['cacti_remembers'] = '9,1,fixture-remembered-token';
                    }
                    $policyBefore = ['generation_reads' => $db->generationReads, 'file_payload_sha256' => is_file($directory . '/sess_' . $id) ? hash_file('sha256', $directory . '/sess_' . $id) : null, 'session_id' => $id, 'sessions' => $db->query('SELECT * FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'tokens' => $db->query('SELECT * FROM user_auth_cache ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'logs' => $db->query('SELECT * FROM user_log')->fetchAll(PDO::FETCH_ASSOC)];
                }
                $requests->pop();
                $request = Request::create('/about', cookies: $_COOKIE, server: $_SERVER);
                $request->attributes->set('_route', 'platform_about');
                $requests->push($request);
            }
            $audit = new class implements \Kadupul\IdentityAccess\Contract\AuditTrail {
                public function record(\Kadupul\IdentityAccess\Contract\AuditEvent $event): void {}
            };
            $browser = new \Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyBrowserAuthentication($requests, $connection, $configuration, new \Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession($configuration, $connection), $audit);
            $actor = (new \Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAboutAccess($session, $browser))->authenticatedActor();
            $accepted = $actor?->id === 9;
            $unauthenticated = !$accepted;
            if ($policyCase) {
                $policyAfter = ['generation_reads' => $db->generationReads, 'file_payload_sha256' => is_file($directory . '/sess_' . $id) ? hash_file('sha256', $directory . '/sess_' . $id) : null, 'session_id' => session_id(), 'sessions' => $db->query('SELECT * FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'tokens' => $db->query('SELECT * FROM user_auth_cache ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'logs' => $db->query('SELECT * FROM user_log')->fetchAll(PDO::FETCH_ASSOC)];
            }
            if ($restore && $accepted) {
                $id = session_id();
                $_COOKIE['Cacti'] = $id;
                $requests->pop();
                $requests->push(Request::create('/links', cookies: $_COOKIE));
                $snapshot = $session->read();
                $_SESSION = $snapshot;
                $legacyValid = auth_session_credentials_valid('old-password-hash');
                $nextConsole = $console->consoleActor()?->id === 9;
            }
        } else {
            $accepted = $access->authorize()->id === 9;
        }
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
                && $db->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn() === ($remembered ? 3 : 0);
        }
        if ($disabledRollback) {
            $db->exec("UPDATE user_auth SET enabled='on' WHERE id=9");
            $resumeWhileActiveDenied = $session->read() === [] && $db->inTransaction()
                && $db->query("SELECT COUNT(*) FROM settings WHERE name='caller_owned_work'")->fetchColumn() === 1;
        }
    }
} finally {
    if ($db->inTransaction()) {
        $rollback ? $db->rollBack() : $db->commit();
    }
}
if ($disabledRollback) {
    $db->exec("UPDATE user_auth SET enabled='on' WHERE id=9");
    $resumeAfterRollbackDenied = $session->read() === [] && $console->consoleActor() === null;
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
$completionMarkers = isset($responseDispatched) ? ['session-state-observed', 'response-dispatched'] : ['session-state-observed'];
if ($disabledRollback) {
    $completionMarkers[] = 'rollback-resume-observed';
}
if ($policyCase) {
    $completionMarkers[] = 'restoration-policy-state-observed';
}
if ($restore && $accepted && $legacyValid === true && $nextConsole === true) {
    $completionMarkers[] = 'restore-handoff-observed';
}
define('SYMFONY_SESSION_NATIVE_COMPLETED', $completionMarkers);
fwrite(STDOUT, json_encode(['resume_while_active_denied' => $resumeWhileActiveDenied, 'resume_after_rollback_denied' => $resumeAfterRollbackDenied, 'policy_before' => $policyBefore ?? null, 'policy_after' => $policyAfter ?? null, 'accepted' => $accepted, 'unauthenticated' => $unauthenticated, 'initial' => $initial, 'revoked' => $revoked, 'transition' => $transition, 'refused_while_active' => $refusedWhileActive, 'legacy_valid' => $legacyValid ?? null, 'next_console' => $nextConsole ?? null], JSON_THROW_ON_ERROR));
