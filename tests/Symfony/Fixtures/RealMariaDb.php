<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** The MariaDB KADUPUL_TEST_MYSQL_DSN names, for SQL that SQLite cannot express; skipped without it. */
trait RealMariaDb
{
    private function realMariaDb(): Connection
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to run against a real MariaDB.');
        }
        // The variable holds a PDO DSN, shared with tests/security; its keys
        // happen to match DBAL's parameter names.
        $params = ['driver' => 'pdo_mysql', 'user' => getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            if (in_array($key, ['host', 'port', 'dbname', 'unix_socket', 'charset'], true)) {
                $params[$key] = $key === 'port' ? (int) $value : $value;
            }
        }

        return DriverManager::getConnection($params);
    }
}
