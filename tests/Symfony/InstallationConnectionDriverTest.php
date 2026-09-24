<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverFailure;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySqlDriver;
use Kadupul\Platform\Infrastructure\Doctrine\InstallationConnectionDriver;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class InstallationConnectionDriverTest extends TestCase
{
    private string $root;
    /** @var array<string, mixed>|null */
    private ?array $seen = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-dbal-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/include', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function driver(string $config): InstallationConnectionDriver
    {
        file_put_contents($this->root . '/include/config.php', "<?php\n\$database_type = 'mysql';\n\$database_default = 'kadupul';\n\$database_hostname = 'local.db';\n\$database_port = 3307;\n\$database_username = 'u';\n\$database_password = 'local-secret';\n" . $config);
        $inner = $this->createMock(Driver::class);
        $inner->method('connect')->willReturnCallback(function (array $params): DriverConnection {
            $this->seen = $params;

            return $this->createMock(DriverConnection::class);
        });

        return new InstallationConnectionDriver($inner, fn(): InstallationConfiguration => new InstallationConfiguration($this->root));
    }

    public function testLocalTargetFillsCredentialsAndStripsTheMarker(): void
    {
        $this->driver('')->connect(['driver' => 'pdo_mysql', 'driverOptions' => ['kadupul_target' => 'local']]);
        self::assertSame(['local.db', 3307, 'kadupul', 'u', 'utf8mb4'], [$this->seen['host'], $this->seen['port'], $this->seen['dbname'], $this->seen['user'], $this->seen['charset']]);
        self::assertTrue($this->seen['password'] === 'local-secret');
        self::assertArrayNotHasKey('kadupul_target', $this->seen['driverOptions']);
        self::assertFalse($this->seen['driverOptions'][\PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testMainOnACollectorUsesTheRemoteDatabase(): void
    {
        $this->driver("\$poller_id = 3;\n\$rdatabase_type = 'mysql';\n\$rdatabase_default = 'main';\n\$rdatabase_hostname = 'main.db';\n\$rdatabase_username = 'ru';\n\$rdatabase_password = 'main-secret';\n")->connect(['driverOptions' => ['kadupul_target' => 'main']]);
        self::assertSame(['main.db', 'main', 'ru'], [$this->seen['host'], $this->seen['dbname'], $this->seen['user']]);
    }

    public function testMissingMainFailsOnlyWhenConnecting(): void
    {
        $driver = $this->driver("\$poller_id = 3;\n");
        $this->expectExceptionMessage('Main database is not configured.');
        $driver->connect(['driverOptions' => ['kadupul_target' => 'main']]);
    }

    public function testWebTargetHonoursTheReadOnlyUser(): void
    {
        $this->driver("\$database_read_username = 'reader';\n\$database_read_password = 'read-secret';\n")->connect(['driverOptions' => ['kadupul_target' => 'web']]);
        self::assertSame('reader', $this->seen['user']);
        self::assertTrue($this->seen['password'] === 'read-secret');
    }

    public function testWebTargetFallsBackToTheInstallationUser(): void
    {
        $this->driver('')->connect(['driverOptions' => ['kadupul_target' => 'web']]);
        self::assertSame(['local.db', 'kadupul', 'u'], [$this->seen['host'], $this->seen['dbname'], $this->seen['user']]);
        self::assertTrue($this->seen['password'] === 'local-secret');
        self::assertArrayNotHasKey('kadupul_target', $this->seen['driverOptions']);
    }

    public function testWebTargetOnACollectorIgnoresTheReadOnlyUser(): void
    {
        // values() on a collector checks both databases before it returns, so
        // the test seeds the resolved configuration it would have cached.
        $request = new Request();
        $request->attributes->set('_route', 'inventory_sites');
        $requests = new RequestStack();
        $requests->push($request);
        $configuration = new InstallationConfiguration($this->root, $requests);
        (new \ReflectionProperty(InstallationConfiguration::class, 'configuration'))->setValue($configuration, [
            'collector_id' => 3, 'host' => 'main.db', 'port' => 3306, 'database' => 'main', 'username' => 'ru', 'password' => 'main-secret',
            'read_username' => 'reader', 'read_password' => 'read-secret', 'ssl' => false, 'ssl_key' => '', 'ssl_cert' => '', 'ssl_ca' => '',
        ]);
        $inner = $this->createMock(Driver::class);
        $inner->method('connect')->willReturnCallback(function (array $params): DriverConnection {
            $this->seen = $params;

            return $this->createMock(DriverConnection::class);
        });
        (new InstallationConnectionDriver($inner, fn(): InstallationConfiguration => $configuration))->connect(['driverOptions' => ['kadupul_target' => 'web']]);
        self::assertSame(['main.db', 'main', 'ru'], [$this->seen['host'], $this->seen['dbname'], $this->seen['user']]);
        self::assertTrue($this->seen['password'] === 'main-secret');
    }

    #[DataProvider('halfConfiguredReadOnlyUsers')]
    public function testWebTargetRejectsAHalfConfiguredReadOnlyUser(string $config): void
    {
        try {
            $this->driver($config)->connect(['driverOptions' => ['kadupul_target' => 'web']]);
            self::fail('Incomplete read-only credentials were accepted.');
        } catch (\RuntimeException $exception) {
            // Compare without assertSame so a failure cannot print the password.
            self::assertTrue($exception->getMessage() === 'Incomplete read-only database credentials.', 'Unexpected exception message.');
            self::assertFalse(str_contains($exception->getMessage(), 'read-secret'), 'The exception message contains the password.');
        }
        self::assertNull($this->seen);
    }

    /** @return iterable<string, array{string}> */
    public static function halfConfiguredReadOnlyUsers(): iterable
    {
        yield 'username only' => ["\$database_read_username = 'reader';\n"];
        yield 'password only' => ["\$database_read_password = 'read-secret';\n"];
    }

    public function testWebTargetRejectsAnUnsafeDatabaseName(): void
    {
        $driver = $this->driver("\$database_default = 'kadupul;charset=latin1';\n");
        $this->expectExceptionMessage('Invalid database configuration.');
        $driver->connect(['driverOptions' => ['kadupul_target' => 'web']]);
    }

    public function testUnknownTargetIsRejectedWithoutReachingTheDriver(): void
    {
        $driver = $this->driver('');
        try {
            $driver->connect(['driverOptions' => ['kadupul_target' => 'replica']]);
            self::fail('An unknown target was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Invalid database configuration.', $exception->getMessage());
        }
        self::assertNull($this->seen);
    }

    public function testTlsRequiresACertificateAuthority(): void
    {
        $driver = $this->driver("\$database_ssl = true;\n");
        $this->expectExceptionMessage('Database TLS requires a CA certificate.');
        $driver->connect(['driverOptions' => ['kadupul_target' => 'local']]);
    }

    public function testTlsOptionsAreApplied(): void
    {
        $this->driver("\$database_ssl = true;\n\$database_ssl_ca = '/ca.pem';\n\$database_ssl_cert = '/c.pem';\n\$database_ssl_key = '/k.pem';\n")->connect(['driverOptions' => ['kadupul_target' => 'local']]);
        self::assertSame('/ca.pem', $this->seen['driverOptions'][\Pdo\Mysql::ATTR_SSL_CA]);
        self::assertTrue($this->seen['driverOptions'][\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        self::assertSame('/c.pem', $this->seen['driverOptions'][\Pdo\Mysql::ATTR_SSL_CERT]);
        self::assertSame('/k.pem', $this->seen['driverOptions'][\Pdo\Mysql::ATTR_SSL_KEY]);
    }

    public function testUnsafeHostIsRejected(): void
    {
        $driver = $this->driver("\$database_hostname = 'x;charset=latin1';\n");
        $this->expectExceptionMessage('Invalid database configuration.');
        $driver->connect(['driverOptions' => ['kadupul_target' => 'local']]);
    }

    public function testConnectionWithoutAMarkerIsPassedThroughUntouched(): void
    {
        $this->driver('')->connect(['host' => 'other', 'driverOptions' => []]);
        self::assertSame('other', $this->seen['host']);
    }

    public function testFailedConnectionTraceOmitsPassword(): void
    {
        // Argument capture must be on, or the trace would be clean with or
        // without the attribute and the test would prove nothing.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $secret = 'kadupul-trace-secret-5d1c';
        file_put_contents($this->root . '/include/config.php', "<?php\n\$database_type = 'mysql';\n\$database_default = 'kadupul';\n\$database_hostname = '127.0.0.1';\n\$database_port = 1;\n\$database_username = 'u';\n\$database_password = '$secret';\n");
        $driver = new InstallationConnectionDriver(new MySqlDriver(), fn(): InstallationConfiguration => new InstallationConfiguration($this->root));
        try {
            $driver->connect(['driverOptions' => ['kadupul_target' => 'local']]);
            self::fail('Connecting to an unused port must fail.');
        } catch (DriverFailure $e) {
            for ($failure = $e; $failure !== null; $failure = $failure->getPrevious()) {
                self::assertStringNotContainsString($secret, print_r($failure->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }
}
