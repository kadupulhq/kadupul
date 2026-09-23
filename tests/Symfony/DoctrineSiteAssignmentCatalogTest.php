<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineSiteAssignmentCatalog;
use Kadupul\Inventory\Infrastructure\Persistence\InventoryReadConnectionFactory;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoctrineSiteAssignmentCatalogTest extends TestCase
{
    public function testItReturnsAssignableSitesInDisplayOrder(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('CREATE TABLE sites (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $database->executeStatement("INSERT INTO sites (id, name) VALUES (2, 'Zulu'), (0, 'Console'), (3, 'Alpha'), (1, 'Alpha')");

        self::assertSame([1 => 'Alpha', 3 => 'Alpha', 2 => 'Zulu'], (new DoctrineSiteAssignmentCatalog($database))->sites());
    }

    public function testConnectionFactoryPreservesInstallationSecurityOptions(): void
    {
        $connection = (new InventoryReadConnectionFactory($this->configuration([
            'ssl' => true,
            'ssl_ca' => '/certificates/ca.pem',
            'ssl_cert' => '/certificates/client.pem',
            'ssl_key' => '/certificates/client.key',
        ])))->create();

        $params = $connection->getParams();
        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame('database.internal', $params['host']);
        self::assertSame(3307, $params['port']);
        self::assertSame('kadupul', $params['dbname']);
        self::assertSame('utf8mb4', $params['charset']);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $params['driverOptions'][\PDO::ATTR_ERRMODE]);
        self::assertFalse($params['driverOptions'][\PDO::ATTR_EMULATE_PREPARES]);
        self::assertSame('/certificates/ca.pem', $params['driverOptions'][\PDO::MYSQL_ATTR_SSL_CA]);
        self::assertTrue($params['driverOptions'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
        self::assertSame('/certificates/client.pem', $params['driverOptions'][\PDO::MYSQL_ATTR_SSL_CERT]);
        self::assertSame('/certificates/client.key', $params['driverOptions'][\PDO::MYSQL_ATTR_SSL_KEY]);
    }

    public function testConnectionFactoryRejectsUnsafeDatabaseNames(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database configuration.');

        (new InventoryReadConnectionFactory($this->configuration(['database' => 'kadupul;charset=latin1'])))->create();
    }

    public function testConnectionFactoryRequiresACertificateAuthorityForTls(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database TLS requires a CA certificate.');

        (new InventoryReadConnectionFactory($this->configuration(['ssl' => true])))->create();
    }

    public function testConnectionFactoryUsesReadOnlyCredentialsWhenConfigured(): void
    {
        $params = (new InventoryReadConnectionFactory($this->configuration([
            'read_username' => 'kadupul_read',
            'read_password' => 'read-secret',
        ])))->create()->getParams();

        self::assertSame('kadupul_read', $params['user']);
        // Compare without assertSame so a failure cannot print the password.
        self::assertTrue($params['password'] === 'read-secret', 'The read-only password was not used.');
    }

    public function testConnectionFactoryFallsBackToPrimaryCredentials(): void
    {
        $params = (new InventoryReadConnectionFactory($this->configuration([
            'read_username' => '',
            'read_password' => '',
        ])))->create()->getParams();

        self::assertSame('kadupul', $params['user']);
        self::assertTrue($params['password'] === 'secret', 'The primary password was not used.');
    }

    #[DataProvider('incompleteReadCredentials')]
    public function testConnectionFactoryRejectsIncompleteReadCredentials(bool $usernameOnly): void
    {
        // PHPUnit prints data-set values on failure, so the password stays out of the provider.
        $credentials = $usernameOnly
            ? ['read_username' => 'kadupul_read', 'read_password' => '']
            : ['read_username' => '', 'read_password' => 'read-secret'];
        try {
            (new InventoryReadConnectionFactory($this->configuration($credentials)))->create();
            self::fail('Incomplete read-only credentials were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertTrue($exception->getMessage() === 'Incomplete read-only database credentials.', 'Unexpected exception message.');
            self::assertFalse(str_contains($exception->getMessage(), 'read-secret'), 'The exception message contains the password.');
        }
    }

    public static function incompleteReadCredentials(): iterable
    {
        yield 'username only' => [true];
        yield 'password only' => [false];
    }

    public function testCollectorConnectionIgnoresReadOnlyCredentials(): void
    {
        $params = (new InventoryReadConnectionFactory($this->configuration([
            'collector_id' => 2,
            'username' => 'primary_user',
            'password' => 'primary-secret',
            'read_username' => 'kadupul_read',
            'read_password' => '',
        ])))->create()->getParams();

        self::assertSame('primary_user', $params['user']);
        self::assertTrue($params['password'] === 'primary-secret', 'The primary password was not used.');
    }

    private function configuration(array $overrides = []): LegacyConfiguration
    {
        $values = array_replace([
            'host' => 'database.internal',
            'port' => 3307,
            'database' => 'kadupul',
            'username' => 'kadupul',
            'password' => 'secret',
            'ssl' => false,
            'ssl_ca' => '',
            'ssl_cert' => '',
            'ssl_key' => '',
        ], $overrides);

        return new readonly class ($values) implements LegacyConfiguration {
            public function __construct(private array $values) {}

            public function values(): array
            {
                return $this->values;
            }
        };
    }
}
