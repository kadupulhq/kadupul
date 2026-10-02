<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\RemoteDataQueryHandoff;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/data_query.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read production data-query source');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'run_data_query')); // nosemgrep: php.lang.security.eval-use.eval-use

const HOST_DOWN = 1;

final class RemoteQueryFixture
{
    public static string $response;
    public static string $timeout = '30';
    /** @var list<array{url: string, timeout: int, allowed: list<string>}> */
    public static array $http = [];
    /** @var list<array{host: int, query: int}> */
    public static array $automation = [];
    /** @var list<array{hook: string, arguments: array{host_id: int, snmp_query_id: int}}> */
    public static array $hooks = [];
}

function read_config_option(string $name): string
{
    return $name === 'remote_agent_timeout' ? RemoteQueryFixture::$timeout : '';
}
function db_column_exists(string $table, string $column): bool
{
    if ($table !== 'host' || $column !== 'poller_id') {
        throw new RuntimeException('Unexpected schema lookup');
    }
    return true;
}
function db_fetch_row_prepared(string $sql, array $parameters): array
{
    if (!str_contains($sql, 'SELECT status, disabled, poller_id') || $parameters !== [7]) {
        throw new RuntimeException('Unexpected device lookup');
    }
    return ['status' => 3, 'disabled' => '', 'poller_id' => 2];
}
function db_fetch_cell_prepared(string $sql, array $parameters): string
{
    if (!str_contains($sql, 'SELECT hostname') || !str_contains($sql, 'FROM poller') || $parameters !== [2]) {
        throw new RuntimeException('Unexpected collector lookup');
    }
    return 'collector.invalid';
}
function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}
function get_url_type(): string
{
    return 'https';
}
function cacti_http(string $url, int $timeout, array $allowed): string
{
    RemoteQueryFixture::$http[] = ['url' => $url, 'timeout' => $timeout, 'allowed' => $allowed];
    return RemoteQueryFixture::$response;
}
function automation_execute_data_query(int $host, int $query): bool
{
    RemoteQueryFixture::$automation[] = ['host' => $host, 'query' => $query];
    return true;
}
function api_plugin_hook_function(string $hook, array $arguments): array
{
    RemoteQueryFixture::$hooks[] = ['hook' => $hook, 'arguments' => $arguments];
    return $arguments;
}
function query_debug_timer_offset(string $section, string $message): void {}
function __esc(string $message): string
{
    return $message;
}

final class RemoteDataQueryHandoffTest extends TestCase
{
    /** @var array<string, array{present: bool, value: mixed}> */
    private array $globals = [];

    protected function setUp(): void
    {
        foreach (['config', '_SESSION', 'input_types'] as $name) {
            $this->globals[$name] = ['present' => array_key_exists($name, $GLOBALS), 'value' => $GLOBALS[$name] ?? null];
        }
        $GLOBALS['config'] = ['poller_id' => 1, 'url_path' => '/kadupul/'];
        $GLOBALS['_SESSION'] = ['debug_log' => ['data_query' => ['previous diagnostic'], 'response' => 'previous response']];
        $GLOBALS['input_types'] = [];
        RemoteQueryFixture::$http = [];
        RemoteQueryFixture::$timeout = '30';
        RemoteQueryFixture::$automation = [];
        RemoteQueryFixture::$hooks = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->globals as $name => $previous) {
            if ($previous['present']) {
                $GLOBALS[$name] = $previous['value'];
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }

    #[DataProvider('sanitizationMarkers')]
    public function testValidResponsePreservesDiagnosticsAndStrictSanitizationMarker(array $marker, bool $expected): void
    {
        $_SESSION['inventory_diagnostics_sanitized'] = true;
        RemoteQueryFixture::$response = json_encode(['result' => true, 'data_query' => ['interface eth0', 'interface eth1']] + $marker, JSON_THROW_ON_ERROR);
        self::assertTrue(run_data_query(7, 4));
        self::assertSame(['interface eth0', 'interface eth1'], $_SESSION['debug_log']['data_query']);
        self::assertSame($expected, $_SESSION['inventory_diagnostics_sanitized']);
        self::assertArrayNotHasKey('response', $_SESSION['debug_log']);
        self::assertSame([['host' => 7, 'query' => 4]], RemoteQueryFixture::$automation);
        self::assertSame([['hook' => 'run_data_query', 'arguments' => ['host_id' => 7, 'snmp_query_id' => 4]]], RemoteQueryFixture::$hooks);
        $this->assertRemoteRequest();
    }

    public static function sanitizationMarkers(): iterable
    {
        yield 'strict true' => [['diagnostics_sanitized' => true], true];
        yield 'false' => [['diagnostics_sanitized' => false], false];
        yield 'integer one' => [['diagnostics_sanitized' => 1], false];
        yield 'string true' => [['diagnostics_sanitized' => 'true'], false];
        yield 'null' => [['diagnostics_sanitized' => null], false];
        yield 'missing' => [[], false];
    }

    public function testConfiguredRemoteTimeoutAlwaysFitsTheAssociationWorkerBudget(): void
    {
        foreach (['-5' => 1, '0' => 1, '30' => 30, '120' => 120, '300' => 300, '600' => 300] as $configured => $expected) {
            RemoteQueryFixture::$timeout = (string) $configured;
            RemoteQueryFixture::$response = '';
            RemoteQueryFixture::$http = [];
            self::assertFalse(run_data_query(7, 4));
            self::assertSame($expected, RemoteQueryFixture::$http[0]['timeout']);
            self::assertGreaterThanOrEqual($expected + 120, \Kadupul\Inventory\Infrastructure\Legacy\DeviceWorkerTimeout::forRemoteCalls(1));
        }
    }

    #[DataProvider('invalidResponses')]
    public function testMalformedOrPreUpgradeResponseCannotRunAutomation(string $response): void
    {
        RemoteQueryFixture::$response = $response;
        self::assertFalse(run_data_query(7, 4));
        self::assertSame([], RemoteQueryFixture::$automation);
        self::assertSame([], RemoteQueryFixture::$hooks);
        self::assertSame(['previous diagnostic'], $_SESSION['debug_log']['data_query']);
        self::assertArrayNotHasKey('inventory_diagnostics_sanitized', $_SESSION);
        $this->assertRemoteRequest();
    }

    public static function invalidResponses(): iterable
    {
        yield 'pre-upgrade empty response' => [''];
        yield 'non-JSON response' => ['not JSON'];
        yield 'JSON null' => ['null'];
        yield 'missing result' => ['{"data_query":[]}'];
        yield 'missing diagnostics' => ['{"result":true}'];
        yield 'scalar diagnostics' => ['{"result":true,"data_query":"interface eth0"}'];
        yield 'null diagnostics' => ['{"result":true,"data_query":null}'];
        yield 'integer result' => ['{"result":1,"data_query":[]}'];
        yield 'string result' => ['{"result":"true","data_query":[]}'];
    }

    private function assertRemoteRequest(): void
    {
        self::assertSame([['url' => 'https://collector.invalid/kadupul/remote_agent.php?action=runquery&host_id=7&data_query_id=4', 'timeout' => 30, 'allowed' => ['collector.invalid']]], RemoteQueryFixture::$http);
    }
}
