<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Opt-in database probe. Requires CREATE/DROP DATABASE on a disposable server.
// Credentials are supplied through REMOVAL_TEST_DSN/USER/PASSWORD, never logged.
require __DIR__ . '/../../include/vendor/autoload.php';

use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalSnapshot;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencies;

$dsn = getenv('REMOVAL_TEST_DSN');
if (!$dsn) {
    throw new RuntimeException('REMOVAL_TEST_DSN is required');
}
$connect = static fn() => new PDO($dsn, getenv('REMOVAL_TEST_USER'), getenv('REMOVAL_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owner = $connect();
$writer = $connect();
$schema = 'removal_lock_probe_' . bin2hex(random_bytes(8));
$created = false;
try {
    $owner->exec("CREATE DATABASE `$schema`");
    $created = true;
    foreach ([$owner, $writer] as $db) {
        $db->exec("USE `$schema`");
    }
    // Use the real schema definitions, including storage engines and indexes.
    $schemaSource = file_get_contents(__DIR__ . '/../../cacti.sql');
    foreach (['graph_local', 'data_local', 'graph_templates_item', 'data_template_rrd', 'aggregate_graphs', 'aggregate_graphs_items'] as $table) {
        if (!preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $schemaSource, $match)) {
            throw new RuntimeException('Fixture schema unavailable');
        }
        $owner->exec($match[0]);
    }
    $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $device = new DeviceState(7, 'fixture', 'fixture.invalid', true, 0, 1, 0);
    foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
        foreach ([false, true] as $populated) {
            foreach (['graph_local', 'data_local'] as $table) {
                $owner->exec("DELETE FROM $table");
                $owner->exec("INSERT INTO $table (id, host_id) VALUES (1, 8)");
                if ($populated) {
                    $owner->exec("INSERT INTO $table (id, host_id) VALUES (2, 7)");
                }
            }
            $owner->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolation);
            $owner->beginTransaction();
            DeviceRemovalSnapshot::read($owner, $device, true);
            foreach (['graph_local', 'data_local'] as $table) {
                foreach (["INSERT INTO $table (id, host_id) VALUES (3, 7)", "UPDATE $table SET host_id = 7 WHERE id = 1"] as $sql) {
                    $writer->beginTransaction();
                    $blocked = false;
                    try {
                        $writer->exec($sql);
                    } catch (PDOException $error) {
                        if (($error->errorInfo[1] ?? null) !== 1205) {
                            throw $error;
                        }
                        $blocked = true;
                    } finally {
                        $writer->rollBack();
                    }
                    if ($blocked !== ($isolation === 'REPEATABLE READ')) {
                        throw new RuntimeException('Unexpected association lock result');
                    }
                }
            }
            $owner->rollBack();
        }
    }
    for ($id = 1; $id <= 200; ++$id) {
        $owner->exec("INSERT INTO data_template_rrd (id, local_data_id) VALUES ($id, $id)");
        $owner->exec("INSERT INTO graph_templates_item (id, local_graph_id, task_item_id) VALUES ($id, $id, $id)");
    }
    $owner->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $owner->beginTransaction();
    if (!DeviceRemovalDependencies::exclusive($owner, [1], [1])) {
        throw new RuntimeException('Owned dependency rejected');
    }
    $writer->beginTransaction();
    try {
        $writer->exec('INSERT INTO graph_templates_item (local_graph_id, task_item_id) VALUES (150, 150)');
    } finally {
        $writer->rollBack();
    }
    foreach (['(1, 150)', '(150, 1)'] as $reference) {
        $writer->beginTransaction();
        $blocked = false;
        try {
            $writer->exec('INSERT INTO graph_templates_item (local_graph_id, task_item_id) VALUES ' . $reference);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1205) {
                throw $error;
            }
            $blocked = true;
        } finally {
            $writer->rollBack();
        }
        if (!$blocked) {
            throw new RuntimeException('Reviewed graph/data reference was not locked');
        }
    }
    $owner->rollBack();
    echo 'PASS: association insert/reassignment cases and unrelated graph writer, server ' . $owner->query('SELECT VERSION()')->fetchColumn() . "\n";
} finally {
    foreach ([$owner, $writer] as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    if ($created) {
        $owner->exec("DROP DATABASE `$schema`");
    }
}
