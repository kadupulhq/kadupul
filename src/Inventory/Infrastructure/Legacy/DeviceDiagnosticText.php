<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Only bounded plain text crosses the worker boundary. */
final class DeviceDiagnosticText
{
    public static function clean(string $output, #[\SensitiveParameter] array $host): string
    {
        $output = preg_replace('/<br\s*\/?>/i', "\n", $output);
        $output = html_entity_decode(strip_tags($output), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $replacements = [];
        foreach (\Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $field => $default) {
            $secret = (string) ($host[$field] ?? '');
            if ($secret !== '') {
                // Legacy Unix shell arguments remove CR/LF and quote apostrophes.
                // Windows SNMP arguments instead escape embedded double quotes.
                $unix = str_replace(["\r", "\n"], '', $secret);
                $forms = [$secret, $unix, "'" . str_replace("'", "'\\''", $unix) . "'",
                    '"' . str_replace('"', '\\"', $secret) . '"'];
                foreach ($forms as $form) {
                    $form = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $form)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($form !== '') {
                        $replacements[$form] = '[redacted]';
                    }
                }
            }
        }
        // Replace longest matches together so a short credential cannot expose
        // the remainder of a longer one, or alter another replacement token.
        $output = strtr($output, $replacements);
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
        return mb_strcut(mb_convert_encoding($output, 'UTF-8', 'UTF-8'), 0, 65536, 'UTF-8');
    }
}
