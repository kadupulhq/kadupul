<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/lib/auth.php';
$scenario = $argv[1];
$directory = $argv[2];
$databaseSessions = $argv[3] === 'database';
$dsn = getenv('KADUPUL_SESSION_TEST_DSN') ?: 'sqlite::memory:';
$db = new PDO($dsn, getenv('KADUPUL_SESSION_TEST_USER') ?: null, getenv('KADUPUL_SESSION_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password TEXT,realm INTEGER);
    INSERT INTO user_auth VALUES(9,'operator','on','','','old-password-hash',0);
    CREATE TABLE settings(name TEXT,value TEXT); INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
    CREATE TABLE settings_user(user_id INTEGER,name VARCHAR(255),value TEXT,PRIMARY KEY(user_id,name));
    CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER); INSERT INTO user_auth_realm VALUES(9,8),(9,15);
    CREATE TABLE sessions(id VARCHAR(256) PRIMARY KEY,data TEXT,access INTEGER)");
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
if ($scenario !== 'unbound') {
    $_SESSION['sess_user_credential'] = auth_session_credential_key('old-password-hash');
}
$id = session_id();
$payload = session_encode();
session_write_close();
if ($databaseSessions) {
    $db->prepare('INSERT INTO sessions VALUES(?,?,?)')->execute([$id, $payload, time()]);
}
$_COOKIE = ['Cacti' => $id];
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
$accepted = false;
$unauthenticated = false;
if (str_ends_with($scenario, '-write')) {
    $db->beginTransaction();
}
try {
    if ($db->inTransaction()) {
        $access->assertCurrent(9);
        $accepted = true;
    } else {
        $accepted = $access->authorize()->id === 9;
    }
} catch (LinkAccessDenied $error) {
    $unauthenticated = $error->unauthenticated;
} finally {
    if ($db->inTransaction()) {
        $db->commit();
    }
}
$revoked = $databaseSessions ? $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 0 : !is_file($directory . '/sess_' . $id);
fwrite(STDOUT, json_encode(['accepted' => $accepted, 'unauthenticated' => $unauthenticated, 'initial' => $initial, 'revoked' => $revoked], JSON_THROW_ON_ERROR));
