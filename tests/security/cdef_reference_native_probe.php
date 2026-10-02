<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Uses an exclusively owned persistent schema. Never point this at an account
// whose ability to create/drop a schema has not been authorized by its owner.
require dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/CdefReferenceTriggers.php';

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceTriggers;

function connectReferenceProbe(): PDO
{
    $dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
    if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('An explicitly configured native MySQL/MariaDB probe DSN is required.');
    }

    return new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function assertReferenceProbe(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

if (($argv[1] ?? '') === 'writer') {
    try {
        $connection = connectReferenceProbe();
        $connection->exec('USE `' . $argv[2] . '`');
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 15');
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $argv[3]);
        echo "READY\n";
        flush();
        $connection->exec($argv[4]);
        echo "ACCEPTED\n";
    } catch (PDOException $error) {
        // Keep only native SQLSTATE/code, never credentials or SQL text.
        echo 'REJECTED ' . (string) ($error->errorInfo[0] ?? $error->getCode())
            . ' ' . (string) ($error->errorInfo[1] ?? 0) . "\n";
    }
    exit;
}

$connection = connectReferenceProbe();
$schema = 'kadupul_cdef_probe_' . bin2hex(random_bytes(8));
$created = false;
$activeProcess = null;
$activePipes = [];
try {
    echo 'SERVER ' . $connection->query('SELECT VERSION()')->fetchColumn() . "\n";
    $connection->exec("CREATE DATABASE `$schema`");
    $created = true;
    $connection->exec("USE `$schema`");
    foreach ([
        'cdef' => 'id MEDIUMINT UNSIGNED PRIMARY KEY',
        'cdef_items' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL',
        'graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0',
        'aggregate_graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        'aggregate_graphs_graph_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
    ] as $table => $columns) {
        $connection->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    }

    // Real red reproduction on the pristine schema, before installing guards.
    $connection->exec('INSERT INTO cdef VALUES (7), (8)');
    $connection->exec('DELETE FROM cdef WHERE id=7');
    $connection->exec('INSERT INTO graph_templates_item VALUES (1,7)');
    assertReferenceProbe(
        (int) $connection->query('SELECT COUNT(*) FROM graph_templates_item WHERE cdef_id=7')->fetchColumn() === 1,
        'unguarded raw writer reproduces dangling reference'
    );
    $connection->exec('DELETE FROM graph_templates_item');
    $connection->exec('INSERT INTO cdef VALUES (7)');

    // Genuine native CREATE TRIGGER; any missing privilege/binary log
    // restriction fails here. No server or privilege settings are changed.
    foreach (CdefReferenceTriggers::createStatements() as $sql) {
        $connection->exec($sql);
    }
    assertReferenceProbe(
        (int) $connection->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema'")->fetchColumn() === 10,
        'all ten native persistent-table triggers installed'
    );

    $cases = [
        ['graph_templates_item', 'INSERT INTO graph_templates_item VALUES (1,7)'],
        ['graph_templates_item', 'REPLACE INTO graph_templates_item VALUES (1,7)'],
        ['graph_templates_item', 'INSERT INTO graph_templates_item SELECT 1,7'],
        ['graph_templates_item', 'INSERT INTO graph_templates_item VALUES (1,0),(2,7)'],
        ['graph_templates_item', 'UPDATE graph_templates_item SET cdef_id=7 WHERE id=1'],
        ['aggregate_graph_templates_item', 'INSERT INTO aggregate_graph_templates_item VALUES (1,7)'],
        ['aggregate_graphs_graph_item', 'INSERT INTO aggregate_graphs_graph_item VALUES (1,7)'],
        ['cdef_items', "INSERT INTO cdef_items VALUES (1,7,1,'1')"],
        ['cdef_items', "INSERT INTO cdef_items VALUES (1,8,5,'7')"],
        ['cdef_items', "UPDATE cdef_items SET value='7' WHERE id=1"],
    ];
    foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
        foreach ($cases as $caseIndex => [$table, $sql]) {
            foreach ([false, true] as $rollbackOwner) {
                foreach (['cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $clearTable) {
                    $connection->exec("DELETE FROM `$clearTable`");
                }
                $connection->exec('INSERT INTO cdef VALUES (7) ON DUPLICATE KEY UPDATE id=VALUES(id)');
                if (str_starts_with($sql, 'UPDATE graph_templates_item')) {
                    $connection->exec('INSERT INTO graph_templates_item VALUES (1,0)');
                } elseif (str_starts_with($sql, 'UPDATE cdef_items')) {
                    $connection->exec("INSERT INTO cdef_items VALUES (1,8,5,'8')");
                }
                $before = $connection->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                $connection->beginTransaction();
                $connection->exec('DELETE FROM cdef WHERE id=7');
                $activeProcess = proc_open(
                    [PHP_BINARY, '-d', 'auto_prepend_file=', __FILE__, 'writer', $schema, $isolation, $sql],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $activePipes
                );
                if (!is_resource($activeProcess)) {
                    throw new RuntimeException('Cannot start actual second database writer.');
                }
                fclose($activePipes[0]);
                $startup = trim((string) fgets($activePipes[1]));
                if ($startup !== 'READY') {
                    $diagnostic = preg_match('/^REJECTED [A-Z0-9]{5} [0-9]+$/D', $startup) === 1
                        ? $startup : 'unexpected startup output';
                    throw new RuntimeException('Second native database connection did not start: ' . $diagnostic . '.');
                }
                $read = [$activePipes[1]];
                $write = $except = [];
                $finished = stream_select($read, $write, $except, 0, 500000);
                assertReferenceProbe($finished === 0, "$isolation case$caseIndex writer waits for owner decision");
                $rollbackOwner ? $connection->rollBack() : $connection->commit();
                $result = trim((string) stream_get_contents($activePipes[1]));
                $errors = stream_get_contents($activePipes[2]);
                fclose($activePipes[1]);
                fclose($activePipes[2]);
                $exitCode = proc_close($activeProcess);
                $activeProcess = null;
                $activePipes = [];
                assertReferenceProbe($exitCode === 0 && $errors === '', "$isolation case$caseIndex writer exits normally");
                if ($rollbackOwner) {
                    assertReferenceProbe($result === 'ACCEPTED', "$isolation case$caseIndex resumed writer succeeds after rollback");
                } else {
                    assertReferenceProbe($result === 'REJECTED 45000 1644', "$isolation case$caseIndex resumed writer rejected after commit");
                    assertReferenceProbe(
                        $connection->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
                        "$isolation case$caseIndex rejected statement is atomic"
                    );
                }
            }
        }
    }
    echo "PASS native resumed-writer probe complete\n";
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    if (is_resource($activeProcess)) {
        proc_terminate($activeProcess);
        foreach ($activePipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($activeProcess);
    }
    if ($created) {
        $connection->exec("DROP DATABASE `$schema`");
    }
}
