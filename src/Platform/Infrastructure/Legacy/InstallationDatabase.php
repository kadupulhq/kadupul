<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final class InstallationDatabase implements DatabaseConnection
{
    private ?\PDO $connection = null;

    public function __construct(private readonly LegacyConfiguration $configuration) {}

    #[\Override]
    public function get(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $config = $this->configuration->values();
        foreach (['host', 'database'] as $key) {
            if (str_contains($config[$key], ';') || str_contains($config[$key], "\0")) {
                throw new \RuntimeException('Invalid database configuration.');
            }
        }
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false];
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

        return $this->connection = new \PDO('mysql:host=' . $config['host'] . ';port=' . (int) $config['port']
            . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], $options);
    }
}
