<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ReviewedRemotePurge;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_device.php');
// Fixed first-party function only; no request or external input is executable.
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'api_device_purge_from_remote')); // nosemgrep: php.lang.security.eval-use.eval-use

const POLLER_COMMAND_PURGE = 99;
function remote_poller_up($id)
{
    return true;
}
function poller_push_to_remote_db_connect($id, $force)
{
    return $GLOBALS['reviewed_remote_db'];
}
function cacti_sizeof($value)
{
    return count($value);
}
function db_execute($sql, $log, $connection)
{
    return $connection->exec($sql) !== false;
}
function db_execute_prepared($sql, $parameters)
{
    return true;
}

final class ReviewedRemotePurgeTest extends TestCase
{
    #[DataProvider('modes')]
    public function testReviewedRemoteCleanupPreservesLateUnreviewedChildren(bool $reviewed): void
    {
        $db = new PDO('sqlite::memory:');
        $GLOBALS['reviewed_remote_db'] = $db;
        $db->sqliteCreateFunction('SUBSTRING_INDEX', static fn($text, $separator, $count) => explode($separator, $text)[0]);
        foreach ([
            'host' => 'id INTEGER', 'host_graph' => 'host_id INTEGER', 'host_snmp_query' => 'host_id INTEGER',
            'host_snmp_cache' => 'host_id INTEGER', 'poller_item' => 'host_id INTEGER, local_data_id INTEGER',
            'poller_reindex' => 'host_id INTEGER', 'graph_tree_items' => 'host_id INTEGER, local_graph_id INTEGER',
            'reports_items' => 'host_id INTEGER, local_graph_id INTEGER', 'poller_command' => 'command TEXT',
            'data_local' => 'id INTEGER, host_id INTEGER', 'graph_local' => 'id INTEGER, host_id INTEGER',
        ] as $table => $columns) {
            $db->exec("CREATE TABLE $table ($columns)");
        }
        $db->exec('INSERT INTO host VALUES (7); INSERT INTO data_local VALUES (12,7),(90,99); INSERT INTO graph_local VALUES (11,7),(91,99); INSERT INTO poller_item VALUES (7,12),(99,90)');
        foreach (['graph_tree_items', 'reports_items'] as $table) {
            $db->exec("INSERT INTO $table VALUES (7,0),(7,11),(99,91)");
        }
        // Insert after scope preflight, during the legacy lifecycle itself.
        $db->exec('CREATE TRIGGER late_children AFTER DELETE ON host BEGIN INSERT INTO data_local VALUES (13,7); INSERT INTO graph_local VALUES (14,7); INSERT INTO poller_item VALUES (7,13); INSERT INTO graph_tree_items VALUES (7,14); INSERT INTO reports_items VALUES (7,14); END');
        api_device_purge_from_remote([7], 2, $reviewed ? [7 => ['graphs' => [11], 'data_sources' => [12]]] : null);
        self::assertSame($reviewed ? [13, 90] : [90], array_map('intval', $db->query('SELECT local_data_id FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_COLUMN)));
        foreach (['graph_tree_items', 'reports_items'] as $table) {
            self::assertSame($reviewed ? [14, 91] : [91], array_map('intval', $db->query("SELECT local_graph_id FROM $table ORDER BY local_graph_id")->fetchAll(PDO::FETCH_COLUMN)));
        }
        self::assertSame($reviewed ? [13, 90] : [90], array_map('intval', $db->query('SELECT id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame($reviewed ? [14, 91] : [91], array_map('intval', $db->query('SELECT id FROM graph_local ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        unset($GLOBALS['reviewed_remote_db']);
    }

    public static function modes(): iterable
    {
        yield 'reviewed lifecycle preserves late children' => [true];
        yield 'legacy unscoped behavior preserved' => [false];
    }
}
