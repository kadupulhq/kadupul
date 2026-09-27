<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\DatabaseTls;
use Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseTlsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(bool $ssl, string $ca = '', string $cert = '', string $key = ''): array
    {
        return ['ssl' => $ssl, 'ssl_ca' => $ca, 'ssl_cert' => $cert, 'ssl_key' => $key];
    }

    public function testNoOptionsWithoutTls(): void
    {
        self::assertSame([], DatabaseTls::options($this->config(false, '/ca.pem')));
    }

    public function testTlsWithoutACertificateAuthorityIsRefused(): void
    {
        $this->expectExceptionMessage('Database TLS requires a CA certificate.');
        DatabaseTls::options($this->config(true));
    }

    public function testTlsAlwaysVerifiesTheServer(): void
    {
        self::assertSame([\Pdo\Mysql::ATTR_SSL_CA => '/ca.pem', \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true], DatabaseTls::options($this->config(true, '/ca.pem', '', '/k.pem')));
    }

    public function testClientCertificateCarriesItsKey(): void
    {
        self::assertSame(
            [\Pdo\Mysql::ATTR_SSL_CA => '/ca.pem', \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true, \Pdo\Mysql::ATTR_SSL_CERT => '/c.pem', \Pdo\Mysql::ATTR_SSL_KEY => '/k.pem'],
            DatabaseTls::options($this->config(true, '/ca.pem', '/c.pem', '/k.pem')),
        );
    }

    public function testPdoPathRefusesTlsWithoutACertificateAuthorityBeforeConnecting(): void
    {
        $configuration = new class implements LegacyConfiguration {
            #[\Override]
            public function values(): array
            {
                return ['host' => '127.0.0.1', 'port' => 1, 'database' => 'kadupul', 'username' => 'u', 'password' => 'p', 'ssl' => true, 'ssl_ca' => '', 'ssl_cert' => '', 'ssl_key' => ''];
            }
        };
        $this->expectExceptionMessage('Database TLS requires a CA certificate.');
        (new InstallationDatabase($configuration))->get();
    }
}
