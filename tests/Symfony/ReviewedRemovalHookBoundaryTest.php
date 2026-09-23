<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ReviewedRemovalHooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
foreach (['api_device.php' => ['api_device_remove_multi'], 'api_graph.php' => ['api_delete_graphs', 'api_graph_remove_multi'], 'api_data_source.php' => ['api_data_source_remove_multi']] as $file => $functions) {
    $source = file_get_contents(__DIR__ . '/../../lib/' . $file);
    foreach ($functions as $function) {
        // Fixed first-party lifecycle bodies; no external executable input.
        eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}
function api_graph_remove_bad_graphs(&$ids) {}
function api_graph_remove_aggregate_items($ids, $reject = false) {}
function cacti_sizeof($rows)
{
    return count($rows);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function array_to_sql_or($ids, $column)
{
    return $column . ' IN (' . implode(',', $ids) . ')';
}
function read_config_option($key)
{
    return '';
}
function cacti_log($message) {}
function set_config_option($key, $value) {}
function db_fetch_assoc($sql)
{
    return $GLOBALS['hook_boundary_reads']++ < 2 ? [['local_data_id' => 10]] : [];
}
function db_fetch_cell_prepared($sql, $parameters)
{
    return $GLOBALS['hook_boundary_poller'];
}
function db_execute($sql)
{
    $GLOBALS['hook_boundary_writes'][] = $sql;
    return true;
}
function api_plugin_hook_function($name, $ids)
{
    $GLOBALS['hook_boundary_events'][] = $name;
    if ($name === $GLOBALS['hook_boundary_inject']) {
        $GLOBALS['hook_boundary_changed'] = true;
    }
}

final class ReviewedRemovalHookBoundaryTest extends TestCase
{
    public function testDeviceHookOwnershipChangeStopsBeforeAnyWrite(): void
    {
        $GLOBALS['hook_boundary_writes'] = $GLOBALS['hook_boundary_events'] = [];
        $GLOBALS['hook_boundary_inject'] = 'device_remove';
        $GLOBALS['hook_boundary_changed'] = false;
        try {
            api_device_remove_multi([7], 2, [], [], static function (): void {
                self::assertTrue($GLOBALS['hook_boundary_changed']);
                throw new RuntimeException('Ownership changed');
            });
            self::fail('Changed ownership was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Ownership changed', $error->getMessage());
        }
        self::assertSame([], $GLOBALS['hook_boundary_writes']);
    }

    #[DataProvider('collectorChanges')]
    public function testCollectorHookCannotRedirectRemoval(int $current, array $connections): void
    {
        $GLOBALS['hook_boundary_writes'] = $GLOBALS['hook_boundary_events'] = [];
        $GLOBALS['hook_boundary_inject'] = 'device_remove';
        $GLOBALS['hook_boundary_poller'] = $current;
        try {
            api_device_remove_multi([7], 2, ['by_device' => [7 => ['poller_id' => 2]]], $connections, static function (): void {});
            self::fail('Unreviewed collector accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Reviewed collector ownership changed', $error->getMessage());
        }
        self::assertSame([], $GLOBALS['hook_boundary_writes']);
    }

    public static function collectorChanges(): iterable
    {
        yield 'hook moves device to another remote' => [3, []];
        yield 'hook moves device to primary' => [1, []];
        yield 'missing reviewed transaction' => [2, []];
    }

    #[DataProvider('boundaries')]
    public function testReviewedDependencyPolicyRunsAfterEachRemovalHook(?string $hook): void
    {
        $GLOBALS['hook_boundary_reads'] = 0;
        $GLOBALS['hook_boundary_writes'] = $GLOBALS['hook_boundary_events'] = [];
        $GLOBALS['hook_boundary_inject'] = $hook;
        $GLOBALS['hook_boundary_changed'] = false;
        $graphs = [7];
        try {
            api_delete_graphs($graphs, 2, [10], static function (): void {
                $GLOBALS['hook_boundary_events'][] = 'verify';
                if ($GLOBALS['hook_boundary_changed']) {
                    throw new RuntimeException('Graph data-source scope changed');
                }
            });
            self::assertNull($hook);
        } catch (RuntimeException $error) {
            self::assertNotNull($hook);
            self::assertSame('Graph data-source scope changed', $error->getMessage());
            foreach ($GLOBALS['hook_boundary_writes'] as $sql) {
                self::assertStringNotContainsString('DELETE FROM graph_', $sql);
            }
            if ($hook === 'data_source_remove') {
                self::assertSame([], $GLOBALS['hook_boundary_writes']);
            }
        }
        self::assertSame($hook === 'data_source_remove' ? ['data_source_remove', 'verify'] : ['data_source_remove', 'verify', 'graphs_remove', 'verify'], $GLOBALS['hook_boundary_events']);
    }

    public static function boundaries(): iterable
    {
        yield 'source hook changes scope' => ['data_source_remove'];
        yield 'graph hook changes scope' => ['graphs_remove'];
        yield 'unchanged dependencies proceed' => [null];
    }
}
