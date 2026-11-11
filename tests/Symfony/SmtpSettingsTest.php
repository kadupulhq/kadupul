<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Alerting\Infrastructure\Mail\SmtpSecurity;
use Kadupul\Alerting\Infrastructure\Mail\SmtpSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpSettingsTest extends TestCase
{
    public function testMissingAndNullValuesTakeTheSenderDefaults(): void
    {
        $smtp = SmtpSettings::fromInstallationSettings(['settings_how' => '2', 'settings_smtp_port' => null]);

        self::assertSame(['localhost', 25, 10, SmtpSecurity::None, '', '', '', ''], [
            $smtp->host, $smtp->port, $smtp->timeout, $smtp->security,
            $smtp->username, $smtp->password, $smtp->fromEmail, $smtp->fromName,
        ]);
    }

    public function testConfiguredValuesAndIpv6Bracketing(): void
    {
        foreach (['::1', '[::1]'] as $host) {
            $smtp = SmtpSettings::fromInstallationSettings([
                'settings_how' => '2', 'settings_smtp_host' => $host, 'settings_smtp_port' => '587',
                'settings_smtp_timeout' => '300', 'settings_smtp_secure' => 'tls', 'settings_smtp_username' => 'user',
                'settings_smtp_password' => 'secret', 'settings_from_email' => 'a@example.test', 'settings_from_name' => 'A',
            ]);
            self::assertSame(['[::1]', 587, 300, SmtpSecurity::Tls, 'user', 'secret', 'a@example.test', 'A'], [
                $smtp->host, $smtp->port, $smtp->timeout, $smtp->security,
                $smtp->username, $smtp->password, $smtp->fromEmail, $smtp->fromName,
            ]);
        }
    }

    public function testSecurityModesMapToTransportFlags(): void
    {
        self::assertSame([false, false, false], [SmtpSecurity::None->implicitTls(), SmtpSecurity::None->startTls(), SmtpSecurity::None->requiresTls()]);
        self::assertSame([false, true, true], [SmtpSecurity::Tls->implicitTls(), SmtpSecurity::Tls->startTls(), SmtpSecurity::Tls->requiresTls()]);
        self::assertSame([true, false, true], [SmtpSecurity::Ssl->implicitTls(), SmtpSecurity::Ssl->startTls(), SmtpSecurity::Ssl->requiresTls()]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsupported(): iterable
    {
        yield 'native mail' => [['settings_how' => '0']];
        yield 'unset method' => [['settings_how' => null]];
        yield 'host list' => [['settings_smtp_host' => 'a.example.test;b.example.test']];
        yield 'DSN' => [['settings_smtp_host' => 'smtp://localhost']];
        yield 'empty host' => [['settings_smtp_host' => '']];
        yield 'falsy host' => [['settings_smtp_host' => '0']];
        yield 'port zero' => [['settings_smtp_port' => '0']];
        yield 'port overflow' => [['settings_smtp_port' => '65536']];
        yield 'signed port' => [['settings_smtp_port' => '+25']];
        yield 'fractional timeout' => [['settings_smtp_timeout' => '2.5']];
        yield 'timeout overflow' => [['settings_smtp_timeout' => '301']];
        yield 'security' => [['settings_smtp_secure' => 'starttls']];
        yield 'non-string value' => [['settings_smtp_port' => 25]];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('unsupported')]
    public function testUnsupportedSettingsAreRefused(array $settings): void
    {
        $settings += ['settings_how' => '2'];
        self::assertNull(SmtpSettings::tryFromInstallationSettings($settings));
        $this->expectException(\InvalidArgumentException::class);
        SmtpSettings::fromInstallationSettings($settings);
    }

    public function testTheSettingsArrayIsRedactedFromRefusalTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            SmtpSettings::fromInstallationSettings(['settings_how' => '2', 'settings_smtp_port' => '0', 'settings_smtp_password' => 'trace-canary']);
            self::fail('An invalid port must be refused.');
        } catch (\InvalidArgumentException $error) {
            $frames = array_filter($error->getTrace(), static fn(array $frame): bool => ($frame['function'] ?? '') === 'fromInstallationSettings');
            self::assertCount(1, $frames);
            self::assertInstanceOf(\SensitiveParameterValue::class, array_values($frames)[0]['args'][0] ?? null);
            self::assertStringNotContainsString('trace-canary', $error->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
