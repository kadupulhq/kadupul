<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Ephemeral collector-boundary redaction; records the credentials actually used. */
final class DeviceDiagnosticScope
{
    private static ?array $hosts = null;

    public static function begin(): void
    {
        self::$hosts = [];
    }

    public static function remember(#[\SensitiveParameter] array $host): void
    {
        if (self::$hosts === null) {
            return;
        }
        $credentials = array_intersect_key($host, \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        $key = hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
        self::$hosts[$key] = $credentials;
        if (count(self::$hosts) > 512) {
            throw new \RuntimeException('Diagnostic credential scope exceeded');
        }
    }

    public static function finish(array $payload): array
    {
        try {
            if (self::$hosts === null) {
                throw new \LogicException('Diagnostic scope unavailable');
            }
            $clean = static function ($value) use (&$clean) {
                if (is_array($value)) {
                    return array_map($clean, $value);
                }
                return is_string($value) ? DeviceDiagnosticText::clean($value, ...array_values(self::$hosts)) : $value;
            };
            return $clean($payload);
        } finally {
            self::discard();
        }
    }

    public static function discard(): void
    {
        self::$hosts = null;
    }
}
