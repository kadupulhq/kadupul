<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\UserRemovalPersistenceNative;

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function removeUser(array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'CHILD'
        $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        require $argv[1] . '/include/global_constants.php';
        require $argv[1] . '/lib/auth.php';
        require $argv[1] . '/lib/html_validate.php';
        $db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $database_hostname = 'native'; $database_port = 0; $database_default = 'auth';
        $database_sessions = array('native:0:auth' => $db);
        $db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY); INSERT INTO user_auth VALUES (42), (43);
            CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY); INSERT INTO user_auth_group VALUES (5);
            CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER); INSERT INTO user_auth_group_members VALUES (5,42), (5,43);');
        $db->exec('CREATE TABLE user_domains (id INTEGER, user_id INTEGER);');
        $children = array('user_auth_realm', 'user_auth_cache', 'user_auth_perms', 'user_auth_row_cache', 'settings_user', 'settings_tree', 'sessions');
        foreach ($children as $table) {
            $db->exec('CREATE TABLE ' . $table . ' (user_id INTEGER); INSERT INTO ' . $table . ' VALUES (42), (43)');
        }
        function query($sql, $params = array()) {
            $statement = $GLOBALS['db']->prepare(str_replace(' FOR UPDATE', '', $sql));
            $statement->execute($params);
            return $statement;
        }
        function db_fetch_cell_prepared($sql, $params) { return query($sql, $params)->fetchColumn(); }
        function db_fetch_assoc_prepared($sql, $params) { return query($sql, $params)->fetchAll(PDO::FETCH_ASSOC); }
        function db_execute_prepared($sql, $params) {
            if (($GLOBALS['scenario']['fail'] ?? '') !== '' && str_starts_with($sql, 'DELETE FROM ' . $GLOBALS['scenario']['fail'] . ' ')) {
                return false;
            }
            query($sql, $params); return true;
        }
        function db_begin_transaction($db) { return $db->beginTransaction(); }
        function db_commit_transaction($db) { return $db->commit(); }
        function db_rollback_transaction($db) { return $db->rollBack(); }
        function read_config_option($name) { return array('admin_user' => 1, 'guest_user' => 2, 'user_template' => 3)[$name] ?? ''; }
        function get_guest_account() { return 2; }
        function get_template_account($user) { return 0; }
        function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
        function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
        function raise_message($message) { $GLOBALS['messages'][] = $message; }
        function api_plugin_hook_function($name, $value) { $GLOBALS['hooks'][] = array($name, $value); }
        function state() {
            $result = array('user_auth' => $GLOBALS['db']->query('SELECT id FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
                'groups' => $GLOBALS['db']->query('SELECT id FROM user_auth_group ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            foreach (array('user_auth_group_members', 'user_auth_realm', 'user_auth_cache', 'user_auth_perms', 'user_auth_row_cache', 'settings_user', 'settings_tree', 'sessions') as $table) {
                $result[$table] = $GLOBALS['db']->query('SELECT * FROM ' . $table . ' ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
            }
            return $result;
        }
        $GLOBALS['messages'] = $GLOBALS['hooks'] = array();
        $caller = $scenario['caller'] ?? false;
        if ($caller) { $db->beginTransaction(); $db->exec('INSERT INTO user_auth VALUES (99)'); }
        $before = state();
        $error = '';
        try { user_remove($scenario['protected'] ?? false ? 2 : 42); }
        catch (RuntimeException $failure) { $error = $failure->getMessage(); }
        $after = state();
        $active = $db->inTransaction();
        if ($caller) { $db->rollBack(); }
        $GLOBALS['nativeChildCoverageMarkers'] = array('native-user-removal-state-readback', 'native-user-removal-transaction-observed');
        print json_encode(array('before' => $before, 'after' => $after, 'error' => $error, 'active' => $active, 'hooks' => $GLOBALS['hooks'], 'messages' => $GLOBALS['messages']), JSON_THROW_ON_ERROR);
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        'user-removal',
        $scenario,
        array('native-user-removal-state-readback', 'native-user-removal-transaction-observed'),
        array('lib/auth.php'),
        array('lib/html_validate.php', 'include/global_constants.php')
    );
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, json_encode($scenario, JSON_THROW_ON_ERROR)), $directory, $registration);
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch native user removal.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($stderr)->toBe('')->and($status)->toBe(0);
        \child_coverage_collect($directory);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($directory !== null && is_dir($directory)) {
            foreach (glob($directory . '/*.coverage*') ?: array() as $ownedReport) {
                unlink($ownedReport);
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

test('native user removal deletes only target rows and preserves caller transactions', function (bool $caller) {
    $state = removeUser(array('caller' => $caller));
    expect($state['error'])->toBe('')->and($state['active'])->toBe($caller)
        ->and($state['after']['user_auth'])->toBe($caller ? array(43, 99) : array(43))
        ->and($state['after']['groups'])->toBe(array(5))
        ->and($state['hooks'])->toBe(array(array('user_remove', 42)));
    foreach ($state['after'] as $table => $rows) {
        if (!in_array($table, array('user_auth', 'groups'), true)) {
            expect(array_column($rows, 'user_id'))->toBe(array(43));
        }
    }
})->with(array('owned transaction' => false, 'caller transaction' => true));

test('native user removal failure restores every row and caller-owned work', function (string $table, bool $caller) {
    $state = removeUser(array('fail' => $table, 'caller' => $caller));
    expect($state['error'])->toBe($table === 'user_auth' ? 'Unable to remove user' : 'Unable to remove user data')
        ->and($state['after'])->toBe($state['before'])->and($state['active'])->toBe($caller)
        ->and($state['hooks'])->toBe(array());
})->with(array('first delete fails' => array('user_auth', false), 'late child delete fails' => array('settings_tree', false),
    'late failure retains caller write' => array('settings_tree', true)));

test('native protected-account removal preserves all rows', function () {
    $state = removeUser(array('protected' => true));
    expect($state['after'])->toBe($state['before'])->and($state['error'])->toBe('')
        ->and($state['messages'])->toBe(array(21))->and($state['hooks'])->toBe(array());
});
