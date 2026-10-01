<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/lib/data_source_profile_integrity.php';
function profile_guard_connection(): PDO
{
    return new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
if (($argv[1] ?? '') === '--writer') {
    $db = profile_guard_connection();
    $data = $argv[2];
    echo $db->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
    flush();
    try {
        $query = match ($argv[3] ?? 'insert') {
            'update' => "UPDATE `$data` SET data_source_profile_id=3 WHERE id=2",
            'upsert' => "INSERT INTO `$data` VALUES (2,3,'new') ON DUPLICATE KEY UPDATE data_source_profile_id=VALUES(data_source_profile_id)",
            default => "INSERT INTO `$data` VALUES (2,3,'new')",
        };
        $db->exec($query);
        echo json_encode(['inserted' => true]);
    } catch (PDOException $error) {
        echo json_encode(['inserted' => false, 'sqlstate' => $error->getCode()]);
    }
    exit;
}
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$directory = $argv[2];
$root = dirname(__DIR__, 2);
$suffix = bin2hex(random_bytes(6));
$profiles = 'guard_parent_' . $suffix;
$data = 'guard_child_' . $suffix;
$prefix = 'guard_trigger_' . $suffix;
$db = profile_guard_connection();
function db_fetch_assoc_prepared($sql, $params = [])
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
$process = null;
$pipes = [];
try {
    $db->exec("CREATE TABLE `$profiles` (id INTEGER PRIMARY KEY) ENGINE=InnoDB");
    $db->exec("CREATE TABLE `$data` (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER NOT NULL, name VARCHAR(32), INDEX(data_source_profile_id)) ENGINE=InnoDB");
    $db->exec("INSERT INTO `$profiles` VALUES (3)");
    // Historical orphan retained from before the upgrade.
    $db->exec("INSERT INTO `$data` VALUES (1,99,'old')");
    $definitions = data_source_profile_reference_triggers($profiles, $data, $prefix);
    foreach ($definitions as $definition) {
        $db->exec($definition['sql']);
    }
    $available = data_source_profile_reference_guards_available($profiles, $data, $prefix);
    $db->exec("UPDATE `$data` SET name='edited' WHERE id=1");
    $db->exec("INSERT INTO `$data` VALUES (1,99,'upserted') ON DUPLICATE KEY UPDATE data_source_profile_id=VALUES(data_source_profile_id), name=VALUES(name)");
    $db->exec("INSERT INTO `$data` VALUES (4,0,'default')");
    $rejected = [];
    foreach (["INSERT INTO `$data` VALUES (5,99,'copy')", "UPDATE `$data` SET data_source_profile_id=98 WHERE id=1", "INSERT INTO `$data` VALUES (4,99,'changed') ON DUPLICATE KEY UPDATE data_source_profile_id=VALUES(data_source_profile_id)"] as $query) {
        try {
            $db->exec($query);
            $rejected[] = false;
        } catch (PDOException $error) {
            $rejected[] = $error->getCode() === '45000';
        }
    }
    $operation = $scenario['writer'] ?? 'insert';
    if ($operation !== 'insert') {
        $db->exec("INSERT INTO `$data` VALUES (2,0,'existing')");
    }
    $db->beginTransaction();
    $db->query("SELECT id FROM `$profiles` WHERE id=3 FOR UPDATE")->fetchAll();
    $db->query("SELECT data_source_profile_id FROM `$data` WHERE data_source_profile_id=3 FOR UPDATE")->fetchAll();
    $db->exec("DELETE FROM `$profiles` WHERE id=3");
    $process = proc_open([PHP_BINARY, __FILE__, '--writer', $data, $operation], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch concurrent reference writer');
    }
    fclose($pipes[0]);
    $writerId = (int) fgets($pipes[1]);
    $monitor = profile_guard_connection();
    $waiting = false;
    $deadline = microtime(true) + 5;
    do {
        $statement = $monitor->prepare("SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=?");
        $statement->execute([$writerId]);
        $waiting = $statement->fetchColumn() === 'LOCK WAIT';
        if (!$waiting) {
            usleep(20000);
        }
    } while (!$waiting && microtime(true) < $deadline);
    if (!$waiting) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        throw new RuntimeException('Concurrent writer did not wait for the deletion transaction: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    }
    if (($scenario['reference_guard'] ?? '') === 'rollback') {
        $db->rollBack();
    } else {
        $db->commit();
    }
    $writer = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $process = null;
    if ($status !== 0 || $stderr !== '') {
        throw new RuntimeException('Concurrent writer failed: ' . $stderr);
    }
    $orphans = (int) $db->query("SELECT COUNT(*) FROM `$data` d LEFT JOIN `$profiles` p ON p.id=d.data_source_profile_id WHERE d.id=2 AND d.data_source_profile_id<>0 AND p.id IS NULL")->fetchColumn();
    // Removing or modifying either guard must stop physical deletion.
    $name = array_key_first($definitions);
    $db->exec("DROP TRIGGER `$name`");
    $missingRejected = !data_source_profile_reference_guards_available($profiles, $data, $prefix);
    $db->exec("CREATE TRIGGER `$name` BEFORE INSERT ON `$data` FOR EACH ROW SET NEW.name=NEW.name");
    $modifiedRejected = !data_source_profile_reference_guards_available($profiles, $data, $prefix);
    file_put_contents($directory . '/result.json', json_encode(['available' => $available, 'waiting' => $waiting, 'writer' => $writer, 'orphans' => $orphans, 'legacyName' => $db->query("SELECT name FROM `$data` WHERE id=1")->fetchColumn(), 'zero' => (int) $db->query("SELECT data_source_profile_id FROM `$data` WHERE id=4")->fetchColumn(), 'rejected' => $rejected, 'missingRejected' => $missingRejected, 'modifiedRejected' => $modifiedRejected], JSON_THROW_ON_ERROR));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $db->exec("DROP TABLE IF EXISTS `$data`");
    $db->exec("DROP TABLE IF EXISTS `$profiles`");
}
