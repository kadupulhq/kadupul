<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Infrastructure\Legacy\RemoteDatabaseTarget;
use Kadupul\Platform\Contract\DatabaseTlsOptions;
use PHPUnit\Framework\TestCase;

final class RemoteDatabaseTargetTest extends TestCase
{
    public function testItBuildsSafeMysqlTargetsAndDoesNotExposeCredentialsInDebugOutput(): void
    {
        $target = RemoteDatabaseTarget::fromCredentials([
            'dbhost' => 'localhost', 'dbdefault' => 'cacti', 'dbuser' => 'service', 'dbpass' => 'secret-marker',
            'dbport' => 3310, 'dbretries' => 3, 'dbssl' => 'on', 'dbsslca' => '/etc/ssl/ca.pem',
        ], $this->tls());
        self::assertNotNull($target);
        self::assertSame('mysql:host=127.0.0.1;port=3310;dbname=cacti;charset=utf8', $target->dsn);
        self::assertSame(3, $target->retries);
        self::assertSame('/etc/ssl/ca.pem', $target->options[\PDO::MYSQL_ATTR_SSL_CA]);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $target->options[\PDO::ATTR_ERRMODE]);
        self::assertSame(2, $target->options[\PDO::ATTR_TIMEOUT]);
        ob_start();
        var_dump($target);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('secret-marker', $debug);
        self::assertStringNotContainsString('service', $debug);
    }

    public function testLegacyRetrySettingsHaveBoundedConnectionProbeAttempts(): void
    {
        $target = RemoteDatabaseTarget::fromCredentials(['dbhost' => 'db.example', 'dbdefault' => 'cacti', 'dbport' => 3306, 'dbretries' => 99999], $this->tls());
        self::assertNotNull($target);
        self::assertSame(5, $target->retries);
    }

    public function testInvalidTargetAndTlsSettingsFailClosed(): void
    {
        self::assertNull(RemoteDatabaseTarget::fromCredentials(['dbhost' => 'bad;host', 'dbdefault' => 'cacti', 'dbport' => 3306], $this->tls()));
        self::assertNull(RemoteDatabaseTarget::fromCredentials(['dbhost' => 'db.example', 'dbdefault' => 'cacti', 'dbport' => 3306, 'dbretries' => 100000], $this->tls()));
        self::assertNull(RemoteDatabaseTarget::fromCredentials(['dbhost' => 'db.example', 'dbdefault' => 'cacti', 'dbport' => 0], $this->tls()));
        $brokenTls = new class implements DatabaseTlsOptions {
            public function options(#[\SensitiveParameter] array $configuration): array
            {
                throw new \RuntimeException('TLS rejected');
            }
        };
        self::assertNull(RemoteDatabaseTarget::fromCredentials(['dbhost' => 'db.example', 'dbdefault' => 'cacti', 'dbport' => 3306], $brokenTls));
    }

    public function testSocketHostProducesSocketDsn(): void
    {
        $path = sys_get_temp_dir() . '/collector-db-socket-' . bin2hex(random_bytes(5));
        $server = @stream_socket_server('unix://' . $path, $errorCode, $errorMessage);
        if (!is_resource($server)) {
            self::markTestSkipped('The PHP runtime cannot create a temporary Unix socket in this environment.');
        }
        try {
            $target = RemoteDatabaseTarget::fromCredentials(['dbhost' => $path, 'dbdefault' => 'cacti', 'dbport' => 3306], $this->tls());
            self::assertNotNull($target);
            self::assertSame('mysql:unix_socket=' . $path . ';dbname=cacti;charset=utf8', $target->dsn);
        } finally {
            fclose($server);
            @unlink($path);
        }
    }

    private function tls(): DatabaseTlsOptions
    {
        return new class implements DatabaseTlsOptions {
            public function options(#[\SensitiveParameter] array $configuration): array
            {
                return !empty($configuration['ssl_ca']) ? [\PDO::MYSQL_ATTR_SSL_CA => $configuration['ssl_ca']] : [];
            }
        };
    }
}
