<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineCacheDiagnostics;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Application\ReadModel\ProductRelease;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen;
use Kadupul\Platform\Infrastructure\Persistence\DbalRrdCheckAccess;
use Kadupul\Platform\Infrastructure\Persistence\DbalSystemDiagnostics;
use PHPUnit\Framework\TestCase;

final class SystemDiagnosticsDatabaseTest extends TestCase
{
    private const array TABLES = ['version', 'settings', 'host', 'graph_local', 'data_local', 'data_template_data', 'poller_item', 'host_snmp_cache', 'snmp_query', 'processes', 'poller_output_boost', 'poller_output_boost_local_data_ids', 'user_auth', 'user_auth_perms', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms', 'user_auth_group_realm'];
    private ?\PDO $administrator = null;
    private string $schema = '';
    private Connection $database;
    private DbalSystemDiagnostics $reader;
    private int $queries = 0;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for native diagnostic contracts.');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->administrator = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'diagnostics_' . bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->administrator->exec('USE `' . $this->schema . '`');
        // The shipped schema retains historical zero defaults. Keep strict type
        // checking without rejecting those defaults in this disposable fixture.
        $this->administrator->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\n\) ENGINE=[^;]*;/s', $sql, $match), $table);
            $this->administrator->exec($match[0]);
        }
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strlen('mysql:')), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $configuration = new \Doctrine\DBAL\Configuration();
        $logger = new class (function (): void {
            $this->queries++;
        }) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly \Closure $record) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'debug') {
                    ($this->record)();
                }
            }
        };
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $this->database = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $options['host'] ?? '127.0.0.1', 'port' => (int) ($options['port'] ?? 3306), 'dbname' => $this->schema, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'], $configuration);
        $this->database->executeStatement("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $version = $this->createMock(ProductVersion::class);
        $version->method('release')->willReturn(new ProductRelease('test-release'));
        $this->reader = new DbalSystemDiagnostics($this->database, new DoctrineCacheDiagnostics($this->database), $version, dirname(__DIR__, 2));
        $this->database->executeStatement("INSERT INTO version (cacti) VALUES ('1.3.0')");
        $this->database->executeStatement("INSERT INTO user_auth (id, username, enabled, policy_hosts, policy_graphs, policy_graph_templates) VALUES (42, 'operator', 'on', 2, 2, 2)");
        $this->database->executeStatement("INSERT INTO settings (name, value) VALUES ('graph_auth_method','3'), ('auth_method','1'), ('poller_interval','300'), ('database_password','credential-canary'), ('boost_poller_status','running:123')");
        $this->database->executeStatement("INSERT INTO host (id, description, hostname, disabled) VALUES (1, 'Visible <b>', 'visible.test', ''), (2, 'Forbidden', 'hidden.test', ''), (3, 'Disabled but allowed', 'disabled.test', 'on')");
        $this->database->executeStatement('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (42, 1, 3), (42, 3, 3)');
        $this->database->executeStatement("INSERT INTO data_local (id, host_id, data_template_id) VALUES (10,1,7),(20,2,7),(30,3,8),(40,0,0)");
        $this->database->executeStatement("INSERT INTO data_template_data (local_data_id, data_template_id, name_cache, active) VALUES (10,7,'Visible source','on'),(20,7,'Forbidden source','on'),(30,8,'Disabled source',''),(40,0,'Non-device source','on')");
        foreach ([[10, 1], [20, 2], [30, 3], [40, 0]] as [$dataId, $hostId]) {
            $this->database->executeStatement('INSERT INTO poller_item (local_data_id, host_id, rrd_name, rrd_path, snmp_password, arg1) VALUES (?, ?, ?, ?, ?, ?)', [$dataId, $hostId, 'traffic', '/rra/' . $dataId . '.rrd', 'credential-canary', 'credential-canary']);
        }
        $this->database->executeStatement("INSERT INTO snmp_query (id, name, xml_path) VALUES (1,'Interfaces','/resource/test.xml')");
        foreach ([1, 2, 3] as $hostId) {
            $this->database->executeStatement('INSERT INTO host_snmp_cache (host_id, snmp_query_id, field_name, field_value, snmp_index, oid) VALUES (?, 1, ?, ?, ?, ?)', [$hostId, 'ifName', 'Ethernet <script>', 'index-only', '.1.3.6']);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        if ($this->administrator !== null && $this->schema !== '') {
            $this->administrator->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testAllTenViewsReadActualShippedSchemaWithoutMutatingIt(): void
    {
        $before = $this->database->fetchOne('SELECT COUNT(*) FROM settings');
        foreach (DiagnosticScreen::cases() as $screen) {
            $page = $this->reader->read($screen, DiagnosticFilters::fromQuery(['rows' => '100'], $screen), 42);
            if ($screen !== DiagnosticScreen::Menu) {
                self::assertNotEmpty($page->rows, $screen->value);
            }
            self::assertLessThanOrEqual(100, count($page->rows));
            self::assertStringNotContainsString('credential-canary', json_encode($page, JSON_THROW_ON_ERROR), $screen->value);
        }
        self::assertSame($before, $this->database->fetchOne('SELECT COUNT(*) FROM settings'));
        self::assertSame(4, (int) $this->database->fetchOne('SELECT COUNT(*) FROM poller_item'));
        self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM host_snmp_cache'));
    }

    public function testCacheReadsPreserveDeviceExceptionsDisabledDevicesAndNonDeviceSources(): void
    {
        $poller = $this->read(DiagnosticScreen::PollerCache);
        self::assertSame(['Disabled source', 'Non-device source', 'Visible source'], array_column($poller->rows, 0));
        $cache = $this->read(DiagnosticScreen::DataQueryCache);
        self::assertSame(['Visible <b>', 'Disabled but allowed'], array_column($cache->rows, 0));
        self::assertSame([], $this->read(DiagnosticScreen::PollerCache, ['host_id' => '2'])->rows);
        self::assertSame([], $this->read(DiagnosticScreen::DataQueryCache, ['host_id' => '2'])->rows);
        self::assertSame(['Non-device source'], array_column($this->read(DiagnosticScreen::PollerCache, ['host_id' => '0'])->rows, 0));
        self::assertSame(['Disabled source'], array_column($this->read(DiagnosticScreen::PollerCache, ['status' => '0'])->rows, 0));
        self::assertSame(['Visible source'], array_column($this->read(DiagnosticScreen::PollerCache, ['status' => '1'])->rows, 0));
        self::assertSame([], $this->read(DiagnosticScreen::DataQueryCache, ['filter' => 'index-only'])->rows);
        self::assertCount(2, $this->read(DiagnosticScreen::DataQueryCache, ['filter' => 'index-only', 'with_index' => '1'])->rows);
        self::assertSame([], $this->read(DiagnosticScreen::PollerCache, ['filter' => 'credential-canary'])->rows);
        self::assertSame([], $this->read(DiagnosticScreen::PollerCache, ['filter' => "' OR 1=1 --"])->rows);
    }

    public function testSimplePermissionsAndEnabledGroupPoliciesMatchManagementScope(): void
    {
        $this->database->executeStatement('DELETE FROM user_auth_perms WHERE user_id = 42');
        $this->database->executeStatement('UPDATE user_auth SET policy_hosts = 1 WHERE id = 42');
        // The simple host policy permits all devices even when graph policies deny.
        self::assertCount(4, $this->read(DiagnosticScreen::PollerCache)->rows);
        $this->database->executeStatement('UPDATE user_auth SET policy_hosts = 2 WHERE id = 42');
        $this->database->executeStatement("INSERT INTO user_auth_group (id, name, enabled, policy_hosts, policy_graphs, policy_graph_templates) VALUES (9,'Operators','on',2,2,2)");
        $this->database->executeStatement('INSERT INTO user_auth_group_members (group_id, user_id) VALUES (9,42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_perms (group_id, item_id, type) VALUES (9,2,3)');
        self::assertSame(['Forbidden source', 'Non-device source'], array_column($this->read(DiagnosticScreen::PollerCache)->rows, 0));
        $this->database->executeStatement("UPDATE user_auth_group SET enabled = '' WHERE id = 9");
        self::assertSame(['Non-device source'], array_column($this->read(DiagnosticScreen::PollerCache)->rows, 0));
    }

    public function testStableLookaheadPagingAndDatabaseErrorsAreNotEmptySuccess(): void
    {
        $this->database->executeStatement("UPDATE user_auth SET policy_hosts = 1 WHERE id = 42");
        $this->database->executeStatement('DELETE FROM user_auth_perms WHERE user_id = 42');
        for ($id = 100; $id < 125; $id++) {
            $this->database->executeStatement('INSERT INTO data_local (id, host_id) VALUES (?,1)', [$id]);
            $this->database->executeStatement("INSERT INTO data_template_data (local_data_id, name_cache) VALUES (?, 'Same name')", [$id]);
            $this->database->executeStatement("INSERT INTO poller_item (local_data_id, host_id, rrd_name) VALUES (?,1,'same')", [$id]);
        }
        $this->queries = 0;
        $first = $this->read(DiagnosticScreen::PollerCache, ['rows' => '10', 'filter' => 'Same name']);
        self::assertLessThanOrEqual(4, $this->queries, 'Cache pages must not issue per-row queries.');
        $second = $this->read(DiagnosticScreen::PollerCache, ['rows' => '10', 'filter' => 'Same name', 'page' => '2']);
        $last = $this->read(DiagnosticScreen::PollerCache, ['rows' => '10', 'filter' => 'Same name', 'page' => '3']);
        self::assertTrue($first->hasNext);
        self::assertTrue($second->hasNext);
        self::assertFalse($last->hasNext);
        self::assertCount(10, $first->rows);
        self::assertCount(5, $last->rows);
        self::assertSame([], array_intersect(array_column($first->rows, 3), array_column($second->rows, 3)));
        $this->database->executeStatement('DROP TABLE host_snmp_cache');
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->read(DiagnosticScreen::DataQueryCache);
    }

    public function testFreshAuthorizationChecksAccountAndDirectOrEnabledGroupGrants(): void
    {
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access = new DbalRrdCheckAccess($console, $this->database);
        $this->assertDenied($access);
        $this->database->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (42,15)');
        self::assertSame(42, $access->authorize()->id);
        foreach (["enabled = ''", "locked = 'on'", "must_change_password = 'on'"] as $mutation) {
            $this->database->executeStatement('UPDATE user_auth SET ' . $mutation . ' WHERE id = 42');
            $this->assertDenied($access);
            $this->database->executeStatement("UPDATE user_auth SET enabled = 'on', locked = '', must_change_password = '' WHERE id = 42");
        }
        $this->database->executeStatement("INSERT INTO settings (name, value) VALUES ('guest_user','operator')");
        $this->assertDenied($access);
        $this->database->executeStatement("DELETE FROM settings WHERE name = 'guest_user'");
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42');
        $this->database->executeStatement("INSERT INTO user_auth_group (id, name, enabled) VALUES (9,'Operators','on')");
        $this->database->executeStatement('INSERT INTO user_auth_group_members (group_id,user_id) VALUES (9,42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES (9,15)');
        self::assertSame(42, $access->authorize()->id);
        $this->database->executeStatement("UPDATE user_auth_group SET enabled = '' WHERE id = 9");
        $this->assertDenied($access);
    }

    public function testOnlineCollectorsSelectTheVerifiedPrimaryForEveryDiagnosticRoute(): void
    {
        $directory = sys_get_temp_dir() . '/diagnostic-primary-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        $localSchema = 'diagnostic_local_' . bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `' . $localSchema . '`');
        foreach (['version', 'poller_output_boost'] as $table) {
            $this->administrator->exec('CREATE TABLE `' . $localSchema . '`.`' . $table . '` LIKE `' . $this->schema . '`.`' . $table . '`');
        }
        $this->administrator->exec("INSERT INTO `$localSchema`.version (cacti) VALUES ('1.3.0')");
        $parameters = $this->database->getParams();
        $source = "<?php\n\$poller_id = 2;\n";
        foreach (['database' => 'local', 'rdatabase' => 'primary'] as $prefix => $label) {
            foreach (['hostname' => $parameters['host'], 'port' => $parameters['port'], 'default' => $prefix === 'database' ? $localSchema : $this->schema, 'username' => $parameters['user'], 'password' => $parameters['password']] as $key => $value) {
                $source .= '$' . $prefix . '_' . $key . ' = ' . var_export($value, true) . ";\n";
            }
        }
        file_put_contents($directory . '/include/config.php', $source);
        try {
            foreach (DiagnosticScreen::cases() as $screen) {
                $request = \Symfony\Component\HttpFoundation\Request::create('/utilities/system');
                $request->attributes->set('_route', 'platform_diagnostics_' . str_replace('-', '_', $screen->value));
                $requests = new \Symfony\Component\HttpFoundation\RequestStack();
                $requests->push($request);
                $configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests);
                self::assertSame(2, $configuration->values()['collector_id']);
                self::assertSame($this->schema, $configuration->values()['database']);
                self::assertSame($parameters['host'], $configuration->values()['host']);
            }
            $this->administrator->exec("INSERT INTO `$localSchema`.poller_output_boost (local_data_id, rrd_name, time, output) VALUES (1, 'test', '2026-10-26 00:00:00', '1')");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Sites administration requires an online collector with a reachable primary.');
            (new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests))->values();
        } finally {
            $this->administrator->exec('DROP DATABASE IF EXISTS `' . $localSchema . '`');
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    private function read(DiagnosticScreen $screen, array $query = []): \Kadupul\Platform\Application\ReadModel\DiagnosticPage
    {
        return $this->reader->read($screen, DiagnosticFilters::fromQuery($query, $screen), 42);
    }

    private function assertDenied(DbalRrdCheckAccess $access): void
    {
        try {
            $access->authorize();
            self::fail('Expected a denied account or realm.');
        } catch (RrdCheckAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }
}
