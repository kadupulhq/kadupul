<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ReviewedAggregateRemoval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_graph.php');
foreach (['api_graph_remove_aggregate_items', 'api_graph_remove_multi'] as $function) {
    // Fixed first-party function bodies; no request or external executable input.
    eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function api_graph_remove_bad_graphs(&$ids) {}
function set_config_option($key, $value) {}
function db_execute($sql)
{
    $GLOBALS['aggregate_writes'][] = $sql;
}
function api_aggregate_disassociate($aggregate, $graph)
{
    $GLOBALS['aggregate_detached'][] = [$aggregate, $graph];
}
function api_plugin_hook_function($name, $ids)
{
    $GLOBALS['aggregate_live'] = $GLOBALS['aggregate_injected'];
}
function db_fetch_assoc_prepared($sql, $parameters)
{
    $table = str_contains($sql, 'FROM aggregate_graphs_items') ? 'aggregate_graphs_items' : 'aggregate_graphs';
    if (str_contains($sql, 'FOR UPDATE')) {
        $GLOBALS['aggregate_locks'][] = [$table, $parameters];
    }
    return $GLOBALS['aggregate_live'] === $table ? [['local_graph_id' => 7, 'aggregate_graph_id' => 11]] : [];
}

final class ReviewedAggregateRemovalTest extends TestCase
{
    #[DataProvider('memberships')]
    public function testRechecksAfterHookBeforeDisassociation(?string $table, int $count): void
    {
        $GLOBALS['aggregate_live'] = null;
        $GLOBALS['aggregate_injected'] = $table;
        $GLOBALS['aggregate_writes'] = $GLOBALS['aggregate_detached'] = $GLOBALS['aggregate_locks'] = [];
        try {
            api_graph_remove_multi(range(1, $count), true);
            self::assertNull($table, 'Changed aggregate membership must fail closed');
        } catch (RuntimeException $error) {
            self::assertNotNull($table);
            self::assertSame('Graph aggregate scope changed', $error->getMessage());
        }
        self::assertSame([], $GLOBALS['aggregate_detached']);
        self::assertNotEmpty($GLOBALS['aggregate_locks']);
        self::assertCount($table === null ? ($count > 1000 ? 10 : 5) : 0, $GLOBALS['aggregate_writes']);
    }

    public static function memberships(): iterable
    {
        foreach ([1, 1001] as $count) {
            yield "unchanged $count" => [null, $count];
            yield "aggregate parent $count" => ['aggregate_graphs', $count];
            yield "aggregate member $count" => ['aggregate_graphs_items', $count];
        }
    }

    public function testLegacyDisassociationRemainsAvailable(): void
    {
        $GLOBALS['aggregate_live'] = 'aggregate_graphs_items';
        $GLOBALS['aggregate_detached'] = [];
        api_graph_remove_aggregate_items([7]);
        self::assertSame([[11, 7]], $GLOBALS['aggregate_detached']);
    }
}
