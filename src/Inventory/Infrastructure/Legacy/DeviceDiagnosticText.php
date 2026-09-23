<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Only bounded plain text crosses the worker boundary. */
final class DeviceDiagnosticText
{
    public static function clean(string $output, #[\SensitiveParameter] array ...$hosts): string
    {
        $replacements = [];
        foreach ($hosts as $host) {
            foreach (\Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $field => $default) {
                $secret = (string) ($host[$field] ?? '');
                $normalized = str_replace(["\r", "\n"], '', $secret);
                // Legacy SNMP logs use shell-escaped arguments on Unix and Windows.
                foreach ([$secret, $normalized, "'" . str_replace("'", "'\\''", $normalized) . "'", '"' . str_replace('"', '\\"', $secret) . '"', str_replace("'", "'\\''", $normalized), str_replace('"', '\\"', $secret)] as $value) {
                    if ($value !== '') {
                        $replacements[$value] = '[redacted]';
                        $replacements[htmlspecialchars($value, ENT_QUOTES, 'UTF-8')] = '[redacted]';
                        $plain = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($plain !== '') {
                            $replacements[$plain] = '[redacted]';
                        }
                    }
                }
            }
        }
        $output = strtr($output, $replacements);
        $output = preg_replace('/<br\s*\/?>/i', "\n", $output);
        $output = html_entity_decode(strip_tags($output), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $output = strtr($output, $replacements);
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
        return mb_strcut(mb_convert_encoding($output, 'UTF-8', 'UTF-8'), 0, 65536, 'UTF-8');
    }
}
