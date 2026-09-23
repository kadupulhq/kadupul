<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class InventoryReadConnectionFactory
{
    public function __construct(private LegacyConfiguration $configuration) {}

    public function create(): Connection
    {
        $config = $this->configuration->values();
        foreach (['host', 'database'] as $key) {
            if (str_contains($config[$key], ';') || str_contains($config[$key], "\0")) {
                throw new \RuntimeException('Invalid database configuration.');
            }
        }

        $username = $config['username'];
        $password = $config['password'];
        // A remote collector already points these at the primary through the
        // rdatabase_* settings; a local read user would not exist there.
        if (($config['collector_id'] ?? 1) === 1) {
            $readUsername = $config['read_username'] ?? '';
            $readPassword = $config['read_password'] ?? '';
            if (($readUsername === '') !== ($readPassword === '')) {
                throw new \RuntimeException('Incomplete read-only database credentials.');
            }
            if ($readUsername !== '') {
                $username = $readUsername;
                $password = $readPassword;
            }
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($config['ssl']) {
            if ($config['ssl_ca'] === '') {
                throw new \RuntimeException('Database TLS requires a CA certificate.');
            }
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            if ($config['ssl_cert'] !== '') {
                $options[\PDO::MYSQL_ATTR_SSL_CERT] = $config['ssl_cert'];
                $options[\PDO::MYSQL_ATTR_SSL_KEY] = $config['ssl_key'];
            }
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $config['host'],
            'port' => (int) $config['port'],
            'dbname' => $config['database'],
            'user' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'driverOptions' => $options,
        ]);
    }
}
