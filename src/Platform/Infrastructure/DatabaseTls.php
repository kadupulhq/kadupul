<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure;

/**
 * The TLS rules for every installation database connection, PDO or DBAL. TLS
 * always verifies the server, so a credential set that enables it without a
 * CA is refused rather than connected unverified.
 */
final readonly class DatabaseTls
{
    /**
     * @param array<string, mixed> $config an installation credential set
     * @return array<int, mixed> PDO options to add; none when TLS is off
     */
    public static function options(#[\SensitiveParameter] array $config): array
    {
        if (!$config['ssl']) {
            return [];
        }
        if ($config['ssl_ca'] === '') {
            throw new \RuntimeException('Database TLS requires a CA certificate.');
        }
        $options = [\Pdo\Mysql::ATTR_SSL_CA => $config['ssl_ca'], \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
        if ($config['ssl_cert'] !== '') {
            $options[\Pdo\Mysql::ATTR_SSL_CERT] = $config['ssl_cert'];
            $options[\Pdo\Mysql::ATTR_SSL_KEY] = $config['ssl_key'];
        }

        return $options;
    }
}
