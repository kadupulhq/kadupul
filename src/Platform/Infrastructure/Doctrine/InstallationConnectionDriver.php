<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;

/**
 * Fills a named connection from include/config.php when it first connects, so
 * credentials never pass through container parameters. The validation and
 * TLS rules are those of InstallationDatabase.
 */
final class InstallationConnectionDriver extends AbstractDriverMiddleware
{
    /** @param \Closure(): InstallationConfiguration $configuration */
    public function __construct(Driver $driver, private readonly \Closure $configuration)
    {
        parent::__construct($driver);
    }

    #[\Override]
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        $marker = $params['driverOptions']['kadupul_target'] ?? null;
        if ($marker === null) {
            return parent::connect($params);
        }
        // The marker names a target for this driver; it is not a PDO attribute.
        unset($params['driverOptions']['kadupul_target']);
        $target = ConnectionTarget::tryFrom((string) $marker) ?? throw new \RuntimeException('Invalid database configuration.');
        $config = $this->credentials($target);
        if (array_any(['host', 'database'], static fn(string $key): bool => str_contains((string) $config[$key], ';') || str_contains((string) $config[$key], "\0"))) {
            throw new \RuntimeException('Invalid database configuration.');
        }
        $options = $params['driverOptions'] + [\PDO::ATTR_EMULATE_PREPARES => false];
        if ($config['ssl']) {
            if ($config['ssl_ca'] === '') {
                throw new \RuntimeException('Database TLS requires a CA certificate.');
            }
            $options[\Pdo\Mysql::ATTR_SSL_CA] = $config['ssl_ca'];
            $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
            if ($config['ssl_cert'] !== '') {
                $options[\Pdo\Mysql::ATTR_SSL_CERT] = $config['ssl_cert'];
                $options[\Pdo\Mysql::ATTR_SSL_KEY] = $config['ssl_key'];
            }
        }

        return parent::connect(array_replace($params, [
            'host' => (string) $config['host'],
            'port' => (int) $config['port'],
            'dbname' => (string) $config['database'],
            'user' => (string) $config['username'],
            'password' => (string) $config['password'],
            'charset' => 'utf8mb4',
            'driverOptions' => $options,
        ]));
    }

    /** @return array<string, mixed> */
    private function credentials(ConnectionTarget $target): array
    {
        $configuration = ($this->configuration)();
        if ($target === ConnectionTarget::Web) {
            $config = $configuration->values();
            // A remote collector already points these at the primary through
            // the rdatabase_* settings; a local read user would not exist there.
            if (($config['collector_id'] ?? 1) === 1) {
                $user = $config['read_username'] ?? '';
                $password = $config['read_password'] ?? '';
                if (($user === '') !== ($password === '')) {
                    throw new \RuntimeException('Incomplete read-only database credentials.');
                }
                if ($user !== '') {
                    $config['username'] = $user;
                    $config['password'] = $password;
                }
            }

            return $config;
        }
        $targets = $configuration->databaseTargets();

        return match ($target) {
            ConnectionTarget::Local => $targets['local'],
            ConnectionTarget::Main => $targets['main'] ?? throw new \RuntimeException('Main database is not configured.'),
        };
    }
}
