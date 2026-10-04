<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function membership_replacement_schema(PDO $db, string $prefix): array
{
    $tables = array('user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_perms', 'user_auth_realm', 'settings_user', 'settings_tree');
    $db->exec("CREATE TABLE {$prefix}_user_auth (id INT PRIMARY KEY, username VARCHAR(64), realm INT, enabled VARCHAR(2), locked VARCHAR(2), password VARCHAR(255), full_name VARCHAR(64), email_address VARCHAR(64), must_change_password VARCHAR(2), reset_perms BIGINT DEFAULT 0) ENGINE=InnoDB");
    $db->exec("INSERT INTO {$prefix}_user_auth VALUES (7,'template',0,'on','','template-hash','','','',0),(42,'alice',0,'on','','alice-hash','','','',0),(43,'other',0,'on','','other-hash','','','',0)");
    $db->exec("CREATE TABLE {$prefix}_user_auth_group (id INT PRIMARY KEY) ENGINE=InnoDB");
    $db->exec("INSERT INTO {$prefix}_user_auth_group VALUES (1),(2),(3),(99)");
    $db->exec("CREATE TABLE {$prefix}_user_auth_group_members (group_id INT,user_id INT,PRIMARY KEY(group_id,user_id)) ENGINE=InnoDB");
    $db->exec("INSERT INTO {$prefix}_user_auth_group_members VALUES (1,42),(2,7),(3,43)");
    $db->exec("CREATE TABLE {$prefix}_user_auth_perms (user_id INT,item_id INT,type INT) ENGINE=InnoDB");
    $db->exec("CREATE TABLE {$prefix}_user_auth_realm (user_id INT,realm_id INT) ENGINE=InnoDB");
    $db->exec("CREATE TABLE {$prefix}_settings_user (user_id INT,name VARCHAR(64),value VARCHAR(255)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE {$prefix}_settings_tree (user_id INT,graph_tree_item_id INT) ENGINE=InnoDB");
    return $tables;
}

function membership_replacement_worker(array $scenario): array
{
    $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/membership-replacement-native-probe.php', json_encode($scenario)), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    stream_set_timeout($pipes[1], 10);
    $ready = trim(fgets($pipes[1]));
    expect($ready)->toStartWith('READY:');
    return array($process, $pipes, (int) substr($ready, 6));
}

function membership_replacement_result($process, array $pipes): array
{
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    return json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
}

test('Batch Copy serializes native concurrent destination membership edits before taking its snapshot', function () {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    $admin_user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
    if (!$dsn || !$admin_user) {
        $this->markTestSkipped('MySQL contract DSN and explicit lock-observer credentials are required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_replace_' . bin2hex(random_bytes(5)), 'action' => 'copy', 'pause' => true);
    $db = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $observer = new PDO($dsn, $admin_user, getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $tables = membership_replacement_schema($db, $scenario['prefix']);
    $workers = array();
    try {
        list($copy, $copy_pipes) = membership_replacement_worker($scenario);
        $workers[] = $copy;
        expect(trim(fgets($copy_pipes[1])))->toBe('SNAPSHOT');
        $editor_scenario = $scenario;
        $editor_scenario['action'] = 'add';
        $editor_scenario['pause'] = false;
        list($editor, $editor_pipes, $connection) = membership_replacement_worker($editor_scenario);
        $workers[] = $editor;
        $locked = false;
        $wait_sql = str_contains($observer->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') ?
            'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS AS w INNER JOIN information_schema.INNODB_TRX AS t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = ?' :
            'SELECT COUNT(*) FROM performance_schema.data_lock_waits AS w INNER JOIN performance_schema.threads AS t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?';
        $deadline = microtime(true) + 5;
        do {
            $q = $observer->prepare($wait_sql);
            $q->execute(array($connection));
            $locked = (int) $q->fetchColumn() > 0;
            $q->closeCursor();
            $read = array($editor_pipes[1]);
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50000) > 0) {
                break; // An editor completing before copy commits is the old defect.
            }
        } while (!$locked && microtime(true) < $deadline);
        fwrite($copy_pipes[0], "continue\n");
        $copy_state = membership_replacement_result($copy, $copy_pipes);
        $editor_state = membership_replacement_result($editor, $editor_pipes);
        $workers = array();
        expect($locked)->toBeTrue()->and($copy_state['status'])->toBe('COMPLETE')
            ->and($editor_state['status'])->toBe('COMPLETE')
            ->and($editor_state['members'])->toBe(array(array('group_id' => 2), array('group_id' => 99)))
            ->and((int) $editor_state['reset'])->toBeGreaterThan(0);
        expect($db->query("SELECT group_id FROM {$scenario['prefix']}_user_auth_group_members WHERE user_id = 43")->fetchColumn())->toBe(3);
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
        }
        foreach (array_reverse($tables) as $table) {
            $db->exec("DROP TABLE IF EXISTS {$scenario['prefix']}_{$table}");
        }
    }
});

test('membership replacement rolls back its unit while preserving caller transaction ownership and unrelated writes', function (bool $fail) {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('MySQL contract DSN is required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_nested_' . bin2hex(random_bytes(5)), 'action' => 'replace', 'nested' => true, 'fail' => $fail);
    $db = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $tables = membership_replacement_schema($db, $scenario['prefix']);
    try {
        list($worker, $pipes) = membership_replacement_worker($scenario);
        $state = membership_replacement_result($worker, $pipes);
        expect($state['transaction'])->toBeTrue()->and($state['caller'])->toBe('caller-owned')
            ->and($state['status'])->toBe($fail ? 'REFUSED' : 'COMPLETE')
            ->and($state['members'])->toBe(array(array('group_id' => $fail ? 1 : 2)))
            ->and((int) $db->query("SELECT group_id FROM {$scenario['prefix']}_user_auth_group_members WHERE user_id = 42")->fetchColumn())->toBe(1)
            ->and($db->query("SELECT full_name FROM {$scenario['prefix']}_user_auth WHERE id = 43")->fetchColumn())->toBe('');
    } finally {
        foreach (array_reverse($tables) as $table) {
            $db->exec("DROP TABLE IF EXISTS {$scenario['prefix']}_{$table}");
        }
    }
})->with(array(false, true));
