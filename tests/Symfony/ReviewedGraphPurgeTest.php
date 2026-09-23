<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ReviewedGraphPurge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_graph.php');
// Fixed first-party function only; no request or external input is executable.
eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source($source, 'api_delete_graphs')); // nosemgrep: php.lang.security.eval-use.eval-use

function api_graph_remove_bad_graphs(&$ids) {}
function api_graph_remove_aggregate_items($ids) {}
function cacti_sizeof($value)
{
    return count($value);
}
function array_to_sql_or($ids, $column)
{
    return $column . ' IN (' . implode(',', $ids) . ')';
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function db_fetch_assoc($sql)
{
    $phase = $GLOBALS['reviewed_graph_query']++;
    return $phase < 2 ? array_map(static fn($id) => ['local_data_id' => $id], $GLOBALS['reviewed_graph_discovered']) : [];
}
function api_data_source_remove_multi($ids, $propagateRemote = true)
{
    $GLOBALS['reviewed_graph_deleted_data'] = array_values($ids);
    $GLOBALS['reviewed_graph_propagate_remote'] = $propagateRemote;
}
function api_graph_remove_multi($ids)
{
    $GLOBALS['reviewed_graph_deleted_graphs'] = $ids;
}
function set_config_option($key, $value)
{
    $GLOBALS['reviewed_graph_marker'] = $key;
}

final class ReviewedGraphPurgeTest extends TestCase
{
    #[DataProvider('scopes')]
    public function testPurgeCannotDiscoverUnreviewedSources(array $discovered, ?array $reviewed, bool $accepted): void
    {
        $GLOBALS['reviewed_graph_query'] = 0;
        $GLOBALS['reviewed_graph_discovered'] = $discovered;
        $GLOBALS['reviewed_graph_deleted_data'] = [];
        $GLOBALS['reviewed_graph_deleted_graphs'] = [];
        $GLOBALS['reviewed_graph_marker'] = null;
        $GLOBALS['reviewed_graph_propagate_remote'] = null;
        $graphs = [7];
        try {
            api_delete_graphs($graphs, 2, $reviewed);
            self::assertTrue($accepted, 'Unreviewed data reached the destructive lifecycle');
        } catch (RuntimeException $error) {
            self::assertFalse($accepted);
            self::assertSame('Graph data-source scope changed', $error->getMessage());
        }
        self::assertSame($accepted ? $discovered : [], $GLOBALS['reviewed_graph_deleted_data']);
        self::assertSame($accepted ? [7] : [], $GLOBALS['reviewed_graph_deleted_graphs']);
        self::assertSame($accepted ? 'time_last_change_graph' : null, $GLOBALS['reviewed_graph_marker']);
        if ($accepted && $discovered !== []) {
            self::assertSame($reviewed === null, $GLOBALS['reviewed_graph_propagate_remote']);
        }
    }

    public static function scopes(): iterable
    {
        yield 'new link to outside source' => [[10, 99], [10], false];
        yield 'new link on empty reviewed scope' => [[99], [], false];
        yield 'reviewed source' => [[10], [10], true];
        yield 'ungraphed reviewed source remains for later cleanup' => [[10], [10, 20], true];
        yield 'no source links' => [[], [], true];
        yield 'legacy caller retains discovery behavior' => [[10, 99], null, true];
    }
}
