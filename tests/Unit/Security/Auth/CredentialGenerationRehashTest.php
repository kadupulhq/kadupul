<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function credential_generation_probe(string $scenario): array
{
    $auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
    $program = '';
    foreach (array('auth_session_credential_key', 'auth_session_credential_generation', 'auth_rehash_password_preserving_sessions', 'auth_session_bind_credentials', 'auth_session_credentials_valid') as $function) {
        $program .= test_php_function_source($auth, $function) . "\n";
    }
    $program .= <<<'PHP'
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, realm INTEGER, enabled TEXT, locked TEXT, password TEXT)');
$db->exec('CREATE TABLE settings_user(user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY(user_id,name))');
$db->exec("INSERT INTO user_auth VALUES (42,0,'on','','old-hash')");
function db_fetch_cell_prepared($sql, $params = array()) {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
$_SESSION = array('sess_user_id' => 42);
auth_session_bind_credentials(42);
$first = $_SESSION;
$_SESSION = array('sess_user_id' => 42);
auth_session_bind_credentials(42);
$second = $_SESSION;
if ($argv[1] === 'replacement') {
    $db->exec("UPDATE user_auth SET password='reset-hash'");
} elseif ($argv[1] === 'rollback') {
    $db->exec("CREATE TRIGGER fail_password BEFORE UPDATE ON user_auth BEGIN SELECT RAISE(ABORT,'write refused'); END");
} elseif ($argv[1] === 'caller') {
    $db->beginTransaction();
    $db->exec("INSERT INTO settings_user VALUES(9,'caller','owned')");
}
$upgraded = auth_rehash_password_preserving_sessions(42, 'old-hash', 'new-hash', $db);
$password = $db->query('SELECT password FROM user_auth WHERE id=42')->fetchColumn();
$_SESSION = $first;
$first_valid = auth_session_credentials_valid($password);
$_SESSION = $second;
$second_valid = auth_session_credentials_valid($password);
$second_upgrade = null;
$reset_valid = null;
$new_binding = null;
$reset_second_valid = null;
if ($argv[1] === 'normal') {
    $second_upgrade = auth_rehash_password_preserving_sessions(42, 'new-hash', 'third-hash', $db);
    $password = $db->query('SELECT password FROM user_auth WHERE id=42')->fetchColumn();
    $second_valid = auth_session_credentials_valid($password);
    $_SESSION = array('sess_user_id' => 42);
    auth_session_bind_credentials(42);
    $new_binding = $_SESSION['sess_user_credential'] === $first['sess_user_credential'];
    $db->exec("UPDATE user_auth SET password='reset-hash'");
    $_SESSION = $first;
    $reset_valid = auth_session_credentials_valid('reset-hash');
    $_SESSION = $second;
    $reset_second_valid = auth_session_credentials_valid('reset-hash');
}
$_SESSION = array('sess_user_id' => 42);
$unbound_valid = auth_session_credentials_valid($password);
print json_encode(array('new_binding'=>$new_binding,'reset_second_valid'=>$reset_second_valid,'upgraded'=>$upgraded, 'first_valid'=>$first_valid,'second_valid'=>$second_valid,'second_upgrade'=>$second_upgrade,'reset_valid'=>$reset_valid,'password'=>$password,'mapping_count'=>(int)$db->query("SELECT COUNT(*) FROM settings_user WHERE name='auth_credential_generation'")->fetchColumn(),'in_transaction'=>$db->inTransaction(),'unbound_valid'=>$unbound_valid));
PHP;
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect($status)->toBe(0)->and($stderr)->toBe('');
    return json_decode($stdout, true);
}

test('two sessions survive successive transparent rehashes but a reset invalidates them', function () {
    $result = credential_generation_probe('normal');
    expect($result['upgraded'])->toBeTrue()->and($result['new_binding'])->toBeTrue()->and($result['reset_second_valid'])->toBeFalse()->and($result['first_valid'])->toBeTrue()->and($result['second_valid'])->toBeTrue()->and($result['second_upgrade'])->toBeTrue()->and($result['reset_valid'])->toBeFalse()->and($result['password'])->toBe('third-hash')->and($result['unbound_valid'])->toBeFalse();
});

test('a stale verified hash cannot overwrite a concurrent password reset', function () {
    $result = credential_generation_probe('replacement');
    expect($result['upgraded'])->toBeFalse()->and($result['password'])->toBe('reset-hash')->and($result['mapping_count'])->toBe(0)->and($result['first_valid'])->toBeFalse()->and($result['in_transaction'])->toBeFalse();
});

test('a failed hash update rolls back its generation mapping', function () {
    $result = credential_generation_probe('rollback');
    expect($result['upgraded'])->toBeFalse()->and($result['password'])->toBe('old-hash')->and($result['mapping_count'])->toBe(0)->and($result['first_valid'])->toBeTrue()->and($result['in_transaction'])->toBeFalse();
});

test('rehashing leaves a caller transaction owned by the caller', function () {
    $result = credential_generation_probe('caller');
    expect($result['upgraded'])->toBeFalse()->and($result['password'])->toBe('old-hash')->and($result['mapping_count'])->toBe(0)->and($result['in_transaction'])->toBeTrue();
});
