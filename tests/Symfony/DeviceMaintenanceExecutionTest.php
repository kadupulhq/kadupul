<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy {
    final class MaintenanceProbeFixture
    {
        public static array $calls = [];
        public static string|false $output = false;
        public static string $address = '10.20.30.40';
    }
    function cacti_http($url, $timeout, array $allowlist, &$status = 0, $connect = ''): string|false
    {
        MaintenanceProbeFixture::$calls[] = [$url, $timeout, $allowlist, $connect];
        return MaintenanceProbeFixture::$output;
    }
    function gethostbyname(string $name): string
    {
        return MaintenanceProbeFixture::$address;
    }
    function get_url_type(): string
    {
        return 'https';
    }
    function read_config_option(string $key): string
    {
        return '15';
    }

    function run_data_query(int $id, int $query): bool
    {
        $_SESSION['debug_log']['data_query'] = ['fixture-secret'];
        if ($query === 4) {
            throw new \RuntimeException('Discovery failed');
        }
        return false;
    }
    function debug_log_return(string $kind): string
    {
        return '<b>Failed</b><br>fixture-secret';
    }
}

namespace Kadupul\Tests {
    use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
    use Kadupul\Inventory\Domain\DeviceMaintenanceState;
    use Kadupul\Inventory\Domain\DeviceState;
    use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceExecutor;
    use PHPUnit\Framework\TestCase;

    final class DeviceMaintenanceExecutionTest extends TestCase
    {
        public function testRemoteProbeUsesHardenedAllowlistedTransportAndRedactsBothHosts(): void
        {
            $primary = new \PDO('sqlite::memory:');
            $primary->exec("CREATE TABLE poller (id INTEGER, hostname TEXT); INSERT INTO poller VALUES (2, 'collector.example.test')");
            $remote = new \PDO('sqlite::memory:');
            $remote->exec("CREATE TABLE host (id INTEGER, poller_id INTEGER, deleted TEXT, snmp_community TEXT, snmp_username TEXT, snmp_password TEXT, snmp_priv_passphrase TEXT); INSERT INTO host VALUES (7,2,'','','','remote-secret','')");
            $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], [], false);
            \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$calls = [];
            \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$output = json_encode(['diagnostics_sanitized' => true, 'output' => 'primary-secret remote-secret'], JSON_THROW_ON_ERROR);
            $executor = new DeviceMaintenanceExecutor('/kadupul/');
            $result = $executor->execute($primary, $remote, $state, new DeviceMaintenanceRequest('connectivity'), ['snmp_password' => 'primary-secret']);
            self::assertSame('[redacted] [redacted]', $result->output);
            self::assertSame([['https://collector.example.test/kadupul/remote_agent.php?action=ping&safe_diagnostics=1&host_id=7', 15, ['collector.example.test'], '10.20.30.40']], \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$calls);
            foreach (['FATAL: Client authorization failed', '{"output":"unsafe-secret"}', '{"diagnostics_sanitized":false,"output":"unsafe-secret"}'] as $invalid) {
                \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$output = $invalid;
                try {
                    $executor->execute($primary, $remote, $state, new DeviceMaintenanceRequest('connectivity'), []);
                    self::fail('Untrusted remote response was accepted');
                } catch (\RuntimeException|\JsonException $error) {
                    self::assertStringNotContainsString('unsafe-secret', $error->getMessage());
                }
            }
            \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$output = false;
            $this->expectException(\RuntimeException::class);
            $executor->execute($primary, $remote, $state, new DeviceMaintenanceRequest('connectivity'), []);
        }

        public function testRemoteProbeRefusesACollectorThatResolvesToAReservedAddress(): void
        {
            $primary = new \PDO('sqlite::memory:');
            $primary->exec("CREATE TABLE poller (id INTEGER, hostname TEXT); INSERT INTO poller VALUES (2, 'collector.example.test')");
            $remote = new \PDO('sqlite::memory:');
            $remote->exec("CREATE TABLE host (id INTEGER, poller_id INTEGER, deleted TEXT, snmp_community TEXT, snmp_username TEXT, snmp_password TEXT, snmp_priv_passphrase TEXT); INSERT INTO host VALUES (7,2,'','','','','')");
            $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], [], false);
            \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$output = json_encode(['diagnostics_sanitized' => true, 'output' => 'reached'], JSON_THROW_ON_ERROR);
            foreach (['169.254.169.254', '::ffff:169.254.169.254', '0.0.0.0', 'fe80::1'] as $address) {
                \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$calls = [];
                \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$address = $address;
                try {
                    (new DeviceMaintenanceExecutor('/kadupul/'))->execute($primary, $remote, $state, new DeviceMaintenanceRequest('connectivity'), []);
                    self::fail('A reserved collector address was contacted: ' . $address);
                } catch (\RuntimeException $error) {
                    self::assertSame([], \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$calls, $address);
                }
            }
            \Kadupul\Inventory\Infrastructure\Legacy\MaintenanceProbeFixture::$address = '10.20.30.40';
        }

        public function testAllQueryOperationsClearSecretsOnFailure(): void
        {
            foreach (['reindex', 'reload-query', 'query-diagnostics'] as $operation) {
                $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [4 => 'Query'], [4 => 2], false);
                try {
                    (new DeviceMaintenanceExecutor())->execute($this->createMock(\PDO::class), null, $state, new DeviceMaintenanceRequest($operation, $operation === 'reindex' ? 0 : 4), []);
                    self::fail('Failure was swallowed');
                } catch (\RuntimeException $error) {
                    self::assertSame('Discovery failed', $error->getMessage());
                    self::assertArrayNotHasKey('debug_log', $_SESSION);
                }
            }
        }

        public function testFailedDiscoveryIsNotReportedAsCompletedAndRedactsDiagnostics(): void
        {
            $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [3 => 'Query'], [3 => 2], false);
            $result = (new DeviceMaintenanceExecutor())->execute($this->createMock(\PDO::class), null, $state, new DeviceMaintenanceRequest('query-diagnostics', 3), ['snmp_password' => 'fixture-secret']);
            self::assertFalse($result->completed);
            self::assertArrayNotHasKey('debug_log', $_SESSION);
            self::assertSame("Failed\n[redacted]", $result->output);
        }
    }
}
