<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\DatabaseTls;

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
            \PDO::ATTR_EMULATE_PREPARES => false] + DatabaseTls::options($config);

        return $this->connection = new \PDO('mysql:host=' . $config['host'] . ';port=' . (int) $config['port']
            . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], $options);
    }
}
