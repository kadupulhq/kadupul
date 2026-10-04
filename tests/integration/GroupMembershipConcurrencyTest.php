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

test('group removal serializes concurrent members while preserving transaction ownership', function (bool $nested) {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('MySQL contract DSN is required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_group_' . bin2hex(random_bytes(5)), 'action' => 'remove', 'nested' => $nested);
    $pdo = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $prefix = $scenario['prefix'];
    $tables = array('user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_group_perms', 'user_auth');
    try {
        $pdo->exec("CREATE TABLE {$prefix}_user_auth_group (id INT PRIMARY KEY) ENGINE=InnoDB");
        foreach (array_slice($tables, 1, 3) as $table) {
            $pdo->exec("CREATE TABLE {$prefix}_{$table} (group_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY(group_id,user_id), KEY member_user(user_id)) ENGINE=InnoDB");
        }
        $pdo->exec("CREATE TABLE {$prefix}_user_auth (id INT PRIMARY KEY, reset_perms BIGINT DEFAULT 0) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$prefix}_user_auth (id) VALUES (42),(43),(44)");
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
        expect(array(proc_close($worker), $error))->toBe(array(0, ''));
        $lines = explode("\n", trim($output));
        if ($nested) {
            $state = json_decode(end($lines), true);
            expect($state['status'])->toBe('REFUSED')->and($state['discovery_reads'])->toBe(1)
                ->and($state['parent'])->toBe(1)
                ->and($pdo->query("SELECT user_id FROM {$prefix}_user_auth_group_members ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN))->toBe(array(42,43));
            if ($state['transaction']) {
                // Ordinary contention rolls back only the savepoint.
                expect($state['caller'])->toBe(777)->and($state['code'])->toBe(0)
                    ->and($state['failure'])->toContain('retry outside caller transaction');
            } else {
                // An engine-aborted transaction cannot be preserved or restarted
                // by this helper. Its original database error must escape.
                expect($state['caller'])->toBe(0)->and((int) $state['code'])->toBe(1020)
                    ->and($state['failure'])->toContain('SQLSTATE');
            }
            expect((int) $pdo->query("SELECT reset_perms FROM {$prefix}_user_auth WHERE id=44")->fetchColumn())->toBe(0);
            return;
        }
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
})->with(array(false, true));

test('copying group grants cannot recreate children after concurrent parent deletion', function () {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    $admin_user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
    if (!$dsn || !$admin_user) {
        $this->markTestSkipped('MySQL contract DSN and explicit lock-observer credentials are required');
    }
    $scenario = array('dsn' => $dsn, 'user' => getenv('KADUPUL_TEST_MYSQL_USER'), 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD'), 'prefix' => 'auth_copy_' . bin2hex(random_bytes(5)));
    $db = new PDO($dsn, $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $observer = new PDO($dsn, $admin_user, getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $prefix = $scenario['prefix'];
    $tables = array('user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms', 'user_auth_group_realm');
    $workers = array();
    try {
        $fields = array('name','description','graph_settings','login_opts','show_tree','show_list','show_preview','policy_graphs','policy_trees','policy_hosts','policy_graph_templates','enabled');
        $db->exec("CREATE TABLE {$prefix}_user_auth_group (id INT AUTO_INCREMENT PRIMARY KEY," . implode(',', array_map(fn($field) => "$field VARCHAR(64) DEFAULT ''", $fields)) . ') ENGINE=InnoDB');
        $db->exec("CREATE TABLE {$prefix}_user_auth_group_perms (group_id INT,item_id INT,type INT,PRIMARY KEY(group_id,item_id,type)) ENGINE=InnoDB");
        $db->exec("CREATE TABLE {$prefix}_user_auth_group_realm (group_id INT,realm_id INT,PRIMARY KEY(group_id,realm_id)) ENGINE=InnoDB");
        $db->exec("CREATE TABLE {$prefix}_user_auth_group_members (group_id INT,user_id INT,PRIMARY KEY(group_id,user_id)) ENGINE=InnoDB");
        $db->exec("CREATE TABLE {$prefix}_user_auth (id INT PRIMARY KEY,reset_perms INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB");
        $db->exec("INSERT INTO {$prefix}_user_auth_group(id) VALUES(5)");
        $db->exec("INSERT INTO {$prefix}_user_auth_group_perms VALUES(5,9,1)");
        $db->exec("INSERT INTO {$prefix}_user_auth_group_realm VALUES(5,1)");
        $copy = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/group-copy-race-probe.php'), array(0 => array('pipe','r'),1 => array('pipe','w'),2 => array('pipe','w')), $copy_pipes);
        $workers[] = $copy;
        fwrite($copy_pipes[0], json_encode($scenario) . "\n");
        stream_set_timeout($copy_pipes[1], 10);
        $marker = trim(fgets($copy_pipes[1]));
        expect($marker)->toStartWith('COPIED:');
        $new_id = (int) substr($marker, 7);
        expect($new_id)->toBeGreaterThan(5);
        expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group WHERE id=$new_id")->fetchColumn())->toBe(0);
        $remover_scenario = $scenario;
        $remover_scenario['action'] = 'remove';
        $remover_scenario['group_id'] = $new_id;
        $remover = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/group-copy-race-probe.php'), array(0 => array('pipe','r'),1 => array('pipe','w'),2 => array('pipe','w')), $remover_pipes);
        $workers[] = $remover;
        fwrite($remover_pipes[0], json_encode($remover_scenario) . "\n");
        fclose($remover_pipes[0]);
        stream_set_timeout($remover_pipes[1], 10);
        $ready = trim(fgets($remover_pipes[1]));
        expect($ready)->toStartWith('REMOVER:');
        $connection = (int) substr($ready, 8);
        $wait_sql = str_contains($observer->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') ?
            'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS AS w INNER JOIN information_schema.INNODB_TRX AS t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = ?' :
            'SELECT COUNT(*) FROM performance_schema.data_lock_waits AS w INNER JOIN performance_schema.threads AS t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?';
        $locked = false;
        $deadline = microtime(true) + 5;
        do {
            $q = $observer->prepare($wait_sql);
            $q->execute(array($connection));
            $locked = (int) $q->fetchColumn() > 0;
            $q->closeCursor();
            $read = array($remover_pipes[1]);
            $write = $except = null;
            // MariaDB's lock snapshot refresh needs >100ms idle since its last read.
            if (stream_select($read, $write, $except, 0, 200000) > 0) {
                break;
            }
        } while (!$locked && microtime(true) < $deadline);
        fwrite($copy_pipes[0], "continue\n");
        fclose($copy_pipes[0]);
        $copy_output = stream_get_contents($copy_pipes[1]);
        $copy_error = stream_get_contents($copy_pipes[2]);
        fclose($copy_pipes[1]);
        fclose($copy_pipes[2]);
        $remove_output = stream_get_contents($remover_pipes[1]);
        $remove_error = stream_get_contents($remover_pipes[2]);
        fclose($remover_pipes[1]);
        fclose($remover_pipes[2]);
        expect(array(proc_close($copy), $copy_error, $copy_output))->toBe(array(0, '', 'COMPLETE_COPY'));
        expect(array(proc_close($remover), $remove_error, $remove_output))->toBe(array(0, '', 'REMOVED_COMPLETE'));
        $workers = array();
        expect($locked)->toBeTrue()->and((int) $db->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group WHERE id=$new_id")->fetchColumn())->toBe(0);
        foreach (array('user_auth_group_perms','user_auth_group_realm','user_auth_group_members') as $table) {
            expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_$table WHERE group_id=$new_id")->fetchColumn())->toBe(0);
        }
        expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group WHERE id=5")->fetchColumn())->toBe(1);
        expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group_perms WHERE group_id=5 AND item_id=9")->fetchColumn())->toBe(1);
        expect((int) $db->query("SELECT COUNT(*) FROM {$prefix}_user_auth_group_realm WHERE group_id=5 AND realm_id=1")->fetchColumn())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
        }
        foreach (array_reverse($tables) as $table) {
            $db->exec("DROP TABLE IF EXISTS {$prefix}_$table");
        }
    }
});
