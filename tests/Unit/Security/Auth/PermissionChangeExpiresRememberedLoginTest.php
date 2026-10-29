<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Upstream Cacti #7600: a permission, group or realm change expires the
 * remembered logins of the users it affects, so their next visit needs a
 * fresh sign-in. Other users keep theirs, and a membership change that rolls
 * back leaves every remembered login in place.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function remembered_login_reset_run(string $scenario): array
{
    $auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
    $program = '';
    foreach (array('reset_user_perms', 'reset_group_perms', 'auth_membership_begin', 'auth_membership_finish', 'auth_membership_lock_users', 'auth_membership_lock_groups', 'auth_membership_rows', 'auth_membership_execute', 'auth_membership_reset_users', 'user_group_change_memberships', 'user_group_update_membership') as $name) {
        $program .= test_php_function_source($auth, $name) . "\n";
    }
    $program .= <<<'PHP'
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->sqliteCreateFunction('RAND', static fn() => 0.5);
$db->sqliteCreateFunction('FLOOR', 'floor');
$database_hostname = 'fixture'; $database_port = 0; $database_default = 'auth';
$database_sessions = array('fixture:0:auth' => $db);
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, reset_perms INTEGER NOT NULL DEFAULT 7);
    INSERT INTO user_auth(id) VALUES(42),(43),(44);
    CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY);
    INSERT INTO user_auth_group VALUES(5);
    CREATE TABLE user_auth_group_members(group_id INTEGER, user_id INTEGER, PRIMARY KEY(group_id,user_id));
    INSERT INTO user_auth_group_members VALUES(5,42),(5,44);
    CREATE TABLE user_auth_cache(user_id INTEGER, token TEXT);
    INSERT INTO user_auth_cache VALUES(42,'a'),(42,'b'),(43,'c'),(44,'d');");
$_SESSION = array();
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function array_rekey($array, $key, $value) { $out = array(); foreach ($array as $row) { $out[$row[$key]] = $row[$value]; } return $out; }
function db_fetch_assoc_prepared($sql, $params = array()) { $q = $GLOBALS['db']->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql, $params = array()) { return $GLOBALS['db']->prepare($sql)->execute($params); }
function db_execute($sql) { return $GLOBALS['db']->exec($sql) !== false; }
function db_begin_transaction($db) { return $db->beginTransaction(); }
function db_commit_transaction($db) { return $db->commit(); }
function db_rollback_transaction($db) { return $db->rollBack(); }
$error = null;
try {
    match ($argv[1]) {
        'user' => reset_user_perms(42),
        'group' => reset_group_perms(5),
        'empty group' => reset_group_perms(6),
        'add member' => user_group_update_membership(5, 43, true),
        'remove member' => user_group_update_membership(5, 43, false),
        'missing member' => user_group_update_membership(5, 99, true),
    };
} catch (Throwable $caught) {
    $error = $caught->getMessage();
}
echo json_encode(array(
    'error' => $error,
    'remembered' => array_map('intval', $db->query('SELECT user_id FROM user_auth_cache ORDER BY user_id, token')->fetchAll(PDO::FETCH_COLUMN)),
    'epochs' => array_map('intval', $db->query('SELECT id, reset_perms FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)),
), JSON_THROW_ON_ERROR);
PHP;
    $worker = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($worker))->toBe(0)->and($error)->toBe('');

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('a user permission change expires only that user\'s remembered logins', function () {
    $result = remembered_login_reset_run('user');

    expect($result['error'])->toBeNull()
        ->and($result['remembered'])->toBe(array(43, 44))
        ->and($result['epochs'][42])->not->toBe(7)
        ->and($result['epochs'][43])->toBe(7);
});

test('a group permission change expires the remembered logins of its members only', function () {
    $result = remembered_login_reset_run('group');

    expect($result['error'])->toBeNull()
        ->and($result['remembered'])->toBe(array(43))
        ->and($result['epochs'][43])->toBe(7);
});

test('a group without members expires no remembered login', function () {
    $result = remembered_login_reset_run('empty group');

    expect($result['error'])->toBeNull()
        ->and($result['remembered'])->toBe(array(42, 42, 43, 44));
});

test('a membership change expires the remembered logins of the member it changes', function (string $scenario) {
    $result = remembered_login_reset_run($scenario);

    expect($result['error'])->toBeNull()
        ->and($result['remembered'])->toBe(array(42, 42, 44))
        ->and($result['epochs'][43])->toBe(8);
})->with(array('add member', 'remove member'));

test('a membership change that rolls back keeps every remembered login', function () {
    $result = remembered_login_reset_run('missing member');

    expect($result['error'])->toBe('Membership user no longer exists')
        ->and($result['remembered'])->toBe(array(42, 42, 43, 44))
        ->and($result['epochs'])->toBe(array(42 => 7, 43 => 7, 44 => 7));
});
