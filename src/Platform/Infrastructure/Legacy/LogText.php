<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * The control-character rule of cacti.log and syslog lines. It uses no
 * dependencies or globals, so it can be loaded without the container.
 *
 * lib/functions.php keeps its own copy of this rule in cacti_log();
 * tests/Symfony/LogTextDriftTest.php fails if the two differ.
 */
final class LogText
{
    /**
     * CR and LF become spaces and tab is kept. Other C0 controls and DEL
     * become \xNN; C1, U+061C, U+200E, U+200F, U+2028 to U+202E and U+2066
     * to U+2069 become \uNNNN. The match is byte oriented, without /u, so
     * invalid UTF-8 cannot make it fail and drop the line; if PCRE fails
     * anyway, CR and LF still become spaces.
     */
    public static function neutralize(string $text): string
    {
        $controls = '/[\x00-\x08\x0a-\x1f\x7f]|\xc2[\x80-\x9f]|\xd8\x9c|\xe2\x80[\x8e\x8f\xa8-\xae]|\xe2\x81[\xa6-\xa9]/';

        $found = preg_match($controls, $text);

        if ($found === 0) {
            return str_replace(["\r", "\n"], ' ', $text);
        }

        $clean = $found === false ? null : preg_replace_callback($controls, static function (array $match): string {
            $bytes = $match[0];

            if ($bytes === "\r" || $bytes === "\n") {
                return ' ';
            }

            if (strlen($bytes) === 1) {
                return sprintf('\x%02x', ord($bytes));
            }

            if (strlen($bytes) === 2) {
                return sprintf('\u%04x', ((ord($bytes[0]) & 0x1f) << 6) | (ord($bytes[1]) & 0x3f));
            }

            return sprintf('\u%04x', ((ord($bytes[0]) & 0x0f) << 12) | ((ord($bytes[1]) & 0x3f) << 6) | (ord($bytes[2]) & 0x3f));
        }, $text);

        return $clean === null ? str_replace(["\r", "\n"], ' ', $text) : $clean;
    }
}
