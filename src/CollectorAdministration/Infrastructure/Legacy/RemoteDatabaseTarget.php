<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseTlsOptions;

final readonly class RemoteDatabaseTarget
{
    private function __construct(
        public string $dsn,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public int $retries,
        public array $options,
    ) {}

    /** @param array<string, mixed> $credentials */
    public static function fromCredentials(#[\SensitiveParameter] array $credentials, DatabaseTlsOptions $tls): ?self
    {
        $host = trim((string) ($credentials['dbhost'] ?? ''));
        $database = trim((string) ($credentials['dbdefault'] ?? ''));
        $username = (string) ($credentials['dbuser'] ?? '');
        $password = (string) ($credentials['dbpass'] ?? '');
        $port = filter_var($credentials['dbport'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $retries = filter_var($credentials['dbretries'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99999]]);
        $ssl = ($credentials['dbssl'] ?? '') === 'on';
        $sslKey = (string) ($credentials['dbsslkey'] ?? '');
        $sslCert = (string) ($credentials['dbsslcert'] ?? '');
        $sslCa = (string) ($credentials['dbsslca'] ?? '');
        if ($host === '' || $database === '' || $port === false || $retries === false
            || str_contains($host, ';') || str_contains($host, "\0")
            || str_contains($database, ';') || str_contains($database, "\0")) {
            return null;
        }
        if ($host === 'localhost' && $port !== 3306) {
            $host = '127.0.0.1';
        }
        try {
            $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2] + $tls->options([
                'ssl' => $ssl, 'ssl_key' => $sslKey, 'ssl_cert' => $sslCert, 'ssl_ca' => $sslCa,
            ]);
        } catch (\Throwable) {
            return null;
        }
        $dsn = str_contains($host, '/') && @filetype($host) === 'socket'
            ? 'mysql:unix_socket=' . $host . ';dbname=' . $database . ';charset=utf8'
            : 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8';
        return new self($dsn, $username, $password, min(5, $retries), $options);
    }

    public function __debugInfo(): array
    {
        return ['dsn' => $this->dsn, 'username' => '[redacted]', 'password' => '[redacted]', 'retries' => $this->retries];
    }
}
