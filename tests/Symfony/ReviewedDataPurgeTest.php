<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ReviewedDataPurge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_data_source.php');
// Fixed first-party function only; no request or external input is executable.
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'api_data_source_remove_multi')); // nosemgrep: php.lang.security.eval-use.eval-use

function cacti_sizeof($value)
{
    return count($value);
}
function api_plugin_hook_function($name, $ids) {}
function read_config_option($key)
{
    return '';
}
function cacti_log($message) {}
function get_remote_poller_ids_from_data_sources($ids)
{
    $GLOBALS['reviewed_data_discovery']++;
    return [2, 3];
}
function db_fetch_assoc($sql)
{
    return array_map(static fn($id) => ['id' => $id], range(1, 1001));
}
function db_execute($sql, $log = true, $connection = null)
{
    $GLOBALS['reviewed_data_writes'][] = [$sql, $connection];
    return true;
}
function poller_push_to_remote_db_connect($id, $force)
{
    return 'collector-' . $id;
}
function api_data_source_cache_crc_update($id) {}

final class ReviewedDataPurgeTest extends TestCase
{
    #[DataProvider('modes')]
    public function testScopedPurgeNeverDiscoversOrWritesRemoteCollectors(?bool $propagate): void
    {
        $GLOBALS['reviewed_data_discovery'] = 0;
        $GLOBALS['reviewed_data_writes'] = [];
        if ($propagate === null) {
            api_data_source_remove_multi([10, 20]);
        } else {
            api_data_source_remove_multi([10, 20], $propagate);
        }
        $remote = array_values(array_filter($GLOBALS['reviewed_data_writes'], static fn($write) => $write[1] !== null));
        self::assertNotEmpty($GLOBALS['reviewed_data_writes'], 'Primary cleanup must still execute');
        self::assertSame($propagate === false ? 0 : 1, $GLOBALS['reviewed_data_discovery']);
        if ($propagate === false) {
            self::assertSame([], $remote);
        } else {
            self::assertSame(['collector-2', 'collector-3'], array_values(array_unique(array_column($remote, 1))));
        }
    }

    public static function modes(): iterable
    {
        yield 'reviewed lifecycle owns remote cleanup' => [false];
        yield 'explicit legacy propagation' => [true];
        yield 'existing callers preserve propagation' => [null];
    }
}
