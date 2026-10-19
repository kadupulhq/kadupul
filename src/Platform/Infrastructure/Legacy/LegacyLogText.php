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

        return $clean ?? self::withoutPcre($text);
    }

    private static function withoutPcre(string $text): string
    {
        /** @var array<string, string>|null $replacements */
        static $replacements = null;

        if ($replacements === null) {
            $replacements = ["\r" => ' ', "\n" => ' ', "\x7f" => '\\x7f'];
            for ($byte = 0; $byte < 0x20; $byte++) {
                if (!in_array($byte, [0x09, 0x0a, 0x0d], true)) {
                    $replacements[chr($byte)] = sprintf('\\x%02x', $byte);
                }
            }
            for ($byte = 0x80; $byte <= 0x9f; $byte++) {
                $replacements["\xc2" . chr($byte)] = sprintf('\\u%04x', $byte);
            }
            foreach ([0x61c, 0x200e, 0x200f, 0x2028, 0x2029, 0x202a, 0x202b, 0x202c, 0x202d, 0x202e, 0x2066, 0x2067, 0x2068, 0x2069] as $codepoint) {
                $character = $codepoint < 0x800
                    ? chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f))
                    : chr(0xe0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
                $replacements[$character] = sprintf('\\u%04x', $codepoint);
            }
        }

        // No regex or Unicode extension is required during early bootstrap.
        // strtr preserves malformed UTF-8 and never rescans replacement text.
        return strtr($text, $replacements);
    }
}
