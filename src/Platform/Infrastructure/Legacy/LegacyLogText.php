<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Shared legacy log text rules; also loaded before Composer by lib/functions.php. */
final class LegacyLogText
{
    private const string CONTROLS = '/[\x00-\x08\x0a-\x1f\x7f]|\xc2[\x80-\x9f]|\xd8\x9c|\xe2\x80[\x8e\x8f\xa8-\xae]|\xe2\x81[\xa6-\xa9]/';

    public static function neutralize(string $text): string
    {
        // Match bytes so malformed UTF-8 cannot cause the line to be discarded.
        $found = preg_match(self::CONTROLS, $text);
        if ($found === 0) {
            return str_replace(["\r", "\n"], ' ', $text);
        }

        $clean = $found === false ? null : preg_replace_callback(self::CONTROLS, static function (array $match): string {
            $bytes = $match[0];
            if ($bytes === "\r" || $bytes === "\n") {
                return ' ';
            }
            if (strlen($bytes) === 1) {
                return sprintf('\\x%02x', ord($bytes));
            }
            if (strlen($bytes) === 2) {
                return sprintf('\\u%04x', ((ord($bytes[0]) & 0x1f) << 6) | (ord($bytes[1]) & 0x3f));
            }

            return sprintf('\\u%04x', ((ord($bytes[0]) & 0x0f) << 12) | ((ord($bytes[1]) & 0x3f) << 6) | (ord($bytes[2]) & 0x3f));
        }, $text);

        // Preserve the legacy PCRE-failure fallback: keep the record on one line.
        return $clean === null ? str_replace(["\r", "\n"], ' ', $text) : $clean;
    }
}
