<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\HostStatusUpdateScope;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 2) . '/include/global_constants.php';

function read_config_option(string $name)
{
    return ['ping_failure_count' => 1, 'ping_recovery_count' => 1][$name] ?? '';
}

function cacti_log(...$args): void {}

function db_fetch_row_prepared(string $query, array $params): array
{
    return $GLOBALS['host_status_scope']['rows'][$params[0]];
}

/*
 * Applies the UPDATE the way MySQL would: to every live row whose key column
 * matches, so a non-unique key shows up as a write to the wrong device.
 */
function db_execute_prepared(string $query, array $params): bool
{
    if (!preg_match('/WHERE\s+(\w+)\s*=\s*\?/', $query, $where) || !preg_match_all('/(\w+)\s*=\s*(?:FROM_UNIXTIME\()?\?/', $query, $set)) {
        throw new \RuntimeException('Unexpected host status query: ' . $query);
    }

    $key     = array_pop($params);
    $columns = array_slice($set[1], 0, count($params));

    foreach ($GLOBALS['host_status_scope']['rows'] as $id => $row) {
        if ((string) $row[$where[1]] === (string) $key && $row['deleted'] === '') {
            foreach ($columns as $i => $column) {
                $GLOBALS['host_status_scope']['rows'][$id][$column] = $params[$i];
            }
        }
    }

    return true;
}

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
if ($source === false) {
    throw new \RuntimeException('Unable to read lib/functions.php');
}

eval('namespace Kadupul\\Tests\\HostStatusUpdateScope;' . \test_php_function_source($source, 'update_host_status')); // nosemgrep: php.lang.security.eval-use.eval-use

final class HostStatusUpdateScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $device = [
            'hostname'           => 'shared.example.net',
            'deleted'            => '',
            'status'             => HOST_UP,
            'status_event_count' => 0,
            'status_fail_date'   => '',
            'status_rec_date'    => '',
            'status_last_error'  => '',
            'snmp_community'     => '',
            'snmp_version'       => 0,
            'min_time'           => 1,
            'max_time'           => 1,
            'cur_time'           => 1,
            'avg_time'           => 1,
            'total_polls'        => 10,
            'failed_polls'       => 0,
            'availability'       => 100,
        ];

        $GLOBALS['host_status_scope'] = [
            'rows' => [
                1 => ['id' => 1] + $device,
                2 => ['id' => 2] + $device,
            ],
        ];
    }

    public function testDownDeviceDoesNotChangeAnotherDeviceWithTheSameHostname(): void
    {
        $ping = (object) ['ping_response' => 'timed out', 'snmp_response' => '', 'ping_status' => 'down', 'snmp_status' => 'down'];

        update_host_status(HOST_DOWN, 1, $ping, AVAIL_PING, false);

        $rows = $GLOBALS['host_status_scope']['rows'];
        $this->assertSame(HOST_DOWN, (int) $rows[1]['status']);
        $this->assertSame(11, (int) $rows[1]['total_polls']);
        $this->assertSame(HOST_UP, (int) $rows[2]['status']);
        $this->assertSame(10, (int) $rows[2]['total_polls']);
        $this->assertSame(100, (int) $rows[2]['availability']);
    }
}
