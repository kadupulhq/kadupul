<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\TemplateDispatcher;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_device.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the template helper');
}
// Extract only a fixed first-party function, without executing request input.
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'api_device_update_host_template')); // nosemgrep: php.lang.security.eval-use.eval-use

function db_execute_prepared($sql, $parameters): bool
{
    return true;
}
function db_fetch_cell_prepared($sql, $parameters): int
{
    return 1;
}
function db_fetch_assoc_prepared($sql, $parameters): array
{
    return str_contains($sql, 'SELECT snmp_query_id') ? [['snmp_query_id' => 12]] : [];
}
function cacti_sizeof($rows): int
{
    return count($rows);
}
function read_config_option($name): int
{
    return 1;
}
function run_data_query($device, $query): bool
{
    $GLOBALS['template_dispatcher_discovery'][] = [$device, $query];
    return true;
}
function api_plugin_hook_function($name, $arguments): void
{
    $GLOBALS['template_dispatcher_hooks'][] = [$name, $arguments];
}

final class DeviceTemplateDispatcherTest extends TestCase
{
    public function testLegacyDefaultRemainsSynchronousAndVoid(): void
    {
        $GLOBALS['template_dispatcher_discovery'] = [];
        $GLOBALS['template_dispatcher_hooks'] = [];
        self::assertNull(api_device_update_host_template(7, 3));
        self::assertSame([[7, 12]], $GLOBALS['template_dispatcher_discovery']);
        self::assertSame([['device_template_change', ['device_id' => 7, 'device_template_id' => 3]]], $GLOBALS['template_dispatcher_hooks']);
    }

    public function testExplicitDispatcherReceivesDiscoveryWithoutRunningIt(): void
    {
        $GLOBALS['template_dispatcher_discovery'] = [];
        $GLOBALS['template_dispatcher_hooks'] = [];
        $pending = [];
        self::assertNull(api_device_update_host_template(7, 3, static function ($device, $query) use (&$pending): void {
            $pending[] = [$device, $query];
        }));
        self::assertSame([[7, 12]], $pending);
        self::assertSame([], $GLOBALS['template_dispatcher_discovery']);
        self::assertSame([['device_template_change', ['device_id' => 7, 'device_template_id' => 3]]], $GLOBALS['template_dispatcher_hooks']);
    }
}
