<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RemoteDiagnosticBoundaryTest extends TestCase
{
    #[DataProvider('operations')]
    public function testCollectorBoundaryNeverReturnsRawCredentials(string $operation, bool $failure, bool $skip = false): void
    {
        $script = <<<'SCRIPT'
require 'include/vendor/autoload.php';
$source = file_get_contents('remote_agent.php');
$start = strpos($source, 'function remote_inventory_diagnostics(');
$end = strpos($source, 'function debug(', $start);
eval(substr($source, $start, $end - $start));
function get_filter_request_var($name) { return 7; }
function diagnostic_fixture() {
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticScope::remember(['snmp_password' => 'before-rotation']);
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticScope::remember(['snmp_password' => 'after-rotation']);
    $_SESSION['debug_log']['data_query'] = ['before-rotation after-rotation'];
    $GLOBALS['config']['debug_log'] = $_SESSION['debug_log'];
    if ($GLOBALS['failure']) { echo 'after-rotation'; throw new RuntimeException('before-rotation'); }
}
function ping_device() { diagnostic_fixture(); echo 'before-rotation after-rotation'; }
function run_data_query($id, $query) { if ($GLOBALS['skip']) { return true; } diagnostic_fixture(); echo json_encode(['result' => true, 'data_query' => $_SESSION['debug_log']['data_query']]); return true; }
$_SESSION['debug_log']['data_query'] = ['previous-secret'];
$config['debug_log']['data_query'] = ['previous-secret'];
http_response_code(200);
ob_start();
remote_inventory_diagnostics($operation);
$body = ob_get_clean();
echo json_encode(['status' => http_response_code(), 'body' => json_decode($body, true, 32, JSON_THROW_ON_ERROR), 'session_clean' => !isset($_SESSION['debug_log']), 'config_clean' => !isset($config['debug_log'])], JSON_THROW_ON_ERROR);
SCRIPT;
        $process = new Process([PHP_BINARY, '-r', '$operation=' . var_export($operation, true) . ';$failure=' . var_export($failure, true) . ';$skip=' . var_export($skip, true) . ';' . $script], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertStringNotContainsString('previous-secret', $process->getOutput());
        self::assertStringNotContainsString('before-rotation', $process->getOutput());
        self::assertStringNotContainsString('after-rotation', $process->getOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($failure ? 502 : 200, $result['status']);
        self::assertTrue($result['session_clean']);
        self::assertTrue($result['config_clean']);
        if ($failure) {
            self::assertSame(['error' => 'diagnostics_unavailable'], $result['body']);
        } else {
            self::assertTrue($result['body']['diagnostics_sanitized']);
            if ($skip) {
                self::assertSame([], $result['body']['data_query']);
            } else {
                self::assertSame('[redacted] [redacted]', $operation === 'ping' ? $result['body']['output'] : $result['body']['data_query'][0]);
            }
        }
    }

    public static function operations(): iterable
    {
        yield 'early-return query discards stale diagnostics' => ['runquery', false, true];
        foreach (['ping', 'runquery'] as $operation) {
            foreach ([false, true] as $failure) {
                yield [$operation, $failure];
            }
        }
    }
}
