<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function logout_everywhere_probe(string $scenario): array
{
    $root = dirname(__DIR__, 4);
    $program = 'require ' . var_export($root . '/lib/auth.php', true) . ';'
        . test_php_function_source(file_get_contents($root . '/auth_profile.php'), 'api_auth_logout_everywhere')
        . <<<'PHP'
class RevocationPdo extends PDO {
    public bool $refuseCommit = false;
    public function commit(): bool { return $this->refuseCommit ? false : parent::commit(); }
}
$db = new RevocationPdo('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database_hostname = 'fixture'; $database_port = 0; $database_default = 'auth';
$database_sessions = ['fixture:0:auth' => $db];
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, password TEXT, realm INTEGER, enabled TEXT, locked TEXT);
    INSERT INTO user_auth VALUES(42,'unchanged-hash',0,'on',''),(43,'other-hash',0,'on','');
    CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
    INSERT INTO settings_user VALUES(42,'page_refresh','60');
    CREATE TABLE sessions(id TEXT PRIMARY KEY,user_id INTEGER,data TEXT);
    INSERT INTO sessions VALUES('first',42,'first-session'),('second',42,'second-session'),('other',43,'other-session');
    CREATE TABLE user_auth_cache(user_id INTEGER,token TEXT);
    INSERT INTO user_auth_cache VALUES(42,'first-token'),(42,'second-token'),(43,'other-token');");
function db_fetch_cell_prepared($sql, $parameters = []) { $q = $GLOBALS['db']->prepare($sql); $q->execute($parameters); return $q->fetchColumn(); }
function db_execute_prepared($sql, $parameters = []) { return $GLOBALS['db']->prepare($sql)->execute($parameters); }
function cacti_cookie_session_logout() { $GLOBALS['expired'] = true; }
function cacti_session_destroy() { $_SESSION = []; $GLOBALS['destroyed'] = true; }
function cacti_log(...$args) { $GLOBALS['audit'][] = $args[0]; }
$_SESSION = ['sess_user_id' => 42];
auth_session_bind_credentials(42);
if ($argv[1] === 'rehash') {
    if (!auth_rehash_password_preserving_sessions(42, 'unchanged-hash', 'upgraded-hash', $db)) { throw new RuntimeException('Rehash failed'); }
}
$oldSession = $_SESSION;
$password = db_fetch_cell_prepared('SELECT password FROM user_auth WHERE id = 42');
$beforeMapping = db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='auth_credential_generation'");
if (in_array($argv[1], ['generation', 'tokens', 'sessions'], true)) {
    $table = ['generation' => 'settings_user', 'tokens' => 'user_auth_cache', 'sessions' => 'sessions'][$argv[1]];
    $event = $argv[1] === 'generation' ? 'INSERT' : 'DELETE';
    $db->exec("CREATE TRIGGER refuse_write BEFORE $event ON $table BEGIN SELECT RAISE(ABORT,'refused'); END");
} elseif ($argv[1] === 'readback') {
    $db->exec("CREATE TRIGGER change_mapping AFTER INSERT ON settings_user BEGIN UPDATE settings_user SET value='invalid' WHERE user_id=42 AND name='auth_credential_generation'; END");
} elseif ($argv[1] === 'commit') { $db->refuseCommit = true; }
elseif ($argv[1] === 'caller') { $db->beginTransaction(); $db->exec("INSERT INTO settings_user VALUES(43,'caller','owned')"); }
elseif ($argv[1] === 'stale') { $_SESSION['sess_user_credential'] = str_repeat('a',64); }
elseif ($argv[1] === 'unbound') { unset($_SESSION['sess_user_credential']); }
elseif ($argv[1] === 'missing') { $db->exec('DELETE FROM user_auth WHERE id=42'); }
$_COOKIE['cacti_remembers'] = '42,0,old-token';
api_auth_logout_everywhere();
$response = http_response_code() ?: 200;
$destroyed = $GLOBALS['destroyed'] ?? false;
$expired = $GLOBALS['expired'] ?? false;
$cookieRemoved = !isset($_COOKIE['cacti_remembers']);
$currentSessionCleared = $_SESSION === [];
$_SESSION = $oldSession;
$oldValid = auth_session_credentials_valid($password);
$_SESSION = ['sess_user_id' => 42];
auth_session_bind_credentials(42);
$newValid = auth_session_credentials_valid($password);
$mapping = db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='auth_credential_generation'");
$state = ['status' => $response, 'old_valid' => $oldValid, 'new_valid' => $newValid,
    'destroyed' => $destroyed, 'expired' => $expired, 'cookie_removed' => $cookieRemoved, 'current_cleared' => $currentSessionCleared,
    'mapping_preserved' => $mapping === $beforeMapping, 'in_transaction' => $db->inTransaction(),
    'sessions' => $db->query('SELECT id FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
    'tokens' => $db->query('SELECT token FROM user_auth_cache ORDER BY token')->fetchAll(PDO::FETCH_COLUMN),
    'preference' => db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='page_refresh'"),
    'password' => db_fetch_cell_prepared('SELECT password FROM user_auth WHERE id=42')];
if ($argv[1] === 'caller') { $state['caller'] = db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=43 AND name='caller'"); }
print json_encode($state, JSON_THROW_ON_ERROR);
PHP;
    $result = test_php_run([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $scenario]);
    expect($result['status'])->toBe(0)->and($result['err'])->toBe('');

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('logout everywhere revokes both sessions and remembered logins while preserving other users', function (string $scenario) {
    $state = logout_everywhere_probe($scenario);
    expect($state['status'])->toBe(200)
        ->and($state['old_valid'])->toBeFalse()->and($state['new_valid'])->toBeTrue()
        ->and($state['destroyed'])->toBeTrue()->and($state['expired'])->toBeTrue()
        ->and($state['current_cleared'])->toBeTrue()->and($state['cookie_removed'])->toBeTrue()
        ->and($state['sessions'])->toBe(['other'])->and($state['tokens'])->toBe(['other-token'])
        ->and($state['preference'])->toBe('60')->and($state['in_transaction'])->toBeFalse()
        ->and($state['password'])->toBe($scenario === 'rehash' ? 'upgraded-hash' : 'unchanged-hash');
})->with(['normal', 'rehash']);

test('failed revocation preserves all sessions and tokens and never returns success', function (string $scenario) {
    $state = logout_everywhere_probe($scenario);
    expect($state['status'])->toBe(503)
        ->and($state['destroyed'])->toBeFalse()->and($state['expired'])->toBeFalse()
        ->and($state['cookie_removed'])->toBeFalse()->and($state['mapping_preserved'])->toBeTrue()
        ->and($state['sessions'])->toBe(['first', 'other', 'second'])
        ->and($state['tokens'])->toBe(['first-token', 'other-token', 'second-token'])
        ->and($state['in_transaction'])->toBe($scenario === 'caller');
    if ($scenario !== 'missing') {
        expect($state['old_valid'])->toBeTrue();
    }
    if ($scenario === 'caller') {
        expect($state['caller'])->toBe('owned');
    }
})->with(['generation', 'tokens', 'sessions', 'readback', 'commit', 'caller', 'stale', 'unbound', 'missing']);
