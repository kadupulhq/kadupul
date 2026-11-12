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

    /**
     * Starts collecting the credentials that must be redacted from diagnostic output.
     */
    public static function begin(): void
    {
        self::$hosts = [];
    }

    /**
     * Adds a device's SNMP credentials to the set to redact.
     * Ignored when no scope has been started.
     *
     * @param array $host A device row; only its credential fields are kept.
     * @throws \RuntimeException When more than 512 distinct credential sets are collected.
     */
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

    /**
     * Redacts the collected credentials from every string in the payload and ends the scope.
     *
     * @param array $payload Diagnostic output, possibly nested.
     * @return array The payload with strings cleaned.
     * @throws \LogicException When no scope was started.
     */
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

    /**
     * Ends the scope and forgets the collected credentials.
     */
    public static function discard(): void
    {
        self::$hosts = null;
    }
}
