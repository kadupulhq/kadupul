<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function group_concurrency_worker(array $scenario): array
{
    $pipes = array();
    $worker = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Helpers/GroupTransactionProbe.php', json_encode($scenario)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    stream_set_timeout($pipes[1], 10);
    expect(trim(fgets($pipes[1])))->toBe('READY');
    return array($worker, $pipes);
}

test('group removal captures committed concurrent members and blocks subsequent insertion', function () {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('MySQL contract DSN is required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_group_' . bin2hex(random_bytes(5)), 'action' => 'remove');
    $pdo = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $prefix = $scenario['prefix'];
    $tables = array('user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_group_perms');
    try {
        $pdo->exec("CREATE TABLE {$prefix}_user_auth_group (id INT PRIMARY KEY) ENGINE=InnoDB");
        foreach (array_slice($tables, 1) as $table) {
            $pdo->exec("CREATE TABLE {$prefix}_{$table} (group_id INT, user_id INT, UNIQUE KEY membership(group_id,user_id)) ENGINE=InnoDB");
        }
        $pdo->exec("INSERT INTO {$prefix}_user_auth_group VALUES (5)");
        $pdo->exec("INSERT INTO {$prefix}_user_auth_group_members VALUES (5,42)");
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM {$prefix}_user_auth_group WHERE id=5 FOR UPDATE")->fetchColumn();
        $pdo->exec("INSERT INTO {$prefix}_user_auth_group_members VALUES (5,43)");
        list($worker, $pipes) = group_concurrency_worker($scenario);
        // Before the fix, the snapshot marker appears before this writer
        // commits. After it, the deletion reaches the blocked parent lock.
        expect(trim(fgets($pipes[1])))->toBeIn(array('LOCK', 'SNAPSHOT'));
        $pdo->commit();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($worker))->toBe(0)->and($error)->toBe('');
        $lines = explode("\n", trim($output));
        expect(json_decode(end($lines), true))->toBe(array(42,43));
        $scenario['action'] = 'add';
        list($worker, $pipes) = group_concurrency_worker($scenario);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($worker))->toBe(0)->and($error)->toBe('')
            ->and((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group_members")->fetchColumn())->toBe(0);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach (array_reverse($tables) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$prefix}_{$table}");
        }
    }
});

test('copying group grants cannot recreate children after concurrent parent deletion', function () {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('MySQL contract DSN is required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_copy_' . bin2hex(random_bytes(5)));
    $db = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $prefix = $scenario['prefix'];
    $tables = array('user_auth_group', 'user_auth_group_perms', 'user_auth_group_realm');
    $worker = null;
    try {
        $fields = array('name','description','graph_settings','login_opts','show_tree','show_list','show_preview','policy_graphs','policy_trees','policy_hosts','policy_graph_templates','enabled');
        $db->exec("CREATE TABLE {$prefix}_user_auth_group (id INT AUTO_INCREMENT PRIMARY KEY," . implode(',', array_map(fn($field) => "$field VARCHAR(64) DEFAULT ''", $fields)) . ') ENGINE=InnoDB');
        $db->exec("CREATE TABLE {$prefix}_user_auth_group_perms (group_id INT,item_id INT,type INT) ENGINE=InnoDB");
        $db->exec("CREATE TABLE {$prefix}_user_auth_group_realm (group_id INT,realm_id INT) ENGINE=InnoDB");
        $db->exec("INSERT INTO {$prefix}_user_auth_group(id) VALUES(5)");
        $db->exec("INSERT INTO {$prefix}_user_auth_group_perms VALUES(5,9,1)");
        $db->exec("INSERT INTO {$prefix}_user_auth_group_realm VALUES(5,1)");
        $worker = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/Helpers/GroupCopyRaceProbe.php', json_encode($scenario)), array(0 => array('pipe','r'),1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
        stream_set_timeout($pipes[1], 10);
        $marker = trim(fgets($pipes[1]));
        expect($marker)->toStartWith('COPIED:');
        $new_id = (int) substr($marker, 7);
        expect($new_id)->toBeGreaterThan(5);
        $db->exec("DELETE FROM {$prefix}_user_auth_group WHERE id=$new_id");
        fwrite($pipes[0], "continue\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($worker))->toBe(0);
        $worker = null;
        expect($error)->toBe('')->and($output)->toBe('REFUSED_REMOVED_PARENT');
        foreach (array_slice($tables, 1) as $table) {
            expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_{$table} WHERE group_id=$new_id")->fetchColumn())->toBe(0);
        }
    } finally {
        if (is_resource($worker)) {
            proc_terminate($worker);
            proc_close($worker);
        }
        foreach (array_reverse($tables) as $table) {
            $db->exec("DROP TABLE IF EXISTS {$prefix}_{$table}");
        }
    }
});
