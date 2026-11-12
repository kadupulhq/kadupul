<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

/**
 * One validated SMTP server and sender, read from the installation settings.
 *
 * Only a single host, a numeric port and timeout and a known security mode are
 * accepted. Host lists, DSNs and other legacy forms stay with the legacy mailer.
 */
final readonly class SmtpSettings
{
    private function __construct(
        public string $host,
        public int $port,
        public int $timeout,
        public SmtpSecurity $security,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public string $fromEmail,
        public string $fromName,
    ) {}

    /**
     * @param array<array-key, mixed> $settings settings_* names and values; a missing or null value takes the default
     *
     * @throws \InvalidArgumentException when the settings are not a single supported SMTP server
     */
    public static function fromInstallationSettings(#[\SensitiveParameter] array $settings): self
    {
        if (self::text($settings, 'settings_how', '0') !== '2') {
            throw new \InvalidArgumentException('SMTP is required.');
        }
        $host = self::text($settings, 'settings_smtp_host', 'localhost');
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $ip . ']';
        } elseif (!self::isHostname($host)) {
            throw new \InvalidArgumentException('One SMTP hostname or IP address is required.');
        }
        $port = self::integer(self::text($settings, 'settings_smtp_port', '25'), 65535);
        $timeout = self::integer(self::text($settings, 'settings_smtp_timeout', '10'), 300);
        $security = SmtpSecurity::tryFrom(self::text($settings, 'settings_smtp_secure', 'none'))
            ?? throw new \InvalidArgumentException('Invalid SMTP security mode.');

        return new self(
            $host,
            $port,
            $timeout,
            $security,
            self::text($settings, 'settings_smtp_username', ''),
            self::text($settings, 'settings_smtp_password', ''),
            self::text($settings, 'settings_from_email', ''),
            self::text($settings, 'settings_from_name', ''),
        );
    }

    /**
     * Like fromInstallationSettings(), but null when the settings are not supported.
     *
     * @param array<array-key, mixed> $settings
     */
    public static function tryFromInstallationSettings(#[\SensitiveParameter] array $settings): ?self
    {
        try {
            return self::fromInstallationSettings($settings);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $settings */
    private static function text(array $settings, string $name, string $default): string
    {
        $value = $settings[$name] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid SMTP setting.');
        }

        return $value;
    }

    private static function integer(string $value, int $maximum): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
            throw new \InvalidArgumentException('Invalid SMTP port or timeout.');
        }

        return (int) $value;
    }

    /** filter_var() returns the host itself, so a host of "0" was always refused as falsy. */
    private static function isHostname(string $host): bool
    {
        return !in_array(filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME), [false, '0'], true);
    }
}
